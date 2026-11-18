---
"posthog-php": patch
---

Transactional email: send, attachment upload and status requests now use the key-scoped `/api/messaging/` routes instead of `/api/projects/@current/messaging/`. They need a PostHog version with those routes; an older self-hosted instance answers 404.
