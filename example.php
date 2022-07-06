<?php

require_once __DIR__ . '/vendor/autoload.php';

use PostHog\PostHog;

const PROJECT_API_KEY = "phc_X8B6bhR1QgQKP1WdpFLN82LxLxgZ7WPXDgJyRyvIpib";

echo "Hello";

PostHog::init(
    PROJECT_API_KEY,
    array('host' => 'https://app.posthog.com'),
);

# Capture an event
PostHog::capture(
    [
        'distinctId' => 'distinct_id',
        'event' => 'event',
        'properties' => [
            'property1' => 'value',
            'property2' => 'value',
        ],
        'sendFeatureFlags' => true
    ]
);

PostHog::capture(
    [
        'distinctId' => 'distinct_id',
        'event' => 'event',
        'properties' => [
            'property1' => 'value',
            'property2' => 'value',
        ],
        'sendFeatureFlags' => false
    ]
);

PostHog::isFeatureEnabled("multivariate-test", "distinct-id", false);
PostHog::isFeatureEnabled("multivariate-test", "distinct-id", false, ['company' => ['id' => 5]]);

PostHog::getFeatureFlag("simple-test", "distinct-id", false);
PostHog::getFeatureFlag("simple-test", "distinct-id", false, ['company' => ['id' => 5]]);

PostHog::getFeatureFlag("multivariate-test", "distinct-id", false);
PostHog::getFeatureFlag("multivariate-test", "distinct-id", false, ['company' => ['id' => 5]]);

PostHog::getFeatureFlag("multivariate-simple-test", "distinct-id", false);
PostHog::getFeatureFlag("multivariate-simple-test", "distinct-id", false, ['company' => ['id' => 5]]);
?>
