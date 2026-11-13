<?php

namespace PostHog\Mailer;

use Closure;
use PostHog\EmailError;
use PostHog\Emails;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds PostHogTransport from `MAILER_DSN=posthog+api://KEY@default`.
 *
 * KEY is a project secret API key (`phs_...`). `default` sends to US Cloud; use `eu.posthog.com` for EU
 * Cloud, or your own PostHog host. The `default_template_key` option sets the template key of emails
 * without `X-PostHog-Template-Key`. Register this class with the `mailer.transport_factory` service tag.
 *
 * It sends through the app's `http_client` service when Symfony passes one, so the app's proxy, TLS and
 * mock settings apply; without one, it sends with curl. The SDK still owns retries and credentials: the
 * client's own retries are off for these requests, and its default credentials never go out with them.
 *
 * Symfony's own PostHog bridge, once installed, handles the same scheme, so this factory steps aside.
 */
final class PostHogTransportFactory extends AbstractTransportFactory
{
    private const SCHEME = 'posthog+api';
    private const BRIDGE_FACTORY = 'Symfony\Component\Mailer\Bridge\PostHog\Transport\PostHogTransportFactory';
    private const MAX_ANSWER_BYTES = 1_048_576;

    public function create(Dsn $dsn): TransportInterface
    {
        if (!in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'posthog', $this->getSupportedSchemes());
        }
        $emails = new Emails(
            $this->getUser($dsn),
            'default',
            appHost: self::appHost($dsn),
            transport: $this->sendThroughClient()
        );

        return new PostHogTransport(
            $emails,
            defaultTemplateKey: $dsn->getOption('default_template_key'),
            dispatcher: $this->dispatcher,
            logger: $this->logger
        );
    }

    protected function getSupportedSchemes(): array
    {
        return class_exists(self::BRIDGE_FACTORY) ? [] : [self::SCHEME];
    }

    private function sendThroughClient(): ?Closure
    {
        if ($this->client === null) {
            return null;
        }
        $client = self::forTheSdk($this->client);

        return static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $timeoutMs,
            int $connectTimeoutMs
        ) use ($client): array {
            try {
                $response = $client->request($method, $url, [
                    'headers' => $headers,
                    'body' => $body ?? '',
                    'timeout' => $connectTimeoutMs / 1000,
                    'max_duration' => $timeoutMs / 1000,
                    'max_redirects' => 0,
                ]);
                $lines = [];
                foreach ($response->getHeaders(false) as $name => $values) {
                    foreach ($values as $value) {
                        $lines[] = "$name: $value";
                    }
                }
                $answer = '';
                foreach ($client->stream($response) as $chunk) {
                    $answer .= $chunk->getContent();
                    if (strlen($answer) > self::MAX_ANSWER_BYTES) {
                        $response->cancel();
                        $limit = self::MAX_ANSWER_BYTES;
                        throw new EmailError(
                            'response_too_large',
                            "PostHog answered with more than $limit bytes, so the SDK stopped reading."
                        );
                    }
                }

                return [$response->getStatusCode(), $answer, $lines];
            } catch (TransportExceptionInterface $error) {
                throw new EmailError(
                    'network_error',
                    'The request to PostHog failed: ' . $error->getMessage(),
                    previous: $error
                );
            }
        };
    }

    /**
     * The app's client without its default credentials or its own retries. The send and the storage upload carry
     * their own credentials, and the SDK retries within its Retry-After cap. withOptions() reaches the client that
     * holds the defaults through every Symfony decorator, such as ScopingHttpClient and TraceableHttpClient.
     */
    private static function forTheSdk(HttpClientInterface $client): HttpClientInterface
    {
        $client = $client->withOptions(
            ['headers' => ['authorization' => []], 'auth_basic' => null, 'auth_bearer' => null]
        );
        try {
            return $client->withOptions(['max_retries' => 0]);
        } catch (InvalidArgumentException) {
            // Only RetryableHttpClient knows max_retries; a client without it has no retries to turn off.
            return $client;
        }
    }

    private static function appHost(Dsn $dsn): string
    {
        $host = $dsn->getHost() === 'default' ? 'us.posthog.com' : $dsn->getHost();
        $port = $dsn->getPort();

        return 'https://' . $host . ($port === null ? '' : ':' . $port);
    }
}
