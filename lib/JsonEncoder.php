<?php

namespace PostHog;

use JsonSerializable;
use stdClass;
use UnitEnum;

/**
 * Encodes event payloads with non-finite numbers represented as null.
 *
 * @internal
 */
final class JsonEncoder implements JsonSerializable
{
    private function __construct(
        private mixed $value,
        private int $depth,
        private int $serializerHops
    ) {
    }

    public static function encode(mixed $value): string|false
    {
        return json_encode(self::prepare($value, 0));
    }

    private static function prepare(mixed $value, int $depth, int $serializerHops = 0): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        if (is_array($value) || (is_object($value) && !$value instanceof UnitEnum)) {
            return $depth < 512 && $serializerHops < 512 ? new self($value, $depth, $serializerHops) : $value;
        }

        return $value;
    }

    public function jsonSerialize(): mixed
    {
        $value = $this->value;
        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();
            if ($serialized !== $value) {
                return self::prepare($serialized, $this->depth, $this->serializerHops + 1);
            }
        }

        // Container depth and serializer hops are bounded before handing recursive input to json_encode.
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::prepare($item, $this->depth + 1);
            }
        } else {
            $result = new stdClass();
            foreach ((array) $value as $key => $item) {
                if (!str_contains((string) $key, "\0")) {
                    $result->{(string) $key} = self::prepare($item, $this->depth + 1);
                }
            }
        }

        return $result;
    }
}
