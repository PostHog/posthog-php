<?php

// phpcs:disable PSR1.Files.SideEffects

// PostHog PHP library example
//
// This script demonstrates various PostHog PHP SDK capabilities including:
// - Basic event capture and user identification
// - Feature flag local evaluation
// - Feature flag dependencies
// - Context management and tagging
//
// Setup:
// 1. Copy .env.example to .env and fill in your PostHog credentials
// 2. Run this script and choose from the interactive menu

require_once __DIR__ . '/vendor/autoload.php';

use PostHog\PostHog;

function loadEnvFile()
{
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && ($line[0] !== '#') && strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
                putenv(trim($key) . '=' . trim($value));
            }
        }
    }
}

// Load .env file if it exists
loadEnvFile();

// Get configuration
$projectKey = $_ENV['POSTHOG_PROJECT_API_KEY'] ?? getenv('POSTHOG_PROJECT_API_KEY') ?: '';
$personalApiKey = $_ENV['POSTHOG_PERSONAL_API_KEY'] ?? getenv('POSTHOG_PERSONAL_API_KEY') ?: '';
$host = $_ENV['POSTHOG_HOST'] ?? getenv('POSTHOG_HOST') ?: 'https://app.posthog.com';

// Check if credentials are provided
if (!$projectKey || !$personalApiKey) {
    echo "❌ Missing PostHog credentials!\n";
    echo "   Please set POSTHOG_PROJECT_API_KEY and POSTHOG_PERSONAL_API_KEY environment variables\n";
    echo "   or copy .env.example to .env and fill in your values\n";
    exit(1);
}

// Test authentication before proceeding
echo "🔑 Testing PostHog authentication...\n";

try {
    // Configure PostHog with credentials
    PostHog::init(
        $projectKey,
        [
            'host' => $host,
            'debug' => false,
            'ssl' => !(substr($host, 0, 7) === 'http://') // Use SSL unless explicitly http://
        ],
        null,
        $personalApiKey
    );

    // Test by attempting to get feature flags (this validates both keys)
    $testFlags = PostHog::getAllFlags("test_user", [], [], [], true);

    // If we get here without exception, credentials work
    echo "✅ Authentication successful!\n";
    echo "   Project API Key: " . substr($projectKey, 0, 9) . "...\n";
    echo "   Personal API Key: [REDACTED]\n";
    echo "   Host: $host\n\n\n";
} catch (Exception $e) {
    echo "❌ Authentication failed!\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "\n   Please check your credentials:\n";
    echo "   - POSTHOG_PROJECT_API_KEY: Project API key from PostHog settings\n";
    echo "   - POSTHOG_PERSONAL_API_KEY: Personal API key (required for local evaluation)\n";
    echo "   - POSTHOG_HOST: Your PostHog instance URL\n";
    exit(1);
}

// Display menu and get user choice
echo "🚀 PostHog PHP SDK Demo - Choose an example to run:\n\n";
echo "1. Identify and capture examples\n";
echo "2. Feature flag local evaluation examples\n";
echo "3. Feature flag dependencies examples\n";
echo "4. Context management and tagging examples\n";
echo "5. ETag polling examples (for local evaluation)\n";
echo "6. Error tracking examples\n";
echo "7. Run all examples\n";
echo "8. Exit\n";
$choice = trim(readline("\nEnter your choice (1-8): "));

function identifyAndCaptureExamples()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "IDENTIFY AND CAPTURE EXAMPLES\n";
    echo str_repeat("=", 60) . "\n";

    // Enable debug for this section
    PostHog::init(
        $_ENV['POSTHOG_PROJECT_API_KEY'],
        [
            'host' => $_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com',
            'debug' => true,
            'ssl' => !str_starts_with($_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com', 'http://')
        ],
        null,
        $_ENV['POSTHOG_PERSONAL_API_KEY']
    );

    // Capture an event
    echo "📊 Capturing events...\n";
    PostHog::capture([
        'distinctId' => 'distinct_id',
        'event' => 'event',
        'properties' => [
            'property1' => 'value',
            'property2' => 'value',
        ],
        'send_feature_flags' => true
    ]);

    // Alias a previous distinct id with a new one
    echo "🔗 Creating alias...\n";
    PostHog::alias([
        'distinctId' => 'distinct_id',
        'alias' => 'new_distinct_id'
    ]);

    PostHog::capture([
        'distinctId' => 'new_distinct_id',
        'event' => 'event2',
        'properties' => [
            'property1' => 'value',
            'property2' => 'value',
        ]
    ]);

    PostHog::capture([
        'distinctId' => 'new_distinct_id',
        'event' => 'event-with-groups',
        'properties' => [
            'property1' => 'value',
            'property2' => 'value',
        ],
        'groups' => ['company' => 'id:5']
    ]);

    // Add properties to the person
    echo "👤 Identifying user...\n";
    PostHog::identify([
        'distinctId' => 'new_distinct_id',
        'properties' => ['email' => 'something@something.com']
    ]);

    echo "✅ Identify and capture examples completed!\n";
}

function featureFlagExamples()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "FEATURE FLAG LOCAL EVALUATION EXAMPLES\n";
    echo str_repeat("=", 60) . "\n";

    // Disable debug for cleaner output
    PostHog::init(
        $_ENV['POSTHOG_PROJECT_API_KEY'],
        [
            'host' => $_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com',
            'debug' => false,
            'ssl' => !str_starts_with($_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com', 'http://')
        ],
        null,
        $_ENV['POSTHOG_PERSONAL_API_KEY']
    );

    echo "🚩 Getting individual feature flags...\n";

    // Test different users to see different results
    $users = ['user_1', 'user_2', 'user_3'];

    foreach ($users as $user) {
        $flags = PostHog::getAllFlags($user, [], [], [], true);
        echo "User $user flags: " . json_encode($flags, JSON_PRETTY_PRINT) . "\n";

        // Get a specific flag
        if (!empty($flags)) {
            $firstFlag = array_key_first($flags);
            $flagValue = PostHog::getFeatureFlag($firstFlag, $user, [], [], [], true);
            echo "Flag '$firstFlag' for $user: " . ($flagValue ? json_encode($flagValue) : 'false') . "\n";
        }
        echo "\n";
    }

    echo "✅ Feature flag examples completed!\n";
}

function flagDependencyExamples()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "FLAG DEPENDENCIES EXAMPLES\n";
    echo str_repeat("=", 60) . "\n";
    echo "🔗 Testing flag dependencies with local evaluation...\n";
    echo "   Flag structure: 'test-flag-dependency' depends on 'beta-feature' being enabled\n";
    echo "\n";
    echo "📋 Required setup (if 'test-flag-dependency' doesn't exist):\n";
    echo "   1. Create feature flag 'beta-feature':\n";
    echo "      - Condition: email contains '@example.com'\n";
    echo "      - Rollout: 100%\n";
    echo "   2. Create feature flag 'test-flag-dependency':\n";
    echo "      - Condition: flag 'beta-feature' is enabled\n";
    echo "      - Rollout: 100%\n";
    echo "\n";

    // Enable debug for this section
    PostHog::init(
        $_ENV['POSTHOG_PROJECT_API_KEY'],
        [
            'host' => $_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com',
            'debug' => true,
            'ssl' => !str_starts_with($_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com', 'http://')
        ],
        null,
        $_ENV['POSTHOG_PERSONAL_API_KEY']
    );

    // Test @example.com user (should satisfy dependency if flags exist)
    $result1 = PostHog::getFeatureFlag(
        "test-flag-dependency",
        "example_user",
        [],
        ["email" => "user@example.com"],
        [],
        true // only_evaluate_locally
    );
    echo "✅ @example.com user (test-flag-dependency): " . json_encode($result1) . "\n";

    // Test non-example.com user (dependency should not be satisfied)
    $result2 = PostHog::getFeatureFlag(
        "test-flag-dependency",
        "regular_user",
        [],
        ["email" => "user@other.com"],
        [],
        true
    );
    echo "❌ Regular user (test-flag-dependency): " . json_encode($result2) . "\n";

    // Test beta-feature directly for comparison
    $beta1 = PostHog::getFeatureFlag(
        "beta-feature",
        "example_user",
        [],
        ["email" => "user@example.com"],
        [],
        true
    );
    $beta2 = PostHog::getFeatureFlag(
        "beta-feature",
        "regular_user",
        [],
        ["email" => "user@other.com"],
        [],
        true
    );
    echo "📊 Beta feature comparison - @example.com: "
        . json_encode($beta1)
        . ", regular: "
        . json_encode($beta2)
        . "\n";

    echo "\n🎯 Results Summary:\n";
    echo "   - Flag dependencies evaluated locally: " . ($result1 != $result2 ? "✅ YES" : "❌ NO") . "\n";
    echo "   - Zero API calls needed: ✅ YES (all evaluated locally)\n";
    echo "   - PHP SDK supports flag dependencies: ✅ YES\n";

    echo "\n" . str_repeat("-", 60) . "\n";
    echo "PRODUCTION-STYLE MULTIVARIATE DEPENDENCY CHAIN\n";
    echo str_repeat("-", 60) . "\n";
    echo "🔗 Testing complex multivariate flag dependencies...\n";
    echo "   Structure: multivariate-root-flag -> multivariate-intermediate-flag -> multivariate-leaf-flag\n";
    echo "\n";
    echo "📋 Required setup (if flags don't exist):\n";
    echo "   1. Create 'multivariate-leaf-flag' with fruit variants (pineapple, mango, papaya, kiwi)\n";
    echo "      - pineapple: email = 'pineapple@example.com'\n";
    echo "      - mango: email = 'mango@example.com'\n";
    echo "   2. Create 'multivariate-intermediate-flag' with color variants (blue, red)\n";
    echo "      - blue: depends on multivariate-leaf-flag = 'pineapple'\n";
    echo "      - red: depends on multivariate-leaf-flag = 'mango'\n";
    echo "   3. Create 'multivariate-root-flag' with show variants (breaking-bad, the-wire)\n";
    echo "      - breaking-bad: depends on multivariate-intermediate-flag = 'blue'\n";
    echo "      - the-wire: depends on multivariate-intermediate-flag = 'red'\n";
    echo "\n";

    // Test pineapple -> blue -> breaking-bad chain
    $dependentResult3 = PostHog::getFeatureFlag(
        "multivariate-root-flag",
        "regular_user",
        [],
        ["email" => "pineapple@example.com"],
        [],
        true
    );
    if ($dependentResult3 !== "breaking-bad") {
        echo "     ❌ Something went wrong evaluating 'multivariate-root-flag' with pineapple@example.com. "
            . "Expected 'breaking-bad', got '"
            . json_encode($dependentResult3)
            . "'\n";
    } else {
        echo "✅ 'multivariate-root-flag' with email pineapple@example.com succeeded\n";
    }

    // Test mango -> red -> the-wire chain
    $dependentResult4 = PostHog::getFeatureFlag(
        "multivariate-root-flag",
        "regular_user",
        [],
        ["email" => "mango@example.com"],
        [],
        true
    );
    if ($dependentResult4 !== "the-wire") {
        echo "     ❌ Something went wrong evaluating multivariate-root-flag with mango@example.com. "
            . "Expected 'the-wire', got '"
            . json_encode($dependentResult4)
            . "'\n";
    } else {
        echo "✅ 'multivariate-root-flag' with email mango@example.com succeeded\n";
    }

    // Show the complete chain evaluation
    echo "\n🔍 Complete dependency chain evaluation:\n";
    $scenarios = [
        ["email" => "pineapple@example.com", "expected" => ["pineapple", "blue", "breaking-bad"]],
        ["email" => "mango@example.com", "expected" => ["mango", "red", "the-wire"]]
    ];

    foreach ($scenarios as $scenario) {
        $email = $scenario["email"];
        $expectedChain = $scenario["expected"];

        $leaf = PostHog::getFeatureFlag(
            "multivariate-leaf-flag",
            "regular_user",
            [],
            ["email" => $email],
            [],
            true
        );
        $intermediate = PostHog::getFeatureFlag(
            "multivariate-intermediate-flag",
            "regular_user",
            [],
            ["email" => $email],
            [],
            true
        );
        $root = PostHog::getFeatureFlag(
            "multivariate-root-flag",
            "regular_user",
            [],
            ["email" => $email],
            [],
            true
        );

        $actualChain = [$leaf, $intermediate, $root];
        $chainSuccess = $actualChain === $expectedChain;

        echo "   📧 $email:\n";
        echo "      Expected: " . implode(" -> ", $expectedChain) . "\n";
        echo "      Actual:   " . implode(" -> ", array_map('strval', $actualChain)) . "\n";
        echo "      Status:   " . ($chainSuccess ? "✅ SUCCESS" : "❌ FAILED") . "\n";
    }

    echo "\n🎯 Multivariate Chain Summary:\n";
    echo "   - Complex dependency chains: ✅ SUPPORTED\n";
    echo "   - Multivariate flag dependencies: ✅ SUPPORTED\n";
    echo "   - Local evaluation of chains: ✅ WORKING\n";
}

function contextManagementExamples()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "CONTEXT MANAGEMENT AND TAGGING EXAMPLES\n";
    echo str_repeat("=", 60) . "\n";

    // Enable debug for this section
    PostHog::init(
        $_ENV['POSTHOG_PROJECT_API_KEY'],
        [
            'host' => $_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com',
            'debug' => true,
            'ssl' => !str_starts_with($_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com', 'http://')
        ],
        null,
        $_ENV['POSTHOG_PERSONAL_API_KEY']
    );

    echo "🏷️ Testing groups and properties...\n";

    // Capture event with groups
    PostHog::capture([
        'distinctId' => 'group_user_1',
        'event' => 'group_event',
        'properties' => [
            'plan' => 'enterprise',
            'feature_used' => 'advanced_analytics'
        ],
        'groups' => [
            'company' => 'acme_corp',
            'team' => 'engineering'
        ]
    ]);

    // Test feature flags with group properties
    echo "🚩 Testing flags with group context...\n";
    $flagValue = PostHog::getFeatureFlag(
        "enterprise_features",
        "group_user_1",
        ['company' => 'acme_corp'],
        ['plan' => 'enterprise'],
        ['company' => ['name' => 'Acme Corp', 'employees' => 100]]
    );

    echo "Enterprise features flag: " . ($flagValue ? json_encode($flagValue) : 'false') . "\n";

    echo "✅ Context management examples completed!\n";
}

function etagPollingExamples()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "ETAG POLLING EXAMPLES\n";
    echo str_repeat("=", 60) . "\n";
    echo "This example demonstrates ETag-based caching for feature flags.\n";
    echo "ETag support reduces bandwidth by skipping full payload transfers\n";
    echo "when flags haven't changed (304 Not Modified response).\n\n";

    // Re-initialize with debug enabled
    PostHog::init(
        $_ENV['POSTHOG_PROJECT_API_KEY'],
        [
            'host' => $_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com',
            'debug' => true,
            'ssl' => !str_starts_with($_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com', 'http://')
        ],
        null,
        $_ENV['POSTHOG_PERSONAL_API_KEY']
    );

    $client = PostHog::getClient();

    // Initial load - should get full response with ETag
    echo "📥 Initial flag load (expecting full response with ETag)...\n";
    $client->loadFlags();
    $initialEtag = $client->getFlagsEtag();
    $flagCount = count($client->featureFlags);

    if ($initialEtag) {
        echo "   ✅ Received ETag: " . substr($initialEtag, 0, 30) . "...\n";
    } else {
        echo "   ⚠️  No ETag received (server may not support ETag caching)\n";
    }
    echo "   📊 Loaded $flagCount feature flag(s)\n\n";

    // Second load - should get 304 Not Modified if flags haven't changed
    echo "📥 Second flag load (expecting 304 Not Modified if unchanged)...\n";
    $client->loadFlags();
    $secondEtag = $client->getFlagsEtag();
    $secondFlagCount = count($client->featureFlags);

    echo "   📊 Flag count: $secondFlagCount (should match initial: $flagCount)\n";
    if ($secondEtag === $initialEtag && $initialEtag !== null) {
        echo "   ✅ ETag unchanged - server likely returned 304 Not Modified\n";
    } elseif ($secondEtag !== null) {
        echo "   📝 ETag changed: " . substr($secondEtag, 0, 30) . "...\n";
        echo "      (flags may have been updated on the server)\n";
    }
    echo "\n";

    // Continuous polling - runs until Ctrl+C
    echo "🔄 Starting continuous polling (every 5 seconds)...\n";
    echo "   Press Ctrl+C to stop.\n";
    echo "   Try changing feature flags in PostHog to see ETag changes!\n\n";

    $iteration = 1;
    while (true) {
        $timestamp = date('H:i:s');
        echo "   [$timestamp] Poll #$iteration: ";

        $beforeEtag = $client->getFlagsEtag();
        $client->loadFlags();
        $afterEtag = $client->getFlagsEtag();
        $currentFlagCount = count($client->featureFlags);

        if ($beforeEtag === $afterEtag && $beforeEtag !== null) {
            echo "No change (304 Not Modified) - $currentFlagCount flag(s)\n";
        } else {
            echo "🔄 Flags updated! New ETag: "
                . ($afterEtag ? substr($afterEtag, 0, 20) . "..." : "none")
                . " - $currentFlagCount flag(s)\n";
        }

        $iteration++;
        sleep(5);
    }
}

function errorTrackingExamples()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "ERROR TRACKING EXAMPLES\n";
    echo str_repeat("=", 60) . "\n";

    PostHog::init(
        $_ENV['POSTHOG_PROJECT_API_KEY'],
        [
            'host' => $_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com',
            'debug' => true,
            'ssl' => !str_starts_with($_ENV['POSTHOG_HOST'] ?? 'https://app.posthog.com', 'http://'),
            'enable_error_tracking' => true,
            'capture_uncaught_exceptions' => true,
            'capture_errors' => true,
            'capture_fatal_errors' => true,
            'error_reporting_mask' => E_ALL,
            'error_tracking_context_provider' => static function (array $payload): array {
                return [
                    'distinctId' => 'sdk-demo-user',
                    'properties' => [
                        '$error_source' => $payload['source'],
                    ],
                ];
            },
        ],
        null,
        $_ENV['POSTHOG_PERSONAL_API_KEY']
    );

    echo "Auto capture enabled for uncaught exceptions, PHP errors, and fatal shutdown errors.\n";
    echo "The demo below still uses manual capture so it can finish without crashing the process.\n\n";

    // 1. Capture a plain string error (no user context)
    echo "1. Capturing anonymous string error...\n";
    PostHog::captureException('Something went wrong during startup');
    echo "   -> sent with auto-generated distinct_id, \$process_person_profile=false\n\n";

    // 2. Capture an exception for a known user
    echo "2. Capturing exception for a known user...\n";
    try {
        throw new \RuntimeException('Database connection failed');
    } catch (\RuntimeException $e) {
        PostHog::captureException($e, 'user-123');
    }
    echo "   -> sent as \$exception event with stacktrace\n\n";

    // 3. Capture with additional context properties
    echo "3. Capturing exception with request context...\n";
    try {
        throw new \InvalidArgumentException('Invalid email address provided');
    } catch (\InvalidArgumentException $e) {
        PostHog::captureException($e, 'user-456', [
            '$current_url'    => 'https://example.com/signup',
            '$request_method' => 'POST',
            'form_field'      => 'email',
        ]);
    }
    echo "   -> sent with URL and request context\n\n";

    // 4. Capture a chained exception (cause + wrapper both appear in \$exception_list)
    echo "4. Capturing chained exception...\n";
    try {
        try {
            throw new \PDOException('SQLSTATE[HY000]: General error: disk full');
        } catch (\PDOException $cause) {
            throw new \RuntimeException('Failed to save user record', 0, $cause);
        }
    } catch (\RuntimeException $e) {
        PostHog::captureException($e, 'user-789');
    }
    echo "   -> sent with 2 entries in \$exception_list (cause + wrapper)\n\n";

    // 5. Capture a PHP Error (not just Exception)
    echo "5. Capturing a TypeError (PHP Error subclass)...\n";
    try {
        $result = array_sum('not-an-array');
    } catch (\TypeError $e) {
        PostHog::captureException($e, 'user-123');
    }
    echo "   -> any Throwable (Error or Exception) is accepted\n\n";

    PostHog::flush();
    echo "Flushed all events.\n";
    echo "Check your PostHog dashboard -> Error Tracking to see the captured exceptions.\n";
}

function runAllExamples()
{
    identifyAndCaptureExamples();
    echo "\n" . str_repeat("-", 60) . "\n";

    featureFlagExamples();
    echo "\n" . str_repeat("-", 60) . "\n";

    flagDependencyExamples();
    echo "\n" . str_repeat("-", 60) . "\n";

    contextManagementExamples();
    echo "\n" . str_repeat("-", 60) . "\n";

    errorTrackingExamples();

    echo "\n🎉 All examples completed!\n";
    echo "   (ETag polling skipped - run separately with option 5)\n";
}

// Handle user choice
switch ($choice) {
    case '1':
        identifyAndCaptureExamples();
        break;
    case '2':
        featureFlagExamples();
        break;
    case '3':
        flagDependencyExamples();
        break;
    case '4':
        contextManagementExamples();
        break;
    case '5':
        etagPollingExamples();
        break;
    case '6':
        errorTrackingExamples();
        break;
    case '7':
        runAllExamples();
        break;
    case '8':
        echo "👋 Goodbye!\n";
        exit(0);
    default:
        echo "❌ Invalid choice. Please run the script again and choose 1-8.\n";
        exit(1);
}

echo "\n💡 Tip: Check your PostHog dashboard to see the captured events and user data!\n";
echo "📖 For more examples and documentation, visit: https://posthog.com/docs/integrations/php-integration\n";
