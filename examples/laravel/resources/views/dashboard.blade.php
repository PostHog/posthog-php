<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PostHog Laravel demo</title>
    <style>
        body { font: 17px/1.5 system-ui, sans-serif; max-width: 960px; margin: 2rem auto; padding: 0 1rem; color: #202020; }
        section { border: 1px solid #ccc; padding: 1rem; margin: 1rem 0; border-radius: .5rem; }
        button { padding: .5rem 1rem; cursor: pointer; } code, pre { background: #f2f2f2; padding: .2rem; }
        pre { overflow: auto; padding: 1rem; } table { width: 100%; text-align: left; }
        form { display: inline-block; margin: .3rem; } small { display: block; }
    </style>
</head>
<body>
<h1>PostHog Laravel demo</h1>
<p>Server-side analytics, flags, exceptions, and opt-in collectors. No npm or browser SDK required.</p>
<p><strong>Local development only.</strong> Synthetic login has no password. Do not expose this app publicly.</p>
<p>SDK: <strong>{{ $enabled ? 'configured' : 'disabled (blank key or POSTHOG_ENABLED=false)' }}</strong>.
    Authentication: {{ auth()->check() ? 'synthetic member' : 'guest' }}.
    Missing credentials are safe: SDK network paths are disabled.</p>
@if (session('result'))
<section aria-label="Last result"><h2>Last result</h2><pre>{{ json_encode(session('result'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></section>
@endif
<section>
    <h2>Explicit and ambient capture</h2>
    <p>Explicit ID <code>demo-explicit</code> wins. Ambient capture uses auth, then untrusted analytics headers,
        then this application's synthetic <code>demo-visitor</code> fallback. Both use team <code>demo-team</code>.</p>
    <form method="post" action="{{ route('demo.run', 'capture') }}">@csrf<button>Capture two events</button></form>
    <form method="post" action="{{ route('demo.run', 'identity') }}">@csrf<button>Identify, alias, group identify</button></form>
    <small>Identity operations use fixed demo IDs; don't alias unrelated real users.</small>
</section>
<section>
    <h2>Feature flags</h2>
    <p>Create boolean <code>demo-checkout</code> and multivariate <code>demo-layout</code> flags in your project.
        One snapshot supplies enabled state, variant, decoded payload, and an accessed-only event attachment.</p>
    <form method="post" action="{{ route('demo.run', 'flags') }}">@csrf<button>Evaluate flags and capture decision</button></form>
    <form method="post" action="{{ route('demo.run', 'pennant') }}">@csrf<button>Read flags through Pennant</button></form>
    <small>Pennant uses the same SDK snapshots. The PostHog store is read-only; manage flags in PostHog.</small>
    <p><code>POSTHOG_SECRET_KEY</code> (server only) + <code>POSTHOG_FLAG_CACHE=true</code> enables shared local definitions.
        <code>POSTHOG_LOCAL_ONLY=true</code> disables remote fallback. Unresolved reasons are shown separately
        (missing context, unsupported definition, dependency, or experience continuity); an empty result with no key is expected.</p>
</section>
<section>
    <h2>Exceptions and transport</h2>
    <form method="post" action="{{ route('demo.run', 'exception') }}">@csrf<button>Manual handled exception</button></form>
    <form method="post" action="{{ route('demo.run', 'report') }}">@csrf<button>Laravel report() (handled)</button></form>
    <form method="post" action="{{ route('demo.failure') }}">@csrf<button>Throw an unhandled 500</button></form>
    <form method="post" action="{{ route('demo.run', 'flush') }}">@csrf<button>Flush transport</button></form>
    <p>Laravel owns reporting; no global PHP error handlers. The failing route intentionally returns 500.
        Automatic lifecycle flushes clean context even on failure. A flush result is not a delivery receipt.</p>
</section>
<section>
    <h2>Application and worker collectors</h2>
    <form method="post" action="{{ route('demo.login') }}">@csrf<button>Synthetic login / register</button></form>
    <form method="post" action="{{ route('demo.logout') }}">@csrf<button>Logout</button></form>
    <form method="post" action="{{ route('demo.run', 'order') }}">@csrf<button>Create order + domain event</button></form>
    <form method="post" action="{{ route('demo.run', 'queue') }}">@csrf<button>Dispatch demo job</button></form>
    <p>Queue defaults to sync. For a real worker set <code>QUEUE_CONNECTION=database</code>, then run
        <code>php artisan queue:work --once</code>. Jobs inherit only bounded analytics identity/session/scalar groups, then demonstrate an explicit scoped override.
        Set <code>POSTHOG_QUEUE_CONTEXT=false</code> to disable propagation; request bodies and arbitrary properties are never copied.
        Run <code>php artisan demo:posthog --flags</code> and <code>php artisan schedule:run</code> for command/schedule demos.</p>
    <p>Collector provider: {{ $collectorsAvailable ? 'available' : 'not installed on this checkout; explicit demos still work' }}.
        Edit the following environment toggles, then clear config/restart workers. All are opt-in, not form-controlled.</p>
    <table><thead><tr><th>Collector / environment toggle</th><th>Enabled</th></tr></thead><tbody>
    @foreach (['requests', 'pageviews', 'auth', 'models', 'domain', 'jobs', 'commands', 'scheduler'] as $collector)
        <tr><td><code>POSTHOG_COLLECT_{{ strtoupper($collector) }}</code></td>
            <td>{{ $collector === 'domain' ? (!empty($collectors['domain_events']) ? 'yes' : 'no') : (!empty($collectors[$collector]['enabled']) ? 'yes' : 'no') }}</td></tr>
    @endforeach
    </tbody></table>
    <p>Requests use matched route templates, never raw URLs or query strings. Model attributes are explicitly
        allowlisted to amount/status; domain mapping sends amount only. No credentials, request bodies, email, cookies, or IP collection.</p>
</section>
<section>
    <h2>Optional browser integration</h2>
    <p>No JavaScript SDK is loaded here. If you add one, use only its public project token and ingestion host;
        never expose <code>POSTHOG_SECRET_KEY</code>. Configure tracing headers for your application host only:
        <code>X-PostHog-Distinct-Id</code> and <code>X-PostHog-Session-Id</code>. They correlate analytics, not authentication.</p>
    <p>Choose either browser pageviews or <code>POSTHOG_COLLECT_PAGEVIEWS</code>, not both: avoid duplicate
        <code>$pageview</code> events. Server pageviews use sanitized route-template URLs.</p>
</section>
<p>See <code>README.md</code> for fake assertions, offline tests, privacy, and setup.</p>
</body>
</html>
