<?php

namespace PostHog;

/**
 * Why local evaluation could not resolve a flag, as reported by
 * FeatureFlagEvaluations::getUnresolvedFlags().
 */
class UnresolvedFlagReason
{
    /**
     * The flag has experience continuity enabled, which local evaluation never resolves.
     * Disable experience continuity on the flag to evaluate it locally.
     */
    public const EXPERIENCE_CONTINUITY = 'experience_continuity';

    /**
     * The flag uses something local evaluation does not support, such as a static cohort, an
     * unrecognized operator, a malformed value, a dependency with no loaded definition, or a
     * circular dependency. Change the flag or upgrade the SDK.
     */
    public const UNSUPPORTED_DEFINITION = 'unsupported_definition';

    /**
     * A flag this flag depends on has a loaded definition but could not be resolved. The
     * dependency's own entry carries its reason when the dependency is in the requested scope.
     */
    public const UNRESOLVED_DEPENDENCY = 'unresolved_dependency';

    /**
     * The call did not pass a property or group property the flag needs, or passed it in a form
     * local evaluation cannot use. Pass it to evaluate the flag locally.
     */
    public const MISSING_CONTEXT = 'missing_context';
}
