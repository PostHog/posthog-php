Releasing
=========

 1. Make sure you have `make`, `git`, and [`git-extras`](https://github.com/tj/git-extras) installed.
 1. Run `VERSION=X.Y.Z make release` (where X.Y.Z is the new version).

 That's it! Composer will pick up the new tag and you can see the latest version at https://packagist.org/packages/posthog/posthog-php.

 An entry will also be made to `History.md` with a list of commits since last release.
