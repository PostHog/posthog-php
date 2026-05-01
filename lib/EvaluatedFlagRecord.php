<?php

namespace PostHog;

/**
 * Immutable per-flag entry stored on a FeatureFlagEvaluations snapshot.
 */
final class EvaluatedFlagRecord
{
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
     */
    public function getValue(): bool|string
    {
        return $this->variant ?? $this->enabled;
    }
}
