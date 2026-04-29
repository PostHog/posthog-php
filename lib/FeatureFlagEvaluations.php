<?php

namespace PostHog;

/**
 * Snapshot of feature flag evaluations for a single distinct id.
 *
 * Created by Client::evaluateFlags(), this object lets callers read flag values, check enablement,
 * and pull payloads without paying additional /flags requests. Reads via isEnabled() and getFlag()
 * record access and trigger a deduped $feature_flag_called event; getFlagPayload() is silent.
 */
class FeatureFlagEvaluations
{
    /** @var array<string, bool> */
    private array $accessed;

    /**
     * @param array<string, EvaluatedFlagRecord> $flags
     * @param array<string, mixed> $groups
     */
    public function __construct(
        private readonly string $distinctId,
        private readonly array $flags,
        private readonly array $groups,
        private readonly FeatureFlagEvaluationsHost $host,
        private readonly ?string $requestId = null,
        private readonly bool $logWarnings = true,
        ?array $accessed = null,
        private readonly bool $errorsWhileComputing = false,
        private readonly bool $quotaLimited = false,
    ) {
        $this->accessed = $accessed ?? [];
    }

    /**
     * @return list<string>
     */
    public function getKeys(): array
    {
        return array_keys($this->flags);
    }

    /**
     * Whether the flag is enabled for the snapshot's distinct id. Returns false for unknown keys.
     */
    public function isEnabled(string $key): bool
    {
        $record = $this->flags[$key] ?? null;
        $this->recordAccess($key, $record);

        return $record?->enabled ?? false;
    }

    /**
     * Returns the variant (string), enabled state (bool), or null for unknown keys.
     */
    public function getFlag(string $key): bool|string|null
    {
        $record = $this->flags[$key] ?? null;
        $this->recordAccess($key, $record);

        if ($record === null) {
            return null;
        }

        return $record->getValue();
    }

    /**
     * Returns the decoded payload for a flag without recording access or firing a $feature_flag_called event.
     * Returns null for unknown keys or flags without a payload.
     */
    public function getFlagPayload(string $key): mixed
    {
        return $this->flags[$key]->payload ?? null;
    }

    /**
     * Returns a clone of this snapshot containing only flags that were previously accessed via
     * isEnabled() or getFlag(). Order-dependent: if nothing has been accessed yet, the returned
     * snapshot is empty. The method honors its name — pre-access if you want a populated result.
     */
    public function onlyAccessed(): self
    {
        $filtered = [];
        foreach ($this->accessed as $key => $_) {
            if (isset($this->flags[$key])) {
                $filtered[$key] = $this->flags[$key];
            }
        }

        return $this->cloneWith($filtered);
    }

    /**
     * Returns a clone of this snapshot filtered to the given keys. Unknown keys are dropped with a
     * warning so silent typos don't slip into captured events.
     *
     * @param list<string> $keys
     */
    public function only(array $keys): self
    {
        $filtered = [];
        foreach ($keys as $key) {
            if (isset($this->flags[$key])) {
                $filtered[$key] = $this->flags[$key];
            } else {
                $this->emitWarning(
                    sprintf('FeatureFlagEvaluations::only() dropped unknown flag key "%s".', $key)
                );
            }
        }

        return $this->cloneWith($filtered);
    }

    /**
     * Properties to merge onto a captured event when it carries this snapshot. Adds $feature/<key>
     * for every flag and a sorted $active_feature_flags list for enabled flags.
     *
     * @return array<string, mixed>
     */
    public function getEventProperties(): array
    {
        $properties = [];
        $active = [];
        foreach ($this->flags as $key => $record) {
            $properties['$feature/' . $key] = $record->getValue();
            if ($record->enabled) {
                $active[] = $key;
            }
        }
        sort($active);
        $properties['$active_feature_flags'] = $active;

        return $properties;
    }

    /**
     * Records that $key was accessed and fires a deduped $feature_flag_called event when the
     * snapshot is bound to a real distinct id. Empty distinct ids short-circuit so we never leak
     * events with an empty actor.
     */
    private function recordAccess(string $key, ?EvaluatedFlagRecord $record): void
    {
        $this->accessed[$key] = true;

        if ($this->distinctId === '') {
            return;
        }

        $properties = [
            '$feature_flag' => $key,
            '$feature_flag_response' => $record?->getValue() ?? false,
        ];

        if ($record !== null) {
            if ($record->id !== null) {
                $properties['$feature_flag_id'] = $record->id;
            }
            if ($record->version !== null) {
                $properties['$feature_flag_version'] = $record->version;
            }
            if ($record->reason !== null) {
                $properties['$feature_flag_reason'] = $record->reason;
            }
            if ($record->locallyEvaluated) {
                $properties['locally_evaluated'] = true;
            }
        }

        // request_id is per /flags response; locally-evaluated records aren't tied to a remote
        // call so we omit it for them, matching the existing single-flag local path.
        if ($this->requestId !== null && !($record?->locallyEvaluated ?? false)) {
            $properties['$feature_flag_request_id'] = $this->requestId;
        }

        // Build a comma-joined $feature_flag_error matching the single-flag path's granularity:
        // response-level errors combine with per-flag errors so consumers can filter by type.
        $errors = [];
        if ($this->errorsWhileComputing) {
            $errors[] = FeatureFlagError::ERRORS_WHILE_COMPUTING_FLAGS;
        }
        if ($this->quotaLimited) {
            $errors[] = FeatureFlagError::QUOTA_LIMITED;
        }
        if ($record === null) {
            $errors[] = FeatureFlagError::FLAG_MISSING;
        }
        if (!empty($errors)) {
            $properties['$feature_flag_error'] = implode(',', $errors);
        }

        $this->host->captureFlagCalledIfNeeded(
            $this->distinctId,
            $key,
            $properties,
            $this->groups
        );
    }

    /**
     * @param array<string, EvaluatedFlagRecord> $flags
     */
    private function cloneWith(array $flags): self
    {
        // Filtered views start with an empty access set so reads on the child don't propagate back
        // into the parent's view. PHP's value-copy semantics on arrays already give us isolation.
        return new self(
            $this->distinctId,
            $flags,
            $this->groups,
            $this->host,
            $this->requestId,
            $this->logWarnings,
            [],
            $this->errorsWhileComputing,
            $this->quotaLimited,
        );
    }

    private function emitWarning(string $message): void
    {
        if ($this->logWarnings) {
            $this->host->logWarning($message);
        }
    }
}
