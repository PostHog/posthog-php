<?php

namespace PostHog;

/**
 * Internal decoded JSON object, including names PHP cannot store on stdClass.
 *
 * @internal
 */
final class JsonObject implements \JsonSerializable
{
    public function __construct(public array $members)
    {
    }

    public function jsonSerialize(): mixed
    {
        foreach ($this->members as $key => $_) {
            // A leading-NUL string key guarantees this array is not a list. Casting it to
            // stdClass would make json_encode silently skip the member as non-public.
            if (is_string($key) && str_starts_with($key, "\0")) {
                return $this->members;
            }
        }

        // Retain object identity even after cleanup leaves no keys or only consecutive integers.
        return (object) $this->members;
    }
}
