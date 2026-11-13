<?php

namespace App\Events;

final class OrderCompleted
{
    public function __construct(public readonly int $amount)
    {
    }
}
