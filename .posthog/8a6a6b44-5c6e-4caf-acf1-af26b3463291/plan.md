# Implementation Plan: Static Cohort Fallback Strategy Architecture Investigation

**Task ID:** 8a6a6b44-5c6e-4caf-acf1-af26b3463291  
**Generated:** 2025-11-07

## Summary

Investigate and document the static cohort handling and API fallback strategy in the PostHog PHP SDK (v3.7.2). This investigation focuses on understanding the architecture decisions around when feature flags require server evaluation versus local evaluation, particularly for flags with static cohorts. The deliverable is comprehensive architecture documentation that explains the fallback behavior, edge cases, and design rationale.

## Implementation Steps

### 1. Deep Analysis of Static Cohort Handling

- [ ] Read and analyze `lib/FeatureFlag.php` focusing on cohort property evaluation logic
- [ ] Examine `lib/RequiresServerEvaluationException.php` to understand exception-based flow control
- [ ] Review `lib/Client.php` to trace how exceptions trigger API fallback calls
- [ ] Identify all code paths that throw `RequiresServerEvaluationException`
- [ ] Document the complete evaluation flow from local attempt to server fallback

### 2. Test Coverage Analysis

- [ ] Read `test/FeatureFlagLocalEvaluationTest.php` focusing on static cohort test cases
- [ ] Identify test methods covering fallback scenarios (search for "static cohort", "RequiresServerEvaluation")
- [ ] Review `test/assests/MockedResponses.php` for static cohort mock data structures
- [ ] Document which edge cases are covered and which may be missing
- [ ] Analyze flag dependency evaluation tests with cohort combinations

### 3. API Integration Investigation

- [ ] Examine how `Client.php` makes fallback API calls to `/flags/?v=2` endpoint
- [ ] Document request/response format for server-side evaluation
- [ ] Identify retry logic, error handling, and timeout configurations
- [ ] Review how cohort data is fetched and cached locally
- [ ] Trace the flow when `ensure_experience_continuity` flag is set

### 4. Edge Case Identification

- [ ] Document behavior when cohort exists locally but has missing properties
- [ ] Analyze circular dependency scenarios with flags dependent on cohort-based flags
- [ ] Identify race conditions in cohort data synchronization
- [ ] Review multi-condition flags with mixed local/static cohorts
- [ ] Examine flag dependencies where parent flags require server evaluation

### 5. Architecture Documentation Creation

- [ ] Create sequence diagrams for local evaluation → server fallback flow
- [ ] Document decision tree for when to evaluate locally vs server-side
- [ ] Explain exception-based control flow rationale vs return value approach
- [ ] Detail cohort caching strategy and invalidation logic
- [ ] Document performance implications of fallback strategy

### 6. Design Rationale Analysis

- [ ] Research why v3.7.2 introduced fallback for multi-condition flags with static cohorts
- [ ] Analyze tradeoffs between local evaluation speed and server accuracy
- [ ] Document consistency guarantees for static cohort evaluation
- [ ] Review implications for user experience continuity
- [ ] Identify potential improvements or optimizations

## File Changes

### New Files

```
docs/architecture/static-cohort-fallback-strategy.md - Comprehensive architecture documentation
  - Overview of feature flag evaluation flow
  - Static cohort handling decision tree
  - Sequence diagrams for fallback scenarios
  - Edge cases and error handling
  - Performance considerations
  - Design rationale and tradeoffs

docs/architecture/diagrams/evaluation-flow.mermaid - Visual flow diagram
  - Local evaluation attempt
  - Exception handling paths
  - API fallback triggers
  - Cohort data synchronization

docs/investigation/PHA-104-findings.md - Investigation report
  - Current implementation analysis
  - Test coverage assessment
  - Identified edge cases
  - Potential issues or improvements
  - Recommendations
```

### Modified Files

```
lib/FeatureFlag.php - Add inline documentation
  - Document cohort property evaluation logic with detailed comments
  - Clarify when RequiresServerEvaluationException is thrown
  - Add PHPDoc blocks explaining static cohort detection
  - Comment complex flag dependency evaluation paths

lib/Client.php - Enhance fallback documentation
  - Document API fallback mechanism with examples
  - Clarify exception handling flow
  - Add PHPDoc for getFeatureFlag method explaining fallback behavior
  - Document cohort fetching and caching strategy

lib/RequiresServerEvaluationException.php - Add architectural context
  - Explain why exception-based flow control was chosen
  - Document when this exception should be thrown
  - Link to architecture documentation

test/FeatureFlagLocalEvaluationTest.php - Document test scenarios
  - Add comments explaining static cohort test cases
  - Group tests by fallback scenario
  - Document expected behavior for each edge case
  - Add references to architecture docs
```

## Considerations

### Architectural Decisions

- **Exception-Based Flow Control**: Using `RequiresServerEvaluationException` as control flow mechanism allows clean separation between local evaluation logic and fallback handling, but may have performance implications if exceptions are thrown frequently
- **Lazy Fallback Strategy**: Only falls back to server when strictly necessary (cohort not found locally), optimizing for performance while maintaining correctness
- **Cohort Caching**: Local cohort storage reduces API calls but requires synchronization strategy to prevent stale data
- **Flag Dependencies**: Recursive evaluation with circular dependency detection adds complexity but enables powerful feature flag compositions

### Potential Risks

- **Stale Cohort Data**: If local cohorts are not synchronized frequently, flags may evaluate incorrectly until fallback occurs
- **API Availability**: Heavy reliance on server fallback for static cohorts means API downtime impacts flag evaluation accuracy
- **Performance**: Multiple nested flag dependencies with cohorts could trigger cascading API fallback calls
- **Circular Dependencies**: Complex dependency chains may be difficult to debug when combined with cohort-based evaluation

### Testing Approach

- **Code Reading**: Trace execution paths through static analysis of PHP code
- **Test Review**: Analyze existing test coverage to understand documented behavior
- **Edge Case Identification**: Look for untested scenarios in flag dependency + cohort combinations
- **Documentation Validation**: Verify documented behavior matches actual implementation

### Documentation Standards

- Use Mermaid diagrams for visual flows (sequence and decision tree diagrams)
- Include code snippets with line references to actual implementation
- Provide before/after examples for fallback scenarios
- Link between architecture docs and inline code comments
- Follow PostHog documentation style if available in repo

### Success Criteria

- Clear explanation of when and why server fallback occurs
- Comprehensive coverage of edge cases and error scenarios
- Visual diagrams that non-PHP developers can understand
- Actionable recommendations for potential improvements
- Enhanced inline documentation for maintainability