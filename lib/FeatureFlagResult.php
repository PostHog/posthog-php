<?php

namespace PostHog;

/**
 * Represents the result of a feature flag evaluation, including both the value and payload.
 */
class FeatureFlagResult
{
    private string $key;
    private bool $enabled;
    private ?string $variant;
    private mixed $payload;

    public function __construct(
        string $key,
        bool $enabled,
        ?string $variant = null,
        mixed $payload = null
    ) {
        $this->key = $key;
        $this->enabled = $enabled;
        $this->variant = $variant;
        $this->payload = $payload;
    }

    /**
     * Get the feature flag key.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Whether the flag is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the variant value if this is a multivariate flag.
     */
    public function getVariant(): ?string
    {
        return $this->variant;
    }

    /**
     * Get the decoded JSON payload associated with this flag.
     */
    public function getPayload(): mixed
    {
        return $this->payload;
    }

    /**
     * Get the flag value in the same format as getFeatureFlag().
     * Returns the variant if set, otherwise the enabled boolean.
     * This matches the $feature_flag_response format.
     */
    public function getValue(): bool|string
    {
        if ($this->variant !== null) {
            return $this->variant;
        }
        return $this->enabled;
    }
}
