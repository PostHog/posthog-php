<?php

namespace PostHog\Laravel\Mail;

use Illuminate\Container\Container;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PostHog\Internal\Mailer\EmailMapper;
use PostHog\Laravel\PostHog;
use PostHog\Mailer\PostHogTransport;
use Symfony\Component\Mailer\DelayedEnvelope;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use WeakMap;

/**
 * @internal The `posthog` mail transport, which sends every Mailable and mail notification of a mailer with
 * `'transport' => 'posthog'` through PostHog's transactional email API, also inside a failover, round-robin
 * or on-demand mailer.
 *
 * Each mail sends under its Mailable or notification class in kebab case as its template key, so
 * `App\Mail\OrderShipped` sends as `order-shipped`. An `X-PostHog-Template-Key` header on the mail wins;
 * mail with no class, such as `Mail::raw()`, uses the mailer's `default_template_key`, or `laravel`.
 */
final class MailDriver implements TransportInterface
{
    private const INTEGRATION = 'laravel';
    private const TEMPLATE_KEY_LENGTH = 256;
    private const NOT_IN_A_TEMPLATE_KEY = '/[^a-zA-Z0-9_-]+/';

    /** @var WeakMap<object, string>|null The class-name key of each mail Laravel sends. */
    private static ?WeakMap $templateKeys = null;

    private function __construct(private readonly ?string $defaultTemplateKey)
    {
        if ($defaultTemplateKey !== null && preg_match(EmailMapper::TEMPLATE_KEY_PATTERN, $defaultTemplateKey) !== 1) {
            throw new InvalidArgumentException(
                'The default template key can only have letters, digits, hyphens (-) and underscores (_), at most '
                . self::TEMPLATE_KEY_LENGTH . '.'
            );
        }
    }

    public static function extend(MailManager $manager): void
    {
        $manager->extend(
            'posthog',
            static fn(array $config): self => new self($config['default_template_key'] ?? null)
        );
    }

    /**
     * Remembers the mail's class as its template key for the PostHog transport. The mail itself is left as is,
     * so no other transport, such as the next one of a failover mailer, sends a PostHog header.
     */
    public static function nameTheMail(MessageSending $event): void
    {
        $class = $event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? null;
        if (!is_string($class)) {
            return;
        }
        $key = (string) preg_replace(self::NOT_IN_A_TEMPLATE_KEY, '-', Str::kebab(class_basename($class)));
        $key = substr(trim($key, '-'), 0, self::TEMPLATE_KEY_LENGTH);
        if ($key !== '') {
            self::$templateKeys ??= new WeakMap();
            self::$templateKeys[$event->message] = $key;
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $mail = $this->mailLaravelHandedOver($envelope) ?? $message;

        return $this->transportForThisSend(self::$templateKeys[$mail] ?? $this->defaultTemplateKey)
            ->send($message, $envelope);
    }

    /**
     * A failover or round-robin mailer hands each transport a clone of the mail with Laravel's own envelope,
     * which holds the mail Laravel named.
     */
    private function mailLaravelHandedOver(?Envelope $envelope): ?RawMessage
    {
        if (!$envelope instanceof DelayedEnvelope) {
            return null;
        }

        return (fn(): ?RawMessage => $this->message ?? null)->call($envelope);
    }

    private function transportForThisSend(?string $templateKey): PostHogTransport
    {
        return new PostHogTransport(
            Container::getInstance()->make(PostHog::class)->client()->emails(),
            integration: self::INTEGRATION . '/' . PostHog::VERSION,
            defaultTemplateKey: $templateKey,
            templates: false,
        );
    }

    public function __toString(): string
    {
        return 'posthog';
    }
}
