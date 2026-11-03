<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\Autocapture\DeferredModelEvents;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;

class TransactionalModelEventsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['a', 'b'];

    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.autocapture.models', [
            'enabled' => true, 'allow' => [CollectedRecord::class => ['state']],
        ]);
        $app['config']->set('database.default', 'a');
        foreach (['a', 'b'] as $name) {
            $app['config']->set('database.connections.' . $name, ['driver' => 'sqlite', 'database' => ':memory:']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        PostHog::fake();
        $this->app->make(Context::class)->begin(['distinctId' => 'actor']);
        foreach (['a', 'b'] as $name) {
            DB::connection($name)->getSchemaBuilder()->create('collected_records', function (Blueprint $table) {
                $table->id();
                $table->string('state');
                $table->string('email');
            });
        }
    }

    private function record(string $state, string $connection = 'a'): void
    {
        CollectedRecord::on($connection)->create(['state' => $state, 'email' => 'private']);
    }

    private function states(): array
    {
        return array_map(
            fn ($call) => $call['message']['properties']['attributes']['state'],
            PostHog::getFacadeRoot()->recorded('capture'),
        );
    }

    public function testWrapperOnlyWritesCaptureImmediatelyLikeAfterCommit(): void
    {
        $afterCommitRan = false;
        DB::afterCommit(function () use (&$afterCommitRan) {
            $afterCommitRan = true;
        });
        $this->assertTrue($afterCommitRan);
        $this->record('wrapper only');
        $this->assertSame(['wrapper only'], $this->states());
        $this->assertSame(['count' => 0, 'bytes' => 0], $this->app->make(DeferredModelEvents::class)->pendingUsage());
    }

    public function testApplicationTransactionsStillDeferAndRemainConnectionCorrect(): void
    {
        DB::connection('a')->beginTransaction();
        $this->record('a committed');
        $this->record('b wrapper only', 'b');
        $this->assertSame(['b wrapper only'], $this->states());
        DB::connection('b')->beginTransaction();
        $this->record('b rolled back', 'b');
        DB::connection('a')->commit();
        $this->assertSame(['b wrapper only', 'a committed'], $this->states());
        DB::connection('b')->rollBack();
        $this->assertSame(['b wrapper only', 'a committed'], $this->states());
        $this->assertSame(['count' => 0, 'bytes' => 0], $this->app->make(DeferredModelEvents::class)->pendingUsage());
    }

    public function testWrapperOnlyWritesDoNotWaitForAnotherConnectionsFullBuffer(): void
    {
        DB::connection('a')->beginTransaction();
        for ($i = 0; $i < 1000; $i++) {
            $this->record('pending a');
        }
        $this->assertSame(1000, $this->app->make(DeferredModelEvents::class)->pendingUsage()['count']);
        $this->record('b wrapper only', 'b');
        $this->assertSame(['b wrapper only'], $this->states());
        DB::connection('a')->rollBack();
        $this->assertSame(['b wrapper only'], $this->states());
        $this->assertSame(['count' => 0, 'bytes' => 0], $this->app->make(DeferredModelEvents::class)->pendingUsage());
    }

    public function testNestedCommitAndRollbackFollowApplicationTransactionBoundaries(): void
    {
        DB::beginTransaction();
        $this->record('outer');
        DB::beginTransaction();
        $this->record('discarded child');
        DB::rollBack();
        DB::beginTransaction();
        $this->record('committed child');
        DB::commit();
        PostHog::assertNothingCaptured();
        DB::commit();
        $this->assertSame(['outer', 'committed child'], $this->states());
        DB::beginTransaction();
        $this->record('discarded outer');
        DB::rollBack();
        $this->record('wrapper again');
        $this->assertSame(['outer', 'committed child', 'wrapper again'], $this->states());
        $this->assertSame(['count' => 0, 'bytes' => 0], $this->app->make(DeferredModelEvents::class)->pendingUsage());
    }
}
