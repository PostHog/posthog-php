---
"posthog-php": minor
---

Add a $feature_flag_has_experiment boolean property to every $feature_flag_called event, sourced from the server's has_experiment field and defaulting to false when the server does not report it.
