<?php

namespace PostHog;

use Exception;
use Psr\SimpleCache\CacheInterface;

/**
 * Static facade for the default PostHog PHP SDK client.
 */
class PostHog
{
    public const VERSION = '4.5.0';
    public const ENV_API_KEY = "POSTHOG_API_KEY";
    public const ENV_HOST = "POSTHOG_HOST";

    private static ?Client $client = null;

    /**
     * Initializes the default client to use. Uses the libcurl consumer by default.
     *
     * When $apiKey is omitted or blank, POSTHOG_API_KEY is used when present. When no
     * non-empty API key can be resolved, a disabled no-op client is initialized. When the
     * host option is omitted, POSTHOG_HOST is used when present.
     *
     * @param string|null $apiKey Your project API key.
     * @param array{
     *     host?: string,
     *     ssl?: bool,
     *     timeout?: int,
     *     verify_batch_events_request?: bool,
     *     feature_flag_request_timeout_ms?: int,
     *     feature_flags_cache?: CacheInterface,
     *     feature_flags_cache_ttl?: int,
     *     feature_flags_cache_stale_ttl?: int,
     *     maximum_backoff_duration?: int,
     *     consumer?: 'socket'|'file'|'fork_curl'|'lib_curl'|'noop',
     *     debug?: bool,
     *     max_queue_size?: int,
     *     batch_size?: int,
     *     compress_request?: bool|string,
     *     error_handler?: callable,
     *     filename?: string,
     *     error_tracking?: array{
     *         enabled?: bool,
     *         capture_errors?: bool,
     *         excluded_exceptions?: list<class-string>,
     *         max_frames?: int,
     *         context_provider?: callable
     *     }
     * }|null $options Client and consumer configuration options.
     * @param Client|null $client Preconfigured client instance. When provided, $apiKey, $options,
     *     and $personalAPIKey are ignored.
     * @param string|null $personalAPIKey Personal API key used to load local feature flag definitions.
     * @return void
     */
    public static function init(
        ?string $apiKey = null,
        ?array $options = [],
        ?Client $client = null,
        ?string $personalAPIKey = null
    ): void {
        if (null === $client) {
            $options = $options ?? [];
            $apiKey = StringNormalizer::normalizeOptional($apiKey);
            if ($apiKey === null) {
                $envApiKey = getenv(self::ENV_API_KEY);
                $apiKey = $envApiKey === false ? null : StringNormalizer::normalizeOptional($envApiKey);
            }

            $rawHost = null;
            if (array_key_exists("host", $options)) {
                $rawHost = $options["host"];
                $options["host"] = self::cleanHost($rawHost);
            } else {
                $envHost = getenv(self::ENV_HOST) ?: null;
                if (null !== $envHost) {
                    $rawHost = $envHost;
                    $options["host"] = self::cleanHost($rawHost);
                }
            }

            // Infer ssl from the host protocol if the user hasn't explicitly set it
            if ($rawHost !== null && !array_key_exists("ssl", $options)) {
                $normalizedHost = StringNormalizer::normalizeHost($rawHost);
                if (str_starts_with($normalizedHost, "http://")) {
                    $options["ssl"] = false;
                } elseif (str_starts_with($normalizedHost, "https://")) {
                    $options["ssl"] = true;
                }
            }

            self::$client = new Client($apiKey, $options, null, $personalAPIKey);
        } else {
            self::$client = $client;
        }
    }

    /**
     * Captures an exception as a PostHog error tracking event.
     *
     * @param \Throwable|string $exception The exception to capture or a plain string message.
     * @param string|null $distinctId User ID; a random UUID is used when omitted (no person profile created).
     * @param array<string, mixed> $additionalProperties Extra properties merged into the event.
     * @return bool Whether the capture call succeeded.
     * @throws Exception
     */
    public static function captureException(
        \Throwable|string $exception,
        ?string $distinctId = null,
        array $additionalProperties = []
    ): bool {
        self::checkClient();
        return self::$client->captureException($exception, $distinctId, $additionalProperties);
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
     *     flags?: FeatureFlagEvaluations,
     *     send_feature_flags?: bool,
     *     sendFeatureFlags?: bool
     * } $message Event payload. `send_feature_flags` and `sendFeatureFlags` are deprecated; pass
     *     a `flags` snapshot from evaluateFlags() instead.
     * @return bool Whether the capture call succeeded.
     * @throws Exception
     */
    public static function capture(array $message)
    {
        self::checkClient();
        $event = !empty($message["event"]);
        self::assert($event, "PostHog::capture() expects an event");

        return self::$client->capture($message);
    }

    /**
     * Tags properties about the user.
     *
     * @param array{distinctId?: string, distinct_id?: string, properties?: array<string, mixed>} $message
     * @return bool Whether the identify call succeeded.
     * @throws Exception
     */
    public static function identify(array $message)
    {
        self::checkClient();
        $message["type"] = "identify";
        self::validate($message, "identify");

        return self::$client->identify($message);
    }

    /**
     * Adds properties to a group.
     *
     * @param array{
     *     groupType: string,
     *     groupKey: string,
     *     properties?: array<string, mixed>,
     *     distinctId?: string,
     *     distinct_id?: string
     * } $message Group identify payload. `distinctId`/`distinct_id` override the default synthetic ID.
     * @return bool Whether the groupIdentify call succeeded.
     * @throws Exception
     */
    public static function groupIdentify(array $message)
    {
        self::assert(!empty($message["groupType"]), "PostHog::groupIdentify() expects a groupType");
        self::assert(!empty($message["groupKey"]), "PostHog::groupIdentify() expects a groupKey");

        if (!isset($message["properties"])) {
            $message["properties"] = array();
        }

        $distinctId = "\${$message['groupType']}_{$message['groupKey']}";
        if (
            array_key_exists("distinctId", $message)
            && is_scalar($message["distinctId"])
            && (string) $message["distinctId"] !== ""
        ) {
            $distinctId = (string) $message["distinctId"];
        } elseif (
            array_key_exists("distinct_id", $message)
            && is_scalar($message["distinct_id"])
            && (string) $message["distinct_id"] !== ""
        ) {
            $distinctId = (string) $message["distinct_id"];
        }

        $msg = array(
            "event" => "\$groupidentify",
            "distinctId" => $distinctId,
            "properties" => array(
                "\$group_type" => $message["groupType"],
                "\$group_key" => $message["groupKey"],
                "\$group_set" => $message["properties"],
            )
        );

        return self::capture($msg);
    }

    /**
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call
     * `$flags->isEnabled($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups
     * @param array<string, mixed> $personProperties
     * @param array<string, array<string, mixed>> $groupProperties
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @param bool $sendFeatureFlagEvents Whether to send $feature_flag_called events.
     * @return bool|null
     * @throws Exception
     */
    public static function isFeatureEnabled(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): null | bool {
        self::checkClient();
        return self::$client->isFeatureEnabled(
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
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call
     * `$flags->getFlag($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups
     * @param array<string, mixed> $personProperties
     * @param array<string, array<string, mixed>> $groupProperties
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @param bool $sendFeatureFlagEvents Whether to send $feature_flag_called events.
     * @return bool|string|null
     * @throws Exception
     */
    public static function getFeatureFlag(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): null | bool | string {
        self::checkClient();
        return self::$client->GetFeatureFlag(
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
    public static function getFeatureFlagResult(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): ?FeatureFlagResult {
        self::checkClient();
        return self::$client->getFeatureFlagResult(
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
     * @deprecated Use `evaluateFlags($distinctId, ...)` and call
     * `$flags->getFlagPayload($key)` instead. This consolidates flag evaluation into a single
     * `/flags` request per incoming request.
     *
     * @param string $key
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups
     * @param array<string, mixed> $personProperties
     * @param array<string, array<string, mixed>> $groupProperties
     * @return mixed
     */
    public static function getFeatureFlagPayload(
        string $key,
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
    ): mixed {
        self::checkClient();

        return self::$client->getFeatureFlagPayload(
            $key,
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties
        );
    }

    /**
     * Evaluate every feature flag for a distinct id in a single round trip and return a snapshot.
     * When distinctId is omitted, the current request context distinctId is used if available.
     * Pass the snapshot to capture() via the `flags` key to attach $feature/<key> properties
     * without making another /flags request.
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
     * @throws Exception
     */
    public static function evaluateFlags(
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $disableGeoip = false,
        ?array $flagKeys = null
    ): FeatureFlagEvaluations {
        self::checkClient();
        return self::$client->evaluateFlags(
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            $onlyEvaluateLocally,
            $disableGeoip,
            $flagKeys
        );
    }

    /**
     * Get all enabled flags for a distinct id.
     *
     * @param string|null $distinctId Defaults to the current request context distinctId, when set.
     * @param array<string, mixed> $groups
     * @param array<string, mixed> $personProperties
     * @param array<string, array<string, mixed>> $groupProperties
     * @param bool $onlyEvaluateLocally Whether to avoid a remote /flags fallback.
     * @return array<string, bool|string>
     * @throws Exception
     */
    public static function getAllFlags(
        ?string $distinctId = null,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false
    ): array {
        self::checkClient();
        return self::$client->getAllFlags(
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            $onlyEvaluateLocally
        );
    }


    /**
     * Fetch all feature flag variants for a distinct id from the remote /flags endpoint.
     *
     * @param string $distinctId The user's distinct ID.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @return array<string, bool|string>
     */
    public static function fetchFeatureVariants(string $distinctId, array $groups = array()): array
    {
        self::checkClient();
        return self::$client->fetchFeatureVariants($distinctId, $groups);
    }

    /**
     * Aliases the distinct id from a temporary id to a permanent one.
     *
     * @param array{
     *     distinctId?: string,
     *     distinct_id?: string,
     *     alias: string,
     *     properties?: array<string, mixed>
     * } $message
     * @return bool Whether the alias call succeeded.
     * @throws Exception
     */
    public static function alias(array $message)
    {
        self::checkClient();
        $alias = !empty($message["alias"]);
        self::assert($alias, "PostHog::alias() requires an alias");
        self::validate($message, "alias");

        return self::$client->alias($message);
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
     * @throws Exception When the client has not been initialized.
     * @throws \Throwable Re-throws any exception thrown by $fn after restoring context.
     */
    public static function withContext(array $data, callable $fn, array $options = []): mixed
    {
        self::checkClient();
        return self::$client->withContext($data, $fn, $options);
    }

    /**
     * Get the currently active request context for this client, if any.
     *
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}|null
     * @throws Exception
     */
    public static function getContext(): ?array
    {
        self::checkClient();
        return self::$client->getContext();
    }

    /**
     * Extract PostHog frontend tracing context from HTTP headers.
     *
     * @param array<string, mixed> $headers HTTP headers, including $_SERVER-style HTTP_* keys.
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}
     */
    public static function contextFromHeaders(array $headers): array
    {
        return RequestContext::contextFromHeaders($headers);
    }

    /**
     * Send a raw, already-prepared message to the underlying consumer queue.
     *
     * @param array<string, mixed> $message Prepared message payload.
     * @return mixed Whether the underlying consumer accepted the message.
     */
    public static function raw(array $message)
    {
        self::checkClient();

        return self::$client->raw($message);
    }


    /**
     * Validate common properties.
     *
     * @internal
     * @param array<string, mixed> $msg
     * @param string $type
     * @return void
     * @throws Exception
     */
    public static function validate($msg, $type)
    {
        $distinctId = !empty($msg["distinctId"]) || !empty($msg["distinct_id"]);
        self::assert($distinctId, "PostHog::{$type}() requires distinctId");
    }

    /**
     * Flush queued events on the underlying client.
     *
     * @return bool True when flushing succeeded or the consumer has no flush operation.
     * @throws Exception
     */
    public static function flush()
    {
        self::checkClient();

        return self::$client->flush();
    }

    /**
     * Get the underlying client instance.
     * Useful for accessing client-level functionality like loadFlags() or getFlagsEtag().
     *
     * @return Client
     * @throws Exception
     */
    public static function getClient(): Client
    {
        self::checkClient();

        return self::$client;
    }

    private static function cleanHost(?string $host): string
    {
        $host = StringNormalizer::normalizeHost($host);

        // remove protocol
        if (substr($host, 0, 8) === "https://") {
            $host = str_replace('https://', '', $host);
        } elseif (substr($host, 0, 7) === "http://") {
            $host = str_replace('http://', '', $host);
        }

        // remove trailing slash
        if (substr($host, strlen($host) - 1, 1) === "/") {
            $host = substr($host, 0, strlen($host) - 1);
        }

        return $host;
    }

    /**
     * Ensure the default client exists. If init() was never called, install a disabled no-op client
     * so public SDK methods do not throw into the host application.
     */
    private static function checkClient()
    {
        if (isset(self::$client)) {
            return;
        }

        error_log('[PostHog] PostHog::init() was not called; SDK will no-op.');
        self::$client = new Client(null, array('consumer' => 'noop'), null, null, false);
    }

    /**
     * Assert `value` or throw.
     *
     * @param mixed $value
     * @param string $msg
     * @throws Exception
     */
    private static function assert($value, $msg)
    {
        if (!$value) {
            throw new Exception($msg);
        }
    }
}
