<?php

namespace PostHog;

/**
 * External cache provider for local-evaluation feature flag definitions.
 *
 * Implement this interface to share downloaded flag definitions across PHP workers, serverless
 * invocations, or other distributed SDK instances. Provider methods are called synchronously by the
 * SDK and any thrown error is logged as a warning without crashing application code.
 *
 * A project API key is required, but a secret key is not needed to read shared definitions when
 * shouldFetchFlagDefinitions() returns false. A secret key is required only for direct definition
 * API requests. Definitions load during construction unless loadFeatureFlags is false; call
 * Client::loadFlags() to refresh from the provider.
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
     * latest definitions from getFlagDefinitions() instead. Returning true requests a direct API
     * fetch, which is skipped with a warning if no secret key is configured; cached data is not read
     * in that case. Empty or unavailable caches preserve previously loaded definitions.
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
