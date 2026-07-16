---
"posthog-php": minor
---

Emit error tracking stack frames in the canonical bottom-up order: `stacktrace.frames[0]` is now the outermost/entry-point call and the last frame is the crash site. This aligns the PHP SDK with the cross-SDK stack frame ordering standard.
