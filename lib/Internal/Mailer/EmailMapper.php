<?php

namespace PostHog\Internal\Mailer;

use ReflectionMethod;
use ValueError;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\RemoteTemplateEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * @internal Turns a Symfony Email into the options of one PostHog send, or refuses it.
 */
final class EmailMapper
{
    private const DISTINCT_ID = 'x-posthog-distinct-id';
    private const TEMPLATE_KEY = 'x-posthog-template-key';
    private const IDEMPOTENCY_KEY = 'x-posthog-idempotency-key';
    private const CAPTURE_ENGAGEMENT = 'x-posthog-capture-engagement';
    private const SENSITIVE = 'x-posthog-sensitive';
    private const OPTION_HEADERS = [
        self::DISTINCT_ID, self::TEMPLATE_KEY, self::IDEMPOTENCY_KEY, self::CAPTURE_ENGAGEMENT, self::SENSITIVE,
    ];
    private const MAPPED_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'sender', 'return-path'];
    private const IGNORED_HEADERS = ['message-id', 'date', 'mime-version', 'x-mailer', 'x-priority'];
    private const TRACKING_HEADER = 'x-track';
    private const TRACKING_FLAGS = ['true' => true, 'false' => false, 'default' => null];
    private const NORMAL_PRIORITY = 3;
    public const TEMPLATE_KEY_PATTERN = '/^[a-zA-Z0-9_-]{1,256}$/D';

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
        $templateKey = $this->templateKey($options[self::TEMPLATE_KEY] ?? null);
        $tracking = self::tracking($email, $options[self::CAPTURE_ENGAGEMENT] ?? null);
        $sensitive = self::flag('X-PostHog-Sensitive', $options[self::SENSITIVE] ?? null);
        $template = $email instanceof RemoteTemplateEmail ? $email->getRemoteTemplate() : null;
        $send = $template === null
            ? $this->renderedContent($email, $templateKey) + ['tracking' => $tracking, 'sensitive' => $sensitive]
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
                if (isset($options[$name])) {
                    throw self::refusal('unsupported_header', "It takes one {$header->getName()} header.");
                }
                $options[$name] = self::unencodedValue($header);
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
        $senders = array_filter([$envelope->getSender(), $email->getSender(), $email->getReturnPath()]);
        foreach ($senders as $sender) {
            if ($from?->getAddress() !== $sender->getAddress()) {
                throw self::refusal(
                    'envelope_mismatch',
                    'It sends from the From address only; the envelope sender, Sender and Return-Path must be it.'
                );
            }
        }
        if ($recipients !== $envelopeRecipients) {
            throw self::refusal(
                'envelope_mismatch',
                'It sends from the From address to the To, Cc and Bcc addresses only.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedContent(Email $email, string $templateKey): array
    {
        $html = self::readOnce($email->getHtmlBody());
        $text = self::readOnce($email->getTextBody());
        self::refuseUnsupportedParts($email, $text, $html);
        $html = self::utf8($html, $email->getHtmlCharset(), 'HTML');
        $text = self::utf8($text, $email->getTextCharset(), 'text');

        return [
            'template_key' => $templateKey,
            'subject' => $email->getSubject(),
            'html' => $html,
            'text' => $text,
            'from' => ($from = $email->getFrom()[0] ?? null) === null ? null : self::mailbox($from),
            'reply_to' => self::addresses($email->getReplyTo()),
            'cc' => self::addresses($email->getCc()),
            'bcc' => self::addresses($email->getBcc()),
            'attachments' => array_map(
                static fn(DataPart $part): array => self::attachment($part, $html),
                $email->getAttachments()
            ),
        ];
    }

    /**
     * The send carries the email's text, HTML and attachments, so a body set with setBody() must hold exactly those:
     * one text and one HTML part for the email's own text and HTML, and each of the email's own attachments.
     *
     * A body Symfony generates holds nothing else, so only a custom body is walked. Message::getBody() returns it
     * without building the generated body. Building it reads a stream body, and older Symfony Mime releases, such
     * as 6.4.0, rename each part the HTML refers to after its generated content ID.
     */
    private static function refuseUnsupportedParts(Email $email, ?string $text, ?string $html): void
    {
        $custom = (new ReflectionMethod(Message::class, 'getBody'))->invoke($email);
        $expected = ['plain' => $text, 'html' => $html];
        if ($custom === null) {
            return;
        }
        $attachments = $email->getAttachments();
        $parts = [$custom];
        while (($part = array_shift($parts)) !== null) {
            $type = "{$part->getMediaType()}/{$part->getMediaSubtype()}";
            if ($part instanceof AbstractMultipartPart) {
                array_push($parts, ...$part->getParts());
            } elseif ($part instanceof DataPart) {
                $index = array_search($part, $attachments, true);
                if ($index === false) {
                    throw self::refusal(
                        'unsupported_part',
                        "The body holds a $type part that is not one of its attachments."
                    );
                }
                unset($attachments[$index]);
            } elseif ($part instanceof TextPart && self::isOwnBody($email, $part, $expected)) {
                $expected[$part->getMediaSubtype()] = null;
            } else {
                throw self::refusal(
                    'unsupported_part',
                    "It can't carry the $type part of the body: a body part must hold the email's own text or HTML."
                );
            }
        }
        $missing = array_keys(array_filter($expected, static fn(?string $content): bool => $content !== null));
        if ($missing !== [] || $attachments !== []) {
            throw self::refusal(
                'unsupported_part',
                'The body leaves out ' . ($missing === [] ? 'an attachment' : "the email's {$missing[0]} part")
                    . ' that the email carries, so the email sent would differ from the one Symfony builds.'
            );
        }
    }

    /**
     * Whether a custom body part is the email's own text or HTML, as Symfony would build it: the same content in the
     * same charset, shown as the body rather than attached.
     *
     * @param array{plain: ?string, html: ?string} $expected the text and HTML no part has matched yet
     */
    private static function isOwnBody(Email $email, TextPart $part, array $expected): bool
    {
        $subtype = $part->getMediaSubtype();
        $charset = match ($subtype) {
            'plain' => $email->getTextCharset(),
            'html' => $email->getHtmlCharset(),
            default => null,
        };
        $partCharset = $part->getPreparedHeaders()->getHeaderParameter('Content-Type', 'charset');

        return ($expected[$subtype] ?? null) !== null
            && $part->getBody() === $expected[$subtype]
            && strtolower((string) $partCharset) === strtolower((string) $charset)
            && in_array($part->getDisposition(), [null, 'inline'], true);
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
                'X-PostHog-Template-Key can only have letters, digits, hyphens (-) and underscores (_), at most 256.'
            );
        }

        return $explicit;
    }

    /**
     * What PostHog tracks for the email: `X-Track` sets opens and clicks as Symfony's TrackingHeader does, and
     * `X-PostHog-Capture-Engagement` sets the engagement events. A flag left out, or `default`, is left to PostHog.
     *
     * @return array{opens?: bool, clicks?: bool, engagement?: bool}|null
     */
    private static function tracking(Email $email, ?string $engagement): ?array
    {
        $headers = iterator_to_array($email->getHeaders()->all(self::TRACKING_HEADER), false);
        if (count($headers) > 1) {
            throw self::refusal('unsupported_header', 'It takes one X-Track header; remove the others.');
        }
        $flags = ['opens' => null, 'clicks' => null];
        $header = $headers[0] ?? null;
        $value = $header instanceof UnstructuredHeader ? $header->getValue() : (string) $header?->getBodyAsString();
        foreach (explode(';', $value) as $part) {
            if (trim($part) === '') {
                continue;
            }
            $pair = array_map('trim', explode('=', $part, 2));
            if (count($pair) !== 2 || !array_key_exists($pair[0], $flags)) {
                throw self::refusal(
                    'unsupported_header',
                    'X-Track can only set opens and clicks, such as "opens=true; clicks=false".'
                );
            }
            if (!array_key_exists($pair[1], self::TRACKING_FLAGS)) {
                throw self::refusal('unsupported_header', "X-Track's $pair[0] must be true, false or default.");
            }
            $flags[$pair[0]] = self::TRACKING_FLAGS[$pair[1]];
        }
        $flags['engagement'] = self::flag('X-PostHog-Capture-Engagement', $engagement);
        $tracking = array_filter($flags, static fn(?bool $flag): bool => $flag !== null);

        return $tracking === [] ? null : $tracking;
    }

    private static function flag(string $header, ?string $value): ?bool
    {
        return match ($value) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw self::refusal('unsupported_header', "$header must be \"true\" or \"false\"."),
        };
    }

    /**
     * The body in UTF-8, the only charset PostHog takes, converted from the charset the email declares for it.
     */
    private static function utf8(?string $body, ?string $charset, string $part): ?string
    {
        if ($body === null || $charset === null || strcasecmp($charset, 'utf-8') === 0) {
            return $body;
        }
        try {
            $converted = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (ValueError) {
            $converted = false;
        }
        if (!is_string($converted)) {
            throw self::refusal('unsupported_part', "It can't convert the $part part from $charset to UTF-8.");
        }

        return $converted;
    }

    /**
     * @return array<string, string>
     */
    private static function attachment(DataPart $part, ?string $html): array
    {
        if (array_diff($part->getHeaders()->getNames(), ['content-id']) !== []) {
            throw self::refusal(
                'unsupported_part',
                "It can't carry the headers set on the attachment {$part->getFilename()}; remove them."
            );
        }
        $attachment = ['filename' => $part->getFilename() ?? $part->getName(), 'content' => $part->getBody()];
        $attachment['content_type'] = $part->getContentType();
        $contentId = self::contentId($part, $html);
        if ($contentId !== null) {
            $attachment['content_id'] = $contentId;
        }

        return $attachment;
    }

    /**
     * The content ID the HTML refers to, `cid:<name>` before `cid:<content ID>`, so a part sends inline under the
     * reference the HTML uses. Symfony gives a referenced part a generated content ID when it renders the body, but
     * the HTML sent still says `cid:<name>`. An inline part the HTML doesn't refer to sends its own content ID.
     */
    private static function contentId(DataPart $part, ?string $html): ?string
    {
        $name = $part->getName();
        if ($name !== null && self::refersTo($html, $name)) {
            $contentId = $part->hasContentId() ? $part->getContentId() : $name;
            if ($contentId !== $name && self::refersTo($html, $contentId)) {
                throw self::refusal(
                    'unsupported_part',
                    "The HTML refers to one inline part as both cid:$name and cid:$contentId; use one."
                );
            }
            return $name;
        }
        if ($part->hasContentId() && self::refersTo($html, $part->getContentId())) {
            return $part->getContentId();
        }
        if ($part->getDisposition() !== 'inline') {
            return null;
        }
        if (!$part->hasContentId()) {
            throw self::refusal(
                'inline_without_cid',
                'An inline attachment needs a content ID, or a name the HTML refers to as cid:<name>.'
            );
        }

        return $part->getContentId();
    }

    /**
     * Whether the HTML holds a complete `cid:` reference to this content ID. A quoted
     * reference runs to its closing quote, so `"cid:logo'v2@shop.example"` refers to
     * `logo'v2@shop.example` and not to `logo`. An unquoted one, such as `url(cid:logo)`,
     * ends at whitespace, a quote, `>` or `)`.
     */
    private static function refersTo(?string $html, string $contentId): bool
    {
        if ($html === null) {
            return false;
        }
        preg_match_all('/(["\'])cid:(.*?)\\1|(?<!["\'])\\bcid:([^\\s"\'>)]+)/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (($match[3] ?? $match[2]) === $contentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Address[] $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn(Address $address): string => $address->getAddress(), $addresses));
    }

    /**
     * The sender with its display name, quoted when the name has a character RFC 5322 reserves, such as a comma.
     */
    private static function mailbox(Address $address): string
    {
        $name = $address->getName();
        if ($name === '') {
            return $address->getAddress();
        }

        return (strpbrk($name, '()<>[]:;@\\,."') === false ? $name : $address->getEncodedName())
            . " <{$address->getAddress()}>";
    }

    /**
     * @param string|resource|null $body
     */
    private static function unencodedValue(HeaderInterface $header): string
    {
        return $header instanceof UnstructuredHeader ? $header->getValue() : $header->getBodyAsString();
    }

    private static function readOnce(mixed $body): ?string
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
