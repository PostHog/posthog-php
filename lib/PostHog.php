<?php

namespace PostHog;

use Exception;

class PostHog
{
    public const VERSION = '2.1.0';
    public const ENV_API_KEY = "POSTHOG_API_KEY";
    public const ENV_HOST = "POSTHOG_HOST";

    private static Client $client;

    /**
     * Initializes the default client to use. Uses the libcurl consumer by default.
     * @param string|null $apiKey your project's API key
     * @param array|null $options passed straight to the client
     * @param Client|null $client
     * @throws Exception
     */
    public static function init(?string $apiKey = null, ?array $options = [], ?Client $client = null): void
    {
        if (null === $client) {
            $apiKey = $apiKey ?: getenv(self::ENV_API_KEY);


            if (array_key_exists("host", $options)) {
                $options["host"] = self::cleanHost($options["host"]);
            } else {
                $envHost = getenv(self::ENV_HOST) ?: null;
                if (null !== $envHost) {
                    $options["host"] = self::cleanHost(getenv(self::ENV_HOST));
                }
            }

            self::assert($apiKey, "PostHog::init() requires an apiKey");
            self::$client = new Client($apiKey, $options);
        } else {
            self::$client = $client;
        }
    }

    /**
     * Captures a user action
     *
     * @param array $message
     * @return boolean whether the capture call succeeded
     * @throws Exception
     */
    public static function capture(array $message)
    {
        $sendFeatureFlags = array_key_exists("sendFeatureFlags", $message) ? $message["sendFeatureFlags"] ?? false : false;
        self::checkClient($sendFeatureFlags);
        $event = !empty($message["event"]);
        self::assert($event, "PostHog::capture() expects an event");
        self::validate($message, "capture");

        return self::$client->capture($message);
    }

    /**
     * Tags properties about the user.
     *
     * @param array $message
     * @return boolean whether the identify call succeeded
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
     * @param array $message Must contain keys `groupType`, `groupKey`, `properties`
     * @return boolean whether the groupIdentify call succeeded
     * @throws Exception
     */
    public static function groupIdentify(array $message)
    {
        self::assert(!empty($message["groupType"]), "PostHog::groupIdentify() expects a groupType");
        self::assert(!empty($message["groupKey"]), "PostHog::groupIdentify() expects a groupKey");

        if (!isset($message["properties"])) {
            $message["properties"] = array();
        }

        $msg = array(
            "event" => "\$groupidentify",
            "distinctId" => "\${$message['groupType']}_{$message['groupKey']}",
            "properties" => array(
                "\$group_type" => $message["groupType"],
                "\$group_key" => $message["groupKey"],
                "\$group_set" => $message["properties"],
            )
        );

        return self::capture($msg);
    }

    /**
     * decide if the feature flag is enabled for this distinct id.
     *
     * @param string $key
     * @param string $distinctId
     * @param mixed $default
     * @param array $groups
     * @return boolean
     * @throws Exception
     */
    public static function isFeatureEnabled(
        string $key,
        string $distinctId,
        bool $default = false,
        array $groups = array()
    ): bool {
        self::checkClient(true);
        return self::$client->isFeatureEnabled($key, $distinctId, $default, $groups);
    }

    /**
     * get the feature flag value for this distinct id.
     *
     * @param string $key
     * @param string $distinctId
     * @param mixed $default
     * @param array $groups
     * @return boolean | string
     * @throws Exception
     */
    public static function getFeatureFlag(
        string $key,
        string $distinctId,
        bool $default = false,
        array $groups = array()
    ): bool | string {
        self::checkClient(true);
        return self::$client->GetFeatureFlag($key, $distinctId, $default, $groups);
    }


    /**
     *
     * @param string $distinctId
     * @return array
     * @throws Exception
     */
    public static function fetchEnabledFeatureFlags(string $distinctId, array $groups = array()): array
    {
        self::checkClient(true);
        return self::$client->fetchEnabledFeatureFlags($distinctId, $groups);
    }

    /**
     * Aliases the distinct id from a temporary id to a permanent one
     *
     * @param array $message distinct id to alias from
     * @return boolean whether the alias call succeeded
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
     * Send a raw (prepared) message
     *
     * @param array $message distinct id to alias from
     * @return boolean whether the alias call succeeded
     */
    public static function raw(array $message)
    {
        return self::$client->raw($message);
    }


    /**
     * Validate common properties.
     *
     * @param array $msg
     * @param string $type
     * @throws Exception
     */
    public static function validate($msg, $type)
    {
        $distinctId = !empty($msg["distinctId"]);
        self::assert($distinctId, "PostHog::${type}() requires distinctId");
    }

    /**
     * Flush the client
     */

    public static function flush()
    {
        self::checkClient();

        return self::$client->flush();
    }

    private static function cleanHost(?string $host): string
    {
        if (!isset($host)) {
            return $host;
        }
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
     * Check the client.
     *
     * @param bool $isPersonalApiKeyRequired
     * @throws Exception
     */
    private static function checkClient(
        $isPersonalApiKeyRequired = false
    ) {
        if (self::$client && $isPersonalApiKeyRequired && self::$client->httpClient->personalApiKey == null) {
            throw new Exception("PostHog::init() must be called with a PersonalApiKey when using feature flags");
        }

        if (null != self::$client) {
            return;
        }

        throw new Exception("PostHog::init() must be called before any other capturing method.");
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
