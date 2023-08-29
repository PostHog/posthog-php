<?php
// phpcs:ignoreFile
namespace PostHog\Test;

use Exception;
use PHPUnit\Framework\TestCase;
use PostHog\FeatureFlag;
use PostHog\Client;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;
use PostHog\InconclusiveMatchException;
use PostHog\SizeLimitedHash;

class FeatureFlagMatch extends TestCase
{
    const FAKE_API_KEY = "random_key";

    protected $http_client;
    protected $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
    }

    public function testMatchPropertyEquals(): void
    {
        $prop = [
            "key" => "key",
            "value" => "value",
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => null,
        ]));

        self::expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty($prop, [
            "key2" => "value2",
        ]);

        $prop = [
            "key" => "key",
            "value" => "value",
            "operator" => "exact"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        $prop = [
            "key" => "key",
            "value" => ["value1", "value2", "value3"],
            "operator" => "exact"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value1",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value3",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value4",
        ]));

        self::expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty($prop, [
            "key2" => "value",
        ]);
    }

    public function testMatchPropertyNotIn(): void
    {
        $prop = [
            "key" => "key",
            "value" => "value",
            "operator" => "is_not"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => null,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "",
        ]));

        $prop = [
            "key" => "key",
            "value" => ["value1", "value2", "value3"],
            "operator" => "is_not"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value4",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value5",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value6",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => null,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value3",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value1",
        ]));

        self::expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty($prop, [
            "key2" => "value",
        ]);
    }

    public function testMatchPropertyIsSet(): void
    {
        $prop = [
            "key" => "key",
            "value" => "is_set",
            "operator" => "is_set"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => null,
        ]));

        self::expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty($prop, [
            "key2" => "value",
        ]);
    }

    public function testMatchPropertyContains(): void
    {
        $prop = [
            "key" => "key",
            "value" => "valUe",
            "operator" => "icontains"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value3",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value4",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "343tfvalue5",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "Alakazam",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => 123,
        ]));

        $prop = [
            "key" => "key",
            "value" => 3,
            "operator" => "icontains"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "3",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 323,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "val3",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "three",
        ]));
    }

    public function testMatchPropertyRegex(): void
    {
        $prop = [
            "key" => "key",
            "value" => "/.com/",
            "operator" => "regex"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value.com",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "value2.com",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => ".com343tfvalue5",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "Alakazam",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => 123,
        ]));


        $prop = [
            "key" => "key",
            "value" => "/3/",
            "operator" => "regex"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "3",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 323,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "val3",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "three",
        ]));

        $prop = [
            "key" => "key",
            "value" => "/?*/",
            "operator" => "regex"
        ];

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value2",
        ]));

        $prop = [
            "key" => "key",
            "value" => "/4/",
            "operator" => "regex"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => "4",
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 4,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "value",
        ]));
    }

    public function testMatchPropertyMathOperators(): void
    {
        $prop = [
            "key" => "key",
            "value" => 1,
            "operator" => "gt"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 2,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 3,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => 0,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => -1,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "23",
        ]));

        $prop = [
            "key" => "key",
            "value" => 1,
            "operator" => "lt"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 0,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => -1,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => -3,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => 1,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "1",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "3",
        ]));

        $prop = [
            "key" => "key",
            "value" => 1,
            "operator" => "gte"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 1,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 2,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => 0,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => -1,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "3",
        ]));

        $prop = [
            "key" => "key",
            "value" => 43,
            "operator" => "lte"
        ];

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 0,
        ]));

        self::assertTrue(FeatureFlag::matchProperty($prop, [
            "key" => 43,
        ]));


        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => 44,
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "1",
        ]));

        self::assertFalse(FeatureFlag::matchProperty($prop, [
            "key" => "3",
        ]));
    }

    public function testFlagPersonProperties()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_REQUEST);

        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );

        PostHog::init(null, null, $this->client);

        $this->assertTrue(PostHog::getFeatureFlag('person-flag', 'some-distinct-id', [], ["region" => "USA"]));
        $this->assertFalse(PostHog::getFeatureFlag('person-flag', 'some-distinct-id-2', [], ["region" => "Canada"]));
    }

    public function testFlagGroupProperties()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_GROUP_PROPERTIES_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertFalse(PostHog::getFeatureFlag('group-flag', 'some-distinct-1', [], [], ["company" => ["name" => "Project Name 1"]]));
        $this->assertFalse(PostHog::getFeatureFlag('group-flag', 'some-distinct-2', [], [], ["company" => ["name" => "Project Name 2"]]));
        $this->assertTrue(PostHog::getFeatureFlag('group-flag', 'some-distinct-id', ["company" => "amazon_without_rollout"], [], ["company" => ["name" => "Project Name 1"]]));
        $this->assertFalse(PostHog::getFeatureFlag('group-flag', 'some-distinct-id', ["company" => "amazon"], [], ["company" => ["name" => "Project Name 1"]]));
        $this->assertFalse(PostHog::getFeatureFlag('group-flag', 'some-distinct-id', ["company" => "amazon_without_rollout"], [], ["company" => ["name" => "Project Name 2"]]));
        $this->assertEquals(PostHog::getFeatureFlag('group-flag', 'some-distinct-id', ["company" => "amazon"], [], ["company" => []]), 'decide-fallback-value');
    }

    public function testFlagComplexDefinition()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_COMPLEX_FLAG_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertTrue(PostHog::getFeatureFlag('complex-flag', 'some-distinct-id', [], ["region" => "USA", "name" => "Aloha"], []));
        $this->assertTrue(PostHog::getFeatureFlag('complex-flag', 'some-distinct-within-roll', [], ["region" => "USA", "email" => "a@b.com"], []));
        $this->assertEquals(PostHog::getFeatureFlag('complex-flag', 'some-distinct-within-rollout', [], ["region" => "USA", "email" => "a@b.com"], []), 'decide-fallback-value');
        $this->assertEquals(PostHog::getFeatureFlag('complex-flag', 'some-distinct-within-rollout', [], ["doesnt_matter" => "1"], []), 'decide-fallback-value');
        $this->assertEquals(PostHog::getFeatureFlag('complex-flag', 'some-distinct-id', [], ["region" => "USA"], []), 'decide-fallback-value');
        $this->assertFalse(PostHog::getFeatureFlag('complex-flag', 'some-distinct-within-rollout', [], ["region" => "USA", "email" => "a@b.com", "name" => "X", "doesnt_matter" => "1"], []));
    }

    public function testFlagFallbackToDecide()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::FALLBACK_TO_DECIDE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(PostHog::getFeatureFlag('feature-1', 'some-distinct'), 'decide-fallback-value');
        $this->assertEquals(PostHog::getFeatureFlag('feature-2', 'some-distinct'), 'decide-fallback-value');
    }

    public function testFeatureFlagDefaultsComeIntoPlayOnlyWhenDecideErrorsOut()
    {
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            null,
            null
        );
        PostHog::init(null, null, $this->client);
        $this->assertEquals(PostHog::getFeatureFlag('simple-flag', 'distinct-id'), null);
    }


    public function testFlagExperienceContinuityNotEvaluatedLocally()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::EXPERIENCE_CONITNUITY_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'distinct-id', [], [], []), 'decide-fallback-value');
    }

    public function testGetAllFlagsWithFallback()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::MULTIPLE_FLAGS_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $flags = PostHog::getAllFlags('distinct-id');

        $this->assertEquals($flags["variant-1"], "variant-1");
        $this->assertEquals($flags["variant-2"], false);
        $this->assertEquals($flags["variant-3"], "variant-3");
    }

    public function testGetAllFlagsWithFallbackEmptyLocalFlags()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse:[]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $flags = PostHog::getAllFlags('distinct-id');

        $this->assertEquals($flags["variant-1"], "variant-1");
        $this->assertEquals($flags["variant-3"], "variant-3");
    }

    public function testGetAllFlagsWithNoFallback()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse:MockedResponses::MULTIPLE_FLAGS_LOCAL_EVALUATE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $flags = PostHog::getAllFlags('distinct-id');

        $this->assertEquals($flags["variant-1"], true);
        $this->assertEquals($flags["variant-2"], false);
    }

    public function testLoadFeatureFlags()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_GROUP_PROPERTIES_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(count($this->client->featureFlags), 1);
        $this->assertEquals($this->client->featureFlags[0]["key"], "group-flag");

        $this->assertEquals($this->client->groupTypeMapping, [
            "0" => "company",
            "1" => "project"
        ]);
    }

    public function testLoadFeatureFlagsWrongKey()
    {
        self::expectException(Exception::class);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            null,
            self::FAKE_API_KEY
        );
        PostHog::init(null, null, $this->client);
    }

    public function testSimpleFlag()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_SIMPLE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertTrue(PostHog::getFeatureFlag('simple-flag', 'some-distinct-id'));
    }

    public function testFeatureFlagsDontFallbackToDecideWhenOnlyLocalEvaluationIsTrue()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::FALLBACK_TO_DECIDE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        # beta-feature should fallback to decide because property type is unknown,
        # but doesn't because only_evaluate_locally is true
        $this->assertEquals(PostHog::getFeatureFlag(
            'beta-feature',
            'some-distinct-id',
            array(),
            array(),
            array(),
            true,
            false
        ), null);

        $this->assertEquals(PostHog::isFeatureEnabled(
            'beta-feature',
            'some-distinct-id',
            array(),
            array(),
            array(),
            true,
            false
        ), null);

        # beta-feature2 should fallback to decide because region property not given with call
        # but doesn't because only_evaluate_locally is true
        $this->assertEquals(PostHog::getFeatureFlag(
            'beta-feature2',
            'some-distinct-id',
            array(),
            array(),
            array(),
            true,
            false
        ), null);

        $this->assertEquals(PostHog::isFeatureEnabled(
            'beta-feature2',
            'some-distinct-id',
            array(),
            array(),
            array(),
            true,
            false
        ), false);
    }

    public function testComputingInactiveFlagLocally()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_WITH_INACTIVE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $flags = PostHog::getAllFlags('distinct-id');

        $this->assertEquals($flags, [
            "enabled-flag" => true,
            "disabled-flag" => false
        ]);
    }

    public function testComputingFlagWithoutRolloutLocally()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_WITH_NO_ROLLOUT_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $flags = PostHog::getAllFlags('distinct-id');

        $this->assertEquals($flags, [
            "enabled-flag" => true,
        ]);
    }

    public function testFlagWithVariantOverrides()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_VARIANT_OVERRIDES_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'test_id', [], ["email" => "test@posthog.com"]), "second-variant");
        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'example_id'), "first-variant");
    }

    public function testFlagWithClashingVariantOverrides()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_CLASHING_VARIANT_OVERRIDES_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'test_id', [], ["email" => "test@posthog.com"]), "second-variant");
        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'example_id', [], ["email" => "test@posthog.com"]), "second-variant");
        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'example_id'), "first-variant");
    }

    public function testFlagWithInvalidVariantOverrides()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_INVALID_VARIANT_OVERRIDES_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'test_id', [], ["email" => "test@posthog.com"]), "third-variant");
        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'example_id'), "second-variant");
    }

    public function testFlagWithMultipleVariantOverrides()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_MULTIPLE_VARIANT_OVERRIDES_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'test_id', [], ["email" => "test@posthog.com"]), "second-variant");
        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'example_id'), "third-variant");
        $this->assertEquals(PostHog::getFeatureFlag('beta-feature', 'another_id'), "second-variant");
    }

    public function testEventCalled()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_SIMPLE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );

        $this->client->distinctIdsFeatureFlagsReported = new SizeLimitedHash(1);
        PostHog::init(null, null, $this->client);

        PostHog::getFeatureFlag('simple-flag', 'some-distinct-id');
        $this->assertEquals($this->client->distinctIdsFeatureFlagsReported->count(), 1);

        PostHog::getFeatureFlag('simple-flag', 'some-distinct-id2');
        $this->assertEquals($this->client->distinctIdsFeatureFlagsReported->count(), 1);
    }

    public function testFlagConsistency()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::SIMPLE_PARTIAL_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $result = [
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            true,
            true,
            false,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
            false,
            false,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            true,
            false,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            false,
            true,
            true,
            true,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            true,
            false,
            true,
            true,
        ];
        foreach (range(0, 999) as $number) {
            $testResult = PostHog::getFeatureFlag('simple-flag', sprintf('distinct_id_%s', $number));
            $this->assertEquals($testResult, $result[$number]);
        }
    }

    public function testMultivariateFlagConsistency()
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::MULTIVARIATE_REQUEST);

        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $result = [
            "second-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            "second-variant",
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "third-variant",
            false,
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            "fourth-variant",
            "first-variant",
            false,
            "third-variant",
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            false,
            "third-variant",
            "second-variant",
            "first-variant",
            false,
            "third-variant",
            false,
            false,
            "first-variant",
            "second-variant",
            false,
            "first-variant",
            "first-variant",
            "second-variant",
            false,
            "first-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            "second-variant",
            "second-variant",
            "third-variant",
            "second-variant",
            "first-variant",
            false,
            "first-variant",
            "second-variant",
            "fourth-variant",
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "second-variant",
            false,
            "third-variant",
            false,
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "fifth-variant",
            false,
            "second-variant",
            "first-variant",
            "second-variant",
            false,
            "third-variant",
            "third-variant",
            false,
            false,
            false,
            false,
            "third-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            "third-variant",
            "third-variant",
            false,
            "third-variant",
            "second-variant",
            "third-variant",
            false,
            false,
            "second-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            false,
            "second-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            "second-variant",
            "second-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            "third-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            "fifth-variant",
            "second-variant",
            false,
            "second-variant",
            false,
            "first-variant",
            "third-variant",
            "first-variant",
            "fifth-variant",
            "third-variant",
            false,
            false,
            "fourth-variant",
            false,
            false,
            false,
            false,
            "third-variant",
            false,
            false,
            "third-variant",
            false,
            "first-variant",
            "second-variant",
            "second-variant",
            "second-variant",
            false,
            "first-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            false,
            false,
            false,
            "second-variant",
            false,
            false,
            "first-variant",
            false,
            "first-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            "first-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            "third-variant",
            "third-variant",
            false,
            "second-variant",
            "first-variant",
            false,
            "second-variant",
            "first-variant",
            false,
            "first-variant",
            false,
            false,
            "first-variant",
            "fifth-variant",
            "first-variant",
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "second-variant",
            false,
            "second-variant",
            "third-variant",
            "third-variant",
            false,
            "first-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            "third-variant",
            "first-variant",
            false,
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "second-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            false,
            "second-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            "third-variant",
            false,
            "first-variant",
            false,
            "third-variant",
            false,
            "third-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            "third-variant",
            "first-variant",
            "second-variant",
            "fifth-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            "third-variant",
            false,
            "second-variant",
            "first-variant",
            false,
            false,
            false,
            false,
            "third-variant",
            false,
            false,
            "third-variant",
            false,
            false,
            "first-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            "fourth-variant",
            "fourth-variant",
            "third-variant",
            "second-variant",
            "first-variant",
            "third-variant",
            "fifth-variant",
            false,
            "first-variant",
            "fifth-variant",
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "second-variant",
            "fifth-variant",
            "second-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            false,
            false,
            "third-variant",
            false,
            "second-variant",
            "fifth-variant",
            false,
            "third-variant",
            "first-variant",
            false,
            false,
            "fourth-variant",
            false,
            false,
            "second-variant",
            false,
            false,
            "first-variant",
            "fourth-variant",
            "first-variant",
            "second-variant",
            false,
            false,
            false,
            "first-variant",
            "third-variant",
            "third-variant",
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            false,
            "first-variant",
            "third-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            "second-variant",
            "second-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "fifth-variant",
            "first-variant",
            false,
            false,
            false,
            "second-variant",
            "third-variant",
            "first-variant",
            "fourth-variant",
            "first-variant",
            "third-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            "third-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            false,
            "fourth-variant",
            "fifth-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            "first-variant",
            "second-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            false,
            "first-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            "third-variant",
            "third-variant",
            "first-variant",
            false,
            false,
            "second-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "second-variant",
            "first-variant",
            false,
            "first-variant",
            "third-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "third-variant",
            "third-variant",
            false,
            false,
            false,
            false,
            "third-variant",
            "fourth-variant",
            "fourth-variant",
            "first-variant",
            "second-variant",
            false,
            "first-variant",
            false,
            "second-variant",
            "first-variant",
            "third-variant",
            false,
            "third-variant",
            false,
            "first-variant",
            "first-variant",
            "third-variant",
            false,
            false,
            false,
            "fourth-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            "fourth-variant",
            false,
            "first-variant",
            "third-variant",
            "first-variant",
            false,
            false,
            "third-variant",
            false,
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            "third-variant",
            "second-variant",
            "fourth-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            "second-variant",
            "first-variant",
            "second-variant",
            false,
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            "second-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "third-variant",
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            "fifth-variant",
            "fourth-variant",
            "first-variant",
            "second-variant",
            false,
            "fourth-variant",
            false,
            false,
            false,
            "fourth-variant",
            false,
            false,
            "third-variant",
            false,
            false,
            false,
            "first-variant",
            "third-variant",
            "third-variant",
            "second-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            "second-variant",
            false,
            false,
            "first-variant",
            false,
            "second-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            "second-variant",
            false,
            false,
            "fifth-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "second-variant",
            "third-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            "third-variant",
            "first-variant",
            false,
            false,
            false,
            false,
            "fourth-variant",
            "first-variant",
            false,
            false,
            false,
            "third-variant",
            false,
            false,
            "second-variant",
            "first-variant",
            false,
            false,
            "second-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            false,
            "second-variant",
            "third-variant",
            "second-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            "first-variant",
            false,
            "second-variant",
            false,
            false,
            false,
            false,
            "first-variant",
            false,
            "third-variant",
            false,
            "first-variant",
            false,
            false,
            "second-variant",
            "third-variant",
            "second-variant",
            "fourth-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            false,
            "second-variant",
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            false,
            "second-variant",
            false,
            false,
            false,
            false,
            "second-variant",
            false,
            "first-variant",
            false,
            "third-variant",
            false,
            false,
            "first-variant",
            "third-variant",
            false,
            "third-variant",
            false,
            false,
            "second-variant",
            false,
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            "second-variant",
            false,
            false,
            "first-variant",
            "third-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "second-variant",
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "fifth-variant",
            false,
            false,
            false,
            "first-variant",
            false,
            "third-variant",
            false,
            false,
            "second-variant",
            false,
            false,
            false,
            false,
            false,
            "fourth-variant",
            "second-variant",
            "first-variant",
            "second-variant",
            false,
            "second-variant",
            false,
            "second-variant",
            false,
            "first-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            "second-variant",
            false,
            "first-variant",
            false,
            "fifth-variant",
            false,
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            false,
            "first-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            false,
            "fifth-variant",
            false,
            false,
            "third-variant",
            false,
            "third-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            "third-variant",
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            "second-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            "fifth-variant",
            "first-variant",
            false,
            false,
            "fourth-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            "fourth-variant",
            "first-variant",
            false,
            "second-variant",
            "third-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "third-variant",
            "third-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            "second-variant",
            false,
            false,
            "second-variant",
            false,
            "third-variant",
            "first-variant",
            "second-variant",
            "fifth-variant",
            "first-variant",
            "first-variant",
            false,
            "first-variant",
            "fifth-variant",
            false,
            false,
            false,
            "third-variant",
            "first-variant",
            "first-variant",
            "second-variant",
            "fourth-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            false,
            false,
            false,
            "second-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            "third-variant",
            false,
            "first-variant",
            false,
            "third-variant",
            "third-variant",
            "first-variant",
            "first-variant",
            false,
            "second-variant",
            false,
            "second-variant",
            "first-variant",
            false,
            false,
            false,
            "second-variant",
            false,
            "third-variant",
            false,
            "first-variant",
            "fifth-variant",
            "first-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "fourth-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "fifth-variant",
            false,
            false,
            false,
            "second-variant",
            false,
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            "second-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            "third-variant",
            "first-variant",
            false,
            "second-variant",
            false,
            false,
            "third-variant",
            "second-variant",
            "third-variant",
            false,
            "first-variant",
            "third-variant",
            "second-variant",
            "first-variant",
            "third-variant",
            false,
            false,
            "first-variant",
            "first-variant",
            false,
            false,
            false,
            "first-variant",
            "third-variant",
            "second-variant",
            "first-variant",
            "first-variant",
            "first-variant",
            false,
            "third-variant",
            "second-variant",
            "third-variant",
            false,
            false,
            "third-variant",
            "first-variant",
            false,
            "first-variant",
        ];
        foreach (range(0, 999) as $number) {
            $testResult = PostHog::getFeatureFlag('multivariate-flag', sprintf('distinct_id_%s', $number));
            $this->assertEquals($testResult, $result[$number]);
        }
    }
}
