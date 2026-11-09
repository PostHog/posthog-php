<?php

namespace PostHog\Mailer;

use InvalidArgumentException;
use PostHog\EmailError;
use PostHog\Emails;
use PostHog\Internal\Mailer\EmailMapper;
use PostHog\Internal\Mailer\RemoteTemplateSupport;
use PostHog\PostHog;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends Symfony Mailer emails through PostHog's transactional email API, one send per email.
 *
 * Symfony apps get it from the `posthog+api://KEY@default` DSN through PostHogTransportFactory. An email
 * PostHog can't carry, such as one with two To addresses, tags or custom headers, throws a LogicException
 * `PostHog cannot send this email: <code>` and sends nothing. A failed send throws a
 * PostHogTransportException that says whether sending again with the same idempotency key is safe.
 *
 * The reserved headers `X-PostHog-Distinct-Id`, `X-PostHog-Template-Key`, `X-PostHog-Idempotency-Key` and
 * `X-PostHog-Capture-Engagement` set the send's options and never go out. `X-PostHog-Capture-Engagement: false`
 * only keeps the email's engagement events off the recipient's person. It doesn't change the email: PostHog
 * still adds its open pixel to count opens. It never rewrites the email's links, so it counts no clicks.
 * Symfony's `X-Track` header is dropped, and an email whose `X-Track` sets `opens=false` is refused with
 * `open_tracking_off`. On Symfony Mailer 8.2 and later, a RemoteTemplateEmail sends a template made in
 * PostHog by its ID.
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
     * @param string|null $defaultTemplateKey The template key of an email without `X-PostHog-Template-Key`.
     *     Defaults to the integration's name.
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
        if ($defaultTemplateKey !== null && preg_match('/^[a-zA-Z0-9_-]{1,400}$/D', $defaultTemplateKey) !== 1) {
            throw new InvalidArgumentException(
                'The default template key can only have letters, digits, hyphens (-) and underscores (_), at most 400.'
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
        $send = $this->mapper->map(MessageConverter::toEmail($message->getOriginalMessage()), $message->getEnvelope());
        try {
            $result = $this->emails->send($send);
        } catch (EmailError $error) {
            throw new PostHogTransportException($error);
        }
        $message->setMessageId($result->getId());
    }
}
