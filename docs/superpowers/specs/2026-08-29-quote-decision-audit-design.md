# Quote decision audit trail — design

**Issue:** [#19](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/19) (2.4 Audit trail)
**Date:** 2026-08-29
**Depends on:** #18 (the negotiation pipeline, merged in #30), #5 (config), #3 (bridge)
**Consumers:** #21 (the 10,000-negotiation run reads its results off this), #7 (admin page), #20 (signatures), #29 (dry-run destination), #22 (replay)

## Why this exists

The PM's framing on 2026-08-27: **observability is the core value of the plugin.** Merchants will not raise the ceiling on an agent they cannot see, and the log is the raw material for improving strategies.

The immediate driver is #21. The internal test round is due 2026-09-04 and its entire readout — outcome counts and shares, average granted discount against the band, p50/p95 servicing latency, failure count by cause — is read off this table. Records cannot be retrofitted onto a run that already happened, which is what puts this ahead of the other open V1 issues.

## Scope

Four scoping decisions were taken before design, and they bound everything below.

1. **Full record shape, gaps null.** The complete schema and migration ship now. Fields the pipeline cannot yet surface become nullable columns rather than a later migration.
2. **No terminal-outcome subscriber.** Columns reserved and unwritten; the subscriber is a follow-up.
3. **No export command.** The JSONL export and HMAC pseudonymisation are a follow-up.
4. **A full DAL entity**, not a plain table, so #7 gets the admin API and DAL aggregation for free.

**Consequence, stated up front: #19 will not close on this work.** Two of its three Done-when clauses ship here; the export's does not.

## What the pipeline currently throws away

The problem this design solves is not "where to put the data" but "how to get it out". Roughly two-thirds of what #19 asks for is discarded by stages whose return shapes were deliberately kept narrow:

| #19 field | In hand today |
|---|---|
| quote id, sales channel, revision | yes — on the snapshot |
| prompt version hashes (×3) | yes — on `NegotiationPass` |
| model, base-URL host | yes — derivable from settings |
| final action | yes — `NegotiationOutcome` |
| interpreted asks | no — `InterpretedAsk` dies inside `negotiate()` |
| band, escalation reasons, value-ceiling | no — `NegotiationDecision` discarded |
| tokens, latency | no — `ChatCompletionClient` returns only the content string |
| raw proposal, authorization result | no — discarded |
| verification result, writes performed | no — `AppliedOffer` discarded |
| the buyer comment verbatim | no — `ReplyComposer` returns only a hash |
| trigger event, timings | no — never enters the pipeline |

Those stages sit under the gates that already forced `OfferRound` out of `NegotiationPipeline`: class-scoped cyclomatic complexity 10, `excessive-parameter-list` 5, `too-many-methods` 11, 400 physical lines per file. `NegotiationPipeline::__construct` and `OfferRound::play` are both **at** five parameters.

## Approach: a per-pass recorder

Rejected alternatives, briefly. **Growing the return values** matches the codebase's `final readonly` style but loses everything earlier stages produced whenever a stage throws — and `ModelUnavailable` is a normal outcome here — so recording failure paths would force catch-and-reconstruct at every level, and tokens still need the client's return type widened. **Recording at the pipeline boundary only** can write nothing beyond what is already on `NegotiationPass`.

The chosen approach is a **mutable `DecisionRecorder`, injected as a constructor dependency** into each stage that knows something worth recording. Nothing's method signature changes, so the parameter cap is sidestepped rather than fought; the record is assembled incrementally, so a pass that dies halfway still has everything up to that point; and `ChatCompletionClient` can record tokens and latency from inside `send()` without changing the return type three call sites depend on.

Its cost is mutable shared state in a codebase that is `final readonly` almost everywhere. The discipline that contains it is specified below and gets its own test.

## The record

New namespace `MerchantQuoteAgentPlugin\Audit`.

`QuoteDecisionRecord`, an attribute entity — `#[Entity('merchant_quote_agent_decision')]`, `extends Shopware\Core\Framework\DataAbstractionLayer\Entity`, public typed properties with `#[Field(..., api: true)]`. Shopware 6.7 supports these (`vendor/shopware/core/Framework/DataAbstractionLayer/Attribute/Entity.php`; real usage at `Content/MeasurementSystem/DataAbstractionLayer/MeasurementSystemEntity.php`). This matters beyond taste: the classic Definition + Entity + Collection with protected properties and getter/setter pairs would be ~50 methods and blow both the 11-method and 400-line gates. One class with public properties has none.

`services.php` already calls `$services->defaults()->autowire()->autoconfigure()`, and `AutoconfigureCompilerPass` registers `Attribute\Entity` for the `shopware.entity` tag, so registration needs no explicit wiring. There is no schema generation for attribute entities — the migration is hand-written.

### Columns

Split by whether the field must be **aggregated** (scalar) or merely read (JSON/text).

Scalar — #21 aggregates, #7 filters:

`quoteId`, `quoteNumber`, `salesChannelId`, `currencyIso`, `trigger`, `attempt`, `revisionVersionId`, `revisionUpdatedAt`, `band`, `outcome`, `escalationReason`, `discountPercentGranted`, `maxDiscountPercent`, `totalNetBefore`, `totalNetAfter`, `model`, `modelHost`, `extractPromptHash`, `negotiatePromptHash`, `replyPromptHash`, `promptTokens`, `completionTokens`, `modelLatencyMs`, `durationMs`, `authorized`, `verified`, `errorClass`, `terminalState`, `terminalAt`, `createdAt`.

JSON / text — detail:

`interpretedAsks` (the whole `CommentInterpretation`), `rawProposal` (the model's answer as received — `OfferProposer` currently passes `complete()` straight into `NegotiateResponse::read()`, so it must capture the string into a variable first), `violations` (the verifier's list), `writes` (what was written to the quote), `errorChain` (class, message, `getPrevious()` walk), `buyerComment` (LONGTEXT, verbatim).

Indexes on `quote_id`, `created_at`, `outcome`, `sales_channel_id`.

Every #21 readout is then a DAL aggregation over scalars with no post-processing: outcome counts group by `outcome`; average granted discount against the band is `discountPercentGranted` over `maxDiscountPercent`; p50/p95 servicing latency is `durationMs`; failure count by cause is `errorClass`.

Two notes on specific columns. `durationMs` is the pass's own wall-clock and is deliberately distinct from `modelLatencyMs` — #21 asks for servicing latency, and the model calls are only part of it. `attempt` comes from the existing crash-budget counter and is what lets a reader tell one retried quote from four separate ones; without it the run's counts are inflated by every redelivery.

`terminalState` and `terminalAt` are reserved and never written by this work. They exist so the follow-up subscriber is a subscriber and nothing else.

### The property-count gate

Measured, not assumed: `too-many-properties` is a mago default (absent from `mago.toml`, like `too-many-methods`) and **fires above 10 properties**. A 37-property entity is therefore impossible by default — and the rule's own suggested fix, grouping related properties into an object, is exactly what a DAL entity cannot do, because its properties *are* the table's columns.

`QuoteDecisionRecord` and `DecisionDraft` each carry one documented `@mago-expect lint:too-many-properties`, verified to work. The suppression is confined to the two classes that are row shapes; no behavioural class gets one. Pushing columns into JSON to duck the rule would trade away exactly the queryability the record exists for — DAL cannot aggregate inside a JSON column.

### Migration

`MerchantQuoteAgentPlugin\Migration\Migration1787998662CreateQuoteAgentDecision extends MigrationStep`. `update()` creates the table; `updateDestructive()` is empty. The plugin's first migration.

## The recorder

Five new classes, one new value object:

| Class | Role |
|---|---|
| `Audit\QuoteDecisionRecord` | the attribute entity |
| `Audit\DecisionDraft` | mutable accumulator; public properties, no behaviour |
| `Audit\DecisionRecorder` | what the stages talk to; owns the draft |
| `Audit\DecisionRecordWriter` | the only class in `Audit` that touches Shopware |
| `Migration\Migration1787998662CreateQuoteAgentDecision` | the table |
| `Servicing\Data\PassContext` | trigger reason + attempt number |

`DecisionRecorder` and `DecisionDraft` are plain PHP with no Shopware dependency, so the stages stay as framework-free as they are today and the recorder is trivially assertable in unit tests.

### The recorder's API

A setter per field would reach fifteen-plus methods and trip `too-many-methods`. The recorder therefore takes **one method per collaborator, each accepting the value object that collaborator already produces** — eight in total:

```
begin(QuoteSnapshot $snapshot, PassContext $context): void
recordAsk(InterpretedAsk $ask): void
recordDecision(NegotiationDecision $decision): void
recordProposal(string $rawResponse, ProposedAnswer $answer): void
recordApplied(AppliedOffer $applied): void
recordReply(string $comment, ?string $promptHash): void
recordModelCall(int $tokens, int $latencyMs): void
finish(?NegotiationPass $pass): void
```

The gate got there first, but this is the better API regardless: no scalar-setter pile, and each stage hands over what it already has.

### Where the pass boundary lives

`NegotiationPipeline::service()`:

```php
$this->recorder->begin($snapshot, $context);
$pass = null;

try {
    // existing body, assigning $pass
} finally {
    $this->recorder->finish($pass);
}
```

`$pass` is initialised to null before the `try` and `finish()` accepts null: when an exception unwinds past the assignment there is no pass to report, and a `finally` referencing an unassigned variable would itself fatal. A null pass records the accumulated draft with `errorClass` set and no outcome.

Not the handler. The pipeline *is* the pass, and `ServiceQuoteHandler` is itself at five parameters. The `finally` covers the already-caught `ModelUnavailable`, a gateway or database failure thrown mid-pass, and anything else that unwinds. It does not cover a segfault — #3's variant-product crash — but no database write would survive that either, and the crash-budget counter is what bounds that case.

### Recording points

All constructor injection. No method signature grows except `service()`.

| Class | ctor params | Records |
|---|---|---|
| `ChatCompletionClient` | 2 → 3 | tokens, per-call latency (accumulated across the pass), model, base-URL host |
| `AskInterpreter` | 2 → 3 | interpreted asks, extract hash |
| `OfferProposer` | 3 → 4 | raw proposal as received, negotiate hash, authorization result (it already holds `OfferAuthorizer`) |
| `OfferApplier` | 2 → 3 | writes performed, verification result, violations, totals after |
| `ReplyComposer` | 3 → 4 | the buyer comment verbatim, reply hash |
| `NegotiationPipeline` | 5 → **5** | begin/finish, band, escalation reason, outcome |

The client row is the payoff for this approach: tokens and latency are recorded from inside `send()`, so the return type stays `string` and all three call sites are untouched. Under any other approach these stay null.

### How the pipeline stays at five parameters

It drops `QuoteEscalator`. Its private `escalate()` is a line-for-line duplicate of `OfferRound::escalated()` — both escalate and return a `NegotiationPass(Escalated, …)`. Routing the pipeline's three escalations through `OfferRound::escalated()` (made public) removes the duplication and frees the slot. A simplification this change pays for, not a workaround for the gate.

### PassContext

`QuoteServicingPipelineInterface::service()` gains a fourth parameter, `PassContext`, carrying what only the message knows: `$message->reason` and the attempt number. `ServiceQuoteHandler` has both. A bare pair of scalars would leave `service()` at five; a value object leaves room.

### Mutable-state discipline

This is the approach's one real risk, so the rules are explicit:

- `begin()` **resets the draft unconditionally.** A leaked draft from a previous pass cannot survive into the next one.
- `finish()` clears the draft after handing it to the writer, so a second `finish()` without a `begin()` writes nothing.
- The recorder is stateful but never shared across passes: Messenger processes one message at a time per worker, and the servicing lock is held for the pass's duration.
- The integration tests call `pipeline->service()` directly. Because `begin`/`finish` live in the pipeline, those tests record too — no null-recorder path is needed anywhere.

## Failure paths

**Ten paths through #18, one record each:** nothing-to-do; structural ask; non-price ask; band-escalate; no offer proposed; per-line round two; verification failed; offered/countered; `ModelUnavailable`; and anything else thrown. The first nine return through `service()`; the tenth unwinds through the `finally` with `errorClass` and `errorChain` populated.

**Four paths deliberately write nothing**, because they never reach #18: preflight refusing (kill switch, misconfigured channel, terminal state), an unchanged fingerprint, an unavailable gateway, and a lock that could not be acquired. Those belong to #4 and #5. Keeping the pipeline the sole owner of the record's lifecycle is what makes `begin`/`finish` a single-owner invariant instead of something three classes can each half-start.

Known consequence: **a merchant looking at the audit trail for a quote the agent escalated as misconfigured will see nothing at all.** `ServicingPreflight` escalates that quote — a visible agent action with no record behind it. Deliberately out of scope; filed as a follow-up against #7, which is what makes the absence visible.

**The audit write must never fail the pass.** `finish()` catches everything the writer throws, logs at error level, and swallows. Letting it propagate would roll the message back into Messenger's retry and re-answer the buyer — precisely the double-message failure #18 spends the `hasNewBuyerAsk` guard and the stranded-reply recovery avoiding. The trade is stated plainly: **a missing record beats a duplicated buyer message.**

The existing end-of-pass structured log event stays exactly as it is — it is the backstop when a write fails, and #22 already reads it.

**A retried pass writes a second record, on purpose.** Each attempt really happened, and `attempt` is what lets a reader collapse them. #21 counts distinct quotes, not rows.

## Security

The entity is `api: true` for the **admin API only** and must never be exposed to the Store API. It holds the raw model proposal, the band, and the merchant's authority limits; a buyer who could read it would know exactly how far the agent may move before asking a human.

`buyerComment` is verbatim customer text and `rawProposal` may echo it. Both stay in the merchant's own database, which is where #19 wants them. The export follow-up is what must gate them — anonymization on by default, comments excluded unless `--include-comments`.

## Testing

**Unit**, against a real recorder and a fake writer:

- `PipelineHarness` grows a recorder, so every existing pipeline test exercises the write path.
- One test per path: exactly one record with the right outcome, across all ten from above including the two thrown ones.
- `begin()` resets — two passes through one recorder, and pass one's ask must not appear in pass two's record. This is the approach's failure mode, so it gets a test rather than a comment.
- A writer that throws is swallowed and the pass still returns its outcome.
- `ChatCompletionClient` records tokens and latency without changing what it returns.
- **`DecisionDraft`'s properties are pinned against `QuoteDecisionRecord`'s `#[Field]` properties by reflection, in both directions**, excluding only `id` (writer-generated) and `terminalState`/`terminalAt` (reserved). Added after measuring the live shop: an unknown payload key does **not** fail the write — `WriteCommandExtractor::normalizeSingle()` skips unmapped keys and `extract()`/`map()` iterate the definition's fields, never the payload's. So drift between a draft property and its entity field produces no exception and no failing write, just a column quietly null in every audit row, in the table #21 reads its results from. The framework will not catch that; this test is what does.

**Integration, on the shop** — the parts that cannot be faked:

- The migration creates the table and the entity resolves as `merchant_quote_agent_decision.repository`. Attribute-entity registration is exactly the kind of thing that silently does not happen.
- A real pass over a real quote writes a real row, read back through the repository with its scalars populated.
- The aggregations #21 needs actually run against the schema: a count grouped by `outcome`, an average of `discountPercentGranted`. Cheap now; discovering during a 10,000-run that the column types do not aggregate is not.

## Deliverables

Create: `Audit/QuoteDecisionRecord`, `Audit/DecisionDraft`, `Audit/DecisionRecorder`, `Audit/DecisionRecordWriter`, `Migration/Migration1787998662CreateQuoteAgentDecision`, `Servicing/Data/PassContext`.

Modify: `ChatCompletionClient`, `AskInterpreter`, `OfferProposer`, `OfferApplier`, `ReplyComposer`, `NegotiationPipeline`, `OfferRound` (make `escalated()` public), `QuoteServicingPipelineInterface` and `ServiceQuoteHandler` (the `PassContext` parameter), `Resources/config/services.php`.

## Out of scope, with homes

| Deferred | Home |
|---|---|
| Terminal-outcome subscriber (columns reserved, unwritten) | follow-up issue |
| JSONL export + HMAC pseudonymisation — carries #19's third Done-when | follow-up issue |
| Records for preflight refusals | follow-up against #7 |
| The admin page over the trail | #7 |
| Message signatures in the trail | #20 |
| Retention and pruning | not planned; a prune command if a pilot asks |

## Global constraints

- PHP 8.3, Shopware 6.7, `declare(strict_types=1)` everywhere.
- Gates: cyclomatic complexity 10 **class-scoped**, `excessive-parameter-list` 5, `too-many-methods` 11, `excessive-nesting` 4, 400 physical lines per file. `composer run quality` must exit 0.
- SwagCommercial stays behind the bridge per ADR 0001; `Audit\DecisionRecordWriter` is the only class in the new namespace permitted to touch Shopware.
- Commits are signed. Never bypass signing.
- Nothing routed through `Servicing\QuoteEscalator` may carry internal text: that comment is customer-facing.
