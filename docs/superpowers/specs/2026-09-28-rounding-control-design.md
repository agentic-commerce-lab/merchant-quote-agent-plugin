# Rounding control — design

**Date:** 2026-09-28 · **Status:** approved · **Base:** `claude/never-retract-concessions` (#208)

## Problem

PM testing (Juan, 2026-09-25) found the agent's offers read as machine output:
a total of 12,356.12 € or a discount of 4.34 % looks strange to a human buyer.
Merchants want to choose whether the agent's own figures come out round.

## Setting

Two plugin config fields (`config.xml` → `QuoteAgentSettingsReader` →
`NegotiationPolicyArray` → `Policy\Data\QuoteLimits`, the same path as
`minMarginPercent`):

| Key | Values | Default |
|---|---|---|
| `roundingMode` | `off` · `discount_percent` · `quote_total` | `off` |
| `roundingStep` | float ≥ 0 | blank |

`roundingStep` is percentage points in `discount_percent` mode (e.g. `0.5`) and
currency units of the buyer-facing total in `quote_total` mode (e.g. `10`).
Blank or `0` means off whatever the mode. Validated in `QuoteLimits`
(`PositiveOrZero`; the mode as a backed enum `Policy\Data\RoundingMode`).

## Rules (both modes)

1. **Merchant's favour only.** Rounding always means *less* discount. It
   therefore cannot breach `maxDiscountPercent`, the counter band, the margin
   floor or the value ceiling, and needs no new check.
2. **Never the buyer's own figure.** When the offer equals the buyer's ask
   (asked percentage, asked line prices, or asked target total, within
   `Epsilon::MONEY` / `Epsilon::RATE`), it is written unrounded. Rounding only
   touches figures the agent chose.
3. **Never above the standing price.** If the rounded offer would price any
   line above what the buyer already holds (#208's never-retract invariant,
   `PredictedWrite`), the unrounded offer is written instead. Rounding never
   causes an escalation.
4. **Never to nothing.** If rounding would bring the discount to zero or less
   (e.g. 0.3 % with step 0.5), the unrounded offer is written.

## Mode `discount_percent`

`terms.discountPercent` from the model is floored to a multiple of the step
(7.34 → 7.0 at step 0.5; `MoneyMath`-style float-noise guard so 7.5 stays 7.5)
after the model answers and before authorization, so the authorizer, the
verifier, `OfferLevelMirror`'s per-line conversion and the reply all see the
rounded rate. One pure function in `src/Policy`.

## Mode `quote_total`

Applies to **quote-wide** offers only. Per-line offers are mostly the buyer's
own line prices and are left unrounded (documented limit).

1. Predict the buyer-facing total the offer would land on (gross on a gross
   quote, net on a net quote — `QuoteTotals::buyerFacingTotal()` space),
   including shipping.
2. Round it **up** to the next multiple of the step.
3. Write a SwagCommercial **absolute** quote discount of
   `goods total before discount − (rounded target − non-goods costs)`, in the
   quote's own tax state (see `Bridge\Data\Discount` — absolute values are
   gross on a gross quote). This lands exactly on the round figure, which an
   adjusted percentage cannot guarantee under per-tax-rate cent rounding.
4. `PredictedWrite` / `OfferLanding` learn to predict an absolute discount so
   the pre-write verification (#208) still runs on the real result: the net
   relief is the absolute value scaled by the goods' net/gross ratio.
5. If the prediction or the post-write check disagrees with the round target by
   more than a cent, the existing backstops stay in force (no silent
   acceptance of a raised total).

## Audit

The `policy_verdict` trace meta gains `rounding`: `{mode, step, unrounded,
rounded, skipped: null|"buyer_figure"|"standing_price"|"to_zero"}`. No
migration. The reply needs no change: it is built from the post-write quote
(`ReplyComposer`), so it states the rounded figure.

## Out of scope

- Rounding per-line unit prices.
- Rounding a figure the buyer asked for.
- Shopware's own cash rounding (`totalRounding`) — shop-wide, round-to-nearest;
  unaffected as long as the step is a multiple of its interval.

## Testing

- `QuoteLimits` / config: mode + step parse, blank step = off, invalid mode rejected.
- `discount_percent`: floor to step, float noise (7.5 stays 7.5), to-zero skip,
  buyer-figure skip, per-line conversion inherits the rounded rate.
- `quote_total`: gross quote with mixed 19 % / 7 % lines + shipping lands exactly
  on the round total; net quote; standing-price skip; buyer-target skip;
  `PredictedWrite` predicts an absolute discount correctly.
- Unit suite, format, lint, typecheck green; one integration test on the test
  shop for the absolute discount landing (SwagCommercial recalculation).
