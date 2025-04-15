<?php

require_once __DIR__ . '/vendor/autoload.php';

use PostHog\PostHog;

const PROJECT_API_KEY = "phc_LBp6TCrkZahJAGVGovQBeNfDIap2hD1ymNjoOftoCow";
const PERSONAL_API_KEY = "phx_pWOpWbl64FJqmRinGtGpPvceve1wqKyqUxwYPdWho86";

PostHog::init(
    PROJECT_API_KEY,
    array('host' => 'https://app.posthog.com', 'debug' => true),
    null,
    PERSONAL_API_KEY
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
        // 'groups' => [
        //     'org' => 123
        // ],
        // 'sendFeatureFlags' => true
        // 'sendFeatureFlags' => true
        'send_feature_flags' => true
    ]
);

// PostHog::capture(
//     [
//         'distinctId' => 'distinct_id',
//         'event' => 'event2',
//         'properties' => [
//             'property1' => 'value',
//             'property2' => 'value',
//         ],
//         // 'groups' => [
//         //     'org' => 123
//         // ],
//         'sendFeatureFlags' => false
//     ]
// );

$enabled = PostHog::getFeatureFlag("first", "user_2311144");

echo $enabled;