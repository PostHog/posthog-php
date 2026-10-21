<?php

require __DIR__ . '/../../vendor/autoload.php';

use PostHog\Client;
use PostHog\Consumer\LibCurl;
use PostHog\FlagDefinitionCacheProvider;

[$script, $target, $failure, $throwableClass, $operation] = $argv;
$throwable = new $throwableClass('application failure');
$options = ['batch_size' => 100, 'flush_interval_seconds' => 3600];
$retry = str_ends_with($operation, '-retry');
if ($retry) {
    $operation = substr($operation, 0, -strlen('-retry'));
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException($error, $errno);
    }
    $options['host'] = stream_socket_get_name($server, false);
    $options['ssl'] = false;
    fclose($server);
}
$property = $retry ? 'valid input' : "\xB1\x31";
if ($failure === 'serializer') {
    $property = new class ($throwable, $retry) implements JsonSerializable {
        private int $attempts = 0;

        public function __construct(private Throwable $throwable, private bool $retry)
        {
        }

        public function jsonSerialize(): mixed
        {
            echo "serialization-attempted\n";
            if ($this->retry && ++$this->attempts === 1) {
                return 'valid input';
            }
            throw $this->throwable;
        }
    };
} else {
    $attempts = 0;
    $options['error_handler'] = static function () use ($throwable, $retry, &$attempts): void {
        echo "error-handler-called\n";
        if ($retry && ++$attempts === 1) {
            return;
        }
        throw $throwable;
    };
}

if ($target === 'client') {
    $options['flag_definition_cache_provider'] = new class implements FlagDefinitionCacheProvider {
        public function getFlagDefinitions(): ?array
        {
            return null;
        }

        public function shouldFetchFlagDefinitions(): bool
        {
            return false;
        }

        public function onFlagDefinitionsReceived(array $data): void
        {
        }

        public function shutdown(): void
        {
            echo "cache-cleaned\n";
        }
    };
    $subject = new Client('test-key', $options, loadFeatureFlags: false);
} else {
    $subject = new LibCurl('test-key', $options);
}

$subject->capture([
    'event' => 'destructor-containment',
    'distinctId' => 'destructor-user',
    'properties' => ['failing' => $property],
]);

if ($operation !== 'automatic') {
    try {
        $subject->$operation();
        echo "exception-not-propagated\n";
        exit(3);
    } catch (Throwable $caught) {
        if ($caught !== $throwable) {
            throw $caught;
        }
        echo "exception-propagated\n";
    }
    if ($operation === 'shutdown') {
        echo $subject->shutdown() ? "shutdown-complete\n" : "shutdown-incomplete\n";
    }
}

echo "application-finished\n";
