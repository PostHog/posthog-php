<?php

namespace PostHog;

use Exception;
use InvalidArgumentException;
use PostHog\Consumer\File;
use PostHog\Consumer\ForkCurl;
use PostHog\Consumer\LibCurl;
use PostHog\Consumer\NoOp;
use PostHog\Consumer\Socket;
use Symfony\Component\Clock\Clock;
use Throwable;

/**
 * PostHog PHP SDK client for event capture, user identification, feature flags, and error tracking.
 */
class Client implements FeatureFlagEvaluationsHost
{
    private const SIZE_LIMIT = 50_000;
    private const CONSUMERS = [
        "socket" => Socket::class,
        "file" => File::class,
        "fork_curl" => ForkCurl::class,
        "lib_curl" => LibCurl::class,
        "noop" => NoOp::class,
    ];


    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $personalAPIKey;

    /**
     * Feature flag request timeout in milliseconds. Defaults to 3000ms.
     *
     * @var integer
     */
    private $featureFlagsRequestTimeout;

    /**
     * Maximum number of retries for /flags/?v=2 transient network errors.
     * Defaults to 1. Set to 0 to disable feature flag request retries.
     *
     * @var integer
     */
    private $featureFlagRequestMaxRetries;

    /**
     * Maximum retry backoff duration in milliseconds. Defaults to 10000ms.
     * Retry backoff starts at 100ms and doubles until capped by this value.
     *
     * @var integer
     */
    private $maximumBackoffDurationMs;

    /**
     * Consumer object handles queueing and bundling requests to PostHog.
     *
     * @var Consumer
     */
    protected $consumer;

    /**
     * @var HttpClient
     */
    public $httpClient;

    /**
     * @var array
     */
    public $featureFlags;

    /**
     * @var array
     */
    public $groupTypeMapping;

    /**
     * @var array
     */
    public $cohorts;

    /**
     * @var array
     */
    public $featureFlagsByKey;

    /**
     * @var SizeLimitedHash
     */
    public $distinctIdsFeatureFlagsReported;

    /**
     * @var string|null Cached ETag for feature flag definitions
     */
    private $flagsEtag;

    /**
     * @var bool Whether flag definitions have been loaded successfully at least once.
     */
    private $flagDefinitionsLoaded;

    /**
     * @var FlagDefinitionCacheProvider|null External shared cache provider for flag definitions.
     */
    private $flagDefinitionCacheProvider;

    /**
     * @var bool Whether the external flag definition cache provider has been shut down.
     */
    private $flagDefinitionCacheProviderShutdown;

    /**
     * @var bool Whether client shutdown has already run.
     */
    private $shutdownComplete;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var array<string, mixed>
     */
    private $options;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var array<string, bool>
     */
    private array $missingDistinctIdWarnings = [];

    /**
     * Create a new PostHog client with your project's API key.
     *
     * @param string|null $apiKey Your project API key. When omitted or empty, the client is disabled
     *     and uses the noop consumer.
     * Time-based options use milliseconds unless the option name says otherwise:
     * `timeout` defaults to 10000ms, `feature_flag_request_timeout_ms` defaults to 3000ms,
     * and `maximum_backoff_duration` defaults to 10000ms for retry backoff. Retry backoff starts
     * at 100ms and doubles until capped by `maximum_backoff_duration`. `flush_interval_seconds`
     * defaults to 5 seconds. For the socket consumer, `timeout` is passed to pfsockopen() and is
     * in seconds.
     *
     * Feature flag requests to `/flags/?v=2` retry transient curl/network errors only.
     * `feature_flag_request_max_retries` defaults to 1; set it to 0 to disable these retries.
     *
     * @param array{
     *     host?: string,
     *     ssl?: bool,
     *     timeout?: int|float,
     *     verify_batch_events_request?: bool,
     *     feature_flag_request_timeout_ms?: int,
     *     feature_flag_request_max_retries?: int,
     *     maximum_backoff_duration?: int,
     *     consumer?: 'socket'|'file'|'fork_curl'|'lib_curl'|'noop',
     *     debug?: bool,
     *     max_queue_size?: int,
     *     batch_size?: int,
     *     flush_interval_seconds?: int|float,
     *     compress_request?: bool|string,
     *     error_handler?: callable,
     *     filename?: string,
     *     is_server?: bool,
     *     flag_definition_cache_provider?: FlagDefinitionCacheProvider,
     *     error_tracking?: array{
     *         enabled?: bool,
     *         capture_errors?: bool,
     *         excluded_exceptions?: list<class-string>,
     *         max_frames?: int,
     *         context_provider?: callable
     *     }
     * } $options Client and consumer configuration options.
     * @param HttpClient|null $httpClient Custom HTTP client, primarily for tests and advanced integrations.
     * @param string|null $personalAPIKey Personal API key used to load local feature flag definitions.
     * @param bool $loadFeatureFlags Whether to load local feature flag definitions during construction.
     */
    public function __construct(
        ?string $apiKey = null,
        array $options = [],
        ?HttpClient $httpClient = null,
        ?string $personalAPIKey = null,
        bool $loadFeatureFlags = true,
    ) {
        $this->apiKey = StringNormalizer::normalizeOptional($apiKey) ?? '';
        $this->enabled = $this->apiKey !== '';
        $this->personalAPIKey = StringNormalizer::normalizeOptional($personalAPIKey);
        $this->options = $options;
        $this->debug = $options["debug"] ?? false;
        $this->flagDefinitionCacheProvider = self::normalizeFlagDefinitionCacheProvider(
            $options['flag_definition_cache_provider'] ?? null
        );
        $this->flagDefinitionCacheProviderShutdown = false;
        $this->shutdownComplete = false;
        $this->options['host'] = StringNormalizer::normalizeHost($options['host'] ?? null);
        if (!$this->enabled) {
            if (($this->options['consumer'] ?? null) !== 'noop') {
                error_log('[PostHog][Client] apiKey is empty after trimming whitespace; check your project API key');
            }
            $this->options['consumer'] = 'noop';
        }
        $Consumer = self::CONSUMERS[$this->options["consumer"] ?? "lib_curl"];
        $this->consumer = new $Consumer($this->apiKey, $this->options, $httpClient);
        $this->maximumBackoffDurationMs = (int) ($options['maximum_backoff_duration'] ?? 10000);
        $this->httpClient = $httpClient !== null ? $httpClient : new HttpClient(
            $this->options['host'],
            $options['ssl'] ?? true,
            $this->maximumBackoffDurationMs,
            false,
            $options["debug"] ?? false,
            null,
            (int) ($options['timeout'] ?? 10000)
        );
        $this->featureFlagsRequestTimeout = (int) ($options['feature_flag_request_timeout_ms'] ?? 3000);
        $this->featureFlagRequestMaxRetries = max(0, (int) ($options['feature_flag_request_max_retries'] ?? 1));
        $this->featureFlags = [];
        $this->groupTypeMapping = [];
        $this->cohorts = [];
        $this->featureFlagsByKey = [];
        $this->distinctIdsFeatureFlagsReported = new SizeLimitedHash(self::SIZE_LIMIT);
        $this->flagsEtag = null;
        $this->flagDefinitionsLoaded = false;

        if ($this->enabled) {
            ExceptionCapture::configure($this, $options['error_tracking'] ?? []);
        }

        // Populate featureflags and grouptypemapping if possible
        if (
            $this->enabled
            && count($this->featureFlags) == 0
            && !is_null($this->personalAPIKey)
            && $loadFeatureFlags
        ) {
            $this->loadFlags();
        }
    }

    /**
     * Validate and normalize an optional external flag definition cache provider.
     *
     * @param mixed $provider
     * @return FlagDefinitionCacheProvider|null
     */
    private static function normalizeFlagDefinitionCacheProvider(mixed $provider): ?FlagDefinitionCacheProvider
    {
        if ($provider === null) {
            return null;
        }

        if (!$provider instanceof FlagDefinitionCacheProvider) {
            throw new InvalidArgumentException(
                'flag_definition_cache_provider must implement PostHog\\FlagDefinitionCacheProvider'
            );
        }

        return $provider;
    }

    /**
     * Flush and clean up the underlying consumer when the client is destroyed.
     */
    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * Flush queued events and release resources held by the client.
     *
     * @return bool True if flushing succeeded or the consumer has no flush operation.
     */
    public function shutdown(): bool
    {
        if ($this->shutdownComplete) {
            return true;
        }

        $flushed = $this->flush();
        $this->shutdownFlagDefinitionCacheProvider();
        $this->consumer->__destruct();
        // Release the per-client feature-flag-called dedupe cache once the client is shut down.
        $this->distinctIdsFeatureFlagsReported = new SizeLimitedHash(self::SIZE_LIMIT);
        $this->shutdownComplete = true;

        return $flushed;
    }

    /**
     * Captures a user action.
     *
     * @param array{
     *     event: string,
     *     distinctId?: string,
     *     distinct_id?: string,
     *     properties?: array<string, mixed>,
     *     groups?: array<string, mixed>,
     *     timestamp?: mixed,
     *     uuid?: string,
     *     flags?: FeatureFlagEvaluations,
     *     send_feature_flags?: bool,
     *     sendFeatureFlags?: bool
     * } $message Event payload. If a top-level `uuid` is supplied it must be a valid UUID;
     *     invalid values are replaced with a generated UUID v4. `send_feature_flags` and
     *     `sendFeatureFlags` are deprecated; pass
     *     a `flags` snapshot from evaluateFlags() instead. Deprecated top-level batch metadata is
     *     stripped before sending: use `event` instead of `type`, `properties['$lib']` instead of
     *     `library`, `properties['$lib_version']` instead of `library_version`, and
     *     `properties['$lib_consumer']` instead of `library_consumer`. Legacy top-level SDK metadata
     *     values are still used as fallbacks when the canonical property is absent; `type` is ignored.
     * @return bool Whether the capture call succeeded.
     */
    public function capture(array $message)
    {
        $flagsSnapshot = $message["flags"] ?? null;
        unset($message["flags"]);
        $hasGroups = array_key_exists("groups", $message);

        $usedGeneratedPersonlessDistinctId = false;
        if ($this->shouldApplyCaptureContext($message)) {
            $message = $this->applyCaptureContext($message, $usedGeneratedPersonlessDistinctId);
        }
        $message = $this->message($message);
        $message = $this->normalizeMessageUuid($message);

        if (!array_key_exists('$groups', $message) && $hasGroups) {
            $message['$groups'] = $message['groups'];
        }

        if (array_key_exists('$groups', $message)) {
            $message["properties"]['$groups'] = $message['$groups'];
        }

        if ($flagsSnapshot instanceof FeatureFlagEvaluations) {
            // Precedence: an explicit `flags` snapshot always wins over `send_feature_flags`. The
            // snapshot guarantees the event carries the same values the developer branched on, with
            // no additional /flags request.
            if (!empty($message["send_feature_flags"])) {
                error_log(
                    "[PostHog][Client] Both `flags` and `send_feature_flags` were passed to "
                    . "capture(); using `flags` and ignoring `send_feature_flags`."
                );
            }
            $message["properties"] = array_merge(
                $flagsSnapshot->getEventProperties(),
                $message["properties"]
            );
        } elseif (array_key_exists("send_feature_flags", $message) && $message["send_feature_flags"]) {
            trigger_error(
                'capture()\'s `send_feature_flags` option is deprecated and will be removed in a '
                . 'future major version. Pass a `flags` snapshot from Client::evaluateFlags(...) '
                . 'instead — it avoids a second /flags request per capture and guarantees the '
                . 'event carries the exact flag values your code branched on.',
                E_USER_DEPRECATED
            );

            if (!$usedGeneratedPersonlessDistinctId) {
                $extraProperties = [];
                $flags = [];

                if (count($this->featureFlags) != 0) {
                    // Local evaluation is enabled, flags are loaded, so try and get all flags
                    // we can without going to the server.
                    $flags = $this->getAllFlags($message["distinct_id"], $message["groups"], [], [], true);
                } else {
                    $flags = $this->fetchFeatureVariants($message["distinct_id"], $message["groups"]);
                }

                // Add all feature variants to event
                foreach ($flags as $flagKey => $flagValue) {
                    $extraProperties[sprintf('$feature/%s', $flagKey)] = $flagValue;
                }
                // Add all feature flag keys that aren't false to $active_feature_flags
                // decide v2 does this automatically, but we need it for when we upgrade to v3
                $extraProperties['$active_feature_flags'] = array_keys(array_filter($flags, function ($flagValue) {
                    return $flagValue !== false;
                }));

                $message["properties"] = array_merge($extraProperties, $message["properties"]);
            }
        }

        unset($message["send_feature_flags"]);

        return $this->consumer->capture($message);
    }

    /**
     * Captures an exception as a PostHog error tracking event.
     *
     * @param \Throwable|string $exception The exception to capture or a plain string message
     * @param string|null $distinctId User ID; a random UUID is used when omitted (no person profile created)
     * @param array $additionalProperties Extra properties merged into the event
     * @return bool whether the capture call succeeded
     */
    public function captureException(
        \Throwable|string $exception,
        ?string $distinctId = null,
        array $additionalProperties = []
    ): bool {
        $errorTrackingConfig = $this->options['error_tracking'] ?? [];
        $maxFrames = max(0, (int) ($errorTrackingConfig['max_frames'] ?? 20));

        $exceptionList = ExceptionPayloadBuilder::buildExceptionList($exception, $maxFrames);
        if (empty($exceptionList)) {
            return false;
        }

        $properties = array_merge(
            $additionalProperties,
            [
                '$exception_list' => $exceptionList,
                '$exception_handled' => ExceptionPayloadBuilder::getPrimaryHandled($exceptionList),
            ]
        );

        $message = [
            'event'       => '$exception',
            'properties'  => $properties,
        ];

        if ($distinctId !== null) {
            $message['distinctId'] = $distinctId;
        }

        return $this->capture($message);
    }

    /**
     * Tags properties about the user.
     *
     * @param array{distinctId?: string, distinct_id?: string, properties?: array<string, mixed>} $message
     * @return bool Whether the identify call succeeded.
     */
    public function identify(array $message)
    {
        if (isset($message['properties'])) {
            $message['$set'] = $message['properties'];
        }

        $message = $this->message($message);
        $message["event"] = '$identify';
        unset($message["send_feature_flags"]);

        return $this->consumer->identify($message);
    }

    /**
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call
     * `$flags->isEnabled($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key Feature flag key.
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @param bool $sendFeatureFlagEvents Whether to send $feature_flag_called events.
     * @return bool|null
     * @throws Exception
     */
    public function isFeatureEnabled(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): null | bool {
        trigger_error(
            'Client::isFeatureEnabled() is deprecated and will be removed in a future major '
            . 'version. Use Client::evaluateFlags($distinctId, ...) and call '
            . '$flags->isEnabled($key) instead — this consolidates flag evaluation into a '
            . 'single /flags request per incoming request.',
            E_USER_DEPRECATED
        );

        // Route through the private helper so the user sees exactly one deprecation warning
        // per call, not two (or three).
        $result = $this->doGetFeatureFlagResult(
            $key,
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            $onlyEvaluateLocally,
            $sendFeatureFlagEvents
        );

        if ($result === null) {
            return null;
        }

        return boolval($result->getValue());
    }

    /**
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call
     * `$flags->getFlag($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key Feature flag key.
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @param bool $sendFeatureFlagEvents Whether to send $feature_flag_called events.
     * @return bool|string|null
     * @throws Exception
     */
    public function getFeatureFlag(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): null | bool | string {
        trigger_error(
            'Client::getFeatureFlag() is deprecated and will be removed in a future major '
            . 'version. Use Client::evaluateFlags($distinctId, ...) and call '
            . '$flags->getFlag($key) instead — this consolidates flag evaluation into a '
            . 'single /flags request per incoming request.',
            E_USER_DEPRECATED
        );

        // Route through the private helper so the user sees exactly one deprecation warning.
        $result = $this->doGetFeatureFlagResult(
            $key,
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            $onlyEvaluateLocally,
            $sendFeatureFlagEvents
        );

        return $result?->getValue();
    }

    /**
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call `$flags->getFlag($key)` and
     * `$flags->getFlagPayload($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key Feature flag key.
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @param bool $sendFeatureFlagEvents Whether to send $feature_flag_called events.
     * @return FeatureFlagResult|null
     * @throws Exception
     */
    public function getFeatureFlagResult(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): ?FeatureFlagResult {
        trigger_error(
            'Client::getFeatureFlagResult() is deprecated and will be removed in a future major '
            . 'version. Use Client::evaluateFlags($distinctId, ...) and call $flags->getFlag($key) '
            . '(and $flags->getFlagPayload($key) if you need the payload) instead — this '
            . 'consolidates flag evaluation into a single /flags request per incoming request.',
            E_USER_DEPRECATED
        );

        return $this->doGetFeatureFlagResult(
            $key,
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            $onlyEvaluateLocally,
            $sendFeatureFlagEvents
        );
    }

    /**
     * Internal entry point for the rich single-flag fetch. Public callers should go through
     * the deprecated `getFeatureFlagResult()`; the deprecated `isFeatureEnabled()` /
     * `getFeatureFlag()` paths route directly here so a single user-level call surfaces exactly
     * one deprecation warning, not two.
     *
     * @param array<string, mixed> $groups
     * @param array<string, mixed> $personProperties
     * @param array<string, array<string, mixed>> $groupProperties
     * @throws Exception
     */
    private function doGetFeatureFlagResult(
        string $key,
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): ?FeatureFlagResult {
        $distinctId = $this->resolveDistinctId($distinctId);
        if ($distinctId === '') {
            $this->warnMissingDistinctId('Feature flag evaluation');
            return null;
        }

        [$personProperties, $groupProperties] = $this->addLocalPersonAndGroupProperties(
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties
        );
        $result = null;
        $payload = null;
        $featureFlagError = null;

        foreach ($this->featureFlags as $flag) {
            if ($flag["key"] == $key) {
                try {
                    $result = $this->computeFlagLocally(
                        $flag,
                        $distinctId,
                        $groups,
                        $personProperties,
                        $groupProperties
                    );
                } catch (RequiresServerEvaluationException $e) {
                    $result = null;
                } catch (InconclusiveMatchException $e) {
                    $result = null;
                } catch (Exception $e) {
                    $result = null;
                    error_log("[PostHog][Client] Error while computing variant:" . $e->getMessage());
                }
            }
        }

        $flagWasEvaluatedLocally = !is_null($result);
        $requestId = null;
        $evaluatedAt = null;
        $flagDetail = null;

        if (!$flagWasEvaluatedLocally && !$onlyEvaluateLocally) {
            try {
                $response = $this->requestFlags($distinctId, $groups, $personProperties, $groupProperties, false, [$key]);
                $errors = [];

                if (isset($response['errorsWhileComputingFlags']) && $response['errorsWhileComputingFlags']) {
                    $errors[] = FeatureFlagError::ERRORS_WHILE_COMPUTING_FLAGS;
                }

                $requestId = isset($response['requestId']) ? $response['requestId'] : null;
                $evaluatedAt = isset($response['evaluatedAt']) ? $response['evaluatedAt'] : null;
                $rawFlag = $response['flags'][$key] ?? null;
                $flagDetail = ($rawFlag !== null && !($rawFlag['failed'] ?? false))
                    ? $rawFlag
                    : null;
                $featureFlags = $response['featureFlags'] ?? [];
                if (array_key_exists($key, $featureFlags)) {
                    $result = $featureFlags[$key];
                } else {
                    $errors[] = FeatureFlagError::FLAG_MISSING;
                    $result = null;
                }

                // Extract payload from response
                $rawPayload = $response['featureFlagPayloads'][$key] ?? null;
                if ($rawPayload !== null) {
                    $payload = json_decode($rawPayload, true);
                }

                if (!empty($errors)) {
                    $featureFlagError = implode(',', $errors);
                }
            } catch (HttpException $e) {
                error_log("[PostHog][Client] Unable to get feature variants: " . $e->getMessage());
                switch ($e->getErrorType()) {
                    case HttpException::QUOTA_LIMITED:
                        $featureFlagError = FeatureFlagError::QUOTA_LIMITED;
                        break;
                    case HttpException::TIMEOUT:
                        $featureFlagError = FeatureFlagError::TIMEOUT;
                        break;
                    case HttpException::CONNECTION_ERROR:
                        $featureFlagError = FeatureFlagError::CONNECTION_ERROR;
                        break;
                    case HttpException::API_ERROR:
                        $featureFlagError = FeatureFlagError::apiError($e->getStatusCode());
                        break;
                    default:
                        $featureFlagError = FeatureFlagError::UNKNOWN_ERROR;
                }
                $result = null;
            } catch (Exception $e) {
                error_log("[PostHog][Client] Unable to get feature variants: " . $e->getMessage());
                $featureFlagError = FeatureFlagError::UNKNOWN_ERROR;
                $result = null;
            }
        }

        if ($sendFeatureFlagEvents) {
            $properties = [
                '$feature_flag' => $key,
                '$feature_flag_response' => $result,
            ];

            if (!is_null($requestId)) {
                $properties['$feature_flag_request_id'] = $requestId;
            }

            if (!is_null($evaluatedAt)) {
                $properties['$feature_flag_evaluated_at'] = $evaluatedAt;
            }

            if (!is_null($flagDetail)) {
                $properties['$feature_flag_id'] = $flagDetail['metadata']['id'];
                $properties['$feature_flag_version'] = $flagDetail['metadata']['version'];
                $properties['$feature_flag_reason'] = $flagDetail['reason']['description'];
            }

            if (!is_null($featureFlagError)) {
                $properties['$feature_flag_error'] = $featureFlagError;
            }

            $this->captureFlagCalledIfNeeded($distinctId, $key, $result, $properties, $groups);
        }

        if (is_null($result)) {
            return null;
        }

        // Determine enabled and variant from result
        if (is_bool($result)) {
            return new FeatureFlagResult($key, $result, null, $payload);
        } else {
            return new FeatureFlagResult($key, true, $result, $payload);
        }
    }

    /**
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call
     * `$flags->getFlagPayload($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key Feature flag key.
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @return mixed
     */
    public function getFeatureFlagPayload(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
    ): mixed {
        trigger_error(
            'Client::getFeatureFlagPayload() is deprecated and will be removed in a future major '
            . 'version. Use Client::evaluateFlags($distinctId, ...) and call '
            . '$flags->getFlagPayload($key) instead — this consolidates flag evaluation into a '
            . 'single /flags request per incoming request.',
            E_USER_DEPRECATED
        );

        // Route through the private helper so the user sees exactly one deprecation warning.
        $result = $this->doGetFeatureFlagResult(
            $key,
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            false,
            false
        );

        return $result?->getPayload();
    }

    /**
     * get the feature flag value for this distinct id.
     *
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @return array<string, bool|string>
     * @throws Exception
     */
    public function getAllFlags(
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false
    ): array {
        $distinctId = $this->resolveDistinctId($distinctId);
        if ($distinctId === '') {
            $this->warnMissingDistinctId('getAllFlags()');
            return [];
        }

        [$personProperties, $groupProperties] = $this->addLocalPersonAndGroupProperties(
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties
        );
        $response = [];
        $fallbackToFlags = false;

        if (count($this->featureFlags) > 0) {
            foreach ($this->featureFlags as $flag) {
                try {
                    $response[$flag['key']] = $this->computeFlagLocally(
                        $flag,
                        $distinctId,
                        $groups,
                        $personProperties,
                        $groupProperties
                    );
                } catch (RequiresServerEvaluationException $e) {
                    $fallbackToFlags = true;
                } catch (InconclusiveMatchException $e) {
                    $fallbackToFlags = true;
                } catch (Exception $e) {
                    $fallbackToFlags = true;
                    error_log("[PostHog][Client] Error while computing variant:" . $e->getMessage());
                }
            }
        } else {
            $fallbackToFlags = true;
        }

        if ($fallbackToFlags && !$onlyEvaluateLocally) {
            try {
                $featureFlags = $this->fetchFeatureVariants($distinctId, $groups, $personProperties, $groupProperties);
                $response = array_merge($response, $featureFlags);
            } catch (Exception $e) {
                error_log("[PostHog][Client] Unable to get feature variants:" . $e->getMessage());
            }
        }

        return $response;
    }

    /**
     * Evaluate every feature flag for a distinct id in a single round trip and return a
     * FeatureFlagEvaluations snapshot. When distinctId is omitted, the current request context
     * distinctId is used if available. Reads on the snapshot do not trigger additional /flags
     * requests; access via isEnabled() or getFlag() fires a deduped $feature_flag_called event the
     * first time each key is touched.
     *
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @param bool $disableGeoip Whether to disable GeoIP enrichment during remote evaluation.
     * @param list<string>|null $flagKeys Optional list of flag keys. When provided, only these
     *     flags are evaluated — the underlying /flags request asks the server for just this
     *     subset, which makes the response smaller and the request cheaper. Use this when you
     *     only need a handful of flags out of many. Distinct from FeatureFlagEvaluations::only(),
     *     which scopes which already-evaluated flags get attached to a captured event.
     * @return FeatureFlagEvaluations
     */
    public function evaluateFlags(
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        bool $disableGeoip = false,
        ?array $flagKeys = null
    ): FeatureFlagEvaluations {
        $distinctId = $this->resolveDistinctId($distinctId);
        if ($distinctId === '') {
            $this->warnMissingDistinctId('evaluateFlags()');
            return new FeatureFlagEvaluations(
                $distinctId,
                [],
                $groups,
                $this,
            );
        }

        [$personProperties, $groupProperties] = $this->addLocalPersonAndGroupProperties(
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties
        );

        $records = [];
        $requestId = null;
        $evaluatedAt = null;
        $errorsWhileComputing = false;
        $quotaLimited = false;
        $fallbackToRemote = false;

        // Local pass: try to resolve any flag we can without going to the server. Track whether
        // any flag was inconclusive (which forces a remote round trip) so we can skip /flags
        // entirely when local evaluation covered everything we know about.
        $hasLocalDefinitions = count($this->featureFlags) > 0;
        if ($hasLocalDefinitions) {
            $localKeys = [];
            foreach ($this->featureFlags as $flag) {
                $key = $flag['key'] ?? null;
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $localKeys[$key] = true;

                if ($flagKeys !== null && !in_array($key, $flagKeys, true)) {
                    continue;
                }

                try {
                    $value = $this->computeFlagLocally(
                        $flag,
                        $distinctId,
                        $groups,
                        $personProperties,
                        $groupProperties
                    );
                } catch (RequiresServerEvaluationException $e) {
                    $fallbackToRemote = true;
                    continue;
                } catch (InconclusiveMatchException $e) {
                    $fallbackToRemote = true;
                    continue;
                } catch (Exception $e) {
                    $fallbackToRemote = true;
                    error_log("[PostHog][Client] Error while computing variant: " . $e->getMessage());
                    continue;
                }

                $variant = is_string($value) ? $value : null;
                $enabled = is_string($value) ? true : (bool) $value;
                $id = isset($flag['id']) ? (int) $flag['id'] : null;

                $records[$key] = new EvaluatedFlagRecord(
                    key: $key,
                    enabled: $enabled,
                    variant: $variant,
                    payload: null,
                    id: $id,
                    version: null,
                    reason: 'Evaluated locally',
                    locallyEvaluated: true,
                );
            }

            // If the caller asked for keys we don't have local definitions for, hit /flags so
            // we can resolve them.
            if ($flagKeys !== null) {
                foreach ($flagKeys as $requestedKey) {
                    if (!isset($localKeys[$requestedKey])) {
                        $fallbackToRemote = true;
                        break;
                    }
                }
            }
        } else {
            // No local definitions loaded — every flag has to come from the server.
            $fallbackToRemote = true;
        }

        $shouldHitRemote = !$onlyEvaluateLocally && $fallbackToRemote;

        if ($shouldHitRemote) {
            try {
                $response = $this->requestFlags(
                    $distinctId,
                    $groups,
                    $personProperties,
                    $groupProperties,
                    $disableGeoip,
                    $flagKeys
                );

                $requestId = $response['requestId'] ?? null;
                $evaluatedAt = isset($response['evaluatedAt']) && is_int($response['evaluatedAt'])
                    ? $response['evaluatedAt']
                    : null;
                $errorsWhileComputing = (bool) ($response['errorsWhileComputingFlags'] ?? false);
                $remoteFlags = $response['flags'] ?? [];

                foreach ($remoteFlags as $key => $flagDetail) {
                    if (!is_string($key) || $key === '' || isset($records[$key])) {
                        continue;
                    }
                    if (!is_array($flagDetail) || ($flagDetail['failed'] ?? false)) {
                        continue;
                    }

                    $variant = $flagDetail['variant'] ?? null;
                    $enabled = (bool) ($flagDetail['enabled'] ?? false);
                    // Payloads come down as JSON strings, but defensively handle pre-decoded
                    // values too (some clients/middleware may deserialize transparently).
                    $rawPayload = $flagDetail['metadata']['payload'] ?? null;
                    if ($rawPayload === null) {
                        $payload = null;
                    } elseif (is_string($rawPayload)) {
                        $payload = json_decode($rawPayload, true);
                    } else {
                        $payload = $rawPayload;
                    }

                    $records[$key] = new EvaluatedFlagRecord(
                        key: $key,
                        enabled: $enabled,
                        variant: is_string($variant) ? $variant : null,
                        payload: $payload,
                        id: isset($flagDetail['metadata']['id'])
                            ? (int) $flagDetail['metadata']['id']
                            : null,
                        version: isset($flagDetail['metadata']['version'])
                            ? (int) $flagDetail['metadata']['version']
                            : null,
                        reason: $flagDetail['reason']['description'] ?? null,
                        locallyEvaluated: false,
                    );
                }
            } catch (HttpException $e) {
                if ($e->getErrorType() === HttpException::QUOTA_LIMITED) {
                    $quotaLimited = true;
                }
                error_log("[PostHog][Client] Unable to evaluate flags: " . $e->getMessage());
            } catch (Exception $e) {
                error_log("[PostHog][Client] Unable to evaluate flags: " . $e->getMessage());
            }
        }

        return new FeatureFlagEvaluations(
            $distinctId,
            $records,
            $groups,
            $this,
            $requestId,
            $evaluatedAt,
            null,
            $errorsWhileComputing,
            $quotaLimited,
        );
    }

    /**
     * Fire a $feature_flag_called event the first time a (flag key, distinct id, response, groups)
     * tuple is seen by this Client, deduped via the per-distinct_id cache shared with every other
     * flag-reading code path. Group context is included so that group-scoped flags fire a separate
     * event for each group a user is evaluated under. Properties are built by the caller so each
     * call site can shape the payload to match its available metadata.
     *
     * @param string $distinctId The distinct ID that accessed the flag.
     * @param string $key Feature flag key.
     * @param mixed $response Evaluated feature flag response for deduping.
     * @param array<string, mixed> $properties Event properties for the $feature_flag_called event.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @return void
     */
    public function captureFlagCalledIfNeeded(
        string $distinctId,
        string $key,
        $response,
        array $properties,
        array $groups = []
    ): void {
        $dedupElement = $distinctId . self::featureFlagResponseCacheKey($response) . self::canonicalGroupsRepr($groups);
        if ($this->distinctIdsFeatureFlagsReported->contains($key, $dedupElement)) {
            return;
        }

        $this->capture([
            'properties' => $properties,
            'distinct_id' => $distinctId,
            'event' => '$feature_flag_called',
            '$groups' => $groups,
        ]);
        $this->distinctIdsFeatureFlagsReported->add($key, $dedupElement);
    }

    /**
     * Build a stable cache fragment for the evaluated response so true, false, null, and variants
     * dedupe independently for the same distinct id and flag key.
     *
     * @param mixed $response
     * @return string
     */
    private static function featureFlagResponseCacheKey($response): string
    {
        return '|' . json_encode($response, JSON_THROW_ON_ERROR);
    }

    /**
     * Canonicalize the groups map so two equal arrays with keys in a different order produce the
     * same dedup suffix. Empty / missing groups produce an empty string so the legacy "no groups"
     * dedupe shape is preserved.
     *
     * @param array<string, mixed> $groups
     * @return string
     */
    private static function canonicalGroupsRepr(array $groups): string
    {
        if (empty($groups)) {
            return '';
        }
        ksort($groups);
        return '_' . json_encode($groups, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Emit a non-fatal SDK warning.
     *
     * @param string $message Warning message without the SDK prefix.
     * @return void
     */
    public function logWarning(string $message): void
    {
        error_log("[PostHog][Client] " . $message);
    }

    private function computeFlagLocally(
        array $featureFlag,
        string $distinctId,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array()
    ): bool | string {
        // Create evaluation cache for flag dependencies
        $evaluationCache = [];

        if ($featureFlag["ensure_experience_continuity"] ?? false) {
            throw new InconclusiveMatchException("Flag has experience continuity enabled");
        }

        if (!$featureFlag["active"]) {
            return false;
        }

        $flagFilters = $featureFlag["filters"] ?? [];
        $aggregationGroupTypeIndex = $flagFilters["aggregation_group_type_index"] ?? null;

        if (!is_null($aggregationGroupTypeIndex)) {
            $groupName = $this->groupTypeMapping[strval($aggregationGroupTypeIndex)] ?? null;

            if (is_null($groupName)) {
                throw new InconclusiveMatchException("Flag has unknown group type index");
            }

            if (!array_key_exists($groupName, $groups)) {
                return false;
            }

            $focusedGroupProperties = $groupProperties[$groupName];
            return FeatureFlag::matchFeatureFlagProperties(
                $featureFlag,
                $groups[$groupName],
                $focusedGroupProperties,
                $this->cohorts,
                $this->featureFlagsByKey,
                $evaluationCache,
                $groups,
                $groupProperties,
                $this->groupTypeMapping
            );
        } else {
            return FeatureFlag::matchFeatureFlagProperties(
                $featureFlag,
                $distinctId,
                $personProperties,
                $this->cohorts,
                $this->featureFlagsByKey,
                $evaluationCache,
                $groups,
                $groupProperties,
                $this->groupTypeMapping
            );
        }
    }


    /**
     * Fetch all feature flag variants for a distinct id from the remote /flags endpoint.
     *
     * @param string $distinctId The user's distinct ID.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, mixed> $personProperties Person properties to use for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties to use for flag evaluation.
     * @return array<string, bool|string> Feature flag values by key.
     */
    public function fetchFeatureVariants(
        string $distinctId,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = []
    ): array {
        $response = $this->fetchFlagsResponse($distinctId, $groups, $personProperties, $groupProperties);
        return $response['featureFlags'] ?? [];
    }

    /**
     * @param string $distinctId
     * @param array<string, mixed> $groups
     * @param array<string, mixed> $personProperties
     * @param array<string, array<string, mixed>> $groupProperties
     * @return array<string, mixed> Feature flags response.
     */
    private function fetchFlagsResponse(
        string $distinctId,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = []
    ): array {
        return $this->flags($distinctId, $groups, $personProperties, $groupProperties);
    }

    /**
     * Load local feature flag definitions using the configured personal API key.
     *
     * @return void
     * @throws Exception
     */
    public function loadFlags()
    {
        if (!$this->enabled) {
            return;
        }

        $shouldFetch = $this->shouldFetchFlagDefinitionsFromApi();

        if (!$shouldFetch && $this->flagDefinitionCacheProvider !== null) {
            try {
                $cachedData = $this->flagDefinitionCacheProvider->getFlagDefinitions();
                if ($cachedData !== null) {
                    $normalizedData = $this->normalizeFlagDefinitionCacheData($cachedData);
                    if ($normalizedData !== null) {
                        $this->applyFlagDefinitions($normalizedData);
                        if ($this->debug) {
                            error_log("[PostHog][Client] Using cached flag definitions from external cache");
                        }
                        return;
                    }

                    $this->logFlagDefinitionCacheWarning('Cache provider returned malformed flag definitions');
                    if ($this->hasFlagDefinitionsLoaded()) {
                        return;
                    }
                } elseif ($this->hasFlagDefinitionsLoaded()) {
                    return;
                }

                $shouldFetch = !is_null($this->personalAPIKey);
            } catch (Throwable $throwable) {
                $this->logFlagDefinitionCacheWarning(
                    'Cache provider read error: ' . $throwable->getMessage()
                );
                if ($this->hasFlagDefinitionsLoaded()) {
                    return;
                }
                $shouldFetch = !is_null($this->personalAPIKey);
            }
        }

        if ($shouldFetch) {
            $this->fetchAndApplyFlagDefinitionsFromApi();
        }
    }

    /**
     * Decide whether to fetch flag definitions directly from PostHog.
     *
     * @return bool
     */
    private function shouldFetchFlagDefinitionsFromApi(): bool
    {
        if ($this->flagDefinitionCacheProvider === null) {
            return true;
        }

        try {
            return $this->flagDefinitionCacheProvider->shouldFetchFlagDefinitions();
        } catch (Throwable $throwable) {
            $this->logFlagDefinitionCacheWarning(
                'Cache provider fetch-decision error: ' . $throwable->getMessage()
            );
            return !is_null($this->personalAPIKey);
        }
    }

    /**
     * Fetch, apply, and optionally store flag definitions from PostHog.
     *
     * @return void
     * @throws Exception
     */
    private function fetchAndApplyFlagDefinitionsFromApi(): void
    {
        $response = $this->localFlags();

        // Handle 304 Not Modified - flags haven't changed, skip processing.
        // On 304, we preserve the existing ETag unless the server sends a new one.
        // This handles edge cases like server restarts where the server may send
        // a refreshed ETag even though the content hasn't changed.
        if ($response->isNotModified()) {
            if ($response->getEtag()) {
                $this->flagsEtag = $response->getEtag();
            }
            if ($this->debug) {
                error_log("[PostHog][Client] Flags not modified (304), using cached data");
            }
            return;
        }

        $responseCode = $response->getResponseCode();
        if ($responseCode !== 200) {
            error_log(
                "[PostHog][Client] Failed to load feature flags (HTTP $responseCode): "
                . $response->getResponse()
            );
            return;
        }

        $payload = json_decode($response->getResponse(), true);

        if ($payload && array_key_exists("detail", $payload)) {
            throw new Exception($payload["detail"]);
        }

        if (!is_array($payload)) {
            error_log("[PostHog][Client] Failed to load feature flags: invalid JSON response");
            return;
        }

        // On 200 responses, always update ETag (even if null) since we're replacing
        // the cached flag data. A null ETag means the server doesn't support caching.
        $this->flagsEtag = $response->getEtag();

        $data = $this->normalizeFlagDefinitionData($payload);
        $this->applyFlagDefinitions($data);
        $this->storeFlagDefinitionsInCacheProvider($data);
    }

    /**
     * Normalize API flag definition data using SDK defaults for optional fields.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeFlagDefinitionData(array $data): array
    {
        return [
            'flags' => isset($data['flags']) && is_array($data['flags']) ? $data['flags'] : [],
            'group_type_mapping' => isset($data['group_type_mapping']) && is_array($data['group_type_mapping'])
                ? $data['group_type_mapping']
                : [],
            'cohorts' => isset($data['cohorts']) && is_array($data['cohorts']) ? $data['cohorts'] : [],
        ];
    }

    /**
     * Validate cached flag definition data from an external cache provider.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function normalizeFlagDefinitionCacheData(array $data): ?array
    {
        if (!array_key_exists('flags', $data) || !is_array($data['flags'])) {
            return null;
        }

        $hasSnakeCaseGroupTypeMapping = array_key_exists('group_type_mapping', $data);
        $hasCamelCaseGroupTypeMapping = array_key_exists('groupTypeMapping', $data);
        if (!$hasSnakeCaseGroupTypeMapping && !$hasCamelCaseGroupTypeMapping) {
            return null;
        }

        $groupTypeMapping = $hasSnakeCaseGroupTypeMapping
            ? $data['group_type_mapping']
            : $data['groupTypeMapping'];
        if (!is_array($groupTypeMapping)) {
            return null;
        }

        if (!array_key_exists('cohorts', $data) || !is_array($data['cohorts'])) {
            return null;
        }

        return [
            'flags' => $data['flags'],
            'group_type_mapping' => $groupTypeMapping,
            'cohorts' => $data['cohorts'],
        ];
    }

    /**
     * Apply flag definitions to in-memory state.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function applyFlagDefinitions(array $data): void
    {
        $this->featureFlags = $data['flags'];
        $this->groupTypeMapping = $data['group_type_mapping'];
        $this->cohorts = $data['cohorts'];

        // Build flags by key dictionary for dependency resolution
        $this->featureFlagsByKey = [];
        foreach ($this->featureFlags as $flag) {
            if (is_array($flag) && array_key_exists('key', $flag)) {
                $this->featureFlagsByKey[$flag['key']] = $flag;
            }
        }

        $this->flagDefinitionsLoaded = true;
    }

    /**
     * Store fetched flag definitions in the configured external cache provider.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function storeFlagDefinitionsInCacheProvider(array $data): void
    {
        if ($this->flagDefinitionCacheProvider === null) {
            return;
        }

        try {
            $this->flagDefinitionCacheProvider->onFlagDefinitionsReceived($data);
        } catch (Throwable $throwable) {
            $this->logFlagDefinitionCacheWarning(
                'Cache provider store error: ' . $throwable->getMessage()
            );
        }
    }

    /**
     * Whether this client has successfully loaded a complete flag definition set.
     *
     * @return bool
     */
    private function hasFlagDefinitionsLoaded(): bool
    {
        return $this->flagDefinitionsLoaded;
    }

    /**
     * Release resources held by the configured external cache provider.
     *
     * @return void
     */
    private function shutdownFlagDefinitionCacheProvider(): void
    {
        if ($this->flagDefinitionCacheProvider === null || $this->flagDefinitionCacheProviderShutdown) {
            return;
        }

        try {
            $this->flagDefinitionCacheProvider->shutdown();
        } catch (Throwable $throwable) {
            $this->logFlagDefinitionCacheWarning(
                'Cache provider shutdown error: ' . $throwable->getMessage()
            );
        } finally {
            $this->flagDefinitionCacheProviderShutdown = true;
        }
    }

    /**
     * Emit a non-fatal warning for flag definition cache provider failures.
     *
     * @param string $message Warning message without the SDK prefix.
     * @return void
     */
    private function logFlagDefinitionCacheWarning(string $message): void
    {
        $this->logWarning('Flag definition cache warning: ' . $message);
    }

    /**
     * Fetch local feature flag definitions from the PostHog API.
     *
     * @return HttpResponse Raw HTTP response, including ETag metadata when available.
     */
    public function localFlags(): HttpResponse
    {
        if (!$this->enabled) {
            return new HttpResponse(
                json_encode([
                    'flags' => [],
                    'group_type_mapping' => [],
                    'cohorts' => [],
                ]),
                200
            );
        }

        $headers = [
            // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
            "User-Agent: " . PostHog::LIBRARY . "/" . PostHog::VERSION,
            "Authorization: Bearer " . $this->personalAPIKey
        ];

        // Add If-None-Match header if we have a cached ETag
        if ($this->flagsEtag !== null) {
            $headers[] = "If-None-Match: " . $this->flagsEtag;
        }

        return $this->httpClient->sendRequest(
            '/flags/definitions?send_cohorts&token=' . $this->apiKey,
            null,
            $headers,
            [
                'includeEtag' => true
            ]
        );
    }

    /**
     * Get the current cached ETag for feature flag definitions
     *
     * @return string|null
     */
    public function getFlagsEtag(): ?string
    {
        return $this->flagsEtag;
    }

    /**
     * Normalize feature flags response to ensure consistent format.
     * Decodes JSON, checks for quota limits, and transforms v4 to v3 format.
     *
     * @param string $response The raw JSON response
     * @return array The normalized response
     * @throws HttpException On invalid JSON or quota limit
     */
    private function normalizeFeatureFlags(string $response): array
    {
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new HttpException(
                HttpException::API_ERROR,
                0,
                "Invalid JSON response"
            );
        }

        // Check for quota limit in response body
        if (
            isset($decoded['quotaLimited'])
            && is_array($decoded['quotaLimited'])
            && in_array('feature_flags', $decoded['quotaLimited'])
        ) {
            throw new HttpException(
                HttpException::QUOTA_LIMITED,
                0,
                "Feature flags quota limited"
            );
        }

        if (isset($decoded['flags']) && !empty($decoded['flags'])) {
            // This is a v4 response, we need to transform it to a v3 response for backwards compatibility
            $transformedFlags = [];
            $transformedPayloads = [];
            foreach ($decoded['flags'] as $key => $flag) {
                // Skip flags that failed evaluation to avoid overwriting cached values
                if (isset($flag['failed']) && $flag['failed']) {
                    continue;
                }
                if ($flag['variant'] !== null) {
                    $transformedFlags[$key] = $flag['variant'];
                } else {
                    $transformedFlags[$key] = $flag['enabled'] ?? false;
                }
                if (isset($flag['metadata']['payload'])) {
                    $transformedPayloads[$key] = $flag['metadata']['payload'];
                }
            }
            $decoded['featureFlags'] = $transformedFlags;
            $decoded['featureFlagPayloads'] = $transformedPayloads;
        }

        return $decoded;
    }

    /**
     * Fetch feature flags from the PostHog API.
     *
     * @param string $distinctId The user's distinct ID.
     * @param array<string, mixed> $groups Group identifiers.
     * @param array<string, mixed> $personProperties Person properties for flag evaluation.
     * @param array<string, array<string, mixed>> $groupProperties Group properties for flag evaluation.
     * @param bool $disableGeoip Whether to disable GeoIP enrichment during remote evaluation.
     * @param list<string>|null $flagKeys Optional list of flag keys to evaluate.
     * @return array<string, mixed> The normalized feature flags response.
     */
    public function flags(
        string $distinctId,
        array $groups = array(),
        array $personProperties = [],
        array $groupProperties = [],
        bool $disableGeoip = false,
        ?array $flagKeys = null
    ): array {
        try {
            return $this->requestFlags(
                $distinctId,
                $groups,
                $personProperties,
                $groupProperties,
                $disableGeoip,
                $flagKeys
            );
        } catch (HttpException $e) {
            error_log('[PostHog][Client] Unable to fetch feature flags: ' . $e->getMessage());
            return $this->emptyFlagsResponse();
        }
    }

    /**
     * @return array<string, mixed>
     * @throws HttpException On network errors, API errors, or quota limits.
     */
    private function requestFlags(
        string $distinctId,
        array $groups = array(),
        array $personProperties = [],
        array $groupProperties = [],
        bool $disableGeoip = false,
        ?array $flagKeys = null
    ): array {
        if (!$this->enabled) {
            return $this->emptyFlagsResponse();
        }

        $payload = array(
            'token' => $this->apiKey,
            'distinct_id' => $distinctId,
            'groups' => empty($groups) ? (object) [] : $groups,
            'person_properties' => empty($personProperties) ? (object) [] : $personProperties,
            'group_properties' => empty($groupProperties) ? (object) [] : $groupProperties,
            'geoip_disable' => $disableGeoip,
        );

        if ($flagKeys !== null) {
            $payload["flag_keys_to_evaluate"] = array_values($flagKeys);
        }

        $httpResponse = $this->sendFeatureFlagsRequest($payload);

        $responseCode = $httpResponse->getResponseCode();
        $curlErrno = $httpResponse->getCurlErrno();

        if ($responseCode === 0) {
            // CURLE_OPERATION_TIMEDOUT (28)
            // https://curl.se/libcurl/c/libcurl-errors.html
            if ($curlErrno === 28) {
                throw new HttpException(
                    HttpException::TIMEOUT,
                    0,
                    "Request timed out"
                );
            }
            // Consider everything else a connection error
            // CURLE_COULDNT_RESOLVE_HOST (6)
            // CURLE_COULDNT_CONNECT (7)
            // CURLE_WEIRD_SERVER_REPLY (8)
            // etc.
            throw new HttpException(
                HttpException::CONNECTION_ERROR,
                0,
                "Connection error (curl errno: {$curlErrno})"
            );
        }

        if ($responseCode >= 400) {
            throw new HttpException(
                HttpException::API_ERROR,
                $responseCode,
                "API error: HTTP {$responseCode}"
            );
        }

        return $this->normalizeFeatureFlags($httpResponse->getResponse());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendFeatureFlagsRequest(array $payload): HttpResponse
    {
        $backoff = 100; // Set initial waiting time to 100ms
        $requestPayload = json_encode($payload);
        $retries = 0;

        while (true) {
            $httpResponse = $this->httpClient->sendRequest(
                '/flags/?v=2',
                $requestPayload,
                [
                    // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
                    "User-Agent: " . PostHog::LIBRARY . "/" . PostHog::VERSION,
                ],
                [
                    "shouldRetry" => false,
                    "timeout" => $this->featureFlagsRequestTimeout
                ]
            );

            if (
                $httpResponse->getResponseCode() !== 0
                || $retries >= $this->featureFlagRequestMaxRetries
                || !$this->isRetryableFlagsCurlError($httpResponse->getCurlErrno())
            ) {
                return $httpResponse;
            }

            $retries++;
            usleep(min($backoff, $this->maximumBackoffDurationMs) * 1000);
            $backoff = min($backoff * 2, $this->maximumBackoffDurationMs);
        }
    }

    private function isRetryableFlagsCurlError(int $curlErrno): bool
    {
        // Match Ruby's transient subset: timeouts, connection resets/receive failures,
        // and empty replies/EOF. Do not retry refused connections or DNS failures.
        return in_array($curlErrno, [28, 52, 56], true);
    }

    /** @return array{featureFlags: array<string, mixed>, featureFlagPayloads: array<string, mixed>, flags: array<string, mixed>} */
    private function emptyFlagsResponse(): array
    {
        return [
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ];
    }

    /**
     * Aliases from one user id to another.
     *
     * @param array{
     *     distinctId?: string,
     *     distinct_id?: string,
     *     alias: string,
     *     properties?: array<string, mixed>
     * } $message
     * @return bool Whether the alias call succeeded.
     */
    public function alias(array $message)
    {
        $message = $this->message($message);
        $message["event"] = '$create_alias';
        unset($message["send_feature_flags"]);

        $message['properties']['distinct_id'] = $message['distinct_id'];
        $message['properties']['alias'] = $message['alias'];

        $message['distinct_id'] = null;
        unset($message['alias']);

        return $this->consumer->alias($message);
    }

    /**
     * Queue a raw, already-prepared message.
     *
     * @param array<string, mixed> $message Prepared message payload. If a top-level `uuid` is supplied
     *     it must be a valid UUID; invalid values are replaced with a generated UUID v4.
     * @return mixed Whether the underlying consumer accepted the message.
     */
    public function raw(array $message)
    {
        return $this->consumer->enqueue($this->normalizeMessageUuid($message));
    }

    /**
     * Flush any async consumers.
     *
     * @return bool True if flushed successfully.
     */
    public function flush()
    {
        if (method_exists($this->consumer, 'flush')) {
            return $this->consumer->flush();
        }

        return true;
    }

    /**
     * Formats a timestamp by making sure it is set
     * and converting it to iso8601.
     *
     * The timestamp can be time in seconds `time()` or `microseconds(true)`.
     * any other input is considered an error and the method will return a new date.
     *
     * Note: php's date() "u" format (for microseconds) has a bug in it
     * it always shows `.000` for microseconds since `date()` only accepts
     * ints, so we have to construct the date ourselves if microtime is passed.
     *
     * @param $ts
     * @return false|string
     */
    private function formatTime($ts)
    {
        // time()
        if (null == $ts || !$ts) {
            $ts = Clock::get()->now()->getTimestamp();
        }
        if (false !== filter_var($ts, FILTER_VALIDATE_INT)) {
            return date("c", (int)$ts);
        }

        // anything else try to strtotime the date.
        if (false === filter_var($ts, FILTER_VALIDATE_FLOAT)) {
            if (is_string($ts)) {
                return date("c", strtotime($ts));
            }

            return date("c", Clock::get()->now()->getTimestamp());
        }

        // fix for floatval casting in send.php
        $parts = explode(".", (string)$ts);
        if (!isset($parts[1])) {
            return date("c", (int)$parts[0]);
        }

        // microtime(true)
        $sec = (int)$parts[0];
        $usec = (int)$parts[1];
        $fmt = sprintf("Y-m-d\\TH:i:s%sP", $usec);

        return date($fmt, (int)$sec);
    }

    /**
     * Run a callback with request context applied to all captures in the callback.
     *
     * @param array{
     *     distinctId?: string,
     *     distinct_id?: string,
     *     sessionId?: string,
     *     session_id?: string,
     *     properties?: array<string, mixed>
     * } $data
     * @param callable $fn Callback to run while the context is active.
     * @param array{fresh?: bool} $options Use `fresh => true` to avoid inheriting the current context.
     * @return mixed
     * @throws \Throwable Re-throws any exception thrown by $fn after restoring context.
     */
    public function withContext(array $data, callable $fn, array $options = []): mixed
    {
        return RequestContext::withContext($data, $fn, $options, $this->contextKey());
    }

    /**
     * Get the currently active request context for this client, if any.
     *
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}|null
     */
    public function getContext(): ?array
    {
        return RequestContext::getContext($this->contextKey());
    }

    /**
     * Extract PostHog frontend tracing context from HTTP headers.
     *
     * @param array<string, mixed> $headers HTTP headers, including $_SERVER-style HTTP_* keys.
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}
     */
    public function contextFromHeaders(array $headers): array
    {
        return RequestContext::contextFromHeaders($headers);
    }

    private function contextKey(): int
    {
        return spl_object_id($this);
    }

    private function resolveDistinctId(?string $distinctId): string
    {
        if ($distinctId !== null && $distinctId !== '') {
            return $distinctId;
        }

        return RequestContext::getDistinctId($this->contextKey()) ?? '';
    }

    private function warnMissingDistinctId(string $operation): void
    {
        if (isset($this->missingDistinctIdWarnings[$operation])) {
            return;
        }

        $this->missingDistinctIdWarnings[$operation] = true;
        $this->logWarning(
            "$operation requires distinctId — pass it explicitly or use withContext()."
        );
    }

    private function shouldApplyCaptureContext(array $msg): bool
    {
        return ($msg['event'] ?? null) !== '$groupidentify';
    }

    /**
     * Apply request context to capture-like events only. Identification, alias,
     * and group identify calls must keep explicit identity/properties to avoid
     * accidentally mutating the wrong entity from ambient request state.
     *
     * @param array $msg
     * @return array
     */
    private function applyCaptureContext(array $msg, bool &$usedGeneratedPersonlessDistinctId): array
    {
        if (!isset($msg["properties"]) || !is_array($msg["properties"])) {
            $msg["properties"] = array();
        }

        $explicitDistinctId = $this->hasExplicitCaptureDistinctId($msg);

        $context = RequestContext::getContext($this->contextKey());
        $msg["properties"] = array_merge($context['properties'] ?? [], $msg["properties"]);

        if (
            isset($context['sessionId'])
            && !array_key_exists('$session_id', $msg["properties"])
        ) {
            $msg["properties"]['$session_id'] = $context['sessionId'];
        }

        if (!$explicitDistinctId && isset($context['distinctId']) && (string) $context['distinctId'] !== '') {
            $msg["distinct_id"] = $context['distinctId'];
            $explicitDistinctId = true;
        }

        if (!$explicitDistinctId) {
            $msg["distinct_id"] = Uuid::v4();
            $usedGeneratedPersonlessDistinctId = true;
            if (!array_key_exists('$process_person_profile', $msg["properties"])) {
                $msg["properties"]['$process_person_profile'] = false;
            }
        }

        return $msg;
    }

    private function hasExplicitCaptureDistinctId(array &$msg): bool
    {
        if (array_key_exists("distinctId", $msg)) {
            if (is_scalar($msg["distinctId"]) && (string) $msg["distinctId"] !== '') {
                return true;
            }

            unset($msg["distinctId"]);
        }

        if (array_key_exists("distinct_id", $msg)) {
            if (is_scalar($msg["distinct_id"]) && (string) $msg["distinct_id"] !== '') {
                return true;
            }

            unset($msg["distinct_id"]);
        }

        return false;
    }

    private function normalizeMessageUuid(array $msg): array
    {
        if (!array_key_exists('uuid', $msg) || !$this->isValidUuid($msg['uuid'])) {
            $msg['uuid'] = Uuid::v4();
        }

        return $msg;
    }

    private function isValidUuid(mixed $uuid): bool
    {
        if (!is_string($uuid)) {
            return false;
        }

        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        ) === 1;
    }

    /**
     * Add common fields to the given `message`
     *
     * @param array $msg
     * @return array
     */
    private function message($msg)
    {
        if (!isset($msg["properties"]) || !is_array($msg["properties"])) {
            $msg["properties"] = array();
        }

        $legacyLibrary = $msg["library"] ?? null;
        $legacyLibraryVersion = $msg["library_version"] ?? null;
        $legacyLibraryConsumer = $msg["library_consumer"] ?? null;

        unset($msg["type"], $msg["library"], $msg["library_version"], $msg["library_consumer"]);

        if (!array_key_exists('$lib', $msg["properties"])) {
            $msg["properties"]['$lib'] = is_scalar($legacyLibrary) && (string) $legacyLibrary !== ''
                ? (string) $legacyLibrary
                : PostHog::LIBRARY;
        }

        if (!array_key_exists('$lib_version', $msg["properties"])) {
            $msg["properties"]['$lib_version'] = is_scalar($legacyLibraryVersion) && (string) $legacyLibraryVersion !== ''
                ? (string) $legacyLibraryVersion
                : PostHog::VERSION;
        }

        if (!array_key_exists('$lib_consumer', $msg["properties"])) {
            $msg["properties"]['$lib_consumer'] = is_scalar($legacyLibraryConsumer) && (string) $legacyLibraryConsumer !== ''
                ? (string) $legacyLibraryConsumer
                : $this->consumer->getConsumer();
        }

        // When running as a server SDK (the default), tag events as server-side so
        // PostHog does not attribute the host machine's device/OS to the event.
        // Set the `is_server` option to false when using posthog-php as a
        // client/CLI so the device OS is attributed normally.
        if (($this->options['is_server'] ?? true) === true) {
            $msg["properties"]['$is_server'] = true;
        }

        if (isset($msg["distinctId"])) {
            $msg["distinct_id"] = $msg["distinctId"];
            unset($msg["distinctId"]);
        }

        if (isset($msg["sendFeatureFlags"])) {
            $msg["send_feature_flags"] = $msg["sendFeatureFlags"];
            unset($msg["sendFeatureFlags"]);
        }

        if (!isset($msg["groups"])) {
            $msg["groups"] = [];
        }

        if (!isset($msg["timestamp"])) {
            $msg["timestamp"] = null;
        }
        $msg["timestamp"] = $this->formatTime($msg["timestamp"]);

        return $msg;
    }

    private function addLocalPersonAndGroupProperties(
        string $distinctId,
        array $groups,
        array $personProperties,
        array $groupProperties
    ): array {
        $allPersonProperties = array_merge(
            ["distinct_id" => $distinctId],
            $personProperties
        );

        $allGroupProperties = [];
        if (count($groups) > 0) {
            foreach ($groups as $groupName => $groupValue) {
                $allGroupProperties[$groupName] = array_merge(
                    ["\$group_key" => $groupValue],
                    $groupProperties[$groupName] ?? []
                );
            }
        }

        return [$allPersonProperties, $allGroupProperties];
    }
}
