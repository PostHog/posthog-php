<?php

// phpcs:disable PSR1.Files.SideEffects
// Makes the PostHog namespace see no curl extension. Load it only in a separate process, before any PostHog code calls
// function_exists().
namespace PostHog;

function function_exists(string $function): bool
{
    return $function !== 'curl_init' && \function_exists($function);
}
