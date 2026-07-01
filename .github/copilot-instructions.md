# Copilot Review Instructions

## Coding guidelines

- Keep StarCache compatible with both regular plugin activation and MU-plugin loading.
- Preserve the `starcache.php` bootloader as the root entrypoint for WordPress.
- Prefer namespaced, typed PHP with PSR-style organization.
- For production hardening, prioritize security, multisite safety, and safe lifecycle behavior.

## Reference repositories (read via MCP)

Before reviewing any PR, read these repos:

- ADR Registry: Starisian-Technologies/sparxstar-architecture-governance-registry
- Product Specs: Starisian-Technologies/sparxstar-product-specification-registry
- Coding Standards: Starisian-Technologies/starisian-technologies-coding-standards
- Enforcement Workflows: Starisian-Technologies/sparxstar-code-conformance
- Contracts: Starisian-Technologies/sparxstar-contracts-registry
- Claude PR Review: Starisian-Technologies/sparxstar-claude-pr-review

## Review checklist

Flag any PR that:

- Contradicts an ADR or invariant
- Assumes an answer to an open question (OQ in OPEN state)
- Violates a coding standard
- Changes a contract interface without updating the README
- Changes behavior that contradicts the product spec
- Adds code with no spec backing it

You are a reviewer, not the authority. Flag and explain. The owner decides.

## Repo-specific invariants to check against

StarCache (`docs/starcache-tech-spec.md`, `TECHNICAL-SPECIFICATION.md`)
has invariants that are easy to violate accidentally in a PR. Flag any
change that:

- Modifies context dimensions (`StarCacheContext::set`) anywhere after
  `plugins_loaded`, or reorders the `plugins_loaded` / `init` /
  `send_headers` hook priorities in `starcache.php`.
- Adds key-building, TTL, or context-evaluation logic inside
  `StarCacheAdapter` (it must remain a pure storage primitive).
- Adds header-setting code inside `StarPageCache`, or cache
  read/write/delete calls inside `StarResponseController`.
- Adds a new caller of `StarQueryCache` (deprecated — use
  `star_cache_remember()`), or adds new features to `StarAssetMinifier`
  (slated for extraction to a companion plugin).
- Allows a cached response to reach an authenticated request, or
  weakens/bypasses `StarCacheContext::shouldBypass()`.
- Adds a global function outside the five `star_cache_*` helpers.
