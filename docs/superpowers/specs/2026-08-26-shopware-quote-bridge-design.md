# Shopware bridge over SwagCommercial quote services — design spec

*2026-08-26 — design spec. Status: implemented (2026-08-27), with the spike
results written back below. Scope: issue #3 — the one module that touches
SwagCommercial's B2B quote services, behind one interface, so nothing else in
the plugin ever references a SwagCommercial class.*

*Two things the design got wrong and the spike corrected: the read model mixed
gross line prices with a net quote total (see "Tax state", a money-path bug),
and `customFields: []` replaces rather than merges. Both are fixed and pinned by
tests. Everything else held.*

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

**Use `QuoteManipulation` and `QuoteCommenter` directly** for add-product and
comment, rather than reconstructing them from generic DAL writes. Fewer places to
get subtly wrong, matches SwagCommercial's own intended usage — at the cost of two
concrete `@internal` dependencies, which get one doc-comment block listing them
(below), satisfying the issue's own done-when criterion. `QuoteState` is
**not** used, because it is a thin wrapper over a Shopware-core service and so
earns its `@internal` cost least of the three; the reasoning is under
"Implementation".

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
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot;

    /**
     * @param list<QuoteLineItemChange> $changes
     * @throws QuoteRevisionMismatch
     */
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void;

    public function addProduct(string $quoteId, string $productId, int $quantity): void;

    public function recalculate(string $quoteId): void;

    /** @throws QuoteRevisionMismatch */
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void;

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
- **`QuoteVersion`**: two-case enum, `Live`/`Snapshot` — which DAL version lane to
  read. See "Versioning" below.
- **`QuoteRevision`**: `versionId`, `updatedAt`. Carried on every `QuoteSnapshot`;
  passed back into a write as an optional precondition.
- **`QuoteRevisionMismatch`**: thrown by a write whose `$expected` revision no
  longer matches what is stored.
- **`QuoteTransition`**: backed enum, `Process`/`Sent`/`Decline`/`RequestChange` —
  the four actions the agent's servicing flow actually drives (`process`, `sent`,
  `decline`, `request_change` are `QuoteStates::ACTION_*` technical names).
  SwagCommercial's state machine has more actions (`accept`, `expire`, `reopen`,
  the `admin_*` ones) that are buyer- or merchant-manual, not part of an
  agent-driven servicing pass — out of scope for this interface, not because
  they're hidden, but because nothing calls them yet.

No `Context`/`SalesChannelContext` parameter anywhere on the interface — building
and threading Shopware's request context is `SwagCommercialQuoteGateway`'s
implementation detail, never exposed above the bridge. `QuoteVersion` is the one
piece of Shopware's versioning the interface *does* expose, because it carries
domain meaning rather than plumbing (below).

## Versioning

SwagCommercial uses DAL versioning for quotes in a way that matters to us, so the
interface follows it rather than hiding it. `QuoteDefinition` is version-aware
(`VersionField`), and there are two lanes plus a mechanism:

- **`Defaults::LIVE_VERSION`** — the working copy.
- **`QuoteEntity::SNAPSHOT_VERSION_ID`** — a single fixed constant
  (`019cfaaf020219939ba2eea26ba651ae`), not a per-quote UUID. This is the
  "what the counterparty last saw" lane: `QuoteSnapshotVersionResolver` loads it
  for the storefront in `open`/`in_review`/`reopen`/`change_requested`/`withdrawn`
  and for the admin in `replied`.
- **Per-quote draft versions** via `QuoteCreateDraftVersionRoute` /
  `QuoteSaveDraftVersionRoute` / `QuoteDeleteDraftVersionRoute` — real Shopware
  fork-and-merge drafts, used by the storefront draft manager.

`QuoteVersion` is therefore a two-case enum, `Live` and `Snapshot`, mapping onto
the first two. Draft versions are out of scope: they are a storefront editing
affordance, and an agent servicing pass has no reason to fork one.

The snapshot lane is worth having in the interface beyond hygiene, because it is
already semantically "the state both parties agreed on" — the natural thing to
compare a fresh read against when deciding whether anything moved.

**`QuoteRevision`** carries `versionId` and `updatedAt`, and rides along on every
`QuoteSnapshot`. Pass one back into `updateLineItems`/`updateQuote` and the gateway
refuses the write with `QuoteRevisionMismatch` if the stored revision has moved
since the read. That is what gives issue #4's "re-read and abort if it moved" teeth
at the write itself rather than only before it — the present, concrete reason for
modelling versions at all.

### Later: signing

Signing quote state so both parties can verify it is unchanged is a real goal but
not this issue's, and nothing here builds toward it speculatively. One finding is
worth recording so it is not re-derived wrongly later: **a versionId cannot serve
as the integrity proof.** `SNAPSHOT_VERSION_ID` is one hardcoded constant shared by
every quote in the shop, and the same id points at different content after every
save — signing `{quoteId, versionId}` would produce something that looks
tamper-evident and verifies happily against modified content.

When signing does land, it will need a digest over a canonically serialized
snapshot (stable field order, float and date formatting), with the versionId as
metadata *inside* the signed payload. That canonical form does not exist yet and
this spec deliberately does not add one. The Protocol module already implements the
pattern for seller mandates (`sha256Base64Url(canonicalizeJson(...))` then
`signCompactJws`), and that is where it belongs — crypto in the bridge would break
the one rule this module exists to enforce.

### The act chain constrains `updateQuote.customFields`

The A2CN act chain — the existing mechanism by which both parties record and verify
what was offered — is persisted on the quote's `customFields`, one act per
top-level key (`a2cn_act_0003_s`), and the reason is spelled out in
`merchant-quote-agent/src/a2cn/chain.ts`:

> Shopware merges customFields shallowly on update, so separate keys make the
> buyer's append and ours conflict-free. […] the role suffix means the two parties
> can never write the same key, so a concurrent append at the same sequence keeps
> BOTH acts rather than one clobbering the other.

That makes shallow-merge semantics a **correctness requirement** on this interface,
not an implementation nicety. `QuoteUpdate.customFields` must merge the given
top-level keys and leave every other key untouched — the TS surface had a separate
`appendCustomField` function for exactly this reason, and collapsing it into a
general `updateQuote` (as an earlier draft of this spec did) risks a write that
replaces the map and destroys the buyer's half of the act chain.

**Verified: it merges, with one exception that would have destroyed the chain.**
`CustomFieldsSerializer::encode()` routes an update of an existing entity through
a `JsonUpdateCommand`, which `EntityWriteGateway` executes as
`JSON_SET(IFNULL(custom_fields, '{}'), …)` — one path expression per given key,
so untouched keys survive. `UpdateQuoteTest` proves it against the live shop by
writing two acts in sequence and asserting both remain, rather than trusting that
reading of the code.

The exception: **`[]` does not merge, it replaces.** An empty map
short-circuits ahead of the `JsonUpdateCommand` path to a literal `'{}'` and
clears the column — the exact silent-loss failure this section exists to prevent.
`QuoteWriter` therefore drops an empty `customFields` before it reaches the DAL
(merging an empty map means changing nothing anyway), and
`testAnEmptyCustomFieldsMapDoesNotClearTheExistingOnes` fails loudly if that
guard is removed.

## Implementation

`SwagCommercialQuoteGateway implements QuoteGatewayInterface`, one file, with a
class-level doc comment listing its `@internal` dependencies up front:

```php
/**
 * @internal-dependencies
 * - Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation::addProduct()
 * - Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter::comment()
 * A SwagCommercial release can change either of these without notice; integration
 * tests (tests/Integration/) are what catches it, not static analysis.
 */
```

In the implementation that block sits on the two `Bridge\Commercial` adapters
that hold those services rather than on the gateway — the gateway itself names
no SwagCommercial type at all, and each adapter carries the note for the one
call it makes. The list is still in one place per dependency, which is what the
issue's done-when criterion asks for.

`SwagCommercialQuoteGateway` also did not stay one file's worth of code. Each
method delegates to a small collaborator (`QuoteSnapshotReader`, `QuoteWriter`,
`QuoteLineItemWriter`, `QuoteRecalculator`, `QuoteStateTransitioner`), grouped
into two objects so the gateway stays inside this repo's
five-constructor-parameter limit. The grouping follows a real seam rather than
arithmetic: `QuoteWriters` holds the four writes that change what the quote
*costs* and therefore need `recalculate()` and the revision precondition around
them; `QuoteLifecycleWriters` holds the two that carry no money and no
precondition — appending a comment and driving the state machine — both of which
are observable outside the shop.

Two, not three. `QuoteState::transition()` is deliberately **not** used, even
though it is the obvious call: reading it, the whole method is a `License::check`,
a `StateMachineRegistry::transition(new Transition('quote', $id, $transitionName,
'stateId'), $context)`, and a null-check on the resulting `toPlace`.
`StateMachineRegistry` is Shopware **core**, and `QuoteDefinition::ENTITY_NAME` is
the string `'quote'`. So depending on `QuoteState` buys about eight lines of
reconstruction and costs a third `@internal` surface — the worst ratio of the
three. `QuoteManipulation` (a real cart round-trip through the Processor) and
`QuoteCommenter` (line-item-history and reply wiring) genuinely earn their keep;
this one does not. The gateway calls `StateMachineRegistry::transition()` directly
with `QuoteTransition`'s value as the transition name.

Note this means the license check for transitions is ours to make, not
SwagCommercial's: `QuoteState` would have run `License::check('QUOTE_MANAGEMENT-6302947')`
for us. The factory-level gate (below) covers it, which is why the gate has to be
on the right toggle.

Per-method mapping:

| Interface method | SwagCommercial call |
| --- | --- |
| `fetchSnapshot` | `EntityRepository` search on `quote` (generic DAL, associations: lineItems, comments, stateMachineState, currency) |
| `updateLineItems` | `EntityRepository::update()` on `quote_line_item` (generic DAL) — see the custom-price constraint below |
| `addProduct` | `QuoteManipulation::addProduct()` (`@internal`) |
| `recalculate` | `SalesChannelContextRestorer::restoreByQuote()` → `QuoteCalculator::recalculate()` (neither `@internal`) |
| `updateQuote` | `EntityRepository::update()` on `quote` (generic DAL) |
| `addComment` | `QuoteCommenter::comment()` (`@internal`) |
| `transition` | Shopware core `StateMachineRegistry::transition()` (zero SwagCommercial classes — see above) |

`recalculate` needs two services, not one: `QuoteCalculator::recalculate()` takes a
`SalesChannelContext`, not a plain `Context`, and the only supported way to build
one for an existing quote is `SalesChannelContextRestorer::restoreByQuote($quoteId,
$context)` — exactly what `QuoteActionController::recalculate()` does. The restorer
is **not** `@internal` at class level (only its constructor is, Shopware's standard
"inject, don't instantiate" marker), so this does not lengthen the `@internal`
list. `QuoteManipulation` takes a plain `Context` and restores internally, so only
`recalculate` needs the restorer at the call site.

### The custom-price constraint (verified in SwagCommercial source)

A repriced line item is only honoured if it also carries
`customFields['quote_custom_offer_price'] === true`. From
`Domain/Transformer/QuoteLineItemTransformer.php`:

```php
$customFields = $entity->getCustomFields();
if (\is_array($customFields) && ($customFields['quote_custom_offer_price'] ?? null) === true) {
    $lineItem->addExtension(ProductCartProcessor::CUSTOM_PRICE, new ArrayStruct());
}
```

Without that extension, the next `recalculate()` re-prices the line from the
catalog and **silently discards** the `priceDefinition` we just wrote. So
`updateLineItems`' price path must write, in the same DAL call:

- a `priceDefinition` (`type: 'quantity'`, `price`, `quantity`, `isCalculated`,
  `taxRules`) — not a bare `unitPrice` field, and
- `customFields: ['quote_custom_offer_price' => true]`.

This is the single most load-bearing detail in the whole bridge: the failure mode
is a money path that looks like it worked and didn't. It is confirmed in
SwagCommercial's own source, not inferred from the TS implementation's comment
about it.

Two consequences for the value objects: `QuoteLineItemChange.unitPriceNet` is a
*net unit price the gateway turns into a full price definition*, not a field
written verbatim; and `customFields` appears on two different surfaces —
`QuoteLineItemChange` (line item, written by the gateway itself for the
custom-price flag) versus `QuoteUpdate.customFields` (the quote, caller-supplied).
The interface must not conflate them.

### Tax state — the design's one money-path bug, and its fix

The `taxRules` value was left open for the spike, with the TS implementation's
hardcoded 19% and its measure-and-rewrite second pass as the fallback. What the
spike found was worse than an open question: **every quote in the shop is
`taxStatus = gross`, and a quote line's `price.unitPrice`/`price.totalPrice` are
gross values, while `quote.amountNet` is genuinely net.** Reading both flat, as
the first implementation did, produced a read model whose line prices and order
total disagreed by the VAT rate — and `Policy\DiscountTotalViolation` compares a
discount derived from lines against that total, so the failure mode was a money
path that looked like it worked. A round-trip test could not catch it, because
the write path was gross in the same direction.

Proof, from quote `019F8ECA…`: `unitPrice` 450.00 with `calculatedTaxes.tax`
71.85. A net reading gives 450 × 0.19 = 85.50; a gross reading gives
450 − 450/1.19 = 71.85. Summed line `totalPrice` equals `amount_total` (450),
never `amount_net` (378.15).

The read model is NET throughout (`QuoteLineNet`), and two changes make it so:

- **On write**, the price definition carries `isCalculated => false`. That is
  what makes `unitPriceNet` a net number rather than a label:
  `GrossPriceCalculator::getUnitPrice()` calls `calculateGross()` on a net input
  only when `isCalculated` is false, and `NetPriceCalculator` uses the same input
  as net directly. So nothing in the bridge branches on tax mode — Shopware
  converts per the quote's own tax state. With `isCalculated => true` the number
  is stored verbatim into the gross `price.unitPrice` field, silently.
- **On read**, line net is derived as `totalPrice - sum(calculatedTaxes[].tax)`
  when `taxStatus` is gross. Rate-agnostic, and it reproduces Shopware's own net
  exactly: checked across all 36 live quotes, summed derived net against
  `amount_net`, zero mismatches over a cent. `requestedPrice` is converted by the
  same line's net ratio, because `Policy\QuoteAutoReplyPricer` compares it
  against `unitPriceNet` and a gross ask there is the same bug one field over.

`taxRules` is therefore read from each repriced line's real tax rules
(`QuoteLineTaxRules`, falling back to 19% only for a line with no tax rules at
all) rather than hardcoded. With `isCalculated => false` the rate drives the
net→gross conversion, so a wrong rate is a wrong stored price — this is
load-bearing, not cosmetic. No second pass is needed.

**One value is deliberately not converted: `Discount` of type `Absolute`.**
`DiscountType::Percentage` is tax-state invariant, but an absolute discount is
denominated in the quote's own tax state — gross, on this shop — because
`QuoteDiscountProcessor::calculateAbsoluteDiscount()` builds a
`QuantityPriceDefinition` through `AbsolutePriceCalculator` with `isCalculated`
defaulting to *true*. So an absolute 100.00 takes 100.00 off the **gross** total
and only 100/1.19 off the net one. The bridge passes it through unscaled and says
so on `Bridge\Data\Discount`; `testAnAbsoluteDiscountIsConsumedAsGrossNotNet`
pins it, and breaks by exactly the VAT rate if anyone "fixes" it by scaling on
our side.

## Runtime gate

Follows ADR 0001 exactly, mirroring `agentic-commerce`'s `QuoteBackendFeature`
pattern already used elsewhere in this codebase:

- `QuoteGatewayFactory`: `class_exists('Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation')`
  (string literal, not `::class`) gates whether `SwagCommercialQuoteGateway` gets
  built at all; the license toggle gates whether it's actually usable.
- **The toggle is `QUOTE_MANAGEMENT-6302947`, not `QUOTE_MANAGEMENT-8702512`.**
  These are two different toggles and the distinction matters here. `8702512`
  guards the Admin API *route* (`QuoteActionController`'s route condition);
  `6302947` is what every service we actually call checks internally —
  `QuoteManipulation`, `QuoteCommenter`, `QuoteState`, `QuoteCalculator` and
  `SalesChannelContextRestorer` all run `License::check('QUOTE_MANAGEMENT-6302947')`
  on entry. Since this bridge calls the services directly and never touches the
  route, gating on `8702512` would check a toggle unrelated to our code path: the
  factory would hand back a gateway that throws a license exception on the first
  write. Gate on `6302947`.
- `services.php` registers `QuoteGatewayInterface` from
  `QuoteGatewayFactory::create()`, which returns `?QuoteGatewayInterface`, so
  consumers take a nullable.
- No SwagCommercial-absent fallback implementation is needed: per the existing
  design, capability absence is handled at the UCP capability layer (issue #1) —
  if the gateway can't be built, the quote capability simply isn't advertised.

### Where each stage of the gate actually landed

The design put both stages in the factory. Only the license stage can live
there: the class-existence stage has to run at **container-build** time, because
by the time the factory is constructible its collaborators have already been
constructed, and three of them hold SwagCommercial services in non-nullable
`object` parameters. So `services.php` opens with

```php
if (!CommercialAvailability::isAvailableByClass()) {
    return;
}
```

and everything below it — the whole bridge graph — is simply absent from a shop
without SwagCommercial. Shopware registers only an *active* plugin's autoloader,
so this is false both when SwagCommercial is missing and when it is installed but
inactive, which covers the second case for free. Plugin activation clears the
container cache, so a later activation takes effect.

Inside that guard the four commercial services are referenced with
`ignoreOnInvalid()`, which reads as belt-and-braces but is the deliberate choice:
in Symfony an unresolvable reference is a **compile-time** failure, so a plain
reference to a service SwagCommercial had renamed would take the entire shop's
container down rather than just the quote capability. With `ignoreOnInvalid()` it
degrades to a `TypeError` on the adapter's `object` parameter — a legible failure
confined to this bridge.

The four ids live once, as public constants on `CommercialAvailability`, because
they are string literals by necessity (ADR 0001) and so invisible to static
analysis. `GatewayWiringTest` resolves all four against the live shop, which is
what catches an upstream rename.

The narrow divergence from ADR 0001's "commercial services injected as
`?object`" is the one the ADR itself already records: `?object` makes every call
into SwagCommercial a `possible-method-access-on-null` error under `mago
analyze`, so the adapters take non-nullable `object` and the nullability is
handled by the registration guard above instead of per-parameter.

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

Three operational facts about that suite, none of them obvious from reading it:

- **The plugin is not installed into the shop.** `tests/Integration/bootstrap.php`
  registers PSR-4 for our namespace and boots the kernel; no `plugin:install`,
  no `plugin:refresh`. That is not a shortcut — this plugin's own `composer.json`
  is currently unsatisfiable in this shop (`php ^8.3` against a default PHP of
  8.2, `ucp-php-sdk/core >=0.0.5` against the 0.0.2 that exists, and
  `cuyz/valinor` absent). Decision (2026-08-27): the constraints stay as they
  are — that shop is a borrowed dev container owned by another project, and this
  plugin gets a dedicated shop that satisfies them (see "Target shop" below).
  Nothing the bridge loads touches those packages, so the suite runs anyway.
  The cost is that `src/Resources/config/services.php` is never loaded by
  Shopware: the gateway is reached through `QuoteGatewayFactory` built by hand
  from container services, and `GatewayWiringTest` covers what is checkable from
  outside a compiled container (see "Where each stage of the gate actually
  landed"). Container compilation of that file is the one thing still unverified.
- **It runs against the shop's real database, not a fresh test one.** Shopware's
  `TestBootstrapper` would otherwise append `_test` to the database name and
  install into an empty schema — which has no quotes, and the fixtures read an
  existing quote rather than constructing one (SwagCommercial's creation path
  needs a customer, a sales-channel context and a cart). Every test therefore
  runs inside `DatabaseTransactionBehaviour`'s rolled-back transaction, and that
  trait asserts the nesting level before rolling back, so a mid-test commit
  fails loudly rather than persisting.
- **`QuoteFixture` selects deliberately, not arbitrarily.** An editable state
  (SwagCommercial treats `accepted`/`expired`/`cancelled` as non-editable, and 9
  of the 36 live quotes are in one of those), at least one live line item, and a
  deterministic sort so reruns hit the same quote. Price tests additionally pick
  a *product* line: all 75 product lines have a non-null `referenced_id` and all
  12 quote-discount lines have it null, and repricing a Shopware-generated
  discount line is meaningless because `recalculate()` regenerates it — a
  failure there would look exactly like the custom-price flag being broken when
  it is not.

### Target shop

The suite currently runs against `shopware-trunk`, a dockware `dev-main`
container owned by the `agentic-supplier-gateway` compose project — borrowed, and
the source of every environmental wart above. The dedicated shop for this plugin
must provide:

- PHP **8.3** as the default CLI (our `composer.json` declares `^8.3`; `src/Policy`
  uses typed class constants, which are 8.3+).
- Shopware 6.7 with **SwagCommercial ≥ 7.13** and the QuoteManagement licence
  toggle `QUOTE_MANAGEMENT-6302947` on.
- **SwagAgenticCommerce ≥ 1.3.0**, which carries `ucp-php-sdk/core ≥ 0.0.5`.
- A clean `config/packages/` and `.env.test` — no `zz-ucp-sdk-test.yaml` loaded in
  every env, no `KERNEL_CLASS='App\Kernel'` — so `TestBootstrapper` boots without
  the workarounds in `tests/Integration/bootstrap.php`.
- Demo quotes in editable states with product lines (`QuoteFixture` needs them),
  and a catching mailer such as mailcatcher, because the transition tests fire
  real `action.mail.send` flows.

Point the harness at it with `SHOP_CONTAINER=<name>` (see `scripts/sync-to-shop.sh`)
and `SHOPWARE_ROOT` if the docroot differs from `/var/www/html`. Once it exists,
`plugin:install` becomes possible and the one unverified item — Shopware compiling
`src/Resources/config/services.php` — can be closed.

**This test suite is the spike.** The issue's open questions — does reprice-then-
recalculate behave like the old Admin API sequence, how does quote versioning react
to two writes in one request, does the expiration-before-`sent` ordering trap from
the TS implementation still apply, which functions are `@internal` — get answered
by writing the integration test for each `QuoteGatewayInterface` method against the
live shop and watching what actually happens, in the same red-before-green shape as
any other TDD cycle. Findings that change the design (e.g. if recalculate turns out
not to see a same-request line-item write) get folded back into this spec before
implementation continues, not discovered after.

Specific things the suite must establish, beyond one test per method:

1. **Custom-price survival.** Write a line price, then `recalculate()`, then
   re-read: the price must still be ours. This is the constraint above, and it is
   the one test that must exist before any other write is trusted.
2. **Tax-mode fidelity.** Whether the net price written comes back as the net price
   stored, or needs the TS implementation's measure-and-rewrite second pass.
3. **Expiration ordering.** Whether `setExpiration` before the `sent` transition is
   still required, or whether that was an Admin-API-path artefact.
4. **Comment authorship.** `QuoteCommenter::comment()` derives `createdById` from
   `AdminApiSource::getUserId()`. In a message handler there is no admin user, so
   `createdById`, `customerId` and `employeeId` are all likely null — record what an
   agent-authored comment actually looks like in the database. Issue #4's
   re-entrancy design depends on being able to tell an agent comment from a buyer
   comment by author; if all three fields come back null, that check needs another
   discriminator (a line-item custom field, or the `customFields` surface
   `updateQuote` already exposes) and issue #4 needs to know that early.

### Spike results (verified 2026-08-27)

All four spikes above are settled, against the live shop (`shopware-trunk`,
SwagCommercial 7.13.0, 36 quotes, `taxStatus = gross` throughout, 87 non-deleted
live line items). The suite is 37 integration tests, none skipped. Summary first,
then the two findings that needed more than a line:

1. **Custom-price survival — the repriced line survives `recalculate()`, and
   only because of the flag.** `customFields['quote_custom_offer_price'] => true`
   plus a full `priceDefinition` is what SwagCommercial's
   `QuoteLineItemTransformer` needs to attach `ProductCartProcessor::CUSTOM_PRICE`;
   without it the line is repriced from the catalog. Not inferred: 22 line items
   in this shop already carry that flag. `UpdateLineItemsTest` asserts the quote
   total is *stale* after a line write before asserting `recalculate()` fixes it,
   so the assertions are attributable to `recalculate()` rather than to a line
   write that happened to update the aggregate.
2. **Tax-mode fidelity — net in, net out, no factor and no second pass, but only
   after a real bug was fixed.** See "Tax state" above; this is the one finding
   that changed the design rather than confirming it.
3. **Expiration ordering — the rule still holds, for a different reason than
   assumed.** Detail below.
4. **Comment authorship — all three author fields are null, which is worse than
   uninformative.** Detail below.
5. **`customFields` merges per top-level key — except `[]`, which replaces.** See
   "The act chain constrains `updateQuote.customFields`".
6. **The revision precondition detects a stale read; it is not a
   compare-and-set.** See Risks.
7. **The DI service ids for the four commercial services are their FQCNs**, with
   no alias: `…\Domain\Admin\QuoteManipulation`,
   `…\Domain\Comment\QuoteCommenter`,
   `…\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer`,
   `…\Domain\Recalculation\QuoteCalculator`. SwagCommercial registers them as
   `$services->set(<FQCN>::class)`. All four are *private* there, which does not
   affect injection and is only why the test harness reaches them through
   `test.service_container`. Runtime-confirmed; they live as constants on
   `CommercialAvailability` so there is one copy.

**3. Expiration ordering — the rule still holds, for a different reason than
assumed.** It is not an Admin-API artefact, and it is not the `sent` transition:
a quote transitioned to `replied` with an expiration three weeks in the past
stays `replied`, and the transition never touches the expiration. What expires
it is `UpdateQuoteExpireTaskHandler::run()`, out of process, which transitions
every quote matching `state = replied AND expiration_date <= now`. So `replied`
plus a stale date IS an auto-expire trigger, and setting the expiration before
`sent` is what keeps the quote out of that set. Two details worth carrying:
`QuoteExpirationDateTimeSubscriber` reschedules that task to the earliest pending
replied expiration on every quote write carrying an `expirationDate`, so the
window is not bounded by the task's 86400s interval; and a NULL expiration is
safe (`NULL <= now` is not true), so only a stale date is dangerous, never an
absent one. `TransitionTest` covers both halves.

**4. Comment authorship — all three fields are null, and that is worse than
uninformative.** `Context::createDefaultContext()` carries a `SystemSource`, so
`QuoteCommenter` has neither an `AdminApiSource::getUserId()` nor a
`SalesChannelApiSource` to derive from: `createdById`, `customerId` and
`employeeId` all come back null, and `createdAt` is the only field an agent
comment reliably carries. The collision is measured, not hypothetical — 42 of
this shop's 118 existing quote comments are already author-less on all three
fields, while 76 carry an author (4 `createdById`, 72 `customerId`, 0
`employeeId`). So issue #4's re-entrancy check cannot use the author field: an
agent comment is indistinguishable from those 42. It needs the `customFields`
discriminator this spec already names as the fallback. `AddCommentTest` asserts
the null authorship rather than merely recording it, so it fails if
SwagCommercial ever starts stamping an author — which is the signal that would
reopen the cheaper design.

**Exceptions are narrower than the first implementation claimed.**
`QuoteNotFoundException` is scoped to `fetchSnapshot`, as this spec always said —
but the interface docblocks initially claimed it for `addComment` and
`transition` too, and neither raises it. Probe-verified: `addComment` on an
unknown id yields Doctrine's `ForeignKeyConstraintViolationException`
(`QuoteCommenter` inserts and lets MySQL reject on `fk.quote_comment.quote_id`),
and `transition` yields Shopware's own `StateMachineException` ("Unable to read
entity quote with id …"). Resolved 2026-08-27: `addComment` now reads the quote
first and raises `QuoteNotFoundException`, at the cost of one redundant read per
comment and no new dependency; `transition` keeps Shopware's typed error. See Risks.

**Mail safety, for anyone else running this suite against a live shop.** This is
not incidental: the shop has *active* flows carrying `action.mail.send` on
`state_enter.quote.state.in_review` and `state_enter.quote.state.replied` —
precisely the two transitions `TransitionTest` drives, each of which would mail a
real customer. Two independent layers make that harmless, and both were verified
rather than assumed:

- `mailcatcher` is listening on the configured `MAILER_DSN` port and never
  relays. (An earlier note in the working ledger claimed *nothing* was listening
  and a send would hit a dead socket — that was wrong; the conclusion survives
  for a better reason.)
- Shopware routes `SendEmailMessage` to the `async` transport
  (`doctrine://default`), so a flow's send is an `INSERT` into
  `messenger_messages` inside the test's transaction, discarded by the rollback
  before SMTP is ever reached.

Verified after the runs: mailcatcher holds 0 messages.

## Non-goals

- Order-fulfillment operations (`attachPoReference`, `acknowledgeOrder` in the TS
  source) — these act on an `order`, not a `quote`, and belong wherever order
  acceptance is handled, not this bridge.
- A fallback/null implementation of `QuoteGatewayInterface` for shops without
  SwagCommercial — capability absence is handled one layer up (issue #1).
- Reconstructing `QuoteManipulation`/`QuoteCommenter`'s behavior from generic DAL
  writes to avoid `@internal` entirely — verified possible (see "What we
  verified"), decided against in favor of less reconstruction risk, since unlike
  `QuoteState` these two carry real logic. Worth revisiting if either proves
  unstable in practice.
- Per-quote draft versions (`QuoteCreateDraftVersionRoute` and friends) — a
  storefront editing affordance; an agent servicing pass has no reason to fork one.
- Anything toward signing: no content digest, no canonical serialization format.
  The bridge supplies a revision marker for concurrency and nothing more. See
  "Later: signing" for the one finding worth carrying forward.

## Risks

**The `@internal` dependencies are the real fragility.** A SwagCommercial patch
release changing `QuoteManipulation::addProduct()`'s or `QuoteCommenter::comment()`'s
signature surfaces at runtime or in `tests/Integration/`, not in `mago analyze` —
this is the accepted tradeoff ADR 0001 already names. The class-level doc comment
on `SwagCommercialQuoteGateway` is what keeps this list visible and shrinkable
rather than ambient, per the issue's own done-when criterion. `QuoteCommenter::comment()`
is additionally marked `@deprecated tag:v6.8.0` on its `$state` parameter, so that
call site will need revisiting for 6.8 regardless.

**Integration tests need the live shop to keep existing.** `tests/Integration/`
is only as good as `shopware-trunk` staying up and matching a version we've
verified against. No version matrix is proposed here (ADR 0001 flags this as a
gap CI should eventually carry); this spec doesn't solve it, just inherits it.

**`updateLineItems`/`updateQuote`'s "null means untouched" batching holds.**
Multiple field writes in one DAL call, and several sequential writes in one
request, neither throw nor silently drop — `UpdateQuoteTest` and
`UpdateLineItemsTest` write repeatedly to the same quote in one test. The
versioning worry did not materialise, for a reason worth recording: these writes
go to the live lane, and the snapshot lane is a separate row that the DAL does not
touch on an ordinary update.

**The write precondition narrows a window; it does not close it. Servicing must
not treat it as a lock.** This is the honest version of what the design left open,
and `RevisionPreconditionTest` establishes both halves.

What it *does* guarantee, measured: a write carrying a revision the quote has
since moved past is refused with `QuoteRevisionMismatch`, the refusal is total
(nothing of the payload lands, `updatedAt` is not bumped), and the comparison is
at millisecond fidelity — `quote.updated_at` is `datetime(3)`, so a revision one
millisecond off is correctly rejected. That last point is not decoration:
`QuoteRevision::matches()` originally compared `getTimestamp()`, which would have
read a same-second concurrent write as unchanged, and same-second is the likeliest
collision rather than the rarest. Both `updateQuote` and `updateLineItems` enforce
it, and each test above fails if the guard is removed from the method it covers.

Of the two fields on `QuoteRevision`, only `updatedAt` can move.
`QuoteSnapshotReader` takes `versionId` from the requested context, so it
identifies the *lane* (live/snapshot), not a per-quote version, and reading the
same lane twice always yields the same value. That is fine — but it means the
precondition rests entirely on one timestamp column.

What it does **not** guarantee: `assertRevision()` re-reads and compares, then the
write follows as a separate statement — no surrounding transaction, no
`SELECT … FOR UPDATE`, no conditional `UPDATE`. A writer that commits inside that
gap is not caught. Shopware's DAL has no native CAS, and the
`Connection::transactional()` the design proposed would narrow the gap without
closing it while putting a `doctrine/dbal` import into production code (see the
open decision below for why that matters). No test can measure the residual
window either: every integration test runs inside
`DatabaseTransactionBehaviour`'s uncommitted transaction, so a second connection
would see nothing. The window is established from the code's structure and stated
rather than measured.

**So: the servicing lock (`symfony/lock`, one per quote id, per the parent
design) is what serialises writers.** The precondition's job is to stop a caller
acting on a snapshot it read too long ago, which is a different and still
worthwhile job. Issue #4 should not read `QuoteRevisionMismatch`'s absence as
proof that no one else wrote.

**`addProduct` crashes the PHP process on variant products.** Confirmed upstream
defect, reproduced independently twice: `addProduct` with a variant (a product
with `parent_id` set) exits **139 (SIGSEGV)** with no PHP error, no exception and
no output. A standalone product (`child_count = 0`) is fine. Characterised as not
xdebug (reproduces with `xdebug.mode=off`), not a PHP stack overflow (reproduces
under `zend.max_allowed_stack_size=1M`, which would raise a catchable fatal), and
not the memory limit (reproduces at `memory_limit=2G`); it happens inside
`QuoteManipulation::addProducts` after `validateProducts`, i.e. in the
cart-conversion / `Processor::process` path. This is a Shopware/SwagCommercial
crash on this shop's demo data, not something the bridge causes.

The consequence for callers is sharper than a normal error: a segfault gives PHP
no chance to throw, so issue #4's servicing would see a dropped worker rather than
a failed operation. **Decision (2026-08-27): guarded.**
`Bridge\Commercial\VariantRejectingProductAdder` decorates the commercial adder
and refuses any product with a parent or with children by throwing
`UnsupportedProductException` before SwagCommercial is reached; unknown ids still
pass through to `validateProducts()`. This is a deliberate functional restriction
(no variant products on quotes) resting on a crash that is not root-caused and may
not exist on other shops — one class to delete once it is understood.
`AddVariantProductTest` covers a variant and a variant parent; those tests are
load-bearing by construction, since an un-fired guard would exit the process
with 139 rather than pass. `AddProductAndRecalculateTest` keeps picking standalone
products (`childCount = 0`).

**Resolved 2026-08-27 — a Doctrine exception leaked through the isolation interface.**
`addComment` on an unknown quote id surfaces
`Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException` through
`QuoteGatewayInterface`, whose entire purpose is isolating SwagCommercial
internals from everything above the bridge. The obvious mapping — catch and
rethrow `QuoteNotFoundException` — was implemented and then backed out, because it
imports `doctrine/dbal` into production code and `doctrine/dbal` is not in our
`composer.json`; `quality:depcheck` flags it as a shadow dependency. Three cheap
closures, none of them the bridge's to choose: declare `doctrine/dbal` and restore
the four-line mapping; have `addComment` read the quote first (no new dependency,
one redundant read per comment); or leave it and have issue #4 catch `\Throwable`
at the servicing boundary. Taken: the redundant read. `addComment` calls the
snapshot reader before `QuoteCommenter`, so an unknown id raises
`QuoteNotFoundException` and `doctrine/dbal` stays undeclared.
`AddCommentTest::testCommentingOnAnUnknownQuoteIsRefusedAsNotFound` pins it.
