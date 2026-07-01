# StarCache — Role and Boundary

## Owns

- Cache backend detection and raw storage operations (Redis, Memcached,
  Memcache, WordPress object cache) via `StarCacheAdapter`.
- Request context resolution (device / auth / experiment dimensions) used
  to key cached responses, via `StarCacheContext`.
- Deterministic, collision-resistant cache key construction, via
  `StarCacheKey`.
- Version-based invalidation (group version bumps, not global flush), via
  `StarVersionStore`.
- Full-page output-buffer caching and fragment/partial caching, via
  `StarPageCache`.
- HTTP `Cache-Control` / `Vary` / `X-Cache` response headers, via
  `StarResponseController`.
- A public cache-aside API facade (`star_cache_get/set/delete/remember`),
  via `StarCache`.
- Per-site and network-wide (multisite) transient helpers, via
  `StarTransientCache`.
- CSS/JS asset minification for enqueued styles/scripts (currently in
  core; slated for extraction — see `TECHNICAL-SPECIFICATION.md`), via
  `StarAssetMinifier`.
- Varnish edge-cache integration: `Cache-Control`/`Vary`/`X-Cache-Tags`
  headers and `PURGE` requests on content change.

## Does not own

- Content sovereignty, quarantine, or governance enforcement — that is a
  SPARXSTAR platform concern (Dheghom, Helios, Sirus, Mehns). StarCache
  does not interact with any SPARXSTAR component. See
  `TECHNICAL-SPECIFICATION.md` § "What StarCache does not do".
- Any WordPress database-stored configuration. All configuration is via
  `wp-config.php` constants; StarCache never reads or writes the WordPress
  options table for its own settings.
- Global/backend-wide cache flushes as a production invalidation strategy.
  `StarCacheAdapter::flush()` is dev/test tooling only, gated behind
  `STARCACHE_ALLOW_DANGEROUS_FLUSH`.
- Serving cached responses to authenticated users — this is an explicit,
  enforced invariant (`StarCacheContext::shouldBypass()`), not a
  configuration option.

## Contracts produced

- None. StarCache is a standalone, general-purpose WordPress caching
  product. It does not publish interfaces to `sparxstar-contracts-registry`
  and does not consume contracts from other Starisian Technologies
  products.

## Consumed by

- WordPress themes and plugins on any site where StarCache is installed
  as a Must-Use plugin, via the global `star_cache_*()` helper functions
  and the `StarCache`/`StarPageCache`/`StarTransientCache` public APIs.
- No other Starisian Technologies / SPARXSTAR repo currently depends on
  StarCache's outputs.
