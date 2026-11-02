<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Laravel\Autocapture\AuthEvents;
use PostHog\Laravel\Autocapture\Collector;
use PostHog\Laravel\Autocapture\DomainEvents;
use PostHog\Laravel\Autocapture\ModelEvents;
use PostHog\Laravel\Autocapture\Outcomes;
use PostHog\Laravel\Autocapture\RequestSummary;
use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TypeError;

class CollectorDiagnosticsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        foreach (['auth', 'models', 'jobs', 'requests', 'blade'] as $section) {
            $app['config']->set('posthog.autocapture.' . $section . '.enabled', true);
        }
    }

    private function failingManager(TypeError $failure): PostHog
    {
        $manager = $this->getMockBuilder(PostHog::class)
            ->setConstructorArgs([$this->app])
            ->onlyMethods(['capture', 'evaluateFlags'])
            ->getMock();
        $manager->expects($this->atLeastOnce())->method('capture')->willThrowException($failure);
        $manager->method('evaluateFlags')->willThrowException($failure);
        $this->app->instance(PostHog::class, $manager);
        return $manager;
    }

    public static function diagnosticModes(): array
    {
        return [
            'quiet by default' => [false, false],
            'debug' => [true, false],
            'broken logger' => [true, true],
        ];
    }

    #[DataProvider('diagnosticModes')]
    public function testCollectorFailuresAreDeduplicatedRedactedAndNeverEscape(bool $debug, bool $loggerThrows): void
    {
        $this->app['config']->set('posthog.sdk.debug', $debug);
        $failure = new TypeError('private credentials and event data');
        $this->failingManager($failure);
        $this->app->make(Context::class)->begin(['distinctId' => 'private actor']);
        $logs = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($debug ? 7 : 0))->method('debug')->willReturnCallback(
            function ($message, $context) use (&$logs, $loggerThrows) {
                $logs[] = [$message, $context];
                $this->assertSame(['collector', 'exception_class'], array_keys($context));
                $this->assertSame(TypeError::class, $context['exception_class']);
                if ($loggerThrows) {
                    throw new RuntimeException('logger failed');
                }
            },
        );
        $this->app->instance('log', $logger);
        $model = $this->createStub(CollectedRecord::class);
        $model->method('getAttributes')->willThrowException($failure);
        $this->app['config']->set('posthog.autocapture.models.allow', [$model::class => ['state']]);
        $user = new class (['id' => 1]) extends GenericUser {
            public function getAuthIdentifier()
            {
                throw new TypeError('private user data');
            }
        };
        $request = Request::create('/private');
        $request->setRouteResolver(fn () => throw $failure);
        $applicationFailure = new RuntimeException('original application failure');
        for ($i = 0; $i < 2; $i++) {
            Collector::capture('private event', ['private' => 'data']);
            (new AuthEvents())->collect('login', (object) ['user' => $user, 'guard' => 'web']);
            (new DomainEvents())->collect(new \stdClass(), [
                'event' => 'private event',
                'properties' => (function () use ($failure) {
                    throw $failure;
                    yield;
                })(),
            ]);
            (new ModelEvents())->collect('created', $model);
            $outcomes = new Outcomes();
            $job = new \stdClass();
            $outcomes->start('jobs', $job);
            $outcomes->finish('jobs', $job, fn () => throw $failure);
            try {
                (new RequestSummary())->handle($request, fn () => throw $applicationFailure);
                $this->fail('The application exception must escape unchanged.');
            } catch (RuntimeException $caught) {
                $this->assertSame($applicationFailure, $caught);
            }
            $this->assertSame('off', trim(Blade::render("@posthogFeature('flag') on @else off @endposthogFeature")));
        }
        $this->assertCount($debug ? 7 : 0, $logs);
        $this->assertStringNotContainsString('private', json_encode($logs));
        if ($debug) {
            $this->assertCount(7, array_unique(array_column(array_column($logs, 1), 'collector')));
        }
        $this->assertSame('private actor', $this->app->make(Context::class)->data()['distinctId']);
    }

    public function testDiagnosticDeduplicationResetsWithTheApplicationScope(): void
    {
        $this->app['config']->set('posthog.sdk.debug', true);
        $manager = $this->failingManager(new TypeError('private'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('debug');
        $this->app->instance('log', $logger);
        for ($scope = 0; $scope < 2; $scope++) {
            for ($i = 0; $i < 2; $i++) {
                Collector::capture('event', [], ['distinctId' => 'actor']);
            }
            $this->app->forgetScopedInstances();
            $this->app->instance(PostHog::class, $manager);
        }
    }
}
