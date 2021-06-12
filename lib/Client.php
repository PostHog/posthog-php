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

    private const LONG_SCALE = 0xFFFFFFFFFFFFFFF;

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

    protected $featureFlags = null;

    /**
     * @var string|null
     */
    private $personalApiKey;

    /**
     * @var HttpClient
     */
    private $httpClient;


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
        $this->personalApiKey = $options["personal_api_key"] ?? null;
        $this->httpClient = $httpClient !== null ? $httpClient : new HttpClient(
            $options['host'] ?? "app.posthog.com",
            $options['ssl'] ?? true,
            10000,
            false,
            $options["debug"] ?? false
        );
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
     * @return bool
     * @throws Exception
     */
    public function isFeatureEnabled(string $key, string $distinctId, $defaultValue = false): bool
    {
        if (null === $this->personalApiKey) {
            throw new Exception(
                "To use feature flags, please set a personal_api_key. 
                For More information: https://posthog.com/docs/api/overview"
            );
        }

        if (null === $this->featureFlags) {
            $this->loadFeatureFlags();
        }

        if (null === $this->featureFlags) { // if loading failed.
            return $defaultValue;
        }

        $selectedFlag = null;
        foreach ($this->featureFlags as $flag) {
            if ($flag['key'] === $key) {
                $selectedFlag = $flag;
            }
        }
        if (null === $selectedFlag) {
            return $defaultValue;
        }

        if ((bool) $selectedFlag['is_simple_flag']) {
            $result = $this->isSimpleFlagEnabled($key, $distinctId, $flag['rollout_percentage']);
        } else {
            $result = in_array($key, $this->fetchEnabledFeatureFlags($distinctId));
        }

        $this->capture([
            "properties" => [
                '$feature_flag' => $key,
                '$feature_flag_response' => $result,
            ],
            "distinct_id" => $distinctId,
            "event" => '$feature_flag_called',
        ]);

        return $result ??  $defaultValue;
    }


    /**
     * @param string $distinctId
     * @return array
     * @throws Exception
     */
    public function fetchEnabledFeatureFlags(string $distinctId): array
    {
        return json_decode($this->decide($distinctId), true)['featureFlags'] ?? [];
    }

    public function decide(string $distinctId)
    {
        $payload = json_encode([
            'api_key' => $this->apiKey,
            'distinct_id' => $distinctId,
        ]);

        return $this->httpClient->sendRequest('/decide/', $payload)->getResponse();
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

        if (!isset($msg["timestamp"])) {
            $msg["timestamp"] = null;
        }
        $msg["timestamp"] = $this->formatTime($msg["timestamp"]);

        return $msg;
    }

    private function loadFeatureFlags(): void
    {
        $response = $this->httpClient->sendRequest(
            '/api/feature_flag',
            null,
            [
                "Authorization: Bearer $this->personalApiKey",
            ]
        );
        if (401 === $response->getResponseCode()) {
            throw new Exception(
                "Your personalApiKey is invalid. Are you sure you're not using your Project API key?
                 More information: https://posthog.com/docs/api/overview"
            );
        }

        $responseBody = json_decode($response->getResponse(), true);
        if (null === $responseBody) {
            return;
        }

        if (empty($responseBody)) {
            $this->featureFlags = [];
        }

        $this->featureFlags = $responseBody['results'];
    }

    private function isSimpleFlagEnabled(string $key, string $distinctId, ?int $rolloutPercentage): bool
    {
        if (! (bool) $rolloutPercentage) {
            return true;
        }
        $hexValueOfHash = sha1("$key.$distinctId", false);
        $integerRepresentationOfHashSubset = intval(substr($hexValueOfHash, 0, 15), 16);
        return ($integerRepresentationOfHashSubset / self::LONG_SCALE) <= ($rolloutPercentage / 100);
    }
}
