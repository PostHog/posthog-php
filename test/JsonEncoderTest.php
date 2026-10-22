<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\JsonEncoder;

class JsonEncoderTest extends TestCase
{
    public function testSupportedObjectRepresentationsAndReferencesArePreserved(): void
    {
        $number = INF;
        $input = [
            'reference' => &$number,
            'date' => new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            'arrayObject' => new \ArrayObject(['number' => NAN]),
            'emptyObject' => new \stdClass(),
            'emptyArray' => [],
            'sparseArray' => [2 => INF, 4 => 'kept'],
        ];
        self::assertSame(
            '{"reference":null,"date":{"date":"2024-01-01 00:00:00.000000","timezone_type":2,"timezone":"Z"}'
            . ',"arrayObject":{"number":null},"emptyObject":{},"emptyArray":[]'
            . ',"sparseArray":{"2":null,"4":"kept"}}',
            JsonEncoder::encode($input)
        );
        self::assertSame(INF, $number);
    }

    public function testUnsupportedValuesStillFailEncoding(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            self::assertFalse(JsonEncoder::encode(['number' => NAN, 'unsupported' => $resource]));
            self::assertSame(JSON_ERROR_UNSUPPORTED_TYPE, json_last_error());
            self::assertFalse(JsonEncoder::encode(['number' => INF, 'invalid' => "\xB1\x31"]));
            self::assertSame(JSON_ERROR_UTF8, json_last_error());
        } finally {
            fclose($resource);
        }
    }

    public function testJsonSerializableExceptionsAreNotSwallowed(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('unsupported serialization');
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unsupported serialization');
        JsonEncoder::encode($value);
    }

    public function testRecursiveAndOverDepthInputFailsInBoundedSubprocess(): void
    {
        $code = <<<'PHP'
        require $argv[1];
        $array = [];
        $array['self'] = &$array;
        $array['again'] = &$array;
        $object = new stdClass();
        $object->self = $object;
        $deep = ['number' => INF];
        for ($i = 0; $i < 513; ++$i) {
            $deep = [$deep];
        }
        foreach ([$array, $object, $deep] as $value) {
            if (PostHog\JsonEncoder::encode($value) !== false) {
                exit(1);
            }
        }
        echo 'rejected';
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=3', '-r', $code,
                __DIR__ . '/../vendor/autoload.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('rejected', $output);
    }
}
