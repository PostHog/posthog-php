<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use WeakMap;
use WeakReference;

/** @internal One application-wide budget, with at most one callback per admitted transaction record. */
class DeferredModelEvents
{
    private const MAX_EVENTS = 1000;
    private const MAX_BYTES = 1048576;

    /**
     * @var array<int, array{
     *     connection: WeakReference, pdo: WeakReference, transaction: WeakReference,
     *     root: WeakReference, encoded: string
     * }>
     */
    private array $pending = [];
    private int $bytes = 0;
    /** @var WeakMap<DatabaseTransactionRecord, int> Number of pending events per registered record. */
    private WeakMap $records;
    /** @var WeakMap<Connection, array{root: WeakReference, count: int}> Only connections with admitted events. */
    private WeakMap $connections;

    public function __construct(private DatabaseTransactionsManager $transactions)
    {
        $this->records = new WeakMap();
        $this->connections = new WeakMap();
    }

    public function defer(Connection $connection, string $event, array $properties, array $snapshot): void
    {
        $this->prune();
        if (count($this->pending) >= self::MAX_EVENTS) {
            return;
        }
        $encoded = json_encode([$event, $properties, $snapshot], JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES - $this->bytes) {
            return;
        }
        // Connection::afterCommit forwards to a shared manager without a connection name.
        $record = $this->transactions->getPendingTransactions()->last(
            fn ($record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel(),
        );
        if ($record === null) {
            return;
        }
        // Laravel 12 can retain old records after a PDO reset, even after their payloads are discarded.
        if (!isset($this->records[$record]) && count($this->records) >= self::MAX_EVENTS) {
            return;
        }
        $root = $record;
        // A reset can leave an older level-1 record as parent; it is not this transaction's root.
        while ($root->parent !== null && $root->parent->level < $root->level) {
            $root = $root->parent;
        }
        $rootReference = WeakReference::create($root);
        if (!isset($this->records[$record])) {
            // Admission precedes registration: rejected writes cannot grow callback bookkeeping.
            // Flush this root's batch even if an earlier application callback starts another transaction.
            $record->addCallback(function () use ($rootReference) {
                if (($root = $rootReference->get()) !== null) {
                    $this->flush($root);
                }
            });
            $this->records[$record] = 0;
        }
        $this->records[$record]++;
        $this->connections[$connection] = [
            'root' => $rootReference,
            'count' => ($this->connections[$connection]['count'] ?? 0) + 1,
        ];
        $this->pending[] = [
            'connection' => WeakReference::create($connection),
            'pdo' => WeakReference::create($connection->getRawPdo()),
            'transaction' => WeakReference::create($record),
            'root' => $rootReference,
            'encoded' => $encoded,
        ];
        $this->bytes += strlen($encoded);
    }

    public function beginning(TransactionBeginning $event): void
    {
        $this->prune();
        $connection = $event->connection;
        if (!isset($this->connections[$connection])) {
            return;
        }
        $root = $this->transactions->getPendingTransactions()->last(
            fn ($record) => $record->connection === $connection->getName(),
        );
        if ($root === null) {
            return;
        }
        while ($root->parent !== null && $root->parent->level < $root->level) {
            $root = $root->parent;
        }
        if ($connection->transactionLevel() === 1) {
            $pending = $this->transactions->getPendingTransactions();
            $committed = $this->transactions->getCommittedTransactions();
            foreach ($this->pending as $key => $entry) {
                $olderRoot = $entry['root']->get();
                if (
                    $entry['connection']->get() === $connection && $olderRoot !== $root
                    && ($pending->containsStrict($olderRoot) || $committed->containsStrict($olderRoot))
                ) {
                    // Laravel 12 commit retries can leave rolled-back roots managed on the same PDO.
                    // Unmanaged roots may still be delivering successful commit callbacks; keep them.
                    $this->remove($key);
                }
            }
        }
        if (!isset($this->connections[$connection])) {
            return;
        }
        // A callback may start/roll back a new root while an older committed root is awaiting its callback.
        $state = $this->connections[$connection];
        $state['root'] = WeakReference::create($root);
        $this->connections[$connection] = $state;
    }

    public function committing(TransactionCommitting $event): void
    {
        $this->prune();
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        $state = $this->connections[$event->connection] ?? null;
        $this->prune($state === null ? null : $state['root']->get());
    }

    /** @return array{count: int, bytes: int} */
    public function pendingUsage(): array
    {
        return ['count' => count($this->pending), 'bytes' => $this->bytes];
    }

    private function flush(DatabaseTransactionRecord $root): void
    {
        $ready = [];
        foreach ($this->pending as $key => $entry) {
            if ($entry['root']->get() === $root) {
                $ready[] = $entry['encoded'];
                $this->remove($key);
            }
        }
        $this->prune();
        // Release capacity before capture and preserve operation order across nested commits.
        foreach ($ready as $encoded) {
            [$name, $properties, $snapshot] = json_decode($encoded, true);
            Collector::capture($name, $properties, $snapshot);
        }
    }

    private function prune(?DatabaseTransactionRecord $rolledBackRoot = null): void
    {
        $pending = $this->transactions->getPendingTransactions();
        $committed = $this->transactions->getCommittedTransactions();
        $managed = static fn ($record) => $pending->containsStrict($record) || $committed->containsStrict($record);
        foreach ($this->pending as $key => $entry) {
            $connection = $entry['connection']->get();
            $record = $entry['transaction']->get();
            $root = $entry['root']->get();
            if (
                $connection === null || $connection->getRawPdo() === null
                || $entry['pdo']->get() !== $connection->getRawPdo()
                || $record === null || $root === null
                || ($rolledBackRoot !== null && $root === $rolledBackRoot && !$managed($record))
                || (!$managed($record) && $managed($root))
                || ($connection->transactionLevel() === 0 && $managed($record))
            ) {
                $this->remove($key);
            }
            // Missing records with a live root can be in Laravel's commit-callback stack.
            // Only their own record callback may publish them; another connection cannot discard them.
        }
        // Keep zero-payload registrations counted until Laravel releases their records.
        foreach ($this->records as $record => $count) {
            if ($count === 0 && !$managed($record)) {
                unset($this->records[$record]);
            }
        }
    }

    private function remove(int $key): void
    {
        $entry = $this->pending[$key];
        $this->bytes -= strlen($entry['encoded']);
        $record = $entry['transaction']->get();
        if ($record !== null && isset($this->records[$record])) {
            --$this->records[$record];
        }
        $connection = $entry['connection']->get();
        if ($connection !== null && isset($this->connections[$connection])) {
            $state = $this->connections[$connection];
            if (--$state['count'] === 0) {
                unset($this->connections[$connection]);
            } else {
                $this->connections[$connection] = $state;
            }
        }
        unset($this->pending[$key]);
        if ($this->pending === []) {
            // Reset PHP's next numeric index when a long-lived worker drains a batch.
            $this->pending = [];
        }
    }
}
