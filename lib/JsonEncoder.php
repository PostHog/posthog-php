<?php

namespace PostHog;

use ArrayIterator;
use ArrayObject;
use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use ReflectionMethod;
use ReflectionReference;
use SplObjectStorage;
use stdClass;
use UnitEnum;
use WeakReference;

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
        private ArrayObject $wrappers,
        private bool $plainData,
        private ?string $readReferenceId = null
    ) {
    }

    public static function encode(mixed $value): string|false
    {
        $wrappers = new ArrayObject();
        try {
            $plainData = self::isPlainData($value, 0, new SplObjectStorage());
            return json_encode(self::prepare($value, 0, $wrappers, $plainData));
        } finally {
            $wrappers->exchangeArray([]);
        }
    }

    // Original objects are safe to reuse only when no callback can mutate a later sibling.
    // References and unknown/callback-capable types conservatively retain deferred preparation.
    private static function isPlainData(mixed $value, int $depth, SplObjectStorage $active): bool
    {
        if ($depth >= 512) {
            return false;
        }
        if (is_object($value)) {
            $class = get_class($value);
            $native = $class === DateTime::class
                || $class === DateTimeImmutable::class
                || $class === DateTimeZone::class
                || $class === ArrayObject::class
                || $class === ArrayIterator::class;
            if (($class !== stdClass::class && !$native) || isset($active[$value])) {
                return false;
            }
            $active[$value] = true;
            try {
                return self::isPlainData(self::nativeProperties($value), $depth + 1, $active);
            } finally {
                unset($active[$value]);
            }
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (
                    ReflectionReference::fromArrayElement($value, $key) !== null
                    || (is_string($key) && str_starts_with($key, "\0"))
                    || !self::isPlainData($item, $depth + 1, $active)
                ) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || is_scalar($value);
    }

    private static function prepare(
        mixed $value,
        int $depth,
        ArrayObject $wrappers,
        bool $plainData,
        ?string $referenceId = null,
        ?bool &$changed = null
    ): mixed {
        $changed = false;
        if (is_float($value) && !is_finite($value)) {
            $changed = true;
            return null;
        }
        if ($depth >= 512) {
            return $value;
        }

        if (is_array($value) && $referenceId === null) {
            $hasReferences = false;
            foreach ($value as $key => $item) {
                if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                    $hasReferences = true;
                    break;
                }
            }
            if (!$hasReferences) {
                return self::prepareArray($value, $depth, $wrappers, $plainData, $changed);
            }
        }
        if ($plainData && is_object($value) && get_class($value) === stdClass::class) {
            $properties = self::prepareArray(get_object_vars($value), $depth, $wrappers, true, $changed);
            return $changed ? (object) $properties : $value;
        }

        if (
            is_array($value)
            || $value instanceof JsonSerializable
            || (is_object($value) && !$value instanceof UnitEnum && !$value instanceof Closure)
        ) {
            $changed = true;
            $identity = is_object($value) ? 'object:' . spl_object_id($value) : $referenceId;
            if ($identity !== null && isset($wrappers[$identity])) {
                $wrapper = $wrappers[$identity]->get();
                if ($wrapper !== null) {
                    return $wrapper;
                }
            }
            $wrapper = new self($value, $depth, $wrappers, $plainData);
            if ($identity !== null) {
                $wrappers[$identity] = WeakReference::create($wrapper);
            }
            return $wrapper;
        }

        return $value;
    }

    private static function prepareArray(
        array $value,
        int $depth,
        ArrayObject $wrappers,
        bool $plainData,
        bool &$changed
    ): array {
        $changed = false;
        $result = [];
        $position = 0;
        foreach ($value as $key => $item) {
            $childChanged = false;
            $prepared = self::prepare($item, $depth + 1, $wrappers, $plainData, null, $childChanged);
            if ($childChanged && !$changed) {
                // Copy by value into a new array: writing a COW copy can mutate caller reference cells.
                $remaining = $position;
                foreach ($value as $prefixKey => $prefixValue) {
                    if ($remaining-- === 0) {
                        break;
                    }
                    $result[$prefixKey] = $prefixValue;
                }
                $changed = true;
            }
            if ($changed) {
                $result[$key] = $prepared;
            }
            ++$position;
        }
        return $changed ? $result : $value;
    }

    private static function prepareReference(
        array $parent,
        int|string $key,
        int $depth,
        ArrayObject $wrappers,
        bool $plainData,
        ReflectionReference $reference
    ): self {
        $referenceId = 'reference:' . $reference->getId();
        $readerId = 'reader:' . $referenceId;
        if (isset($wrappers[$readerId])) {
            $reader = $wrappers[$readerId]->get();
            if ($reader !== null) {
                return $reader;
            }
        }
        $reader = new self(null, $depth, $wrappers, $plainData, $referenceId);
        // ReflectionReference proves this cell already exists; never bind ordinary input slots.
        $reader->value = &$parent[$key];
        $wrappers[$readerId] = WeakReference::create($reader);
        return $reader;
    }

    private static function nativeProperties(object $value): array
    {
        $usesStorage = $value instanceof DateTimeInterface
            || $value instanceof DateTimeZone
            || (($value instanceof ArrayObject || $value instanceof ArrayIterator)
                && !(self::nativeArrayFlags($value) & ArrayObject::STD_PROP_LIST));
        return $usesStorage ? (array) $value : get_object_vars($value);
    }

    private static function nativeArrayFlags(ArrayObject|ArrayIterator $value): int
    {
        $nativeClass = $value instanceof ArrayObject ? ArrayObject::class : ArrayIterator::class;
        return (new ReflectionMethod($nativeClass, 'getFlags'))->invoke($value);
    }

    public function jsonSerialize(): mixed
    {
        if ($this->readReferenceId !== null) {
            return self::prepare(
                $this->value,
                $this->depth,
                $this->wrappers,
                $this->plainData,
                $this->readReferenceId
            );
        }
        $value = $this->value;
        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();
            if ($serialized !== $value) {
                return self::prepare($serialized, $this->depth, $this->wrappers, $this->plainData);
            }
        }

        // Reuse wrappers for original identities so json_encode can detect recursion before expanding it.
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $reference = ReflectionReference::fromArrayElement($value, $key);
                $result[$key] = $reference !== null
                    ? self::prepareReference(
                        $value,
                        $key,
                        $this->depth + 1,
                        $this->wrappers,
                        $this->plainData,
                        $reference
                    )
                    : self::prepare($item, $this->depth + 1, $this->wrappers, $this->plainData);
            }
        } else {
            $result = new stdClass();
            $properties = self::nativeProperties($value);
            foreach ($properties as $key => $item) {
                if (!str_starts_with((string) $key, "\0")) {
                    $reference = ReflectionReference::fromArrayElement($properties, $key);
                    $result->{(string) $key} = $reference !== null
                        ? self::prepareReference(
                            $properties,
                            $key,
                            $this->depth + 1,
                            $this->wrappers,
                            $this->plainData,
                            $reference
                        )
                        : self::prepare($item, $this->depth + 1, $this->wrappers, $this->plainData);
                }
            }
        }

        return $result;
    }
}
