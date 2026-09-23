# Commercial Gate Reads the Bundle, Not the Classpath

Date: 2026-09-23

## Status

Approved in chat. Written for issue #152.

## Problem

`CommercialAvailability::isAvailableByClass()` decides whether `services.php`
registers the SwagCommercial bridge, and whether `AgentFacingRoutes` imports the
quote routes. It asks the classpath. SwagCommercial arrives through
`composer require` (see `scripts/shop-setup.sh`), so its namespace stays in
Composer's autoloader when a merchant deactivates it in the admin.

After that deactivation the gate still opens, `services.php` registers
definitions holding plain `service('quote.repository')` references, the bundle
that synthesises `quote.repository` is gone, and the container fails to compile.
The shop does not boot, and recovering it means editing files on disk.

`UcpAvailability::isRegistered()` fixed the same defect for Agentic Commerce on
2026-09-10 by reading `kernel.bundles`. ADR 0001's amendment deferred the
commercial side "to the next time SwagCommercial's gate is touched". This is
that time.

## What the bundle is called

SwagCommercial registers each feature as its own bundle. Verified on both
lanes with `bin/console debug:container --parameter=kernel.bundles`:

- the 7.13 test shop lists `SwagCommercial` plus ~37 feature bundles;
- the 6.7.12 b2bseller shop lists the same `QuoteManagement` entry.

```
"QuoteManagement": "Shopware\\Commercial\\B2B\\QuoteManagement\\QuoteManagement"
```

The gate keys on **`QuoteManagement`**, not on `SwagCommercial`. That is the
bundle that owns the quote entities (hence `quote.repository` and
`quote_line_item.repository`) and the services the bridge injects. It is absent
whenever SwagCommercial is inactive, so it covers the reported case, and it is
also correct if SwagCommercial ever ships without it. `CustomerSpecificFeatures`
is a separate bundle, but the plugin only reaches it through `nullOnInvalid()`,
so it is not part of the gate.

## Design

### The probe

`CommercialAvailability` gains:

```php
public static function isRegistered(?ContainerInterface $container): bool
```

True when **both** hold:

1. `kernel.bundles` exists and has the key `QuoteManagement`;
2. the existing class check passes (`QUOTE_MANIPULATION`, `QUOTE_COMMENTER`,
   `License`).

The class check stays because it still answers a question the bundle cannot:
whether this SwagCommercial build carries the `@internal` classes the bridge
is written against. Null container, or a hand-built `ContainerBuilder` without
`kernel.bundles`, answers false, the same safe default `UcpAvailability` uses.

`isAvailableByClass()` becomes private. Every build-time caller moves to
`isRegistered()`; keeping the old one public would leave the wrong question one
autocomplete away.

`isLicensed()` keeps its signature and stays container-free. Its runtime
callers (`QuoteGatewayFactory`, `CommercialQuoteAccess`,
`SwagCommercialBuyerQuoteGateway`) are only registered behind the stage-one
gate, so on a shop without the bundle they do not exist to ask. It keeps its
internal class check, which is what makes it safe for the integration harness
to call by hand.

### Call sites

| Where | Change |
|---|---|
| `services.php:249` (nested in the UCP block) | `isRegistered($container)` |
| `services.php:590` (the early return) | `isRegistered($container)`; rewrite the comment above it, whose "Shopware only registers an active plugin's autoloader" is the assumption this issue disproves |
| `AgentFacingRoutes::import()` | gains a `?ContainerInterface $container` parameter; the commercial gate calls `isRegistered($container)` |
| `MerchantQuoteAgentPlugin::configureRoutes()` | passes `$this->container` to `import()` |

Comments that name `isAvailableByClass()` as the guard (`services.php` around
620 and 697, `QuoteGatewayFactory`'s docblock) are updated to name the new
probe. ADR 0001's amendment gets one paragraph recording that the commercial
side has migrated, keyed on `QuoteManagement`, replacing the "left alone here"
paragraph.

### Behaviour on a shop with SwagCommercial deactivated

The shop boots. Quote servicing, the agent's reply and escalation do not run,
because there are no quotes. The UCP quote endpoints and A2CN records/act
routes answer 404, not 500, because the route gate reads the same probe. The
mandate and discovery documents, settings and audit views are unaffected.
Reactivating SwagCommercial rebuilds the container and the gate opens again;
nothing is reinstalled and no data is lost.

## Testing

### `CommercialAvailabilityTest` (unit)

The four shapes `UcpAvailabilityTest` checks: null container; container without
`kernel.bundles`; `kernel.bundles` without `QuoteManagement`; `kernel.bundles`
with it. In this suite SwagCommercial's classes are absent, so the fourth shape
is still **false**, and that pins the AND: a listed bundle is not enough without
the classes. `isLicensed()` never throwing stays as it is.

The true case needs the classes aliased, which is one-way. It is covered by
`GateMatrix`'s present shops rather than a second process-isolated test here.

### `GateMatrix` / `CommercialSurfaceConfigurationTest` (unit)

- `container()` sets `kernel.bundles` from the shop's gates: `UcpSdkBundle` when
  UCP is on, `QuoteManagement` when commercial is on.
- A fifth shop, **`vendoredButInactive`**: SwagCommercial's classes loadable,
  `QuoteManagement` absent, UCP on. Built after the classes are aliased. In
  `SHOPS` its commercial gate is shut, so `removedIn()` counts the commercial
  foreign ids (`quote.repository` among them) as removed.
- `shopNames()` yields it, so the two existing "nothing depends on what its
  gate removed" tests run over it.
- One new assertion: `vendoredButInactive` registers exactly the ids
  `withoutCommercial` does. That states the fix directly; the dependency tests
  state its consequence.

This is the red test. Under today's classpath probe the fifth shop opens the
gate, and `testNoServiceDependsOnOneItsGateRemoved` reports
`QuoteWriter -> quote.repository` and its siblings.

UCP off with the classes vendored is not a separate shop: that case hits the
same line-590 gate the fifth shop does, and the line-249 gate is nested inside
the UCP block, so the fifth shop exercises both.

### `OrderHistoryLocatorConfigurationTest` (unit)

It aliases the commercial classes and expects the bridge to be registered, so it
must now also set `kernel.bundles` with `QuoteManagement`. Without that the gate
stays shut and the test fails for a reason that has nothing to do with it.

### `GatewayWiringTest` (integration)

`testALicensedShopIsAlsoAClassAvailableShop` asserts
`isRegistered(static::getContainer())` instead of the class probe. It runs only
on the test shop.

### On the shop

Not automatable here, and the thing ADR 0001's amendment says was only ever
found on a real shop:

1. On the test shop, deactivate SwagCommercial in the admin.
2. The shop boots; `bin/console cache:clear` succeeds.
3. `GET /ucp/quotes` answers 404, and `GET /a2cn/sessions/{id}/acts` gets
   `A2cnNotFoundController`'s protocol 404 rather than a 500.
4. Reactivate; a quote comment is serviced again.

## Out of scope

- Making the `service('quote.repository')` references `ignoreOnInvalid()`. It
  hides the crash for those four references and leaves every other
  definition in the block registered on a shop that cannot serve it.
- Whether the UCP quote capability is still *advertised* without the bundle.
  It is registered with a null gateway and answers 501; what it advertises is
  issue #1's question.
- `CommercialCapabilitiesFactory`'s `class_exists(QUOTE_SEND_REQUEST_ROUTE)`.
  It sits behind the gate and asks which SwagCommercial *version* this is, a
  question the classpath answers correctly.
