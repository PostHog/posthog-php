<?php

namespace PostHog;

use ArrayIterator;
use ArrayObject;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionReference;
use SplObjectStorage;
use stdClass;
use UnitEnum;

/**
 * Encodes event payloads with non-finite numbers represented as null.
 *
 * @internal
 */
final class JsonEncoder
{
    private const MAX_REFERENCE_SCAN_STEPS = 1000000;

    private string $output = '';
    private int $error = JSON_ERROR_NONE;
    private mixed $errorValue = null;
    private array $references = [];
    private array $propertyLayouts = [];
    private SplObjectStorage $objects;

    private function __construct()
    {
        $this->objects = new SplObjectStorage();
    }

    public static function encode(mixed $value): string|false
    {
        $encoder = new self();
        if ($encoder->writeValue($value, 0) && $encoder->error === JSON_ERROR_NONE) {
            json_encode(null);
            return $encoder->output;
        }
        $encoder->restoreError();
        return false;
    }

    private function writeValue(mixed $value, int $depth, ?string $referenceId = null): bool
    {
        if (is_float($value) && !is_finite($value)) {
            $value = null;
        }
        if (is_array($value)) {
            if ($referenceId !== null && isset($this->references[$referenceId])) {
                $references = [$referenceId => true];
                $remaining = self::MAX_REFERENCE_SCAN_STEPS;
                $cycle = self::hasArrayCycle($value, $references, $remaining, 0);
                if ($cycle !== false) {
                    $this->error = $cycle === true ? JSON_ERROR_RECURSION : JSON_ERROR_DEPTH;
                    return false;
                }
            }
            if ($depth >= 512) {
                $this->error = JSON_ERROR_DEPTH;
                return false;
            }
            if ($value === []) {
                $this->output .= '[]';
                return true;
            }
            if ($referenceId !== null) {
                $this->references[$referenceId] = ($this->references[$referenceId] ?? 0) + 1;
            }
            try {
                return $this->writeArray($value, $depth);
            } finally {
                if ($referenceId !== null && --$this->references[$referenceId] === 0) {
                    unset($this->references[$referenceId]);
                }
            }
        }
        if (
            is_object($value)
            && ($value instanceof JsonSerializable || (!$value instanceof UnitEnum && !$value instanceof Closure))
        ) {
            return $this->writeObject($value, $depth);
        }
        $encoded = $this->encodeScalar($value);
        if ($encoded === false) {
            return false;
        }
        $this->output .= $encoded;
        return true;
    }

    private static function hasArrayCycle(array $value, array &$references, int &$remaining, int $depth): ?bool
    {
        // Unlike encoding, this bounded scan invokes no callbacks, so reference cells cannot be reassigned mid-scan.
        if ($depth >= 512) {
            return null;
        }
        foreach ($value as $key => $item) {
            if (--$remaining < 0) {
                return null;
            }
            if (!is_array($item)) {
                continue;
            }
            $id = ReflectionReference::fromArrayElement($value, $key)?->getId();
            if ($id !== null && isset($references[$id])) {
                return true;
            }
            if ($id !== null) {
                $references[$id] = true;
            }
            $cycle = self::hasArrayCycle($item, $references, $remaining, $depth + 1);
            if ($id !== null) {
                unset($references[$id]);
            }
            if ($cycle !== false) {
                return $cycle;
            }
        }
        return false;
    }

    private function writeArray(array $value, int $depth, bool $object = false): bool
    {
        $list = !$object && array_is_list($value);
        $this->output .= $list ? '[' : '{';
        $first = true;
        foreach ($value as $key => $item) {
            if ($object && str_starts_with((string) $key, "\0")) {
                continue;
            }
            if (!$first) {
                $this->output .= ',';
            }
            $first = false;
            if (!$list) {
                $this->writeKey((string) $key);
            }
            $reference = ReflectionReference::fromArrayElement($value, $key);
            if (!$this->writeValue($item, $depth + 1, $reference?->getId())) {
                return false;
            }
        }
        $this->output .= $list ? ']' : '}';
        return true;
    }

    private function encodeScalar(mixed $value): string|false
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $this->error = $error->getCode();
            $this->errorValue = $value;
            return false;
        }
    }

    private function writeKey(string $key): void
    {
        $encoded = $this->encodeScalar($key);
        if ($encoded === false) {
            // Native JSON continues visiting members after a malformed key, but never returns partial output.
            $encoded = '""';
        }
        $this->output .= $encoded . ':';
    }

    private function writeObject(object $value, int $depth): bool
    {
        if (isset($this->objects[$value])) {
            $this->error = JSON_ERROR_RECURSION;
            return false;
        }
        $this->objects[$value] = true;
        try {
            if ($value instanceof JsonSerializable) {
                $serialized = $value->jsonSerialize();
                if ($serialized !== $value) {
                    return $this->writeValue($serialized, $depth);
                }
            }
            if ($depth >= 512) {
                $this->error = JSON_ERROR_DEPTH;
                return false;
            }
            if (self::usesStorage($value)) {
                return $this->writeArray((array) $value, $depth, true);
            }
            if (get_class($value) === stdClass::class || (new ReflectionClass($value))->isInternal()) {
                return $this->writeArray(get_object_vars($value), $depth, true);
            }
            $layout = $this->propertyLayout($value);
            // Casting avoids permanent property tables and does not invoke declared getters ahead of their slot.
            $properties = (array) $value;
            $this->output .= '{';
            $first = true;
            foreach ($layout as $name => $property) {
                unset($properties[$name]);
                if (PHP_VERSION_ID >= 80400 && $property->hasHooks()) {
                    if ($property->isVirtual() && !$property->hasHook(\PropertyHookType::Get)) {
                        continue;
                    }
                } elseif (!$property->isInitialized($value)) {
                    continue;
                }
                if (!$first) {
                    $this->output .= ',';
                }
                $first = false;
                $this->writeKey($name);
                if (!$this->writeValue($value->{$name}, $depth + 1)) {
                    return false;
                }
            }
            foreach ($properties as $name => $item) {
                if (str_starts_with((string) $name, "\0")) {
                    continue;
                }
                if (!$first) {
                    $this->output .= ',';
                }
                $first = false;
                $this->writeKey((string) $name);
                $reference = ReflectionReference::fromArrayElement($properties, $name);
                if (!$this->writeValue($item, $depth + 1, $reference?->getId())) {
                    return false;
                }
            }
            $this->output .= '}';
            return true;
        } finally {
            unset($this->objects[$value]);
        }
    }

    private function propertyLayout(object $value): array
    {
        $name = get_class($value);
        if (array_key_exists($name, $this->propertyLayouts)) {
            return $this->propertyLayouts[$name];
        }
        $classes = [];
        $class = new ReflectionClass($value);
        do {
            $classes[] = $class;
        } while (($class = $class->getParentClass()) !== false);
        $slots = [];
        foreach (array_reverse($classes) as $class) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $key = $property->isPrivate()
                    ? "\0" . $class->getName() . "\0" . $property->getName()
                    : $property->getName();
                $slots[$key] = $property;
            }
        }
        return $this->propertyLayouts[$name] = array_filter(
            $slots,
            static fn ($property) => $property->isPublic()
        );
    }

    private static function usesStorage(object $value): bool
    {
        if (
            $value instanceof DateTimeInterface
            || $value instanceof DateTimeZone
            || $value instanceof \SimpleXMLElement
        ) {
            return true;
        }
        if ($value instanceof ArrayObject || $value instanceof ArrayIterator) {
            $nativeClass = $value instanceof ArrayObject ? ArrayObject::class : ArrayIterator::class;
            $flags = (new ReflectionMethod($nativeClass, 'getFlags'))->invoke($value);
            return !($flags & ArrayObject::STD_PROP_LIST);
        }
        return false;
    }

    private function restoreError(): void
    {
        // PHP has no json_last_error setter; replay only callback-free failures.
        if ($this->error === JSON_ERROR_RECURSION) {
            $value = [];
            $value[] = &$value;
        } elseif ($this->error === JSON_ERROR_DEPTH) {
            $value = [];
            for ($i = 0; $i < 513; ++$i) {
                $value = [$value];
            }
        } else {
            $value = $this->errorValue;
        }
        json_encode($value);
    }
}
