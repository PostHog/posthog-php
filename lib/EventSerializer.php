<?php

namespace PostHog;

/**
 * JSON serialization policy for event properties, not other API/cache payloads.
 *
 * @internal
 */
final class EventSerializer
{
    /**
     * Encode events after PHP has resolved JsonSerializable values and object/list identity.
     *
     * @param mixed $payload Event or batch envelope.
     * @param bool $batch Whether this is a batch envelope.
     * @param string|null $error Receives a JSON or key-transform failure message.
     * @return string|false
     */
    public static function encode($payload, bool $batch = false, ?string &$error = null)
    {
        // Preserve floats (including negative zero) through the intermediate JSON tree.
        $error = null;
        $json = json_encode($payload, JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            $error = json_last_error_msg();
            return false;
        }

        // The encoder has already checked depth and cycles. Decoding must allow its full depth.
        $decoded = self::decodeTree($json, 514, $error);
        if ($error !== null) {
            return false;
        }
        if ($batch) {
            foreach ($decoded->members['batch'] as $event) {
                self::cleanEvent($event);
            }
        } else {
            self::cleanEvent($decoded);
        }

        $json = json_encode($decoded);
        if ($json === false) {
            $error = json_last_error_msg();
        }
        return $json;
    }

    /** Decode a file/CLI array envelope; $error distinguishes failure from a JSON null value. */
    public static function decode(string $json, ?string &$error = null)
    {
        $decoded = self::decodeTree($json, 512, $error);
        return $decoded instanceof JsonObject ? $decoded->members : $decoded;
    }

    private static function decodeTree(string $json, int $depth, ?string &$error)
    {
        $error = null;
        // Match complete string tokens, never a quote inside a value string. Prefix ALL keys
        // bijectively so even NUL-prefixed names are representable by native stdClass decoding.
        $prefixed = preg_replace_callback('/"(?:[^"\\\\]++|\\\\.)*+"(\s*:)?/s', static function ($match) {
            return isset($match[1]) ? '"_' . substr($match[0], 1) : $match[0];
        }, $json);
        if ($prefixed === null) {
            $error = 'JSON key transform failed: ' . preg_last_error_msg();
            return null;
        }
        $decoded = json_decode($prefixed, false, $depth);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg();
            return null;
        }
        return self::restoreKeys($decoded);
    }

    private static function restoreKeys($value)
    {
        if ($value instanceof \stdClass) {
            $members = [];
            foreach ($value as $key => $item) {
                $members[substr($key, 1)] = self::restoreKeys($item);
            }
            return new JsonObject($members);
        }
        return is_array($value) ? array_map([self::class, 'restoreKeys'], $value) : $value;
    }

    private static function cleanEvent($event): void
    {
        if (!$event instanceof JsonObject) {
            return;
        }

        $properties = $event->members['properties'] ?? null;
        if ($properties instanceof JsonObject) {
            foreach ($properties->members as $key => $value) {
                $name = $event->members['event'] ?? null;
                // Preserve only producer-backed typed metadata on its own event type.
                if (
                    ($name === '$exception' && $key === '$exception_list') ||
                    ($name === '$feature_flag_called' && $key === '$feature_flag_response')
                ) {
                    continue;
                }
                if ($value === null) {
                    unset($properties->members[$key]);
                } else {
                    $properties->members[$key] = self::cleanValue($value);
                }
            }
        } elseif (is_array($properties)) {
            $event->members['properties'] = self::cleanValue($properties);
        }

        // Identify also carries custom person properties outside the properties envelope.
        foreach (['$set', '$set_once', '$group_set'] as $key) {
            if (isset($event->members[$key])) {
                $event->members[$key] = self::cleanValue($event->members[$key]);
            }
        }
    }

    private static function cleanValue($value)
    {
        if ($value instanceof JsonObject) {
            foreach ($value->members as $key => $item) {
                if ($item === null) {
                    unset($value->members[$key]);
                } else {
                    $value->members[$key] = self::cleanValue($item);
                }
            }
        } elseif (is_array($value)) {
            return array_map([self::class, 'cleanValue'], $value);
        }

        return $value;
    }
}
