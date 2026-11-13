<?php

namespace App\Support;

final class RedactDemoProperties
{
    public function __invoke(array $message): ?array
    {
        // Defense in depth, not a complete PII scrubber. Prefer never collecting sensitive data.
        unset($message['properties']['email'], $message['properties']['password']);
        return $message;
    }
}
