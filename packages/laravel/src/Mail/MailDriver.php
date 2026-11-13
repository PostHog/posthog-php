<?php

namespace PostHog\Laravel\Mail;

use Illuminate\Container\Container;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Str;
use PostHog\Laravel\PostHog;
use PostHog\Mailer\PostHogTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
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
    private const TEMPLATE_KEY = 'X-PostHog-Template-Key';
    // The longest template key the send API takes.
    private const TEMPLATE_KEY_LENGTH = 256;

    /** @var WeakMap<object, string>|null The template key of each mail Laravel is sending. */
    private static ?WeakMap $templateKeys = null;

    private function __construct(private readonly ?string $defaultTemplateKey)
    {
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
        $key = preg_replace('/[^a-zA-Z0-9_-]+/', '-', Str::kebab(class_basename($class)));
        $key = substr(trim((string) $key, '-'), 0, self::TEMPLATE_KEY_LENGTH);
        if ($key !== '') {
            self::$templateKeys ??= new WeakMap();
            self::$templateKeys[$event->message] = $key;
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $key = self::templateKeyOf($message);
        if ($key !== null && $message instanceof Message && !$message->getHeaders()->has(self::TEMPLATE_KEY)) {
            // PostHogTransport reads the reserved header and strips it, so it never goes out.
            $message->getHeaders()->addTextHeader(self::TEMPLATE_KEY, $key);
        }

        // Built per send, so each request, job or Octane worker sends with its own scoped client.
        $transport = new PostHogTransport(
            Container::getInstance()->make(PostHog::class)->client()->emails(),
            integration: self::INTEGRATION . '/' . PostHog::VERSION,
            defaultTemplateKey: $this->defaultTemplateKey,
            templates: false,
        );

        return $transport->send($message, $envelope);
    }

    private static function templateKeyOf(RawMessage $message): ?string
    {
        if (self::$templateKeys === null) {
            return null;
        }
        if (isset(self::$templateKeys[$message])) {
            return self::$templateKeys[$message];
        }
        // A failover or round-robin mailer sends each transport a clone of the mail, equal to it until sent.
        foreach (self::$templateKeys as $mail => $key) {
            if ($mail == $message) {
                return $key;
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return 'posthog';
    }
}
