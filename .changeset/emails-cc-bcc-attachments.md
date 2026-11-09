---
"posthog-php": minor
---

Transactional email: a send with `template_key` also takes `cc`, `bcc` and `attachments`. An attachment's `content` is the file's bytes: the SDK hashes each file, uploads the ones PostHog doesn't have yet, then sends the email with references to them. An email takes at most 10 files and 40 MB after Base64 encoding, about 29 MB of files.
