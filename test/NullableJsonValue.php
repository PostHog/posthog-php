<?php

namespace PostHog\Test;

class NullableJsonValue implements \JsonSerializable
{
    public int $calls = 0;

    public function __construct(private $value)
    {
    }

    public function jsonSerialize(): mixed
    {
        $this->calls++;
        return $this->value;
    }
}
