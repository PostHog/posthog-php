---
"posthog-php": minor
---

`emails()->send()` takes a `category`. Leave it out, or pass `Emails::CATEGORY_TRANSACTIONAL`, to send transactional email as before. Any other value is the key of one of your project's marketing message categories:

```php
PostHog::emails()->send([
    'to' => 'user@example.com',
    'distinct_id' => 'user_123',
    'template_key' => 'weekly-digest',
    'category' => 'product-updates',
    'subject' => 'This week at Acme',
    'html' => $renderedHtml, // must contain {{ unsubscribe_url }}
]);
```

A marketing send honors the recipient's opt-outs and needs `{{ unsubscribe_url }}` in the body. A template send follows the template's own category, so leave `category` out or pass the template's. PostHog refuses a send with the new `EmailError` codes `unknown_category`, `category_mismatch`, `unsubscribe_link_required` or `recipient_opted_out`.
