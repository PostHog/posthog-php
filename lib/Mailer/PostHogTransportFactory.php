<?php

namespace PostHog\Mailer;

use Closure;
use PostHog\Emails;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds PostHogTransport from `MAILER_DSN=posthog+api://KEY@default`.
 *
 * KEY is a project secret API key (`phs_...`). `default` sends to US Cloud; use `eu.posthog.com` for EU
 * Cloud, or your own PostHog host. The `default_template_key` option sets the template key of emails
 * without `X-PostHog-Template-Key`. Register this class with the `mailer.transport_factory` service tag.
 *
 * Symfony's own PostHog bridge, once installed, handles the same scheme, so this factory steps aside.
 */
final class PostHogTransportFactory extends AbstractTransportFactory
{
    private const SCHEME = 'posthog+api';
    private const BRIDGE_FACTORY = 'Symfony\Component\Mailer\Bridge\PostHog\Transport\PostHogTransportFactory';

    /**
     * @param Closure|null $sendRequest @internal Replaces the HTTP requests, for tests.
     */
    public function __construct(
        ?EventDispatcherInterface $dispatcher = null,
        ?HttpClientInterface $client = null,
        ?LoggerInterface $logger = null,
        private readonly ?Closure $sendRequest = null,
    ) {
        parent::__construct($dispatcher, $client, $logger);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'posthog', $this->getSupportedSchemes());
        }
        return new PostHogTransport(
            new Emails($this->getUser($dsn), 'default', appHost: self::appHost($dsn), transport: $this->sendRequest),
            defaultTemplateKey: $dsn->getOption('default_template_key'),
            dispatcher: $this->dispatcher,
            logger: $this->logger
        );
    }

    protected function getSupportedSchemes(): array
    {
        return class_exists(self::BRIDGE_FACTORY) ? [] : [self::SCHEME];
    }

    private static function appHost(Dsn $dsn): string
    {
        $host = $dsn->getHost() === 'default' ? 'us.posthog.com' : $dsn->getHost();
        $port = $dsn->getPort();

        return 'https://' . $host . ($port === null ? '' : ':' . $port);
    }
}
