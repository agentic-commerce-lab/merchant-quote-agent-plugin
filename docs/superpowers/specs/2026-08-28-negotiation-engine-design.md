# Negotiation engine: deterministic guardrails, LLM strategy

Issue #18. The pipeline that fills the seam #4 left and #5 fed: snapshot →
interpret → classify → propose → authorize → apply → verify → reply or
escalate.

Depends on #2 (the policy layer, which decides), #3 (the bridge, which reads
and writes), #4 (the servicing loop, which triggers and locks) and #5 (the
config layer, which supplies bands, credentials and the merchant's strategy).
All four are merged. Feeds #19 (audit trail), #6 (escalation surfacing), #21
(the stress run) and #29 (rollout controls), none of which can start until an
agent actually negotiates.

## Scope

**In:** a new `src/Negotiation/` namespace implementing
`QuoteServicingPipelineInterface`; the adapter between the bridge's and the
policy layer's snapshot models; three prompt-driven model calls, gated so an
out-of-authority ask never reaches a model; the deterministic counter-offer
band; apply-verify-reply with every step idempotent; and the escalation paths.

**Out:**

- **Negotiation quality.** Whether a 3% counter was a good move is #21's
  question and #22's; pinning it in a fixture would break on every prompt edit.
- **Persisting the decision record.** #19 owns the schema. This issue emits the
  data as one structured log event per pass and stops there.
- **Non-price term persistence.** `QuoteUpdate` carries discount, expiry and
  customFields — nothing for delivery, payment or bundle. #11 decides whether
  to add them or drop them from the mandate; until then a non-price *grant*
  escalates. See "What escalates".
- **Dry-run and customer scoping.** #29.

## Two amendments to #5, made here

Both are consequences this issue discovered, not new scope.

1. **Rules-only mode requires an API key.** #5 ships `rulesOnlyMode` as "decide
   deterministically without calling the model" and its factory treats the mode
   as needing no key. But a buyer's ask arrives only as free text: no
   bridge path populates `Policy\Data\QuoteSnapshot::$buyerTargetNet` — it is
   derived inside the policy layer from an interpretation, and `PriceAsk` /
   `StructuralAsks` are only ever built from the extract prompt's JSON. With no
   model at all the agent cannot know what was asked and can only escalate
   everything, which makes the toggle a noisier kill switch.

   Rules-only therefore means **the model reads, but never decides or writes**:
   extract runs, the band decides, a template replies. The key rule in
   `QuoteAgentSettingsFactory` changes from `!$rulesOnly && $apiKey === ''` to
   requiring a key whenever the agent is enabled, and the `config.xml` helpText
   and README change with it.

2. **`ModelAccess` gains a model name.** It carries `apiKey` and `baseUrl`, but
   `chat/completions` needs a `model`, and a merchant pointing at Azure or a
   self-hosted gateway must name theirs. Add `llmModel` to `config.xml` (text)
   and to `ModelAccess`. `config.xml` is declarative, so no migration.

   **The default value is unresolved.** Nothing in this repo — prompts, docs,
   scripts — names a model, so there is no established choice to inherit from
   the TypeScript path. Shipping a guess as a default would silently pick a
   price/quality point on the merchant's behalf. Options: no default and treat
   a blank as a misconfiguration, which is loud and consistent with #5's
   posture; or a named default agreed with the PM. Resolve before implementing.

## Architecture

`src/Negotiation/` is Shopware-free. It imports `Bridge\Data\*`,
`Bridge\QuoteGatewayInterface`, `Policy\*` and `Config\QuoteAgentSettings` and
nothing else — no `SystemConfigService`, no filesystem, no container. Layer
order becomes policy → bridge → negotiation → servicing.

`NegotiationPipeline implements QuoteServicingPipelineInterface` calls six
stages in order. Each is a final class with one public method returning a
readonly value object, so each is testable alone and the whole reads like the
issue's own pipeline sentence.

| Stage | Does | Model call |
| --- | --- | --- |
| `SnapshotAdapter` | `Bridge\QuoteSnapshot` → `Policy\QuoteSnapshot`; splits comments into buyer (authored) and agent (author-less) | — |
| `AskInterpreter` | buyer comments → `CommentInterpretation` | 1 (extract) |
| `BandClassifier` | `NegotiationDecider` + `PriceBandClassifier` → Grant / Counter / Escalate | — |
| `OfferProposer` | → `ProposedOffer`, then `OfferAuthorizer::authorize()` | 2 (negotiate) |
| `OfferApplier` | writes through the gateway, re-reads, `OfferVerifier::verify()` | — |
| `ReplyComposer` | buyer-facing message, comment, `Sent` transition | 3 (reply) |

**The gating is the point.** `BandClassifier` returning Escalate exits before
`OfferProposer`, so an out-of-authority ask costs one model call rather than
three, and the safety property holds even when the model is unreachable.
`AskInterpreter` is skipped entirely when no buyer comment is newer than the
agent's last reply — a re-trigger with nothing new costs nothing.

**The adapter is real work, not a cast.** Both layers define their own
`QuoteLineSnapshot` and `QuoteLineIdentity` in different namespaces. The
bridge's model carries identity, revision, totals, lifecycle and content; the
policy's carries `currencyIso`, `totalNet`, `lines`, `lifecycle` and
`buyerTargetNet`. Line-level target prices map onto `requestedUnitPrice`, so a
per-line ask entered in the storefront reaches the deciders without any comment
at all.

**A naming trap, kept straight throughout:** `NegotiationProposal` is the
*buyer's* interpreted ask and is input to `NegotiationDecider`; `ProposedOffer`
is the *agent's* offer and is input to `OfferAuthorizer`.

### The counter-offer band

`QuoteLimits::$counterOfferMaxPercent` has been carried and never read;
`PriceBandClassifier` documents `Band::Counter` as unreachable, a faithful port
of the TypeScript "no auto counter-offer". #5 now populates the field, so this
issue wires it — in `Policy\QuoteBandDecider`, not in the new namespace:

- ask ≤ `maxDiscountPercent` → grant, as today
- ask in `(maxDiscountPercent, counterOfferMaxPercent]` → price at
  `maxDiscountPercent` and set `counteredRequestPercent`, which makes
  `Band::Counter` reachable through the **unchanged** classifier
- ask > `counterOfferMaxPercent`, or the field unset → escalate, as today

Fixture-driven, in #2's style.

## Model client, prompts and versioning

**One client, three uses.** `ChatCompletionClient` wraps Guzzle — already a
direct dependency of `shopware/core` (`^7.5`), so this costs a `composer.json`
declaration and no install. One method: `complete(string $system, string $user,
bool $json): string`. `POST {baseUrl}/chat/completions`, bearer key,
`response_format: json_object` when asked. Timeout 30s; **one** retry after ~2s
on 429/5xx/timeout, then `ModelUnavailable`. Three calls at worst ≈ 3 minutes
against the lock's 300s TTL — there is no margin for a second retry.

**Prompts are baked at compile, not loaded at runtime.** `services.php` reads
the three files under `config/agents/` and passes the strings in. Nothing in
`Negotiation\` touches the filesystem, which is what keeps it Shopware-free and
path-free; prompts change with a deploy in any case.

**Composition.** Extract: verbatim. Negotiate: the base prompt plus a delimited
`## Merchant strategy` section carrying `strategyPrompt`, included only when
set. Reply: `{{tone}}` ← `replyTone`, a neutral instruction when blank.

The merchant's strategy tunes tone and posture and **cannot move a cap**: the
bands are the guardrail and `OfferAuthorizer` rejects anything outside
authority regardless of what the prompt says.

**Versioning.** `sha256` of each *composed* system prompt, computed per call.
Extract's is constant per deploy, negotiate's varies per sales channel, reply's
by tone. All three ride on the outcome event, which is what lets #22 later say
which prompt produced which result.

**Translation, not decoding.** The prompts speak snake_case; the policy DTOs'
`fromArray()` expects camelCase. `ExtractResponse::toInterpretation()` and
`NegotiateResponse::toProposedOffer()` do that mapping and catch the existing
`\TypeError`s. A response that will not parse escalates; so does a negotiate
response with `action: escalate`, carrying the model's own reason.

## Apply, verify and idempotency

For an authorized offer, `OfferApplier`:

1. `transition(Process)`, state-guarded (below).
2. Absolute per-line `updateLineItems()` from `lineUnitPricesNet` when the ask
   was per-line; otherwise a quote-level percentage `Discount`. `expiresAt =
   now + validityDays` in the same `updateQuote()`.
3. `recalculate()`.
4. Re-fetch, adapt, `OfferVerifier::verify(reference: pre-apply, final:
   post-apply, limits, now)`.

The **first** gateway write passes `$snapshot->revision` as its precondition, so
a buyer edit between read and write surfaces as `QuoteRevisionMismatch` and the
pass returns without writing; the handler retries and the next pass reads the
new state. Later writes pass none — our own first write has already moved
`updatedAt`.

**A verification failure escalates and leaves the changes in place.** Rolling
back is itself a fallible write with no transaction around it, and a failed
rollback leaves a third state nobody intended. We report what the database
says.

**Idempotency, step by step.** Prices, discount and expiry are absolute writes;
`recalculate` is idempotent. The two that are not:

- **Transitions** are attempted, not predicted. The shop's own machine allows
  `process` from `open` and `change_requested`, and `sent` from `in_review`
  **and `open`** (read from `state_machine_transition`; a hand-written source
  list would have been wrong about that second one). So the guard is the
  exception, not a state whitelist: attempt the transition and catch
  `IllegalTransitionException`, logged at `info`. A re-run finds the quote
  already moved and carries on — the transition is bookkeeping, the offer is
  the substance.
- **The reply comment** is skipped when an author-less comment already exists
  newer than the newest authored one — the agent has already answered this ask.
  This rests on #3's finding that agent comments are author-less on
  `createdById`, `customerId` and `employeeId` alike; `AddCommentTest` pins it,
  and the day SwagCommercial stamps an author is the day this switches to
  `createdById`.

Order is comment → `Sent` → the handler's fingerprint stamp, so a crash
anywhere yields a clean retry and never a duplicate buyer message. A pass
finding the quote already `replied`, with an agent comment newer than the
buyer's, returns `AlreadyAnswered` and does nothing.

**This closes #4's last Done-when** — "a quote can no longer be left in
`in_review` by a race" — because an interrupted pass is completed by its retry
rather than abandoned mid-flight.

### The escalation-marker fix carried from #28

`ServiceQuoteHandler` currently clears `QuoteEscalator::MARKER_KEY` on every
successful pass, which would erase a marker this pipeline writes two statements
earlier and re-escalate the quote on every later buyer comment. `service()`
therefore returns `NegotiationOutcome` instead of `void` — a one-line interface
change with no production implementations yet. The handler stamps the
fingerprint on every outcome, because the ask *was* handled even when the
answer was to escalate, but clears the escalation marker only on Offered or
Countered.

## What escalates

Existing band reasons, plus three new `QuoteEscalationReason` cases:
`ModelUnavailable`, `ProposalRejected` (the authorizer refused), and
`VerificationFailed`.

Two categories escalate as `NeedsHumanReview` by policy rather than by failure:

- **Structural asks** — quantity changes, line removals, product additions.
  Changing *what is being sold* is outside a price-and-validity mandate, and
  `addProduct` on a variant product still segfaults the worker (#3, guarded by
  `VariantRejectingProductAdder`).
- **Non-price grants** the gateway cannot persist, until #11 decides.

Every escalation goes through `QuoteEscalator`. Anything it writes is
**customer-facing** — SwagCommercial renders quote comments in the buyer's
storefront account — so the comment stays a fixed buyer-safe sentence and the
reason lives in the log. That constraint is #5's and this issue inherits it.

There is no fallback to rules-only on error. A model failure escalates; it does
not silently change how the shop negotiates.

## Testing

**Unit — no kernel, no network.** Each stage against a fake gateway and a
scripted client. The cases that earn their place:

- **Gating**: an out-of-band ask never calls the proposer client — asserted on
  the fake's call count, because an outcome-only assertion passes even when we
  paid for a call we should not have made.
- **Rules-only**: extract called; negotiate and reply not; the offer priced
  from `QuoteAutoReplyDetails`.
- **Translation**: both prompts' full documented JSON, plus malformed,
  truncated, and `action: escalate`.
- **Reply guard**: a reworded reply missing the discount figure or the validity
  date falls back to the template.
- **Idempotency**: the same message twice yields one comment and one `Sent`;
  the second returns `AlreadyAnswered`.
- **Counter band**: fixture-driven — grant below max, counter at max inside the
  band with `counteredRequestPercent` set, escalate above. The first test to
  make `Band::Counter` reachable.

**Golden-file prompt tests.** Compose each system prompt against a fixed
settings fixture and assert the exact string, so a prompt edit is a visible
diff rather than a silent behaviour change; assert the hash moves when
`strategyPrompt` or `replyTone` moves.

**Integration against the live shop, with a stubbed client.** Pipeline resolved
from the container, real gateway, real quotes, scripted model responses. Proves
the wiring, the writes, the verifier against real database state and the
transitions — and needs no API key, so it runs in the ordinary suite.

**One live-model test, opt-in.** Skipped unless an env var supplies a real key;
asserts only that a real endpoint returns JSON the readers parse. It is the
only thing that catches a provider changing its response shape, and it must
never gate CI or the stress run.

## Done when

- A buyer ask within `maxDiscountPercent` gets an offer applied to the quote and
  a reply the buyer can read.
- An ask inside the counter band gets a deterministic counter at
  `maxDiscountPercent`; `counterOfferMaxPercent` stops being dead config.
- An ask above the counter band escalates without any model call.
- A model proposal exceeding authority is rejected by `OfferAuthorizer` and
  escalates.
- `OfferVerifier` disagreeing with the database escalates rather than replying.
- Rules-only mode produces offers with no model deciding or writing.
- Delivering the same message twice produces one comment, one transition and
  one offer.
- Each pass emits one structured outcome event carrying the three prompt
  hashes — the shape #19 will persist.
