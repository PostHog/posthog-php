<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Support\Collection;
use Traversable;

class CountedTransactionCollection extends Collection
{
    public int $inspections = 0;

    public function containsStrict($key, $value = null): bool
    {
        foreach ($this as $record) {
            if ($record === $key) {
                return true;
            }
        }
        return false;
    }

    public function getIterator(): Traversable
    {
        foreach ($this->items as $key => $record) {
            $this->inspections++;
            yield $key => $record;
        }
    }
}
