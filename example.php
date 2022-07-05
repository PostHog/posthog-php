<?php

namespace PostHog\Example;

use PostHog\Client;
use PostHog\PostHog;

const PROJECT_API_KEY = "phc_X8B6bhR1QgQKP1WdpFLN82LxLxgZ7WPXDgJyRyvIpib";
const PERSONAL_API_KEY = "";

echo "Hello";

PostHog::init(PROJECT_API_KEY,
    array('host' => 'https://app.posthog.com'),
);

# Capture an event


?>