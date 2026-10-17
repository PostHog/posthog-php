<?php

namespace PostHog\Test;

use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanLimitsBuilder;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\OpenTelemetry\ContextSpanProcessor;

class ContextSpanProcessorTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        require_once __DIR__ . '/error_log_mock.php';
        $this->client = new Client('test-token', [], new MockedHttpClient('example.com'), 'test');
    }

    public function testExistingRequestSpanAndChildKeepTheirContextAfterScopeEnds(): void
    {
        $provider = TracerProvider::builder()->addSpanProcessor(new ContextSpanProcessor($this->client))->build();
        $tracer = $provider->getTracer('test');
        $request = $tracer->spanBuilder('request')->startSpan();
        $scope = $request->activate();
        try {
            $child = $this->client->withContext(
                $this->client->contextFromHeaders([
                    'X-PostHog-Distinct-Id' => 'browser-user',
                    'X-PostHog-Session-Id' => 'browser-session',
                ]),
                fn () => $tracer->spanBuilder('child')->startSpan(),
                ['fresh' => true]
            );
        } finally {
            $scope->detach();
        }
        $late = $tracer->spanBuilder('late')->startSpan();
        foreach ([$request, $child] as $span) {
            $span->end();
            $this->assertSame([
                'posthogDistinctId' => 'browser-user',
                'sessionId' => 'browser-session',
            ], $span->toSpanData()->getAttributes()->toArray());
        }
        $this->assertSame([], $late->toSpanData()->getAttributes()->toArray());
        $late->end();
    }

    public static function identityCases(): array
    {
        return [
            'explicit identities' => [
                ['posthogDistinctId' => 'app-user', 'sessionId' => 'app-session'],
                ['distinctId' => 'header-user', 'sessionId' => 'header-session'],
                ['posthogDistinctId' => 'app-user', 'sessionId' => 'app-session'],
            ],
            'explicit user' => [
                ['posthogDistinctId' => 'app-user'],
                ['distinctId' => 'header-user', 'sessionId' => 'header-session'],
                ['posthogDistinctId' => 'app-user', 'sessionId' => 'header-session'],
            ],
            'empty explicit values' => [
                ['posthogDistinctId' => '', 'sessionId' => ''],
                ['distinctId' => 'header-user', 'sessionId' => 'header-session'],
                ['posthogDistinctId' => '', 'sessionId' => ''],
            ],
            'missing IDs' => [[], [], []],
            'user only' => [[], ['distinctId' => 'user'], ['posthogDistinctId' => 'user']],
            'session only' => [[], ['sessionId' => 'session'], ['sessionId' => 'session']],
        ];
    }

    #[DataProvider('identityCases')]
    public function testPreservesExplicitAttributesAndOmitsMissingIds(
        array $attributes,
        array $context,
        array $expected
    ): void {
        $provider = TracerProvider::builder()->addSpanProcessor(new ContextSpanProcessor($this->client))->build();
        $span = $this->client->withContext(
            $context,
            fn () => $provider->getTracer('test')->spanBuilder('child')->setAttributes($attributes)->startSpan()
        );
        $span->end();
        $this->assertSame($expected, $span->toSpanData()->getAttributes()->toArray());
    }

    public function testRegisteredBridgePreservesCapacity(): void
    {
        $provider = new TracerProvider(
            [new ContextSpanProcessor($this->client)],
            spanLimits: (new SpanLimitsBuilder())->setAttributeCountLimit(1)->build()
        );
        $span = $provider->getTracer('test')->spanBuilder('request')->setAttribute('app.key', 'keep')->startSpan();
        $scope = $span->activate();
        try {
            $this->client->withContext(['distinctId' => 'user', 'sessionId' => 'session'], static fn () => null);
        } finally {
            $scope->detach();
            $span->end();
        }
        $this->assertSame(['app.key' => 'keep'], $span->toSpanData()->getAttributes()->toArray());
    }

    public function testConcurrentFibersAndFreshScopesStayIsolated(): void
    {
        $provider = TracerProvider::builder()->addSpanProcessor(new ContextSpanProcessor($this->client))->build();
        $tracer = $provider->getTracer('test');
        $makeFiber = fn (string $id) => new \Fiber(function () use ($id, $tracer) {
            $scope = Context::getRoot()->activate();
            try {
                return $this->client->withContext(
                    ['distinctId' => $id, 'sessionId' => $id],
                    static function () use ($tracer) {
                        \Fiber::suspend();
                        return $tracer->spanBuilder('child')->startSpan();
                    },
                    ['fresh' => true]
                );
            } finally {
                $scope->detach();
            }
        });
        $first = $makeFiber('first');
        $second = $makeFiber('second');
        $first->start();
        $second->start();
        foreach (['second' => $second, 'first' => $first] as $id => $fiber) {
            $fiber->resume();
            $span = $fiber->getReturn();
            $span->end();
            $this->assertSame($id, $span->toSpanData()->getAttributes()->get('sessionId'));
        }
        $span = $this->client->withContext(
            ['sessionId' => 'outer'],
            fn () => $this->client->withContext(
                ['distinctId' => 'fresh-user'],
                fn () => $tracer->spanBuilder('fresh')->startSpan(),
                ['fresh' => true]
            )
        );
        $span->end();
        $this->assertSame(['posthogDistinctId' => 'fresh-user'], $span->toSpanData()->getAttributes()->toArray());
        $this->assertNull($this->client->getContext());
    }

    public function testClientIsolationAndFailureCleanup(): void
    {
        $other = new Client('other-token', [], new MockedHttpClient('example.com'), 'test');
        $provider = TracerProvider::builder()->addSpanProcessor(new ContextSpanProcessor($this->client))->build();
        $tracer = $provider->getTracer('test');
        try {
            $other->withContext(['sessionId' => 'other-session'], function () use ($tracer): void {
                $span = $tracer->spanBuilder('other-client')->startSpan();
                $span->end();
                $this->assertSame([], $span->toSpanData()->getAttributes()->toArray());
            });
            $this->client->withContext(['sessionId' => 'failed-session'], static function (): void {
                throw new \RuntimeException('test failure');
            });
            $this->fail('Expected the application exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('test failure', $exception->getMessage());
        }
        $span = $tracer->spanBuilder('after-failure')->startSpan();
        $span->end();
        $this->assertSame([], $span->toSpanData()->getAttributes()->toArray());
    }

    public function testUninitializedFiberCannotChangeMainSpan(): void
    {
        $provider = TracerProvider::builder()->addSpanProcessor(new ContextSpanProcessor($this->client))->build();
        $main = $provider->getTracer('test')->spanBuilder('main')->startSpan();
        $scope = $main->activate();
        try {
            $fiber = new \Fiber(fn () => $this->client->withContext(
                ['distinctId' => 'fiber-user', 'sessionId' => 'fiber-session'],
                static fn () => null
            ));
            $fiber->start();
            $this->assertTrue($fiber->isTerminated());
        } finally {
            $scope->detach();
            $main->end();
        }
        $this->assertSame([], $main->toSpanData()->getAttributes()->toArray());
    }

    public function testDefaultContextDoesNotChangeActiveSpan(): void
    {
        $provider = TracerProvider::builder()->build();
        $span = $provider->getTracer('test')->spanBuilder('request')->startSpan();
        $scope = $span->activate();
        try {
            $this->client->withContext(['sessionId' => 'unregistered'], static fn () => null);
        } finally {
            $scope->detach();
            $span->end();
        }
        $this->assertSame([], $span->toSpanData()->getAttributes()->toArray());
    }

    public function testContextLookupFailureDoesNotBreakApplicationTracing(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getContext')->willThrowException(new \RuntimeException('lookup failure'));
        $provider = TracerProvider::builder()->addSpanProcessor(new ContextSpanProcessor($client))->build();
        $span = $provider->getTracer('test')->spanBuilder('application')->startSpan();
        $this->assertTrue($span->isRecording());
        $span->end();
        $this->assertSame([], $span->toSpanData()->getAttributes()->toArray());
    }

    public function testProcessorShutdownStopsNewChildEnrichment(): void
    {
        $processor = new ContextSpanProcessor($this->client);
        $provider = TracerProvider::builder()->addSpanProcessor($processor)->build();
        $this->assertTrue($processor->forceFlush());
        $this->assertTrue($processor->shutdown());
        $this->assertFalse($processor->shutdown());
        $span = $this->client->withContext(
            ['sessionId' => 'session'],
            fn () => $provider->getTracer('test')->spanBuilder('after-shutdown')->startSpan()
        );
        $span->end();
        $this->assertSame([], $span->toSpanData()->getAttributes()->toArray());
    }
}
