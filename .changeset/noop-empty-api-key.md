---
'posthog-php': patch
---

Initialize a disabled no-op client instead of throwing or sending requests when the API key is missing or blank.
