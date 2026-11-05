<?php

namespace PostHog\Internal\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\RemoteTemplateEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * @internal Turns a Symfony Email into the options of one PostHog send, or refuses it.
 */
final class EmailMapper
{
    private const DISTINCT_ID = 'x-posthog-distinct-id';
    private const TEMPLATE_KEY = 'x-posthog-template-key';
    private const IDEMPOTENCY_KEY = 'x-posthog-idempotency-key';
    private const CAPTURE_ENGAGEMENT = 'x-posthog-capture-engagement';
    private const OPTION_HEADERS = [
        self::DISTINCT_ID, self::TEMPLATE_KEY, self::IDEMPOTENCY_KEY, self::CAPTURE_ENGAGEMENT,
    ];
    private const MAPPED_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'sender', 'return-path'];
    private const IGNORED_HEADERS = ['message-id', 'date', 'mime-version', 'x-mailer', 'x-priority'];
    private const TRACKING_HEADER = 'x-track';
    private const NORMAL_PRIORITY = 3;
    private const TEMPLATE_KEY_PATTERN = '/^[a-zA-Z0-9_-]{1,400}$/D';

    public function __construct(
        private readonly string $integration,
        private readonly ?string $defaultTemplateKey,
        private readonly bool $templates,
    ) {
    }

    /**
     * @return array<string, mixed> The options of Emails::send().
     */
    public function map(Email $email, Envelope $envelope): array
    {
        $to = $this->onlyRecipient($email);
        $options = $this->headerOptions($email);
        $this->refuseEnvelopeMismatch($email, $envelope);
        $template = $email instanceof RemoteTemplateEmail ? $email->getRemoteTemplate() : null;
        $send = $template === null
            ? $this->renderedContent($email, $options)
            : $this->templateContent($email, $template->getReference(), $template->getVariables());

        return array_filter(
            ['to' => $to, ...$send, 'distinct_id' => $options[self::DISTINCT_ID] ?? null,
                'idempotency_key' => $options[self::IDEMPOTENCY_KEY] ?? null, 'integration' => $this->integration],
            static fn($value): bool => $value !== null
        );
    }

    private function onlyRecipient(Email $email): string
    {
        $to = $email->getTo();
        if (count($to) !== 1) {
            throw self::refusal('multiple_to', 'It takes exactly one To address; send one email per recipient.');
        }

        return $to[0]->getAddress();
    }

    /**
     * @return array<string, string>
     */
    private function headerOptions(Email $email): array
    {
        $options = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if (in_array($name, self::OPTION_HEADERS, true)) {
                $options[$name] = $header->getBodyAsString();
                continue;
            }
            self::refuseHeader($name);
        }
        if ($email->getPriority() !== self::NORMAL_PRIORITY) {
            throw self::refusal('unsupported_priority', 'It has no priority; leave the priority normal.');
        }
        if (count($email->getFrom()) > 1) {
            throw self::refusal('unsupported_header', 'It takes one From address.');
        }

        return $options;
    }

    private static function refuseHeader(string $name): void
    {
        if (
            in_array($name, self::MAPPED_HEADERS, true)
            || in_array($name, self::IGNORED_HEADERS, true)
            || str_starts_with($name, 'content-')
            || $name === self::TRACKING_HEADER
        ) {
            return;
        }
        if ($name === 'x-tag') {
            throw self::refusal('tags', 'It has no tags yet; remove the tags.');
        }
        if (str_starts_with($name, 'x-metadata-')) {
            throw self::refusal('metadata', 'It has no metadata yet; remove the metadata.');
        }
        throw self::refusal('unsupported_header', "It can't carry the $name header; remove it.");
    }

    private function refuseEnvelopeMismatch(Email $email, Envelope $envelope): void
    {
        $from = $email->getFrom()[0] ?? null;
        $recipients = self::addresses([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]);
        $envelopeRecipients = self::addresses($envelope->getRecipients());
        sort($recipients);
        sort($envelopeRecipients);
        if ($from?->getAddress() !== $envelope->getSender()->getAddress() || $recipients !== $envelopeRecipients) {
            throw self::refusal(
                'envelope_mismatch',
                'It sends from the From address to the To, Cc and Bcc addresses only.'
            );
        }
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function renderedContent(Email $email, array $options): array
    {
        return [
            'template_key' => $this->templateKey($options[self::TEMPLATE_KEY] ?? null),
            'subject' => $email->getSubject(),
            'html' => self::text($email->getHtmlBody()),
            'text' => self::text($email->getTextBody()),
            'from' => ($from = $email->getFrom()[0] ?? null) === null ? null : self::mailbox($from),
            'reply_to' => self::addresses($email->getReplyTo()),
            'cc' => self::addresses($email->getCc()),
            'bcc' => self::addresses($email->getBcc()),
            'attachments' => array_map(self::attachment(...), $email->getAttachments()),
            'capture_engagement' => self::captureEngagement($email, $options[self::CAPTURE_ENGAGEMENT] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function templateContent(Email $email, string $templateId, array $variables): array
    {
        if (!$this->templates) {
            throw self::refusal('template_unsupported', 'This mailer sends rendered content only, not a template.');
        }
        $content = $email->getSubject() !== null || $email->getReplyTo() !== [] || $email->getCc() !== []
            || $email->getBcc() !== [] || $email->getAttachments() !== [];
        if ($content) {
            throw self::refusal(
                'template_with_content',
                'A template made in PostHog sets its own subject and content, so remove the subject, Reply-To, '
                . 'Cc, Bcc and attachments.'
            );
        }

        return ['template_id' => $templateId, 'template_data' => $variables];
    }

    private function templateKey(?string $explicit): string
    {
        if ($explicit === null) {
            return $this->defaultTemplateKey ?? explode('/', $this->integration, 2)[0];
        }
        if (preg_match(self::TEMPLATE_KEY_PATTERN, $explicit) !== 1) {
            throw self::refusal(
                'invalid_template_key',
                'X-PostHog-Template-Key can only have letters, digits, hyphens (-) and underscores (_), at most 400.'
            );
        }

        return $explicit;
    }

    private static function captureEngagement(Email $email, ?string $explicit): bool|string|null
    {
        $choices = array_values(array_unique(array_filter(
            [self::flag($explicit), ...self::tracking($email)],
            static fn($choice): bool => $choice !== null
        ), SORT_REGULAR));
        if (count($choices) > 1) {
            throw self::refusal(
                'mixed_tracking',
                'It captures opens and clicks together; set them to the same value.'
            );
        }

        return $choices[0] ?? null;
    }

    /**
     * @return list<bool|null>
     */
    private static function tracking(Email $email): array
    {
        $header = $email->getHeaders()->get(self::TRACKING_HEADER);
        if ($header === null) {
            return [];
        }
        $flags = ['opens' => null, 'clicks' => null];
        foreach (explode(';', $header->getBodyAsString()) as $part) {
            [$name, $value] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
            if (array_key_exists($name, $flags)) {
                $flags[$name] = ['true' => true, 'false' => false][$value] ?? null;
            }
        }

        return array_values($flags);
    }

    private static function flag(?string $value): bool|string|null
    {
        return match ($value === null ? null : strtolower(trim($value))) {
            null => null,
            'true' => true,
            'false' => false,
            default => $value,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function attachment(DataPart $part): array
    {
        $attachment = ['filename' => $part->getFilename(), 'content' => $part->getBody()];
        $attachment['content_type'] = $part->getContentType();
        if ($part->getDisposition() === 'inline') {
            $contentId = $part->hasContentId() ? $part->getContentId() : $part->getName();
            if ($contentId === null) {
                throw self::refusal('inline_without_cid', 'An inline attachment needs a name the HTML refers to.');
            }
            $attachment['content_id'] = $contentId;
        }

        return $attachment;
    }

    /**
     * @param Address[] $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn(Address $address): string => $address->getAddress(), $addresses));
    }

    private static function mailbox(Address $address): string
    {
        return $address->getName() === '' ? $address->getAddress() : "{$address->getName()} <{$address->getAddress()}>";
    }

    /**
     * @param string|resource|null $body
     */
    private static function text(mixed $body): ?string
    {
        if (!is_resource($body)) {
            return $body;
        }
        if (stream_get_meta_data($body)['seekable']) {
            rewind($body);
        }

        return stream_get_contents($body);
    }

    private static function refusal(string $code, string $detail): LogicException
    {
        return new LogicException("PostHog cannot send this email: $code. $detail");
    }
}
