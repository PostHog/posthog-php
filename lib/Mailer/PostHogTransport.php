<?php

namespace PostHog\Mailer;

use InvalidArgumentException;
use PostHog\EmailError;
use PostHog\Emails;
use PostHog\Internal\Integration;
use PostHog\Internal\Mailer\EmailMapper;
use PostHog\Internal\Mailer\RemoteTemplateSupport;
use PostHog\PostHog;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends Symfony Mailer emails through PostHog's transactional email API, one send per email.
 *
 * Symfony apps get it from the `posthog+api://KEY@default` DSN through PostHogTransportFactory. An email
 * PostHog can't carry, such as one with two To addresses, tags or custom headers, throws a LogicException
 * `PostHog cannot send this email: <code>` and sends nothing. A failed send throws a
 * PostHogTransportException that says whether sending again with the same idempotency key is safe.
 *
 * The reserved headers `X-PostHog-Distinct-Id`, `X-PostHog-Template-Key` (at most 256 characters),
 * `X-PostHog-Idempotency-Key`, `X-PostHog-Capture-Engagement` and `X-PostHog-Sensitive` set the send's options
 * and never go out. Symfony's `X-Track` header, such as `opens=true; clicks=false`, sets which opens and clicks
 * PostHog tracks, and `X-PostHog-Capture-Engagement: false` turns its engagement events off; a flag left out or
 * `default` keeps PostHog's default. `X-PostHog-Sensitive: true` marks an email that carries login links, reset
 * tokens or codes. Set `X-PostHog-Idempotency-Key` from a stable ID, such as the order ID and the email's name,
 * when Messenger retries the send. On Symfony Mailer 8.2 and later, a RemoteTemplateEmail sends a template made
 * in PostHog by its ID; the template sets its own tracking and sensitive setting.
 */
final class PostHogTransport extends AbstractTransport implements RemoteTemplateSupport
{
    public const INTEGRATION = 'posthog-php-mailer';

    private EmailMapper $mapper;

    /**
     * @param Emails $emails The email sender, such as `$client->emails()`.
     * @param string|null $integration The integration that sends, `<name>/<version>`. A framework package
     *     that registers this transport passes its own, such as `laravel/1.4.0`. Defaults to
     *     `posthog-php-mailer/<SDK version>`.
     * @param string|null $defaultTemplateKey The template key of an email without `X-PostHog-Template-Key`, at
     *     most 256 characters. Defaults to the integration's name.
     * @param bool $templates Whether a RemoteTemplateEmail may send a template made in PostHog.
     */
    public function __construct(
        private readonly Emails $emails,
        ?string $integration = null,
        ?string $defaultTemplateKey = null,
        bool $templates = true,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($integration !== null && !Integration::isValid($integration)) {
            throw new InvalidArgumentException(ucfirst(Integration::RULE));
        }
        if ($defaultTemplateKey !== null && preg_match(EmailMapper::TEMPLATE_KEY_PATTERN, $defaultTemplateKey) !== 1) {
            throw new InvalidArgumentException(
                'The default template key can only have letters, digits, hyphens (-) and underscores (_), at most 256.'
            );
        }
        parent::__construct($dispatcher, $logger);
        $this->mapper = new EmailMapper(
            $integration ?? self::INTEGRATION . '/' . PostHog::VERSION,
            $defaultTemplateKey,
            $templates
        );
    }

    public function __toString(): string
    {
        return 'posthog+api://';
    }

    protected function doSend(SentMessage $message): void
    {
        $send = $this->mapper->map($message->getOriginalMessage(), $message->getEnvelope());
        try {
            $result = $this->emails->send($send);
        } catch (EmailError $error) {
            throw new PostHogTransportException($error);
        }
        $message->setMessageId($result->getId());
    }
}
