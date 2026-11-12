---
"posthog-php": minor
---

A send with `template_key` takes a `category`. Leave it out to send transactional email as before, or to follow the category of the template that `template_key` names. `Emails::CATEGORY_TRANSACTIONAL` sends transactional email with no category, so PostHog refuses it with `category_mismatch` on a template in a category. To file the email under a message category, pass the category itself:

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
    'html' => $renderedHtml, // must contain {{ unsubscribe_url }}
]);
```

PostHog creates the category the first time a send names its key, or restores it if it was deleted, and otherwise leaves it unchanged. Its `name` and `description` apply only when the send creates the category; change them later in PostHog. A marketing send honors the recipient's opt-outs, adds unsubscribe headers, and needs `{{ unsubscribe_url }}` in the body. A template made in PostHog is transactional, so PostHog refuses a send whose `template_key` names a template in a marketing category with `marketing_template`. PostHog refuses a send with the new `EmailError` codes `category_mismatch`, `too_many_categories`, `unsubscribe_link_required`, `copies_not_allowed`, `marketing_template` or `recipient_opted_out`.
