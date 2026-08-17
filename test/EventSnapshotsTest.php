<?php

namespace PostHog\Test;

use PostHog\Client;
use PostHog\PostHog;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

class EventSnapshotsTest extends JsonSnapshotTestCase
{
    private const API_KEY = 'phc_snapshot_project_key';

    private MockedHttpClient $httpClient;
    private Client $client;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        Clock::set(new MockClock(new \DateTimeImmutable('2024-01-02T03:04:05.123456+00:00')));
        $this->httpClient = new MockedHttpClient('snapshot.posthog.test');
        $this->client = new Client(
            self::API_KEY,
            [
                'batch_size' => 100,
                'debug' => true,
            ],
            $this->httpClient,
            null,
            false
        );
        PostHog::init(null, null, $this->client);
    }

    protected function tearDown(): void
    {
        $this->client->shutdown();
        Clock::set(new NativeClock());
    }

    public function testEventFamilyBatchRequestMatchesSnapshot(): void
    {
        self::assertTrue($this->client->capture([
            'distinctId' => 'person-123',
            'event' => 'checkout completed',
            'groups' => [
                'organization' => 'org-42',
                'project' => 'project-7',
            ],
            'properties' => [
                'boolean_false' => false,
                'boolean_true' => true,
                'empty_list' => [],
                'empty_object' => (object) [],
                'empty_string' => '',
                'float' => 42.5,
                'floating_zero' => 0.0,
                'integer' => 42,
                'large_integer' => 9007199254740991,
                'negative_floating_zero' => -0.0,
                'negative_integer' => -42,
                'list_order' => ['third', 'first', 'second'],
                'nested_object' => [
                    'zeta' => null,
                    'alpha' => 'first',
                ],
                'null' => null,
                'numeric_string' => '0',
                'text' => "line one\n\"quoted\" \\ slash / café 🌍",
                'zero' => 0,
            ],
        ]));

        self::assertTrue($this->client->identify([
            'distinctId' => 'person-123',
            'properties' => [
                'email' => 'person@example.com',
                'roles' => ['admin', 'editor'],
                'preferences' => (object) [],
            ],
        ]));

        self::assertTrue($this->client->alias([
            'distinctId' => 'person-123',
            'alias' => 'anonymous-456',
            'properties' => [
                'source' => 'snapshot-suite',
            ],
        ]));

        self::assertTrue(PostHog::groupIdentify([
            'groupType' => 'organization',
            'groupKey' => 'org-42',
            'properties' => [
                'employees' => 150,
                'labels' => ['customer', 'beta'],
                'metadata' => (object) [],
                'name' => 'PostHog',
            ],
        ]));

        self::assertTrue($this->client->flush());
        self::assertCount(1, $this->httpClient->calls);

        $this->assertJsonSnapshot(
            'event-family-request.json',
            $this->structuredRequest($this->httpClient->calls[0])
        );
    }

    public function testCompleteFlagsRequestMatchesSnapshot(): void
    {
        $this->client->flags(
            'person-123',
            [
                'organization' => 'org-42',
                'project' => 'project-7',
            ],
            [
                'empty_list' => [],
                'empty_object' => (object) [],
                'nullable' => null,
                'plan' => 'enterprise',
                'roles' => ['admin', 'editor'],
            ],
            [
                'organization' => [
                    'employee_count' => 150,
                    'industry' => 'analytics',
                    'settings' => (object) [],
                ],
                'project' => [
                    'enabled' => false,
                    'tags' => ['php', 'server'],
                ],
            ],
            true,
            ['checkout-redesign', 'server-rollout']
        );

        self::assertCount(1, $this->httpClient->calls);
        $this->assertJsonSnapshot(
            'flags-request.json',
            $this->structuredRequest($this->httpClient->calls[0])
        );
    }

    /**
     * @param array{
     *     path: string,
     *     payload: string,
     *     extraHeaders: array<int, string>,
     *     requestOptions: array<string, mixed>
     * } $call
     * @return array<string, mixed>
     */
    private function structuredRequest(array $call): array
    {
        $headers = [];
        foreach ($call['extraHeaders'] as $header) {
            [$name, $value] = explode(':', $header, 2);
            $headers[trim($name)] = trim($value);
        }

        return [
            'body' => json_decode($call['payload'], false, 512, JSON_THROW_ON_ERROR),
            'headers' => $headers,
            'options' => $call['requestOptions'],
            'path' => $call['path'],
        ];
    }
}
