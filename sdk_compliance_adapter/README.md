# PostHog PHP SDK compliance adapter

The adapter runs the repository's `PostHog\Client` with production capture consumers
and production `HttpClient` feature-flag requests. Harness 1.0.0 selects **30 server
capture V0 tests and 17 flag tests per profile**, including UTC timestamp overrides
and gzip. No tests are filtered; compliance assertions remain advisory in CI.

## Profiles

| `POSTHOG_CONSUMER` | Capture transport | Completion |
| --- | --- | --- |
| `lib_curl` (default) | SDK LibCurl + HttpClient | Verified synchronous HTTP |
| `socket` | SDK Socket | Synchronous socket response handling |
| `fork_curl` | SDK ForkCurl + system curl/gzip | Foreground, using existing `debug=true` |

CI runs all three profiles with distinct report artifacts. All profiles use
`debug=true`, no local flag definitions/secret key, and SDK flag-called events.
Compression is enabled only when requested at `/init`. Flags use the SDK's own
uncompressed HTTP client even when capture compression is enabled.

The adapter maps `flush_at` to `batch_size` and `flush_interval_ms` to
`flush_interval_seconds` (SDK default: 5 seconds, enforced on enqueue, not an idle
timer). `max_retries` maps to the existing backoff-duration option: for three
retries, 801 ms for HttpClient and 800 ms for Socket, reflecting their different
limit checks. ForkCurl does not implement that retry option. Socket's connection
timeout is expressed in seconds; the PHP worker's `default_socket_timeout=1`
bounds reads, including idle retry connections. This is a configured runtime
profile, not certification of default PHP socket timing.

## Observation and isolation

`server.py` exposes `/health`, `/init`, `/capture`, `/get_feature_flag`, `/flush`,
`/state`, and `/reset`. It starts one PHP SDK worker per initialization. SDK traffic
passes through a loopback TCP relay to the supplied **HTTP mock host**. The relay
forwards request and response bytes unchanged, once per connection, without
retries or response substitution. It parses copies only for request telemetry;
flag values and side-effect events come from SDK APIs. HTTPS/TLS is not covered.

The public `before_send` callback returns the SDK-enriched event unchanged and
observes its UUID and capture count, including flag-called events. Capture input
omits UUID so generation happens inside `Client::capture`. Capture and flush
responses preserve the SDK's boolean result, including failures.

State has explicit observation limits:

- `pending_events` is `null` while initialized: there is no public queue-length
  accessor. Captured counts are events observed by `before_send`, not proof of
  enqueue or delivery.
- `requests_made` records HTTP responses observed at the relay, including flags.
  Connections without an HTTP response are not represented. Retry indices group
  identical request bytes within a single adapter action; separate flag calls
  start independent sequences.
- `total_events_sent` counts events in observed HTTP 200 batches.
  `events_flushed` counts those events only during that flush action, not over the
  client lifetime. Neither counter overrides the SDK's success/failure result.

Reset kills and waits for only the owned PHP worker, without running its
queue-flushing destructors. It then closes the relay. This provides test isolation,
not SDK shutdown certification; `/flush` always calls the real public SDK method.

## Known failures and applicability

LibCurl is expected to pass all 47 selected tests. Socket and foreground ForkCurl
each expose these 11 existing capture failures:

- `capture.retry_behavior.retries_on_503`
- `capture.retry_behavior.retries_on_500`
- `capture.retry_behavior.retries_on_502`
- `capture.retry_behavior.retries_on_504`
- `capture.retry_behavior.respects_retry_after_header`
- `capture.retry_behavior.implements_backoff`
- `capture.retry_behavior.max_retries_respected`
- `capture.error_handling.retries_on_408`
- `capture.deduplication.preserves_uuid_on_retry`
- `capture.deduplication.preserves_uuid_and_timestamp_on_retry`
- `capture.deduplication.preserves_uuid_and_timestamp_on_batch_retry`

Socket does not reset its write state on retry, treats 408 as terminal, and ignores
Retry-After. ForkCurl does not retry HTTP rejection and reports a successful curl
process exit as successful delivery even for HTTP errors. The adapter exposes
these behaviors rather than retrying on the SDK's behalf.

Background ForkCurl and LibCurl's explicit fire-and-forget option do not promise
blocking verified delivery and are not selected. File/noop consumers are not HTTP
sinks; file replay is not covered. Capture V1, dedicated AI capture, and non-gzip
encodings are not supported capabilities of this adapter's SDK entry.

## Running

```sh
cd sdk_compliance_adapter
POSTHOG_CONSUMER=socket docker compose up --build --abort-on-container-exit --exit-code-from test-harness
```

For native PHP 8.3, Python 3, curl and gzip, from the repository root:

```sh
composer install --prefer-dist --no-progress
PORT=18270 PROXY_PORT=19271 BIND_HOST=127.0.0.1 POSTHOG_CONSUMER=lib_curl \
  python3 sdk_compliance_adapter/server.py
```

Run the harness against that adapter with a separate mock port. The adapter does
not support parallel test execution. `PORT` defaults to 8080 and `PROXY_PORT` to
8082 inside containers; choose unused loopback ports for concurrent local work.

Adapter regression checks run during each Docker build and can also run locally:

```sh
TEST_MOCK_PORT=19276 TEST_PROXY_PORT=19277 PYTHONDONTWRITEBYTECODE=1 \
  python3 -m unittest discover -s sdk_compliance_adapter -p 'test_*.py' -v
```

These checks cover byte-preserving relay behavior, SDK-generated UUIDs, immediate
capture, production retries, compression plus flags, real SDK result booleans,
worker failure, and reset isolation. They use local mock traffic only.
