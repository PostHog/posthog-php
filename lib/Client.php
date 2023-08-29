<?php

namespace PostHog;

use Exception;
use PostHog\Consumer\File;
use PostHog\Consumer\ForkCurl;
use PostHog\Consumer\LibCurl;
use PostHog\Consumer\Socket;

const SIZE_LIMIT = 50_000;

class Client
{
    private const CONSUMERS = [
        "socket" => Socket::class,
        "file" => File::class,
        "fork_curl" => ForkCurl::class,
        "lib_curl" => LibCurl::class,
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
     * @var SizeLimitedHash
     */
    public $distinctIdsFeatureFlagsReported;

    /**
     * Create a new posthog object with your app's API key
     * key
     *
     * @param string $apiKey
     * @param array $options array of consumer options [optional]
     * @param HttpClient|null $httpClient
     */
    public function __construct(
        string $apiKey,
        array $options = [],
        ?HttpClient $httpClient = null,
        string $personalAPIKey = null
    ) {
        $this->apiKey = $apiKey;
        $this->personalAPIKey = $personalAPIKey;
        $Consumer = self::CONSUMERS[$options["consumer"] ?? "lib_curl"];
        $this->consumer = new $Consumer($apiKey, $options);
        $this->httpClient = $httpClient !== null ? $httpClient : new HttpClient(
            $options['host'] ?? "app.posthog.com",
            $options['ssl'] ?? true,
            (int) ($options['maximum_backoff_duration'] ?? 10000),
            false,
            $options["debug"] ?? false,
            null,
            (int) ($options['timeout'] ?? 10000)
        );
        $this->featureFlags = [];
        $this->groupTypeMapping = [];
        $this->distinctIdsFeatureFlagsReported = new SizeLimitedHash(SIZE_LIMIT);

        // Populate featureflags and grouptypemapping if possible
        if (count($this->featureFlags) == 0 && !is_null($this->personalAPIKey)) {
            $this->loadFlags();
        }
    }

    public function __destruct()
    {
        $this->consumer->__destruct();
    }

    /**
     * Captures a user action
     *
     * @param array $message
     * @return bool whether the capture call succeeded
     */
    public function capture(array $message)
    {
        $message = $this->message($message);
        $message["type"] = "capture";

        if (array_key_exists('$groups', $message)) {
            $message["properties"]['$groups'] = $message['$groups'];
        }

        if (array_key_exists("send_feature_flags", $message) && $message["send_feature_flags"]) {
            $flags = $this->fetchFeatureVariants($message["distinct_id"], $message["groups"]);

            // Add all feature variants to event
            foreach ($flags as $flagKey => $flagValue) {
                $message["properties"][sprintf('$feature/%s', $flagKey)] = $flagValue;
            }

            // Add all feature flag keys to $active_feature_flags key
            $message["properties"]['$active_feature_flags'] = array_keys($flags);
        }

        return $this->consumer->capture($message);
    }

    /**
     * Tags properties about the user.
     *
     * @param array $message
     * @return bool whether the identify call succeeded
     */
    public function identify(array $message)
    {
        if (isset($message['properties'])) {
            $message['$set'] = $message['properties'];
        }

        $message = $this->message($message);
        $message["type"] = "identify";
        $message["event"] = '$identify';

        return $this->consumer->identify($message);
    }

    /**
     * decide if the feature flag is enabled for this distinct id.
     *
     * @param string $key
     * @param string $distinctId
     * @param array $groups
     * @param array $personProperties
     * @param array $groupProperties
     * @return bool
     * @throws Exception
     */
    public function isFeatureEnabled(
        string $key,
        string $distinctId,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): null | bool {
        $result = $this->getFeatureFlag(
            $key,
            $distinctId,
            $groups,
            $personProperties,
            $groupProperties,
            $onlyEvaluateLocally,
            $sendFeatureFlagEvents
        );

        if (is_null($result)) {
            return $result;
        } else {
            return boolval($result);
        }
    }

    /**
     * get the feature flag value for this distinct id.
     *
     * @param string $key
     * @param string $distinctId
     * @param array $groups
     * @param array $personProperties
     * @param array $groupProperties
     * @return bool | string
     * @throws Exception
     */
    public function getFeatureFlag(
        string $key,
        string $distinctId,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true
    ): null | bool | string {
        $result = null;

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
                } catch (InconclusiveMatchException $e) {
                    $result = null;
                } catch (Exception $e) {
                    $result = null;
                    error_log("[PostHog][Client] Error while computing variant:" . $e->getMessage());
                }
            }
        }

        $flagWasEvaluatedLocally = !is_null($result);

        if (!$flagWasEvaluatedLocally && !$onlyEvaluateLocally) {
            try {
                $featureFlags = $this->fetchFeatureVariants($distinctId, $groups, $personProperties, $groupProperties);
                $result = $featureFlags[$key] ?? null;
            } catch (Exception $e) {
                error_log("[PostHog][Client] Unable to get feature variants:" . $e->getMessage());
                $result = null;
            }
        }

        if ($sendFeatureFlagEvents && !$this->distinctIdsFeatureFlagsReported->contains($key, $distinctId)) {
            $this->capture([
                "properties" => [
                    '$feature_flag' => $key,
                    '$feature_flag_response' => $result,
                ],
                "distinct_id" => $distinctId,
                "event" => '$feature_flag_called',
                '$groups' => $groups
            ]);
            $this->distinctIdsFeatureFlagsReported->add($key, $distinctId);
        }

        if (!is_null($result)) {
            return $result;
        }
        return null;
    }

    /**
     * get the feature flag value for this distinct id.
     *
     * @param string $distinctId
     * @param array $groups
     * @param array $personProperties
     * @param array $groupProperties
     * @return array
     * @throws Exception
     */
    public function getAllFlags(
        string $distinctId,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array(),
        bool $onlyEvaluateLocally = false
    ): array {
        $response = [];
        $fallbackToDecide = false;

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
                } catch (InconclusiveMatchException $e) {
                    $fallbackToDecide = true;
                } catch (Exception $e) {
                    $fallbackToDecide = true;
                    error_log("[PostHog][Client] Error while computing variant:" . $e->getMessage());
                }
            }
        } else {
            $fallbackToDecide = true;
        }

        if ($fallbackToDecide && !$onlyEvaluateLocally) {
            try {
                $featureFlags = $this->fetchFeatureVariants($distinctId, $groups, $personProperties, $groupProperties);
                $response = array_merge($response, $featureFlags);
            } catch (Exception $e) {
                error_log("[PostHog][Client] Unable to get feature variants:" . $e->getMessage());
            }
        }

        return $response;
    }

    private function computeFlagLocally(
        array $featureFlag,
        string $distinctId,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array()
    ): bool | string {
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
            return FeatureFlag::matchFeatureFlagProperties($featureFlag, $groups[$groupName], $focusedGroupProperties);
        } else {
            return FeatureFlag::matchFeatureFlagProperties($featureFlag, $distinctId, $personProperties);
        }
    }


    /**
     * @param string $distinctId
     * @param array $groups
     * @return array of feature flags
     * @throws Exception
     */
    public function fetchFeatureVariants(
        string $distinctId,
        array $groups = array(),
        array $personProperties = [],
        array $groupProperties = []
    ): array {
        $flags = json_decode(
            $this->decide($distinctId, $groups, $personProperties, $groupProperties),
            true
        )['featureFlags'] ?? [];
        return $flags;
    }

    /**
     * @throws Exception
     */

    public function loadFlags()
    {
        $payload = json_decode($this->localFlags(), true);

        if ($payload && array_key_exists("detail", $payload)) {
            throw new Exception($payload["detail"]);
        }

        $this->featureFlags = $payload['flags'] ?? [];
        $this->groupTypeMapping = $payload['group_type_mapping'] ?? [];
    }


    public function localFlags()
    {

        return $this->httpClient->sendRequest(
            '/api/feature_flag/local_evaluation?token=' . $this->apiKey,
            null,
            [
                // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
                "User-Agent: posthog-php/" . PostHog::VERSION,
                "Authorization: Bearer " . $this->personalAPIKey
            ]
        )->getResponse();
    }

    public function decide(
        string $distinctId,
        array $groups = array(),
        array $personProperties = [],
        array $groupProperties = []
    ) {
        $payload = array(
            'api_key' => $this->apiKey,
            'distinct_id' => $distinctId,
        );

        if (!empty($groups)) {
            $payload["groups"] = $groups;
        }

        if (!empty($personProperties)) {
            $payload["person_properties"] = $personProperties;
        }

        if (!empty($groupProperties)) {
            $payload["group_properties"] = $groupProperties;
        }

        return $this->httpClient->sendRequest(
            '/decide/?v=2',
            json_encode($payload),
            [
                // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
                "User-Agent: posthog-php/" . PostHog::VERSION,
            ]
        )->getResponse();
    }

    /**
     * Aliases from one user id to another
     *
     * @param array $message
     * @return boolean whether the alias call succeeded
     */
    public function alias(array $message)
    {
        $message = $this->message($message);
        $message["type"] = "alias";
        $message["event"] = '$create_alias';

        $message['properties']['distinct_id'] = $message['distinct_id'];
        $message['properties']['alias'] = $message['alias'];

        $message['distinct_id'] = null;
        unset($message['alias']);

        return $this->consumer->alias($message);
    }

    /**
     * Queue a raw (prepared) message
     *
     * @param array $message
     * @return mixed whether the identify call succeeded
     */
    public function raw(array $message)
    {
        return $this->consumer->enqueue($message);
    }

    /**
     * Flush any async consumers
     * @return boolean true if flushed successfully
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
            $ts = time();
        }
        if (false !== filter_var($ts, FILTER_VALIDATE_INT)) {
            return date("c", (int)$ts);
        }

        // anything else try to strtotime the date.
        if (false === filter_var($ts, FILTER_VALIDATE_FLOAT)) {
            if (is_string($ts)) {
                return date("c", strtotime($ts));
            }

            return date("c");
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
     * Add common fields to the given `message`
     *
     * @param array $msg
     * @return array
     */
    private function message($msg)
    {
        if (!isset($msg["properties"])) {
            $msg["properties"] = array();
        }

        $msg["library"] = 'posthog-php';
        $msg["library_version"] = PostHog::VERSION;
        $msg["library_consumer"] = $this->consumer->getConsumer();

        $msg["properties"]['$lib'] = 'posthog-php';
        $msg["properties"]['$lib_version'] = PostHog::VERSION;
        $msg["properties"]['$lib_consumer'] = $this->consumer->getConsumer();

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
}
