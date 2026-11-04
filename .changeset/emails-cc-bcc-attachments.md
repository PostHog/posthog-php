---
"posthog-php": minor
---

Transactional email: a send with `template_key` also takes `cc`, `bcc` and `attachments`. An attachment's `content` is the file's bytes, which the SDK encodes as Base64.
