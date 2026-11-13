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
to ignore both headers.

Without an explicit or contextual identity, captures and exception events use a fresh
random UUID **for each event** and default to `$process_person_profile=false`. An
explicit `$process_person_profile` property still wins. Enabled automatic collectors
use the same personless capture behavior. These IDs are not persistent visitor IDs:
they are never stored in Laravel sessions, cookies, request context or queue metadata,
and do not link events from the same anonymous visitor. A frontend session-ID header
can still correlate events through `$session_id` without supplying a person identity.
Feature-flag evaluation never generates an identity: without one, it returns an empty
snapshot without loading definitions, making flag requests or sending exposure events.

Captures include the request method and matched route template (for example
`/orders/{order}`), never concrete route parameters. Request context also adds the
visitor's IP address as `$ip` using Laravel's `request()->ip()`. Captures, exceptions
and enabled automatic collectors inherit it. Explicit event or scoped `$ip` values,
including `null`, win. Identification, alias and group-identify calls retain their
explicit-message behavior rather than inheriting capture metadata.

IP collection is on by default, matching the other backend framework integrations.
To disable automatic IP collection, set `POSTHOG_CAPTURE_IP=false` (or
`posthog.capture_ip=false`). This does not remove IPs your application explicitly
supplies. Configure Laravel's trusted proxies before collecting events behind a
reverse proxy or load balancer. The SDK does not parse forwarding headers itself or
change the application's proxy trust configuration. Without trusted-proxy setup,
Laravel may return a proxy address instead of the visitor address.

IPs are resolved only during HTTP request context, not inferred for standalone
commands or jobs. Request IPs are not copied into queue payload metadata. Deferred
model events and exception snapshots retain the originating IP in memory; later
requests cannot replace it. Fresh context scopes exclude inherited request IPs.

GeoIP enrichment remains disabled by default (`sdk.disableGeoip=true`), independently
of IP collection. Supplying `$ip` alone does not turn geographic enrichment on.
Use `sdk.disableGeoip=false` or a per-capture `disableGeoip: false` option only when
you want that enrichment. IP addresses can be personal data; apply your consent and
retention policies and use `sdk.before_send` to redact or drop them.

By default there is no collection of raw URLs, query strings, request bodies,
cookies, email, or user profile fields. Application-supplied properties and exception
messages may still contain sensitive information. Exception stacks are serialized
by the PHP SDK.

For a custom identity resolver, set `distinct_id` to an invokable container class.
It receives the application and returns a string identifier or null. A non-null
custom result replaces the request auth identifier. Otherwise auth/header fallback
applies. Resolver errors do not break the application. With `sdk.debug=true`, failed
identity, authenticated-user and group lookups log at most one debug message each
per context scope through Laravel's logger. Diagnostics contain the exception class,
not its message or resolved data; enable debug output on your Laravel log channel to
see them. Set `groups` to an invokable container class receiving the application and
returning group identifiers. Explicit
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
It accepts `distinct_id`/`session_id` aliases; canonical `distinctId`/`sessionId` keys
win if both forms are supplied.
The manager/facade also exposes `withContext($data, $callback, $options)`,
`enterContext($data, $options)` and `getContext()`. These bridge Laravel context and
raw-client context, accept `distinct_id`/`session_id` aliases, and merge properties
shallowly like the core SDK. Explicit values override active raw-client values, which
override resolved Laravel values. Groups inherit from Laravel unless explicitly
replaced. `['fresh' => true]` excludes ambient identity, request metadata, groups and
resolvers. Close an entered scope in `finally`; closing it twice is harmless. Parent
data and flag snapshots are restored after closing or throwing.
Auth is resolved lazily only while handling a request; no user model is retained by
the analytics context after cleanup. Queue jobs start fresh and receive only bounded
identity, session and scalar group context in `_posthog` payload metadata. Arbitrary
properties, models and request data are not propagated. Set `queue_context=false` to
disable propagation. This metadata is analytics context, never authorization evidence.
Jobs may override it explicitly. Nested synchronous jobs and kernel-level
`Artisan::call()` executions restore the caller's context and flag snapshots when
they finish or fail.

Request context remains active through HTTP kernel termination, including
`dispatchAfterResponse()` jobs and callbacks registered during application termination.
The kernel's completion hook flushes and clears it afterward, not when middleware
returns the response. Custom HTTP kernels without Laravel's lifecycle callback API
fall back to middleware cleanup and cannot provide after-response correlation.

Queue events and Octane request/task/tick boundaries flush and clean context
automatically. Console integration decorates the existing console kernel contract:
nested `Artisan::call()` executions restore their caller in `finally`, while the root
command stays active through kernel termination. Nested calls defer flushing to the
outer command or HTTP termination. A standalone `Artisan::call()` still flushes on
exit. Thrown objects retain their own snapshots; nonzero return codes never supply another exception's identity. The
application's commands, custom kernel, and exception-reporting policy are preserved.
Direct child-command `$this->call()`/`callSilent()`/`callSilently()` execution bypasses
those kernel boundaries. Opt into isolation by adding `IsolatesCommandContext` to
**the calling command**, or to your application's base command:

```php
namespace App\Console;

use Illuminate\Console\Command as BaseCommand;
use PostHog\Laravel\Concerns\IsolatesCommandContext;

abstract class Command extends BaseCommand
{
    use IsolatesCommandContext;
}
```

Have your application commands extend this base class, or apply the trait directly
to each calling command. All three call helpers then restore the caller's context
and cached flag snapshots when the child returns or throws, including closure
children. Arguments, output, exit codes and throwables pass through unchanged.
Root context still lasts through kernel termination.

This is opt-in, not global interception: uninstrumented callers, including commands
registered with `Artisan::command()`, still need kernel-level `Artisan::call()` or an
explicit `PostHog::withContext([], fn () => $this->call(...))` scope when a child can
mutate analytics context. The trait uses Laravel's inherited `runCommand()`;
custom call implementations that bypass it also need explicit scoping.

SDK creation is lazy,
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
set. Disable automatic reporting with `capture_exceptions=false`. Custom exception handlers
must expose Laravel's `reportable()` API for automatic registration; otherwise add an
explicit report callback using the public capture API.

```php
try {
    // Application work...
} catch (Throwable $exception) {
    PostHog::captureException($exception, properties: ['component' => 'import']);
}
```

The manager/facade uses the named argument `properties:` for exception properties,
not the core client's `additionalProperties:`. Other exception parameters match core.

Manual captures and ordinary `report()` calls are handled and use mechanism `generic`
unless a known integration boundary supplies a more specific category. Failures
reported from Laravel's routing pipeline or HTTP/console kernel failure paths, escaping request
middleware or queue execution, or reported by Octane's worker error listener are
unhandled. Boundary detection inspects only framework call names, never arguments.
Weakly held exception snapshots preserve the originating identity after cleanup;
automatic reports of the same exception object are deduplicated. A report already
emitted by an earlier framework listener cannot be retroactively relabeled by a
later boundary event. Explicit manual capture is intentionally independent of
automatic-report deduplication.

Automatic reports include `$exception_source` from the first known SDK boundary:
`laravel.queue_job`, `laravel.console_lifecycle`, or `laravel.octane_worker_error`.
Normal HTTP pipeline failures are reported and rendered by Laravel before the outer
middleware can catch them: their source is `laravel.reportable` and mechanism is
`middleware`. The safety-net middleware catch supplies `laravel.middleware` only
when an exception escapes to it, such as in a custom pipeline. Without a remembered
boundary source, the registered Laravel reportable callback supplies `laravel.reportable`.
Sources are never inferred from stack frames. Ordinary manual `captureException()`
calls omit the source unless an integration explicitly supplies it through the typed
`source` option.

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
emit the core's deduplicated exposure events with the originating context, even
when read after another request starts or the original scope ends; payload reads
are silent. An explicit
empty `flagKeys: []` returns an empty snapshot without evaluation.

The full core signature is supported: `distinctId`, `groups`, `personProperties`,
`groupProperties`, `onlyEvaluateLocally`, `disableGeoip`, and `flagKeys`.
The manager also supports the core-compatible `getAllFlags()`, `getFeatureFlag()`,
`getFeatureFlagResult()`, `getFeatureFlagPayload()`, and `isFeatureEnabled()` methods
with current context, plus `loadFlags()` and `contextFromHeaders()`. These methods
are declared explicitly; new core methods are not exposed automatically. Use
`client()` for other lower-level PHP SDK operations:

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
identified use, preserving local-only options. Like `evaluateFlags()`, they inherit
context groups when the groups argument is empty; explicit nonempty groups override
them. With the raw `client()` escape hatch, call `loadFlags()` explicitly before local
evaluation if definitions have not loaded.

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
refresh lazily after the interval. The interval only throttles within a single
scoped manager: without a cache provider, each new request/job that evaluates flags
with a secret key performs a blocking definition fetch, even within the interval.
For production local evaluation, enable `flag_cache` with a shared store, or configure
a custom provider. `posthog:doctor` warns about a secret key without a provider.
No cache is enabled implicitly, and there is no background timer. The PHP client's
`loadFlags()` remains available for an explicit refresh. You can instead supply a
custom provider class in `sdk.flag_definition_cache_provider`.

## Laravel Pennant

An optional read-only PostHog store supports Pennant boolean and variant reads.
See [Pennant setup, scope mapping and cache behavior](PENNANT.md).

## Laravel queued event delivery

Delivery runs in the current PHP process by default. To hand event batches to
Laravel workers instead, opt in:

```dotenv
POSTHOG_LARAVEL_QUEUE_ENABLED=true
POSTHOG_LARAVEL_QUEUE_CONNECTION=redis
POSTHOG_LARAVEL_QUEUE_NAME=posthog
```

This Laravel queue is separate from the SDK's in-memory event buffer. SDK
batching remains active in both delivery modes.

Run a worker for that connection and queue, with an appropriate retry policy:

```bash
php artisan queue:work redis --queue=posthog --tries=3 --backoff=10
```

The corresponding configuration is `posthog.laravel_queue.enabled`,
`posthog.laravel_queue.connection`, and `posthog.laravel_queue.name`. Omitting the connection or
queue uses Laravel's defaults. A `sync` connection executes delivery inline: it
provides neither off-process delivery nor persistent jobs. Rebuild cached config
and restart workers after changing settings. Already-published `config/posthog.php`
files must also receive the new `laravel_queue` section from the package config.

Events still use SDK batching. Batch-size thresholds and lifecycle flushes dispatch
one job per prepared batch, without making a PostHog ingestion request in the
producer. `capture()` can therefore return before a Laravel job has been persisted;
`flush()`/`shutdown()` drain the in-process buffer into Laravel, not through the
worker to PostHog. A successful flush acknowledges handoff, **not delivery**. If the
queue backend rejects dispatch, an explicit flush returns false and the batch
remains in memory for a later attempt. There is no fallback to direct HTTP.
Laravel's connection-level `after_commit` behavior still applies: a deferred job
is not persisted until commit. On rollback, the SDK restores the frozen batch to
its in-process buffer for a later flush, including events captured before the
transaction. Failed deferred writes also restore the batch without failing the
already-committed application transaction. These batches remain in memory, not
durable storage, until a subsequent flush successfully hands them to Laravel.
Model autocapture remains commit-only: rolled-back model changes do not emit events.
Buffered events and batches awaiting commit share `sdk.max_queue_size`. Further
captures return false when that capacity is full. Successful dispatch releases
capacity; rollback and failed deferred writes transfer their slots back to the
buffer until retried. This in-process limit does not cap jobs already persisted
by Laravel.

Workers send verified HTTP using the SDK's cURL transport. Transient transport and
HTTP failures escape the job so Laravel can retry; terminal rejections mark it
failed immediately. Laravel controls the outer retry budget/backoff and failed-job
storage; the SDK's bounded HTTP retries still apply within an attempt. Configure a
bounded `POSTHOG_TIMEOUT_MS`, allow enough worker time for those attempts, and keep
the queue's `retry_after` (or visibility timeout) longer than the worker timeout.
Monitor failed jobs and retry them after correcting configuration or outages.
Retries can deliver a batch more than once; there is no exactly-once guarantee, and
multiple workers can reorder batches. Retries retain the original event UUIDs and
timestamps for ingestion deduplication.

The worker uses its current project key and host; configure producers and workers
for the same project. API and secret keys are not serialized into jobs. Prepared
events do include their original identity, session, groups and event properties,
including a collected request IP. Unlike the bounded context metadata on ordinary
application jobs, the delivery payload is the complete event batch. Delivery jobs
use Laravel's `ShouldBeEncrypted` contract to encrypt the serialized command in
queue and failed-job storage. Producers and workers need the same valid `APP_KEY`.
Keep decryption keys available for outstanding and failed jobs when rotating keys.
Encryption increases payload size. It does not encrypt the entire queue envelope
or application logs, so queue access controls and retention still matter. Delivery
jobs omit ambient `_posthog` metadata rather than reintroducing values removed by
capture hooks. Capture hooks run before persistence and are not rerun by the worker;
worker context cannot replace the original attribution. Disabling PostHog
or selecting `noop` in workers discards pending batches without sending them; pause
workers instead when you need to retain pending delivery jobs.

Queue mode uses verified cURL delivery regardless of the direct transport selection;
`consumer=noop` still disables event delivery. Compression and HTTP timeout settings
apply in the worker, including `http_timeout_ms` rather than socket seconds. Queue
backend payload limits also apply; lower the SDK batch size if needed. Feature-flag
requests remain synchronous, while their exposure events use queued delivery.
Internal delivery jobs and their delivery failures are excluded from automatic
analytics reporting to prevent recursive queues. This feature is independent of
`queue_context`, which propagates context into your application's existing jobs.

## Environment-based SDK tuning

The package configuration maps these environment variables to PHP SDK options
under `posthog.sdk`:

| Environment variable | SDK option | SDK default when unset |
| --- | --- | --- |
| `POSTHOG_TIMEOUT_MS` | `timeout` for cURL delivery; `http_timeout_ms` for SDK HTTP requests | 10000 milliseconds |
| `POSTHOG_SOCKET_TIMEOUT_SECONDS` | `timeout` for the socket consumer | 5 seconds |
| `POSTHOG_BATCH_SIZE` | `batch_size` | 100 events |
| `POSTHOG_MAX_QUEUE_SIZE` | `max_queue_size` | 10000 events |
| `POSTHOG_FLUSH_INTERVAL_SECONDS` | `flush_interval_seconds` | 5 seconds |
| `POSTHOG_COMPRESS_REQUEST` | `compress_request` | `false` |
| `POSTHOG_CONSUMER` | `consumer` | `lib_curl` |
| `POSTHOG_FF_REQUEST_TIMEOUT_MS` | `feature_flag_request_timeout_ms` | 3000 milliseconds |
| `POSTHOG_FF_REQUEST_MAX_RETRIES` | `feature_flag_request_max_retries` | 1 retry |

For example, with the default cURL consumer:

```dotenv
POSTHOG_TIMEOUT_MS=5000
POSTHOG_BATCH_SIZE=50
POSTHOG_FF_REQUEST_MAX_RETRIES=0
```

The event-delivery timeout is selected by `POSTHOG_CONSUMER`: `socket` uses
`POSTHOG_SOCKET_TIMEOUT_SECONDS`; cURL consumers use `POSTHOG_TIMEOUT_MS`.
The inactive transport's timeout does not affect event delivery. For example, use
`POSTHOG_CONSUMER=socket` with `POSTHOG_SOCKET_TIMEOUT_SECONDS=0.25` for a
quarter-second socket timeout.

`POSTHOG_TIMEOUT_MS` also controls SDK HTTP requests, including local flag-definition
fetches, even when events use the socket consumer. Those requests retain their
10000-millisecond default when it is unset; socket seconds never become HTTP
milliseconds. Positive fractional milliseconds round up rather than disabling the
timeout. Explicit `POSTHOG_TIMEOUT_MS=0` disables the HTTP timeout.
`POSTHOG_FF_REQUEST_TIMEOUT_MS` separately controls remote flag evaluation requests.
There is no ambiguous `POSTHOG_TIMEOUT` mapping.

Unset or empty variables leave the SDK's defaults in control. Numeric strings are
converted to integers or floats, including fractional socket timeouts and flush
intervals. Explicit `false` compression and `0` retries are preserved. A zero flush
interval means flush on every enqueue; otherwise the interval is checked when
another event is enqueued, not by a background timer. HTTP, job and command lifecycle
flushes still apply. Consumer names are `lib_curl`, `socket`, `fork_curl`, `file` and
`noop`; `noop` disables event delivery, not feature-flag requests.

If you already published `config/posthog.php`, update it using the package's
[`config/posthog.php`](config/posthog.php) as a reference. Copy the numeric conversion
helpers and consumer lookup above `return`, as well as the environment-mapped `sdk`
section. Updating the package or running `posthog:install` does not overwrite an
existing published config file. Keep your custom SDK settings and callback classes
when updating that section.
You can also configure the existing SDK options directly there instead of using
environment variables. No new flag fallback or local-only evaluation behavior is
introduced by these mappings.

Environment variables are read only while loading Laravel configuration. After
changing them in a deployment that caches config, rebuild it with
`php artisan config:cache` and restart long-running workers. Do not call `env()`
from runtime SDK callbacks or application services; use `config()` instead.

## Configuration callbacks and full PHP access

`posthog.sdk` passes PHP SDK options through. `before_send`, `error_handler`, and
`flag_definition_cache_provider` accept container class names, so published config
can be cached without serializing closures. Callback classes must implement the
corresponding SDK callable/interface contract. The Laravel library metadata and
framework handler ownership are enforced by the wrapper. `release_id` sets release
metadata; advanced SDK options remain accessible under `sdk`.

Client construction remains lazy. Invalid SDK configuration raises an initialization
error on first client use rather than silently disabling analytics. Run
`php artisan posthog:doctor` as a deployment check: it constructs the client and
reports initialization failures without printing credentials, loading flag
definitions or sending a diagnostic event. Custom container callbacks/providers
are resolved, so their constructors should not perform network work.

The supported PHP surface consists of the manager, facade, documented `Context`
methods, `IsolatesCommandContext` trait, test fake, service provider registration,
and Laravel flag-definition cache provider. Classes and methods marked `@internal`
are framework plumbing, not supported customer APIs or extension points, even when
Laravel or other package classes require their methods to be public.

## Diagnostics

- `php artisan posthog:doctor`: local configuration validation, no network or keys in output.
- `php artisan posthog:install`: publish config without overwriting an existing file.
- `php artisan posthog:test --send`: explicitly opt in to a diagnostic event with a random test ID.

The diagnostic event excludes ambient identity, session, groups and request/user
properties without changing the caller's context. Application-owned `before_send`
callbacks still run and may deliberately enrich or drop it.

Optional server event collectors are included but **all are off by default**.
The main provider discovers the separate `AutocaptureServiceProvider` automatically.
See [collector configuration and exact event schemas](AUTOCAPTURE.md) for request
summaries, separately enabled server HTML pageviews, auth outcomes, explicit domain
mappings, after-commit model events, worker outcomes, and Blade flag conditionals.
Server pageviews can duplicate browser SDK pageviews: enable only one equivalent
source. These collectors do not observe UI interactions or emit `$autocapture`.

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
