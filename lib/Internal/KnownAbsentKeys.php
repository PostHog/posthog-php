<?php

namespace PostHog\Internal;

use InvalidArgumentException;

/**
 * Validates known-absent property key lists. Used by the client and by PostHog framework
 * integrations that return before calling the client.
 *
 * @internal
 */
final class KnownAbsentKeys
{
    // The flags service rejects a known-absent list longer than this, counted with duplicates.
    private const LIMIT = 1000;

    private function __construct()
    {
    }

    /**
     * Reject known-absent lists the flags service would refuse, before any evaluation or request.
     * Lists are never truncated.
     *
     * @param list<string>|null $personPropertyKeysKnownAbsent
     * @param array<string, list<string>>|null $groupPropertyKeysKnownAbsent
     * @throws InvalidArgumentException
     */
    public static function assertValid(
        ?array $personPropertyKeysKnownAbsent,
        ?array $groupPropertyKeysKnownAbsent
    ): void {
        self::assertValidList($personPropertyKeysKnownAbsent ?? [], 'personPropertyKeysKnownAbsent');
        foreach ($groupPropertyKeysKnownAbsent ?? [] as $groupType => $absentKeys) {
            if (!is_array($absentKeys)) {
                throw new InvalidArgumentException(
                    "groupPropertyKeysKnownAbsent['{$groupType}'] must be a list of property keys."
                );
            }
            self::assertValidList($absentKeys, "groupPropertyKeysKnownAbsent['{$groupType}']");
        }
    }

    private static function assertValidList(array $absentKeys, string $name): void
    {
        if (count($absentKeys) > self::LIMIT) {
            throw new InvalidArgumentException(
                "{$name} can hold at most " . self::LIMIT . " keys, got " . count($absentKeys) . "."
            );
        }
        foreach ($absentKeys as $absentKey) {
            if (!is_string($absentKey)) {
                throw new InvalidArgumentException("{$name} must hold only string property keys.");
            }
        }
    }
}
