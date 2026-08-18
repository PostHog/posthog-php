<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use stdClass;

abstract class JsonSnapshotTestCase extends TestCase
{
    /** @var array<string, string> */
    private array $uuidPlaceholders = [];

    protected function assertJsonSnapshot(string $name, mixed $actual): void
    {
        $normalized = $this->normalizeSnapshotValue($actual);
        $rendered = json_encode(
            $normalized,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        ) . "\n";
        $path = __DIR__ . '/assests/snapshots/' . $name;

        if (getenv('UPDATE_EVENT_SHAPE_SNAPSHOTS') === '1') {
            file_put_contents($path, $rendered);
        }

        self::assertFileExists($path, "Missing snapshot {$name}");
        self::assertSame(
            file_get_contents($path),
            $rendered,
            "Snapshot {$name} changed. Re-record with UPDATE_EVENT_SHAPE_SNAPSHOTS=1."
        );
    }

    protected function normalizeSnapshotValue(mixed $value): mixed
    {
        $this->uuidPlaceholders = [];

        return $this->normalizeValue($value);
    }

    private function normalizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key === 'api_key' || $key === 'personal_api_key' || $key === 'secret_key') {
            return '<redacted>';
        }

        if ($key === '$lib_version' && is_string($value) && $this->isSdkReleaseVersion($value)) {
            return '<sdk-version>';
        }

        if (
            is_string($value)
            && $key !== null
            && strcasecmp($key, 'User-Agent') === 0
            && str_starts_with($value, 'posthog-php/')
            && $this->isSdkReleaseVersion(substr($value, strlen('posthog-php/')))
        ) {
            return 'posthog-php/<sdk-version>';
        }

        if (is_string($value)) {
            if ($this->isUuid($value)) {
                if (!isset($this->uuidPlaceholders[$value])) {
                    $this->uuidPlaceholders[$value] = '<uuid-' . (count($this->uuidPlaceholders) + 1) . '>';
                }

                return $this->uuidPlaceholders[$value];
            }

            return $value;
        }

        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $normalized = new stdClass();
            foreach ($properties as $property => $propertyValue) {
                $normalized->{$property} = $this->normalizeValue($propertyValue, $property);
            }

            return $normalized;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn(mixed $item): mixed => $this->normalizeValue($item), $value);
            }

            ksort($value, SORT_STRING);
            $normalized = [];
            foreach ($value as $property => $propertyValue) {
                $normalized[$property] = $this->normalizeValue($propertyValue, (string) $property);
            }

            return $normalized;
        }

        return $value;
    }

    private function isSdkReleaseVersion(string $value): bool
    {
        return preg_match('/^\d+\.\d+\.\d+\z/', $value) === 1;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
