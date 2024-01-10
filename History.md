
3.1.0 / 2024-01-10
==================

  * feat(flags): Add relative date operator and fix numeric ops (#58)
  * Numeric property handling for feature flags now does the expected: When passed in a number, we do a numeric comparison. When passed in a string, we do a string comparison. Previously, we always did a string comparison.
  * Add support for relative date operators for local evaluation.

3.0.8 / 2023-09-25
==================

  * fix(flags): Safe access flags in decide v2 (#55)

3.0.7 / 2023-08-31
==================

  * PHP 8.1+ Support + Fix Errors When API/Internet Connection Down (#54)

3.0.6 / 2023-07-04
==================

  * Fix typehint (#52)

3.0.5 / 2023-06-16
==================

  * Prevent "Undefined array key" warning in isFeatureEnabled() (#51)

3.0.4 / 2023-05-19
==================

  * fix(flags): Handle no rollout percentage condition (#49)

3.0.3 / 2023-03-21
==================

  * Merge branch 'master' into groups-fix
  * Make timeout configurable (#44)
  * format
  * fix(groups): actually add groups support for capture

3.0.2 / 2023-03-08
==================

  * update version 3.0.2
  * Allow to configure the HttpClient maximumBackoffDuration (#33)

3.0.1 / 2022-12-09
==================

  * feat(flags): Add support for variant overrides (#39)
  * Update history (#37)

3.0.0 / 2022-08-15
==================


  * Requires posthog 1.38
  * Local Evaluation: isFeatureEnabled and getFeatureFlag accept group and person properties now which will evaluate relevant flags locally.
  * isFeatureEnabled and getFeatureFlag also have new parameters:
    onlyEvaluateLocally (bool) - turns on and off local evaluation
    sendFeatureFlagEvents (bool) - turns on and off $feature_flag_called events
  * Removes default parameter from isFeatureEnabled and getFeatureFlag. Returns null instead

2.1.1 / 2022-01-21
==================

  * more sensible default timeout for requests
  * Merge pull request #29 from PostHog/group-analytics-flags
  * Add groups feature flags support
  * Test default behavior
  * Release 2.1.0
  * Merge pull request #26 from PostHog/group-analytics-support
  * Add basic group analytics support
  * Fix bin/posthog help text
  * Allow bypassing ssl in bin/ command
  * Solve linter issues

2.1.0 / 2021-10-28
==================

  * Add basic group analytics support
  * Fix bin/posthog help text
  * Allow bypassing ssl in bin/ command

2.0.6 / 2021-10-05
==================

  * Separate timeout from maxBackoffDuration
  * Set the timeout config for HttpClient curl

2.0.5 / 2021-07-13
==================

  * Merge pull request #23 from joesaunderson/bugfix/send-user-agent
  * Send user agent with decide request

2.0.5 / 2021-07-13
==================



2.0.4 / 2021-07-08
==================

  * Release 2.0.3
  * Merge pull request #21 from joesaunderson/bugfix/optional-apikey
  * API key is optional
  * Merge pull request #20 from imhmdb/patch-1
  * Fix calling error handler Closure function stored in class properties

2.0.3 / 2021-07-08
==================

  * Merge pull request #21 from joesaunderson/bugfix/optional-apikey
  * API key is optional
  * Merge pull request #20 from imhmdb/patch-1
  * Fix calling error handler Closure function stored in class properties

2.0.2 / 2021-07-08
==================

  * Merge pull request #19 from PostHog/handle-host
  * fix tests for good
  * check if host exists before operating on it
  * undefined check
  * fix tests
  * Allow hosts with protocol specified
  * Merge pull request #18 from PostHog/feature-flags
  * remove useless comment
  * have env var as the secondary option
  * bump version
  * bring back destruct
  * remove feature flags
  * simplify everything
  * Cleanup isFeatureEnabled docblock
  * Fix user agent undefined array key
  * Merge pull request #17 from PostHog/releasing-update
  * Note `git-extras` in RELEASING.md
  * Add test case for isFeatureEnabled with the simple flag in the mocked response
  * Fix: make rolloutPercentage nullable in isSimpleFlagEnabled
  * Merge remote-tracking branch 'upstream/master'
  * Fix is_simple_flag tests by mocking response
  * Use LONG_SCALE const
  * Implement isSimpleFlagEnabled
  * Don't set payload on get requests
  * (WIP) Rework feature flags based on spec `https://github.com/PostHog/posthog.com/pull/1455`
  * Extract http client functionalities
  * Remove extra line
  * Change default host to app.posthog.com
  * Feature/support feature flags decide API
  * Upgrade phplint
2.0.1 / 2021-06-11
==================

  * Allow for setup via environment variables POSTHOG_API_KEY and POSTHOG_HOST
  * Make code adhere to PSR-4 and PSR-12

2.0.0 / 2021-05-22
==================

  * fix sed command for macos
  * Merge pull request #9 from adrienbrault/psr-4
  * Finish psr-4 refactoring
  * PostHog/posthog-php#3: Update composer.json to support PSR-4
  * Update README.md
  * Merge pull request #6 from chuva-inc/document_property
  * Merge pull request #5 from chuva-inc/issue_4
  * Posthog/posthog-php#3: Document the customer property
  * PostHog/posthog-php#4: Removes \n from beginning of the file
  * Update README.md
  * fix infinite loop on error 0 from libcurl
  * fix error when including https:// in host
  * fix tests for php 7.1, phpunit 8.5
  * upgrade phpunit and switch php to >=7.1
  * Update README.md
  * make tests pass
  * first commit
