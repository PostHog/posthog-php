<?php

namespace PostHog;

use ArrayObject;
use JsonSerializable;
use ReflectionClass;
use ReflectionReference;
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
        private ArrayObject $wrappers
    ) {
    }

    public static function encode(mixed $value): string|false
    {
        $wrappers = new ArrayObject();
        try {
            return json_encode(self::prepare($value, 0, $wrappers));
        } finally {
            $wrappers->exchangeArray([]);
        }
    }

    private static function prepare(
        mixed $value,
        int $depth,
        ArrayObject $wrappers,
        ?string $referenceId = null
    ): mixed {
        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        if (is_array($value) || (is_object($value) && !$value instanceof UnitEnum)) {
            $identity = is_object($value) ? 'object:' . spl_object_id($value) : $referenceId;
            if ($identity !== null && isset($wrappers[$identity])) {
                return $wrappers[$identity];
            }
            if ($depth >= 512) {
                return $value;
            }
            $wrapper = new self($value, $depth, $wrappers);
            if ($identity !== null) {
                $wrappers[$identity] = $wrapper;
            }
            return $wrapper;
        }

        return $value;
    }

    public function jsonSerialize(): mixed
    {
        $value = $this->value;
        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();
            if ($serialized !== $value) {
                return self::prepare($serialized, $this->depth, $this->wrappers);
            }
        }

        // Reuse wrappers for original identities so json_encode can detect recursion before expanding it.
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $reference = ReflectionReference::fromArrayElement($value, $key);
                $referenceId = $reference !== null ? 'reference:' . $reference->getId() : null;
                $result[$key] = self::prepare($item, $this->depth + 1, $this->wrappers, $referenceId);
            }
        } else {
            $result = new stdClass();
            $class = new ReflectionClass($value);
            while (!$class->isInternal() && ($parent = $class->getParentClass()) !== false) {
                $class = $parent;
            }
            $properties = $class->isInternal() ? (array) $value : get_object_vars($value);
            foreach ($properties as $key => $item) {
                if (!str_contains((string) $key, "\0")) {
                    $result->{(string) $key} = self::prepare($item, $this->depth + 1, $this->wrappers);
                }
            }
        }

        return $result;
    }
}
