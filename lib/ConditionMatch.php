<?php

namespace PostHog;

/**
 * Outcome of evaluating a single feature flag condition group.
 *
 * OUT_OF_ROLLOUT_BOUND means the group's property filters matched (or there were none)
 * but the rollout percentage excluded the user — the only case that triggers a flag's
 * `early_exit` short-circuit. Mirrors the server-side (Rust) engine's match cases.
 *
 * @internal
 */
enum ConditionMatch: string
{
    case Match = 'match';
    case NoMatch = 'no_match';
    case OutOfRolloutBound = 'out_of_rollout_bound';
}
