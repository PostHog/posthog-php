<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog;

function function_exists(string $function): bool
{
    return $function !== 'curl_init' && \function_exists($function);
}
