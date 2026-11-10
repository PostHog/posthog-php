---
"posthog-php": minor
"posthog-laravel": minor
---

Read the project token from `POSTHOG_TOKEN`. `POSTHOG_API_KEY` still works as a fallback, and the Laravel config key is now `token`, with `api_key` still read from configs published earlier.
