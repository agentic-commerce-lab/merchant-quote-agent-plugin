# 1. Depend on SwagCommercial and Agentic Commerce at runtime, not through Composer

Date: 2026-08-26

## Status

Accepted.

## Context

This plugin runs on top of two other Shopware plugins:

- **SwagCommercial** (`shopware/commercial`) owns the B2B quote entities, the quote
  state machine and the Store API quote routes.
- **Agentic Commerce** (`shopware/agentic-commerce`) owns the UCP surface, and is
  what imports the UCP SDK's routes into Shopware. Without it there is no UCP
  routing for us to extend.

Both are hard runtime requirements. The question is how to express them.

Three facts shape the answer:

1. **Neither package is on Packagist.** `shopware/agentic-commerce` is open source
   and public on GitHub, but unpublished — it is distributed through the Shopware
   Store. `shopware/commercial` is licensed and served from
   `packages.shopware.com`, which needs an auth token. A `require` on the latter
   makes `composer install` fail for every clone and CI run without that token.
2. **These are plugins a merchant installs through the plugin manager**, not
   libraries. Requiring them by Composer as well means the same code can arrive
   twice, by two mechanisms, at two versions.
3. **We need their types for static analysis.** The bridge over SwagCommercial's
   quote services (issue #3) cannot be type-checked if the analyzer cannot see
   those classes.

Shopware's own plugin has exactly the same dependency and has already answered
this. From `QuoteBackendFeature` in `shopware/agentic-commerce`:

> Quote entities and the Store API quote routes live in SwagCommercial (Evolve
> tier). This plugin must install and run without it, so the dependency is
> detected at runtime and never declared in composer: class existence decides
> whether the gateway is registered at all, the license toggle decides whether it
> serves requests.

Their implementation of that:

- Class-name **string literals**, never `::class`, because the classes need not be
  loadable: `'Shopware\Commercial\B2B\QuoteManagement\QuoteManagement'`,
  `'Shopware\Commercial\Licensing\License'`.
- A two-stage gate: `class_exists()` decides registration; the license toggle
  `QUOTE_MANAGEMENT-8702512` decides whether requests are served.
- Commercial services injected as `?object`, defaulted `null`, through
  ignore-on-invalid DI references.
- Graceful degradation: `UcpExtensionAvailability::supportsQuotes()` returns false
  and the capability is not advertised in discovery.
- CI checks out `shopware/shopware` at pinned refs (6.7.11.x, 6.7.12.x) and the UCP
  SDK via path repositories. No `packages.shopware.com`, no `COMPOSER_AUTH`.

## Decision

**Neither `shopware/commercial` nor `shopware/agentic-commerce` appears in
`require` or `require-dev`.** We follow the runtime-detection pattern above:
string class-name constants, `class_exists()` for registration, the license toggle
for serving, nullable injection with ignore-on-invalid references, and degradation
to "capability not advertised" when the backend is absent.

**With one deliberate divergence.** Their `?object` typing leaves every call into
SwagCommercial statically unchecked, which PHPStan level 7 tolerates and our gate
(`mago analyze`, full strictness) does not. So the untyped surface is confined to a
single adapter: we declare narrow interfaces for the commercial routes we actually
call, everything above the bridge is typed against those interfaces, and one
factory does the `class_exists` check and returns a typed adapter. This is the
"bridge behind one interface" the design spec already prescribes, and it is what
makes the pattern survive a strict analyzer.

**CI mirrors Shopware's approach:** check out `shopware/shopware` at pinned refs
rather than requiring anything, and run the gate against that.

## Consequences

**Good**

- `composer install` works for anyone, with no license token, in any clone or CI job.
- No double-install or version-skew between Composer and the plugin manager.
- The plugin installs and runs on a shop without SwagCommercial; the quote
  capability simply is not advertised.
- We stay consistent with the plugin we sit directly on top of, which reduces the
  chance of the two disagreeing about lifecycle.

**Bad, and accepted**

- Commercial APIs get no type checking except through our own adapter interfaces,
  which we have to write and keep accurate by hand. A SwagCommercial signature
  change surfaces at runtime or in integration tests, not in the analyzer.
- Those interfaces are a maintenance surface that duplicates, in miniature, part of
  SwagCommercial's API.
- CI has to materialise a Shopware checkout, and should carry a version matrix like
  Shopware's own, or we will only ever prove one version works.
- Integration tests for the bridge need an environment where SwagCommercial is
  actually present — which is a licensed shop, not a public CI runner. Expect the
  bridge's integration coverage to run somewhere other than open CI.

**Revisit if**

- Either package is published somewhere we can resolve without credentials, *and*
  Shopware's guidance on plugin-to-plugin Composer dependencies changes.
- Shopware ships public interfaces or stubs for the commercial quote services, at
  which point the hand-written adapter interfaces can be dropped.

## References

- `shopware/agentic-commerce`: `src/Ucp/Quote/QuoteBackendFeature.php`,
  `src/Ucp/Gateway/ShopwareQuoteGateway.php`,
  `src/Ucp/Capability/UcpExtensionAvailability.php`, `.github/workflows/ci.yml`
- Design spec: `docs/2026-08-25-quote-agent-shopware-plugin-design.md`
- Issue #3 (the bridge), issue #9 (moving the fork's additions here)
