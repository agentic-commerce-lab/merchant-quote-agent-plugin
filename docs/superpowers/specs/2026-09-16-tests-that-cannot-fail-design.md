# Tests That Cannot Fail

Date: 2026-09-16

## Status

Approved. Written for issues #139 and #141.

The two are one design because they are one defect in two costumes: a test whose
name asserts something the test itself cannot observe. #139's test names a
guarantee the code does not make and so is permanently red; #141's five files
name a path they stopped taking and are permanently green. Both are the shape
#81 recorded for the whole suite and #87 for `DidWebResolverTest`.

### Where the user would have been asked

No user was reachable during this work. Three questions would have been asked;
each is recorded here with the assumption taken instead.

1. **#139 — raise the support floor instead?** Declaring `maxLength: 32` on
   `#[Field]` would make the DAL reject the write, but the argument does not
   exist at the 6.7.1 floor. Raising the floor to ~6.7.5 would buy a typed
   `WriteException` here and cost every merchant below it. **Assumed: no.** The
   floor is a product decision recorded in `composer.json`, the README and
   `CoreFloorCompatibilityTest`, and a red audit-column test is not a reason to
   move it.
2. **#141 — should the reply figures be asserted exactly, or loosely?**
   **Assumed: exactly** (`assertSame` against the full scripted string). A
   `assertStringContainsString` on a fragment is how the original tests got
   here: it survives a fallback whose text happens to share the fragment.
3. **#141 — convert the four pre-existing hand-spelled copies of the same
   fixture reply?** **Assumed: yes.** They are the correct idiom, spelled out
   four times; leaving them beside a new shared builder would be the worst of
   both. They are covered by the gate either way.

## Part 1 — #139: the `band` column is bounded by MySQL, not by the DAL

### Measured

On the `merchant-quote-shop` container, on this branch's base commit:

```
1) DecisionRecordTest::testAStringLongerThanItsColumnIsRejectedAtWriteTime
Failed asserting that exception of type "Doctrine\DBAL\Exception\DriverException"
matches expected exception "Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException".
Message was: "An exception occurred while executing a query: SQLSTATE[22001]:
String data, right truncated: 1406 Data too long for column 'band' at row 1"
```

The stack runs `EntityRepository::create()` → `EntityWriter` →
`EntityWriteGateway` → `MultiInsertQueryQueue` → PDO. Nothing between the
repository and the driver looks at the column's width.

### Why the DAL cannot

`QuoteDecisionRecord` is an attribute entity. The only way its field definitions
can carry a length is `#[Field(..., maxLength: 32)]`, and
`Attribute\Field::$maxLength` **does not exist at the 6.7.1 support floor** —
absent up to and including 6.7.4.2, present by 6.7.13.1 (where it defaults to
255, which 33 characters clear anyway). Passing a named argument for a
parameter the installed core does not declare is an `Error` at attribute
instantiation, which happens during the container build: it takes the whole
shop down, not just this plugin.

This is not a new finding. It is already written up in `QuoteDecisionRecord`'s
own docblock, and `CoreFloorCompatibilityTest::testDalAttributeArgumentsExistAtTheSupportFloor`
exists precisely to stop the argument coming back. The thirteen columns it was
once used on are the reason that guard was written.

So the DAL genuinely cannot reject an over-long `band`, and the issue's first
option — "give the field its length" — is closed. The second option applies:
change the test to assert what is actually guaranteed.

### The sibling columns

`band` does not lack a length while its siblings have one. **No string field on
this entity carries `maxLength`**, for the one reason above. The widths live in
the migrations, which are the only constraint that holds:

| column | width | | column | width |
|---|---|---|---|---|
| `quote_number` | 64 | | `model` | 128 |
| `currency_iso` | 3 | | `model_host` | 255 |
| `trigger_reason` | 64 | | `*_prompt_hash` | 64 |
| `revision_version_id` | 64 | | `error_class` | 255 |
| `band` | **32** | | `terminal_state` | 64 |
| `outcome` | 32 | | `escalation_reason` | 64 |

All of those are `Migration1787998662CreateQuoteAgentDecision`;
`resolved_state` (64) arrived later, in
`Migration1789000000AddEscalationResolution`. Every one is enforced by MySQL at
write time and by nothing before it. `band` at 32 is the column the test has
always used and is narrow enough that a plausible value overruns it;
`currency_iso` at 3 is narrower still, and in exactly the same position.

Bounding the values at the application layer — truncating or validating in
`DecisionRecordWriter` — is **#60's** scope (`buyer_comment` / `raw_proposal`
have no size bound either) and is deliberately not done here. This change adds
no production code.

### The premise the issue got wrong, and why it matters

#139 argues that a typed `WriteException` "is part of what that swallow was
designed around; a `DriverException` escaping a lower layer is not". Read at the
call site, that is not so. The swallow is `NegotiationPipeline::record()`:

```php
try {
    $this->recorder->finish($pass, $error);
} catch (\Throwable $e) {
    $this->logger->error('The negotiation pass could not be recorded; the pass itself stands.', ...);
}
```

`\Throwable`, not `WriteException` — and `DecisionRecorder::finish()` itself
catches nothing at all. A `DriverException` from the write is caught, logged and
dropped on exactly the same path as a `WriteException` would be, which is what
`RecordedPassTest::testAnAuditWriteFailureDoesNotFailThePass` already pins
(it throws a bare `RuntimeException` from the writer). The audit swallow is
therefore not at risk, and the only live cost of the current state is the red
test itself — which is cost enough, because a permanently-red suite trains
people to skim it, which is how #81 happened.

The corrected reasoning belongs in the test's docblock, so the next reader does
not re-derive it from the issue.

### Change

One file, `tests/Integration/DecisionRecordTest.php`:

- Rename to `testAStringLongerThanItsColumnIsRejectedByTheDatabaseNotTheDal`.
  The old name claims a layer that does not do the rejecting.
- Assert the exception that actually escapes: a
  `Doctrine\DBAL\Exception\DriverException` whose SQLSTATE is `22001` and whose
  message names `band`. SQLSTATE is the stable half of that pair; the message
  text is asserted only for the column name, so a different column's overflow
  cannot satisfy this test.
- Use try/catch/`self::fail()` rather than `expectException`, so both facts can
  be asserted on the same throw.
- Docblock carrying: why the DAL cannot, that the migration is the real
  constraint, that every sibling column is in the same position, that the
  audit swallow catches `\Throwable` so this exception class costs the pass
  nothing, and a pointer to `CoreFloorCompatibilityTest` as the guard that
  keeps `maxLength` out.

**RED check.** Shorten the written string to 32 characters, which the column
accepts: the test must fail with "the database accepted a 33-character band".

## Part 2 — #141: five test files silently stopped exercising the reword path

### Measured

Each of the nine scripted replies was run through the real pipeline on the base
commit and the guard's own reason recorded. Every one falls back to the
deterministic template, and in every case the buyer receives
`We can bring this quote down by 5% to 950.00 EUR. The offer is valid until
2026-09-23.` — `ReplyTemplate::compose()`'s output, verbatim. The fixture expiry
on the day of measurement was `2026-09-23`:

| site | guard's reason | and after repointing the date alone |
|---|---|---|
| `NegotiationPipelineTest::testAnInBandAskIsOffered` | figure nobody authorised: `2026-09-11` | **dropped the new total** |
| `NegotiationPipelineTest::testAnAskInTheCounterBandIsCountered` | figure nobody authorised: `10` | unchanged |
| `NegotiationPipelineTest::testAPerLineTargetPriceIsNotStructuralAndStillNegotiates` | figure nobody authorised: `95` | unchanged |
| `AskMirrorTest::testAPriceAskTypedInChatIsWrittenOntoTheLine` | figure nobody authorised: `95.00` | unchanged |
| `AskMirrorTest::testAnAskWithNoPerLineTargetMirrorsNothing` | figure nobody authorised: `2026-09-11` | **dropped the new total** |
| `AskMirrorTest::testATargetTheMergerDoesNotAdoptIsNotDisplayed` | figure nobody authorised: `90.00` | unchanged |
| `RecordedOutcomePathsTest::testACounteredPassRecordsTheCounterBand` | figure nobody authorised: `10` | unchanged |
| `HistoryPipelineTest::testHistoryStaysOutOfExtractionAndReplyPrompts` | figure nobody authorised: `2026-09-11` | **dropped the new total** |
| `HistoryBoundaryTest::testMissingCustomerContinuesWithoutHistoryAndLogsWarning` | figure nobody authorised: `2026-09-11` | **dropped the new total** |

In every case `DecisionDraft::$replyPromptHash` is `null` — the composer's own
record that the template, not the model, wrote the reply.

**The stale date is not the whole defect, and on its own it is none of it.**
Five of the nine never mention `2026-09-11` as their reason at all: they state a
figure the template never wrote (`10` twice, `95`, `95.00`, `90.00`), which is #53's
figure check — the two-directional token comparison that replaced three
`str_contains()` calls — rejecting them, not #57's clock-relative expiry. Those
five have been falling back for longer than #141 supposes.

The remaining four do fail on the date first, but repointing the date is
measured to fix **none of them**: with the live expiry substituted and nothing
else changed, all four go on to fail with "it dropped the new total", because
`We can offer 5% off. Valid until …` never states the 950.00 the template
wrote. The guard reports only its first objection, which is what made the date
look like the whole story.

Why the scripted replies cannot simply say what the offer says: the reply's
three facts are measured on the **database's** totals, not the offer's. The
harness re-reads 950.00 against a 1000.00 opening, so every one of these passes
reduces by 5% to 950.00 EUR regardless of whether the offer claimed 5%, 10% or
95.00 per unit. `ReplyTemplate::reduction()` is what makes that true and
`RewordingGuard` is what makes it enforced.

### Route taken, per file

The issue names two legitimate routes, and `RewordingGuardTest`'s docblock
records when the second is right: *when the test builds no quote and reaches no
verifier*. Each of the five files was checked against that test, and the answer
is the same in all five — **route one, point the scripted reply at the fixture's
live expiry** — for a reason worth stating rather than assuming:

Every one of these tests reaches the reply through `PipelineHarness`, which
builds its snapshots with `NegotiationFixture::snapshot()` and runs them through
`OfferVerifier`. `ReplyComposer` then takes its `$validUntil` from
`$after->lifecycle->expiresAt`, which is that same fixture expiry. There is no
seam at which such a test could hold a date of its own: giving it one would mean
a different expiry in the guard than in the quote, which is the bug, not the
fix. `RewordingGuardTest` can take route two precisely because it calls
`RewordingGuard::unsafeBecause()` directly and builds no quote at all.

Route one is uniform here because the code leaves no choice, not because the
files were treated as interchangeable. Where they do differ is in **what each
one asserts**, and that follows each file's own subject:

| file | asserts on | because |
|---|---|---|
| `NegotiationPipelineTest` (3 tests) | `$harness->gateway->comments` | the file's subject is what a pass does to the quote |
| `AskMirrorTest` (3 tests) | `$harness->gateway->comments` | same; the mirror assertions already read the gateway |
| `RecordedOutcomePathsTest` (1 test) | `$harness->writer->drafts[0]->replyToBuyer` | the file's whole subject is the audit draft, and `replyToBuyer` is the draft's copy of the same string |
| `HistoryPipelineTest` (1 test) | `$h->gateway->comments` | the file's subject is prompt contents, but the reply is the third call it counts |
| `HistoryBoundaryTest` (1 test) | `$h->gateway->comments` | same |

### The shared builder

The correct idiom already exists in this repo, hand-spelled four times —
`RecordedPassTest` (three copies) and `OfferRoundTest` (one) both write
`'We can bring this quote down by 5% to 950.00 EUR, valid until '
. NegotiationFixture::expires() . '.'` and pass. Thirteen sites spelling out a
fixture string whose figures must track two other defaults is the duplication
that produced this defect, so the idiom is hoisted once:

```php
PipelineHarness::rewordedReply(float $afterNet = ..., float $beforeNet = ...): string
```

On `PipelineHarness` rather than `NegotiationFixture`, because the figures are
the harness's own — `$beforeNet` is `NegotiationFixture::snapshot()`'s default
total and `$afterNet` is `with()`'s default re-read — and `NegotiationFixture`
must not depend on the harness. The two defaults become named constants so the
builder and the harness cannot drift apart. The string itself is built with
`ReplyTemplate::percent()`, `ReplyTemplate::money()` and
`ReplyTemplate::reduction()`, so it tracks the production formatters that
`RewordingGuard` compares against, and it is deliberately **not** equal to
`ReplyTemplate::compose()`'s output — a reworded reply that were byte-identical
to the fallback would make the assertion unable to tell them apart, which is the
defect again.

The four pre-existing copies are converted to the builder in the same commit.

### What is deliberately not changed

Six of the lines #141 lists are not scripted *replies*. They are the
`message` field of a scripted `{"action":"offer", ...}` negotiate answer:
`NegotiationPipelineTest` 19 and 98, `AskMirrorTest` 27, 95 and 114,
`RecordedOutcomePathsTest` 24. That string becomes
`ProposedAnswer::$modelMessage`, which is read by nothing in `src/` — grep finds
one reader, `OfferProposerTest`, asserting it round-trips. `RewordingGuard`'s
docblock already says so: "the negotiate call's `modelMessage` is discarded."

The date in those six is inert. Changing it would be churn that makes the diff
look like it fixed more than it did; leaving it is recorded here so the next
reader does not mistake it for an oversight.

### RED check

Route-one fixes are exactly the fixes that can be faked, so each touched test is
proven able to fail before the work is called done. With the fix in place,
`RewordingGuard::unsafeBecause()` is temporarily made to return a reason
unconditionally — the fallback then fires on every pass, which is the state the
suite is in today — and the full unit suite is run. Every test touched by this
change must fail; if one still passes, its assertion does not observe the reword
path and is rewritten. The guard is then restored and the suite re-run green.

## Testing

Gates, in order: `composer run test`, `composer run quality`,
`composer run test:integration`. `test:integration` syncs this checkout into
`merchant-quote-shop` and runs there; other agents run the same suite
concurrently, so a failure is re-run once before it is believed.

## Commits

Two, revertible independently:

1. `test(audit): assert the band column's real constraint` — #139, one file.
2. `test(negotiation): make the scripted replies survive the rewording guard` —
   #141, eight files: the five #141 names, `PipelineHarness` for the builder,
   and `RecordedPassTest` / `OfferRoundTest` for the four copies it replaces.
