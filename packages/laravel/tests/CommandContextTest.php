<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\FeatureFlagEvaluations;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHog as Manager;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\ReportingConsoleKernel;
use PostHog\Laravel\Tests\Fixtures\ScopedCommand;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class CommandContextTest extends TestCase
{
    private array $events = [];

    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'test-key');
        $app['config']->set('posthog.secret_key', null);
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('posthog.sdk.before_send', function ($event) {
            $this->events[] = $event;
            return null;
        });
    }

    public static function childCalls(): iterable
    {
        foreach (['call', 'callSilent', 'callSilently'] as $method) {
            foreach ([false, true] as $throws) {
                yield $method . ($throws ? ' throwing' : ' returning') => [$method, $throws];
            }
        }
    }

    #[DataProvider('childCalls')]
    public function testCallHelpersRestoreContextAndFlagsWithoutChangingCommandBehavior(
        string $method,
        bool $throws,
    ): void {
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'caller']);
        $parent = [
            'distinctId' => 'parent', 'sessionId' => 'parent-session',
            'groups' => ['team' => 'parent-team'], 'properties' => ['component' => 'parent'],
        ];
        $child = [
            'distinctId' => 'child', 'sessionId' => 'child-session',
            'groups' => ['team' => 'child-team'], 'properties' => ['component' => 'child'],
        ];
        $parentFlags = new FeatureFlagEvaluations('parent', [], [], PostHog::client());
        $childFlags = new FeatureFlagEvaluations('child', [], [], PostHog::client());
        $failure = new RuntimeException('child failed');
        $observed = [];
        Artisan::command('posthog:child {--value=}', function () use (
            $context,
            $child,
            $childFlags,
            $throws,
            $failure,
            &$observed,
        ) {
            $observed['inherited'] = $context->snapshot();
            $observed['freshFlags'] = $context->flags('snapshot', fn () => $childFlags) === $childFlags;
            $context->begin($child);
            PostHog::capture('child');
            $this->line($this->option('value'));
            if ($throws) {
                throw $failure;
            }
            return 7;
        });
        Artisan::registerCommand(new ScopedCommand('posthog:parent', function (Command $command) use (
            $context,
            $parent,
            $parentFlags,
            $childFlags,
            $method,
            &$observed,
        ) {
            $context->begin($parent);
            $context->flags('snapshot', fn () => $parentFlags);
            $observed['result'] = null;
            $observed['exception'] = null;
            try {
                $observed['result'] = $command->{$method}('posthog:child', ['--value' => 'forwarded']);
            } catch (RuntimeException $exception) {
                $observed['exception'] = $exception;
                report($exception);
            }
            $observed['after'] = $context->snapshot();
            $observed['flags'] = $context->flags('snapshot', fn () => $childFlags);
            PostHog::capture('parent continued');
            return 0;
        }));

        $this->assertSame(0, Artisan::call('posthog:parent'));
        $this->assertSame($parent, $observed['after']);
        $this->assertSame($parent, $observed['inherited']);
        $this->assertTrue($observed['freshFlags']);
        $this->assertSame($parentFlags, $observed['flags']);
        $this->assertSame($throws ? null : 7, $observed['result']);
        $this->assertSame($throws ? $failure : null, $observed['exception']);
        $this->assertSame($method === 'call' ? 'forwarded' . PHP_EOL : '', Artisan::output());
        $this->assertSame(['distinctId' => 'caller'], $context->snapshot());
        $this->assertSame($throws ? ['child', 'child', 'parent'] : ['child', 'parent'], array_column(
            $this->events,
            'distinct_id',
        ));
        $event = $this->events[array_key_last($this->events)];
        $this->assertSame('parent-session', $event['properties']['$session_id']);
        $this->assertSame(['team' => 'parent-team'], $event['properties']['$groups']);
        $this->assertSame('parent', $event['properties']['component']);
    }

    public function testNestedCallsRestoreEachCallerAndKeepTheDeepestThrowableSnapshot(): void
    {
        $context = $this->app->make(Context::class);
        $failure = new RuntimeException('grandchild failed');
        $observed = [];
        $grandchild = new ScopedCommand('posthog:grandchild', function () use ($context, $failure) {
            $context->begin(['distinctId' => 'grandchild']);
            throw $failure;
        });
        $this->app->bind(ScopedCommand::class, fn () => $grandchild);
        $child = new ScopedCommand('posthog:child', function (Command $command) use ($context, &$observed) {
            $context->begin(['distinctId' => 'child']);
            try {
                $command->call(ScopedCommand::class);
            } catch (RuntimeException $exception) {
                $observed['child'] = $context->snapshot();
                throw $exception;
            }
            return 0;
        });
        Artisan::registerCommand(new ScopedCommand('posthog:parent', function (Command $command) use (
            $context,
            $child,
            &$observed,
        ) {
            $context->begin(['distinctId' => 'parent']);
            try {
                $command->call($child);
            } catch (RuntimeException $exception) {
                $observed['exception'] = $exception;
                report($exception);
                report($exception);
            }
            $observed['parent'] = $context->snapshot();
            PostHog::capture('parent continued');
            return 0;
        }));

        $this->assertSame(0, Artisan::call('posthog:parent'));
        $this->assertSame(['distinctId' => 'child'], $observed['child']);
        $this->assertSame(['distinctId' => 'parent'], $observed['parent']);
        $this->assertSame($failure, $observed['exception']);
        $this->assertSame(['grandchild', 'parent'], array_column($this->events, 'distinct_id'));
        $this->assertSame('$exception', $this->events[0]['event']);
        $mechanism = $this->events[0]['properties']['$exception_list'][0]['mechanism'];
        $this->assertFalse($mechanism['handled']);
        $this->assertSame('runtime', $mechanism['type']);
    }

    public function testChildFailureExitCodeDoesNotReplaceRootIdentityThroughTermination(): void
    {
        $context = $this->app->make(Context::class);
        $this->app->singleton(Kernel::class, fn ($app) => new ReportingConsoleKernel($app, $app['events']));
        Artisan::clearResolvedInstance(Kernel::class);
        $kernel = $this->app->make(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        Artisan::command('posthog:child', function () use ($context) {
            $context->begin(['distinctId' => 'child']);
            return 1;
        });
        Artisan::registerCommand(new ScopedCommand('posthog:parent', function (Command $command) use ($context) {
            $context->begin(['distinctId' => 'parent']);
            $command->call('posthog:child');
            throw new RuntimeException('parent failed independently');
        }));
        $this->app->terminating(fn () => PostHog::capture('terminating'));
        $input = new ArrayInput(['command' => 'posthog:parent']);

        $this->assertSame(1, $kernel->handle($input, new NullOutput()));
        $this->assertSame(['distinctId' => 'parent'], $context->snapshot());
        $kernel->terminate($input, 1);
        $this->assertSame([], $context->snapshot());
        $this->assertSame(['$exception', 'terminating'], array_column($this->events, 'event'));
        $this->assertSame(['parent', 'parent'], array_column($this->events, 'distinct_id'));
    }

    public function testFlushFailureCannotMaskAChildThrowableOrPreventRestoration(): void
    {
        $manager = new class ($this->app) extends Manager {
            public function flush(): bool
            {
                throw new RuntimeException('flush failed');
            }
        };
        PostHog::swap($manager);
        $context = $this->app->make(Context::class);
        $failure = new RuntimeException('child failed');
        $observed = [];
        Artisan::command('posthog:child', function () use ($context, $failure) {
            $context->begin(['distinctId' => 'child']);
            throw $failure;
        });
        Artisan::registerCommand(new ScopedCommand('posthog:parent', function (Command $command) use (
            $context,
            &$observed,
        ) {
            $context->begin(['distinctId' => 'parent']);
            try {
                $command->call('posthog:child');
            } catch (RuntimeException $exception) {
                $observed = [$exception, $context->snapshot()];
            }
            return 0;
        }));

        $this->assertSame(0, Artisan::call('posthog:parent'));
        $this->assertSame([$failure, ['distinctId' => 'parent']], $observed);
        $this->assertSame([], $context->snapshot());
    }
}
