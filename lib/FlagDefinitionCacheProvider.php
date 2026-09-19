<?php

namespace PostHog;

/**
 * External cache provider for local-evaluation feature flag definitions.
 *
 * Implement this interface to share downloaded flag definitions across PHP workers, serverless
 * invocations, or other distributed SDK instances. Provider methods are called synchronously by the
 * SDK and any thrown error is logged as a warning without crashing application code.
 */
interface FlagDefinitionCacheProvider
{
    /**
     * Retrieve cached local-evaluation flag definitions.
     *
     * Return null when the external cache is empty or unavailable. Returned data should include the
     * complete definition set: flags, group_type_mapping, cohorts, and property_matching_version.
     * Preserve property_matching_version together with the definitions: only 2 selects explicit
     * property matching; missing/1 and other versions use legacy matching. Older cache entries
     * without this field remain supported and reset matching to legacy when loaded.
     * groupTypeMapping is also accepted for integrations that prefer camelCase.
     *
     * @return array<string, mixed>|null
     */
    public function getFlagDefinitions(): ?array;

    /**
     * Decide whether this SDK instance should fetch fresh definitions from PostHog.
     *
     * Return false when another worker is responsible for fetching and this instance should read the
     * latest definitions from getFlagDefinitions() instead.
     *
     * @return bool
     */
    public function shouldFetchFlagDefinitions(): bool;

    /**
     * Receive definitions fetched successfully from PostHog so they can be stored externally.
     * Store the complete array, including property_matching_version, as one snapshot.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function onFlagDefinitionsReceived(array $data): void;

    /**
     * Release provider resources during SDK shutdown.
     *
     * @return void
     */
    public function shutdown(): void;
}
