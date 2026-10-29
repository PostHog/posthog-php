# Optional server event collectors

These are **server events**, not UI interaction autocapture. The package does not
observe clicks or emit `$autocapture`. All collectors and Blade flag conditionals
are off by default. The main `PostHogServiceProvider` discovers the separate
`PostHog\Laravel\AutocaptureServiceProvider` automatically. Register only the main
provider; registering the collector provider again does not add duplicate listeners.

## Configuration

Publish the optional configuration:

```sh
php artisan vendor:publish --tag=posthog-autocapture-config
```

Edit `config/posthog-autocapture.php`. The complete defaults are in
[`config/autocapture.php`](config/autocapture.php). These settings are merged into
`posthog.autocapture`; values explicitly set under `autocapture` in
`config/posthog.php` take precedence **per top-level section**. When overriding a
section there, include its complete settings. Both approaches support config caching.
Use class names, not closures, in configuration.

```php
return [
    'requests' => [
        'enabled' => true,
        'exclude' => ['up', 'health', 'health/*', 'assets/*', 'build/*', '*.css', '*.js'],
        'sample_rate' => 1.0,
    ],
    'pageviews' => ['enabled' => false],
    'auth' => [
        'enabled' => true,
        'events' => ['registered', 'login', 'logout'],
    ],
    'models' => [
        'enabled' => true,
        'allow' => [App\Models\Order::class => ['state']],
    ],
    'domain_events' => [
        App\Events\OrderCompleted::class => [
            'event' => 'order_completed',
            'properties' => ['amount' => 'amount', 'currency' => 'details.currency'],
        ],
    ],
    'jobs' => ['enabled' => true, 'exclude' => [App\Jobs\InternalMaintenance::class]],
    'commands' => ['enabled' => false, 'exclude' => ['posthog:*']],
    'scheduler' => ['enabled' => false, 'exclude' => []],
    'blade' => ['enabled' => false],
];
```

Every `enabled` default is `false`. `domain_events` and `models.allow` default to
empty arrays. Auth defaults include only registered/login/logout; add `failed` and
`lockout` explicitly to capture those outcomes. `exclude` patterns use Laravel's
case-sensitive `Str::is` wildcard matching. Request exclusions match the route
and concrete path **only for filtering**, without capturing that concrete path.
Defaults exclude `up`, `health`, `health/*`, `build/*`, `assets/*`, `vendor/*`,
`favicon.ico`, `robots.txt`, and common asset extensions; add your application's
other health/static routes. Unmatched routes are never captured. Sampling clamps
`requests.sample_rate` to 0–1 and makes one decision per request for both summary
and pageview events. Zero drops both; the default 1 captures every eligible request.

### Privacy and identity

Collectors emit only the properties listed below, plus normal SDK metadata and
explicit identity/groups. Arbitrary context properties are not copied. Collection
never reads request bodies, query values, cookies, credentials, email, IP addresses,
job arguments or full model/event payloads. Auth uses `getAuthIdentifier()`; configure
an opaque identifier rather than email if your application customizes that method.
Application-selected attributes/property mappings may contain PII: allowlist only
fields you intend to send and use `sdk.before_send` for additional redaction.

Events require an existing analytics identity; collectors do not invent anonymous
IDs. HTTP uses the runtime's lazy authenticated identity/header fallback. Queue jobs
use the runtime's bounded queue context propagation or explicit job context. Console
and scheduler events need explicit context or a configured identity resolver. Failed
login/lockout events may use existing anonymous context but **never** the attempted
user identifier. Missing credentials or disabled SDK configuration cannot send traffic.
Failures in analytics do not change application responses or replace original errors.

## Exact event schemas

The following properties are collector-owned. `duration_ms` is a nonnegative float
measured by a monotonic clock, not a trace/span. No request IDs or parameters are
added implicitly. Each event passes through the ordinary PHP capture pipeline.

| Event | Properties |
| --- | --- |
| `laravel_request` | `route: string`, `method: string`, `status_code: int`, `duration_ms: float` |
| `$pageview` | Request properties plus `$current_url: string`, `$pathname: string` |
| `laravel_auth_registered` | Empty properties |
| `laravel_auth_login` | `guard: string` when supplied |
| `laravel_auth_logout` | `guard: string` when supplied |
| `laravel_auth_failed` | `guard: string` when supplied; no credentials or attempted ID |
| `laravel_auth_lockout` | Empty properties; no request/credentials/attempted ID |
| `laravel_model_created` | `model: string` (exact class), `attributes: array` (only allowlisted JSON-safe values) |
| `laravel_model_updated` | Same as created; selected values at update time, not a before/after diff |
| `laravel_model_deleted` | Same as created; selected values at deletion time |
| Configured domain event name | Only explicitly mapped output names and JSON-safe values |
| `laravel_job` | `name: string` (queued job class, not display name), `outcome: 'success'\|'failed'`, `attempts: int`, `duration_ms: float` |
| `laravel_command` | `name: string` (first command token only), `outcome: 'success'\|'failed'`, `exit_code: int`, `duration_ms: float` |
| `laravel_scheduled_task` | `name: string` (task PHP class only), `outcome: 'success'\|'failed'\|'background_started'`, `exit_code: int\|null`, `duration_ms: float` |

### Requests and server pageviews

`route` and `$pathname` are normalized matched route templates with a leading `/`,
for example `/orders/{order}`, never `/orders/123`. Request summaries include all
methods and status codes for eligible matched routes. Escaping middleware errors
are summarized as 500 and rethrown unchanged; framework-rendered errors use the
response status. Streaming duration measures response creation, not body delivery.

Pageviews are a **separate opt-in** and require a matched route, HTML Content-Type,
GET method, and 2xx status. They can be enabled without request summaries. The
`$current_url` is the sanitized configured `app.url` origin (HTTP/HTTPS scheme,
host and optional port only) followed by the route template. Credentials, paths
and queries in `app.url` are discarded; the request Host header is not trusted.
Invalid configured origins suppress pageviews.

**Browser duplication warning:** disable server pageviews if a browser SDK already
captures the same pageviews, or disable equivalent browser pageviews. No automatic
cross-SDK deduplication is provided. Server pageviews do not track client-side
navigation, browser lifecycle or actual browser rendering.

### Models and transactions

Only exact allowlisted Eloquent classes are collected for created/updated/deleted
lifecycle events. An empty attribute list emits class metadata with an empty
`attributes` array—no implicit primary key, relation, dirty attributes or `toArray()`.
Only existing attributes are read, with their normal casts/accessors; JSON-safe
scalar/null/array values are accepted, objects/resources/invalid JSON values are
omitted. Do not allowlist a sensitive accessor or JSON column.

Values, identity and groups are snapshotted at the change, then emitted **after the
model connection's outer transaction commits**. Rollback (including a nested
savepoint rollback) discards the corresponding pending events. No model object is
retained by the commit callback. Outside a transaction, collection is immediate.
This is not a durable outbox; process crashes or transport failures can lose events.
Each actual model lifecycle event generates one event: multiple saves are not
coalesced. Bulk query-builder updates/deletes do not produce model events. Soft
deletes produce the normal deleted event; restores are not collected.

### Domain events

Mappings select exact event classes and named public property/array-key paths.
No wildcard subscriptions, getter calls, magic model attribute access, model
serialization or automatic inheritance matching are performed. Missing paths and
non-JSON-safe values are omitted. Domain collection is immediate, including inside
a transaction; use Laravel's own after-commit event dispatch when the domain event
must reflect committed state. Mapping is read at provider boot, so rebuild cached
config/restart long-running workers after changing it.

### Worker outcomes and duplication

Jobs emit once per started attempt; a retry is another attempt, not deduplicated
against the previous failure. Repeated completion events for an already completed
attempt are ignored. Command metadata excludes all arguments/options. Scheduler
names deliberately use task PHP classes, not descriptions or command lines. A
foreground task emits one outcome even when Laravel emits Finished then Failed for
a nonzero exit. Background launch emits `background_started`, **not completion**;
there is no cross-process completion/heartbeat guarantee. Skipped scheduled tasks
and hard-killed jobs/commands without a terminal framework event are not captured.
Weak object keys retain neither worker payloads nor completed execution objects.

Enabling several collectors intentionally produces different events for different
boundaries: a request that logs in a user and saves a model can produce request,
auth, and model events. Explicit application captures remain independent. The
package does not deduplicate these against your manual instrumentation.

## Optional Blade flag conditionals

Enable `blade.enabled`, then use:

```blade
@posthogFeature('new-checkout')
    <x-checkout />
@else
    <x-classic-checkout />
@endposthogFeature
```

This reads `evaluateFlags()->isEnabled()` using the runtime's existing context
snapshot. Repeated checks share evaluation and core exposure deduplication; a new
identity/context evaluates separately. Disabled/unavailable flags fail closed.
There is no boot-time evaluation, payload read, independent flag cache, or custom
exposure event. This conditional is a presentation helper, **not authorization**.
