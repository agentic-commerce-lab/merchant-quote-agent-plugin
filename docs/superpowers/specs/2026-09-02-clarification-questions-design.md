# Surfacing buyer clarification questions

**Status:** proposed design, 2026-09-02. Implements issue #65.

**The problem in one sentence:** when the model correctly asks the buyer to clarify an ambiguous ask instead of guessing, the question is thrown away and the buyer gets a generic auto-reply that grants nothing.

**In scope:** routing `clarificationQuestions` to the buyer once per stuck point, escalating to a human if the ambiguity survives the buyer's answer, and the round accounting that keeps a clarification from being mistaken for a negotiation round.

**Out of scope, with reasons:**

| Left out | Why |
| --- | --- |
| Rewording the questions through the LLM | The extract prompt already promises they are "sent to the buyer as-is". Rewording costs a model call and lets the model paraphrase its own question into a different one. |
| A `Band::Clarify` or new `QuoteDecision` type | `QuoteDecider` is about money. A new band arm would force every `match` over bands to grow, for a concept that never reaches the money policy. |
| Routing through `HumanReviewEscalation` (issue #65's option 2) | It can only escalate, and `QuoteEscalator::escalate()` posts a fixed constant by design, so it structurally cannot ask the buyer anything. Ask-once-then-escalate needs the reply path regardless. |
| Answering the clarification with a rules-only guess | The whole point of the model asking is that guessing is wrong here. |
| A per-quote limit above one | One ask per stuck point, cleared when negotiation resumes, is the behaviour asked for. A counter is speculative until someone reports a loop. |

## Why

`config/agents/quote-extract-agent.prompt.md:35-37` tells the model that `clarificationQuestions` "are sent to the buyer as-is". `ExtractResponse::toInterpretation()` captures the field into `CommentInterpretation::$clarificationQuestions`, and a unit test pins the round trip. **Nothing in `src/` reads it** — verified by grep: every other occurrence is the DTO's own construction and serialisation, plus test fixtures.

The consequence is not merely a dropped field. With no price, structural or non-price ask set, the pipeline reaches `NegotiationDecider` with an empty ask, lands in the grant band at roughly 0%, and sends a generic offer reply. That reply advances `hasNewBuyerAsk()`, so the ambiguous ask is never resurfaced. The buyer asked something reasonable, the model behaved correctly, and the shop answered with a no-op.

## What we verified before designing

Read out of this repository, not assumed:

- **The pipeline already has this exact shape of guard, twice.** `NegotiationPipeline` runs `interpret()` and then two gates — `$ask->isStructural()` and `$ask->hasNonPriceAsk()` — each logging a reason and escalating **before the negotiate and reply calls are paid for**. Both carry a comment explaining that silently dropping the buyer's real ask is the worse outcome. A clarification gate is the third of these.
- **Escalation cannot carry text.** `QuoteEscalator::escalate()` posts the constant `'A member of our team will review this quote personally and get back to you.'` and takes no caller string. Its docblock records why: a caller-supplied detail parameter was the leak that printed internal field names and constraint values to buyers. Escalating an ambiguity therefore tells the buyer nothing about what was ambiguous.
- **The reply path does post model text.** `ReplyComposer::reply()` rewords through the LLM and comments the result, so buyer-facing model text is established ground. The constant-only rule is specific to escalation.
- **There is no round counter to consume.** #49's cap is discount drift measured against a persisted baseline, not a count of passes. "Not a negotiation round" therefore means not touching the offer machinery — no proposal, no baseline write, no negotiate call — rather than decrementing anything.
- **There is a marker precedent.** `QuoteEscalator` owns a `customFields` key and exposes `releaseFor(NegotiationOutcome): array<string, null>`, which `ServiceQuoteHandler` spreads into the pass's stamp. Only a pass that `answeredTheBuyer()` clears it.
- **`OfferRound` is at its budget.** Its own docblock says the constructor and class sit at the complexity and constructor-size limits deliberately. New behaviour goes beside it, not inside it.
- **`NegotiationPipeline` is at the parameter limit.** It has exactly five constructor parameters, and `mago.toml:25` sets `excessive-parameter-list` to `error` at threshold 5. Nothing new may be injected into it, which is why the new collaborator is stateless and takes its arguments per call.
- **Comments cannot re-trigger servicing.** `QuoteEscalator`'s docblock records that a comment written through the gateway carries `AgentContext::STATE`, so the clarification comment cannot start another pass. It must go through the gateway for that reason, not merely for convenience.

## Components

### 1. The predicate — `InterpretedAsk::needsClarification()`

```php
public function needsClarification(): bool
{
    return $this->interpretation->clarificationQuestions !== [];
}
```

Joins `isStructural()` and `hasNonPriceAsk()` on the same object, so the pipeline reads uniformly.

### 2. The gate — a third guard in `NegotiationPipeline`

Placed **after** the structural and non-price guards and **before** the decider. Order is load-bearing: an ask that is both structural and ambiguous escalates as structural, because changing what is being sold is outside the mandate whether or not it is clear.

```php
if ($ask->needsClarification()) {
    return ClarificationRound::handle($gateway, $snapshot, $ask, $this->round, $this->logger);
}
```

One branch in the pipeline, exactly like the two guards above it. Which of the two things happens — ask, or escalate because we already asked — is `ClarificationRound`'s decision, not the pipeline's, because the marker is what settles it and `ClarificationRound` owns the marker.

Both branches return before `OfferRound::play()`, so an ambiguous pass pays for the extract call only and skips the two model calls that class exists to spend — the same economy the two guards above it were written for.

### 3. The ask — `ClarificationRound`

Its own small collaborator rather than a method on `OfferRound`, which is at its stated budget — and **stateless, with no constructor**, because `NegotiationPipeline` already has exactly five constructor parameters and `mago.toml` sets `excessive-parameter-list` to `error` at threshold 5. A sixth injected collaborator would fail the gate, and this repo restructures rather than suppresses.

It therefore owns **both** branches of the decision, taking what it needs as arguments:

```php
public static function handle(
    QuoteGatewayInterface $gateway,
    QuoteSnapshot $snapshot,
    InterpretedAsk $ask,
    OfferRound $round,
    LoggerInterface $logger,
): NegotiationPass
```

`$round` is the collaborator the pipeline already injects, so the escalating branch reuses `OfferRound::escalated()` without the pipeline learning a new dependency. This keeps the pipeline's third guard a single `if` returning a pass, uniform with the two above it, and keeps the ask-versus-escalate decision in the class that owns the marker.

On the asking branch it posts the questions as a comment **verbatim**, writes the marker, and returns a `NegotiationPass`. No proposal, no offer, no baseline write, no reply prompt — so `NegotiationPass::$negotiateHash` and `$replyHash` are both null and `$extractHash` carries the prompt that produced the questions, which is exactly what #19's audit trail needs to attribute the ask.

Formatting: one question per line, in the order the model returned them, with no preamble the model did not write. Nothing is appended, so nothing can leak past what the extract prompt produced.

### 4. The outcome — `NegotiationOutcome::Clarified`

`answeredTheBuyer()` returns **false** for it. Two consequences, both wanted:

- A quote that was already escalated keeps its escalation marker, because a clarification is not an answer.
- The clarification marker is not cleared by the pass that wrote it.

### 5. The marker — `ClarificationMarker`

A `customFields` key mirroring `QuoteEscalator`'s shape, with `alreadyAsked(QuoteSnapshot): bool` and `releaseFor(NegotiationOutcome): array<string, null>`. `ServiceQuoteHandler` spreads its release into the same stamp that already spreads `QuoteEscalator::releaseFor($outcome)`.

**It clears when a pass answers with an offer** (`Offered` or `Countered`). So "ask once" means once per stuck point: the buyer answers, negotiation resumes, and a genuinely new ambiguity months later is asked about rather than escalated in silence. The alternative — never clearing — would make the first ambiguity in a quote's life permanently consume its one question.

## Data flow

```
buyer comment
  → interpret()  [one model call]
      ├─ structural?     → escalate (existing)
      ├─ non-price ask?  → escalate (existing)
      ├─ needs clarification?
      │     ├─ marker set → escalate, NeedsHumanReview
      │     └─ else       → comment the questions verbatim, set marker, Clarified
      └─ → decider → OfferRound::play()  [two model calls]
                        → Offered/Countered clears both markers
```

## Error handling

The ask is two gateway writes: a comment and a `customFields` update. If the comment succeeds and the marker write fails, the next pass asks again — the buyer sees the question twice, which is mildly annoying and strictly better than escalating an ambiguity nobody has voiced. The reverse order would risk marking a quote as asked without asking, which silently converts the next ambiguity into an escalation. **Comment first, then mark.**

The pipeline's existing rule stands: every failure escalates, with no fall back to rules-only.

## Testing

- **Unit — `InterpretedAsk::needsClarification()`:** true for a non-empty list, false for an empty one, and false for an interpretation whose other fields are populated.
- **Unit — the pipeline gate:** an ambiguous ask with no marker asks and does not reach the decider; the same ask with the marker escalates with `NeedsHumanReview`; an ask that is both structural and ambiguous escalates as structural, pinning the guard order; an unambiguous ask is unaffected.
- **Unit — `ClarificationRound`:** posts every question verbatim in order, appends nothing, writes the marker after the comment, and returns `Clarified` with `extractHash` set and the other two hashes null.
- **Unit — the marker:** `releaseFor()` clears on `Offered` and `Countered` and does not clear on `Clarified`, `Escalated` or `NothingToDo`.
- **Unit — `answeredTheBuyer()`:** false for `Clarified`, which is what keeps an escalated quote's marker intact.
- **Regression:** the existing suite must stay green at **403** unit tests.

There is no integration test for this. The pipeline's collaborators are all injected and the behaviour is fully determined by the interpretation and the snapshot's custom fields, so a unit test proves it; an integration test would only re-prove the container wiring that already has coverage.

## Risks

| Risk | Mitigation |
| --- | --- |
| **Model-generated text reaches the buyer.** The escalation path is constant-only precisely because caller-supplied detail once leaked internal field names and constraint values. | The reply path already posts model text, so this is not new ground. Nothing is appended to what the extract prompt produced, and the questions are posted verbatim rather than composed, so there is no template into which internal state could be interpolated. Called out for review attention rather than designed around. |
| **A clarification that is never answered leaves the quote quiet.** The marker is set, the buyer never replies, and no pass runs because there is no new buyer ask. | This is the same shape as any unanswered agent reply and is governed by whatever chases stale quotes, not by this feature. Recorded, not fixed here. |
| **`Clarified` is a fourth outcome** that every `match` over `NegotiationOutcome` must handle. | There are two such sites (`answeredTheBuyer()` and the handler's stamp), both changed here, and PHP's `match` on an enum fails loudly on an unhandled case rather than silently. |
