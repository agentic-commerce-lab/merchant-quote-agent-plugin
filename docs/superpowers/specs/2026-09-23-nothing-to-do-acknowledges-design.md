# An Empty Extraction Acknowledges the Buyer

Date: 2026-09-23

## Status

Implemented. 2026-09-23.

## Context

Since #177 (PR #182) a buyer comment whose extraction is empty in every field
ends the pass as `NegotiationOutcome::NothingToDo`: no reply, no escalation, and
`ServiceQuoteHandler` stamps the servicing fingerprint, so the comment is
consumed.

That dead-ends the buyer. Writing the comment moved the quote to
`change_requested`, and only a reply moves it back to `replied`. Over UCP,
`POST /ucp/quotes/{id}/counter` and `/accept` are valid only in `replied`, so
the buyer can neither answer nor accept the offer still standing on the quote.

Observed on sw-ag.dev (plugin 1.0.96), quote **1056**, read from
`merchant_quote_agent_decision`:

| created_at | outcome | error_class | interpreted_asks | buyer_ask | reply_to_buyer |
|---|---|---|---|---|---|
| 12:42:42 | `offered` (5.92%) | null | `price.targetTotal=3271.03` | "My budget limit is 3500 - can you adjust?" | "…reduce the quote by 5.92% to a total of 3500.17 EUR…" |
| 12:43:01 | `nothing_to_do` | null | every field null/empty | "Apply the disconut to the whole quote" | null |

The second pass was a genuine `nothing_to_do` — not a crash, not a timeout. The
next UCP counter was refused: "must have state replied before requesting
changes. Current state: change_requested."

The user: "without an answer, the customer can't accept the offer".

## Decision

A pass that **read a buyer comment** and found no ask in it answers with a
short, deterministic acknowledgement that restates the quote as it stands, then
moves the quote back to `replied`. It is recorded as a new outcome,
`NegotiationOutcome::Acknowledged = 'acknowledged'`.

It is still not an escalation: "thanks", "ok" and "will discuss internally" do
not reach a human (#167 holds). It is not a model call and writes nothing to
prices.

### When it fires

In `NegotiationPipeline::negotiate()`, inside the existing
`($ask === null || $ask->hasNoAsk()) && !StructuredAsk::isUnmet($snapshot)`
branch — the gate itself does not change. Within that branch:

| Condition | Result |
|---|---|
| `$ask === null` (no buyer comment read: duplicate trigger, stranded reply) | `NothingToDo`, silent, `finishStrandedReply()` as today |
| comment read, quote carries the escalation marker (`QuoteEscalator::MARKER_KEY`) | `NothingToDo`, silent |
| comment read, anything else — any state, including `open` and a pending clarification | `Acknowledged` |

The scope ("every read comment") is the user's choice. Two consequences are
accepted with it:

- A fresh RFQ in `open` whose comment carries no ask ("please send me a quote")
  is sent at its current prices. That is always inside the merchant's
  authority; it is also what the buyer asked for.
- A buyer answering a clarification with an empty-extraction comment is told
  the quote stands. The clarification marker is not released (below), so a
  later ambiguity still escalates.

**Why escalated quotes stay silent.** A human owns an escalated quote, and the
buyer already has the escalation notice (`notifyBuyerOnEscalation`, "A member
of our team will review this quote personally…", posted once per quote per
reason). Acknowledging would contradict that notice, and worse: moving the
quote to `replied` makes `SellerActEmitter` sign an act for the now-visible
terms, and `SellerActPublisher::recordApproval()` reads the unreleased
escalation marker as "a human stood behind these terms" — an A2CN approval
receipt for an approval nobody gave.

`MerchantHandover::tookOver()` still runs first, so a quote a human merchant
answered more recently than the buyer wrote stays `HandedOver`.

### The reply

`ReplyTemplate::acknowledges(float $total, string $currencyIso, ?\DateTimeImmutable $validUntil)`:

> Thank you for your message. This quote stands at 3500.17 EUR. The offer
> remains valid until 2026-10-08. You can accept it as it is, or tell us what
> you would like changed.

- The total is `QuoteTotals::buyerFacingTotal()` (gross, falling back to net) —
  the figure `ReplyComposer` already quotes, formatted by `ReplyTemplate::money()`.
- The date is `lifecycle->expiresAt`. When the quote has none, the validity
  sentence is **omitted** — the ack restates the quote, it does not invent a
  term. (The offer path's `+14 days` fallback is not reused here.)
- The last sentence names both next steps. For a comment the model misread as
  empty, it is the buyer's prompt to ask again.
- The copy obeys `RewordingGuard` even though no model wrote it: at most five
  sentences, no concession word, and no figure other than the total and the
  date. A unit test runs the template through `RewordingGuard::unsafeBecause()`
  to pin that.

### The write path

The silence-or-acknowledge choice lives in a new stateless
`Negotiation\PassedOver::handle()`, handed the `OfferRound` the pipeline already
holds (the `ClarificationRound` pattern): the pipeline, `OfferRound` and
`ReplyComposer` are each at their class complexity limit, measured. It calls
`OfferRound::acknowledge()` → `ReplyComposer::acknowledge(QuoteGatewayInterface, QuoteSnapshot): void`,
beside `reply()`:

1. `addComment()` with the template.
2. `DecisionRecorder::recordReply($text, null)` — `reply_to_buyer` holds it, no
   reply prompt hash (no model call).
3. `send()` — the existing transition picker: `sent` from `open`/`in_review`,
   `admin_resend` from `change_requested`/`reopen`. A refused transition is
   already logged at error level and recorded as an incomplete pass.

No already-answered guard is needed: an acknowledgement needs an interpreted
ask, and `AskInterpreter` interprets only a buyer comment newer than every
agent one — so a retry after the comment landed reads the agent as newest,
gets no ask, and stays silent.

Nothing is written to line prices, discounts or expiry.

### The outcome

`NegotiationOutcome::Acknowledged = 'acknowledged'`.

| Reader | Behaviour | Why |
|---|---|---|
| `answeredTheBuyer()` | **false** | It is not an offer. The admin's "Agent Offer" column and `latestAnswered`, and the strategy measures, read offered/countered passes; an ack carries no figures and no strategy version. |
| `QuoteEscalator::releaseFor()` | releases nothing | Follows `answeredTheBuyer()`; and an escalated quote never acknowledges. |
| `ClarificationMarker::releaseFor()` | releases nothing | Follows `answeredTheBuyer()`; a pending question stays pending. |
| `AgentDisclosure::stampFor()` | **stamps** | The agent put a comment in front of the buyer. |

### Keeping the trade-off checkable

The four supports from #180 stay:

- `NoAskFieldCoverageTest` — untouched.
- The `Nothing to answer on this quote` log line — still emitted on both
  branches, `commentRead` unchanged, plus `acknowledged: bool`. Its message
  changes from "standing down" to name what happened.
- `buyer_ask` — written for both outcomes as today (`DecisionRecorder` records
  the newest buyer comment regardless of outcome).
- The export — the review of passed-over comments is now
  `--outcome=acknowledged --include-comments` (the read comments) plus
  `--outcome=nothing_to_do` (the silent remainder). The command's help text,
  `docs/for-merchants.md` and `docs/end-to-end.md` say so.

### Admin

`decision.ts` gets `acknowledged` in the outcome variant map (`neutral`), the
disposition map, and a pass note; `en.json`/`de.json` get a label ("Acknowledged"
/ "Angebot bekräftigt"). `decision.check.mjs` asserts each, and that
`answeredTheBuyer('acknowledged')` is false. The detail page's `isNoop` stays
false for it: the agent did write something.

## Testing

- **Unit, pipeline:** an empty extraction on a quote in `change_requested`
  ends `Acknowledged`, posts exactly one comment equal to the template with the
  quote's gross total and expiry, transitions with `admin_resend`, and writes
  no price. Same from `open` with `sent`. With the escalation marker set:
  `NothingToDo`, no comment, no transition. With `$ask === null`:
  `NothingToDo` as today.
- **Unit, template:** the wording, the omitted-date variant, and
  `RewordingGuard::unsafeBecause()` returning null for both.
- **Unit, composer:** the ack posts the template and records it with a null
  hash; a retry with the agent speaking last stays `NothingToDo`.
- **Unit, outcome readers:** `answeredTheBuyer()` false, both `releaseFor()`
  empty, `AgentDisclosure` stamps.
- **Existing tests** that pin `NothingToDo` for a read, empty comment
  (`NegotiationPipelineTest`, `RecordedBuyerAskTest`, `StructuredAskGateTest`)
  move to `Acknowledged`; the ones for `$ask === null` stay.
- **Admin:** `composer run quality:admin`.
- **Live:** replay quote 1056's shape on sw-ag.dev after deploy — the second
  counter must get the ack and the third must be accepted.

## Known limits

- A worker that dies between the ack comment and the transition leaves the
  quote in `change_requested` with the agent speaking last. The retry reads no
  new ask and `finishStrandedReply()` only finishes `in_review`, so it stays
  stranded. The offer path has the same window from the renegotiation states
  today; widening `finishStrandedReply()` needs it to tell a stranded reply
  from a clarification question, which is its own change.
- `StructuredAskGateTest`'s pinned limit (a comment that merely points at an
  already-granted price) now gets the ack instead of silence — which is the fix
  the #180 memory predicted.
- ~~An escalated quote stays silent even after a human merchant has answered
  it.~~ Fixed in the follow-up: `QuoteEscalator` now stamps
  `merchant_quote_agent_escalated_at` beside the marker, `MerchantActionReader`
  reads the target state of the newest admin transition, and
  `PendingEscalation::awaitsAHuman()` is false once a merchant has SENT the
  quote (a transition into `replied`) after the escalation. Only a send counts:
  a merchant who moved it to `in_review` may have half-edited prices, which the
  acknowledgement's move to `replied` would receipt as approved. The same
  reason escalating again after a send is a new escalation (fresh notice, fresh
  time). A marker written before this change carries no time and stays silent.
