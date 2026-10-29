<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\Autocapture\DeferredModelEvents;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;
use RuntimeException;

class ModelEventsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
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
        foreach (['a', 'b'] as $name) {
            DB::connection($name)->getSchemaBuilder()->create('collected_records', function (Blueprint $table) {
                $table->id();
                $table->text('state');
                $table->text('email');
            });
        }
        $this->identity('original');
    }

    private function identity(string $id): void
    {
        $this->app->make(Context::class)->begin(['distinctId' => $id, 'groups' => ['team' => $id]]);
    }

    private function record(string $state, string $connection = 'a'): CollectedRecord
    {
        return CollectedRecord::on($connection)->create(['state' => $state, 'email' => 'private']);
    }

    private function messages(): array
    {
        return array_column(PostHog::getFacadeRoot()->recorded('capture'), 'message');
    }

    private function states(): array
    {
        return array_map(fn ($event) => $event['properties']['attributes']['state'], $this->messages());
    }

    private function buffer(): DeferredModelEvents
    {
        return $this->app->make(DeferredModelEvents::class);
    }

    private function assertUsage(int $count, int $bytes): void
    {
        $this->assertSame(['count' => $count, 'bytes' => $bytes], $this->buffer()->pendingUsage());
    }

    private function encodedSize(string $state): int
    {
        return strlen(json_encode([
            'laravel_model_created',
            ['model' => CollectedRecord::class, 'attributes' => ['state' => $state]],
            ['distinctId' => 'original', 'groups' => ['team' => 'original']],
        ]));
    }

    private function assertTransactionCallbackCount(int $expected): void
    {
        $transactions = $this->app['db.transactions'];
        $records = $transactions->getPendingTransactions()->merge($transactions->getCommittedTransactions());
        $this->assertSame($expected, $records->sum(fn ($record) => count($record->getCallbacks())));
    }

    public function testOtherConnectionCommitCannotPublishRolledBackEvents(): void
    {
        DB::connection('a')->beginTransaction();
        DB::connection('b')->beginTransaction();
        $this->record('rolled back');
        DB::connection('b')->commit();
        PostHog::assertNothingCaptured();
        DB::connection('a')->rollBack();
        PostHog::assertNothingCaptured();
        $this->assertUsage(0, 0);
    }

    public function testOwningConnectionCommitAndNontransactionalWritesDoNotWaitForOtherConnection(): void
    {
        DB::connection('a')->beginTransaction();
        DB::connection('b')->beginTransaction();
        $this->record('committed');
        $this->identity('later');
        DB::connection('a')->commit();
        $this->assertSame(['committed'], $this->states());
        $this->assertSame('original', $this->messages()[0]['distinctId']);
        $this->record('immediate');
        $this->assertSame(['committed', 'immediate'], $this->states());
        DB::connection('b')->rollBack();
        $this->assertUsage(0, 0);
    }

    public function testAfterCommitCallbackCanCommitOtherConnectionWithoutLosingOriginalBatch(): void
    {
        DB::connection('a')->beginTransaction();
        DB::connection('a')->afterCommit(fn () => DB::connection('b')->commit());
        DB::connection('b')->beginTransaction();
        $this->record('a first');
        $this->record('b', 'b');
        $this->record('a second');
        $this->identity('later');
        DB::connection('a')->commit();
        $this->assertSame(['b', 'a first', 'a second'], $this->states());
        $this->assertSame(['original', 'original', 'original'], array_column($this->messages(), 'distinctId'));
        $this->assertUsage(0, 0);
    }

    public function testAfterCommitCallbackCanStartAnotherTransactionWithoutPublishingItsEvents(): void
    {
        DB::beginTransaction();
        DB::afterCommit(function () {
            DB::beginTransaction();
            $this->record('next transaction');
        });
        $this->record('original transaction');
        $this->identity('later');
        DB::commit();
        $this->assertSame(['original transaction'], $this->states());
        PostHog::assertCapturedCount(1);
        DB::commit();
        $this->assertSame(['original transaction', 'next transaction'], $this->states());
        $this->assertSame(['original', 'later'], array_column($this->messages(), 'distinctId'));
        $this->assertUsage(0, 0);
    }

    public function testAfterCommitCallbackRollbackCannotDiscardTheCommittedRoot(): void
    {
        DB::beginTransaction();
        DB::afterCommit(function () {
            DB::beginTransaction();
            $this->record('rolled back callback transaction');
            DB::rollBack();
        });
        $this->record('committed original');
        DB::commit();
        $this->assertSame(['committed original'], $this->states());
        $this->assertUsage(0, 0);
    }

    public function testRollbackReleasesBudgetEvenWhenApplicationRetainsTransactionRecords(): void
    {
        DB::beginTransaction();
        $this->record('rolled back');
        $records = $this->app['db.transactions']->getPendingTransactions()->all();
        DB::rollBack();
        $this->assertUsage(0, 0);
        $this->assertCount(1, $records);
        PostHog::assertNothingCaptured();
    }

    public function testNestedCommitsPreserveEventOrderAndRollbackReleasesSavepointBudget(): void
    {
        DB::beginTransaction();
        $this->record('first');
        DB::beginTransaction();
        $this->record('discarded');
        DB::beginTransaction();
        $this->record('discarded committed child');
        DB::commit();
        DB::rollBack();
        $this->assertUsage(1, $this->encodedSize('first'));
        DB::beginTransaction();
        $this->record('second');
        DB::commit();
        $this->record('third');
        PostHog::assertNothingCaptured();
        $this->identity('later');
        DB::commit();
        $this->assertSame(['first', 'second', 'third'], $this->states());
        $this->assertSame(['original', 'original', 'original'], array_column($this->messages(), 'distinctId'));
        $this->assertUsage(0, 0);
        DB::beginTransaction();
        DB::beginTransaction();
        $this->record('committed child of rolled back parent');
        DB::commit();
        DB::rollBack();
        $this->assertSame(['first', 'second', 'third'], $this->states());
        $this->assertUsage(0, 0);
    }

    public function testCountAdmissionIsAggregateDropNewestAndBoundsCallbackBookkeeping(): void
    {
        DB::connection('a')->beginTransaction();
        DB::connection('b')->beginTransaction();
        for ($i = 0; $i < 1100; $i++) {
            $this->record(sprintf('%04d', $i), $i % 2 === 0 ? 'a' : 'b');
        }
        $this->assertTransactionCallbackCount(2);
        $this->assertUsage(1000, 1000 * $this->encodedSize('0000'));
        PostHog::assertNothingCaptured();
        DB::connection('a')->rollBack();
        $this->assertUsage(500, 500 * $this->encodedSize('0000'));
        $this->record('new after release', 'b');
        DB::connection('b')->commit();
        PostHog::assertCapturedCount(501);
        $expected = array_map(fn ($i) => sprintf('%04d', $i), range(1, 999, 2));
        $this->assertSame([...$expected, 'new after release'], $this->states());
        $this->assertUsage(0, 0);
    }

    public function testDistinctTransactionRecordsCannotGrowCallbacksBeyondAdmissionBudget(): void
    {
        DB::beginTransaction();
        for ($i = 0; $i < 1100; $i++) {
            DB::beginTransaction();
            $this->record('same');
            DB::commit();
        }
        $this->assertUsage(1000, 1000 * $this->encodedSize('same'));
        $this->assertTransactionCallbackCount(1000);
        PostHog::assertNothingCaptured();
        DB::commit();
        PostHog::assertCapturedCount(1000);
        $this->assertUsage(0, 0);
    }

    public function testConnectionResetsCannotAccumulateUnboundedOrphanedCallbacks(): void
    {
        $connection = DB::connection('a');
        for ($i = 0; $i < 1100; $i++) {
            if ($i !== 0) {
                // Laravel 12 disconnect resets PDO/depth without clearing manager records.
                $connection->setPdo(new \PDO('sqlite::memory:'));
                $connection->getSchemaBuilder()->create('collected_records', function (Blueprint $table) {
                    $table->id();
                    $table->text('state');
                    $table->text('email');
                });
            }
            $connection->beginTransaction();
            $this->record('abandoned');
        }
        $this->assertUsage(0, 0);
        $this->assertTransactionCallbackCount(1000);
        $connection->commit();
        PostHog::assertNothingCaptured();
        DB::transaction(fn () => $this->record('fresh'));
        $this->assertSame(['fresh'], $this->states());
        $this->assertUsage(0, 0);
    }

    public function testByteAdmissionIncludesContextAndDropsOversizedApprovedAttributes(): void
    {
        DB::beginTransaction();
        $this->record(str_repeat('x', 1048576));
        $this->assertTransactionCallbackCount(0);
        $this->assertUsage(0, 0);
        $state = str_repeat('x', 1048576 - $this->encodedSize(''));
        $this->record($state);
        $this->assertUsage(1, 1048576);
        $this->record('drop newest');
        $this->assertUsage(1, 1048576);
        PostHog::assertNothingCaptured();
        DB::commit();
        $this->assertSame([$state], $this->states());
        $this->assertUsage(0, 0);
        DB::beginTransaction();
        $this->record('fits after commit');
        DB::commit();
        $this->assertSame([$state, 'fits after commit'], $this->states());
    }

    public function testCommitFailureRetryDiscardsFailedAttemptBeforeReadmittingEvents(): void
    {
        $commits = 0;
        $this->app['events']->listen(TransactionCommitting::class, function ($event) use (&$commits) {
            if ($event->connectionName === 'a' && ++$commits === 1) {
                throw new RuntimeException('database is locked');
            }
        });
        $pdo = DB::connection('a')->getPdo();
        $attempt = 0;
        $usageOnRetry = null;
        DB::transaction(function () use (&$attempt, &$usageOnRetry) {
            if (++$attempt === 1) {
                for ($i = 0; $i < 1000; $i++) {
                    $this->record('failed attempt');
                }
                $this->assertUsage(1000, 1000 * $this->encodedSize('failed attempt'));
            } else {
                $usageOnRetry = $this->buffer()->pendingUsage();
                $this->record('committed retry');
            }
            PostHog::assertNothingCaptured();
        }, 2);
        $this->assertSame(2, $attempt);
        $this->assertSame($pdo, DB::connection('a')->getPdo());
        $this->assertSame(['count' => 0, 'bytes' => 0], $usageOnRetry);
        $this->assertSame(['committed retry'], $this->states());
        $this->assertSame(1, CollectedRecord::count());
        $this->assertUsage(0, 0);
        $this->assertTransactionCallbackCount(0);
        DB::transaction(fn () => $this->record('next transaction'));
        $this->assertSame(['committed retry', 'next transaction'], $this->states());
        $this->assertUsage(0, 0);
    }

    public function testRetryAndRepeatedSavepointRollbackReleaseCapacityWithoutCallbackAccumulation(): void
    {
        $attempt = 0;
        DB::transaction(function () use (&$attempt) {
            $this->record('attempt ' . ++$attempt);
            if ($attempt === 1) {
                throw new RuntimeException('database is locked');
            }
        }, 2);
        $this->assertSame(['attempt 2'], $this->states());
        $this->assertUsage(0, 0);
        DB::beginTransaction();
        for ($i = 0; $i < 1100; $i++) {
            DB::beginTransaction();
            $this->record('discarded');
            DB::rollBack();
        }
        $this->assertUsage(0, 0);
        $this->assertTransactionCallbackCount(0);
        $this->record('final');
        DB::commit();
        $this->assertSame(['attempt 2', 'final'], $this->states());
    }

    public function testNoIdentityConsumesNoPendingCapacityAndModelIsNotRetained(): void
    {
        $this->app->make(Context::class)->begin([]);
        DB::beginTransaction();
        $this->record('no identity');
        $this->assertUsage(0, 0);
        $this->assertTransactionCallbackCount(0);
        $this->identity('original');
        $model = $this->record('admitted');
        $reference = \WeakReference::create($model);
        unset($model);
        gc_collect_cycles();
        $this->assertNull($reference->get());
        DB::rollBack();
        $this->assertUsage(0, 0);
        PostHog::assertNothingCaptured();
    }

    public function testDisconnectReleasesAbandonedTransactionBudgetBeforeNextAdmission(): void
    {
        DB::beginTransaction();
        $this->record('abandoned');
        DB::disconnect('a');
        DB::connection('b')->beginTransaction();
        $this->record('fresh', 'b');
        $this->assertUsage(1, $this->encodedSize('fresh'));
        DB::connection('b')->commit();
        $this->assertUsage(0, 0);
        $this->assertSame(['fresh'], $this->states());
    }
}
