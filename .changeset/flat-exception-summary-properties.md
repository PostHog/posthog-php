---
"posthog-php": patch
---

Add flat `$exception_type` and `$exception_message` properties to captured exception events, mirroring the outermost entry of `$exception_list`.
