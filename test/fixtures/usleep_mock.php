<?php

namespace PostHog;

function usleep(int $microseconds): void
{
    global $httpClientSleepDurations;

    if (is_array($httpClientSleepDurations)) {
        $httpClientSleepDurations[] = $microseconds;
        return;
    }

    \usleep($microseconds);
}
