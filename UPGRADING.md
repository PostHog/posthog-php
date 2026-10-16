# Upgrade notes

## GeoIP configuration

The SDK keeps `disableGeoip => false` by default for compatibility with existing
PHP releases. GeoIP enrichment remains enabled unless you explicitly disable it.
Ideally this default will change to `true` in the next major release, matching Node.

To opt into Node's default behavior now, disable GeoIP enrichment at initialization:

```php
PostHog::init('project-api-key', ['disableGeoip' => true]);
```

Events then carry `properties['$geoip_disable'] = true`, and remote feature flag
requests send `geoip_disable: true`. This prevents PostHog from attributing the
server's location to users and groups. GeoIP-based feature flag targeting can also
produce different results when you enable this option.

The same option is available in the `Client` constructor. A per-call `disableGeoip`
option overrides the client setting for `capture()`, `identify()`, `alias()`, and
`groupIdentify()`:

```php
PostHog::groupIdentify([
    'groupType' => 'company',
    'groupKey' => 'acme',
    'properties' => ['name' => 'Acme'],
    'disableGeoip' => false,
]);
```

`evaluateFlags()` and `Client::flags()` also accept a `disableGeoip` override.
Omitting it or passing `null` inherits the client setting. A snapshot retains its
override for exposure events, including snapshots filtered with `only()` or
`onlyAccessed()`.

Setting `disableGeoip` to `false` prevents the SDK from adding `$geoip_disable`;
it does not remove a manually supplied event property. When GeoIP is disabled,
the SDK sets `$geoip_disable` to `true` even if a caller supplied `false` in
properties. The `before_send` hook runs afterward and can still modify or remove
that property.
