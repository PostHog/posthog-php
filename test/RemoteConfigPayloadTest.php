<?php

// phpcs:ignoreFile
namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;

class RemoteConfigPayloadTest extends TestCase
{
    private const PROJECT_KEY = 'random_key';
    private const SECRET_KEY = 'phs_test';
    private const PATH = '/api/projects/@current/feature_flags/test-flag/remote_config?token=random_key';

    public function setUp(): void
    {
        global $errorMessages;
        $errorMessages = [];
    }

    private function httpClient(
        string|false $body = '{}',
        int $code = 200,
        int $curlErrno = 0
    ): MockedHttpClient {
        return new MockedHttpClient(
            'app.posthog.com',
            remoteConfigEndpointResponse: $body,
            remoteConfigEndpointResponseCode: $code,
            remoteConfigEndpointCurlErrno: $curlErrno
        );
    }

    private function client(
        MockedHttpClient $http,
        string $projectKey = self::PROJECT_KEY,
        array $options = [],
        ?string $secretKey = self::SECRET_KEY,
        ?string $personalApiKey = null
    ): Client {
        return new Client(
            $projectKey,
            $options,
            $http,
            $personalApiKey,
            loadFeatureFlags: false,
            secretKey: $secretKey
        );
    }

    public function testReturnsObjectBodyAsArray()
    {
        $client = $this->client($this->httpClient('{"foo":["bar","baz"]}'));

        $this->assertSame(['foo' => ['bar', 'baz']], $client->getRemoteConfigPayload('test-flag'));
    }

    public function testReturnsEmptyArrayForEmptyObject()
    {
        $client = $this->client($this->httpClient('{}'));

        $this->assertSame([], $client->getRemoteConfigPayload('test-flag'));
    }

    public function testDecodesDoubleEncodedStringBody()
    {
        $client = $this->client($this->httpClient(json_encode('{ "foo":["bar","baz"]}')));

        $this->assertSame(['foo' => ['bar', 'baz']], $client->getRemoteConfigPayload('test-flag'));
    }

    public function testReturnsStringWhenSecondDecodeFails()
    {
        $client = $this->client($this->httpClient('"hello"'));

        $this->assertSame('hello', $client->getRemoteConfigPayload('test-flag'));
    }

    public function testReturnsNullForJsonNullBodyWithoutLogging()
    {
        global $errorMessages;
        $client = $this->client($this->httpClient('null'));

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertSame([], $errorMessages);
    }

    public function testReturnsNullAndLogsWhenBodyIsNotJson()
    {
        global $errorMessages;
        $client = $this->client($this->httpClient('not json'));

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertCount(1, $errorMessages);
        $this->assertStringContainsString('invalid JSON response', $errorMessages[0]);
    }

    #[DataProvider('emptyBodies')]
    public function testReturnsNullWithoutLoggingForEmpty2xxBody(string $body)
    {
        global $errorMessages;
        $http = $this->httpClient($body);
        $client = $this->client($http);

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertCount(1, $http->calls);
        $this->assertSame([], $errorMessages);
    }

    public static function emptyBodies(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \r\n\t"],
        ];
    }

    public function testReturnsNullAndLogsOn404WithEmptyBody()
    {
        global $errorMessages;
        $client = $this->client($this->httpClient('', 404));

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertCount(1, $errorMessages);
        $this->assertStringContainsString('HTTP 404', $errorMessages[0]);
    }

    public function testReturnsNullAndLogsWhenNoSecretKey()
    {
        global $errorMessages;
        $http = $this->httpClient();
        $client = $this->client($http, secretKey: null);

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertSame([], $http->calls ?? []);
        $this->assertSame(
            ['[PostHog][Client] A secret key is required to fetch a remote config payload from PostHog'],
            $errorMessages
        );
    }

    public function testReturnsNullAndLogsWhenClientDisabled()
    {
        global $errorMessages;
        $http = $this->httpClient();
        $client = $this->client($http, projectKey: '');
        $errorMessages = [];

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertSame([], $http->calls ?? []);
        $this->assertSame(['[PostHog][Client] The client is disabled'], $errorMessages);
    }

    #[DataProvider('refusedKeys')]
    public function testReturnsNullAndLogsForRefusedKey(string $key)
    {
        global $errorMessages;
        $http = $this->httpClient();
        $client = $this->client($http);

        $this->assertNull($client->getRemoteConfigPayload($key));
        $this->assertSame([], $http->calls ?? []);
        $this->assertSame(
            ['[PostHog][Client] A feature flag key is required to fetch a remote config payload from PostHog'],
            $errorMessages
        );
    }

    public static function refusedKeys(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'double dot' => ['..'],
        ];
    }

    #[DataProvider('failureStatuses')]
    public function testReturnsNullAndLogsOnNon2xxWithoutRetry(int $code)
    {
        global $errorMessages;
        $http = $this->httpClient('{"detail":"nope"}', $code);
        $client = $this->client($http);

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertCount(1, $http->calls);
        $this->assertCount(1, $errorMessages);
        $this->assertStringContainsString('HTTP ' . $code, $errorMessages[0]);
    }

    public static function failureStatuses(): array
    {
        return [[401], [403], [404], [429], [500], [503]];
    }

    #[DataProvider('curlErrnos')]
    public function testReturnsNullAndLogsOnNetworkFailure(int $errno)
    {
        global $errorMessages;
        $http = $this->httpClient(false, 0, $errno);
        $client = $this->client($http);

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertCount(1, $http->calls);
        $this->assertCount(1, $errorMessages);
        $this->assertStringContainsString('curl errno ' . $errno, $errorMessages[0]);
    }

    public function testReturnsNullAndLogsWhenTransferFailsAfter2xxHeaders()
    {
        global $errorMessages;
        $client = $this->client($this->httpClient(false, 200, 28));

        $this->assertNull($client->getRemoteConfigPayload('test-flag'));
        $this->assertCount(1, $errorMessages);
        $this->assertStringContainsString('curl errno 28', $errorMessages[0]);
    }

    public static function curlErrnos(): array
    {
        return [[28], [6]];
    }

    public function testEncodesReservedCharactersInFlagKey()
    {
        $http = $this->httpClient();
        $client = $this->client($http);

        $client->getRemoteConfigPayload('a/b?c#d e');

        $this->assertSame(
            '/api/projects/@current/feature_flags/a%2Fb%3Fc%23d%20e/remote_config?token=random_key',
            $http->calls[0]['path']
        );
    }

    public function testEncodesProjectTokenInQuery()
    {
        $http = $this->httpClient();
        $client = $this->client($http, projectKey: 'a b&c');

        $client->getRemoteConfigPayload('test-flag');

        $this->assertSame(
            '/api/projects/@current/feature_flags/test-flag/remote_config?token=a%20b%26c',
            $http->calls[0]['path']
        );
    }

    public function testSendsGetRequestWithBearerHeaders()
    {
        $http = $this->httpClient();
        $client = $this->client($http);

        $client->getRemoteConfigPayload('test-flag');

        $this->assertCount(1, $http->calls);
        $this->assertSame(
            [
                'path' => self::PATH,
                'payload' => null,
                'extraHeaders' => [
                    'User-Agent: posthog-php/' . PostHog::VERSION,
                    'Authorization: Bearer phs_test',
                ],
                'requestOptions' => ['shouldRetry' => false, 'timeout' => 3000],
            ],
            $http->calls[0]
        );
    }

    public function testUsesPersonalApiKeyAliasForAuthorization()
    {
        $http = $this->httpClient();
        $client = $this->client($http, secretKey: null, personalApiKey: 'test');

        $client->getRemoteConfigPayload('test-flag');

        $this->assertContains('Authorization: Bearer test', $http->calls[0]['extraHeaders']);
    }

    public function testUsesConfiguredFeatureFlagTimeout()
    {
        $http = $this->httpClient();
        $client = $this->client($http, options: ['feature_flag_request_timeout_ms' => 1500]);

        $client->getRemoteConfigPayload('test-flag');

        $this->assertSame(1500, $http->calls[0]['requestOptions']['timeout']);
    }

    public function testSendsNoFeatureFlagCalledEvent()
    {
        $http = $this->httpClient();
        $client = $this->client($http);

        $client->getRemoteConfigPayload('test-flag');
        $client->flush();

        $this->assertCount(1, $http->calls);
        foreach ($http->calls as $call) {
            $this->assertStringStartsWith('/api/projects/@current/feature_flags/', $call['path']);
        }
    }

    public function testStaticFacadeDelegatesToClient()
    {
        $client = $this->client($this->httpClient('{"foo":"bar"}'));
        PostHog::init(null, null, $client);

        $this->assertSame(['foo' => 'bar'], PostHog::getRemoteConfigPayload('test-flag'));
    }
}
