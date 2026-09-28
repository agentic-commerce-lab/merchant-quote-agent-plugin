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
discount folded in) + `Epsilon::MONEY`, and the round's cap is never below the
standing discount.

## Guards

- **Cap floor — `Negotiation\CappedAuthority`.** Effective cap =
  `min(merchant max, max(asked ceiling, standing discount))`, standing =
  anchored total vs live total. CappedAuthority owns it because it is the one
  place the round's cap is derived; `Policy\AskedDiscountCeiling` keeps
  answering only "what did the buyer ask".
- **Anchored quote-wide percentage — `Policy\OfferConversion::quoteWidePercent`.**
  A quote-wide offer's `p` is re-expressed as the percentage that, applied to
  the live lines, lands the goods at `baseline × (1 − p)` — but never at more
  than the buyer pays today. A hold keeps the current discount; a repeat
  reproduces round one's price instead of stacking.
- **Pre-write verification — `Negotiation\OfferApplier::apply`.** The write is
  predicted from the live quote (`OfferWrite::landing`), and the full
  `OfferVerifier` plus the per-line never-raise check run on the prediction. Any
  violation writes nothing and escalates as `proposal_rejected`. The post-write
  verification and the #174 total check stay as the backstop.

## Out of scope

- Per-line concessions that are uneven across lines: a line cut deeper than the
  quote's total standing discount still trips the line check under a
  total-level floor. It now fails safely (pre-write rejection, nothing written)
  instead of landing a raised price.
- Whether "2% more" should mean standing + 2%: that is the interpreter's
  reading of the ask, not the cap.
- The prompt's display of the cap (`AuthorityBrief`), handled elsewhere.
