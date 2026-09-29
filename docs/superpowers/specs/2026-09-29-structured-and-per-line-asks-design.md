# Structured asks reach the band; per-line asks reach the negotiate model

Date: 2026-09-29

## Status

Approved 2026-09-29. Fixes #222 and #223, both found by the first live eval runs on sw-ag.dev (plugin 1.0.113): `eval-20260929-075002-113a05` and `eval-20260929-085116-3c51dc`.

## #223: a comment-less per-line ask is banded at 0%

Quote 1206: the buyer's storefront requested price is 0.84 net against 727.23, about 99.9% off, with no comment. Band `grant`, offer 15%, order placed.

**Root cause (verified in the source).**

1. `QuoteBandDecider` measures the ask only through `QuoteSnapshot::$buyerTargetNet`: `MoneyMath::requestedDiscount()` (`MoneyMath.php:18`), then `QuoteBandDecider.php:52`, where `?? 0.0` turns "unknown" into 0%.
2. Nothing sets that field when there is no comment:
   - `SnapshotAdapter::toPolicy()` never fills it;
   - `CommentTargetMerger::merge()` returns the snapshot untouched when the comment has no line targets (`CommentTargetMerger.php:30`).

   So the one function that rolls line asks up into it, `rescaledBuyerTarget()`, never runs.
3. The 99.88% is computed only by `AskedDiscountCeiling`. That feeds `CappedAuthority`, which runs after the band.

The retired TS agent's snapshot builder supplied the field: fixture `quote-decision.json`, the "per-line asks" case. The port lost it.

**Fix.** In `CommentTargetMerger::merge()`, the shared seam every decider path goes through: with no comment targets, still roll the snapshot's own per-line requested prices up into `buyerTargetNet` via `rescaledBuyerTarget()`, when the field is empty and at least one line carries a requested price below its quoted one. It never overrides a target that is already set, so it cannot double-count. The rollup applies only when the comment makes no price ask of its own (no `additionalDiscountPercent`, `targetTotal` or `bestPriceRequested` on its `PriceAsk`), so a later "can you do 5%?" never stacks on a stale storefront price.

**Effect.**

| Storefront ask | Result |
|---|---|
| far out of band (99.9%) | escalates `discount_limit_exceeded` before any model call |
| 20% | counters |
| inside the band | granted, as today |

**Tests.**
- A pipeline test in `StructuredAskGateTest`: requested 1.0 against 100 with no comment → `Escalated` with `discount_limit_exceeded`, zero model calls.
- A policy test: a structured ask with no interpretation, no `buyerTargetNet` and a 50% ask → escalate.

The eval scenario `structured-only` keeps its `escalated` expectation, which is now right for the right reason.

## #222: the negotiate model reads a gross per-line ask against net prices

Quote 1202: the buyer wrote "770.21 a unit", which is 11% off the 865.40 gross price. The extractor correctly filed 647.24 net and the band was `grant`, but the negotiate model escalated because "770.21 is higher than 727.23".

**Root cause (verified).**

1. The negotiate prompt is net throughout:
   - `NegotiateLineBlock`: "Everything is net.", "unit price net";
   - the net total.
2. It pastes the buyer's comment verbatim, still gross (`OfferProposer::userPrompt()`).
3. The converted per-line target never reaches the model:
   - `NegotiationContext` carries only a whole-quote target (the quote 1055 fix);
   - the "buyer asks per unit net" column reads `requestedUnitPrice` from the snapshot taken before `AskMirror` wrote the ask.

**Fix (user's choice: fill the column and label the comment).**

1. **Fill the column.** `NegotiationContext` gains the adopted per-line targets, net: `CommentTargetMerger::adopted()`, the number the policy layer prices against. `NegotiateLineBlock` shows a line's adopted comment target when there is one, else its storefront `requestedUnitPrice`. The adopted target wins because `CommentLineTargets::adoptedBy()` has already applied the precedence: in a renegotiation round (`change_requested`/`reopen`) the comment wins even over a storefront ask, otherwise only lines without one adopt it.
2. **Label the comment.** On a quote whose lines are stored gross (any line `netRatio` < 1), the prompt tells the model that the buyer's figures include tax while the table is net.

**Test.** In `NegotiateTargetSpaceTest`, on the gross fixture (net ratio 0.8), the comment "90 a unit" with extract `lineChanges` 90 must produce a negotiate prompt whose line row carries 72.00 and the gross label.

## #222: a model-chosen escalation is not an outage

`OfferProposer.php:102-111` maps the model's own `action: escalate` to `model_unavailable`. That was a deliberate decision under #169, restated in the 2026-09-24 spec at lines 97-99. **The user reverses it (2026-09-29):**
- a new `QuoteEscalationReason::ModelDeclined` (`model_declined`) records that the model chose to escalate;
- `model_unavailable` keeps "unreachable, or answered unusably" (the history budget ran out, transport errors).

This touches:
- the enum and its comment;
- the mapping in `OfferProposer`;
- the two tests that pin the model-declined branch (`OfferProposerTest::testTheModelMayDeclineAndItsReasonIsKept`, `RecordedOutcomePathsTest::testANoOfferProposedPassRecordsOneEscalatedRecord`);
- the admin snippets (`escalation.*` label and `escalationWhy.*` sentence, en and de).

The admin renders reasons generically by snippet key, so it needs no code change. The column stores a plain string, so there is no migration. Existing rows keep `model_unavailable`: which case they were can no longer be told.

## Verification

- `composer run test`, `format:check` and `lint`, plus the admin self-checks.
- After deploying to sw-ag.dev, one eval run. `structured-only` and `multi-round-anchoring` should pass, leaving only the known, unfiled held-floor one-cent rise (expected 20/21).
