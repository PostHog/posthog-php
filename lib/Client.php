<?php

namespace PostHog;

use Exception;
use PostHog\Consumer\File;
use PostHog\Consumer\ForkCurl;
use PostHog\Consumer\LibCurl;
use PostHog\Consumer\Socket;

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
    private $featureFlags;

    /**
     * @var array
     */
    private $groupTypeMapping;

    /**
     * Create a new posthog object with your app's API key
     * key
     *
     * @param string $apiKey
     * @param array $options array of consumer options [optional]
     * @param HttpClient|null $httpClient
     */
    public function __construct(string $apiKey, array $options = [], ?HttpClient $httpClient = null)
    {
        $this->apiKey = $apiKey;
        $Consumer = self::CONSUMERS[$options["consumer"] ?? "lib_curl"];
        $this->consumer = new $Consumer($apiKey, $options);
        $this->httpClient = $httpClient !== null ? $httpClient : new HttpClient(
            $options['host'] ?? "app.posthog.com",
            $options['ssl'] ?? true,
            10000,
            false,
            $options["debug"] ?? false
        );
        $this->featureFlags = [];
        $this->groupTypeMapping = [];

        // Populate featureflags and grouptypemapping if possible
        $this->loadFlags();
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

        if (array_key_exists("send_feature_flags", $message) && $message["send_feature_flags"]) {
            $flags = $this->fetchEnabledFeatureFlags($message["distinct_id"], $message["groups"]);

            if (!isset($message["properties"])) {
                $message["properties"] = array();
            }

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
     * @param mixed $defaultValue
     * @param array $groups
     * @param array $personProperties
     * @param array $groupProperties
     * @return bool
     * @throws Exception
     */
    public function isFeatureEnabled(
        string $key,
        string $distinctId,
        $defaultValue = false,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array()
    ): bool {
        return boolval($this->getFeatureFlag($key, $distinctId, $defaultValue, $groups, $personProperties, $groupProperties));
    }

    /**
     * get the feature flag value for this distinct id.
     *
     * @param string $key
     * @param string $distinctId
     * @param mixed $defaultValue
     * @param array $groups
     * @param array $personProperties
     * @param array $groupProperties
     * @return bool | string
     * @throws Exception
     */
    public function getFeatureFlag(
        string $key,
        string $distinctId,
        bool $defaultValue = false,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array()
    ): bool | string {
        $result = false;

        foreach ($this->featureFlags as $flag) {
            if ($flag["key"] == $key) {

                try {
                    $result = $this->_computeFlagLocally(
                        $flag,
                        $distinctId,
                        $groups,
                        $personProperties,
                        $groupProperties
                    );
                } catch (InconclusiveMatchException $e) {
                    // TODO: handle error
                }
    
            }
        }

        if (is_null($result)) {
            try {
                $featureFlags = $this->fetchEnabledFeatureFlags($distinctId, $groups, $personProperties, $groupProperties);
                $response = $featureFlags[$key] ?? $defaultValue;
            } catch (Exception $e) {
                // TODO: handle error
            }
        }

        $this->capture([
            "properties" => [
                '$feature_flag' => $key,
                '$feature_flag_response' => $result,
            ],
            "distinct_id" => $distinctId,
            "event" => '$feature_flag_called',
        ]);

        if ($result) {
            return $result;
        }
        return $defaultValue;
    }

    private function _computeFlagLocally(
        array $featureFlag,
        string $distinctId,
        array $groups = array(),
        array $personProperties = array(),
        array $groupProperties = array()
    ) : bool | string
    {
        if ($featureFlag["ensure_experience_continuity"] ?? false) {
            throw new InconclusiveMatchException("Flag has experience continuity enabled");
        }

        if (!$featureFlag["active"]) {
            return false;
        }

        $flagFilters = $featureFlag["filters"] ?? [];
        $aggregationGroupTypeIndex = $featureFlag["aggregation_group_type_index"] ?? null;

        if (!is_null($aggregationGroupTypeIndex)) {
            // TODO: handle groups
        } else {
            return FeatureFlag::matchFeatureFlagProperties($featureFlag, $distinctId, $personProperties);
        }

    }


    /**
     * @param string $distinctId
     * @param array $groups
     * @return array of enabled feature flags
     * @throws Exception
     */
    public function fetchEnabledFeatureFlags(string $distinctId, array $groups = array(), array $personProperties = [], array $groupProperties = []): array
    {
        $flags = json_decode($this->decide($distinctId, $groups, $personProperties, $groupProperties), true)['featureFlags'] ?? [];
        return array_filter($flags, function ($v) {
            return $v != false;
        });
    }

    /**
     * @throws Exception
     */

    private function loadFlags()
    {
        $payload = json_decode($this->localFlags(), true);
        $this->featureFlags = $payload['flags'] ?? [];
        $this->groupTypeMapping = $payload['group_type_mapping'] ?? [];
    }


    public function localFlags()
    {
        $payload = array(
            'api_key' => $this->apiKey,
        );

        return $this->httpClient->sendRequest(
            '/api/feature_flag/local_evaluation',
            json_encode($payload),
            [
                // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
                "User-Agent: posthog-php/" . PostHog::VERSION,
            ]
        )->getResponse();
    }

    public function decide(string $distinctId, array $groups = array(), array $personProperties = [], array $groupProperties = [])
    {
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
