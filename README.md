# PostHog PHP

[![PHP Version](https://img.shields.io/packagist/php-v/posthog/posthog-php?logo=php)](https://packagist.org/packages/posthog/posthog-php)
[![CI](https://github.com/PostHog/posthog-php/actions/workflows/php.yml/badge.svg)](https://github.com/PostHog/posthog-php/actions/workflows/php.yml)

Please see the main [PostHog docs](https://posthog.com/docs).

SDK usage examples and code snippets live in the official documentation so they stay up to date.

## Documentation

- [PHP library docs](https://posthog.com/docs/libraries/php)
- [Laravel integration docs](https://posthog.com/docs/libraries/laravel)
- [Upgrade notes](UPGRADING.md), including GeoIP configuration and compatibility

## OpenTelemetry context

The optional `PostHog\OpenTelemetry\ContextSpanProcessor` adds session and person IDs to application spans inside this client's request context. Register it on the application's existing OpenTelemetry provider. OpenTelemetry remains an optional application dependency.

A client with no project key keeps request context but adds no span attributes. A valid-key client with the `noop` consumer still adds them.

The registered processor also enables request span enrichment when `withContext()` starts. This lookup skips Fibers because uninitialized OpenTelemetry Fiber storage can return another execution path's span. Child spans still use the active Fiber's PostHog context. New Fibers and queue jobs need explicit context propagation.

Existing span attributes take precedence. The processor preserves the application's provider, sampling, and exporters. See the [PHP tracing guide](https://posthog.com/docs/distributed-tracing/installation/php) and [Laravel guide](https://posthog.com/docs/libraries/laravel) for setup.

## Questions?

### [Join our Slack community.](https://join.slack.com/t/posthogusers/shared_invite/enQtOTY0MzU5NjAwMDY3LTc2MWQ0OTZlNjhkODk3ZDI3NDVjMDE1YjgxY2I4ZjI4MzJhZmVmNjJkN2NmMGJmMzc2N2U3Yjc3ZjI5NGFlZDQ)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup and test instructions.

## Releasing

See [RELEASING.md](RELEASING.md).
