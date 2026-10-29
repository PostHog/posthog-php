<?php

namespace PostHog\Consumer;

use PostHog\QueueConsumer;
use Symfony\Component\Clock\Clock;

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
        $payload = $this->encodeBatchPayload($messages);
        if (false === $payload) {
            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }

        // Escape for shell usage and reject payloads that expand beyond the
        // configured safety limit before creating compression temporary files.
        try {
            $payload = escapeshellarg($payload);
        } catch (\ValueError $error) {
            $this->handleError($error->getCode(), $error->getMessage());
            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }
        if ($this->payloadExceedsSizeLimit($payload)) {
            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }

        $protocol = $this->ssl() ? "https://" : "http://";

        $path = "/batch/";
        $url = $protocol . $this->host . $path;

        $timeout = escapeshellarg((string) (ceil($this->options['timeout'] ?? 10000) / 1000));
        $cmd = "curl -X POST -H 'Content-Type: application/json'";
        $cmd .= " --connect-timeout " . $timeout . " --max-time " . $timeout;

        $tmpfname = "";
        if ($this->compress_request) {
            // Compress request to file
            $tmpfname = tempnam("/tmp", "forkcurl_");
            $cmd2 = "printf '%s' " . $payload . " | gzip > " . $tmpfname;
            exec($cmd2, $output, $exit);

            if (0 == $exit) {
                $cmd .= " -H 'Content-Encoding: gzip'";
                $cmd .= " --data-binary '@" . $tmpfname . "'";
            } else {
                $this->handleError($exit, $output);
                if (is_file($tmpfname)) {
                    unlink($tmpfname);
                }
                $tmpfname = "";
                $cmd .= " -d " . $payload;
            }
        } else {
            $cmd .= " -d " . $payload;
        }

        $cmd .= " '" . $url . "'";

        // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
        $cmd .= " -H 'User-Agent: " . $this->userAgent() . "'";

        if ($this->debug()) {
            try {
                return $this->sendForeground($cmd);
            } finally {
                if ($tmpfname != "") {
                    unlink($tmpfname);
                }
            }
        }

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

    private function sendForeground(string $cmd): bool|string
    {
        $headersFile = tempnam(sys_get_temp_dir(), "posthog-curl-headers-");
        if ($headersFile === false) {
            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }

        try {
            $cmd .= " --silent --show-error --dump-header " . escapeshellarg($headersFile);
            $cmd .= " --write-out '\n%{http_code}'";
            $backoff = 100;

            while (true) {
                $output = [];
                exec($cmd, $output, $exit);
                if ($exit !== 0) {
                    $this->handleError($exit, implode("\n", $output));
                    return $this->isNetworkCurlExit($exit)
                        ? self::FLUSH_BATCH_RETRYABLE_FAILURE
                        : self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
                }

                $statusCode = (int) array_pop($output);
                if ($statusCode === 200) {
                    return true;
                }

                $this->handleError($statusCode, implode("\n", $output));
                if (!$this->isRetryableBatchStatus($statusCode)) {
                    return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
                }
                if ($backoff >= $this->maximum_backoff_duration) {
                    return self::FLUSH_BATCH_RETRYABLE_FAILURE;
                }

                $headers = file($headersFile, FILE_IGNORE_NEW_LINES) ?: [];
                $delay = $this->retryDelayMilliseconds($headers, $backoff, $this->maximum_backoff_duration);
                Clock::get()->sleep($delay / 1000);
                $backoff *= 2;
            }
        } finally {
            unlink($headersFile);
        }
    }

    private function isNetworkCurlExit($exit)
    {
        return in_array((int) $exit, [6, 7, 28, 35, 52, 56], true);
    }
}
