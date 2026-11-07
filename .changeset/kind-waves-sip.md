---
"posthog-php": patch
"posthog-laravel": patch
---

Suppress definition-load warnings for client-only flags. Preserve PHP's fresh-response-only warning policy to avoid per-request log spam from shared-cache readers, while reconciling warning history on cache loads so flags warn again after returning from client-only runtime.
