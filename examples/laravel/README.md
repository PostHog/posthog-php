# PostHog Laravel example

An original, minimal Laravel 12/13 application using `posthog/posthog-laravel` and
the PHP SDK from this checkout. No JavaScript build, npm, external database, or
PostHog credentials are needed to run it. **Local development only:** synthetic
login deliberately has no password; never expose this application publicly.

## Run

Requirements: PHP 8.2+ with Laravel's extensions (including DOM/XML, mbstring,
PDO SQLite) and cURL for live SDK transport; Composer 2. Dependency installation
requires access to Composer's package repositories. No vendor files are committed.

From this directory (`examples/laravel`):

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan serve
```

Open <http://localhost:8000>. Migration creates the SQLite file automatically.
The checked-in lock targets Laravel 12 / PHP 8.2. The Composer constraint also
supports Laravel 13 (PHP 8.3+); to try it on a compatible interpreter:

```sh
composer config platform.php 8.3.0
composer update --with-all-dependencies
composer test
```

The example's **development-only path repositories** point to `../..` (PHP SDK,
version override `4.20.x-dev`) and `../../packages/laravel` (integration, override
`0.1.0`). Run inside this repository, not as an isolated copy. They do not belong
in a published package manifest. The lock does not replace the local source trees.

## Safe defaults and configuration

With a blank `POSTHOG_API_KEY` or `POSTHOG_ENABLED=false`, **all SDK network paths
are disabled**, including flags and raw client access. Forms still run and show
false/empty SDK results. SQLite operations and synthetic login still work.

To send synthetic events to a development project, set `POSTHOG_API_KEY` and the
project's ingestion `POSTHOG_HOST` in `.env`. Keep `.env` private. Do not use
production credentials/data for this demo. Never put `POSTHOG_SECRET_KEY` in HTML,
browser code, event properties, logs, or a browser configuration endpoint.

`config/posthog.php` demonstrates SDK options, release metadata, GeoIP disabled,
and a container-resolved `before_send` callback. `RedactDemoProperties` strips
email/password top-level properties as defense in depth; it is **not** a complete
PII scrubber. Application properties and exception messages/stacks may be sensitive.
Request collection does not include raw URLs, query strings, bodies, cookies,
credentials, email, or IP. Synthetic model fields are explicitly allowlisted.

After changing configuration, use `php artisan config:clear` and restart workers.
`php artisan config:cache` also works (no closures in config).

## Dashboard and source map

| Form / command | What it demonstrates | Source |
| --- | --- | --- |
| Capture two events | explicit identity/groups and ambient scoped capture | `app/Support/DemoActions.php` |
| Identify, alias, group identify | synthetic member/visitor relationship, team profile | `DemoActions::identity()` |
| Evaluate flags | boolean, variant, decoded payload, one shared snapshot, `onlyAccessed()` attachment, unresolved reasons | `DemoActions::flags()` |
| Read flags through Pennant | optional read-only PostHog store; boolean and variant values | `DemoActions::pennant()` |
| Manual exception | explicit handled capture and safe properties | `DemoActions::exception()` |
| Laravel report() | handled framework reporting (no global PHP handlers) | `DemoActions::exception()` |
| Throw an unhandled 500 | escaping failure reporting and context cleanup | `DemoController::failure()` |
| Create order | SQLite transaction, model collector, explicitly mapped domain event | `DemoActions::order()`, `app/Events/OrderCompleted.php` |
| Login / logout | real Laravel session auth events using a synthetic user | `app/Http/Controllers/DemoController.php` |
| Dispatch job | default sync queue; bounded analytics propagation plus explicit job scope override | `app/Jobs/DemoJob.php` |
| Flush | explicit transport result; lifecycle flush is automatic | `DemoController::run()` |
| `php artisan demo:posthog --flags` | the same APIs from Artisan (`--flags` is optional) | `app/Console/Commands/DemoCommand.php` |
| `php artisan schedule:run` | named once-per-minute scheduled command | `routes/console.php` |

The example-only `DemoServiceProvider` assigns `demo-console` to the known
`demo:posthog`, `demo:scheduled`, and `schedule:run` commands for their complete
lifetime, so command/task outcomes have an actor. This is a synthetic diagnostic
identity, not real-user attribution, and does not change HTTP identity.

Captures use `demo_*` event names. These are custom business events, **not**
automatic duplicate `$pageview` events. `flush()` is not a delivery receipt.
Transport defaults to `lib_curl`; `POSTHOG_CONSUMER=noop` drops event delivery but
**does not disable flag network requests** when a project key is present. Use
`POSTHOG_ENABLED=false` or blank credentials for a genuinely offline app.

### Context and tracing headers

`Context::with()` restores prior context on success/failure. Request auth identity
wins over the `X-PostHog-Distinct-Id` anonymous analytics fallback; explicit capture
identity wins over both. `X-PostHog-Session-Id` adds `$session_id`. This application's
explicit synthetic fallback is `demo-visitor`, not an SDK-generated anonymous ID.
**Headers are untrusted analytics context, never authentication/authorization.**

The runtime propagates only bounded identity, session and scalar groups to queue
payload `_posthog` metadata. It does not serialize arbitrary context properties,
models or request data. Set `POSTHOG_QUEUE_CONTEXT=false` to opt out. The job first
captures with the propagated identity, then overrides it with `demo-job-owner`.
For a persistent worker instead of sync:

```sh
# Set QUEUE_CONNECTION=database in .env, then click Dispatch job.
php artisan queue:work --once
```

### Feature flags and local evaluation

Create `demo-checkout` (boolean) and `demo-layout` (multivariate, optionally with a
JSON payload) in your development project. Flag evaluation is explicit: dashboard
GET and ordinary capture do not evaluate flags. Value reads emit deduplicated core
exposure events; payload reads are silent. Snapshot attachment adds
`$feature/<key>` and `$active_feature_flags` without reevaluating. Missing or
disabled keys are distinct from local unresolved reasons.

For local evaluation set the **server-only** `POSTHOG_SECRET_KEY` and optionally
`POSTHOG_FLAG_CACHE=true`. Definitions load/refresh lazily and use Laravel Cache;
the default file store is suitable for a local demo, not a distributed production
cache. `POSTHOG_LOCAL_ONLY=true` prevents remote flag fallback, but a secret-key
worker may still fetch definitions. Unresolved entries can report missing context,
unsupported definitions, unresolved dependencies or experience continuity. Without
local definitions, local-only evaluation is empty; it is not proof a flag is off.

The Pennant form uses `Feature::store('posthog')` explicitly, leaving other Pennant
stores unchanged. Its values come from the same native SDK snapshots; write operations
are intentionally unsupported. This form is disabled when `POSTHOG_LOCAL_ONLY=true`;
use the native flags form for local-only evaluation. Direct POSTs are guarded too.
See the integration's `PENNANT.md` for scope mapping
and when to clear Pennant's cache after targeting changes within a request.

Advanced PHP SDK methods remain accessible without another global SDK instance:

```php
$client = \PostHog\Laravel\Facades\PostHog::client();
$scope = $client->enterContext(['distinctId' => 'demo-advanced'], ['fresh' => true]);
try {
    $client->capture(['event' => 'demo_advanced', 'properties' => ['source' => 'raw-client']]);
} finally {
    $scope->close();
}
```

### Collector opt-ins

All collector flags in `.env.example` default to false. The dashboard displays
configuration, not a remote configuration editor. The separate collector provider
must be present for automatic events; explicit runtime examples do not depend on it.

| Toggle | Events / limits |
| --- | --- |
| `POSTHOG_COLLECT_REQUESTS` | `laravel_request`: matched route template, method, status, duration; excludes health routes |
| `POSTHOG_COLLECT_PAGEVIEWS` | canonical `$pageview`, successful matched HTML GET only, sanitized template URL; don't also enable browser pageviews |
| `POSTHOG_COLLECT_AUTH` | `laravel_auth_registered/login/logout/failed/lockout`; no attempted credentials/identity for failure events |
| `POSTHOG_COLLECT_MODELS` | `laravel_model_created/updated/deleted`, after commit; `DemoOrder` amount/status only, rollback discarded |
| `POSTHOG_COLLECT_DOMAIN` | explicit `OrderCompleted` → `order_completed`, amount only; no whole-event serialization |
| `POSTHOG_COLLECT_JOBS` | `laravel_job`, safe name/outcome/duration, no payload |
| `POSTHOG_COLLECT_COMMANDS` | `laravel_command`, command name/outcome/duration, no arguments |
| `POSTHOG_COLLECT_SCHEDULER` | `laravel_scheduled_task`, safe task name/outcome/duration |

### Optional browser JavaScript

No browser SDK, script tags, or key-bearing HTML are installed. If you add browser
analytics, use only its public project token and ingestion host. Configure tracing
headers for your application's host only and keep secret keys server-side.
Choose **either** browser pageviews **or** the server pageview collector to avoid
double-counting. Browser identity headers do not authenticate the synthetic login.

## Repeatable offline tests

```sh
composer test
```

Tests use a fresh in-memory SQLite database, `PostHog::fake()`/mocked transport,
no real keys, no live PostHog, and no queue server. They exercise dashboard HTML,
POST forms, auth/header precedence, flag variant/payload/snapshot/unresolved output,
handled and unhandled exceptions, queue payloads/opt-out, transport failure results,
Artisan, and each collector family (including commit/rollback). The collector provider is required for those integration tests; no collector
coverage is silently skipped. Tests explicitly enable Symfony command event
rerouting because Laravel normally omits it under `runningUnitTests()`.

Example fake assertions (see `tests/Feature/DashboardTest.php`):

```php
$fake = \PostHog\Laravel\Facades\PostHog::fake();
$this->post('/demo/capture')->assertRedirect('/');
$fake->assertCaptured('demo_explicit', fn ($event) => $event['distinctId'] === 'demo-explicit');
$fake->assertCapturedCount(2);
$fake->assertNotCaptured('$pageview');
```

The fake supports identify/alias/group/exception assertions and fixture flag
snapshots. Raw `client()` escape-hatch calls on the fake are disabled and **not
recorded**. Tests assert message payloads; they do not claim server delivery.

`php artisan posthog:doctor` checks configuration without network (nonzero exit is
expected with blank credentials). `posthog:test --send` is a deliberate live-send
opt-in and is not used by this application's tests.

The examples follow the SDK specs for capture, identify, alias, group identify,
exception capture, flags, tracing headers, flush and autocapture. No changes to
core SDK semantics or public APIs are made here; server collector names are the
Laravel integration contract, not new canonical reserved SDK events.
