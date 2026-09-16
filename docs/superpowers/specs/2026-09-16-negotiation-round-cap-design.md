# The negotiation round cap

Date: 2026-09-16

## Status

Approved, not yet implemented.

## Context

Issue #142, a prerequisite for #21's 10,000-negotiation run. Nothing in the
plugin bounds what one quote can cost.

**The discount is bounded; the spend is not.** `QuoteBaselineLines::anchor()`
measures every round against the baseline the first pass stamped, so round 50
cannot concede more than `maxDiscountPercent` — that hole was closed by #49 and
#54. But every round still buys one to three model calls, and nothing counts
them. A buyer agent that keeps commenting keeps paying: each new buyer comment
moves `ServicingFingerprint`, so the fingerprint gate — the only thing between a
trigger and the pipeline — waves it through as genuinely new work, because it
is.

**The two counters that look like bounds are not.** `ModelPlatform` retries once
on a transport failure; that bounds one call, not a negotiation.
`ServiceQuoteHandler::MAX_ATTEMPTS = 4` is a crash budget — `servicePass()`
clears `merchant_quote_agent_attempts` on both normal exits, so it only bites
after a worker dies *without throwing*. Neither is a spend bound, and #21's own
prerequisite list asks for one.

This is not test scaffolding. On a merchant's shop an unbounded round count
means one persistent buyer can run up the merchant's model bill, which is why
#23 lists the same control as a prerequisite for a public "trick the agent"
demo. And there is a product argument that stands without the cost one: a
negotiation that has gone fifteen rounds is one a human should look at. That
argument is what makes this a merchant-facing control rather than a harness
flag, and it is what sets the default.

## Decisions

1. **A per-quote round cap, and no spend ceiling.** Fifteen passes per quote.
2. **Hitting the cap escalates**, through `QuoteEscalator`, under a new
   `QuoteEscalationReason::RoundLimitExceeded`. Loudness is inherited, not
   invented: the buyer is told if and only if `notifyBuyerOnEscalation` says so,
   exactly as for a discount above the cap.
3. **The count is a stamp, not a query** — `merchant_quote_agent_rounds` on the
   quote's customFields, incremented in the write that already claims the crash
   budget.
4. **The cap is a constant, not a config field.** `config.xml` gains nothing.
5. **The check lives at the top of `NegotiationPipeline::negotiate()`**, before
   the extract call, so a refused round costs no model call and still leaves an
   audit row.

## The four questions, and the arguments

### 1. Round cap, spend ceiling, or both

Round cap only. The issue ranks it first and that ranking is right, for three
reasons that are about more than ordering.

**The plugin does not know what a token costs.** `llmBaseUrl` and `llmModel` are
free text: the merchant points them at OpenAI, Azure, a gateway or a local
model. A spend ceiling denominated in money needs a price per token, which the
plugin can only get by asking the merchant for one — a config field that is
wrong for every provider that changes its prices and silently wrong for the
merchant who forgets to update it. A ceiling denominated in *tokens* avoids
that, but then it is not a spend ceiling, it is a proxy the merchant has to
convert by hand.

**A spend ceiling has the wrong blast radius.** It is by definition a
cross-quote aggregate, so when it fires it takes the whole sales channel's agent
out of service — every quote, including the ones nowhere near a problem. The
plugin has one precedent for a control that silently stops the agent
shop-wide, and the entire point of #5 was to remove it. The round cap stops
exactly the quote that is spending and hands that one quote to a human; every
other quote is untouched.

**The provider already has the backstop the plugin would be reimplementing.**
Every provider whose API this plugin speaks offers a hard cap on the key, and it
sees spend the plugin cannot (other consumers of the same key, the real prices).
What the provider cannot do is escalate one quote to a human instead of
returning an error, and that is precisely what the round cap does. Splitting the
job along that line gives each half to the thing that can actually do it.

What #21 asked for was "a per-run ceiling so a runaway loop stops itself". Once
each negotiation is bounded at fifteen passes, the run's worst case is
arithmetic — 10,000 quotes × 15 passes × ≤3 calls — rather than unbounded. The
unbounded thing becomes a computable thing, which is what the prerequisite was
for.

**Revisit if** a measured run shows the cost concentrated somewhere the
per-quote cap cannot see — many quotes each below the cap — at which point the
right control is a per-window pass budget across quotes, not a money figure.

### 2. What a quote does when it hits the cap

It escalates. Every other "outside the mandate" condition escalates
(`DiscountLimitExceeded`, `QuoteValueLimitExceeded`, `NeedsHumanReview`,
`CurrencyMismatch`), and this one is the same shape: the agent has reached the
end of what the merchant authorised it to do on this quote, so a human takes
over. Stopping silently — answering nothing, writing nothing — would leave the
buyer talking to a shop that has stopped replying, which is the failure #29's
`notifyBuyerOnEscalation` help text names outright.

**Silent or loud is not a new decision, and that is the point.** #29 set the
precedent that a deliberate merchant choice is silent and a misconfiguration is
loud. The round cap is neither: it is a mandate boundary, like the discount cap.
So it takes the discount cap's behaviour unchanged, by routing through
`QuoteEscalator::escalate()`:

- the buyer gets the standard escalation comment if and only if the merchant
  left `notifyBuyerOnEscalation` on — the merchant's existing choice, honoured
  without a second switch;
- the merchant gets the `EscalationNotifierInterface` notice, the same one every
  other escalation raises;
- the log line is `info`, not `error`. Nothing is broken. `ServicingPreflight`
  logs `error` for a misconfiguration because a human must go fix something;
  here a human must go *decide* something, which is the normal escalation path
  and already has a queue.

The escalation marker carries its own anti-spam rule and it applies here
unchanged: `escalate()` returns early when the marker already holds this reason,
so a buyer who keeps commenting after the cap gets exactly one comment, not one
per comment.

### 3. Where the count lives

A stamp on `customFields`, keyed `merchant_quote_agent_rounds`, written in
`ServiceQuoteHandler::claimAttempt()`'s existing `updateQuote()` — the one that
already writes the crash-budget counter and `QuoteBaseline::stampOrExtend()`.

**Why not count `merchant_quote_agent_decision` rows.** The issue is right that
the rows exist, one per pass with token counts. Three things disqualify the
query:

- **The audit write is deliberately allowed to fail.**
  `NegotiationPipeline::record()` catches everything `DecisionRecorder::finish()`
  throws, logs it and lets the pass stand — correct, because a failed audit write
  must never re-answer a buyer through Messenger's retry. A cost control read
  from a store that is permitted to lose writes fails *open*: the rows go
  missing, the count stays low, and the cap never fires. The one thing a spend
  bound must not do is stop working quietly.
- **It inverts a one-way dependency.** `src/Audit/` is written to and never read
  by the negotiation path; `DecisionRecordWriter` is the namespace's only
  DAL-facing class on the write side. Counting rows would give `src/Negotiation/`
  or `src/Servicing/` a repository dependency and a per-pass query against a
  table whose rows are the merchant's data and prunable by them.
- **The write is already paid for.** `claimAttempt()` writes customFields before
  every pipeline pass regardless. The counter rides along in that write for
  nothing, and — this is the part that matters — it inherits that write's
  ordering: committed *before* the pipeline runs, so it survives a process death
  during the pass. That ordering is the mechanism for the crash budget and it is
  the mechanism here too. A pass that segfaults halfway through still paid for
  its model calls, and still counts.

**Why not count the buyer's comments**, which needs no new state at all and is
already computed inside `ServicingFingerprint`: because the one ask that arrives
*without* a comment is the per-line `requested_price` edit, which the fingerprint
has a whole component for. A harness — or a buyer — that loops on price edits and
never types anything would hold the comment count at zero forever while buying a
pass per edit. The counter has to count the thing being bought.

**What is counted is passes that reached the pipeline**, not rounds the agent
answered. The fingerprint gate and the preflight both return before
`claimAttempt()`, so a duplicate trigger, a paused channel and a terminal quote
all cost nothing and count nothing. Everything past that point buys at least the
extract call. Counting answers instead would be worse in the exact case the cap
exists for: a quote that escalates on every pass (`ModelUnavailable`,
`ProposalRejected`) answers nobody and would never reach a cap on answers, while
paying for every attempt. Passes are the spend metric; rounds are at most the
pass count, so the "fifteen rounds deserves a human" argument survives the
substitution.

### 4. Whether the cap is configurable

It is not. `config.xml` gains no field.

The starting position is the one the 2026-09-08 config-simplification spec set:
the surface went from 20 fields to 11 by deleting fields that tuned logic which
could not execute, and adding one back needs an argument. Here the argument
against is stronger than "one more field":

**The blank convention and a safety control point in opposite directions.**
Every optional numeric field in this config reads blank as *no limit* —
`counterOfferMaxPercent` ("Blank means no counter band"), `maxQuoteValueNet`
("Blank means no ceiling at all"). A `maxNegotiationRounds` field would have to
read blank as *the default cap*, because reading it as "unlimited" would give
every existing installation no cap at all. That inversion inside one config card
is a trap for the next person to read it.

**And #57 is the reason that matters practically.** Core writes `defaultValue`
rows at install, and `updatePlugin()` calls `savePluginConfiguration()` with
`$override = false`, so a new `config.xml` field reaches no shop that already has
the plugin — every installed shop would hold no row at all. Making the field work
therefore needs a migration writing the default into every scope, and a
validation constraint without one takes every installed agent out of service, as
#57 established the hard way. That is a migration and a constraint and an
inverted convention, for a number whose two failure modes are both mild:

- too low → some merchant's genuinely long negotiation reaches a human early,
  which is a graceful outcome with a queue behind it, not a broken quote;
- too high → a runaway buyer burns 15 passes instead of 8, still bounded.

**The value: 15.** Two independent readings agree closely enough. Real B2B quote
negotiations settle in well under six rounds, so 15 leaves roughly 2.5× headroom
before the cap can touch a legitimate deal. And the issue's own product argument
puts a human on the quote at about fifteen rounds. 15 bounds one quote at ≤45
model calls.

**When to make it configurable:** the first merchant who reports a legitimate
negotiation escalating at the cap. The change then is a `config.xml` int field, a
migration writing 15 into every existing scope, and a default of 15 in
`NegotiationRounds` for a shop that clears it — deliberately *not* "blank means
unlimited". Recording the shape here is what makes that a small change later
rather than a rediscovery.

## Design

### `NegotiationRounds`

One small class in `src/Servicing/`, shaped like `QuoteBaseline` and
`ClarificationMarker` — the two customFields owners it sits beside — so the key,
the cap and the int-coercion have one home rather than being split across the
handler that writes and the pipeline that reads.

```php
final class NegotiationRounds
{
    public const KEY = 'merchant_quote_agent_rounds';
    public const MAX = 15;

    public static function completed(QuoteSnapshot $snapshot): int;
    public static function exhausted(QuoteSnapshot $snapshot): bool;
    /** @return array<string, int> spread into a QuoteUpdate's customFields */
    public static function increment(QuoteSnapshot $snapshot): array;
}
```

`completed()` reads the stored value and treats anything that is not an int as
`0` — the same coercion `claimAttempt()` already applies to the attempts
counter, and the reason is the same: customFields is a JSON column and a value
that is not a number cannot be a count.

### The write

`ServiceQuoteHandler::claimAttempt()` gains one spread in the `QuoteUpdate` it
already builds:

```php
$gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
    self::ATTEMPTS_KEY => $attempts + 1,
    ...QuoteBaseline::stampOrExtend($snapshot),
    ...NegotiationRounds::increment($snapshot),
]));
```

No new write, no new round trip. Unlike `ATTEMPTS_KEY`, the counter is **never
cleared** on a normal exit — clearing it is what makes the crash budget a crash
budget, and is exactly what must not happen to a spend bound.

### The check

The first statement of `NegotiationPipeline::negotiate()`, before
`$this->interpreter->interpret(...)`:

```php
if (NegotiationRounds::exhausted($snapshot)) {
    $this->logger->info('This quote has had its full budget of agent passes; a human takes it from here.', [
        'quoteId' => $snapshot->identity->quoteId,
        'rounds' => NegotiationRounds::completed($snapshot),
    ]);

    return $this->round->escalated(
        $gateway,
        $snapshot,
        QuoteEscalationReason::RoundLimitExceeded,
        null,
        null,
    );
}
```

Three properties, each deliberate.

**Before the extract call**, so a refused round costs zero model calls. The cost
of a post-cap trigger is `ServiceQuoteHandler`'s two snapshot reads and two
customFields writes (the claim write and the stamp write) plus one audit row.

**Inside the pipeline, not in `ServicingPreflight`.** The preflight answers "may
the agent run at all" — paused, misconfigured, terminal state — and none of its
refusals reach the recorder, which is the gap #35 exists to fill. The round cap
answers "is this ask inside the mandate", which is `AskGate`'s question, and
`AskGate` escalates from inside the pipeline precisely so the refusal is
recorded. Putting the cap here means the escalation gets its audit row from the
machinery that already exists — `recorder->begin()` has run, `finish()` will run
in the `finally`, and `PassOutcome` writes `outcome = escalated` with
`escalation_reason = round_limit_exceeded`. #21 can then count capped quotes in
the same query as every other escalation.

**Reading the pass-start snapshot**, which `servicePass()` fetched *before*
`claimAttempt()` wrote to it. `completed()` is therefore the number of passes
that finished before this one, so `>= 15` lets passes 1 through 15 run and
refuses the 16th. Reading a snapshot taken after the increment would be an
off-by-one that only shows up in production.

### After the cap

The handler stamps the fingerprint as it does for any other outcome — the ask
*was* handled, the answer was "a human will". `QuoteEscalator::releaseFor()`
returns `[]` for `Escalated`, so the marker stands. A buyer who keeps commenting
after that gets: a fingerprint that differs, a pipeline pass, the cap check, an
early return inside `escalate()` on the marker, and one audit row. No comment, no
model call.

**The escape hatch** is the one the crash budget already documents: clear
`merchant_quote_agent_rounds` on the quote to hand it back to the agent. It is
recorded in `NegotiationRounds`' docblock and in the log line's wording. Nothing
resets the counter automatically, including a deal desk resolving the escalation
— see "Deliberately not in scope".

### Vocabulary

`QuoteEscalationReason::RoundLimitExceeded = 'round_limit_exceeded'`, plus
`escalation.round_limit_exceeded` and `escalationWhy.round_limit_exceeded` in the
admin's `en.json` and `de.json`. `escalationExplanation()` falls back to the short
label for a reason with no sentence, so a missing snippet degrades rather than
breaks — but the sentence is the merchant-facing half of this feature and shipping
without it would leave the admin saying nothing about why the quote stopped.

English: *"This negotiation reached the maximum number of agent passes, so it was
handed to a person. Clear the quote's round counter to let the agent continue."*

## Open questions and the assumptions taken

No one could be asked, so each is recorded with the assumption and what would
change it.

1. **Is 15 the right number?** Assumed yes, from "real negotiations settle under
   six rounds" and the issue's own fifteen. Changed by the first real merchant
   telling us their negotiations are longer — which also makes the case for the
   config field.
2. **Should a human resolving the escalation reset the counter?** Assumed no.
   `EscalationResolutionSubscriber` observes state transitions with no author
   filter and explicitly cannot tell the deal desk from the buyer, so wiring a
   reset to it would let a *buyer* withdraw-and-restore their way to another
   fifteen passes — a reset any buyer can trigger is not a cap. The manual clear
   stands instead. Changed by a way to attribute a resolution to a human.
3. **Should the cap count passes or answered rounds?** Assumed passes; argued
   above. Changed if repeated escalating passes on one quote turn out to be
   common enough that quotes hit the cap without ever getting an answer — that
   would be a bug in the escalation path worth fixing before loosening this.
4. **Is one cap right for every sales channel?** Assumed yes, for now. The
   settings already resolve per sales channel, so the config field, when it
   comes, is per channel for free.
5. **Should the counter stop incrementing once past the cap?** Assumed no —
   left to grow, because `>= MAX` is unaffected and the stored figure is then
   an honest count of how hard a buyer pushed after the cap, which #21 may want.

## Interaction with #35

#35 is being built in parallel and adds audit rows for passes refused *before*
the pipeline. The two do not overlap and must not:

- **#35's rows** cover `ServicingPreflight`'s refusals and the fingerprint gate
  — passes that never reached `DecisionRecorder::begin()`.
- **This cap's row** is written by the existing recorder, because the check runs
  *inside* the pipeline, after `begin()`. It needs nothing from #35.

The risk to watch when both land: if #35 also gives the preflight a
`QuoteEscalationReason`-shaped vocabulary, the two sets of reasons must stay one
enum, so the admin's `escalation.*` snippets keep covering every value the column
can hold. Nothing in this design constrains #35's choice; it only adds one case
to the enum both would share.

## Testing

Test-driven, one failing test first.

**Unit, `NegotiationRoundsTest`** — the boundary and the coercion: 14 completed
passes is not exhausted, 15 is, a missing key reads as 0, a non-int value reads
as 0, and `increment()` returns the stored value plus one.

**Unit, `NegotiationPipelineTest`** — the load-bearing case: a snapshot carrying
15 completed rounds returns `NegotiationOutcome::Escalated` with reason
`round_limit_exceeded`, the interpreter is never called (the assertion that the
model call was not paid for), and the recorded draft carries the reason. A
snapshot at 14 negotiates normally.

**Unit, `ServiceQuoteHandlerTest`** — `claimAttempt()`'s write carries the
incremented counter alongside the attempts counter and the baseline, and the
post-pass write does **not** clear it. That second assertion is the one that
catches a future edit adding `NegotiationRounds::KEY => null` beside
`ATTEMPTS_KEY => null` by symmetry.

**Integration, `ServicingRoundCapTest`** — a real quote driven past the cap
through the real handler and gateway: passes run up to the cap, the next trigger
writes no comment beyond the first escalation comment, makes no model call, and
the quote carries `merchant_quote_agent_escalated = round_limit_exceeded`.
Sibling of `ServicingCrashBudgetTest`, which drives the other counter the same
way.

**Admin, `decision.check.mjs`** — the new reason composes a sentence rather than
falling through to the short label.

## Deliberately not in scope

- A spend ceiling in money or tokens. Argued above; the provider's key cap is the
  backstop until a measured run says otherwise.
- Per-customer rate limiting across quotes (#23's other half). The round cap
  bounds one quote; a buyer who opens a hundred quotes is a different control with
  a different key.
- Resetting the counter on human resolution (open question 2).
- Surfacing the round count in the admin. `merchant_quote_agent_decision` already
  carries one row per pass, so the dashboard can count them; this adds no column.

## Consequences

**Good**

- One quote's model spend is bounded, so #21's run has an arithmetic worst case.
- A persistent buyer on a merchant's live shop cannot run the bill up
  indefinitely on one quote.
- A negotiation that has genuinely gone fifteen rounds now reaches a human, which
  is the outcome that was wanted independently of cost.
- Post-cap triggers become the cheapest path through the pipeline: no model call,
  no comment, one row.

**Bad, and accepted**

- A merchant whose negotiations legitimately run longer than fifteen passes gets
  escalations they did not want, and no way to raise the number without a code
  change. Accepted because the failure is graceful and the config field has a
  recorded shape when it is earned.
- The counter lives on the quote, so clearing it is a customFields edit — an
  operator action, not an admin button. Same ergonomics as the crash budget, and
  the same reason: it should be rare.
- Passes and rounds are not the same number. A quote that spends passes on
  `nothing_to_do` (a merchant note that moved the fingerprint, a stranded reply)
  reaches the cap after fewer real rounds than fifteen. Accepted: those passes
  cost model calls too, which is what the cap bounds.
- The guard returns before `negotiate()`'s `finishStrandedReply()` call, so a
  quote whose last allowed pass posted its reply and then died before the
  `sent` transition is escalated rather than having that transition finished.
  Accepted: it takes a crash on exactly the last allowed pass, and the outcome
  either way is an escalated quote with a human notified — which is where such
  a quote should end up regardless.

**Revisit if**

- A measured #21 run shows cost concentrated across quotes rather than within
  one.
- A merchant reports a legitimate negotiation hitting the cap.
- #35 lands a second vocabulary for pre-pipeline refusals.

## References

- `src/Servicing/ServiceQuoteHandler.php:208-251` — `claimAttempt()`, the write
  the counter joins, and the crash budget it must not be confused with
- `src/Negotiation/NegotiationPipeline.php:136-172` — `negotiate()`, where the
  check goes, and `record()`'s deliberately swallowed audit failure
- `src/Negotiation/AskGate.php` — the precedent: a mandate boundary escalating
  from inside the pipeline
- `src/Servicing/QuoteEscalator.php` — the marker's once-per-reason rule and
  `notifyBuyerOnEscalation`
- `src/Negotiation/QuoteBaseline.php` — the customFields owner this class is
  shaped after
- `docs/superpowers/specs/2026-09-08-config-simplification-and-discount-ceiling-design.md`
  — why `config.xml` gains nothing
- `docs/superpowers/specs/2026-09-16-offer-validity-default-design.md` and
  `src/Migration/Migration1789500000DefaultOfferValidityDays.php` — #57: what a
  new config field would cost
