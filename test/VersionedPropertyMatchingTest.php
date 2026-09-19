<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\FeatureFlag;
use PostHog\InconclusiveMatchException;

class VersionedPropertyMatchingTest extends TestCase
{
    public static function matchingCases(): iterable
    {
        $rows = [
            'false banana' => [false, 'banana', true, false],
            'false zero' => [false, 0, true, false],
            'boolean list true' => [['true', 'false'], 'true', false, true],
            'boolean list pro' => [['true', 'false'], 'pro', true, false],
            'empty true' => [[], true, true, true],
            'empty empty' => [[], [], true, true],
            'true list' => [true, [true], true, false],
            'false uppercase' => [false, 'FALSE', true, true],
            'false null' => [false, null, true, false],
            'false empty string' => [false, '', true, false],
            'empty nested truthy' => [[], [true, 'TRUE', [[]]], true, true],
            'empty nested falsy' => [[], [true, [0]], false, false],
            'empty false' => [[], false, false, false],
            'empty zero' => [[], 0, false, false],
            'empty banana' => [[], 'banana', false, false],
            'empty null' => [[], null, false, false],
            'mixed boolean' => [[false, 'PRO'], 'FALSE', true, true],
            'mixed string' => [[true, 'PRO'], 'pro', true, true],
            'mixed numeric' => [[1, 'PRO'], '1', true, true],
            'mixed null' => [[null, 'PRO'], 'null', true, true],
            'nested normalized array' => [[[true, true], 'PRO'], [true, true], true, true],
            'whole property array' => [[true], [true], true, false],
            'recursive boolean filter' => [[[true, false]], 'banana', true, false],
            'nested empty filter' => [[[]], true, true, false],
            'nested empty member' => [[[]], [], true, true],
            'boolean list boolean true' => [['TrUe', 'FALSE'], true, false, true],
            'boolean list boolean false' => [['TrUe', 'FALSE'], false, true, true],
            'null normalized' => ['null', null, true, true],
            'unicode expansion' => ['İ', "i\u{0307}", true, true],
            'unicode sigma' => ['ΟΣ', 'ος', true, true],
            'normal string' => ['PRO', 'pro', true, true],
        ];
        foreach ($rows as $name => [$filter, $property, $legacy, $explicit]) {
            foreach ([null, 1, 2, 0, 3, '2'] as $version) {
                yield $name . ' version ' . json_encode($version) => [
                    $filter, $property, $version, $version === 2 ? $explicit : $legacy,
                ];
            }
        }
    }

    #[DataProvider('matchingCases')]
    public function testExactAndIsNot($filter, $value, $version, bool $expected): void
    {
        foreach (['exact', 'is_not'] as $operator) {
            $property = ['key' => 'value', 'value' => $filter, 'operator' => $operator];
            $actual = $version === null
                ? FeatureFlag::matchProperty($property, ['value' => $value])
                : FeatureFlag::matchProperty($property, ['value' => $value], $version);
            self::assertSame($operator === 'exact' ? $expected : !$expected, $actual);
        }
    }

    public static function missingCases(): iterable
    {
        foreach ([1, 2] as $version) {
            foreach (['exact', 'is_not'] as $operator) {
                yield [$version, $operator];
            }
        }
    }

    #[DataProvider('missingCases')]
    public function testMissingPropertyRemainsInconclusive(int $version, string $operator): void
    {
        self::expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty(['key' => 'missing', 'value' => false, 'operator' => $operator], [], $version);
    }
}
