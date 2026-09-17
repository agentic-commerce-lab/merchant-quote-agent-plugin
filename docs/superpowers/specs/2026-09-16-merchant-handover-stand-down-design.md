# The Agent Stands Down When a Human Merchant Is on the Quote

Date: 2026-09-16

## Status

Approved. Written from the question "when a human merchant answers a quote by
hand, does that also trigger the assistant?"

## The question, answered

A merchant acting by hand is already filtered in three independent places, and
none of them is the one that matters.

**Filtered today.** `QuoteServicingTrigger::isMerchantComment()` skips a comment
carrying `createdById` and neither buyer column, so an admin note queues no
message. `TRIGGER_STATES` is `open`, `change_requested`, `reopen` only, with
`reopen` further restricted to the buyer's `request_change` transition, so a
merchant replying (`replied`/`sent`), reviewing (`in_review`) or un-declining
queues nothing either. And if a message is queued anyway, `ServicingFingerprint`
counts buyer comments only and `SnapshotAdapter::conversation()` drops merchant
comments, so `BuyerConversation::hasNewBuyerAsk()` is false and the pass records
`nothing_to_do` without a model call.

**Not filtered.** Nothing in the pass re-checks for merchant activity between the
trigger and the write. Three defects follow:

1. **The race.** Buyer asks, the message queues, the merchant answers by hand in
   the seconds or minutes before a worker picks it up — or while the message is
   being redelivered after lock contention (`BUSY_RETRY_DELAY_MS`, 5s) or a
   Messenger retry. `ServicingPreflight` lets `replied` through (it is not
   terminal), the fingerprint has not moved for anything the merchant did, and
   the buyer's comment is still newer than the agent's last reply — which is
   nothing on a first pass. The agent answers on top of the human, and because
   `OfferApplier` writes line prices, it can also overwrite the prices the
   merchant just set by hand.
2. **The agent cannot read what the human wrote.** After a merchant hand-reply, a
   genuine new buyer comment is serviced — correctly — but the merchant's text is
   in neither `BuyerConversation` bucket, so the agent negotiates from its own
   last reply and can contradict or undercut what the human just promised.
3. **`OfferRound::finishStrandedReply()`** transitions *any* quote it finds in
   `in_review` to replied. It cannot tell a pass of ours that died mid-way from a
   human merchant with the quote open in the admin.

## The rule

**A pass stands down when a human merchant acted more recently than the buyer's
newest input.** A later buyer ask re-enables the agent by itself; there is no
permanent handover and no merchant-operated switch.

## Part 1 — Dating the human merchant

Two ways a merchant acts, one answer.

**Comments** are already datable: `createdById` set, `customerId` and
`employeeId` null, is the administration's shape, documented on
`Bridge\Data\QuoteComment` and measured on the test shop (4 such rows on
2026-09-16). The predicate exists; only the bucket is missing.

**Transitions** are datable through core, not through SwagCommercial. At the
6.7.1 support floor, `StateMachineRegistry::transition()` writes one
`state_machine_history` row per transition:

```php
'userId' => $context->getSource() instanceof AdminApiSource ? $context->getSource()->getUserId() : null,
```
— `src/Core/System/StateMachine/StateMachineRegistry.php:154` at `v6.7.1.0`

So `user_id IS NOT NULL` is exactly "an administration user moved this quote".
The buyer's store-api transitions carry a `SalesChannelApiSource` and the agent's
own carry a `SystemSource`; both write null by construction. This is the same
three-way authorship split `QuoteComment` documents for comments, and it is the
signal `EscalationResolutionSubscriber` says the core state-change event does not
carry — true of the event, not of the row core writes beside it.

`Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryDefinition`
exists at the floor (`since(): '6.0.0.0'`) with `referencedId`, `entityName`,
`userId`, `integrationId` and `transitionActionName`, so
`CoreFloorCompatibilityTest` is satisfied. The read is a core entity, not a
SwagCommercial one, but it goes in `src/Bridge` anyway: ADR 0001 puts every DAL
read of a Shopware entity there, and `src/Negotiation` may not import Shopware at
all (`NamespacePurityTest`).

**Criteria.** `entity_name = 'quote'`, `referenced_id = <quoteId>`,
`user_id != null`, newest `createdAt` first, limit 1. Deliberately **not**
filtered by `referenced_version_id`: SwagCommercial edits quotes in a version
lane, and a human acting there is still a human acting. The floor's index is
`(referenced_id, referenced_version_id)`
(`Migration1694426018AddEntityIndexToStateMachineHistory`), whose leading column
serves this query.

An integration acting through the admin API sets `integration_id` and leaves
`user_id` null, so an ERP sync is not read as a human. That is the intended
reading: the rule is about a person having looked.

**Where it lands.** A new `Bridge\MerchantActionReader` (one method,
`lastTransitionAt(string $quoteId, Context $context): ?\DateTimeImmutable`),
injected into `QuoteSnapshotReader` as a fourth argument, filling a new nullable
`lastAdminTransitionAt` on `QuoteLifecycle`. Everything downstream reads a plain
DTO field and learns no new Shopware type.

The field carries the transition alone, not the later of transition and comment.
Combining the two is a decision, and decisions belong where they can be unit
tested: the comments are already in the snapshot, so `MerchantHandover` takes the
maximum itself, in pure code, and the bridge stays transport.

Accepted cost: one indexed query per `fetchSnapshot()`, and that method is called
more than once per pass (pass start, `OfferApplier`'s verification read, the
post-pass read) as well as on the A2CN protocol reads. Measured against an LLM
call it is noise; if it ever shows up, the fix is to read it only in the servicing
path rather than to cache it.

## Part 2 — Dating the buyer

The newest buyer-authored comment's `createdAt`, which `ServicingFingerprint`
already computes for its own marker.

A buyer ask can also arrive with no comment at all: the storefront writes a
per-line target to `quote_line_item.requested_price`. `quote_line_item` carries
`updated_at`, which `QuoteLineSnapshot` does not read today — a one-field
addition to it and to `QuoteLineMapper`.

**Only the line whose ask moved is read.** `updated_at` moves on *any* write to
the line, including the agent's own price writes, so the newest line timestamp on
its own would read our last pass as a fresh buyer ask on every quote we have ever
serviced. The line to date is the one whose `requestedUnitPrice` differs from the
asks component of the stamped fingerprint — the buyer's number, changed since we
last looked. `ServicingFingerprint::asks()` already composes exactly that string;
it gains a public reader for the stamped half.

Known ceiling, deliberately accepted: `updated_at` carries no authorship, so a
line timestamp is weak evidence of a buyer at best — strongest when the
buyer's own target changed, which is exactly what the per-line token
comparison checks, but never proof. A merchant who writes ANY column on the
line moves the same `updated_at`, and the unit price — the column
`OfferApplier` overwrites — is the one they have every reason to touch. If
that line's ask token already differs from the stamp (a real, still-unread
buyer ask), the merchant's own later write to that row is what gets read back
as the buyer's timestamp, dating the ask after the merchant acted and
suppressing a stand-down that should have covered it. A full fix would need
per-write authorship the column does not have; this is a known gap, not a
mitigation.

A quote with no stamped fingerprint has no "since when" to compare against, so a
comment-less ask on a never-serviced quote is dated by the line's `updatedAt`
outright.

## Part 3 — Where the guard lives

Split between the class that owns the conversation and one static predicate over
the snapshot, because the decision needs more than the conversation holds.

`BuyerConversation` is comments only, and that stays true. It gains a third
bucket — `SnapshotAdapter::conversation()` currently drops merchant comments on
the floor rather than keeping them — so "when did the merchant last write" is
answerable at all. The bucket is read by nothing else: `agentText()` and
`newestBuyerText()` compose prompts, and a merchant's note belongs in neither.

The comparison itself is `Negotiation\MerchantHandover::tookOver()`, a static
predicate over the snapshot in the same idiom as `StructuredAsk::isUnmet()` and
`MirroredAsks`. It needs three things the conversation cannot supply on its own:
the merchant bucket, `lifecycle.lastMerchantActionAt`, and the lines with their
`updatedAt` and stamped asks. Being static, it adds no constructor parameter —
which matters, because `ServiceQuoteHandler` and `NegotiationPipeline` both sit at
the five-parameter cap and a new injected collaborator for either would have
forced a refactor to buy nothing.

`NegotiationPipeline::negotiate()` gains one branch, **before** the
`$ask === null` branch, returning a new `NegotiationOutcome::HandedOver`.

Consequences, all of them free:

- No model call: the branch is before `AskInterpreter`.
- One audit row, through the existing `begin()`/`finish()` lifecycle — no new
  `DecisionRecorder` method, no `recordRefusal()` variant.
- `HandedOver` does not answer the buyer, so `answeredTheBuyer()` is false and
  neither the escalation marker nor the clarification marker is released.
- The fingerprint IS stamped, as it is for every other outcome: the trigger was
  handled, and the answer was "a human has this".

Rejected: a subscriber stamping a `human_touch` custom field on every non-agent
admin transition. It writes inside the merchant's own admin request, it is blind
to every quote that exists before the deploy, and it duplicates a row core
already writes.

Rejected: reading SwagCommercial's `quote_history`. It does not exist below
7.13 — see the version map — and `EscalationResolutionSubscriber` already records
why that door is closed.

**Admin vocabulary moves in the same change, not after it.** A new outcome value
is read in four places outside PHP:
`Resources/app/administration/src/module/merchant-quote-agent/decision.ts` (the
`outcomeVariant` and `disposition` maps), `decision.check.mjs`, and the `en.json`
/ `de.json` snippets. This repo has twice shipped an admin keyed to values PHP no
longer writes; the pairing is one task.

## Part 4 — The stranded reply

Ordering the handover branch before the `$ask === null` branch already stops
`finishStrandedReply()` touching a quote a human transitioned into `in_review`.

For a merchant who opens the quote in the admin **without** transitioning it,
there is no signal at all, so the method is additionally restricted to the only
case it exists for: a quote whose newest comment is the AGENT's own. That is what
a stranded reply is — #31's worker died between the comment write and the `sent`
transition — and only the agent writes an author-less comment, so no human can
produce the shape. A quote sitting in `in_review` with no agent comment newest
(including one with no comments at all, where a structured ask was met) is not
ours to finish. `BuyerConversation` answers it with `agentSpokeLast()`, over the
same three buckets the handover rule uses.

**Rejected: `PassContext->attempt > 0`.** It reads as "a previous pass died
mid-way", and for a segfault it does — the counter is committed before the
pipeline and cleared after it. But `ServiceQuoteHandler` also clears the counter
in its catch before rethrowing, so a pass that *threw* between the comment write
and the transition — an `IllegalTransitionException` from the transition itself,
which is the likeliest way to strand a reply — comes back with `attempt === 0`
and would never be finished. The guard would have left the exact quotes #31
exists for stranded forever.

## Testing

**Unit.**
- Merchant comment newer than the newest buyer comment → `handed_over`, no model
  call, fingerprint stamped, escalation marker untouched.
- Buyer comment newer than the merchant's action → serviced as today.
- An admin transition newer than the buyer's comment, with no merchant comment at
  all → `handed_over`.
- A comment-less requested-price change dated against a merchant action, both
  orders.
- A requested-price change on a quote with no stamped fingerprint → serviced.
- `finishStrandedReply()` skipped at `attempt === 0`, taken above it.
- `BuyerConversation` keeps merchant comments out of `agentText()` and
  `newestBuyerText()` — the new bucket must not leak into either prompt.

**Integration.** `MerchantActionReader` against a real admin transition on the
test shop: one quote moved from the administration, one moved by the agent, and
the reader distinguishing them.

## Out of scope

Defect 2 — the agent negotiating blind to what the human wrote — is not fixed
here. Standing down removes the case where it does damage unasked; feeding a
merchant's text into the negotiate prompt is a separate product decision about
whether the agent should speak for a human who has already spoken, and it belongs
in its own issue.

`ServicingPreflight`'s misconfiguration path is not covered by this rule
either, and stays that way by design: the guard lives in the pipeline, one
step after the preflight check, so a shop with an unusable configuration
still escalates — and, once per quote per reason, can still post the
customer-facing "a member of our team will review this quote personally and
get back to you" comment — on a quote a human has already taken over. Worth
recording rather than leaving for someone to rediscover.
