<?php

namespace PostHog;

/**
 * Immutable per-flag entry stored on a FeatureFlagEvaluations snapshot.
 *
 * @internal
 */
final class EvaluatedFlagRecord
{
    /**
     * Create an evaluated flag record.
     *
     * @param string $key Feature flag key.
     * @param bool $enabled Whether the flag is enabled.
     * @param string|null $variant Variant key for multivariate flags.
     * @param mixed $payload Decoded JSON payload associated with this flag.
     * @param int|null $id Feature flag ID, when provided by the API.
     * @param int|null $version Feature flag version, when provided by the API.
     * @param string|null $reason Evaluation reason, when provided by the API.
     * @param bool $locallyEvaluated Whether the value was computed locally.
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $enabled,
        public readonly ?string $variant,
        public readonly mixed $payload,
        public readonly ?int $id,
        public readonly ?int $version,
        public readonly ?string $reason,
        public readonly bool $locallyEvaluated,
    ) {
    }

    /**
     * The value as $feature_flag_response would render it: variant string when set, otherwise the enabled bool.
     *
     * @return bool|string
     */
    public function getValue(): bool|string
    {
        return $this->variant ?? $this->enabled;
    }
}
