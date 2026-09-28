# Never retract a concession (2026-09-28)

## Problem

PM testing: 67 passes raised the quote total, 45 line-floor violations, and
`verification_failed` was 77 of 104 escalations. Discounts are measured against
the ORIGINAL price (the anchored baseline), but three places treated a later
round as if the buyer held nothing yet:

1. `CappedAuthority::forRound` lowers the round's `maxDiscountPercent` to the
   buyer's current ask. Round 1 grants 14%; round 2 "5% off" caps the round at
   5% of the baseline, so the agent is told to offer LESS than the buyer holds.
   The verifier receives the same lowered cap and flags every line still
   carrying the 14% as below the minimum.
2. A quote-wide `p%` write acts on the LIVE line prices and REPLACES the
   existing quote discount: it stacks on lines an earlier round cut, and a hold
   (null or smaller `p`) writes 0%/less over a larger standing discount.
3. Verification runs only after the write, with no rollback, so a bad price
   stays on the quote.

## Invariant

A pass never takes back what the buyer already holds: no positive line may end
above what it costs the buyer right now (its live unit price with the quote
discount folded in) + `Epsilon::MONEY`. The round's cap is
`min(merchant max, max(asked ceiling, standing discount))`: never below the
standing discount unless the merchant's own maximum is lower. If the merchant
lowers the maximum below what the buyer already holds, a hold fails
verification and the pass escalates. That is by design: the agent may not
confirm a price outside the merchant's authority, and may not retract it either.

## Guards

- **Cap floor — `Negotiation\CappedAuthority`.** Standing discount = the larger
  of the total's (anchored total vs live total) and the deepest positive line's
  (anchored unit vs live unit × GoodsFactor). Those are the two things the
  verifier checks. The line term is needed because MarginFloorClamp makes a
  round's write uneven by construction. CappedAuthority owns the cap because it
  is the one place the round's cap is derived; `Policy\AskedDiscountCeiling`
  keeps answering only "what did the buyer ask". A structural reduction (lower
  quantity, removed line) inflates the standing discount against the stale
  anchored total (#49's totals limitation). That can lift the cap to the
  merchant maximum, and is only safe because the predicted write then fails
  closed.
- **Anchored quote-wide percentage — `Policy\QuoteWidePercent`.** A
  quote-wide offer's `p` is re-expressed as the percentage that, applied to
  the live lines, lands the goods at `baseline × (1 − p)`, and never at more
  than the buyer pays today. A line above its baseline anchors on today's
  price. A hold keeps the current discount; a repeat reproduces round one's
  price instead of stacking.
- **Pre-write verification — `Negotiation\OfferApplier::apply`.** The write is
  predicted from the live quote (`PredictedWrite`), and the full
  `OfferVerifier` plus the per-line never-raise check run on the prediction. A
  quote whose total sits below its lines with no discount line is refused as
  well, because the prediction cannot see that discount. Any refusal writes
  nothing and escalates as `proposal_rejected`. The post-write verification and
  the #174 total check stay as the backstop.

## Out of scope

- Whether "2% more" should mean standing + 2%: that is the interpreter's
  reading of the ask, not the cap.
- The prompt's display of the cap (`AuthorityBrief`), handled elsewhere.
