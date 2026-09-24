# A Minimum-Margin Floor Under Every Offer

Date: 2026-09-24

## Status

Implemented. 2026-09-24.

## Context

Shopware keeps a **purchase price** per product (`product.purchasePrices`, a
`PriceCollection` with net and gross per currency, `Inherited` on variants). It
is the merchant's own cost: never shown in the storefront, used internally for
margin calculations.

The agent's only price limit today is a percentage: `maxDiscountPercent`,
optionally with a counter band above it. A percentage knows nothing about what
the product cost. On a thin-margin product the merchant's usual 15% can sell
below cost, and the only way to prevent that today is to lower the cap for the
whole shop.

The user asked for a config option that bounds every negotiated price:

> lowest_allowed_negotiation_price >= purchase_price + min_margin

and gave the worked example this spec is tested against: a product listed at
120, the buyer asks for 15% (102), purchase price 100, minimum margin 10% — the
floor is `100 × 1.10 = 110`, so the agent may grant at most 10 off.

## Decision

### The rule

Per product line:

```
floor = ceilToCent(purchasePriceNet × (1 + minMarginPercent / 100))
```

`minMarginPercent` is a **markup on the purchase price** (the user's choice over
gross margin on the selling price and over an absolute amount). The agent may
reduce a line down to its floor and never below it.

The floor **clamps** the price; it does not escalate. The band decision is
unchanged — whether an ask is granted, countered or escalated is still decided
against `maxDiscountPercent` and the counter band exactly as today. The floor
only limits how low the written price may go. In the example the band grants
15%, and the line is written at 110, not 102.

On a multi-line quote each line is floored on its own (the user's choice over
uniformly lowering the quote-wide percentage): a quote-wide 15% ask with a
thin-margin line B gives every other line its 15% and B its floor.

### Configuration

- New field `minMarginPercent` (float) in the **Negotiation policies** card of
  `config.xml`. Blank means off. `0` means "never below cost".
- `QuoteAgentSettingsReader::KEYS` gains the key; `NegotiationPolicyArray`
  passes `RawConfigValue::float($raw, 'minMarginPercent')` through, null when
  cleared.
- `QuoteLimits` gains `?float $minMarginPercent = null` with
  `#[Assert\PositiveOrZero]`. No upper bound: a markup can exceed 100%. It is
  validated where every other limit is, in `QuoteAgentSettingsFactory`; no new
  validation site.
- **`QuoteLimits::withMaxDiscountPercent()` must carry the new field.**
  `CappedAuthority` rebuilds the limits through it on every round the buyer's
  ask is below the cap; dropping the field there would silently switch the
  floor off on exactly those rounds. A test pins it.

### Where the purchase price comes from

A new Bridge reader, `Bridge\PurchasePriceReader`, implements a new
`Negotiation\PurchasePricesInterface` — the pattern `CustomerHistoryInterface`
and `DalCustomerHistory` already use, which keeps `src/Negotiation` free of
Shopware imports (`NamespacePurityTest`).

```php
/**
 * @param list<string> $productIds
 * @return array<string, float> productId => net purchase price per unit, in $currencyIso
 */
public function netUnitPrices(array $productIds, string $currencyIso): array;
```

- Filters the ids with `Uuid::isValid()` first: `productId` is the line item's
  `referencedId`, which is not a product id on custom lines.
- Resolves the currency by ISO code through `currency.repository` (id and
  `factor`). An unknown ISO code returns `[]`.
- Reads `product.repository` with inheritance enabled
  (`Context::enableInheritance()`), because `purchasePrices` is `Inherited` and
  a variant usually carries none of its own.
- Takes `PriceCollection::getCurrencyPrice($currencyId)`. That method falls back
  to the default-currency price **unconverted**, so when the returned price's
  currency differs from the quote's, its net is multiplied by the currency
  factor.
- A product with no purchase price, or not found, is left out of the map.

It is called only when `minMarginPercent` is set.

### Where the floor is enforced

Everything happens in `OfferApplier::apply()`, the one place an offer is
written (both `updateLineItems` and the quote `Discount` write live only
there).

1. **Floors, once.** Right after the fresh pre-write read, `$live =
   SnapshotAdapter::toPolicy($reference)` moves up (it is computed after the
   write today) and a new pure Policy class computes the effective floors:

   ```php
   // Policy\MarginFloors::of(QuoteSnapshot $live, array $purchaseNetByProduct, float $marginPercent)
   // => array<string lineItemId, float effectiveFloorNet>
   effectiveFloor = min(floor, roundMoney(liveUnitNet × goodsFactor(live)))
   ```

   - Only positive lines with a purchase price get an entry.
   - `goodsFactor` is `(Σ positive line totals + Σ negative line totals) /
     Σ positive line totals`, capped at 1. It is 1 without a discount. A
     quote-wide percentage discount is a negative line SwagCommercial generates
     (see `QuoteBaselineLines::extendedWith()`), so the factor is what a line
     actually costs the buyer. It is built from the lines, not from `totalNet`,
     so shipping does not distort it — which is why this does not reuse
     `NetFactor`.
   - The `min` is what makes the floor **never raise a price**. A line the
     merchant already priced below its floor (a loss leader, a changed purchase
     price) gets no further discount, but is not pushed back up either — which
     `OfferApplier`'s never-raise check would escalate.

2. **Clamp before the write.** A new pure Policy class,
   `Policy\MarginFloorClamp::clamp(ProposedOffer $offer, list<QuoteLineSnapshot> $liveLines, array $floors): ?ProposedOffer`,
   works out the effective price each positive line would land at:

   | Offer | Effective price of line *i* |
   |---|---|
   | quote-wide `p` | `live_i × (1 − p/100)` — the percentage replaces any existing discount |
   | per-line, line named | `offered_i × goodsFactor(live)` — today's write keeps the existing discount |
   | per-line, line not named | `live_i × goodsFactor(live)` |

   If every line with a floor lands at or above it (within `Epsilon::MONEY`),
   it returns **null** and the offer is written exactly as today. Otherwise it
   returns a **complete per-line offer**: every positive line priced at
   `max(roundMoney(effective_i), floor_i)` (lines without a floor keep their
   effective price). Then `OfferApplier::write()`:

   - writes only the lines whose price differs from their live unit price
     (rewriting an unchanged price through the gross conversion could move it
     a cent and trip the never-raise check), and
   - **resets the quote-level discount to 0%** when the quote carries one, in
     the same `updateQuote` the per-line branch already issues.

   The reset is the stacking guard. Without it, round one's "5% off the quote"
   would stay on top of round two's floored line prices and push them under the
   floor. It is safe because a complete per-line offer already folds the old
   discount into every line's price (`goodsFactor` above) — nothing the buyer
   had is lost, it is re-expressed.

   A non-null result is logged at info level with the quote id only — no
   prices, no line ids.

3. **Check after the write.** A new `Policy\MarginFloorVerifier`, run by
   `OfferVerifier` alongside the totals, line and expiration checks, compares
   each positive line of the post-write snapshot: `unitPriceNet ×
   goodsFactor(final)` must be at least its effective floor minus
   `Epsilon::MONEY`. `VerifyOfferInput` gains `array $floors = []`; an empty
   map checks nothing. A violation escalates through the existing
   `VerificationFailed` path, like every other verifier finding. This catches
   what the clamp cannot see: a rounding surprise, an absolute discount whose
   share shifted, a bug in the clamp.

   The violation text names the line and the floor price. It goes to the log
   and the audit record (both merchant-only); `QuoteEscalator`'s buyer comment
   is generic and carries none of it.

### Refinement to the approved design

The design approved in chat said a per-line write would **always** reset an
existing quote-level discount, fixing today's stacking on the per-line path as
well. Writing it out showed that this breaks lines the offer does not name:
after "5% off the quote" in round one, a round-two per-line offer for line A
alone would reset the discount and put line B back to full price, raising the
total — which the never-raise check escalates.

So the reset happens **only when the floor clamp fires**, because only then is
every line re-priced. Without a floor, or when no floor binds, the per-line
write path is exactly what it is today — including today's stacking, which is
left as it is and which `MarginFloorVerifier` now catches whenever it would go
below a floor.

### What does not change

- **The model never sees a purchase price or a floor.** Nothing is added to
  the negotiate prompt, `AuthorityBrief`, the policy line DTOs or the Bridge
  snapshot, so neither can reach a model call or a buyer comment. The buyer's
  reply is composed from the post-write snapshot (`ReplyTemplate::compose()`),
  so it reports the floored figure truthfully ("down by 8.33% to 110.00"). A
  buyer who insists gets the next round clamped to the same floor, and "this
  quote stands at …" (#174/#175).
- The band decision (`QuoteBandDecider`), the counter band,
  `AskedDiscountCeiling`/`CappedAuthority`, `QuoteDiscountApplier`, the offer
  authorization (`OfferAuthorizer`) and the outcome recorded (`offered` /
  `countered`) are untouched. A floored grant is recorded as `offered`.
- The published A2CN mandate (`NegotiationBands`) stays shop-wide. Publishing a
  per-product floor would publish the cost it is derived from. The floor only
  ever makes an offer smaller than the published bands allow.

## Edge cases

| Case | Behaviour |
|---|---|
| `minMarginPercent` blank | No read, no floors, today's behaviour byte for byte |
| Custom line, deleted product, no purchase price | No floor on that line |
| Purchase price only in the default currency | Converted by the quote currency's factor |
| Variant without its own purchase price | Parent's price, through inheritance |
| Line already at or below its floor | No further discount on it; never raised |
| Buyer asks less than the floor allows | Nothing binds; offer written as today |
| Ask exceeds the counter band | Escalates as today — the floor never runs |
| A negative line that is not the quote discount (a manual credit) | Counted in `goodsFactor` like a discount, and not removed by the reset — conservative: at worst the verifier escalates |

## Testing

Unit (Policy, no kernel):

- `MarginFloors`: the worked example (120 list, cost 100, margin 10% → 110);
  ceil-to-cent; the `min` with the live price; `goodsFactor` with a negative
  discount line; lines without a purchase price.
- `MarginFloorClamp`: the worked example (15% quote-wide on one line → a
  per-line offer at 110); the multi-line case (A keeps 15%, B at its floor);
  nothing binds → null; per-line offer clamped; unnamed lines carry the old
  discount into their price.
- `MarginFloorVerifier`: passes at the floor; fails a cent below; stacked
  discount under the floor fails.
- `QuoteLimits`: `withMaxDiscountPercent()` keeps `minMarginPercent`;
  `PositiveOrZero` rejects negatives; `fromArray` blank → null.

Unit (Negotiation):

- `OfferApplierTest`: with a margin set and a fake `PurchasePricesInterface`,
  the gateway receives the floored line prices and a 0% discount reset; with no
  margin the reader is never called.

Integration (test shop):

- `PurchasePriceReader`: a variant inherits its parent's purchase price; a
  default-currency-only price is converted; a non-UUID id is ignored.

## Docs

- `docs/end-to-end.md`: a `minMarginPercent` row in the configuration table,
  and one sentence in the pricing section saying the floor clamps, never
  escalates.

## Out of scope

- Telling the model the floors. Rejected: it risks the floor appearing in a
  buyer comment, and a model that misreads it turns a grant into an
  escalation.
- Per-product or per-category margins. One shop-wide number, as requested.
- Fixing the stacking of a per-line offer on an earlier quote-level discount
  when no floor binds. It predates this change; see the refinement above.
