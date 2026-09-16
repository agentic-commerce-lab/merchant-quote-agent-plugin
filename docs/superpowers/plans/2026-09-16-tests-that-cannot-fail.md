# Tests That Cannot Fail — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `DecisionRecordTest`'s column-width test assert what the code
actually guarantees, and make nine scripted model replies survive
`RewordingGuard` so the tests that script them exercise the reword path again —
with an assertion each that fails if they stop.

**Architecture:** Tests only; no production code changes. #139 swaps an
unreachable `WriteException` expectation for the `DriverException` the database
really raises, with a docblock recording why the DAL cannot do better at the
6.7.1 support floor. #141 hoists the repo's existing correct fixture-reply idiom
into `PipelineHarness::rewordedReply()`, points every scripted reply at it, and
adds one exact-string assertion per test so a silent fallback fails.

**Tech Stack:** PHP 8.3, PHPUnit 11, Shopware 6.7 DAL, Doctrine DBAL 4.4, mago
(format + lint + analyze).

**Spec:** `docs/superpowers/specs/2026-09-16-tests-that-cannot-fail-design.md`

## Global Constraints

- `declare(strict_types=1)` in every file; the files touched already have it.
- Support floor is Shopware **6.7.1**. Do **not** add `maxLength:` (or any other
  argument) to a `#[Field]` / `#[Entity]` attribute — it is an `Error` during
  the container build at the floor, and `CoreFloorCompatibilityTest` fails it.
- No production (`src/`) file is modified by this plan. If a task seems to need
  one, stop and report instead.
- Branch: `fix/139-141-tests-that-cannot-fail`. Two commits, #139 and #141
  separate, each ending with
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Do **not** push, open a PR, or comment on the issues.
- `git stash` is shared across worktrees — never use it. Use a WIP commit.
- Run git as `/usr/bin/git` (a shell hook rewrites bare `git`).
- `composer run test:integration` syncs this checkout into the
  `merchant-quote-shop` container and runs there; other agents run it
  concurrently, so re-run once before believing a failure.

---

### Task 1: #139 — assert the constraint the database actually enforces

**Files:**
- Modify: `tests/Integration/DecisionRecordTest.php` (import block; the
  `testAStringLongerThanItsColumnIsRejectedAtWriteTime` method, lines 51–64)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing other tasks rely on.

- [ ] **Step 1: Confirm the test is red for the stated reason**

Run:

```bash
composer run test:integration -- --filter 'DecisionRecordTest::testAStringLongerThanItsColumnIsRejectedAtWriteTime'
```

Expected: FAIL — `Failed asserting that exception of type
"Doctrine\DBAL\Exception\DriverException" matches expected exception
"…\Write\WriteException". Message was: "…SQLSTATE[22001]: String data, right
truncated: 1406 Data too long for column 'band' at row 1"`.

If it fails differently, stop and report.

- [ ] **Step 2: Swap the import**

In the `use` block of `tests/Integration/DecisionRecordTest.php`, delete:

```php
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
```

and add (imports in this file are alphabetical by FQCN, so this goes first,
above `use MerchantQuoteAgentPlugin\Audit\DecisionDraft;`):

```php
use Doctrine\DBAL\Exception\DriverException;
```

`WriteException` is used nowhere else in the file — verify with
`grep -n WriteException tests/Integration/DecisionRecordTest.php` before
deleting, and expect no remaining hits after.

- [ ] **Step 3: Replace the test method**

Replace the whole of `testAStringLongerThanItsColumnIsRejectedAtWriteTime`
(from its `public function` line to its closing brace) with:

```php
    /**
     * The column's width is enforced by MySQL, and by nothing above it.
     *
     * The DAL cannot do this job on this entity, and that is not an oversight
     * waiting to be corrected. `QuoteDecisionRecord` is an attribute entity,
     * so the only place a field definition could carry a length is
     * `#[Field(..., maxLength: 32)]` — and `Attribute\Field::$maxLength` does
     * not exist at the 6.7.1 support floor (absent up to and including
     * 6.7.4.2, present by 6.7.13.1, where it defaults to 255 and so would not
     * reject 33 characters even there). A named argument for a parameter the
     * installed core does not declare is an `Error` at attribute
     * instantiation, which happens while core builds the container: it takes
     * the whole shop down, not just this plugin. That is what
     * `CoreFloorCompatibilityTest::testDalAttributeArgumentsExistAtTheSupportFloor`
     * exists to prevent, and the thirteen columns `maxLength` was once used on
     * are why it was written.
     *
     * So the migrations hold the whole of the constraint —
     * `Migration1787998662CreateQuoteAgentDecision` for every column here, and
     * `Migration1789000000AddEscalationResolution` for `resolved_state`. This
     * test writes to `band` because VARCHAR(32) is narrow enough that a
     * plausible value overruns it, not because it is the narrowest column —
     * `currency_iso` is VARCHAR(3). Every string column on this entity is in
     * exactly the same position, bounded by its migration and by nothing in
     * PHP.
     *
     * Losing the typed `WriteException` costs a servicing pass nothing.
     * `NegotiationPipeline::record()` wraps the audit write in
     * `catch (\Throwable)` — not `catch (WriteException)` — and
     * `DecisionRecorder::finish()` catches nothing at all, so a driver-level
     * failure is logged and dropped on exactly the path a DAL-level one would
     * take. `RecordedPassTest::testAnAuditWriteFailureDoesNotFailThePass` pins
     * that with a bare `RuntimeException`.
     *
     * Asserted on the SQLSTATE rather than on the message: `22001` is the
     * standard's "string data, right truncated" and survives MySQL rewording
     * its 1406, while the message is read only for the column name, so another
     * column overflowing cannot satisfy this test. Bounding these values before
     * they reach the database at all is #60's scope.
     */
    public function testAStringLongerThanItsColumnIsRejectedByTheDatabaseNotTheDal(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');

        self::assertInstanceOf(EntityRepository::class, $repository);

        try {
            $repository->create([[
                'id' => Uuid::randomHex(),
                'quoteId' => Uuid::randomHex(),
                'band' => str_repeat('x', times: 33),
            ]], Context::createDefaultContext());

            self::fail('The database accepted 33 characters into the VARCHAR(32) band column.');
        } catch (DriverException $e) {
            self::assertSame('22001', $e->getSQLState(), 'Expected SQLSTATE 22001, string data right truncated.');
            self::assertStringContainsString('band', $e->getMessage(), 'The rejection must name the column.');
        }
    }
```

`self::fail()` throws `PHPUnit\Framework\AssertionFailedError`, which is not a
`DriverException`, so the `catch` cannot swallow it.

- [ ] **Step 4: Run the test green**

Run:

```bash
composer run test:integration -- --filter 'DecisionRecordTest::testAStringLongerThanItsColumnIsRejectedByTheDatabaseNotTheDal'
```

Expected: `OK (1 test, 3 assertions)`.

- [ ] **Step 5: RED check — prove the test can fail**

Temporarily change `times: 33` to `times: 32` (a width the column accepts) and
re-run the same command.

Expected: FAIL with `The database accepted 33 characters into the VARCHAR(32)
band column.`

Record the output, then restore `times: 33` and re-run to green. Do not commit
with `32` in place.

- [ ] **Step 6: Run the whole integration file**

```bash
composer run test:integration -- --filter DecisionRecordTest
```

Expected: all tests pass. If something unrelated fails, re-run once (other
agents share the container) before reporting it.

- [ ] **Step 7: Format and lint**

```bash
composer run format:check
composer run lint
```

Expected: both clean. If `format:check` complains, run `composer run format`
and re-check.

- [ ] **Step 8: Commit**

```bash
/usr/bin/git add tests/Integration/DecisionRecordTest.php
/usr/bin/git commit -F - <<'EOF'
test(audit): assert the band column's real constraint (#139)

The test expected a DAL WriteException and got a Doctrine DriverException:
SQLSTATE[22001] Data too long for column 'band'. It has been red on a
correctly-configured shop for a while.

The DAL cannot do better here. QuoteDecisionRecord is an attribute entity,
and Attribute\Field::$maxLength does not exist at the 6.7.1 support floor
(absent through 6.7.4.2, present by 6.7.13.1, where it defaults to 255 and
would not reject 33 characters anyway). Passing an argument the installed
core does not declare is an Error during the container build, which takes
the whole shop down -- which is exactly what CoreFloorCompatibilityTest
was written to prevent. Every string column on this entity is in the same
position: the migrations are the only constraint that holds.

So the test now asserts what is guaranteed -- SQLSTATE 22001 naming the
column -- and its docblock says why the DAL cannot.

The issue's worry about the audit swallow does not hold either way:
NegotiationPipeline::record() catches \Throwable, not WriteException, so a
driver-level failure is logged and dropped on the same path.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 2: #141 — one builder for the fixture reply, and constants behind it

**Files:**
- Modify: `tests/Unit/Negotiation/NegotiationFixture.php` (add
  `DEFAULT_TOTAL_NET`, use it in `snapshot()`'s default)
- Modify: `tests/Unit/Negotiation/PipelineHarness.php` (add `AFTER_NET`, use it
  in `with()` and `withTotals()`; add `rewordedReply()`)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces, for Task 3:
  - `NegotiationFixture::DEFAULT_TOTAL_NET` — `float`, `1000.0`
  - `PipelineHarness::AFTER_NET` — `float`, `950.0`
  - `PipelineHarness::rewordedReply(float $afterNet = self::AFTER_NET, float $beforeNet = NegotiationFixture::DEFAULT_TOTAL_NET, string $currencyIso = 'EUR'): string`
    — called with no arguments everywhere in Task 3. With the defaults it
    returns `We can bring this quote down by 5% to 950.00 EUR, valid until
    <NegotiationFixture::expires()>.`

- [ ] **Step 1: Name the fixture's opening total**

In `tests/Unit/Negotiation/NegotiationFixture.php`, add this constant directly
above `public static function expires(): string`:

```php
    /**
     * What a fixture quote opens at, named because two other things have to
     * agree with it: `PipelineHarness::AFTER_NET` is the total a pass re-reads
     * against it, and `PipelineHarness::rewordedReply()` states the reduction
     * between the two. A literal in three places is how the scripted replies
     * in #141 drifted away from the quotes they answer.
     */
    public const DEFAULT_TOTAL_NET = 1000.0;
```

Then change `snapshot()`'s signature default from `float $totalNet = 1000.0,`
to `float $totalNet = self::DEFAULT_TOTAL_NET,`.

- [ ] **Step 2: Name the harness's re-read total**

In `tests/Unit/Negotiation/PipelineHarness.php`, add this constant as the first
member of the class, directly above `public OfferRound $round;`:

```php
    /**
     * What `with()` serves as the post-apply re-read, so a default pass comes
     * down from `NegotiationFixture::DEFAULT_TOTAL_NET` by exactly 5%. Named
     * because `rewordedReply()` below has to state that same figure.
     */
    public const AFTER_NET = 950.0;
```

Then, in `with()`, change `float $reReadTotalNet = 950.0,` to
`float $reReadTotalNet = self::AFTER_NET,`; and in `withTotals()`, change
`float $beforeNet = 1000.0,` to
`float $beforeNet = NegotiationFixture::DEFAULT_TOTAL_NET,`.

- [ ] **Step 3: Add the builder**

Add this method to `PipelineHarness`, directly below `with()` (after `with()`'s
closing brace, before the class's closing brace), and add
`use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;` to the file's import
block (alphabetically, between `PromptComposer` and `ReplyComposer`):

```php
    /**
     * A rewording `RewordingGuard` accepts: exactly the three facts
     * `ReplyTemplate` wrote for this harness's totals, and no fourth figure.
     *
     * A scripted reply is the only thing standing between these tests and the
     * reword path, and the guard's verdict is invisible from outside. A
     * rejected rewording is not an error — it is the deterministic template
     * arriving instead, with the outcome, the model-call count and every
     * gateway write unchanged. That is how five test files spent months
     * exercising the fallback while their names said otherwise (#141).
     *
     * So the figures are computed by the production formatters rather than
     * typed out. `percent()`, `money()` and `reduction()` are the very
     * functions `RewordingGuard` compares a rewording against, and
     * `NegotiationFixture::expires()` is the expiry the fixture quote actually
     * carries — clock-relative since #57 — so this string cannot drift away
     * from the template the way a hardcoded `2026-09-11` did.
     *
     * Deliberately NOT `ReplyTemplate::compose()`'s own sentence. A scripted
     * reply identical to the fallback ships either way, so an assertion on it
     * could not tell the reword path from the template path — which is the
     * defect, not the fix. This one differs in its final clause and is
     * accepted by the guard, which `RewordingGuardTest` pins for exactly this
     * shape.
     */
    public static function rewordedReply(
        float $afterNet = self::AFTER_NET,
        float $beforeNet = NegotiationFixture::DEFAULT_TOTAL_NET,
        string $currencyIso = 'EUR',
    ): string {
        return sprintf(
            'We can bring this quote down by %s%% to %s %s, valid until %s.',
            ReplyTemplate::percent(ReplyTemplate::reduction($beforeNet, $afterNet)),
            ReplyTemplate::money($afterNet),
            $currencyIso,
            NegotiationFixture::expires(),
        );
    }
```

- [ ] **Step 4: Prove the builder produces the string the repo already uses**

The idiom already exists hand-spelled in `RecordedPassTest` (lines 58, 251, 280).
Replace those three occurrences of

```php
            'We can bring this quote down by 5% to 950.00 EUR, valid until ' . NegotiationFixture::expires() . '.',
```

with

```php
            PipelineHarness::rewordedReply(),
```

and do the same for `OfferRoundTest`'s copy (lines 243–245, the three-line
concatenation ending `. '.',` inside
`testEveryPassEndsInOneStructuredEventCarryingThePromptHashes`).

Both files already reference `PipelineHarness`; no new import is needed. If
`NegotiationFixture` becomes unused in either file after the edit, leave the
import only if other code in the file still uses it — check with
`grep -n NegotiationFixture <file>` and remove the `use` line only if there are
no remaining uses.

- [ ] **Step 5: Run the two converted files**

```bash
composer run test -- --filter 'RecordedPassTest|OfferRoundTest'
```

Expected: PASS, with the same test and assertion counts as before the edit. If
anything fails, the builder's string does not match the idiom — stop and report
the diff rather than adjusting the assertion.

- [ ] **Step 6: Format, lint, and run the whole unit suite**

```bash
composer run format:check
composer run lint
composer run test
```

Expected: all clean and green. `composer run typecheck` is scoped to `src` only
and is not affected by this task.

- [ ] **Step 7: Do NOT commit yet**

Task 3 lands in the same commit. Leave the working tree dirty and report the
files changed.

---

### Task 3: #141 — point the nine scripted replies at the builder and assert the comment

**Files:**
- Modify: `tests/Unit/Negotiation/NegotiationPipelineTest.php` (3 tests)
- Modify: `tests/Unit/Negotiation/AskMirrorTest.php` (3 tests)
- Modify: `tests/Unit/Negotiation/RecordedOutcomePathsTest.php` (1 test)
- Modify: `tests/Unit/Negotiation/HistoryPipelineTest.php` (1 test)
- Modify: `tests/Unit/Negotiation/HistoryBoundaryTest.php` (1 test)
- Modify: `tests/Unit/Negotiation/RecordedPassTest.php` (1 test — see Step 7)

**Interfaces:**
- Consumes: `PipelineHarness::rewordedReply()` from Task 2, called with no
  arguments at every site below.
- Produces: nothing later tasks rely on.

Throughout this task: the **third** entry of a `PipelineHarness::with([...])`
script is the reply call. Change only that entry. Leave the second entry — the
`{"action":"offer","message":"…"}` JSON — exactly as it is, including its
`2026-09-11`: that string becomes `ProposedAnswer::$modelMessage`, which is read
by nothing in `src/` (`RewordingGuard`'s docblock: "the negotiate call's
`modelMessage` is discarded"), so changing it would be churn.

- [ ] **Step 1: `NegotiationPipelineTest::testAnInBandAskIsOffered`**

Change the third script entry from
`'We can offer 5% off. Valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),`, and add this assertion after
`self::assertSame(3, $harness->spy->calls);`:

```php
        // Not just "a comment was posted": the model's rewording, exactly.
        // The deterministic template arriving here instead is not an error --
        // it is RewordingGuard rejecting the script, silently, with the
        // outcome and the call count above unchanged (#141).
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
```

Add `use` for `PipelineHarness` only if the file does not already reference it —
it is in the same namespace (`MerchantQuoteAgentPlugin\Tests\Unit\Negotiation`),
so no import is needed in any file in this task.

- [ ] **Step 2: `NegotiationPipelineTest::testAnAskInTheCounterBandIsCountered`**

Change the third script entry from
`'Our best is 10%. Valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),`, and add after
`self::assertSame(NegotiationOutcome::Countered, $outcome);`:

```php
        // 5% and 950.00, not the 10% the counter offered: the reply states
        // what the DATABASE came down by (ReplyTemplate::reduction over the
        // harness's re-read), and RewordingGuard rejects any other figure.
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
```

- [ ] **Step 3: `NegotiationPipelineTest::testAPerLineTargetPriceIsNotStructuralAndStillNegotiates`**

Change the third script entry from `'95 each, valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),`, and add after
`self::assertNotSame(NegotiationOutcome::Escalated, $outcome);`:

```php
        // A per-line concession is announced as the quote-wide reduction the
        // database reports; `95` is a figure the template never wrote, and a
        // reply stating it falls back to the template without failing here.
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
```

- [ ] **Step 4: `AskMirrorTest`, all three scripted replies**

In `testAPriceAskTypedInChatIsWrittenOntoTheLine`, change
`'We can do 95.00 per unit. Valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),` and add after
`self::assertSame([95.0], self::mirroredPrices($harness));`:

```php
        // The mirror is what this file is about, but the reply is the third
        // model call it pays for: without this, a rewording the guard rejects
        // leaves the assertions above untouched (#141).
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
```

In `testAnAskWithNoPerLineTargetMirrorsNothing`, change
`'We can offer 5% off. Valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),` and add after
`self::assertSame([], self::markerWrites($harness));`:

```php
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
```

In `testATargetTheMergerDoesNotAdoptIsNotDisplayed`, change
`'We can do 90.00 per unit. Valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),` and add after
`self::assertSame([], self::mirroredPrices($harness));`:

```php
        // 90.00 is the buyer's stored ask, not a figure the reply may state:
        // the quote came down 5% to 950.00, and that is all the buyer is told.
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
```

- [ ] **Step 5: `RecordedOutcomePathsTest::testACounteredPassRecordsTheCounterBand`**

Change the third script entry from
`'Our best is 10%. Valid until 2026-09-11.',` to
`PipelineHarness::rewordedReply(),` and add after
`self::assertSame('counter', $harness->writer->drafts[0]->band);`:

```php
        // Asserted on the draft rather than the gateway, because the draft is
        // this file's subject -- and it is the same string either way.
        // `replyToBuyer` null-or-template is how a rejected rewording shows up
        // in the audit trail, and nothing else here would notice (#141).
        self::assertSame(PipelineHarness::rewordedReply(), $harness->writer->drafts[0]->replyToBuyer);
```

- [ ] **Step 6: The two history tests**

In `tests/Unit/Negotiation/HistoryPipelineTest.php`,
`testHistoryStaysOutOfExtractionAndReplyPrompts`: change
`'5% off, valid until 2026-09-11.',` to `PipelineHarness::rewordedReply(),` and
add as the last assertion of the method:

```php
        // The prompts above are this test's subject; this pins that the reply
        // call's ANSWER still reaches the buyer, rather than being replaced by
        // the template while all three prompt assertions stay green (#141).
        self::assertSame([PipelineHarness::rewordedReply()], $h->gateway->comments);
```

In `tests/Unit/Negotiation/HistoryBoundaryTest.php`,
`testMissingCustomerContinuesWithoutHistoryAndLogsWarning`: change
`[HistoryProposerHarness::offer(), '5% off, valid until 2026-09-11.'],` to
`[HistoryProposerHarness::offer(), PipelineHarness::rewordedReply()],` and add
as the last assertion of the method:

```php
        // A pass that continued without history still answers the buyer in the
        // model's words, not the fallback's.
        self::assertSame([PipelineHarness::rewordedReply()], $h->gateway->comments);
```

- [ ] **Step 7: Make `RecordedPassTest`'s name true**

`testAnOfferedPassWritesOneRecordCarryingWhatTheBuyerWasTold` scripts
`'We can offer 5% off.'` — which the guard rejects — and then asserts nothing
about what the buyer was told. Change its third script entry to
`PipelineHarness::rewordedReply(),` and add after
`self::assertSame('grant', $draft->band);`:

```php
        // The clause in this test's name, actually checked: the record has to
        // carry the sentence the buyer received, not merely exist.
        self::assertSame(PipelineHarness::rewordedReply(), $draft->replyToBuyer);
```

Leave `testAnAuditWriteFailureDoesNotFailThePass` (line ~221) alone: its subject
is the write failure, the template is a fine reply there, and it claims nothing
about wording.

- [ ] **Step 8: Run the unit suite green**

```bash
composer run test
```

Expected: PASS. Every one of the ten assertions added above must pass; if one
fails on a figure, do not weaken the assertion — report the guard's reason,
which `RecordingLogger` captures under the message "did not survive the guard".

- [ ] **Step 9: RED check — break the guard, watch every touched test fail**

This is the step the whole task exists for. In `src/Negotiation/RewordingGuard.php`,
temporarily make `unsafeBecause()` reject everything by inserting, as its first
statement:

```php
        return 'RED CHECK';
```

Then run:

```bash
composer run test -- --filter 'NegotiationPipelineTest|AskMirrorTest|RecordedOutcomePathsTest|HistoryPipelineTest|HistoryBoundaryTest|RecordedPassTest|OfferRoundTest'
```

Expected: FAIL. Record the failing test names and confirm the list contains all
ten tests changed in Steps 1–7:

1. `NegotiationPipelineTest::testAnInBandAskIsOffered`
2. `NegotiationPipelineTest::testAnAskInTheCounterBandIsCountered`
3. `NegotiationPipelineTest::testAPerLineTargetPriceIsNotStructuralAndStillNegotiates`
4. `AskMirrorTest::testAPriceAskTypedInChatIsWrittenOntoTheLine`
5. `AskMirrorTest::testAnAskWithNoPerLineTargetMirrorsNothing`
6. `AskMirrorTest::testATargetTheMergerDoesNotAdoptIsNotDisplayed`
7. `RecordedOutcomePathsTest::testACounteredPassRecordsTheCounterBand`
8. `HistoryPipelineTest::testHistoryStaysOutOfExtractionAndReplyPrompts`
9. `HistoryBoundaryTest::testMissingCustomerContinuesWithoutHistoryAndLogsWarning`
10. `RecordedPassTest::testAnOfferedPassWritesOneRecordCarryingWhatTheBuyerWasTold`

plus `OfferRoundTest::testEveryPassEndsInOneStructuredEventCarryingThePromptHashes`,
whose `replyPromptHash` assertion already observed this path before this change.

**Any of those eleven that still passes has an assertion that cannot see the
reword path** — rewrite it and repeat this step.

The two other `RecordedPassTest` tests converted in Task 2 Step 4
(`testALoggerFailureDoesNotFailASuccessfulPass` and
`testALoggerFailureDoesNotReplaceTheOriginalExceptionOnAFailedPass`) are
expected to keep passing here. They were de-duplicated, not given coverage:
their subject is the logger, and the template is a correct reply for them.

Then remove the `return 'RED CHECK';` line, re-run `composer run test`, and
confirm green. Verify with
`/usr/bin/git diff --stat src/` that `src/` is untouched before committing.

- [ ] **Step 10: Format and lint**

```bash
composer run format:check
composer run lint
```

Expected: clean.

- [ ] **Step 11: Commit Tasks 2 and 3 together**

```bash
/usr/bin/git add tests/Unit/Negotiation/
/usr/bin/git commit -F - <<'EOF'
test(negotiation): make the scripted replies survive the rewording guard (#141)

Nine scripted model replies across five files were being rejected by
RewordingGuard, so ReplyComposer fell back to the deterministic template
and the tests exercised the fallback while their names said reword. None
of them failed, because all of them asserted on the outcome and the
model-call count and never on the comment.

Measured through the real pipeline: four fail on #57's clock-relative
expiry (the hardcoded 2026-09-11 is now "a figure nobody authorised"),
and five fail on a figure the template never wrote -- 10, 95, 95.00,
90.00 -- which is #53's figure check, not the date. Repointing the date
alone fixes none of the four: they go on to fail "it dropped the new
total", because the guard reports only its first objection.

Route taken, and why it is the same in all five files: every one of these
tests reaches the reply through PipelineHarness, so its expiry comes from
NegotiationFixture::snapshot() and reaches RewordingGuard through
ReplyComposer's $after->lifecycle->expiresAt. There is no seam at which
such a test could hold a date of its own -- that is why RewordingGuardTest
could take the other route and these cannot. What does differ per file is
what each one asserts: the pipeline and mirror tests assert on the
gateway's comment, RecordedOutcomePathsTest and RecordedPassTest on the
audit draft's replyToBuyer, because the draft is their subject.

The correct idiom already existed hand-spelled four times in
RecordedPassTest and OfferRoundTest; it is hoisted into
PipelineHarness::rewordedReply(), built from ReplyTemplate's own
formatters and NegotiationFixture::expires() so it cannot drift again,
and deliberately not equal to ReplyTemplate::compose() so an assertion
can tell reword from fallback. NegotiationFixture::DEFAULT_TOTAL_NET and
PipelineHarness::AFTER_NET name the two totals it reads.

RecordedPassTest::testAnOfferedPassWritesOneRecordCarryingWhatTheBuyerWasTold
now checks the clause in its own name.

Unchanged on purpose: the 2026-09-11 inside the scripted
{"action":"offer","message":...} answers. That string becomes
ProposedAnswer::$modelMessage, which nothing in src/ reads.

RED-checked: with RewordingGuard::unsafeBecause() forced to reject, every
test touched here fails.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 4: Full gates

**Files:** none modified.

**Interfaces:** consumes the two commits from Tasks 1 and 3.

- [ ] **Step 1: Unit suite**

```bash
composer run test
```

Expected: `OK`, no failures, no errors.

- [ ] **Step 2: Quality aggregate**

```bash
composer run quality
```

Expected: every sub-step passes — `format:check`, `lint`, `typecheck`,
`quality:filesize`, `quality:admin`, `quality:dupes`, `quality:depcheck`,
`quality:security`. `quality:dupes` (jscpd) is the one worth watching: this
change removes duplication rather than adding it, so a new finding there is a
real regression.

- [ ] **Step 3: Integration suite**

```bash
composer run test:integration
```

Expected: green. Other agents run this against the same container
concurrently — re-run once before treating any failure as real, and compare a
suspected pre-existing failure against the base commit by checking it out
detached (never `git stash`).

- [ ] **Step 4: Confirm the shape of the work**

```bash
/usr/bin/git log --oneline fix/139-141-tests-that-cannot-fail
/usr/bin/git diff --stat HEAD~2
/usr/bin/git diff HEAD~2 -- src/
```

Expected: three commits on top of the base (spec, #139, #141), and the last
command prints nothing — this plan changes no production code.

## Self-Review

**Spec coverage.** Part 1's "Change" list → Task 1 Steps 2–3; its RED check →
Task 1 Step 5. Part 2's per-file route table → Task 3 Steps 1–6; the shared
builder and its constants → Task 2 Steps 1–3; the four pre-existing copies →
Task 2 Step 4; "what is deliberately not changed" → Task 3's preamble; the RED
check → Task 3 Step 9; the gates → Task 4. The spec's three recorded assumptions
are carried into Task 1 (no floor change), Task 3 (`assertSame` on the full
string, never a substring) and Task 2 Step 4 (convert the four copies).

**Placeholders.** None: every code step carries the literal code to write, and
every run step carries the command and the expected output.

**Type consistency.** `rewordedReply()` is defined once in Task 2 with three
optional parameters and is called with none in Tasks 2 and 3.
`NegotiationFixture::DEFAULT_TOTAL_NET` and `PipelineHarness::AFTER_NET` are
declared in Task 2 Steps 1–2 and referenced only in Task 2's own signatures and
docblocks. `DriverException::getSQLState(): ?string` is Doctrine DBAL 4.4's own
signature, checked in `vendor/doctrine/dbal/src/Exception/DriverException.php`.
