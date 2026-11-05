# posthog-laravel

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
