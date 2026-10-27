<?php

namespace PostHog\Test;

use JsonSerializable;

enum NonFiniteJsonSerializableEnum implements JsonSerializable
{
    case NotANumber;
    case PositiveInfinity;
    case NegativeInfinity;

    public function jsonSerialize(): array
    {
        return [
            'number' => match ($this) {
                self::NotANumber => NAN,
                self::PositiveInfinity => INF,
                self::NegativeInfinity => -INF,
            },
            'finite' => 2.5,
            'literal' => 'NaN',
        ];
    }
}
