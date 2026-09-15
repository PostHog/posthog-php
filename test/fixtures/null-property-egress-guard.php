<?php

// Subprocess-only transport guard: never loaded into the general PHPUnit suite.
namespace PostHog;

function curl_init($url = null)
{
    if ($url !== null) {
        throw new \RuntimeException('Unexpected initial URL');
    }
    $handle = \curl_init();
    \curl_setopt($handle, CURLOPT_PROXY, '');
    return $handle;
}

function curl_setopt($handle, $option, $value)
{
    if ($option === CURLOPT_URL && $value !== getenv('POSTHOG_TEST_HOST') . '/batch/') {
        throw new \RuntimeException('Non-loopback or unexpected SDK request denied: ' . $value);
    }
    return \curl_setopt($handle, $option, $value);
}

namespace PostHog\Consumer;

function pfsockopen($host, $port, &$errno, &$errstr, $timeout)
{
    $expected = str_replace('http://', 'tcp://', getenv('POSTHOG_TEST_HOST'));
    if ($host !== $expected) {
        throw new \RuntimeException('Unexpected socket host denied');
    }
    return \pfsockopen($host, $port, $errno, $errstr, $timeout);
}

function exec($command, &$output, &$exit)
{
    $url = escapeshellarg(getenv('POSTHOG_TEST_HOST') . '/batch/');
    if (!str_starts_with($command, 'curl -X POST ') || !str_contains($command, ' ' . $url . ' ')) {
        throw new \RuntimeException('Unexpected subprocess denied');
    }
    // No proxies, user curl config, retries, or background process may escape the test lifetime.
    $command = 'curl -q --noproxy "*" --connect-timeout 2 --max-time 5 ' . substr($command, 5);
    return \exec($command, $output, $exit);
}
