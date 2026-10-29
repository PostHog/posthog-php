# Laravel Pennant

The optional, read-only driver uses the PHP SDK's feature flag snapshots. Install
Pennant in your application, then select the store:

```sh
composer require laravel/pennant:^1.26
```

```dotenv
PENNANT_STORE=posthog
```

Package discovery registers a `posthog` store if the application has not already
configured one. No Pennant migration or database-backed flag store is needed.

```php
use Laravel\Pennant\Feature;

Feature::for('account-123')->active('new-checkout');
$variant = Feature::for('account-123')->value('checkout-layout');
```

Values retain their boolean or variant-string type. Unknown or unresolved flags
return `false`, not an accidentally active `null`. Reads use the SDK's normal
exposure events; repeated cached reads do not generate duplicate exposures.

String and integer scopes are distinct IDs. Authenticated user/model scopes use
their auth identifier or model key, without a class-name prefix, to match ordinary
SDK capture. Models are not serialized and their properties are not sent.
Pennant normally uses the authenticated user as its default scope. When that
scope is null, the driver uses the runtime's current analytics context. Tracing
headers remain untrusted analytics input, never authorization evidence.

For custom scope types, use Pennant's scope-identifier contract to resolve an
explicit string/integer identifier. Configure runtime group resolvers for group
flag targeting. Use the native `evaluateFlags()` API for person/group properties,
local-only evaluation, payloads, key subsets and unresolved reasons:

```php
$payload = \PostHog\Laravel\Facades\PostHog::evaluateFlags()->getFlagPayload('checkout-layout');
```

## Read-only behavior

Define and manage flags in PostHog. `define`, `activate`, `deactivate`, `delete`,
`purge` and other write operations throw `LogicException`; the driver never pretends
to modify remote flag definitions. Use remote flag key strings, not locally defined
PHP feature classes.

`Feature::defined()` intentionally returns an empty array: this read-only external
store has no local definitions. Pennant calls it during feature-name resolution,
before resolving the requested scope, so it never evaluates flags or performs I/O.
Use `Feature::for($scope)->all()` or `Feature::for($scope)->loadAll()` to discover
PostHog flags for a scope through Pennant's external-definition contract. Discovery
uses that scope's native snapshot without recording exposure; reading the discovered
values uses normal deduplicated exposures. Bulk and subsequent individual reads
reuse the same snapshot, preserving variants and disabled values. Unknown or
unresolved flags remain inactive.

## Cache lifetime

The SDK reuses snapshots for identical complete evaluation inputs. Pennant also
has its own feature/scope cache. The integration clears PostHog stores around HTTP,
queue and command boundaries, including failed jobs. Pennant's own Octane lifecycle
integration remains active, and the driver resolves the current application rather
than retaining a request manager in a singleton.

If targeting properties/groups change for the **same scope within one request**,
call `Feature::flushCache()` before another Pennant read. Alternatively use native
`evaluateFlags()` directly, which keys its snapshot cache by all evaluation inputs.
Do not retain a scoped PostHog manager in an application singleton.
