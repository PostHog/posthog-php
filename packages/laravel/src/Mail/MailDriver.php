<?php

namespace PostHog\Laravel\Mail;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Str;
use PostHog\Laravel\PostHog;
use PostHog\Mailer\PostHogTransport;

/**
 * @internal Registers the `posthog` mail transport, which sends every Mailable and mail notification of a
 * mailer with `'transport' => 'posthog'` through PostHog's transactional email API.
 *
 * Each mail sends under its Mailable or notification class in kebab case as its template key, so
 * `App\Mail\OrderShipped` sends as `order-shipped`. An `X-PostHog-Template-Key` header on the mail wins;
 * mail with no class, such as `Mail::raw()`, uses the mailer's `default_template_key`, or `laravel`.
 */
final class MailDriver
{
    private const TRANSPORT = 'posthog';
    private const INTEGRATION = 'laravel';
    private const TEMPLATE_KEY = 'X-PostHog-Template-Key';

    public static function extend(MailManager $manager): void
    {
        $manager->extend(self::TRANSPORT, static fn(array $config): PostHogTransport => new PostHogTransport(
            // Resolved per mailer, so each Octane request's mailer sends with that request's client.
            Container::getInstance()->make(PostHog::class)->client()->emails(),
            integration: self::INTEGRATION . '/' . PostHog::VERSION,
            defaultTemplateKey: $config['default_template_key'] ?? null,
            templates: false,
        ));
    }

    public static function nameTheMail(MessageSending $event, Repository $config): void
    {
        $mailer = $event->data['mailer'] ?? null;
        $mailers = $config->get('mail.mailers', []);
        if (!is_string($mailer) || ($mailers[$mailer]['transport'] ?? null) !== self::TRANSPORT) {
            return;
        }
        $class = $event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? null;
        $headers = $event->message->getHeaders();
        if (!is_string($class) || $headers->has(self::TEMPLATE_KEY)) {
            return;
        }
        $key = preg_replace('/[^a-zA-Z0-9_-]+/', '-', Str::kebab(class_basename($class)));
        $key = substr(trim((string) $key, '-'), 0, 400);
        if ($key !== '') {
            $headers->addTextHeader(self::TEMPLATE_KEY, $key);
        }
    }
}
