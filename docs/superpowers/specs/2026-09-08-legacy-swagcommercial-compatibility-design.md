# Legacy SwagCommercial compatibility

Date: 2026-09-08

## Status

Approved, not yet implemented.

## Context

The plugin cannot be installed by anyone.

Its quote bridge is written against SwagCommercial `trunk` — the unreleased
6.7.13. The highest released tag in `~/projects/swagcommercial` is `v6.7.12.0`.
Every field the bridge reads that arrived with trunk's line-item discount work
is absent from every shop a pilot customer can actually run, and
`Entity::get()` throws `DataAbstractionLayerException::propertyNotFound` on an
absent field rather than returning null. The first quote the agent touches on a
released shop takes the servicing pass down.

The support floor for this work is **SwagCommercial 6.7.1.2**. That is where
`quote_comment.employee_id` lands; 6.7.0.x lacks it and nobody stays there.

### What actually differs

Two findings reshaped this design and are worth recording, because both
contradict the obvious reading of the trunk diff.

**Line-level offer prices already work on 6.7.12.** The obvious reading is that
trunk's `quote_custom_offer_price` custom field — which
`QuoteLineItemTransformer` turns into core's `ProductCartProcessor::CUSTOM_PRICE`
extension — is what makes a merchant's per-line price survive recalculation, and
that a shop without it loses per-line pricing. The reverse is true. On 6.7.12,
`SalesChannelContextRestorer::ADMIN_EDIT_QUOTE_PERMISSIONS` sets
`CheckoutPermissions::SKIP_PRODUCT_RECALCULATION => true` unconditionally, and
core's `shouldPriceBeRecalculated()` bails out on that permission alone. Trunk
flips that same permission to `false` and introduces the per-line flag as its
narrower replacement. `QuoteLineItemWriter` already writes both the
`priceDefinition` and the flag; on 6.7.12 the flag is inert and harmless.
**The write path needs no change for repricing.**

**The loss is inbound, not outbound.** `quote_line_item.requested_price` is
trunk-only, so on a released shop a buyer has no way to state a per-line ask —
in the storefront UI or anywhere else. Asks arrive only as comment prose. The
negotiation engine already tolerates this: `requestedUnitPrice` is `?float`
throughout and every one of its fifteen consuming files handles null, with
`CommentTargetMerger` deriving targets from comment text instead.

### The breakages

| # | Dependency | ≤6.7.12 | Consequence |
|---|---|---|---|
| 1 | `quote_line_item.requestedPrice` | absent | `QuoteLineNet:38` throws |
| 2 | `quote_line_item.deletedAt` | absent | `QuoteLineMapper:32` throws; `QuoteLineItemWriter:80` write rejected |
| 3 | `quote_comment.quoteLineItemId` | absent | `QuoteCommentMapper:31` throws |
| 4 | state `change_requested` | absent | see below |
| 5 | `QuoteSendRequestRoute` | absent | buyer gateway reports itself unavailable wholesale |
| 6 | `QuoteLineItemRoute` | absent | as above |
| 7 | `QuoteLineItemEntity::getRequestedPrice()` | absent | `CommercialQuoteSnapshotMapper:69` fatals |
| 8 | `quote_line_item.deletedAt` named in a DAL criteria filter | absent | `SwagCommercialBuyerQuoteGateway:357` throws `UnmappedFieldException` |
| 9 | `stateMachineState` association on the load route | not added | `CommercialQuoteSnapshotMapper:40` publishes `state: null` |
| 10 | `quote_line_item.requestedPrice`, written by the ask mirror | absent | `QuoteLineItemWriter:138` write rejected |
| 11 | `config.xml`'s `<card><subtitle>` element | not in the schema | plugin fails to install: `[ERROR 1871] Element 'subtitle': This element is not expected.` |
| 12 | `reopen`'s only exit is `admin_resend`, not `sent` | state machine shape | `OfferApplier`'s claim and `ReplyComposer`'s send both fail silently; quote never reaches `replied` |

Verified present on both: `quote.discount`, the `quote.state` machine,
`QuoteManipulation::addProduct`/`addCustomLineItem`,
`QuoteCommenter::comment(Context, string, string)`,
`SalesChannelContextRestorer::restoreByQuote`, `QuoteCalculator::recalculate`,
`quote_line_item.customFields`, quote versioning. `QuoteVersion::Snapshot` maps
to trunk-only `SNAPSHOT_VERSION_ID`, but no call site passes it, so it needs no
work.

Breakage 4 is the subtle one. On 6.7.12 a buyer's change request runs
`ACTION_REQUEST_CHANGE` into **`reopen`** and posts their text as a comment
(`QuoteRequestChangeRoute:50`); trunk added `change_requested` as a distinct
state. Two places name `change_requested` literally:
`QuoteServicingTrigger:42`, which decides whether to service at all, and
`CommentTargetMerger:34`, which decides whether a comment-derived ask outranks
the structured one. On a legacy shop the comment is the *only* ask channel, so
the second one failing would silently discard every buyer ask the agent was
built to read.

Breakage 7 is the one that bites hardest and is easiest to miss, because it is
a method call rather than a field read: `CommercialQuoteSnapshotMapper` builds
the snapshot published to buyer agents and calls `$lineItem->getRequestedPrice()`
on an untyped commercial entity. On 6.7.12 that is `Error: Call to undefined
method`, and it fires on *every* buyer-side read — `getQuote`, `listQuotes`,
and the snapshot returned by every mutating call. The whole buyer surface is
dead on legacy until this is guarded, not just the pricing parts.

Breakage 5 is not a loss of function. On 6.7.12 `QuoteRequestRoute::request()`
creates the quote directly in `open` (`CartToQuoteConverter:114`); trunk changed
it to create a `draft` that `QuoteSendRequestRoute` then sends. The route is
missing because the step does not exist there.

Breakage 8 was not found by reading — it was found by running
`LegacyBuyerFlowTest` against a real released shop, and it is the category the
other seven entries in this table don't cover: a criteria filter rather than
an `Entity::get()` read or a method call. `SwagCommercialBuyerQuoteGateway`
names `deletedAt` in a DAL `Criteria` on the `lineItems` association, in
`listQuotes()` and the shared `loadQuote()` helper, to keep soft-deleted lines
out of what the buyer sees. `Entity::get()` and method calls degrade to `null`
or throw a catchable error the surrounding code already expects; a criteria
naming an unmapped field is neither — the DAL rejects it outright with
`UnmappedFieldException` before the query ever runs. Live, this surfaced as
every quote-reading `LegacyBuyerFlowTest` case failing with `Quote "…" was not
found for this customer`, because `loadQuote()` translates any exception into
not-found (see Breakage 4's sibling reasoning): the real cause — an unsupported
field in a filter — was invisible behind a misleading 404. Fixed the same way
as the rest of the table: gated on `$capabilities->softDeleteLines`, and
skipped entirely rather than merely worked around, because a release without
that column also has no soft-deleted rows to filter.

Breakage 9 was also found by running against a real shop, not by reading, and
it is a third category the original eight entries don't cover: relying on an
association a newer version's route happens to add on its own, alongside
Breakage 8's criteria-filter case. `loadQuote()`'s `Criteria` never named
`stateMachineState`; it didn't need to, because trunk's `QuoteLoadRoute`
associates it itself. Released SwagCommercial's `QuoteLoadRoute` associates
only `currency` — `stateMachineState` is trunk's addition, not a baseline every
release shares. So on a released shop `$quote->getStateMachineState()` comes
back null, and `CommercialQuoteSnapshotMapper::toSnapshot()` publishes
`state: null` on every buyer-facing snapshot: `getQuote`, `requestQuote`,
`counterQuote`, `acceptQuote`, `declineQuote`. Live, this surfaced as
`LegacyBuyerFlowTest::testAQuoteRequestLandsOpenInOneCallAndCarriesTheComment`
failing with `Failed asserting that null is identical to 'open'`. Fixed by
adding the association in `loadQuote()` unconditionally, not gated on a
capability: the association exists on every supported release, and having the
route also add it is idempotent, so there is no version difference to gate on
here — only a version difference in who was already adding it. `listQuotes()`
already carried this same association for the same reason; the two now share
one `addQuoteReadAssociations()` helper instead of stating it twice.

Breakage 10 arrived by neither of the first two routes: not from reading the
trunk diff, like entries 1–7, and not from running the suite against a real
shop, like 8 and 9. It arrived from **merging `main`**. While this branch was
in flight, `main` grew a feature — "mirror a chat price ask onto the line it
names" (`AskMirror`, `MirroredAsks`) — developed against trunk, with no idea
this branch's capability gate existed. `QuoteLineItemWriter` already built its
DAL payload through `QuoteLineTaxRules::requestedPriceRow()`, and that helper
added a `requestedPrice` entry to the row whenever a net price and a tax ratio
were both available, unconditionally. The merge landed clean — nothing
conflicted, nothing failed to compile — and the two changes were each correct
in isolation. Only running the integration suite against a real 6.7.12 shop
surfaced it: any agent reprice that also mirrored an ask now threw
`PropertyNotFoundException: Property "requestedPrice" does not exist`, because
the row carried the field regardless of `$capabilities->lineItemAsks`. Fixed by
threading the guard into `requestedPriceRow()` itself, which already held a
`CommercialCapabilities` reference from this branch's own merge resolution.
This is a distinct and recurring failure mode, worth naming on its own: a
capability gate is not a one-time retrofit. Every trunk-only column or method
this plugin touches is a fresh place parallel work can reintroduce a hard
dependency on it, invisibly, through a clean merge — the gate has to be applied
to new work as it lands, not just to the code that existed when the gate was
built.

Breakage 11 is the most severe entry in this table, even though it touches
none of the quote domain: every other row degrades a feature at runtime on a
shop that is already installed and running, while this one blocks
installation outright, on any 6.7.12 shop, before a merchant ever gets to see
the plugin work at all. It also shares breakage 10's origin rather than 1–9's:
it arrived by **merging `main`**, not from reading the trunk diff or running
the suite. `main`'s `8261271` ("refactor(config): restructure the admin…")
added a `<subtitle>` to the "A2CN identity" card in `config.xml` against a
`shopware/core` newer than this branch's floor; `subtitle` was only added to
`config.xsd` after 6.7.12, so the merge produced a `config.xml` this branch
could not actually install on the shop range it exists to support. Unlike
breakage 10, this one is not fixed case-by-case: the schema itself is now
covered by a unit test that validates `config.xml` against a vendored copy of
6.7.12.1's `config.xsd` (`tests/Unit/Config/ConfigXmlSchemaTest.php`), so the
next element a parallel merge adds ahead of the floor fails a fast unit test
instead of surfacing as an installation failure on a merchant's shop.

Breakage 12 is the most commercially damaging entry in this table, even though
every other row either blocks a feature or fatals loudly and this one does
neither: the negotiation *succeeds*. `OfferApplier::claim()` drives `process`
to move the quote into review, and `ReplyComposer::send()` drives `sent` once
the reply is posted, both wrapping `IllegalTransitionException` and treating it
as harmless — `claim()` as "already claimed, continue with the offer", `send()`
as "the comment is already with the buyer, so a state we cannot move is worth a
log line and nothing more." On trunk that reasoning holds: `change_requested`
has a `process` edge, so the claim succeeds and `sent` works from the
resulting `in_review`. On released SwagCommercial (≤6.7.12), `reopen`'s only
exit is `admin_resend` — `process` is illegal from there, so the claim fails
silently, and `sent` is then illegal too, so the reply transition fails
silently as well. Two independently reasonable exception swallows compounded
into one unreasonable outcome: the agent grants a discount, writes a comment
telling the buyer exactly what they got, and the quote is left in `reopen`
with no accept path behind that comment. This was not caught by any test —
every unit test built the quote in `in_review` and every integration test ran
against a schema where the claim succeeds — it was found by a merchant
looking at a real quote, `01a085ee828b708a8e4e506e8051e1c8` (#1021): 10%
granted, reply sent, state stuck at `reopen`. Fixed by giving
`QuoteTransition` an `AdminResend` case (the name is shared by both machines,
`reopen` on ≤6.7.12 and `change_requested` on trunk) and having
`ReplyComposer::send()` choose the transition for the state the quote is
ACTUALLY in when the reply lands — not necessarily the state the pass started
in, since a successful claim can move `open` to `in_review` first. Just as
important as the added transition: a failure to reach `replied` no longer logs
at `info` and returns quietly. It logs at `error`, and the audit record itself
is marked so the pass cannot report success while the buyer holds an offer
they cannot accept.

## Goals

- The plugin installs and services quotes on SwagCommercial 6.7.1.2 through
  6.7.12.x, and continues to work unchanged on trunk.
- Both merchant concession levers stay available on both: per-line offer prices
  and the quote-level discount.
- The buyer-side gateway works on legacy for everything except per-line price
  asks — the same set the storefront UI itself offers there.
- Nothing advertises or prompts for a capability the shop in front of it lacks.

## Non-goals

- SwagCommercial 6.6.x. The four merchant-side services exist that far back, but
  it would add a second `shopware/core` constraint (currently `~6.7.0`) and a
  third CI lane for no identified pilot.
- Reimplementing missing commercial routes against the DAL. ADR 0001 keeps
  commercial logic in the commercial plugin; a legacy shop gets less function,
  not a parallel implementation.
- Backfilling `requested_price` on legacy shops via custom fields. The buyer has
  no UI to populate it, so it would be an empty channel.

## Design

### Capability detection

A single value object, injected wherever the difference matters:

```php
final readonly class CommercialCapabilities
{
    public function __construct(
        public bool $lineItemAsks,        // quote_line_item.requestedPrice
        public bool $softDeleteLines,     // quote_line_item.deletedAt
        public bool $lineScopedComments,  // quote_comment.quoteLineItemId
        public bool $draftBeforeSend,     // QuoteSendRequestRoute exists
    ) {}
}
```

A factory builds it once from `DefinitionInstanceRegistry`:

```php
$registry->getByEntityName('quote_line_item')->getFields()->get('requestedPrice') !== null
```

and, for `draftBeforeSend`, from `class_exists` on the constant already in
`CommercialAvailability`. It is registered inside the existing
`isAvailableByClass()` guard in `services.php:392`, so it is only built on a
shop that has SwagCommercial at all.

**Detection is by field presence, never by version number.** SwagCommercial
backports schema into patch releases — `quote.cart_payload` appeared in 6.7.9,
mid-line. A version constant would be wrong the first time somebody backports
`requested_price`, which is the single most likely backport given that it is the
feature blocking these pilots. Field presence is the fact we actually need.

`CommercialAvailability` keeps its current job — the two-stage class-and-licence
gate from ADR 0001, which decides whether the bridge is registered. The new
object answers a different question: given that it is registered, what can this
backend do. The two stay separate.

### Read path

`QuoteLineNet::of()`, `QuoteLineMapper`, `QuoteCommentMapper` and
`CommercialQuoteSnapshotMapper` take `CommercialCapabilities` and read the
trunk-only fields only when present, substituting null otherwise. The first
three read them off a DAL `Entity`; the fourth calls a getter on an untyped
commercial entity, so it is gated on the same `lineItemAsks` flag rather than on
`method_exists` — one fact, one source. No consumer changes: all three values are already
nullable in the read model, and `QuoteLineNet`'s existing `$requested === null`
branch already covers the legacy case exactly.

### Write path

Repricing is unchanged, per the finding above.

Removal is not. `QuoteLineItemWriter::rowFor()` returns a `deletedAt` row for a
removal, and the DAL rejects an unknown field outright. The writer gains a
second payload: soft-delete rows continue through the existing batched
`update()` when `softDeleteLines` is true; otherwise the removed ids are
collected and issued as a `delete()`.

This is a real behavioural difference and the spec accepts it. A hard delete
loses the audit trail that SwagCommercial's own soft-delete model preserves, but
a legacy shop has no `deleted_at` column to preserve it in, and the alternative —
refusing to remove lines on legacy — would block a concession the agent is
otherwise authorized to make. The A2CN act chain in `quote.customFields` is
untouched either way, so the negotiation's own evidence survives.

### State names

`QuoteServicingTrigger::TRIGGER_STATES` becomes
`['open', 'change_requested', 'reopen']`, and `CommentTargetMerger:34` matches
either `change_requested` or `reopen`.

Neither is capability-gated. This is a plain widening: on trunk no route
transitions into `reopen` (it survives only in `QuoteDisplayStateBuilder` and the
uninstall handler), and if one ever did, servicing a reopened quote is the
correct response. Gating it would buy nothing and add a branch to the hottest
path in the plugin.

The comment in `QuoteServicingTrigger` explaining why `in_review` and `replied`
are excluded stays accurate and stays.

### Buyer gateway

`SwagCommercialBuyerQuoteGateway::hasCommercialRoutes()` drops
`quoteSendRequestRoute` from its all-or-nothing check; the other six routes
remain required. Then:

- `requestQuote()` calls the send route only when `draftBeforeSend` is true.
  On legacy the single `request()` call already lands the quote in `open`.
- `counterQuote()` posts the comment through `quoteRequestChangeRoute`, which
  already accepts one, and skips line pricing when
  `CommercialQuoteLinePricing::isAvailable()` is false. That method exists and
  already returns false without the route; no change to it.
- `getQuote`, `acceptQuote`, `declineQuote`: unchanged.
- `listQuotes()` and the shared `loadQuote()` helper add the `deletedAt`
  criteria filter only when `softDeleteLines` is true (breakage 8) — the field
  is trunk-only and the DAL rejects it in a criteria outright, not just in a
  read.

A counter-offer carrying `requested_unit_price` on a legacy shop is a 422, not a
silent drop — the agent asked for something this backend cannot record, and
saying so is the only honest answer.

### Honesty at the edges

One surface, not three. The boundary where a legacy shop must say no is
`counterQuote()`: `QuoteLineItemValidator::validateCounterIdentity()` makes
`requested_unit_price` **mandatory** on every counter line item, and a legacy
backend cannot record it. A counter carrying line items therefore returns a 422
naming the reason; a counter carrying only a comment is valid and is the path
the storefront itself uses there.

The published OpenAPI document and the extract prompt stay as they are, and the
reasoning is worth recording because the first draft of this spec changed both:

- The **OpenAPI document** is a static file served verbatim by
  `QuoteContractController`. Its only claim a legacy shop cannot honour is that
  mandatory counter field, which the 422 above already answers precisely, at the
  point the agent actually asks. Serving a second variant document — or doing
  runtime surgery on the JSON — buys a marginally more accurate contract at the
  cost of a second artifact to keep in sync.
- The **extract prompt** already renders `requested price` as `none` per line
  (`AskInterpreter::userPrompt()`), so on a legacy shop the model simply sees a
  column that is never populated. The prompt describes that column truthfully; it
  does not assert one will be present. Splitting it into two variants would also
  fork `ComposedPrompt`'s hash, which the audit trail records per decision — two
  prompt lineages for no behavioural gain.

`CommercialCapabilities` therefore has four consumers, all inside Bridge: the
three read mappers and the buyer gateway. That is still enough to justify naming
the fact once rather than scattering `has()` checks, but it is a smaller claim
than the first draft made.

### Discovery honesty is a second edge

Not found by reading the spec — found by an agent hitting the dead end in
practice, then tracing the resulting "authorization link expired" consent-page
error back to its real cause in the shop log, an hour later.

`QuoteCapabilityProfileContributor` re-adds `com.shopware.quote` to the
published UCP profile unconditionally (see its class docblock above for why it
has to re-add anything at all). But a buyer agent cannot use that capability
without an identity-linking access token, and Agentic Commerce's
`identity_linking` capability is off by default and configured per sales
channel. Advertising the quote capability on a shop that has not turned
identity linking on is the same honesty failure as the OpenAPI document and
extract prompt question above, just reaching a different surface: discovery is
the only signal a buyer agent gets, and a capability it cannot obtain a token
for is a dead end it cannot diagnose from the outside.

The fix mirrors `RuntimeConfiguration::isCapabilityEnabled()`'s own semantics
in `ProfileBuildInput::$enabledCapabilities`: an empty list means the shop
never restricted capabilities at all, not that none are enabled, so it must
still advertise the quote capability. Only a non-empty list that omits
`dev.ucp.common.identity_linking` suppresses the descriptor. Getting that
inverted would silently kill the feature on every shop that has never touched
capability restriction, which is the common case — a worse regression than
the bug being fixed. This is buyer-discovery-only: the merchant-side servicing
loop never needs a buyer token and is unaffected.

## Testing

**Unit.** `CommercialCapabilities` is a constructor argument, so both profiles
are two fixtures. Cover: each of the four read guards with the field absent and
present; `QuoteLineItemWriter` issuing a `delete()` under `softDeleteLines:
false` and a `deletedAt` update under true; `requestQuote()` skipping the send
route under `draftBeforeSend: false`; `counterQuote()` rejecting a line price
when line pricing is unavailable; the widened state lists in
`QuoteServicingTrigger` and `CommentTargetMerger`.

**Integration.** A second parity shop pinned to SwagCommercial 6.7.12, running
the existing integration suite as a second lane. This is the only layer that
catches a DAL write the legacy schema rejects — breakage 2 would have passed
every unit test we would have thought to write. `GatewayWiringTest`, which today
resolves all the commercial service ids against one live shop, runs against both
and asserts the expected capability profile for each.

**Capability probe correctness.** A test that reads `QuoteDefinition`,
`QuoteLineItemDefinition` and `QuoteCommentDefinition` from the swagcommercial
git tags and asserts the probe classifies each release in the supported range
correctly. This is what catches drift between what we believe about a release and
what it contains, including a backport landing in a patch.

## Open questions

None.
