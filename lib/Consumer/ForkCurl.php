<?php

namespace PostHog\Consumer;

use PostHog\QueueConsumer;

/**
 * Queue consumer that sends batches through a forked curl process.
 *
 * @internal
 */
class ForkCurl extends QueueConsumer
{
    protected $type = "ForkCurl";

    /**
     * Creates a new queued fork consumer which queues fork and identify
     * calls before adding them to
     * @param string $apiKey Project API key.
     * @param array<string, mixed> $options Consumer options.
     */
    public function __construct($apiKey, $options = array())
    {
        parent::__construct($apiKey, $options);
    }

    /**
     * Define getter method for consumer type
     *
     * @return string
     */
    public function getConsumer()
    {
        return $this->type;
    }

    /**
     * Make an async request to our API. Fork a curl process, immediately send
     * to the API. If debug is enabled, we wait for the response.
     * @param array<int, array<string, mixed>> $messages Array of messages to send.
     * @return bool|string Whether the request succeeded or a queue failure classification.
     */
    public function flushBatch($messages)
    {
        $body = $this->payload($messages);
        $payload = json_encode($body);

        // Escape for shell usage.
        $payload = escapeshellarg($payload);

        $protocol = $this->ssl() ? "https://" : "http://";

        $path = "/batch/";
        $url = $protocol . $this->host . $path;

        $cmd = "curl -X POST -H 'Content-Type: application/json'";

        $tmpfname = "";
        if ($this->compress_request) {
            // Compress request to file
            $tmpfname = tempnam("/tmp", "forkcurl_");
            $cmd2 = "echo " . $payload . " | gzip > " . $tmpfname;
            exec($cmd2, $output, $exit);

            if (0 != $exit) {
                $this->handleError($exit, $output);
                return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
            }

            $cmd .= " -H 'Content-Encoding: gzip'";

            $cmd .= " --data-binary '@" . $tmpfname . "'";
        } else {
            $cmd .= " -d " . $payload;
        }

        $cmd .= " '" . $url . "'";

        if (strlen($payload) >= self::MAX_BATCH_PAYLOAD_SIZE) {
            if ($this->debug()) {
                $msg = "Message size is larger than " . self::MAX_BATCH_PAYLOAD_SIZE_HUMAN;
                error_log("[PostHog][" . $this->type . "] " . $msg);
            }

            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }

        // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
        $cmd .= " -H 'User-Agent: " . $this->userAgent() . "'";

        if (!$this->debug()) {
            $cmd .= " > /dev/null 2>&1 &";
        }

        exec($cmd, $output, $exit);

        if (0 != $exit) {
            $this->handleError($exit, $output);
        }

        if ($tmpfname != "") {
            unlink($tmpfname);
        }

        if (0 == $exit) {
            return true;
        }

        return $this->isNetworkCurlExit($exit)
            ? self::FLUSH_BATCH_RETRYABLE_FAILURE
            : self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
    }

    private function isNetworkCurlExit($exit)
    {
        return in_array((int) $exit, [6, 7, 28, 35, 52, 56], true);
    }
}
