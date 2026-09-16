# Extend the baseline to lines added mid-negotiation, and bound the offer from below — design

**Issues:** [#54](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/54), [#56](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/56)
**Date:** 2026-09-16
**Upstream:** [#49](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/49) — `2026-09-01-persist-reference-lines-design.md`. Its reasoning is the constraint here, not the thing being revised.

Two defects in the same authorize/verify area, deliberately specified together and committed separately so either can be reverted alone.

---

## Part 1 — #54: a line added after the stamp has no anchor

### What is actually broken

#49 stores the quote as the agent first found it and bounds every later round against it. The store is written once and never extended:

- `ServiceQuoteHandler::claimAttempt()` stamps it on the first pass, via `QuoteBaseline::stampIfAbsent()`, riding in the attempt-counter write.
- `OfferApplier::write()` stamps it too, for the same quote, if the handler somehow did not — `QuoteBaseline::read($reference) === null ? stamp($reference) : null`.

Both sites ask one question: *is there a baseline?* Neither asks *does it cover the lines that are on the quote now?* So a line added after the stamp — a human adds a product after a structural-ask escalation and negotiation resumes — is never anchored, and the two sides of the pass disagree about it:

| Side | Reads | A line added after the stamp |
|---|---|---|
| Authorize — `LinePriceOfferCheck` via `QuoteBaselineLines::anchor()` → `linesMergedWith()` | baseline lines, plus every unknown **current** line at its **current** price | bounded against whatever its price is *this round* |
| Verify — `LineOfferVerifier` via `QuoteBaselineLines::asReferenceSnapshot()` | the **stored** lines only | not bounded at all — `LineOfferVerifier` skips lines absent from the reference |
| Verify — `DiscountTotalViolation` | the stored `totalNet` | the added line inflates the final total, so the measured discount reads **lower** than it is |

`linesMergedWith()`'s docblock is right about the round the line appears — "its current price IS its baseline" — and wrong about every round after, because the merge is computed in memory and thrown away. Round N discounts the line; round N+1 re-derives its "baseline" from the discounted price; the per-line compounding #49 closed is open again for exactly those lines. The verify side cannot catch it, because it has no reference row to compare against, and the totals check is pulled the wrong way by the added line's own value.

### The fork, and why extend wins

#54 offers two directions.

**Escalate** — refuse any concession on a line absent from the baseline until a human re-baselines. This is pre-#49 behaviour and it is strictly safe. It is also a trap: nothing in this plugin re-baselines a quote, so "until a human re-baselines" means *forever*. One added product would convert a live negotiation into a permanently human quote, and every subsequent round of it, for a buyer action that is completely ordinary. #49's whole argument was that escalating the normal path is expensive; adding a second permanent escalation door immediately after closing the first is undoing it in a different place.

**Extend** — stamp the unknown line into the stored baseline at the price it has *right now*, in the write the pass is already making. Chosen. Three reasons:

1. It is not a new judgement, it is the existing one made durable. `linesMergedWith()` already decides "a line with no agent concession behind it has its current price as its baseline". That decision is correct; it is only wrong because it is recomputed from a moved price. Persisting it is the smallest change that makes it hold.
2. It costs no write. Both stamping sites already issue an `updateQuote` with a `customFields` array on the pass in question — the handler's attempt counter, the applier's price write. The extension is another key in a fragment that is already going out. #49's "no extra write and no extra revision bump" survives intact.
3. It makes the two sides agree by construction rather than by two parallel rules, which is what let them drift apart in the first place.

The cost the issue names — "writes to the quote on a path that previously did not" — does not materialise: `claimAttempt()` writes `ATTEMPTS_KEY` unconditionally on every pass, and `OfferApplier::write()` writes the expiry unconditionally. Neither gains a write it was not already making; both gain a key.

**The extension is one-way and never revalues.** A line the baseline already knows keeps its stored price forever, exactly as today. Extension only ever appends a row the baseline has no entry for. The anchor still cannot move.

### Which lines are extended, and which are not

**Not negative-priced lines.** Shopware generates its own line items for a quote-wide percentage discount, and they come back with a negative `unitPriceNet` — `LineNetViolation` already skips them for exactly that reason. Stamping one into the baseline would be the compounding bug in its purest form: round one's own discount line would be absorbed into the baseline total, and round two would be measured against a total that already contains round one's concession. Extension takes `unitPriceNet >= 0` only. Negative lines keep today's behaviour: merged in memory by `linesMergedWith()`, skipped by the line check, covered by the totals check.

### `totalNet` moves with the lines, and the net factor does not

`NetFactor::of()` divides a snapshot's `totalNet` by the sum of its `unitPriceNet × quantity` to normalise gross-vs-net price space. #49 stores quantities precisely so that ratio stays meaningful. Appending a line to the stored lines without touching the stored total would break it: the denominator grows, the numerator does not, every reference price is scaled down, and the verifier starts reporting lines "priced above their reference" that are nothing of the sort — while the totals check reads the added line's value as if the agent had conceded it.

So the extension carries the total too, in the one form that leaves the ratio exactly where it was:

```
f            = storedTotalNet / Σ(stored unitPriceNet × quantity)
extendedTotal = storedTotalNet + f × Σ(added unitPriceNet × quantity)
```

`NetFactor` of the extended baseline is `(f·S + f·v) / (S + v) = f` — unchanged, by construction. The added line enters the baseline total at its own first-seen value, expressed in the baseline's price space. When the stored line sum is zero or negative the factor is not defined and the raw value is added unscaled, matching `NetFactor`'s own `1.0` fallback.

Deliberately *not* used: the added line's own `totalNet` field. Line totals are read in the cart's display space, which is gross on a gross-calculated cart, while the quote total is always net — that mismatch is the entire reason `NetFactor` exists, and adding one to the other would reintroduce it.

### Components

**`QuoteBaselineLines::extendedWith(list<PolicyLine> $live): self`** — the baseline plus every live line it does not know and whose price is not negative, with `totalNet` scaled as above. Returns `$this` unchanged when there is nothing to add, so callers can compare by identity.

**`QuoteBaselineLines::anchor()`** already merges unknown lines in memory; it now anchors on `extendedWith()` first, so the in-memory anchor and the stored baseline compute the same numbers. This matters because `ServiceQuoteHandler` hands the pipeline the *pass-start* snapshot, whose custom fields predate the extension the handler just wrote — the proposer would otherwise see an un-extended baseline while the applier, which re-reads, sees an extended one.

**`QuoteBaselineLines::asReferenceSnapshot()` is deleted; `OfferApplier` calls `anchor()`.** With merged lines the two methods become character-for-character the same snapshot, and keeping two was what let the verify side fall behind the authorize side. One method, one reference, one place to be wrong. The verifier gains the live identity for its violation messages — `anchor()` maps a matched baseline row through `asOriginalOf()` — so a violation names the line's label instead of a raw UUID.

**`QuoteBaseline::stampIfAbsent()` becomes `stampOrExtend()`** — `stamp()` when there is no baseline, the extended fragment when the baseline is missing lines, `[]` when it already knows them all. Both call sites route through it: `ServiceQuoteHandler::claimAttempt()` spreads it as today, and `OfferApplier::write()` takes `?: null` to keep its existing null-vs-array shape. One function, both callers, so the two cannot drift.

**`BaselineRow`** gains a writer from a policy line, because the extended fragment re-serialises rows the baseline already holds (policy lines) alongside rows taken from the live snapshot. Both directions go through one field-name list.

### Where extension happens in the pass

```
claimAttempt()      read snapshot → stampOrExtend() rides the attempt write   ← the added line is stamped here, at its pre-round price
pipeline            proposer anchors via anchor() → extendedWith() in memory  ← same numbers, from a snapshot whose custom fields are stale
OfferApplier        re-reads → baseline already extended → anchor() → verify  ← authorize and verify now read one reference
next pass           the added line's first-seen price is stored              ← the compounding is closed
```

### Known limitations, unchanged and one narrowed

#49's documented limitation — a quantity reduction or line removal shrinks the final total structurally and `DiscountTotalViolation` reads that as a concession, escalating a legal offer — is untouched here. Extension addresses only lines the baseline does not know; it does not rescale for quantities that changed on lines it does.

The mirror of it *is* narrowed: a line **added** mid-negotiation used to pull the measured discount down and mask a real concession. It no longer does, because the baseline total now grows with the line.

---

## Part 2 — #56: the offer has no floor, and the constraints that look like one do nothing

Two independent facts, fixed together because either alone leaves the other misleading.

### The missing lower bound

`PriceOfferCheck` rejects a `discountPercent` above `maxDiscountPercent` and nothing else, and `DiscountTotalViolation` mirrors that one-sidedness. A negative discount — a *surcharge* — passes both, is written as a SwagCommercial percentage discount, and `ReplyTemplate::reduction()` floors the reported figure at `0.0`, so the buyer is told the quote came down by 0% while the total went up.

Both gain the lower bound, with the tolerance each already uses (`Epsilon::RATE` for the rate check, `Epsilon::MONEY` for the money one).

The verifier mirror deserves its own note, because it has a false-positive mode. A total that ends up *higher* than the reference is not always a surcharge: a buyer who raises a quantity mid-negotiation raises the final total against a baseline that did not move. After the mirror, that escalates. That is the symmetric twin of #49's documented quantity-*reduction* limitation, which already escalates a legal offer for a structural change in the other direction, and it fails the same safe way — a human gets it, and nothing is under-charged. Consistency with the existing limitation is why it is mirrored rather than left to the authorize side alone.

### The decorative constraints

`ValidatorInterface::validate()` runs in exactly one place in the plugin, `QuoteAgentSettingsFactory`, on the merchant's `NegotiationPolicy`. `Assert\Valid` extends that run to `QuoteLimits` and on to `QuoteValueCeiling`. **Those three classes are the whole of the validated tree.**

Every other `Assert` attribute under `Policy\Data` — on `ProposedOffer`, `OfferedPrice`, `QuoteSnapshot`, `QuoteLineSnapshot`, `PriceAsk`, `DeliveryAsk`, `PaymentAsk`, `InterpretedLineChange`, `InterpretedProductAddition` — is never evaluated by anything.

**Decision: delete them, and pin that the remaining ones are real.** The alternative the issue offers — run the validator over `ProposedOffer` in `OfferAuthorizer` — was rejected:

- `OfferAuthorizer` is a dependency-free bounds check whose collaborators are constructor defaults. Giving it a `ValidatorInterface` means container wiring for a class that currently needs none.
- It would create a second, parallel violation channel over the same values the explicit checks already cover, with generic messages ("This value should be between 0 and 100") next to purpose-written ones ("discount 22% exceeds the 15% limit") — both flowing into the same escalation text a merchant reads.
- The bound that actually matters is the one being added to `PriceOfferCheck` in the same commit. A generic `Range(min: 0)` would not have caught anything the explicit check now catches, and would still not cover the totals side.

A constraint that looks like enforcement and is not is worse than no constraint, so the honest move is deletion. `QuoteLineSnapshot::$unitPriceNet` keeps its comment about Shopware's negative lines — that is a domain fact worth stating — reworded so it no longer reads as a note about a sibling attribute.

**The pin:** `ValidatedConstraintsTest`, in the spirit of `RecordFieldGuardsTest`, walks every class under `src/` by reflection and asserts that Symfony constraint attributes appear only on the three classes the validator actually reaches. It fails when someone adds a decorative constraint, and it fails when someone removes a class from the validated tree while leaving its constraints behind.

`AGENTS.md`'s "Shared contracts" bullet currently says boundary data is validated "with constraint attributes on the `Policy\Data` DTOs", which is what made the decoration look deliberate. It is corrected to name the validated tree.

---

## Questions I would have asked

No human was available for this work, so these were assumed rather than settled. Each is recorded with the assumption taken and what would change if it is wrong.

1. **Should a *removed* line be pruned from the baseline?** Assumed no — unchanged from #49, which calls a stale entry "never looked up" and pruning "a second write for no reader". Nothing here makes that cheaper.
2. **Should the extension also rescale the baseline total when a known line's *quantity* changes?** Assumed no. It is #49's documented limitation, it touches the arithmetic that branch spent five tasks getting right, and #54 is about lines the baseline does not know. If it is wanted, it is its own issue.
3. **Should the totals lower bound be dropped to avoid escalating a quantity increase?** Assumed no — mirrored, per the argument above. If the false positives prove noisy in the test shop, the authorize-side bound alone is the smaller half of the fix and the verifier mirror can be reverted on its own line.
4. **Delete the decorative constraints, or wire the validator?** Assumed delete, argued above. Wiring the validator is the reversible direction if a boundary later genuinely needs schema-shaped validation.

## Testing

**Unit — `QuoteBaselineLines::extendedWith`:** an unknown line is appended at its current price; a known line is untouched even when the live price has moved; a negative-priced line is not appended; the extended baseline's `NetFactor` equals the stored baseline's.

**Unit — `QuoteBaseline::stampOrExtend`:** no baseline yields a full stamp; a baseline missing a line yields a fragment containing both the stored row at its stored price and the new row; a baseline that knows every line yields `[]`.

**Unit — the test #54 asks for, two rounds with a line added between them:** round one stamps the baseline; a line is added; round two stamps that line at its first-seen price; round three's reference bounds it against that price, not against the price round two left it at. This fails on `main` and is the test that would have caught the defect.

**Unit — authorize and verify read one reference:** the snapshot `OfferApplier` hands the verifier and the snapshot the proposer anchors on contain the same lines for a quote with a late-added line.

**Unit — `PriceOfferCheck` / `DiscountTotalViolation`:** a negative discount is a violation; zero is not; a final total above the reference total is a violation.

**Unit — `ValidatedConstraintsTest`:** the three validated classes carry constraints; nothing else does.

**Integration:** a per-line chain with a line inserted mid-chain ends no lower than `first-seen × (1 − maxDiscountPercent/100)` on that line. Requires the test shop.

## Done when

- A line added after the baseline was stamped is anchored at the price it had when it appeared, on every later round.
- The authorize side and the verify side read the same reference snapshot, because there is only one method producing it.
- A Shopware-generated negative line is never written into the baseline.
- The baseline's net factor is identical before and after an extension.
- A negative `discountPercent` is refused by the authorizer, and a total that rose is refused by the verifier.
- No `Assert` attribute exists on a class the validator does not reach, and a test says so.
- `composer run test` and `composer run quality` are green.

## Not here

Rescaling the baseline for quantity changes on known lines (#49's limitation, still open). Any re-baselining mechanism for a human. Exposing the baseline in the admin UI. Validating model output with Symfony Validator — `ModelAnswerSerializer` and Valinor own that boundary and this changes nothing about it.
