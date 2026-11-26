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

    private const PERSON_METADATA_KEY_PREFIX = '__posthog_person_metadata__';

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

    /**
     * Whether a listed person key can apply. Like the flags service, a person key with the person-metadata
     * prefix never applies, so known absence never hides a persons-table field such as `created_at`.
     */
    public static function appliesToPersonFilter(string|int $absentKey): bool
    {
        return !str_starts_with((string) $absentKey, self::PERSON_METADATA_KEY_PREFIX);
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
