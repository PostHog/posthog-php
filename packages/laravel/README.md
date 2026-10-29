# PostHog for Laravel

Server-side analytics, feature flags and exception reporting for Laravel 12 and 13.
Requires PHP 8.2+ (Laravel 13 requires PHP 8.3+).

## Install

```sh
composer require posthog/posthog-laravel
php artisan posthog:install
```

Discovery registers the provider, facade and scoped container bindings automatically.
For applications without discovery, register `PostHog\Laravel\PostHogServiceProvider`.

Configure your **server environment**:

```dotenv
POSTHOG_API_KEY=phc_your_project_key
POSTHOG_HOST=https://us.i.posthog.com
POSTHOG_ENABLED=true
```

Use your project's ingestion host. `POSTHOG_SECRET_KEY` is optional and enables local
flag evaluation. Never publish secret keys in browser configuration, logs, or event
properties. No browser SDK or browser configuration endpoint is installed.

A missing/blank project key or `POSTHOG_ENABLED=false` disables all SDK network paths,
including flag requests and the `client()` escape hatch. Setting `sdk.consumer=noop`
only changes event transport: a valid-key client can still evaluate flags remotely.

## Capture and identity

```php
use PostHog\Laravel\Facades\PostHog;

PostHog::capture('checkout completed', ['items' => 2]);
PostHog::capture('import completed', ['rows' => 42], 'account-123', ['team' => 'team-1']);
PostHog::identify(['distinctId' => 'account-123', 'properties' => ['plan' => 'paid']]);
PostHog::alias(['distinctId' => 'account-123', 'alias' => 'old-account']);
PostHog::groupIdentify(['groupType' => 'team', 'groupKey' => 'team-1', 'properties' => ['plan' => 'paid']]);
```

Inject `PostHog\Laravel\PostHog` to use the same API without a facade. `posthog` is a
container alias. `PostHog\Client` is also injectable, but raw client calls require
explicit identity or an explicit core context.

Identity precedence: an explicit API `distinctId` wins, then an explicitly scoped
`Context` identity, then the custom identity resolver or authenticated request user's
auth identifier, then `X-PostHog-Distinct-Id` as an anonymous analytics fallback. `X-PostHog-Session-Id` provides session correlation. Headers are untrusted
analytics input, **never authentication or authorization**. Set `header_context=false`
to ignore both headers. No anonymous ID is generated for requests without identity.

Captures include the request method and matched route template (for example
`/orders/{order}`), never concrete route parameters. Explicit event properties win.
By default there is no collection of raw URLs, query strings, request bodies,
cookies, email, IP addresses, or user profile fields. GeoIP is disabled by default
(`sdk.disableGeoip=true`). Application-supplied properties and exception messages may
still contain sensitive information; use `sdk.before_send` to redact or drop events.
Exception stacks are serialized by the PHP SDK.

For a custom identity resolver, set `distinct_id` to an invokable container class.
It receives the application and returns a string identifier or null. A non-null
custom result replaces the request auth identifier. Otherwise auth/header fallback
applies. Resolver errors do not break the application. Set `groups` to an invokable
container class receiving the application and returning group identifiers. Explicit
context groups override matching resolver groups; exception snapshots inherit them.

## Scoped context and workers

```php
use PostHog\Laravel\Context;

app(Context::class)->with([
    'distinctId' => 'job-owner-123',
    'groups' => ['team' => 'team-1'],
    'properties' => ['component' => 'import'],
], function () {
    PostHog::capture('import started');
});
```

`Context::begin()`, `data()`, `snapshot()` and `end()` support explicit integrations.
`with()` restores the previous data and flag snapshots even when the callback throws.
Auth is resolved lazily only while handling a request; no user model is retained by
the analytics context after cleanup. Queue jobs start fresh and receive only bounded
identity, session and scalar group context in `_posthog` payload metadata. Arbitrary
properties, models and request data are not propagated. Set `queue_context=false` to
disable propagation. This metadata is analytics context, never authorization evidence.
Jobs may override it explicitly. Nested synchronous jobs and Artisan commands restore
the caller's context and flag snapshots when they finish or fail.

Global request middleware, queue events, Artisan command events, and Octane
request/task/tick events flush and clean context automatically. SDK creation is lazy,
per Laravel scope. Shared flag definitions live in Laravel Cache, not a global SDK.
Do not retain scoped services in application singletons or prewarm them in Octane.

Supported execution models are ordinary HTTP, Laravel queue workers (including sync
jobs), console commands, and sequential Octane sandbox lifecycles. The package does
not claim isolation for overlapping Swoole coroutines sharing one Laravel container.
Core Fiber context support alone does not make Laravel scopes coroutine-local.
Automatic flush failures do not replace an HTTP response or worker failure. Explicit
`flush()`/`shutdown()` expose the SDK results; flush is not a delivery receipt.

## Exceptions

Laravel's reportable exception path respects `dontReport`, exception-specific report
methods, and preceding report callbacks that stop reporting. The package does not
install global PHP exception/error handlers, even if `sdk.error_tracking.enabled` is
set. Disable automatic reporting with `capture_exceptions=false`.

```php
try {
    // Application work...
} catch (Throwable $exception) {
    PostHog::captureException($exception, properties: ['component' => 'import']);
}
```

Manual captures and ordinary `report()` calls are handled. Failures reported from
Laravel's routing pipeline or HTTP/console kernel failure paths, escaping request
middleware or queue execution, or reported by Octane's worker error listener are
unhandled. Boundary detection inspects only framework call names, never arguments.
Weakly held exception snapshots preserve the originating identity after cleanup;
automatic reports of the same exception object are deduplicated. A report already
emitted by an earlier framework listener cannot be retroactively relabeled by a
later boundary event. Explicit manual capture is intentionally independent of
automatic-report deduplication.

## Feature flags

```php
$flags = PostHog::evaluateFlags(); // Current request identity, evaluated on demand.
if ($flags->isEnabled('new-checkout')) {
    $variant = $flags->getFlag('checkout-layout');
    $payload = $flags->getFlagPayload('checkout-layout');
}
```

The same complete evaluation inputs reuse one core snapshot within the current
context. New identities, groups, properties, key scopes, or lifecycle scopes do not
reuse another user's result. Capture and boot do not evaluate flags. Value reads
emit the core's deduplicated exposure events; payload reads are silent. An explicit
empty `flagKeys: []` returns an empty snapshot without evaluation.

The full core signature is supported: `distinctId`, `groups`, `personProperties`,
`groupProperties`, `onlyEvaluateLocally`, `disableGeoip`, and `flagKeys`.
All other public PHP client methods are forwarded with current context:

```php
$result = PostHog::getFeatureFlagResult('flag-key', 'account-123');
$client = PostHog::client(); // Advanced PHP SDK access; pass identity explicitly.
$scope = $client->enterContext(['distinctId' => 'account-123'], ['fresh' => true]);
try {
    $client->capture(['event' => 'advanced', 'properties' => ['component' => 'worker']]);
} finally {
    $scope->close();
}
```

Array-form `capture()` forwards the PHP message unchanged, including its advanced
options. Prefer `evaluateFlags()` over the legacy getters for shared flag decisions.
Forwarded legacy flag getters also lazily load configured local definitions on first
identified use, preserving local-only options. With the raw `client()` escape hatch,
call `loadFlags()` explicitly before local evaluation if definitions have not loaded.

### Shared local definitions

Enable `flag_cache.enabled` to use `PostHog\Laravel\FlagDefinitionCacheProvider`.
`flag_cache.store` selects a Laravel cache store, `ttl` controls definition retention,
and `refresh_interval` controls refresh eligibility (seconds). The key is isolated
by ingestion host and project-key hash. Complete definition documents, including
`property_matching_version`, round-trip unchanged. Cache backend failures are handled
by the core's defensive provider contract. Use a shared production cache store whose
`add()` operation is atomic for best-effort fetch coordination; the array store is
process-local, not distributed coordination.

With a secret key, a worker can publish definitions; without it, workers only read
the cache. Definitions load lazily during the first nonempty flag evaluation and
refresh lazily after the interval. There is no background timer. The PHP client's
`loadFlags()` remains available for an explicit refresh. You can instead supply a
custom provider class in `sdk.flag_definition_cache_provider`.

## Configuration callbacks and full PHP access

`posthog.sdk` passes PHP SDK options through. `before_send`, `error_handler`, and
`flag_definition_cache_provider` accept container class names, so published config
can be cached without serializing closures. Callback classes must implement the
corresponding SDK callable/interface contract. The Laravel library metadata and
framework handler ownership are enforced by the wrapper. `release_id` sets release
metadata; advanced SDK options remain accessible under `sdk`.

## Diagnostics

- `php artisan posthog:doctor`: local configuration validation, no network or keys in output.
- `php artisan posthog:install`: publish config without overwriting an existing file.
- `php artisan posthog:test --send`: explicitly opt in to a diagnostic event with a random test ID.

No collectors are enabled by this foundation package. Optional collectors can be
registered by a separate `AutocaptureServiceProvider` when installed.

## Testing

```php
$fake = PostHog::fake();
PostHog::capture('checkout completed', ['items' => 2], 'account-123');
$fake->assertCaptured('checkout completed', fn ($event) => $event['properties']['items'] === 2);
$fake->assertCapturedCount(1);
$fake->assertNotCaptured('checkout failed');
```

Also available: `assertNothingCaptured()`, `assertIdentified()`, `assertAliased()`,
`assertGroupIdentified()`, `assertExceptionCaptured()`, and `recorded()`. Message API
assertions accept an optional predicate. `flags($coreSnapshot)` configures fake flag
results; the default is an empty snapshot. The fake's raw client is always disabled,
so escape-hatch calls cannot send traffic (they are not recorded).

The production manifest deliberately has no development repository overrides. For
local development before the required PHP SDK release is published, use the root
`scripts/test-laravel.sh 12` or `scripts/test-laravel.sh 13` helper once the tooling
PR in this stack is merged. It installs local dependencies in a disposable source
copy and runs the matching framework tests without changing package metadata.

Tests use Testbench, mocked transports and real Laravel lifecycle events; no live
PostHog service or Octane server process is required.
