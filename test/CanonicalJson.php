<?php

namespace PostHog\Test;

use stdClass;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new stdClass();
            foreach ($properties as $key => $item) {
                $sorted->{(string) $key} = self::canonicalize($item);
            }

            return $sorted;
        }
        if (is_array($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        return $value;
    }
}
