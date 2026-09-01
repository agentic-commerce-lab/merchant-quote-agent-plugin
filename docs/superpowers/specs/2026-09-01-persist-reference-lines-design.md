# Persist the pre-negotiation reference lines — design

**Issue:** [#49](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/49) (was #2(a))
**Date:** 2026-09-01
**Unblocks:** the later-round half of [#47](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/47)
**Removes:** the stopgap at `OfferRound.php:62`

## Why this exists

A per-line offer is bounded against `referenceLines`, and the reference is re-captured every round from the current snapshot. Round two is therefore measured against round one's already-reduced prices and compounds straight past `maxDiscountPercent` — with the authorizer and the verifier both reporting clean. #2 identified this and shipped without it; the fix it named was a pre-negotiation reference snapshot, captured once and persisted per quote.

What stands in today is `OfferRound.php:62`, which escalates any per-line offer once the agent has already replied. It is correct and it is expensive, because **per-line is the normal path, not an edge case**: every open quote in the test shop carries a `requested_price` on its line — 1023 asks 15 on a 19.99 line, 1030 asks 14.9, 1031 asks 449 — since that is Shopware's buyer-facing "Requested price" field and how a trunk buyer itemises an ask. The agent answers round one and hands round two to a human on most real negotiations.

It also caps #47. That fix makes the agent answer a per-line ask at the buyer's level, but only on the first round, precisely because converting a later round walks it into this guard — two integration tests flipped from `offered` to `escalated` when it did.

## Two leak sites, not one

Both of these re-derive their reference per pass, and fixing only the first would leave the cap leaking:

| Site | Reference today | Reached via |
|---|---|---|
| Authorizer — `LinePriceOfferCheck` | this round's `$snapshot->lines` | `ProposedOffer::withReferenceLines()` in `OfferProposer::authorize()` |
| Verifier — `LineOfferVerifier` | this pass's pre-write snapshot | `VerifyOfferInput::$reference` in `OfferApplier::apply()` |

## Scope

Four decisions were taken before design.

1. **The anchor is the quote as the agent first found it**, not the product's catalog price. #2's prose says "pre-negotiation reference snapshot"; its fixture says `catalogPrice`. They differ whenever a merchant hand-priced the quote before the agent touched it, and that pricing is the merchant's decision. Bounding against catalog would let the agent unwind a deliberate merchant markup or discount. The agent's mandate is what it may concede *from here*.
2. **A reopened or expiration-extended quote keeps its baseline.** The cap covers total concession from the original price across the quote's whole life. Resetting would reintroduce compounding through the one door a merchant can open repeatedly.
3. **Quotes already mid-negotiation keep escalating.** A quote with `ServicingFingerprint::MARKER_KEY` stamped but no baseline predates this work; per-line offers on it behave exactly as they do today. The population is small and shrinks to nothing as those quotes close, and the alternative would hand each of them one more full discount on top of what they already received.
4. **#47's first-round restriction is lifted** as part of this work, since the guard that forced it is what this removes.

## Approach: a quote custom field, captured at the first price write

**Rejected: a dedicated DAL entity.** One row per quote is cleaner to query and independently prunable, but it costs a migration, an entity, a writer and a repository for data read twice per pass that belongs to the quote's own lifetime. Quote custom fields already exist for exactly this, are already shallow-merged so a new key cannot collide, and are already how this plugin keeps per-quote state.

**Rejected: derive it from the audit trail.** #19's table holds `totalNetBefore` and a `writes` list, and the first record for a quote describes the pre-negotiation state. It stores no per-line prices, which alone settles it — but the principle matters more: making pricing correctness depend on an observability table means pruning the audit log would silently change what the agent may offer. The audit trail must stay wipeable.

**Chosen: `merchant_quote_agent_baseline` on the quote's custom fields**, alongside the three keys already there (`…_serviced`, `…_escalated`, `…_attempts`), written through `QuoteUpdate::$customFields`, which the gateway shallow-merges.

### Where it is captured, and why there

Not at pass start. `OfferApplier::apply()` already reads a fresh snapshot immediately before writing:

```php
$reference = $gateway->fetchSnapshot($quoteId);
```

That is by construction the pre-negotiation state on the first pass that changes anything. The baseline rides along in the `QuoteUpdate` that same method already issues, only when the quote has none — **no extra write and no extra revision bump.**

This self-corrects on the paths that matter. A pass that escalates writes nothing, so no baseline is stored; that is right, because nothing changed and the next pass's prices are still original.

### Shape

A list of `{lineItemId, unitPriceNet}` — the net unit price of every line as the agent first found it. No timestamp: the quote's own history carries that, and an unread field rots.

## Components

### `Negotiation\QuoteBaseline`

Two static methods, one parsing site:

- `read(BridgeSnapshot $snapshot): ?list<PolicyLineSnapshot>` — null when the field is absent, and also when it is present but malformed, since a baseline nobody can parse must not silently become "no limit".
- `stamp(list<BridgeLineSnapshot> $lines): array<string, mixed>` — the custom-field fragment for `QuoteUpdate`.

It lives in `Negotiation` rather than `Bridge` because it produces policy DTOs, which is the mapping `SnapshotAdapter` already owns. It imports nothing from Shopware, so `NamespacePurityTest` stays green.

### Wiring

- `OfferRound::play` reads the baseline from the bridge snapshot and passes it to `OfferProposer::propose()`, which becomes its fifth parameter — at the cap, not over it.
- `OfferProposer` uses `$baseline ?? $snapshot->lines` as the reference. Missing baseline resolves to the current lines, which is correct on the first pass by definition.
- `OfferApplier` reads the baseline itself from the bridge snapshot it already holds, and uses it as the verifier's `reference` when present.

### A simplification that falls out

`OfferProposer::authorize()` takes `PolicySnapshot $snapshot` solely to read `$snapshot->lines`. It becomes `array $referenceLines`. That keeps it at five parameters instead of six, and states what it actually depends on.

### Removals

- `OfferRound.php:62`'s second-round escalation, and the test that pins it.
- `OfferProposer::atTheBuyersLevel()`'s `$conversation->agent === []` condition (#47), so `OfferLevelMirror` runs on every round.

Replacing the removed guard: a per-line offer is escalated only when the quote has been serviced before **and** carries no baseline — decision 3's in-flight case.

## Two line-level rules

**A line added mid-negotiation** has no baseline entry. It takes its current price as its own baseline: it has had no agent concession yet, so there is nothing to compound, and rejecting it as "not on this quote" would escalate for no reason.

**A line removed mid-negotiation** leaves a stale entry that is never looked up. Pruning it would be a second write for no reader.

## Testing

**Unit — `QuoteBaseline`:** round-trip through `stamp`/`read`; absent field yields null; malformed field yields null rather than a partial list; a line absent from the baseline resolves to its current price; a stale entry for a removed line is ignored.

**Unit — `OfferProposer`:** an offer is bounded against the baseline, not against the round's lines. The distinguishing fixture has a baseline strictly higher than the current lines, so a test that confused the two would pass against the wrong number.

**Integration — the one that proves the point:** a three-round per-line chain must not end below `original × (1 − maxDiscountPercent/100)` on any line. This is #2's own stated fixture requirement and nothing currently exercises it; it is the test that would have caught the original defect.

**Integration — the regressions #47 documented:** `NegotiationPipelineTest::testAnInBandAskIsAppliedToTheQuoteAndAnswered` and `DecisionRecordTest::testARealPassWritesARealRow` must be green with the guard removed, since those are what flipped to `escalated` when per-line offers reached a second round.

**Integration — capture:** the first pass that writes prices stores the baseline; a pass that escalates stores none.

## Done when

- A per-line offer is bounded against the prices the quote had before the agent first touched it, at both the authorizer and the verifier.
- A three-round per-line chain cannot end below the cap relative to the original price.
- `OfferRound`'s second-round escalation is gone, and a per-line ask is answered per line on every round.
- A quote serviced before this shipped, carrying no baseline, still escalates its per-line offers.
- Capturing the baseline adds no write the pass was not already making.

## Not here

Pruning the baseline when a quote closes — it is a few hundred bytes on a quote's own custom fields and no reader is harmed by it. The catalog-price anchor from #2's fixture, deliberately not chosen. Exposing the baseline anywhere merchant-facing; if that becomes wanted it is a detail row on #7's page, not part of this.
