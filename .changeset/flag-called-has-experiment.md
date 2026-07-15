---
"posthog-php": minor
---

Add a $feature_flag_has_experiment boolean property to $feature_flag_called events, mirroring the server's has_experiment field. The property is only sent when the server explicitly reports has_experiment and is omitted when unknown (older deployments, missing metadata, missing flags).
