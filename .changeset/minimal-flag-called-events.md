---
"posthog-php": minor
---

Send minimal $feature_flag_called events when the server enables the minimal_flag_called_events gate and the flag is not linked to an experiment. Minimal events keep only an allowlisted set of flag evaluation properties; experiment-linked flags, ungated projects, and responses without the signal keep the full event shape. The gate is read from the top-level minimalFlagCalledEvents field of /flags responses and the top-level minimal_flag_called_events field of the local-evaluation flag definitions payload, and persists through external flag definition caches.
