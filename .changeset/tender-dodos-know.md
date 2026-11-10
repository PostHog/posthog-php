---
"posthog-php": minor
"posthog-laravel": minor
---

Read the project token from `POSTHOG_PROJECT_TOKEN`. `POSTHOG_API_KEY` still works as a fallback, and the Laravel config gains a `project_token` key. The `api_key` key is deprecated and still read when `project_token` is blank.
