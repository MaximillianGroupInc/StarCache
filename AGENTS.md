# Agent Instructions

## Platform governance

Read `.github/instructions/governance/` for compiled ADRs, invariants,
and open questions. These are the platform rules. Do not assume rules
not in the governance reference.

As of this writing, `.github/instructions/governance/` has not yet been
populated by the org's governance-sync workflow — it is created and kept
current by a push from `sparxstar-architecture-governance-registry`. If
the folder is empty or missing, the sync has not run yet; do not fabricate
its contents, and ask the repo owner to trigger it from that registry's
Actions tab.

Platform repos (read these for full context when accessible):

- Decisions: https://github.com/Starisian-Technologies/sparxstar-architecture-governance-registry
- Specs: https://github.com/Starisian-Technologies/sparxstar-product-specification-registry
- Standards: https://github.com/Starisian-Technologies/starisian-technologies-coding-standards
- Enforcement: https://github.com/Starisian-Technologies/sparxstar-code-conformance
- Contracts: https://github.com/Starisian-Technologies/sparxstar-contracts-registry
- PR Review: https://github.com/Starisian-Technologies/sparxstar-claude-pr-review

If no spec exists for what you're asked to build — STOP implementation.
Draft or request the missing spec first. Do not invent product behavior
in code.

## Repo-specific rules

StarCache is a WordPress mu-plugin: a deterministic cache orchestration
engine (backend detection, full-page/fragment caching, Varnish
integration, version-based invalidation, CSS/JS minification). It is a
standalone Starisian Technologies product — it does not use or reference
Helios, Sirus, Mehns, or Dheghom, and does not enforce SPARXSTAR content
sovereignty.

- Full architectural detail, invariants, and the current hardening
  checklist live in `TECHNICAL-SPECIFICATION.md` — read it before
  touching `StarCacheContext`, `StarCacheKey`, `StarCacheAdapter`, or
  hook registration order in `starcache.php`.
- The product tech spec for the governance spec registry is
  `docs/starcache-tech-spec.md`. Update it when behavior changes; it must
  stay in sync with the code, not with plans for the code.
- Hard rules that must never be violated silently: `declare(strict_types=1)`
  in every file; no global functions besides the five `star_cache_*`
  helpers; `StarCacheAdapter` never builds keys, decides TTLs, or
  evaluates context; `StarPageCache` never sets HTTP headers directly;
  `StarResponseController` never stores or retrieves cached data; nothing
  modifies context dimensions after `plugins_loaded`; authenticated users
  never receive cached responses.
- `StarQueryCache` is deprecated and slated for removal before v3.0 — do
  not add new callers; use `star_cache_remember()` instead.
- `StarAssetMinifier` is slated for extraction to a companion plugin
  before v3.0 — do not add new features to it.
- PHPStan runs at level 9 (`phpstan.neon`) and PHPCS enforces PSR-12
  (`phpcs.xml`) — both must pass before merge.
