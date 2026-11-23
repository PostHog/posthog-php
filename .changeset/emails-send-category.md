---
"posthog-php": minor
---

A send with `template_key` takes a `category`. Leave it out to send transactional email as before, or, when code-send categories are enabled for the project, to follow the category of the template that `template_key` names. `Emails::CATEGORY_TRANSACTIONAL` sends transactional email with no category, so PostHog refuses it with `category_mismatch` on a template in a category. Likewise, passing a category object when `template_key` matches an uncategorized saved template returns `category_mismatch`: use a different `template_key` for a marketing send (a transactional-enabled saved template assigned to a marketing category returns `marketing_template`, while one without transactional sending enabled returns `template_not_transactional`), or, for a transactional send, either use a different `template_key` or assign the saved template to that transactional category in PostHog first. To file the email under a message category, pass the category itself:

```php
PostHog::emails()->send([
    'to' => 'user@example.com',
    'distinct_id' => 'user_123',
    'template_key' => 'monthly-news',
    'category' => [
        'key' => 'newsletter',
        'type' => 'marketing', // or 'transactional'
        'name' => 'Newsletter',
        'description' => 'Monthly product news', // optional
    ],
    'subject' => 'This month at Acme',
    'html' => '<p>This month at Acme</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
]);
```

When `template_key` doesn't match a saved template, PostHog creates the category the first time a send names its key, or restores it if it was deleted, and otherwise leaves it unchanged. When `template_key` matches a saved template and code-send categories are enabled for the project, the send uses that template's own category without restoring an archived category (with code-send categories disabled, omitting `category` uses the legacy path and queues without the saved template's category). Its `name` and `description` apply only when the send creates the category; change them later in PostHog. When code-send categories are not yet enabled for the project, PostHog refuses an explicit category array with `categories_not_enabled`. A marketing send honors the recipient's opt-outs, adds unsubscribe headers, and needs a clickable `<a href="{{ unsubscribe_url }}">` link in `html` (or `{{ unsubscribe_url }}` in `text` when there is no `html`). A marketing code send takes no `cc` or `bcc` (`copies_not_allowed`) and cannot carry `attachments` (`workflow_can_wait` on a verified sender because marketing sending limits can hold delivery, or `sandbox_fields_unsupported` when using the PostHog test sender; a refused send can still upload attachments PostHog doesn't already have, skipping uploads for files PostHog reports as `present`). A template made in PostHog must be enabled for transactional sends (`template_not_transactional` otherwise); when an otherwise sendable template is in a marketing category, PostHog refuses a send whose `template_key` names it with `marketing_template`. PostHog refuses a send with the `EmailError` codes `category_mismatch`, `too_many_categories`, `unsubscribe_link_required`, `copies_not_allowed`, `workflow_can_wait`, `sandbox_fields_unsupported`, `template_not_transactional`, `marketing_template`, `categories_not_enabled` or `recipient_opted_out`.
