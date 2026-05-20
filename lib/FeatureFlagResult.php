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

    /**
     * Create a feature flag result.
     *
     * @param string $key Feature flag key.
     * @param bool $enabled Whether the flag is enabled.
     * @param string|null $variant Variant key for multivariate flags.
     * @param mixed $payload Decoded JSON payload associated with this flag.
     */
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
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Whether the flag is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the variant value if this is a multivariate flag.
     *
     * @return string|null
     */
    public function getVariant(): ?string
    {
        return $this->variant;
    }

    /**
     * Get the decoded JSON payload associated with this flag.
     *
     * @return mixed
     */
    public function getPayload(): mixed
    {
        return $this->payload;
    }

    /**
     * Get the flag value in the same format as getFeatureFlag().
     * Returns the variant if set, otherwise the enabled boolean.
     * This matches the $feature_flag_response format.
     *
     * @return bool|string
     */
    public function getValue(): bool|string
    {
        if ($this->variant !== null) {
            return $this->variant;
        }
        return $this->enabled;
    }
}
