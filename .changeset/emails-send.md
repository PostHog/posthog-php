---
"posthog-php": minor
---

Add transactional email: `$client->emails()->send()` and `PostHog::emails()->send()` queue one email from a template made in PostHog (`template_id`, `template_data`) or from content your code rendered (`template_key`, `subject`, `html` and/or `text`), and `emails()->get()` reads its status. Sending needs a project secret API key (`phs_...`) as `$secretKey`. New client options: `app_host`, `email_request_timeout_ms`, `email_request_max_retries` and `migrated_from`. A failed send throws `PostHog\EmailError`.
