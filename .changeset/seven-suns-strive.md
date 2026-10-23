---
"posthog-php": patch
---

Normalize NaN and infinity to null in bounded data-only JSON payloads, while preserving native encoding for objects, references and out-of-budget payloads.
