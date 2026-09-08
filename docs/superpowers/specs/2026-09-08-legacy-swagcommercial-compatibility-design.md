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

Breakage 5 is not a loss of function. On 6.7.12 `QuoteRequestRoute::request()`
creates the quote directly in `open` (`CartToQuoteConverter:114`); trunk changed
it to create a `draft` that `QuoteSendRequestRoute` then sends. The route is
missing because the step does not exist there.

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

`QuoteLineNet::of()`, `QuoteLineMapper` and `QuoteCommentMapper` take
`CommercialCapabilities` and read the three trunk-only fields only when present,
substituting null otherwise. No consumer changes: all three values are already
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
- `getQuote`, `listQuotes`, `acceptQuote`, `declineQuote`: unchanged.

A counter-offer carrying `requested_unit_price` on a legacy shop is a 422, not a
silent drop — the agent asked for something this backend cannot record, and
saying so is the only honest answer.

### Honesty at the edges

Two surfaces must stop promising what a legacy shop cannot do:

- `QuoteCapabilityProfileContributor` omits `requested_unit_price` from the
  advertised line-item shape when `lineItemAsks` is false.
- `AskInterpreter`'s prompt stops telling the model that structured per-line
  asks exist when they never will, so the model reads the buyer's prose as the
  sole ask channel rather than as a supplement to a field that is always null.

This is the reason the capability is a named object rather than three inline
`has()` guards. The bridge, the UCP profile and the prompt all need the same
fact, and it should be stated once.

## Testing

**Unit.** `CommercialCapabilities` is a constructor argument, so both profiles
are two fixtures. Cover: each of the three read guards with the field absent and
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
