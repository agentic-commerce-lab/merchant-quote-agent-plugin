# Shopware bridge over SwagCommercial quote services — design spec

*2026-08-26 — design spec. Status: proposed. Scope: issue #3 — the one module that
touches SwagCommercial's B2B quote services, behind one interface, so nothing else
in the plugin ever references a SwagCommercial class.*

Related: ADR 0001 (`docs/adr/0001-runtime-plugin-dependencies.md`) — the
runtime-dependency convention this bridge follows; `docs/2026-08-25-quote-agent-shopware-plugin-design.md`
— the plugin's overall module decomposition ("Shopware bridge — the only
version-fragile module").

## Why

Issue #2 ported the negotiation policy as pure functions with zero Shopware
dependency. Everything above the bridge — Policy, and later Servicing — must stay
that way. The bridge is the one seam where SwagCommercial's actual shape (entities,
services, state machine, cart calculation) meets the plugin, and the one place a
SwagCommercial minor release can break something.

The TS reference (`merchant-quote-agent/src/commerce/shopware-admin/quote-operations.ts`,
225 lines) did this today as a **remote** Admin API client — every operation was an
HTTP call against a shop it didn't run inside. As an in-process plugin, that's no
longer the natural shape: Shopware exposes the same capabilities as real PHP
services and a real Data Abstraction Layer, and using those directly is both less
code and removes an entire HTTP round-trip per write. This spec is the PHP-native
redesign, not a port.

## What we verified before designing

Checked against the actual `shopware/commercial` source (available locally at
`/Users/sebastian/projects/SwagCommercial/src/B2B/QuoteManagement/`) and a live
Shopware instance (docker container `shopware-trunk`, `dockware/shopware:dev-main`,
port 8090, `SwagCommercial 7.13.0` + `SwagAgenticCommerce 1.1.1` both installed and
active).

**`@internal` is narrower than it first looks.** Three PHP services are marked
`@internal` at the class level: `QuoteManipulation` (add/change/remove line items),
`QuoteCommenter` (comments), `QuoteState` (state-machine transitions). But:

- `QuoteCalculator` (recalculation) and `QuoteActionController` (the Admin API
  controller these route through) are **not** `@internal`.
- Reading `QuoteCommenter::comment()`, the non-`@internal` path is a single
  `EntityRepository::create()` call on `quote_comment` — the real logic (flow
  triggers, notifications) lives in an event subscriber reacting to the entity
  write, not in the service method itself.
- Reading `QuoteCalculator::recalculate()`, it re-reads whatever is currently
  persisted on the quote (via `QuoteToCartConverter`) and reprices it through the
  real Cart `Processor` — including a line item inserted by a plain DAL write, not
  only ones added through `QuoteManipulation`.

In other words, most of what the `@internal` classes do could be reconstructed with
generic DAL writes plus the two non-internal services. We use them anyway
(decision below) — but the option existed, and reconstructing it isn't the only
sane way to avoid `@internal` risk, so it's worth recording for issue #9's fork
retirement if this file needs revisiting then.

**Line-level operations have no dedicated Admin API route.** `QuoteActionController`
only exposes `addProduct(s)`, `recalculate`, `comment`, `replyHistory`,
`admin-requested-flow`, `withdraw`, `detail-mode`. `changeLineItem` and
`removeLineItem` live only on `QuoteManipulation`, reached by TS today via generic
DAL `PATCH /quote-line-item/{id}` (not a custom route at all) — confirming that
quote-level and line-level field writes are meant to go through the DAL directly,
with SwagCommercial's custom routes reserved for the operations that need real
cart computation (add-product, recalculate) or write side effects (comment).

## Decision

**Direct service injection**, not an HTTP loopback and not a Symfony sub-request.
The bridge is a normal Shopware plugin sitting in the same container as
SwagCommercial; injecting its services is the native way to call into another
installed plugin, same as `agentic-commerce`'s own `ShopwareQuoteGateway` does for
the Store API surface. This was weighed against calling the plugin's own Admin API
over real HTTP (byte-for-byte matches the already-validated TS sequencing, but a
real network round-trip per write and a token to manage) and a Symfony sub-request
(no network cost, but sharper edges around auth/sales-channel context threading
that would need the same live verification as direct injection anyway, for less
benefit). Direct injection is simplest by a comfortable margin **given the plugin
already runs where the target does**.

**Use `QuoteManipulation`/`QuoteCommenter`/`QuoteState` directly** for add-product,
comment, and state transition, rather than reconstructing them from generic DAL
writes. Fewer places to get subtly wrong, matches SwagCommercial's own intended
usage — at the cost of three concrete `@internal` dependencies, which get one
doc-comment block listing them (below), satisfying the issue's own done-when
criterion.

**One interface, one implementation**, per the issue. `QuoteGatewayInterface` is
the only thing anything else in the plugin depends on; `SwagCommercialQuoteGateway`
is the only class in the plugin permitted to import a SwagCommercial type.

## Interface

Not a 1:1 port of the TS function list — TS called eleven separate functions
because it was issuing eleven separate HTTP `PATCH`/`POST` requests. In PHP,
`EntityRepository::update()` already batches multiple fields and multiple rows in
one call, so the interface groups by *entity written to*:

```php
interface QuoteGatewayInterface
{
    /** @throws QuoteNotFoundException */
    public function fetchSnapshot(string $quoteId): QuoteSnapshot;

    /** @param list<QuoteLineItemChange> $changes */
    public function updateLineItems(string $quoteId, array $changes): void;

    public function addProduct(string $quoteId, string $productId, int $quantity): void;

    public function recalculate(string $quoteId): void;

    public function updateQuote(string $quoteId, QuoteUpdate $update): void;

    public function addComment(string $quoteId, string $comment): void;

    public function transition(string $quoteId, QuoteTransition $action): void;
}
```

Supporting value objects, all under `Bridge\Data\` and all plain readonly PHP —
same style as the Policy module's DTOs:

- **`QuoteLineItemChange`**: `lineItemId`, nullable `unitPriceNet`, nullable
  `quantity`, nullable `remove` (bool). A null field means "don't touch it" —
  `updateLineItems` writes only the lines and fields actually present, in one
  `EntityRepository::update()` call.
- **`QuoteUpdate`**: nullable `discount` (a small `Discount { type: DiscountType,
  value: float }` value object, `DiscountType` a two-case enum
  `Percentage`/`Absolute`), nullable `expiresAt` (`\DateTimeImmutable`), nullable
  `customFields` (array, shallow-merged — Shopware's own DAL custom-fields
  convention). Same "null means untouched" shape as line items, one
  `EntityRepository::update()` call on `quote`.
- **`QuoteNotFoundException`**: thrown by `fetchSnapshot` when the id doesn't
  resolve to a quote — the interface always returns a real snapshot or throws,
  never null, since Servicing only ever calls it with an id it already believes
  exists.
- **`QuoteTransition`**: backed enum, `Process`/`Sent`/`Decline`/`RequestChange` —
  the four actions the agent's servicing flow actually drives (`process`, `sent`,
  `decline`, `request_change` are `QuoteStates::ACTION_*` technical names).
  SwagCommercial's state machine has more actions (`accept`, `expire`, `reopen`,
  the `admin_*` ones) that are buyer- or merchant-manual, not part of an
  agent-driven servicing pass — out of scope for this interface, not because
  they're hidden, but because nothing calls them yet.

No `Context`/`SalesChannelContext` parameter anywhere on the interface — building
and threading Shopware's request context is `SwagCommercialQuoteGateway`'s
implementation detail, never exposed above the bridge.

## Implementation

`SwagCommercialQuoteGateway implements QuoteGatewayInterface`, one file, with a
class-level doc comment listing its three `@internal` dependencies up front:

```php
/**
 * @internal-dependencies
 * - Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation::addProduct()
 * - Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter::comment()
 * - Shopware\Commercial\B2B\QuoteManagement\Domain\State\QuoteState::transition()
 * A SwagCommercial release can change any of these without notice; integration
 * tests (tests/Integration/) are what catches it, not static analysis.
 */
```

Per-method mapping:

| Interface method | SwagCommercial call |
| --- | --- |
| `fetchSnapshot` | `EntityRepository` search on `quote` (generic DAL, associations: lineItems, comments, stateMachineState, currency) |
| `updateLineItems` | `EntityRepository::update()` on `quote_line_item` (generic DAL) |
| `addProduct` | `QuoteManipulation::addProduct()` (`@internal`) |
| `recalculate` | `QuoteCalculator::recalculate()` (not `@internal`) |
| `updateQuote` | `EntityRepository::update()` on `quote` (generic DAL) |
| `addComment` | `QuoteCommenter::comment()` (`@internal`) |
| `transition` | `QuoteState::transition()` (`@internal`) |

## Runtime gate

Follows ADR 0001 exactly, mirroring `agentic-commerce`'s `QuoteBackendFeature`
pattern already used elsewhere in this codebase:

- `QuoteGatewayFactory`: `class_exists('Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation')`
  (string literal, not `::class`) gates whether `SwagCommercialQuoteGateway` gets
  built at all; the license toggle (`QUOTE_MANAGEMENT-8702512`, same constant the
  Admin API route condition uses) gates whether it's actually usable.
- `services.php` registers `QuoteGatewayInterface` from the factory with
  `autowire(false)` and a nullable/ignore-on-invalid reference, matching how
  `QuoteCapabilityProfileContributor` is already wired in this repo.
- No SwagCommercial-absent fallback implementation is needed: per the existing
  design, capability absence is handled at the UCP capability layer (issue #1) —
  if the gateway can't be built, the quote capability simply isn't advertised.

## Snapshot ownership

The bridge returns its own `Bridge\Data\QuoteSnapshot` — full-fidelity (id, number,
lines with product info, comments, state, currency, expiration, discount, custom
fields), a plain PHP value object, never a SwagCommercial `QuoteEntity`.

This is **not** `Policy\Data\QuoteSnapshot`. Issue #2 deliberately trimmed that DTO
to only the fields negotiation-core reads, with a note that issue #4 (Servicing)
extends it when it has a real reader for the rest. Servicing is what will sit
between the bridge's full snapshot and Policy's narrow one — the bridge has no
reason to know about Policy's shape, and Policy has no reason to grow fields this
issue doesn't need.

## Testing

No TS source of truth exists for how Shopware itself behaves, so this isn't
fixture-driven like the Policy module — it's real integration testing against
`shopware-trunk`. Per ADR 0001, this can't run on public CI (SwagCommercial is
licensed). `tests/Integration/`, a separate PHPUnit testsuite/config from
`tests/Unit/`, runs live against the docker shop and is not wired into the GitHub
Actions workflow.

**This test suite is the spike.** The issue's open questions — does reprice-then-
recalculate behave like the old Admin API sequence, how does quote versioning react
to two writes in one request, does the expiration-before-`sent` ordering trap from
the TS implementation still apply, which functions are `@internal` — get answered
by writing the integration test for each `QuoteGatewayInterface` method against the
live shop and watching what actually happens, in the same red-before-green shape as
any other TDD cycle. Findings that change the design (e.g. if recalculate turns out
not to see a same-request line-item write) get folded back into this spec before
implementation continues, not discovered after.

## Non-goals

- Order-fulfillment operations (`attachPoReference`, `acknowledgeOrder` in the TS
  source) — these act on an `order`, not a `quote`, and belong wherever order
  acceptance is handled, not this bridge.
- A fallback/null implementation of `QuoteGatewayInterface` for shops without
  SwagCommercial — capability absence is handled one layer up (issue #1).
- Reconstructing `QuoteManipulation`/`QuoteCommenter`/`QuoteState`'s behavior from
  generic DAL writes to avoid `@internal` entirely — verified possible (see
  "What we verified"), decided against for this issue in favor of less
  reconstruction risk. Worth revisiting if these three internals prove unstable in
  practice.

## Risks

**The `@internal` dependencies are the real fragility.** A SwagCommercial patch
release changing `QuoteManipulation::addProduct()`'s signature, or `QuoteCommenter`
/`QuoteState`'s, surfaces at runtime or in `tests/Integration/`, not in `mago
analyze` — this is the accepted tradeoff ADR 0001 already names. The class-level
doc comment on `SwagCommercialQuoteGateway` is what keeps this list visible and
shrinkable rather than ambient, per the issue's own done-when criterion.

**Integration tests need the live shop to keep existing.** `tests/Integration/`
is only as good as `shopware-trunk` staying up and matching a version we've
verified against. No version matrix is proposed here (ADR 0001 flags this as a
gap CI should eventually carry); this spec doesn't solve it, just inherits it.

**`updateLineItems`/`updateQuote`'s "null means untouched" batching is unverified
against real concurrent-write behavior.** The quote-versioning spike question
(does Shopware's versioning throw or silently drop on two writes in one request)
applies most directly to these two methods, since they're the ones batching
multiple field writes in a single DAL call. First thing `tests/Integration/`
should cover.
