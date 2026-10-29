<?php

namespace PostHog\Laravel\Testing;

use PHPUnit\Framework\Assert;
use PostHog\Client;
use PostHog\FeatureFlagEvaluations;
use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use Throwable;

class PostHogFake extends PostHog
{
    private array $recorded = [];
    private ?Client $disabledClient = null;
    private ?FeatureFlagEvaluations $flagSnapshot = null;

    public function enabled(): bool
    {
        return true;
    }

    public function client(): Client
    {
        return $this->disabledClient ??= new Client(null, ['consumer' => 'noop'], loadFeatureFlags: false);
    }

    protected function message(string $method, array $message): bool
    {
        $data = $this->app->make(Context::class)->data();
        $message['distinctId'] ??= $data['distinctId'] ?? null;
        $message['groups'] ??= $data['groups'] ?? [];
        $message['properties'] = array_replace($data['properties'] ?? [], $message['properties'] ?? []);
        $this->recorded[] = ['method' => $method, 'message' => $message];
        return true;
    }

    public function captureException(
        Throwable|string $exception,
        ?string $distinctId = null,
        array $properties = [],
        array $options = [],
    ): bool {
        $data = $this->app->make(Context::class)->data();
        $properties += isset($data['groups']) ? ['$groups' => $data['groups']] : [];
        return $this->message('captureException', [
            'exception' => $exception, 'distinctId' => $distinctId, 'properties' => $properties, 'options' => $options,
        ]);
    }

    public function evaluateFlags(
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        ?bool $disableGeoip = null,
        ?array $flagKeys = null,
    ): FeatureFlagEvaluations {
        return $this->flagSnapshot ?? new FeatureFlagEvaluations($distinctId ?? '', [], $groups, $this->client());
    }

    public function flags(FeatureFlagEvaluations $snapshot): static
    {
        $this->flagSnapshot = $snapshot;
        return $this;
    }

    public function recorded(?string $method = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            fn ($item) => $method === null || $item['method'] === $method,
        ));
    }

    public function assertCaptured(string $event, ?callable $callback = null): void
    {
        $matches = array_filter(
            $this->recorded('capture'),
            fn ($item) => ($item['message']['event'] ?? null) === $event
                && ($callback === null || $callback($item['message'])),
        );
        Assert::assertNotEmpty($matches, 'Expected PostHog event was not captured: ' . $event);
    }

    public function assertNotCaptured(string $event): void
    {
        Assert::assertEmpty(array_filter(
            $this->recorded('capture'),
            fn ($item) => ($item['message']['event'] ?? null) === $event,
        ));
    }

    public function assertCapturedCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded('capture'));
    }

    public function assertNothingCaptured(): void
    {
        Assert::assertSame([], $this->recorded);
    }

    public function assertIdentified(?callable $callback = null): void
    {
        $this->assertRecorded('identify', $callback);
    }

    public function assertAliased(?callable $callback = null): void
    {
        $this->assertRecorded('alias', $callback);
    }

    public function assertGroupIdentified(?callable $callback = null): void
    {
        $this->assertRecorded('groupIdentify', $callback);
    }

    public function assertExceptionCaptured(?callable $callback = null): void
    {
        $this->assertRecorded('captureException', $callback);
    }

    private function assertRecorded(string $method, ?callable $callback): void
    {
        Assert::assertNotEmpty(array_filter(
            $this->recorded($method),
            fn ($item) => $callback === null || $callback($item['message']),
        ));
    }

    public function reportException(Throwable $exception, array $snapshot, array $options): bool
    {
        $this->recorded[] = ['method' => 'captureException', 'message' => [
            'exception' => $exception,
            'distinctId' => $snapshot['distinctId'] ?? null,
            'properties' => array_replace(
                $snapshot['properties'] ?? [],
                isset($snapshot['groups']) ? ['$groups' => $snapshot['groups']] : [],
            ),
            'options' => $options,
        ]];
        return true;
    }

    public function flush(): bool
    {
        return true;
    }

    public function shutdown(): bool
    {
        return true;
    }
}
