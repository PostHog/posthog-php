<?php

require __DIR__ . '/../../vendor/autoload.php';

use PostHog\Client;
use PostHog\Consumer\LibCurl;
use PostHog\FlagDefinitionCacheProvider;

[$script, $target, $failure, $throwableClass, $operation] = $argv;
$throwable = new $throwableClass('application failure');
$options = ['batch_size' => 100, 'flush_interval_seconds' => 3600];
$property = "\xB1\x31";
if ($failure === 'serializer') {
    $property = new class ($throwable) implements JsonSerializable {
        public function __construct(private Throwable $throwable)
        {
        }

        public function jsonSerialize(): mixed
        {
            echo "serialization-attempted\n";
            throw $this->throwable;
        }
    };
} else {
    $options['error_handler'] = static function () use ($throwable): void {
        echo "error-handler-called\n";
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
