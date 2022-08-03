<?php

namespace PostHog\Test\Assets;

class MockedResponses
{
    public const DECIDE_REQUEST = [
        'config' => [
            'enable_collect_everything' => true,
        ],
        'editorParams' => [
        ],
        'isAuthenticated' => false,
        'supportedCompression' => [
            0 => 'gzip',
            1 => 'gzip-js',
            2 => 'lz64',
        ],
        'featureFlags' => [
            'simpleFlag' => true,
            'having_fun' => false,
            'enabled-flag' => true,
            'disabled-flag' => false,
            'multivariate-simple-test' => 'variant-simple-value',
            'simple-test' => true,
            'multivariate-test' => 'variant-value',
        ],
        'sessionRecording' => false,
    ];

    public const LOCAL_EVALUATION_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "simple-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "region",
                                    "value" => ["USA"],
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                                ],
                            "rollout_percentage" => 100
                        ]
                    ]
                                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ]
        ],
    ];

    public const SIMPLE_FLAG_EXAMPLE_REQUEST = [
        "count" => 1,
        "next" => null,
        "previous" => null,
        "results" => [
            [
                "id" => 719,
                "name" => "",
                "key" => "simpleFlag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => null
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ],
            [
                "id" => 720,
                "name" => "",
                "key" => "enabled-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => null
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null
            ],
            [
                "id" => 721,
                "name" => "",
                "key" => "disabled-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => null
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null
            ],
        ]
    ];
}
