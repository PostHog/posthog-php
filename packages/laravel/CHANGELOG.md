# posthog-laravel

## 0.4.2

### Patch Changes

- Updated dependencies:
  - posthog-php@4.24.0

## 0.4.1

### Patch Changes

- Updated dependencies:
  - posthog-php@4.23.1

## 0.4.0

### Minor Changes

- Read the project token from `POSTHOG_PROJECT_TOKEN`. `POSTHOG_API_KEY` still works as a fallback, and the Laravel config gains a `project_token` key. The `api_key` key is deprecated and still read when `project_token` is blank.

### Patch Changes

- Updated dependencies:
  - posthog-php@4.23.0

## 0.3.1

### Patch Changes

- Suppress definition-load warnings for client-only flags. Preserve PHP's fresh-response-only warning policy to avoid per-request log spam from shared-cache readers, while reconciling warning history on cache loads so flags warn again after returning from client-only runtime.

- Updated dependencies:
  - posthog-php@4.22.1

## 0.3.0

### Minor Changes

- Add opt-in Laravel queued event delivery with prepared batches, verified worker delivery, and framework-managed retries.

## 0.2.1

### Patch Changes

- Updated dependencies:
  - posthog-php@4.22.0

## 0.2.0

### Minor Changes

- Collect Laravel request IPs through trusted-proxy-aware request context, with an opt-out and GeoIP still disabled by default.

- Add environment mappings for SDK transport and flag-request settings, with explicit timeout units and unchanged SDK defaults.

### Patch Changes

- Updated dependencies:
  - posthog-php@4.21.0

## 0.1.3

### Patch Changes

- Add Laravel package authors, keywords, homepage, and support links

## 0.1.2

### Patch Changes

- Capture Laravel automatic events without an identity as personless events with a fresh UUID per event.

## 0.1.1

### Patch Changes

- Test the release process

- Updated dependencies:
  - posthog-php@4.20.2

## 0.1.0

### Minor Changes

- Initial Laravel package
