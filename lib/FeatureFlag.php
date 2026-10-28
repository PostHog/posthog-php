<?php

namespace PostHog;

use Symfony\Component\Clock\Clock;

/**
 * Local feature flag matching helpers.
 *
 * @internal
 */
class FeatureFlag
{
    private const LONG_SCALE = 0xfffffffffffffff;

    // When several causes apply to one flag, the spec reports the first reason in this order, so
    // every SDK picks the same reason whatever order it inspects the filters in.
    private const UNRESOLVED_REASON_PRECEDENCE = [
        UnresolvedFlagReason::EXPERIENCE_CONTINUITY,
        UnresolvedFlagReason::UNSUPPORTED_DEFINITION,
        UnresolvedFlagReason::UNRESOLVED_DEPENDENCY,
        UnresolvedFlagReason::MISSING_CONTEXT,
    ];

    /**
     * Match a single property filter against provided property values.
     *
     * @param array<string, mixed> $property Feature flag property filter.
     * @param array<string, mixed> $propertyValues Available property values keyed by property name.
     * @param mixed $propertyMatchingVersion Definition snapshot selector; only 2 enables explicit matching.
     * @return bool Whether the property matches.
     * @throws InconclusiveMatchException When the property cannot be evaluated locally.
     */
    public static function matchProperty($property, $propertyValues, $propertyMatchingVersion = 1)
    {
        try {
            return FeatureFlag::matchPropertyValue($property, $propertyValues, $propertyMatchingVersion);
        } catch (InconclusiveMatchException $e) {
            // An unusable filter outranks missing context, so check the filter before blaming the context.
            if ($e->getReason() === UnresolvedFlagReason::MISSING_CONTEXT) {
                FeatureFlag::assertFilterSupported($property);
            }
            throw $e;
        }
    }

    /**
     * Throw an unsupported_definition error when local evaluation cannot use the filter, whatever
     * the context holds: an unknown operator, or a filter value that the operator cannot parse.
     *
     * @param array<string, mixed> $property Feature flag property filter.
     * @throws InconclusiveMatchException When local evaluation cannot use the filter.
     */
    private static function assertFilterSupported($property): void
    {
        $operator = $property["operator"] ?? "exact";
        $value = $property["value"] ?? null;

        if (
            in_array($operator, [
                "exact", "is_not", "is_set", "is_not_set", "icontains", "not_icontains",
                "starts_with", "not_starts_with", "ends_with", "not_ends_with",
            ])
        ) {
            return;
        }

        if (in_array($operator, ["regex", "not_regex"])) {
            $pattern = FeatureFlag::valueToString($value);
            FeatureFlag::assertPortableRegex($pattern);
            FeatureFlag::isRegularExpression(FeatureFlag::prepareValueForRegex($pattern));
            return;
        }

        if (in_array($operator, ["gt", "gte", "lt", "lte"])) {
            FeatureFlag::parseNumericFilter($value);
            return;
        }

        if (in_array($operator, ["is_date_before", "is_date_after"])) {
            FeatureFlag::parseDateFilter($value);
            return;
        }

        if (in_array($operator, ["semver_eq", "semver_neq", "semver_gt", "semver_gte", "semver_lt", "semver_lte"])) {
            FeatureFlag::parseSemver($value);
            return;
        }

        if ($operator === "semver_tilde") {
            FeatureFlag::tildeBounds($value);
            return;
        }

        if ($operator === "semver_caret") {
            FeatureFlag::caretBounds($value);
            return;
        }

        if ($operator === "semver_wildcard") {
            FeatureFlag::wildcardBounds($value);
            return;
        }

        throw new InconclusiveMatchException(
            "Unknown operator: " . $operator,
            reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
        );
    }

    private static function matchPropertyValue($property, $propertyValues, $propertyMatchingVersion)
    {
        $key = $property["key"];
        $operator = $property["operator"] ?? "exact";
        $value = $property["value"];

        if (!array_key_exists($key, $propertyValues)) {
            throw new InconclusiveMatchException(
                "Can't match properties without a given property value",
                reason: UnresolvedFlagReason::MISSING_CONTEXT
            );
        }

        if ($operator == "is_not_set") {
            return false;
        }

        $overrideValue = $propertyValues[$key];

        if ($operator == "exact") {
            return FeatureFlag::computeExactMatch($value, $overrideValue, $propertyMatchingVersion);
        }

        if ($operator == "is_not") {
            return !FeatureFlag::computeExactMatch($value, $overrideValue, $propertyMatchingVersion);
        }

        if ($operator == "is_set") {
            return array_key_exists($key, $propertyValues);
        }

        if ($operator == "icontains") {
            return strpos(strtolower(FeatureFlag::valueToString($overrideValue)), strtolower(FeatureFlag::valueToString($value))) !== false;
        }

        if ($operator == "not_icontains") {
            return strpos(strtolower(FeatureFlag::valueToString($overrideValue)), strtolower(FeatureFlag::valueToString($value))) === false;
        }

        if ($operator == "starts_with") {
            return str_starts_with(strtolower(FeatureFlag::valueToString($overrideValue)), strtolower(FeatureFlag::valueToString($value)));
        }

        if ($operator == "not_starts_with") {
            return !str_starts_with(strtolower(FeatureFlag::valueToString($overrideValue)), strtolower(FeatureFlag::valueToString($value)));
        }

        if ($operator == "ends_with") {
            return str_ends_with(strtolower(FeatureFlag::valueToString($overrideValue)), strtolower(FeatureFlag::valueToString($value)));
        }

        if ($operator == "not_ends_with") {
            return !str_ends_with(strtolower(FeatureFlag::valueToString($overrideValue)), strtolower(FeatureFlag::valueToString($value)));
        }

        if (in_array($operator, ["regex", "not_regex"])) {
            $pattern = FeatureFlag::valueToString($value);
            $usesWordClass = FeatureFlag::assertPortableRegex($pattern);
            $regexValue = FeatureFlag::prepareValueForRegex($pattern);
            if (!FeatureFlag::isRegularExpression($regexValue)) {
                return false;
            }

            $input = FeatureFlag::valueToString($overrideValue);
            // Rust and PCRE disagree on word membership for join controls, marks, numbers and symbols.
            if ($usesWordClass && preg_match('/[^\x00-\x7f\p{L}\p{Nd}]/u', $input) === 1) {
                throw new InconclusiveMatchException(
                    "Regex Unicode word class requires server evaluation",
                    reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                );
            }

            $matched = preg_match($regexValue, $input);
            if ($matched === false) {
                throw new InconclusiveMatchException(
                    "Regex execution failed: " . preg_last_error_msg(),
                    reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                );
            }

            return ($matched === 1) === ($operator == "regex");
        }

        if (in_array($operator, ["gt", "gte", "lt", "lte"])) {
            $parsedValue = FeatureFlag::parseNumericFilter($value);

            $parsedOverrideValue = FeatureFlag::parseNumericValue($overrideValue);
            if ($parsedOverrideValue === null) {
                return false;
            }

            return FeatureFlag::compare($parsedOverrideValue, $parsedValue, $operator);
        }

        if (in_array($operator, ["is_date_before", "is_date_after"])) {
            $parsedDate = FeatureFlag::parseDateFilter($value);

            $overrideDate = FeatureFlag::convertToDateTime($overrideValue, UnresolvedFlagReason::MISSING_CONTEXT);
            if ($operator == 'is_date_before') {
                return $overrideDate < $parsedDate;
            } else {
                return $overrideDate > $parsedDate;
            }
        }

        // Semver operators. The filter is parsed before the context, so a bad filter reports
        // unsupported_definition even when the context value is bad too.
        if (in_array($operator, ["semver_eq", "semver_neq", "semver_gt", "semver_gte", "semver_lt", "semver_lte"])) {
            $valueTuple = FeatureFlag::parseSemver($value);
            $overrideTuple = FeatureFlag::parseContextSemver($overrideValue);

            $comparison = FeatureFlag::compareSemverTuples($overrideTuple, $valueTuple);

            if ($operator === "semver_eq") {
                return $comparison === 0;
            } elseif ($operator === "semver_neq") {
                return $comparison !== 0;
            } elseif ($operator === "semver_gt") {
                return $comparison > 0;
            } elseif ($operator === "semver_gte") {
                return $comparison >= 0;
            } elseif ($operator === "semver_lt") {
                return $comparison < 0;
            } elseif ($operator === "semver_lte") {
                return $comparison <= 0;
            }
        }

        if ($operator === "semver_tilde") {
            list($lower, $upper) = FeatureFlag::tildeBounds($value);
            $overrideTuple = FeatureFlag::parseContextSemver($overrideValue);

            return FeatureFlag::compareSemverTuples($overrideTuple, $lower) >= 0
                && FeatureFlag::compareSemverTuples($overrideTuple, $upper) < 0;
        }

        if ($operator === "semver_caret") {
            list($lower, $upper) = FeatureFlag::caretBounds($value);
            $overrideTuple = FeatureFlag::parseContextSemver($overrideValue);

            return FeatureFlag::compareSemverTuples($overrideTuple, $lower) >= 0
                && FeatureFlag::compareSemverTuples($overrideTuple, $upper) < 0;
        }

        if ($operator === "semver_wildcard") {
            list($lower, $upper) = FeatureFlag::wildcardBounds($value);
            $overrideTuple = FeatureFlag::parseContextSemver($overrideValue);

            return FeatureFlag::compareSemverTuples($overrideTuple, $lower) >= 0
                && FeatureFlag::compareSemverTuples($overrideTuple, $upper) < 0;
        }

        throw new InconclusiveMatchException(
            "Unknown operator: " . $operator,
            reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
        );
    }

    /**
     * Match a cohort property filter against provided property values.
     *
     * @param array<string, mixed> $property Cohort property filter.
     * @param array<string, mixed> $propertyValues Available property values keyed by property name.
     * @param array<string, mixed> $cohortProperties Local cohort definitions keyed by cohort ID.
     * @param array<string, array<string, mixed>>|null $flagsByKey Local feature flag definitions keyed by key.
     * @param array<string, mixed>|null $evaluationCache Cache used for flag dependency evaluation.
     * @param string|null $distinctId Distinct ID used for nested flag dependency evaluation.
     * @param mixed $propertyMatchingVersion Definition snapshot selector; defaults to legacy matching.
     * @return bool Whether the cohort matches.
     * @throws RequiresServerEvaluationException When the cohort requires server-side data.
     * @throws InconclusiveMatchException When the cohort cannot be evaluated locally.
     */
    public static function matchCohort(
        $property,
        $propertyValues,
        $cohortProperties,
        $flagsByKey = null,
        $evaluationCache = null,
        $distinctId = null,
        $propertyMatchingVersion = 1
    ) {
        return FeatureFlag::matchCohortForIdentity(
            $property,
            $propertyValues,
            $cohortProperties,
            $flagsByKey,
            $evaluationCache,
            $distinctId,
            $propertyMatchingVersion,
            false
        );
    }

    /** @param int|string|null|false $identityAggregation See evaluateFlagDependencyForIdentity(). */
    private static function matchCohortForIdentity(
        $property,
        $propertyValues,
        $cohortProperties,
        $flagsByKey,
        &$evaluationCache,
        $distinctId,
        $propertyMatchingVersion,
        $identityAggregation
    ) {
        $cohortId = strval($property["value"]);
        if (!array_key_exists($cohortId, $cohortProperties)) {
            throw new RequiresServerEvaluationException(
                "cohort {$cohortId} not found in local cohorts - " .
                "likely a static cohort that requires server evaluation"
            );
        }

        $propertyGroup = $cohortProperties[$cohortId];
        return FeatureFlag::matchPropertyGroupForIdentity(
            $propertyGroup,
            $propertyValues,
            $cohortProperties,
            $flagsByKey,
            $evaluationCache,
            $distinctId,
            $propertyMatchingVersion,
            $identityAggregation
        );
    }

    /**
     * Match a property group against provided property values.
     *
     * @param array<string, mixed>|null $propertyGroup Property group definition.
     * @param array<string, mixed> $propertyValues Available property values keyed by property name.
     * @param array<string, mixed> $cohortProperties Local cohort definitions keyed by cohort ID.
     * @param array<string, array<string, mixed>>|null $flagsByKey Local feature flag definitions keyed by key.
     * @param array<string, mixed>|null $evaluationCache Cache used for flag dependency evaluation.
     * @param string|null $distinctId Distinct ID used for nested flag dependency evaluation.
     * @param mixed $propertyMatchingVersion Definition snapshot selector; defaults to legacy matching.
     * @return bool Whether the property group matches.
     * @throws RequiresServerEvaluationException When server-side data is required.
     * @throws InconclusiveMatchException When the group cannot be evaluated locally.
     */
    public static function matchPropertyGroup(
        $propertyGroup,
        $propertyValues,
        $cohortProperties,
        $flagsByKey = null,
        $evaluationCache = null,
        $distinctId = null,
        $propertyMatchingVersion = 1
    ) {
        return FeatureFlag::matchPropertyGroupForIdentity(
            $propertyGroup,
            $propertyValues,
            $cohortProperties,
            $flagsByKey,
            $evaluationCache,
            $distinctId,
            $propertyMatchingVersion,
            false
        );
    }

    /** @param int|string|null|false $identityAggregation See evaluateFlagDependencyForIdentity(). */
    private static function matchPropertyGroupForIdentity(
        $propertyGroup,
        $propertyValues,
        $cohortProperties,
        $flagsByKey,
        &$evaluationCache,
        $distinctId,
        $propertyMatchingVersion,
        $identityAggregation
    ) {
        if (!$propertyGroup) {
            return true;
        }

        $propertyGroupType = $propertyGroup["type"];
        $properties = $propertyGroup["values"];

        if (!$properties || count($properties) === 0) {
            // empty groups are no-ops, always match
            return true;
        }

        $inconclusiveReason = null;

        if (array_key_exists("values", $properties[0])) {
            // a nested property group
            foreach ($properties as $prop) {
                try {
                    $matches = FeatureFlag::matchPropertyGroupForIdentity(
                        $prop,
                        $propertyValues,
                        $cohortProperties,
                        $flagsByKey,
                        $evaluationCache,
                        $distinctId,
                        $propertyMatchingVersion,
                        $identityAggregation
                    );
                    if ($propertyGroupType === 'AND') {
                        if (!$matches) {
                            return false;
                        }
                    } else {
                        // OR group
                        if ($matches) {
                            return true;
                        }
                    }
                } catch (RequiresServerEvaluationException $err) {
                    // Immediately propagate - this condition requires server-side data
                    throw $err;
                } catch (InconclusiveMatchException $err) {
                    $inconclusiveReason = FeatureFlag::pickUnresolvedReason($inconclusiveReason, $err->getReason());
                }
            }

            if ($inconclusiveReason !== null) {
                throw new InconclusiveMatchException(
                    "Can't match cohort without a given cohort property value",
                    reason: $inconclusiveReason
                );
            }
            // if we get here, all matched in AND case, or none matched in OR case
            return $propertyGroupType === 'AND';
        } else {
            foreach ($properties as $prop) {
                try {
                    $matches = false;
                    $propType = $prop["type"] ?? null;
                    if ($propType === 'cohort') {
                        $matches = FeatureFlag::matchCohortForIdentity(
                            $prop,
                            $propertyValues,
                            $cohortProperties,
                            $flagsByKey,
                            $evaluationCache,
                            $distinctId,
                            $propertyMatchingVersion,
                            $identityAggregation
                        );
                    } elseif ($propType === 'flag') {
                        $matches = FeatureFlag::evaluateFlagDependencyForIdentity(
                            $prop,
                            $flagsByKey,
                            $evaluationCache,
                            $distinctId,
                            $propertyValues,
                            $cohortProperties,
                            $propertyMatchingVersion,
                            $identityAggregation
                        );
                    } else {
                        $matches = FeatureFlag::matchProperty($prop, $propertyValues, $propertyMatchingVersion);
                    }

                    $negation = $prop["negation"] ?? false;

                    if ($propertyGroupType === 'AND') {
                        // if negated property, do the inverse
                        if (!$matches && !$negation) {
                            return false;
                        }
                        if ($matches && $negation) {
                            return false;
                        }
                    } else {
                        // OR group
                        if ($matches && !$negation) {
                            return true;
                        }
                        if (!$matches && $negation) {
                            return true;
                        }
                    }
                } catch (RequiresServerEvaluationException $err) {
                    // Immediately propagate - this condition requires server-side data
                    throw $err;
                } catch (InconclusiveMatchException $err) {
                    $inconclusiveReason = FeatureFlag::pickUnresolvedReason($inconclusiveReason, $err->getReason());
                }
            }

            if ($inconclusiveReason !== null) {
                throw new InconclusiveMatchException(
                    "can't match cohort without a given cohort property value",
                    reason: $inconclusiveReason
                );
            }

            // if we get here, all matched in AND case, or none matched in OR case
            return $propertyGroupType === 'AND';
        }
    }

    /**
     * Parse a PostHog relative date string for feature flag matching.
     *
     * @param mixed $value Relative date string such as `1h`, `7d`, `2w`, `3m`, or `1y`.
     * @return \DateTime|null Parsed UTC date, or null when the value is invalid.
     */
    public static function relativeDateParseForFeatureFlagMatching($value)
    {
        $regex = "/^-?(?<number>[0-9]+)(?<interval>[a-z])$/";
        $parsedDt = \DateTime::createFromInterface(Clock::get()->now())->setTimezone(new \DateTimeZone("UTC"));
        if (preg_match($regex, $value, $matches)) {
            $number = intval($matches["number"]);

            if ($number >= 10_000) {
                // Guard against overflow, disallow numbers greater than 10_000
                return null;
            }

            $interval = $matches["interval"];
            if ($interval == "h") {
                $parsedDt->sub(new \DateInterval("PT{$number}H"));
            } elseif ($interval == "d") {
                $parsedDt->sub(new \DateInterval("P{$number}D"));
            } elseif ($interval == "w") {
                $parsedDt->sub(new \DateInterval("P{$number}W"));
            } elseif ($interval == "m") {
                $parsedDt->sub(new \DateInterval("P{$number}M"));
            } elseif ($interval == "y") {
                $parsedDt->sub(new \DateInterval("P{$number}Y"));
            } else {
                return null;
            }

            return $parsedDt;
        } else {
            return null;
        }
    }

    /**
     * Parse a semver numeric identifier (e.g., the "1" in "1.2.3").
     *
     * @throws InconclusiveMatchException If the part is empty, non-numeric, or has a leading zero.
     */
    private static function parseSemverNumeric(string $part, string $component, $value): int
    {
        // Semver 2.0.0 §2: numeric identifiers MUST NOT include leading zeros.
        if ($part === "" || !ctype_digit($part)) {
            throw new InconclusiveMatchException(
                "Cannot parse semver: invalid {$component} version in {$value}",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }
        if (strlen($part) > 1 && $part[0] === "0") {
            throw new InconclusiveMatchException(
                "Cannot parse semver: {$component} version has leading zero in {$value}",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }
        return intval($part);
    }

    /**
     * Parse a semver string into a tuple of [major, minor, patch].
     *
     * Rules:
     * 1. Strip leading/trailing whitespace
     * 2. Strip `v` or `V` prefix (e.g., "v1.2.3" → "1.2.3")
     * 3. Strip pre-release and build metadata suffixes (split on `-` or `+`, take first part)
     * 4. Split on `.` and parse first 3 components as integers
     * 5. Default missing components to 0 (e.g., "1.2" → (1, 2, 0), "1" → (1, 0, 0))
     * 6. Ignore extra components beyond the third (e.g., "1.2.3.4" → (1, 2, 3))
     * 7. Reject numeric identifiers with leading zeros per semver 2.0.0 §2
     * 8. Throw InconclusiveMatchException for invalid input (empty string, non-numeric parts, leading dot)
     *
     * @param mixed $value The semver string to parse
     * @return array{int, int, int} The parsed tuple [major, minor, patch]
     * @throws InconclusiveMatchException If the value cannot be parsed as semver
     */
    public static function parseSemver($value): array
    {
        if ($value === null || $value === "") {
            throw new InconclusiveMatchException(
                "Cannot parse empty or null value as semver",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        if (!is_scalar($value)) {
            throw new InconclusiveMatchException(
                "Cannot parse a non-scalar value as semver",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        $text = trim(strval($value));

        if ($text === "") {
            throw new InconclusiveMatchException(
                "Cannot parse empty value as semver",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        // Strip v/V prefix
        $text = ltrim($text, "vV");

        if ($text === "") {
            throw new InconclusiveMatchException(
                "Cannot parse semver: only prefix found",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        // Strip pre-release and build metadata (split on - or +, take first part)
        $text = preg_split('/[-+]/', $text, 2)[0];

        // Check for leading dot
        if (str_starts_with($text, ".")) {
            throw new InconclusiveMatchException(
                "Cannot parse semver with leading dot: {$value}",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        // Split on dots
        $parts = explode(".", $text);

        $major = FeatureFlag::parseSemverNumeric($parts[0] ?? "", "major", $value);

        $minor = 0;
        if (isset($parts[1]) && $parts[1] !== "") {
            $minor = FeatureFlag::parseSemverNumeric($parts[1], "minor", $value);
        }

        $patch = 0;
        if (isset($parts[2]) && $parts[2] !== "") {
            $patch = FeatureFlag::parseSemverNumeric($parts[2], "patch", $value);
        }

        return [$major, $minor, $patch];
    }

    /**
     * Compare two semver tuples.
     *
     * @param array{int, int, int} $a First tuple
     * @param array{int, int, int} $b Second tuple
     * @return int -1 if a < b, 0 if a == b, 1 if a > b
     */
    private static function compareSemverTuples(array $a, array $b): int
    {
        if ($a[0] !== $b[0]) {
            return $a[0] <=> $b[0];
        }
        if ($a[1] !== $b[1]) {
            return $a[1] <=> $b[1];
        }
        return $a[2] <=> $b[2];
    }

    /**
     * Calculate tilde bounds for semver matching.
     * ~X.Y.Z means >=X.Y.Z and <X.(Y+1).0
     *
     * @param mixed $value The semver pattern
     * @return array{array{int, int, int}, array{int, int, int}} [lower, upper] bounds
     */
    private static function tildeBounds($value): array
    {
        $tuple = FeatureFlag::parseSemver($value);
        $lower = $tuple;
        $upper = [$tuple[0], $tuple[1] + 1, 0];
        return [$lower, $upper];
    }

    /**
     * Calculate caret bounds for semver matching.
     * ^X.Y.Z where:
     * - X > 0: >=X.Y.Z <(X+1).0.0
     * - X == 0, Y > 0: >=0.Y.Z <0.(Y+1).0
     * - X == 0, Y == 0: >=0.0.Z <0.0.(Z+1)
     *
     * @param mixed $value The semver pattern
     * @return array{array{int, int, int}, array{int, int, int}} [lower, upper] bounds
     */
    private static function caretBounds($value): array
    {
        $tuple = FeatureFlag::parseSemver($value);
        $lower = $tuple;

        if ($tuple[0] > 0) {
            $upper = [$tuple[0] + 1, 0, 0];
        } elseif ($tuple[1] > 0) {
            $upper = [0, $tuple[1] + 1, 0];
        } else {
            $upper = [0, 0, $tuple[2] + 1];
        }

        return [$lower, $upper];
    }

    /**
     * Calculate wildcard bounds for semver matching.
     * X.Y.* means >=X.Y.0 <X.(Y+1).0
     * X.* means >=X.0.0 <(X+1).0.0
     *
     * @param mixed $value The semver pattern with wildcard
     * @return array{array{int, int, int}, array{int, int, int}} [lower, upper] bounds
     */
    private static function wildcardBounds($value): array
    {
        if ($value === null || $value === "") {
            throw new InconclusiveMatchException(
                "Cannot parse empty or null value as semver wildcard",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        if (!is_scalar($value)) {
            throw new InconclusiveMatchException(
                "Cannot parse a non-scalar value as semver wildcard",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        $text = trim(strval($value));

        // Strip v/V prefix
        $text = ltrim($text, "vV");

        // Split on dots
        $parts = explode(".", $text);

        // Remove trailing wildcard parts and empty parts
        while (count($parts) > 0 && (end($parts) === "*" || end($parts) === "x" || end($parts) === "X" || end($parts) === "")) {
            array_pop($parts);
        }

        if (count($parts) === 0) {
            throw new InconclusiveMatchException(
                "Cannot parse semver wildcard: no version components found in {$value}",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        $major = FeatureFlag::parseSemverNumeric($parts[0], "major", $value);

        if (count($parts) === 1) {
            // X.* pattern
            $lower = [$major, 0, 0];
            $upper = [$major + 1, 0, 0];
        } else {
            // X.Y.* pattern
            $minor = FeatureFlag::parseSemverNumeric($parts[1], "minor", $value);
            $lower = [$major, $minor, 0];
            $upper = [$major, $minor + 1, 0];
        }

        return [$lower, $upper];
    }

    private static function parseDateFilter($value): \DateTime
    {
        $parsedDate = is_string($value) ? FeatureFlag::relativeDateParseForFeatureFlagMatching($value) : null;

        return $parsedDate ?? FeatureFlag::convertToDateTime($value, UnresolvedFlagReason::UNSUPPORTED_DEFINITION);
    }

    /**
     * @param string $reason Reason for an unusable value: unsupported_definition for a filter value,
     *     missing_context for a value the evaluation call passed.
     */
    private static function convertToDateTime($value, string $reason): \DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }

        if (!is_string($value)) {
            throw new InconclusiveMatchException(
                "The date provided " . FeatureFlag::valueToString($value) . " must be a string or date object",
                reason: $reason
            );
        }

        try {
            return new \DateTime($value);
        } catch (\Exception $e) {
            throw new InconclusiveMatchException(
                "{$value} is in an invalid date format: " . $e->getMessage(),
                0,
                $e,
                $reason
            );
        }
    }

    private static function parseNumericFilter($value): float
    {
        $parsedValue = FeatureFlag::parseNumericValue($value);
        if ($parsedValue === null) {
            throw new InconclusiveMatchException(
                "The number set on the flag is not a valid format",
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        return $parsedValue;
    }

    /**
     * Parse a semver value read from the evaluation call: failing to use it is missing context,
     * not an unsupported definition.
     *
     * @return array{int, int, int}
     */
    private static function parseContextSemver($value): array
    {
        try {
            return FeatureFlag::parseSemver($value);
        } catch (InconclusiveMatchException $e) {
            throw new InconclusiveMatchException($e->getMessage(), 0, $e, UnresolvedFlagReason::MISSING_CONTEXT);
        }
    }

    private static function computeExactMatch($value, $overrideValue, $propertyMatchingVersion)
    {
        if ($propertyMatchingVersion !== 2 && FeatureFlag::isTruthyOrFalsyPropertyValue($value)) {
            return FeatureFlag::isTruthyPropertyValue($value) === FeatureFlag::isTruthyPropertyValue($overrideValue);
        }

        if ($value === []) {
            return FeatureFlag::isTruthyPropertyValue($overrideValue);
        }

        $overrideString = FeatureFlag::unicodeLowercase(FeatureFlag::valueToString($overrideValue));
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $candidate) {
                if (FeatureFlag::unicodeLowercase(FeatureFlag::valueToString($candidate)) === $overrideString) {
                    return true;
                }
            }
            return false;
        }
        return FeatureFlag::unicodeLowercase(FeatureFlag::valueToString($value)) === $overrideString;
    }

    private static function isTruthyOrFalsyPropertyValue($value)
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ["true", "false"], true);
        }
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $candidate) {
                if (!FeatureFlag::isTruthyOrFalsyPropertyValue($candidate)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private static function isTruthyPropertyValue($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strtolower($value) === "true";
        }
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $candidate) {
                if (!FeatureFlag::isTruthyPropertyValue($candidate)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private static function unicodeLowercase($value)
    {
        $mode = defined('MB_CASE_LOWER_SIMPLE') ? MB_CASE_LOWER_SIMPLE : MB_CASE_LOWER;
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return mb_convert_case($value, $mode, "UTF-8");
        }

        foreach ($characters as $index => $character) {
            if ($character !== "Σ") {
                continue;
            }

            // PHP 8.2+'s PCRE2 supports the Cased and Case_Ignorable
            // derived properties used by Unicode's Final_Sigma rule.
            $precededByCased = false;
            for ($before = $index - 1; $before >= 0; $before--) {
                if (preg_match('/^\p{Case_Ignorable}$/u', $characters[$before])) {
                    continue;
                }
                $precededByCased = preg_match('/^\p{Cased}$/u', $characters[$before]) === 1;
                break;
            }

            $followedByCased = false;
            for ($after = $index + 1; $after < count($characters); $after++) {
                if (preg_match('/^\p{Case_Ignorable}$/u', $characters[$after])) {
                    continue;
                }
                $followedByCased = preg_match('/^\p{Cased}$/u', $characters[$after]) === 1;
                break;
            }

            $characters[$index] = $precededByCased && !$followedByCased ? "ς" : "σ";
        }

        // U+0130 is the unconditional multi-code-point lowercase mapping that
        // PHP's simple mode omits.
        $value = str_replace("\u{0130}", "i\u{0307}", implode('', $characters));
        return mb_convert_case($value, $mode, "UTF-8");
    }

    private static function valueToString($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? "true" : "false";
        }
        if (is_null($value)) {
            return "null";
        }
        if (is_float($value)) {
            return FeatureFlag::floatToString($value);
        }
        if (is_array($value) || is_object($value)) {
            return FeatureFlag::jsonValueToString($value);
        }
        return strval($value);
    }

    private static function jsonValueToString($value)
    {
        $jsonFlags = JSON_UNESCAPED_LINE_TERMINATORS
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR;

        if (is_string($value)) {
            return json_encode($value, $jsonFlags);
        }
        if (is_bool($value)) {
            return $value ? "true" : "false";
        }
        if (is_null($value)) {
            return "null";
        }
        if (is_float($value)) {
            return FeatureFlag::floatToString($value);
        }
        if (is_int($value)) {
            return strval($value);
        }
        if ($value instanceof \JsonSerializable) {
            return FeatureFlag::jsonValueToString($value->jsonSerialize());
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
            $isList = false;
        } else {
            $isList = array_is_list($value);
        }

        if ($isList) {
            $items = array_map(fn($item) => FeatureFlag::jsonValueToString($item), $value);
            return '[' . implode(',', $items) . ']';
        }

        ksort($value, SORT_STRING);
        $items = [];
        foreach ($value as $key => $item) {
            $encodedKey = json_encode(strval($key), $jsonFlags);
            $items[] = $encodedKey . ':' . FeatureFlag::jsonValueToString($item);
        }
        return '{' . implode(',', $items) . '}';
    }

    private static function floatToString($value)
    {
        if (!is_finite($value)) {
            return strval($value);
        }

        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if ($value == 0.0) {
            return str_starts_with($encoded, '-') ? "-0.0" : "0.0";
        }

        preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i', $encoded, $parts);
        $sign = $parts[1];
        $integer = $parts[2];
        $fraction = $parts[3] ?? '';
        $encodedExponent = intval($parts[4] ?? 0);
        $digits = $integer . $fraction;
        $firstNonZero = strspn($digits, '0');
        $digits = rtrim(substr($digits, $firstNonZero), '0');
        $decimalPosition = strlen($integer) + $encodedExponent;
        $exponent = $decimalPosition - $firstNonZero - 1;

        if ($exponent >= 16 || $exponent <= -6) {
            $mantissa = $digits[0];
            if (strlen($digits) > 1) {
                $mantissa .= '.' . substr($digits, 1);
            }
            return $sign . $mantissa . 'e' . ($exponent >= 0 ? '+' : '') . $exponent;
        }

        $position = $exponent + 1;
        if ($position <= 0) {
            return $sign . '0.' . str_repeat('0', -$position) . $digits;
        }
        if ($position >= strlen($digits)) {
            return $sign . $digits . str_repeat('0', $position - strlen($digits)) . '.0';
        }
        return $sign . substr($digits, 0, $position) . '.' . substr($digits, $position);
    }

    private static function parseNumericValue($value): ?float
    {
        if (is_string($value)) {
            if (preg_match('/\A[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?\z/', $value) !== 1) {
                return null;
            }
        } elseif (!is_int($value) && !is_float($value)) {
            return null;
        }

        $parsedValue = (float) $value;
        return is_finite($parsedValue) ? $parsedValue : null;
    }

    private static function compare(float $lhs, float $rhs, string $operator): bool
    {
        $comparison = $lhs <=> $rhs;

        if ($operator == "gt") {
            return $comparison > 0;
        } elseif ($operator == "gte") {
            return $comparison >= 0;
        } elseif ($operator == "lt") {
            return $comparison < 0;
        } elseif ($operator == "lte") {
            return $comparison <= 0;
        }

        throw new \Exception("Invalid operator: " . $operator);
    }

    private static function hash($key, $distinctId, $salt = "")
    {
        $hashKey = sprintf("%s.%s%s", $key, $distinctId, $salt);
        $hashVal = base_convert(substr(sha1($hashKey), 0, 15), 16, 10);

        return $hashVal / self::LONG_SCALE;
    }

    /**
     * Resolve the holdout variant for a flag, when its bucketing identity falls inside the holdout.
     *
     * @param mixed $holdout The flag's filters.holdout configuration, if any.
     * @param string $bucketingValue Flag-level bucketing identity (distinct id or group key).
     * @return string|null The "holdout-<id>" variant, or null when no holdout applies.
     */
    private static function matchHoldout($holdout, $bucketingValue)
    {
        $percentage = FeatureFlag::holdoutPercentage($holdout);
        if (is_null($percentage)) {
            return null;
        }

        $holdoutKey = "holdout-" . $holdout["id"];

        if ($percentage >= 100.0) {
            return $holdoutKey;
        }

        return FeatureFlag::holdoutHash($bucketingValue) <= $percentage / 100 ? $holdoutKey : null;
    }

    /**
     * @param mixed $holdout The flag's filters.holdout configuration, if any.
     * @return float|null Exclusion percentage clamped to 0-100, or null when the holdout is unusable.
     */
    private static function holdoutPercentage($holdout): ?float
    {
        if (!is_array($holdout) || is_null($holdout["id"] ?? null)) {
            return null;
        }

        $exclusionPercentage = $holdout["exclusion_percentage"] ?? null;
        if (!is_numeric($exclusionPercentage)) {
            return null;
        }

        // Fractional percentages are preserved; only the 0-100 range is clamped.
        return min(100.0, max(0.0, (float) $exclusionPercentage));
    }

    private static function hasActiveHoldout($holdout): bool
    {
        return (FeatureFlag::holdoutPercentage($holdout) ?? 0.0) > 0.0;
    }

    private static function aggregationKey($aggregation): ?string
    {
        return is_null($aggregation) ? null : strval($aggregation);
    }

    /**
     * Hash a bucketing identity for holdout membership, matching the backend.
     *
     * Unlike the ordinary flag hash, neither the flag key nor the holdout id participates:
     * the digest is taken over "holdout-<bucketing_value>" with no separator or salt.
     *
     * @param string $bucketingValue Flag-level bucketing identity (distinct id or group key).
     * @return float Hash in the range [0, 1).
     */
    private static function holdoutHash($bucketingValue)
    {
        $hashVal = base_convert(substr(sha1("holdout-" . $bucketingValue), 0, 15), 16, 10);

        return $hashVal / self::LONG_SCALE;
    }

    private static function getMatchingVariant($flag, $distinctId)
    {
        $variants = FeatureFlag::variantLookupTable($flag);

        foreach ($variants as $variant) {
            if (
                FeatureFlag::hash($flag["key"], $distinctId, "variant") >= $variant["value_min"]
                && FeatureFlag::hash($flag["key"], $distinctId, "variant") < $variant["value_max"]
            ) {
                return $variant["key"];
            }
        }

        return null;
    }

    private static function variantLookupTable($featureFlag)
    {
        $lookupTable = [];
        $valueMin = 0;
        $multivariates = (($featureFlag['filters'] ?? [])['multivariate'] ?? [])['variants'] ?? [];

        foreach ($multivariates as $variant) {
            $valueMax = $valueMin + $variant["rollout_percentage"] / 100;

            array_push($lookupTable, [
                "value_min" => $valueMin,
                "value_max" => $valueMax,
                "key" => $variant["key"]
            ]);
            $valueMin = $valueMax;
        }

        return $lookupTable;
    }

    /**
     * Match a full feature flag definition for a distinct id and properties.
     *
     * @param array<string, mixed> $flag Feature flag definition.
     * @param string $distinctId Distinct ID or group key used for bucketing.
     * @param array<string, mixed> $properties Person or group properties for evaluation.
     * @param array<string, mixed> $cohorts Local cohort definitions keyed by cohort ID.
     * @param array<string, array<string, mixed>>|null $flagsByKey Local feature flag definitions keyed by key.
     * @param array<string, mixed>|null $evaluationCache Cache used for flag dependency evaluation.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @param array<string, array<string, mixed>> $groupProperties Group properties for evaluation.
     * @param array<string, string> $groupTypeMapping Mapping from group type index to group type name.
     * @param mixed $propertyMatchingVersion Definition snapshot selector; defaults to legacy matching.
     * @return bool|string False for disabled, true for enabled boolean flags, or variant key.
     * @throws RequiresServerEvaluationException When server-side data is required.
     * @throws InconclusiveMatchException When the flag cannot be evaluated locally.
     */
    public static function matchFeatureFlagProperties(
        $flag,
        $distinctId,
        $properties,
        $cohorts = [],
        $flagsByKey = null,
        $evaluationCache = null,
        $groups = [],
        $groupProperties = [],
        $groupTypeMapping = [],
        $propertyMatchingVersion = 1
    ) {
        return FeatureFlag::matchFeatureFlagPropertiesForIdentity(
            $flag,
            $distinctId,
            $properties,
            $cohorts,
            $flagsByKey,
            $evaluationCache,
            $groups,
            $groupProperties,
            $groupTypeMapping,
            $propertyMatchingVersion,
            ($flag["filters"] ?? [])["aggregation_group_type_index"] ?? null
        );
    }

    /**
     * Same as matchFeatureFlagProperties, but bucketing by an identity whose aggregation is already known.
     * Dependency recursion keeps the root flag's identity, which may differ from the dependency's own.
     *
     * @param int|string|null $identityAggregation Null for a person, a group type index otherwise.
     */
    private static function matchFeatureFlagPropertiesForIdentity(
        $flag,
        $distinctId,
        $properties,
        $cohorts,
        $flagsByKey,
        &$evaluationCache,
        $groups,
        $groupProperties,
        $groupTypeMapping,
        $propertyMatchingVersion,
        $identityAggregation
    ) {
        $flagFilters = $flag["filters"] ?? [];

        // Experiment holdouts win over every release condition, variant override, and rollout.
        $holdoutVariant = FeatureFlag::matchHoldout($flagFilters["holdout"] ?? null, $distinctId);
        if (!is_null($holdoutVariant)) {
            return $holdoutVariant;
        }

        $flagConditions = $flagFilters["groups"] ?? [];
        $flagAggregation = $flagFilters["aggregation_group_type_index"] ?? null;
        $earlyExitEnabled = $flagFilters["early_exit"] ?? false;
        $inconclusiveReason = null;
        $groupEvaluationCaches = [];

        foreach ($flagConditions as $conditionIndex => $condition) {
            try {
                // Per-condition aggregation overrides only when the condition explicitly
                // sets its own aggregation_group_type_index (mixed targeting). When absent,
                // use the properties/bucketing already resolved by the caller.
                $hasConditionAggregation = array_key_exists("aggregation_group_type_index", $condition);
                $conditionAggregation = $hasConditionAggregation
                    ? $condition["aggregation_group_type_index"]
                    : $flagAggregation;

                $effectiveProperties = $properties;
                $effectiveBucketing = $distinctId;
                $effectiveAggregation = $identityAggregation;
                $conditionEvaluationCache = &$evaluationCache;

                // Mixed-override path: condition-level aggregation differs from flag-level.
                // This assumes flag-level aggregation is null for mixed flags.
                if ($conditionAggregation !== $flagAggregation) {
                    if (!is_null($conditionAggregation)) {
                        $groupName = $groupTypeMapping[strval($conditionAggregation)] ?? null;
                        if (is_null($groupName) || !array_key_exists($groupName, $groups)) {
                            continue;
                        }
                        if (!array_key_exists($groupName, $groupProperties)) {
                            $inconclusiveReason = FeatureFlag::pickUnresolvedReason(
                                $inconclusiveReason,
                                UnresolvedFlagReason::MISSING_CONTEXT
                            );
                            continue;
                        }
                        $effectiveProperties = $groupProperties[$groupName];
                        $effectiveBucketing = $groups[$groupName];
                        $effectiveAggregation = $conditionAggregation;
                        // Dependency results depend on the bucketing identity, so each group keeps its own.
                        $groupEvaluationCaches[$groupName] ??= is_null($evaluationCache) ? null : [];
                        $conditionEvaluationCache = &$groupEvaluationCaches[$groupName];
                    }
                }

                $matchResult = FeatureFlag::isConditionMatch(
                    $flag,
                    $effectiveBucketing,
                    $condition,
                    $effectiveProperties,
                    $cohorts,
                    $flagsByKey,
                    $conditionEvaluationCache,
                    $propertyMatchingVersion,
                    $effectiveAggregation
                );

                if ($matchResult === ConditionMatch::Match) {
                    $variantOverride = $condition["variant"] ?? null;
                    $flagVariants = ($flagFilters["multivariate"] ?? [])["variants"] ?? [];
                    $variantKeys = array_map(function ($variant) {
                        return $variant["key"];
                    }, $flagVariants);

                    if ($variantOverride && in_array($variantOverride, $variantKeys)) {
                        return $variantOverride;
                    } else {
                        return FeatureFlag::getMatchingVariant($flag, $effectiveBucketing) ?? true;
                    }
                } elseif ($earlyExitEnabled && $matchResult === ConditionMatch::OutOfRolloutBound) {
                    // The condition's property filters (if any) matched and only the rollout check
                    // failed, so re-evaluating later groups can't change the outcome. Return a
                    // definitive false immediately, mirroring the server-side (Rust) engine.
                    // But if a prior condition was inconclusive, we can't be definitive — stop
                    // evaluating (early exit still applies to later groups) and let the
                    // post-loop inconclusive check fall back to server evaluation. A throw here
                    // would be swallowed by the catch below and evaluation would wrongly continue.
                    if ($inconclusiveReason !== null) {
                        break;
                    }
                    return false;
                }
            } catch (RequiresServerEvaluationException $e) {
                // Immediately propagate - this condition requires server-side data. Later conditions
                // are not evaluated, so their definitions still count toward the reason.
                $reason = FeatureFlag::pickUnresolvedReason($inconclusiveReason, $e->getReason());
                $laterReason = FeatureFlag::conditionsDefinitionReason(
                    array_slice($flagConditions, $conditionIndex + 1),
                    $flagsByKey ?? [],
                    $cohorts ?? []
                );
                if ($laterReason !== null) {
                    $reason = FeatureFlag::pickUnresolvedReason($reason, $laterReason);
                }
                throw new RequiresServerEvaluationException($e->getMessage(), 0, $e, $reason);
            } catch (InconclusiveMatchException $e) {
                $inconclusiveReason = FeatureFlag::pickUnresolvedReason($inconclusiveReason, $e->getReason());
            }
        }

        if ($inconclusiveReason !== null) {
            throw new InconclusiveMatchException(
                "Can't determine if feature flag is enabled or not with given properties",
                reason: $inconclusiveReason
            );
        }

        return false;
    }

    private static function isConditionMatch(
        $featureFlag,
        $distinctId,
        $condition,
        $properties,
        $cohorts,
        $flagsByKey,
        &$evaluationCache,
        $propertyMatchingVersion,
        $identityAggregation
    ): ConditionMatch {
        $rolloutPercentage = array_key_exists("rollout_percentage", $condition) ? $condition["rollout_percentage"] : null;

        if (count($condition['properties'] ?? []) > 0) {
            // Filters in a condition have no defined order, so an inconclusive filter does not stop
            // the loop: a later filter can still rule the condition out or carry a higher-precedence reason.
            $inconclusiveReason = null;
            $serverEvaluationError = null;
            foreach ($condition['properties'] as $property) {
                $propertyType = $property['type'] ?? null;
                try {
                    if ($propertyType == 'cohort') {
                        $matches = FeatureFlag::matchCohortForIdentity(
                            $property,
                            $properties,
                            $cohorts,
                            $flagsByKey,
                            $evaluationCache,
                            $distinctId,
                            $propertyMatchingVersion,
                            $identityAggregation
                        );
                    } elseif ($propertyType == 'flag') {
                        $matches = FeatureFlag::evaluateFlagDependencyForIdentity(
                            $property,
                            $flagsByKey,
                            $evaluationCache,
                            $distinctId,
                            $properties,
                            $cohorts,
                            $propertyMatchingVersion,
                            $identityAggregation
                        );
                    } else {
                        $matches = FeatureFlag::matchProperty($property, $properties, $propertyMatchingVersion);
                    }
                } catch (RequiresServerEvaluationException $e) {
                    // Fall back to the server only when this is the first filter that could not be
                    // checked, as when the loop stopped there. After another inconclusive filter,
                    // later conditions must still be able to resolve the flag locally.
                    if ($inconclusiveReason === null) {
                        $serverEvaluationError = $e;
                    }
                    $inconclusiveReason = FeatureFlag::pickUnresolvedReason($inconclusiveReason, $e->getReason());
                    continue;
                } catch (InconclusiveMatchException $e) {
                    $inconclusiveReason = FeatureFlag::pickUnresolvedReason($inconclusiveReason, $e->getReason());
                    continue;
                } catch (\Throwable $e) {
                    // After an inconclusive filter the condition is already inconclusive, so an unexpected
                    // error must not fail the whole flag and stop later conditions from resolving it.
                    if ($inconclusiveReason === null) {
                        throw $e;
                    }
                    break;
                }

                if (!$matches) {
                    return ConditionMatch::NoMatch;
                }
            }

            // Rethrow the server error type: the caller stops at it and falls back to the server
            // instead of trying later conditions. Later filters in this condition can still
            // outrank its reason.
            if ($serverEvaluationError !== null) {
                throw new RequiresServerEvaluationException(
                    $serverEvaluationError->getMessage(),
                    0,
                    $serverEvaluationError,
                    $inconclusiveReason
                );
            }
            if ($inconclusiveReason !== null) {
                throw new InconclusiveMatchException(
                    sprintf("Can't determine if a condition of flag '%s' matches", $featureFlag["key"] ?? "unknown"),
                    reason: $inconclusiveReason
                );
            }

            if (is_null($rolloutPercentage)) {
                return ConditionMatch::Match;
            }
        }

        if (!is_null($rolloutPercentage) && FeatureFlag::hash($featureFlag["key"], $distinctId) > ($rolloutPercentage / 100)) { //phpcs:ignore
            return ConditionMatch::OutOfRolloutBound;
        }

        return ConditionMatch::Match;
    }

    /**
     * Pick the reason to report when two causes apply to one flag.
     *
     * @param string|null $current Reason found so far, if any.
     * @param string $next Reason of the cause just found.
     * @return string
     */
    private static function pickUnresolvedReason(?string $current, string $next): string
    {
        if ($current === null) {
            return $next;
        }

        $precedence = array_flip(self::UNRESOLVED_REASON_PRECEDENCE);
        return $precedence[$next] < $precedence[$current] ? $next : $current;
    }

    private static function isRegularExpression($string)
    {
        if ($string === null) {
            return false;
        }
        set_error_handler(function () {
        }, E_WARNING);
        $isRegularExpression = preg_match($string, "") !== false;
        $error = preg_last_error();
        $errorMessage = preg_last_error_msg();
        restore_error_handler();
        if (!$isRegularExpression && $error !== PREG_INTERNAL_ERROR) {
            // Preflight also executes the pattern; execution limits do not mean invalid syntax.
            throw new InconclusiveMatchException(
                "Regex execution failed: " . $errorMessage,
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }
        return $isRegularExpression;
    }

    /** @return bool Whether the admitted pattern uses a word-character shorthand. */
    private static function assertPortableRegex(string $pattern): bool
    {
        $usesWordClass = false;
        $inClass = false;
        $classStart = 0;
        $previousToken = '';
        for ($index = 0; $index < strlen($pattern); $index++) {
            $character = $pattern[$index];
            if ($character === '\\') {
                $escaped = $pattern[++$index] ?? '';
                if ($escaped === '') {
                    break;
                }
                if ($escaped === 'x') {
                    if (!preg_match('/^[0-9a-fA-F]{2}$/D', substr($pattern, $index + 1, 2))) {
                        throw new InconclusiveMatchException(
                            'Regex escape requires server evaluation',
                            reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                        );
                    }
                    $index += 2;
                } elseif (
                    !str_contains('dDsSwWfnrt', $escaped)
                    && !($escaped === 'b' && $inClass)
                    && !(!$inClass && str_contains('Az', $escaped))
                    && !preg_match('/^[\x20-\x2f:;=?@\x5b-\x60\x7b-\x7e]$/D', $escaped)
                ) {
                    // In particular, word boundaries can exhaust the service's 10,000-step budget.
                    throw new InconclusiveMatchException(
                        'Regex escape requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
                if (
                    $inClass
                    && !str_contains('dDsSwWbfnrtx\\^-[]', $escaped)
                ) {
                    throw new InconclusiveMatchException(
                        'Regex class escape requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
                if (
                    $inClass && str_contains('dDsSwW', $escaped)
                    && (($pattern[$index - 2] ?? '') === '-'
                        || (($pattern[$index + 1] ?? '') === '-' && ($pattern[$index + 2] ?? '') !== ']'))
                ) {
                    throw new InconclusiveMatchException(
                        'Regex class range requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
                if ($escaped === 'w' || $escaped === 'W') {
                    $usesWordClass = true;
                }
                $previousToken = !$inClass && str_contains('Az', $escaped) ? '^' : 'literal';
                continue;
            }

            if ($character === '[') {
                // PCRE accepts POSIX/nested/set classes with semantics different from Rust's.
                if ($inClass) {
                    throw new InconclusiveMatchException(
                        'Regex nested class requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
                $inClass = true;
                $classStart = $index + (($pattern[$index + 1] ?? '') === '^' ? 2 : 1);
            } elseif ($character === ']' && $inClass) {
                if ($index === $classStart) {
                    throw new InconclusiveMatchException(
                        'Regex empty class requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
                $inClass = false;
                $previousToken = 'literal';
                continue;
            } elseif ($inClass) {
                if (in_array(substr($pattern, $index, 2), ['&&', '--', '~~'], true)) {
                    throw new InconclusiveMatchException(
                        'Regex set operation requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
                continue;
            } elseif ($character === '(' && ($pattern[$index + 1] ?? '') === '?') {
                if (($pattern[$index + 2] ?? '') === ':') {
                    $index += 2;
                } elseif (substr($pattern, $index, 4) === '(?i)') {
                    $index += 3;
                    continue;
                } else {
                    throw new InconclusiveMatchException(
                        'Regex group requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
            } elseif ($character === '(' && ($pattern[$index + 1] ?? '') === '*') {
                throw new InconclusiveMatchException(
                    'Regex verb requires server evaluation',
                    reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                );
            } elseif ($character === '{') {
                if (
                    !preg_match('/^\{(\d+)(?:,(\d*))?\}/', substr($pattern, $index), $repetition)
                    || in_array($previousToken, ['', '|', '('], true)
                    || (float) $repetition[1] > 65535
                    || (isset($repetition[2]) && (float) $repetition[2] > 65535)
                ) {
                    // PCRE's repetition count limit is smaller than the service's.
                    throw new InconclusiveMatchException(
                        'Regex repetition requires server evaluation',
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }
            } elseif (
                str_contains('*+?}', $character)
                && in_array($pattern[$index + 1] ?? '', ['+', '{'], true)
            ) {
                throw new InconclusiveMatchException(
                    'Regex stacked repetition requires server evaluation',
                    reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                );
            }
            if (str_contains('*+?{', $character) && in_array($previousToken, ['^', '$', 'empty group'], true)) {
                throw new InconclusiveMatchException(
                    'Regex assertion repetition requires server evaluation',
                    reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                );
            }
            $previousToken = $character === ')' && in_array($previousToken, ['(', 'empty group'], true)
                ? 'empty group' : $character;
        }
        return $usesWordClass;
    }

    private static function prepareValueForRegex(string $pattern): string
    {
        // Escape each delimiter slash that is not already escaped by an odd run of backslashes.
        $escaped = preg_replace('~(?<!\\\\)((?:\\\\\\\\)*)/~', '$1\\\\/', $pattern);

        // Rust's default $ is strict, unlike PCRE's match before a final newline.
        return "/" . $escaped . "/uD";
    }

    /**
     * Evaluate a feature flag dependency property.
     *
     * @param array<string, mixed> $property Flag dependency property.
     * @param array<string, array<string, mixed>>|null $flagsByKey Local feature flag definitions keyed by key.
     * @param array<string, mixed>|null $evaluationCache Cache used for dependency evaluation.
     * @param string $distinctId Distinct ID used for bucketing.
     * @param array<string, mixed> $properties Person or group properties for evaluation.
     * @param array<string, mixed> $cohortProperties Local cohort definitions keyed by cohort ID.
     * @param mixed $propertyMatchingVersion Definition snapshot selector; defaults to legacy matching.
     * @return bool Whether the dependency matches.
     * @throws InconclusiveMatchException When the dependency cannot be evaluated locally.
     */
    public static function evaluateFlagDependency(
        $property,
        $flagsByKey,
        $evaluationCache,
        $distinctId,
        $properties,
        $cohortProperties,
        $propertyMatchingVersion = 1
    ) {
        return FeatureFlag::evaluateFlagDependencyForIdentity(
            $property,
            $flagsByKey,
            $evaluationCache,
            $distinctId,
            $properties,
            $cohortProperties,
            $propertyMatchingVersion,
            false
        );
    }

    /**
     * @param int|string|null|false $identityAggregation Aggregation of the identity being bucketed (null for a
     *     person, a group type index otherwise). False means unknown and skips the holdout aggregation guard.
     */
    private static function evaluateFlagDependencyForIdentity(
        $property,
        $flagsByKey,
        &$evaluationCache,
        $distinctId,
        $properties,
        $cohortProperties,
        $propertyMatchingVersion,
        $identityAggregation
    ) {
        if ($flagsByKey === null || $evaluationCache === null) {
            throw new InconclusiveMatchException(
                sprintf(
                    "Cannot evaluate flag dependency on '%s' without flags_by_key and evaluation_cache",
                    $property["key"] ?? "unknown"
                ),
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        // An unsupported dependency outranks one that is only inconclusive, so check it before
        // evaluating any of it.
        FeatureFlag::assertFlagDependencySupported($property, $flagsByKey, $evaluationCache);
        $depFlagKey = $property["key"];

        // Check if we've already evaluated this flag
        if (!array_key_exists($depFlagKey, $evaluationCache)) {
            $depFlag = $flagsByKey[$depFlagKey];
            // Same order as Client::computeFlagLocally: an inactive flag is false whatever its
            // experience continuity setting.
            if (!($depFlag["active"] ?? false)) {
                $evaluationCache[$depFlagKey] = false;
            } elseif ($depFlag["ensure_experience_continuity"] ?? false) {
                $evaluationCache[$depFlagKey] = null;
                throw new InconclusiveMatchException(
                    sprintf(
                        "Cannot evaluate flag dependency '%s' - flag has experience continuity enabled",
                        $depFlagKey
                    ),
                    reason: UnresolvedFlagReason::UNRESOLVED_DEPENDENCY
                );
            } else {
                // Holdout bucketing uses the evaluated identity, which can't be resolved for a
                // dependency aggregated differently, so defer to server evaluation.
                $depFilters = $depFlag["filters"] ?? [];
                if (
                    $identityAggregation !== false &&
                    FeatureFlag::hasActiveHoldout($depFilters["holdout"] ?? null) &&
                    FeatureFlag::aggregationKey($depFilters["aggregation_group_type_index"] ?? null) !==
                        FeatureFlag::aggregationKey($identityAggregation)
                ) {
                    throw new InconclusiveMatchException(
                        sprintf(
                            "Cannot evaluate flag dependency '%s' with a holdout aggregated differently from the dependent flag", //phpcs:ignore
                            $depFlagKey
                        ),
                        reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                    );
                }

                // Recursively evaluate the dependency
                try {
                    $depResult = FeatureFlag::matchFeatureFlagPropertiesForIdentity(
                        $depFlag,
                        $distinctId,
                        $properties,
                        $cohortProperties,
                        $flagsByKey,
                        $evaluationCache,
                        [],
                        [],
                        [],
                        $propertyMatchingVersion,
                        $identityAggregation
                    );
                    $evaluationCache[$depFlagKey] = $depResult;
                } catch (RequiresServerEvaluationException $e) {
                    // Keep the server error type so the dependent flag falls back to the server instead
                    // of letting a later condition resolve it locally. Nothing is cached, so a later
                    // check of this dependency needs the server too.
                    throw new RequiresServerEvaluationException(
                        sprintf("Cannot evaluate flag dependency '%s': %s", $depFlagKey, $e->getMessage()),
                        0,
                        $e,
                        UnresolvedFlagReason::UNRESOLVED_DEPENDENCY
                    );
                } catch (InconclusiveMatchException $e) {
                    // If we can't evaluate a dependency, store null and propagate the error
                    $evaluationCache[$depFlagKey] = null;
                    throw new InconclusiveMatchException(
                        sprintf("Cannot evaluate flag dependency '%s': %s", $depFlagKey, $e->getMessage()),
                        0,
                        $e,
                        UnresolvedFlagReason::UNRESOLVED_DEPENDENCY
                    );
                }
            }
        }

        // Get the evaluated flag value
        $flagValue = $evaluationCache[$depFlagKey];
        if ($flagValue === null) {
            // Previously inconclusive - raise error again
            throw new InconclusiveMatchException(
                sprintf("Flag dependency '%s' was previously inconclusive", $depFlagKey),
                reason: UnresolvedFlagReason::UNRESOLVED_DEPENDENCY
            );
        }

        // Now check if the flag value matches the expected value in the property
        $expectedValue = $property["value"] ?? null;
        if ($expectedValue !== null) {
            return FeatureFlag::matchesDependencyValue($expectedValue, $flagValue);
        }

        // If no value check needed, return true (all dependencies passed)
        return true;
    }

    /**
     * Throw unsupported_definition when local evaluation can never evaluate a flag dependency filter.
     *
     * @param array<string, mixed> $evaluationCache Dependency results already known, keyed by flag key.
     * @throws InconclusiveMatchException
     */
    private static function assertFlagDependencySupported($property, $flagsByKey, array $evaluationCache): void
    {
        // Check if dependency_chain is present - it should always be provided for flag dependencies
        if (!is_array($property["dependency_chain"] ?? null)) {
            throw new InconclusiveMatchException(
                sprintf(
                    "Cannot evaluate flag dependency on '%s' without dependency_chain",
                    $property["key"] ?? "unknown"
                ),
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        $dependencyChain = $property["dependency_chain"];

        // Handle circular dependency (empty chain means circular)
        if (count($dependencyChain) === 0) {
            throw new InconclusiveMatchException(
                sprintf("Circular dependency detected for flag '%s'", $property["key"] ?? "unknown"),
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        // The flag key to evaluate is in the "key" field
        $depFlagKey = $property["key"] ?? null;
        if (!$depFlagKey) {
            throw new InconclusiveMatchException(
                sprintf("Flag dependency missing 'key' field: %s", json_encode($property)),
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        $operator = $property["operator"] ?? "exact";
        if (($property["value"] ?? null) !== null && $operator !== "flag_evaluates_to") {
            throw new InconclusiveMatchException(
                sprintf("Flag dependency property for '%s' has invalid operator '%s'", $depFlagKey, $operator),
                reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
            );
        }

        foreach (array_merge($dependencyChain, [$depFlagKey]) as $chainFlagKey) {
            if (!array_key_exists($chainFlagKey, $evaluationCache) && empty($flagsByKey[$chainFlagKey])) {
                throw new InconclusiveMatchException(
                    sprintf("Cannot evaluate flag dependency '%s' - flag not found in local flags", $chainFlagKey),
                    reason: UnresolvedFlagReason::UNSUPPORTED_DEFINITION
                );
            }
        }
    }

    /**
     * Compare an expected flag dependency value with an evaluated flag value.
     *
     * @param mixed $expectedValue Expected dependency value from the property filter.
     * @param mixed $actualValue Actual evaluated flag value.
     * @return bool Whether the values match dependency semantics.
     */
    public static function matchesDependencyValue($expectedValue, $actualValue)
    {
        // String variant case - check for exact match or boolean true
        if (is_string($actualValue) && strlen($actualValue) > 0) {
            if (is_bool($expectedValue)) {
                // Any variant matches boolean true
                return $expectedValue;
            } elseif (is_string($expectedValue)) {
                // variants are case-sensitive, hence our comparison is too
                return $actualValue === $expectedValue;
            } else {
                return false;
            }
        } elseif (is_bool($actualValue) && is_bool($expectedValue)) {
            // Boolean case - must match expected boolean value
            return $actualValue === $expectedValue;
        }

        // Default case
        return false;
    }

    /**
     * The unresolved reason that comes from the flag's definition alone, so it is known when
     * definitions load. Matches the reason local evaluation reports for the same cause.
     *
     * @param array<string, mixed> $flag Feature flag definition.
     * @param array<string, array<string, mixed>> $flagsByKey Local feature flag definitions keyed by key.
     * @param array<string, mixed> $cohorts Local cohort definitions keyed by cohort ID.
     * @param array<string, string> $groupTypeMapping Mapping from group type index to group type name.
     * @return string|null UnresolvedFlagReason::EXPERIENCE_CONTINUITY or
     *     UnresolvedFlagReason::UNSUPPORTED_DEFINITION, or null when the definition alone does not
     *     stop local evaluation.
     */
    public static function getDefinitionUnresolvedReason($flag, $flagsByKey, $cohorts, $groupTypeMapping): ?string
    {
        if (!($flag["active"] ?? false)) {
            return null;
        }

        if ($flag["ensure_experience_continuity"] ?? false) {
            return UnresolvedFlagReason::EXPERIENCE_CONTINUITY;
        }

        $flagFilters = $flag["filters"] ?? [];
        $aggregation = $flagFilters["aggregation_group_type_index"] ?? null;
        if (!is_null($aggregation) && is_null($groupTypeMapping[strval($aggregation)] ?? null)) {
            return UnresolvedFlagReason::UNSUPPORTED_DEFINITION;
        }

        return FeatureFlag::conditionsDefinitionReason($flagFilters["groups"] ?? [], $flagsByKey, $cohorts);
    }

    /**
     * UnresolvedFlagReason::UNSUPPORTED_DEFINITION when local evaluation can never check a filter in
     * one of the conditions, null otherwise.
     *
     * @param array<int, array<string, mixed>> $conditions Release conditions of a feature flag.
     */
    private static function conditionsDefinitionReason(array $conditions, $flagsByKey, $cohorts): ?string
    {
        foreach ($conditions as $condition) {
            foreach ($condition["properties"] ?? [] as $property) {
                $visitedCohortIds = [];
                if (FeatureFlag::isUnsupportedProperty($property, $flagsByKey, $cohorts, $visitedCohortIds)) {
                    return UnresolvedFlagReason::UNSUPPORTED_DEFINITION;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, bool> $visitedCohortIds
     */
    private static function isUnsupportedProperty($property, $flagsByKey, $cohorts, array &$visitedCohortIds): bool
    {
        $propertyType = $property["type"] ?? null;

        if ($propertyType === "flag") {
            try {
                FeatureFlag::assertFlagDependencySupported($property, $flagsByKey, []);
                return false;
            } catch (InconclusiveMatchException $e) {
                return true;
            }
        }

        if ($propertyType === "cohort") {
            $cohortId = strval($property["value"] ?? "");
            if (!array_key_exists($cohortId, $cohorts)) {
                return true;
            }
            if (isset($visitedCohortIds[$cohortId])) {
                return false;
            }
            $visitedCohortIds[$cohortId] = true;
            return FeatureFlag::isUnsupportedPropertyGroup(
                $cohorts[$cohortId],
                $flagsByKey,
                $cohorts,
                $visitedCohortIds
            );
        }

        try {
            FeatureFlag::assertFilterSupported($property);
            return false;
        } catch (InconclusiveMatchException $e) {
            return true;
        }
    }

    /**
     * @param array<string, bool> $visitedCohortIds
     */
    private static function isUnsupportedPropertyGroup(
        $propertyGroup,
        $flagsByKey,
        $cohorts,
        array &$visitedCohortIds
    ): bool {
        foreach ($propertyGroup["values"] ?? [] as $value) {
            $unsupported = is_array($value) && array_key_exists("values", $value)
                ? FeatureFlag::isUnsupportedPropertyGroup($value, $flagsByKey, $cohorts, $visitedCohortIds)
                : FeatureFlag::isUnsupportedProperty($value, $flagsByKey, $cohorts, $visitedCohortIds);
            if ($unsupported) {
                return true;
            }
        }

        return false;
    }
}
