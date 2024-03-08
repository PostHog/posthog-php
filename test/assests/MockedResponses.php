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
            'group-flag' => 'decide-fallback-value',
            'complex-flag' => 'decide-fallback-value',
            'beta-feature' => 'decide-fallback-value',
            'beta-feature2' => 'alakazam',
            'feature-1' => 'decide-fallback-value',
            'feature-2' => 'decide-fallback-value',
            'variant-1' => 'variant-1',
            'variant-3' => 'variant-3'
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
                "key" => "person-flag",
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

    public const LOCAL_EVALUATION_MULTIPLE_REQUEST = [
        'count' => 2,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "person-flag",
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
            ],
            [
                "id" => 2,
                "name" => "",
                "key" => "true-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
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

    public const LOCAL_EVALUATION_WITH_NO_ROLLOUT_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "enabled-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                            ],
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ],
        ],
    ];

    public const LOCAL_EVALUATION_WITH_INACTIVE_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "enabled-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                            ],
                            "rollout_percentage" => 100
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ],
            [
                "id" => 1,
                "name" => "",
                "key" => "disabled-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                            ],
                            "rollout_percentage" => 100
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => false,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ]
        ],
    ];

    public const LOCAL_EVALUATION_WITH_COHORTS_REQUEST = [
        'flags' => [
            [
                'id' => 2,
                'name' => 'Beta Feature',
                'key' => 'beta-feature',
                'is_simple_flag' => false,
                'active' => true,
                'filters' => [
                    'groups' => [
                        [
                            'properties' => [
                                [
                                    'key' => 'region',
                                    'operator' => 'exact',
                                    'value' => ['USA'],
                                    'type' => 'person',
                                ],
                                [
                                    'key' => 'id',
                                    'value' => 98,
                                    'operator' => null,
                                    'type' => 'cohort',
                                ],
                            ],
                            'rollout_percentage' => 100,
                        ],
                    ],
                ],
            ]
        ],
        'cohorts' => [
            '98' => [
                'type' => 'OR',
                'values' => [
                    ['key' => 'id', 'value' => 1, 'type' => 'cohort'],
                    ['key' => 'nation', 'operator' => 'exact', 'value' => ['UK'], 'type' => 'person'],
                ],
            ],
            '1' => [
                'type' => 'AND',
                'values' => [
                    ['key' => 'other', 'operator' => 'exact', 'value' => ['thing'], 'type' => 'person'],
                ],
            ],
        ]

    ];

    public const LOCAL_EVALUATION_FOR_NEGATED_COHORTS_REQUEST = [
        'flags' => [
            [
                'id' => 2,
                'name' => 'Beta Feature',
                'key' => 'beta-feature',
                'is_simple_flag' => false,
                'active' => true,
                'filters' => [
                    'groups' => [
                        [
                            'properties' => [
                                [
                                    'key' => 'region',
                                    'operator' => 'exact',
                                    'value' => ['USA'],
                                    'type' => 'person',
                                ],
                                [
                                    'key' => 'id',
                                    'value' => 98,
                                    'operator' => null,
                                    'type' => 'cohort',
                                ],
                            ],
                            'rollout_percentage' => 100,
                        ],
                    ],
                ],
            ]
        ],
        'cohorts' => [
            '98' => [
                'type' => 'OR',
                'values' => [
                    ['key' => 'id', 'value' => 1, 'type' => 'cohort'],
                    ['key' => 'nation', 'operator' => 'exact', 'value' => ['UK'], 'type' => 'person'],
                ],
            ],
            '1' => [
                'type' => 'AND',
                'values' => [
                    ['key' => 'other', 'operator' => 'exact', 'value' => ['thing'], 'type' => 'person', 'negation' => true],
                ],
            ],
        ],
    ];

    public const LOCAL_EVALUATION_GROUP_PROPERTIES_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "group flag",
                "key" => "group-flag",
                "filters" => [
                    "aggregation_group_type_index" => 0,
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "group_type_index" => 0,
                                    "key" => "name",
                                    "value" => ["Project Name 1"],
                                    "operator" => "exact",
                                    "type" => "group"
                                ]
                            ],
                            "rollout_percentage" => 35
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ]
        ],
        'group_type_mapping' => [
            "0" => "company",
            "1" => "project"
        ]
    ];

    public const LOCAL_EVALUATION_COMPLEX_FLAG_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "complex-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "region",
                                    "value" => ["USA"],
                                    "operator" => "exact",
                                    "type" => "person"
                                ],
                                [
                                    "key" => "name",
                                    "value" => ["Aloha"],
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100
                        ],
                        [
                            "properties" => [
                                [
                                    "key" => "email",
                                    "value" => ["a@b.com"],
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 35
                        ],
                        [
                            "properties" => [
                                [
                                    "key" => "doesnt_matter",
                                    "value" => ["1", "2"],
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 0
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => null
            ]
        ]
    ];

    public const LOCAL_EVALUATION_VARIANT_OVERRIDES_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "Beta feature",
                "key" => "beta-feature",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "email",
                                    "value" => "test@posthog.com",
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100,
                            "variant" => "second-variant"
                        ],
                        [
                            "rollout_percentage" => 50,
                            "variant" => "first-variant"
                        ]
                    ],
                    "multivariate" => [
                        "variants" => [
                            [
                                "key" => "first-variant",
                                "name" => "First Variant",
                                "rollout_percentage" => 50
                            ],
                            [
                                "key" => "second-variant",
                                "name" => "Second Variant",
                                "rollout_percentage" => 25
                            ],
                            [
                                "key" => "third-variant",
                                "name" => "Third Variant",
                                "rollout_percentage" => 25
                            ]
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null
            ]
        ],
    ];

    public const LOCAL_EVALUATION_CLASHING_VARIANT_OVERRIDES_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "Beta feature",
                "key" => "beta-feature",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "email",
                                    "value" => "test@posthog.com",
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100,
                            "variant" => "second-variant"
                        ],
                        [
                            "properties" => [
                                [
                                    "key" => "email",
                                    "value" => "test@posthog.com",
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100,
                            "variant" => "first-variant"
                        ],
                        [
                            "rollout_percentage" => 50,
                            "variant" => "first-variant"
                        ]
                    ],
                    "multivariate" => [
                        "variants" => [
                            [
                                "key" => "first-variant",
                                "name" => "First Variant",
                                "rollout_percentage" => 50
                            ],
                            [
                                "key" => "second-variant",
                                "name" => "Second Variant",
                                "rollout_percentage" => 25
                            ],
                            [
                                "key" => "third-variant",
                                "name" => "Third Variant",
                                "rollout_percentage" => 25
                            ]
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null
            ]
        ],
    ];

    public const LOCAL_EVALUATION_INVALID_VARIANT_OVERRIDES_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "Beta feature",
                "key" => "beta-feature",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "email",
                                    "value" => "test@posthog.com",
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100,
                            "variant" => "second???"
                        ],
                        [
                            "rollout_percentage" => 50,
                            "variant" => "first???"
                        ]
                    ],
                    "multivariate" => [
                        "variants" => [
                            [
                                "key" => "first-variant",
                                "name" => "First Variant",
                                "rollout_percentage" => 50
                            ],
                            [
                                "key" => "second-variant",
                                "name" => "Second Variant",
                                "rollout_percentage" => 25
                            ],
                            [
                                "key" => "third-variant",
                                "name" => "Third Variant",
                                "rollout_percentage" => 25
                            ]
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null
            ]
        ],
    ];

    public const LOCAL_EVALUATION_MULTIPLE_VARIANT_OVERRIDES_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "Beta feature",
                "key" => "beta-feature",
                "filters" => [
                    "groups" => [
                        [
                            "rollout_percentage" => 100,
                        ],
                        [
                            "properties" => [
                                [
                                    "key" => "email",
                                    "value" => "test@posthog.com",
                                    "operator" => "exact",
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100,
                            "variant" => "second-variant"
                        ],
                        [
                            "rollout_percentage" => 50,
                            "variant" => "third-variant"
                        ]
                    ],
                    "multivariate" => [
                        "variants" => [
                            [
                                "key" => "first-variant",
                                "name" => "First Variant",
                                "rollout_percentage" => 50
                            ],
                            [
                                "key" => "second-variant",
                                "name" => "Second Variant",
                                "rollout_percentage" => 25
                            ],
                            [
                                "key" => "third-variant",
                                "name" => "Third Variant",
                                "rollout_percentage" => 25
                            ]
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null
            ]
        ],
    ];


    public const EXPERIENCE_CONITNUITY_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "Beta Feature",
                "key" => "beta-feature",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => 100
                        ]
                    ]
                ],
                "ensure_experience_continuity" => true,
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => 100
            ]
        ],
    ];

    public const FALLBACK_TO_DECIDE_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "feature 1",
                "key" => "feature-1",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "id",
                                    "value" => 98,
                                    "operator" => null,
                                    "type" => "cohort"
                                ]
                            ],
                            "rollout_percentage" => 100
                        ],
                    ],
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null

            ],
            [
                "id" => 2,
                "name" => "feature 2",
                "key" => "feature-2",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "region",
                                    "value" => ["USA"],
                                    "operator" => null,
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 100
                        ],
                    ],
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => null

            ]
        ]
    ];

    public const LOCAL_EVALUATION_SIMPLE_EMPTY_REQUEST = [
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
                            "properties" => [],
                            "rollout_percentage" => 0
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
            ]
        ],
    ];

    public const LOCAL_EVALUATION_SIMPLE_REQUEST = [
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
                            "properties" => [],
                            "rollout_percentage" => 100
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => 100
            ]
        ],
    ];

    public const SIMPLE_PARTIAL_REQUEST = [
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
                            "properties" => [],
                            "rollout_percentage" => 45
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => 45
            ]
        ],
    ];

    public const MULTIVARIATE_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "multivariate-flag",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => 55,
                        ],
                    ],
                    "multivariate" => [
                        "variants" => [
                            [
                                "key" => "first-variant",
                                "name" => "First Variant",
                                "rollout_percentage" => 50
                            ],
                            [
                                "key" => "second-variant",
                                "name" => "Second Variant",
                                "rollout_percentage" => 20
                            ],
                            [
                                "key" => "third-variant",
                                "name" => "Third Variant",
                                "rollout_percentage" => 20
                            ],
                            [
                                "key" => "fourth-variant",
                                "name" => "Fourth Variant",
                                "rollout_percentage" => 5
                            ],
                            [
                                "key" => "fifth-variant",
                                "name" => "Fifth Variant",
                                "rollout_percentage" => 5
                            ]
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => true,
                "rollout_percentage" => 45
            ]
        ],
    ];

    public const MULTIPLE_FLAGS_LOCAL_EVALUATE_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "variant-1",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => 100
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => 100
            ],
            [
                "id" => 2,
                "name" => "",
                "key" => "variant-2",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => 0
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => 100
            ]
        ],
    ];

    public const MULTIPLE_FLAGS_REQUEST = [
        'count' => 1,
        'next' => null,
        'previous' => null,
        'flags' => [
            [
                "id" => 1,
                "name" => "",
                "key" => "variant-1",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => 100
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => 100
            ],
            [
                "id" => 2,
                "name" => "",
                "key" => "variant-2",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [],
                            "rollout_percentage" => 0
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => 100
            ],
            [
                "id" => 3,
                "name" => "",
                "key" => "variant-3",
                "filters" => [
                    "groups" => [
                        [
                            "properties" => [
                                [
                                    "key" => "country",
                                    "value" => ["USA"],
                                    "operator" => null,
                                    "type" => "person"
                                ]
                            ],
                            "rollout_percentage" => 0
                        ]
                    ]
                ],
                "deleted" => false,
                "active" => true,
                "is_simple_flag" => false,
                "rollout_percentage" => 100
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
