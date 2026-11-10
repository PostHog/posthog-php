---
"posthog-php": minor
---

A send with `template_key` takes a `category`. Leave it out, or pass `Emails::CATEGORY_TRANSACTIONAL`, to send transactional email as before. To file the email under a message category, pass the category itself:

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

PostHog creates the category the first time a send names its key, and updates its name and description when they change. Leaving `description` out, or null, keeps the stored one, and `''` clears it. A marketing send honors the recipient's opt-outs, adds unsubscribe headers, and needs `{{ unsubscribe_url }}` in the body. A template made in PostHog follows its own category. PostHog refuses a send with the new `EmailError` codes `category_mismatch`, `too_many_categories`, `unsubscribe_link_required`, `copies_not_allowed` or `recipient_opted_out`.
