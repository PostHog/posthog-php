# PostHog PHP SDK Compliance Adapter

This adapter wraps the PostHog PHP SDK for the PostHog SDK compliance test harness.

## Local run

```sh
cd sdk_compliance_adapter
docker compose up --build --abort-on-container-exit --exit-code-from test-harness
```

The adapter exposes the standard harness endpoints: `/health`, `/init`, `/capture`, `/flush`, `/state`, and `/reset`.
