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

Both are runtime requirements — SwagCommercial for everything, Agentic Commerce
for the agent-facing half only (see the amendment below). The question is how to
express them.

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

## Amendment, 2026-09-10: Agentic Commerce is optional

The original decision treated both plugins as hard runtime requirements and only
built the gate for one of them. That was wrong about what the module is for.

Two things are being conflated. UCP is how an **agent** asks the shop for a quote
of its own accord. It is not how a quote gets negotiated. A buyer who requests a
quote by hand in the storefront produces exactly the same `quote` row, and the
merchant-side agent services it identically — reads the ask, decides against the
policy, replies or escalates, writes the decision record. None of that touches
UCP. Requiring Agentic Commerce for it excluded every shop that wants the
negotiation without the agent-facing surface, for no technical reason.

So the same degradation applies to Agentic Commerce, in
`MerchantQuoteAgentPlugin\Ucp\UcpAvailability` — **but not the same probe.**

`class_exists()` is wrong here, and the shop said so. The original decision
above rests on "Shopware only registers an active plugin's autoloader". That
holds for a plugin in `custom/plugins`. It does not hold for one a shop
`composer require`s into `vendor/`, which is how both of these plugins actually
arrive (see `scripts/shop-setup.sh`): Composer's autoloader carries the
namespace whether the plugin is active or not. Deactivating Agentic Commerce on
the test shop left `Swag\AgenticCommerce\SwagAgenticCommerce` loadable forever,
so a class-existence gate kept registering services against an SDK bundle that
was no longer there — and the deactivation itself died in `DecoratorServicePass`
on the `RuntimeConfigurationResolverInterface` decoration. The same lag shows up
even for a `custom/plugins` install, because `plugin:deactivate` rebuilds the
container inside the process that booted with the plugin active.

What we actually depend on is the BUNDLE, so that is what the gate asks for:
`isRegistered()` looks for `UcpSdkBundle` in `kernel.bundles`. That parameter is
derived from the bundle collection the container is being built from, so it has
no lag, and it is readable both during `Bundle::build()` (Symfony fills the
parameter bag before calling it — `services.php` already reads
`kernel.environment` there) and from the booted container.

One consequence: `Resources/config/routes.php` is handed a `RoutingConfigurator`
and no container, so it cannot ask. The gated route imports therefore moved into
`MerchantQuoteAgentPlugin::configureRoutes()`, which has `$this->container` —
the booted one, the same container whose services those routes resolve against,
so the router and the service graph cannot disagree.

`CommercialAvailability` still uses `class_exists` and is left alone here: its
service gate and its route gate read the same probe, so they agree with each
other. It carries the same vendored-plugin blind spot, worth fixing the next
time SwagCommercial's gate is touched.

**Switched off without it:** the UCP quote endpoints, the quote and mandate
capability descriptors, identity linking (both controllers and the OAuth-table
readers behind them), the allow-any-agent and grant console commands, the Agent
Access page in the administration, and the whole A2CN evidence layer. That page reads and writes Agentic
Commerce's own `_admin/ucp/*` API, so it has nothing to edit; the admin module
gates it on `Shopware.Context.app.config.bundles.SwagAgenticCommerce`, which is
how core's own `sw-search-bar` tests for this very bundle. A privilege check
would not do — an administrator's role grants `ucp.viewer` whether or not
anything defines it.

**Still on without it:** quote servicing end to end, the negotiation policy that
drives it, the decision log and its dashboard, the escalation flow, and the
quote contract documents under `/.well-known/ucp/schemas/` — which describe the
capability rather than serving it.

**Why A2CN goes with the surface rather than standing alone.** The first cut
kept it: nothing in `src/Protocol/` touches SwagCommercial, and the two SDK
services it needs (`DeterministicJsonInterface`, `SigningKeyManagerInterface`)
are dependency-free classes from `ucp-php-sdk/core` — a real Composer
requirement, unlike the two plugins — so four lines of registration kept it
signing without the bundle. That was the wrong call, and reading the emitter is
what showed it. `SellerActEmitter` reads the act chain from the quote's
`a2cn_session` custom field; **nothing in this plugin ever writes that field**,
and the counterparty that does can only reach the shop over UCP. With no session
the emitter returns `inert()` on every pass, the session and record endpoints
have nothing to answer for, and all five evidence checks have nothing to check.
What was left running was three `.well-known` documents advertising `endpoint`
and `records_url` to any agent that crawled them — a signed promise the shop
cannot honour. A capability that cannot be exercised should not be advertised.

One knock-on: `Plugin::activate()`/`update()` generate the A2CN signing key, and
`A2cnKeyStore` is no longer a service id on such a shop. The hook now checks
`has()` before `get()` — without it, every activate and update on a shop with no
UCP surface logged "A2CN signing key generation failed", an error about a
feature that shop deliberately does not have.

One more coupling surfaced on the way, and it was invisible until the shop
actually ran without the plugin: **core does not alias
`SalesChannelContextServiceInterface`** — Agentic Commerce does, in its own
`services.php`. `SalesChannelContextResolver` had been autowiring off that
alias. It now names core's concrete `SalesChannelContextService` instead, which
loses nothing when the plugin is there (decoration rewrites that id, so
SwagCommercial's context-service decorators still apply) and stops the whole
container rebuild from failing when it is not. Worth generalising: autowiring an
interface says nothing about who registered the alias behind it.

**Not done:** registering `UcpSdkBundle` ourselves as a fallback. UCP support is
Agentic Commerce's job, and a second registration would collide with its own
when both are present.

**The A2CN organization name moved with the layer**, out of `config.xml` and
onto the Agent Access page: a merchant should not be asked to configure a
feature the shop does not have. Same `system_config` key, so
`A2cnIdentityResolver` reads it unchanged — but per sales channel only now,
where the plugin config page could also set a global default (an existing global
value still shows, because the page's GET inherits, and saving copies it onto
the channel). While it was being moved, its fallback changed too: an empty field
used to publish the SALES CHANNEL name, an internal admin label —
"Storefront" on a stock installation, against a shop actually called
"Demostore". It now prefers `core.basicInformation.shopName`, the name the shop
already presents to customers, and the input's placeholder shows exactly what an
empty field will publish.

**Verified on the test shop, both ways.** Without: `plugin:deactivate
SwagAgenticCommerce` succeeds, the storefront serves, every `/ucp/*`,
`/quote-agent/*` and `/.well-known/a2cn-*` route 404s, only the contract
documents and the decision API remain, and the integration suite is green with
28 skipped. Reactivated: all 160 cases run, 24 routes are back, and the
organization name round-trips from the Agent Access page through `system_config`
into the published `/.well-known/a2cn-agent`.
