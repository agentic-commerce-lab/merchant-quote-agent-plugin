# Preflight Escalation Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the one visible agent action that happens before `NegotiationPipeline::service()` — a misconfigured quote escalated by `ServicingPreflight` — a row in `merchant_quote_agent_decision`, without making `begin`/`finish` a two-owner lifecycle.

**Architecture:** Four surfaces. `DecisionRecorder` gains `recordRefusal()`, a complete row written in one call that never touches `$this->draft`, so the pipeline keeps sole ownership of the draft lifecycle. A source-scan test pins that ownership so the widening stays checkable. `ServicingPreflight` takes the recorder and calls it inside its `InvalidQuoteAgentConfiguration` catch, guarded so the audit write can never fail servicing. `ServiceQuoteHandler` builds the `PassContext` ahead of the preflight so the refusal row and a serviced pass's row agree about trigger and attempt.

**Tech Stack:** PHP 8.3, Shopware 6.7 plugin, PHPUnit 11, mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-09-16-preflight-escalation-record-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file. Enforced by mago, level error, not disableable.
- Cyclomatic complexity ≤ 10 **class-scoped**; parameter lists ≤ 5; nesting ≤ 4; 400 physical lines per file.
- Docblocks say **why**, grounded in measured evidence, and match the surrounding files. Read the neighbours before writing.
- `DecisionRecorder` stays plain PHP with no Shopware dependency. `DecisionRecordWriter` remains the only class in `Audit` that touches the DAL.
- **Never write the string `->begin(` or `->finish(` into a docblock in `src/`** — Task 2 scans for exactly those two spellings. Write "begin()" / "finish()" in prose instead.
- Gates that must be green at the end: `composer run test`, `composer run quality`, `composer run test:integration`.
- The integration shop `merchant-quote-shop` is shared with other agents. Do not migrate it, do not seed it, do not mutate its config outside a test's own transaction.
- Every commit message ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Branch is `feat/35-record-preflight-escalations`. Do not push, do not open a PR, do not touch the GitHub issue.
- Run git as `/usr/bin/git` (the bare name is rewritten by a shell hook that this worktree refuses).

---

### Task 1: `DecisionRecorder::recordRefusal()`

One complete row, written in one call, for an escalation that happened before a pass could start.

**Files:**
- Modify: `src/Audit/DecisionRecorder.php`
- Test: `tests/Unit/Audit/DecisionRecorderTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `DecisionRecorder::recordRefusal(QuoteSnapshot, PassContext, QuoteEscalationReason, list<string>): void`. Task 3 calls it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Audit/DecisionRecorderTest.php` (the class already has a private `self::context()` helper and uses `NegotiationFixture::snapshot()`):

```php
    public function testARefusalBeforeAnyPassWritesOneEscalatedRecord(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->recordRefusal(
            NegotiationFixture::snapshot(),
            self::context(),
            QuoteEscalationReason::NotConfigured,
            ['No LLM API key is set.'],
        );

        self::assertCount(1, $writer->drafts);
        $draft = $writer->drafts[0];
        self::assertSame('q1', $draft->quoteId);
        self::assertSame('escalated', $draft->outcome);
        self::assertSame('not_configured', $draft->escalationReason);
        self::assertSame(['No LLM API key is set.'], $draft->violations);
        // A pass that did not run has no duration. #21 reads durationMs as
        // servicing latency and takes p50/p95 over it; a sub-millisecond
        // refusal folded into that distribution would deflate both.
        self::assertNull($draft->durationMs);
        self::assertNull($draft->band);
        self::assertNull($draft->model);
    }

    public function testARefusalDoesNotDisturbAPassThatIsAlreadyOpen(): void
    {
        // The failure mode the design claims is impossible: recordRefusal()
        // never reads or assigns the draft, so it cannot swallow, truncate or
        // duplicate a pass that is mid-flight. Asserted rather than argued --
        // this is the whole reason a second write path was affordable.
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordReply('We can do 5%.', 'reply-hash');
        $recorder->recordRefusal(
            NegotiationFixture::snapshot(),
            self::context(),
            QuoteEscalationReason::NotConfigured,
            [],
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertCount(2, $writer->drafts);
        self::assertSame('escalated', $writer->drafts[0]->outcome);
        self::assertNull($writer->drafts[0]->replyToBuyer);
        self::assertNull($writer->drafts[0]->violations, 'An empty problem list is not a violation.');
        self::assertSame('offered', $writer->drafts[1]->outcome);
        self::assertSame('We can do 5%.', $writer->drafts[1]->replyToBuyer);
    }
```

Add `use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;` to the imports.

Run `composer run test -- --filter DecisionRecorderTest` and confirm both fail on the missing method.

- [ ] **Step 2: Implement**

In `src/Audit/DecisionRecorder.php`, extract the body of `begin()` into a private static factory and add the new method after it.

`begin()` becomes:

```php
    public function begin(QuoteSnapshot $snapshot, PassContext $context): void
    {
        $this->draft = self::draftFor($snapshot, $context);
    }

    /**
     * One record for an escalation that happened before a pass could start,
     * written whole rather than opened and closed.
     *
     * ServicingPreflight escalates a misconfigured quote -- marker, buyer
     * comment, admin notification -- and returns null, so
     * NegotiationPipeline::service() never runs and the draft lifecycle never
     * opens. Before #35 that action had no row at all: both of #7's pages read
     * this table and nothing else, so the quote was absent rather than
     * incomplete, and #21's outcome counts were short by exactly the
     * misconfigured channels the run exists to find.
     *
     * Deliberately NOT part of the draft lifecycle. It neither reads nor
     * assigns $this->draft, so NegotiationPipeline stays the only class that
     * opens and closes a record and "exactly one record per pass" remains a
     * property of one place -- RecorderOwnershipTest is what holds that line.
     *
     * Everything a pass would have measured stays null, because none of it
     * happened: no band, no model call, no duration. The problems go to
     * `violations`, which is where recordProposal() already puts an escalation
     * detail and where merchant-quote-agent-detail already renders one -- the
     * `not_configured` snippet has been promising "the technical details below
     * name the fields" to a row that did not exist.
     *
     * @param list<string> $problems the configuration's own complaints; admin-scope
     *                               only, never routed to the buyer-facing comment
     */
    public function recordRefusal(
        QuoteSnapshot $snapshot,
        PassContext $context,
        QuoteEscalationReason $reason,
        array $problems,
    ): void {
        $draft = self::draftFor($snapshot, $context);
        $draft->outcome = NegotiationOutcome::Escalated->value;
        $draft->escalationReason = $reason->value;
        $draft->violations = $problems === [] ? null : $problems;

        $this->writer->write($draft);
    }

    private static function draftFor(QuoteSnapshot $snapshot, PassContext $context): DecisionDraft
    {
        $draft = new DecisionDraft();
        // ... the existing begin() body verbatim, ending with:
        $draft->startedAt = microtime(true);

        return $draft;
    }
```

Add imports for `MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome` and
`MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason`.

Place `recordRefusal()` immediately after `begin()` — it is the second way a row
starts, and a reader comparing the two should not have to scroll.

- [ ] **Step 3: Verify**

`composer run test -- --filter DecisionRecorderTest` green; `composer run test` green (`DraftMirrorsEntityTest` must stay green — no new draft property was added).

- [ ] **Step 4: Commit**

```
feat(audit): record an escalation that happens before the pass

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

### Task 2: Pin the single-owner invariant

Runs in parallel with Task 1 — it asserts a property of `src/` that Task 1 does not change.

**Files:**
- Create: `tests/Unit/Audit/RecorderOwnershipTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a failing test the moment a second class opens a decision record.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;

/**
 * NegotiationPipeline is the only class that opens and closes a decision
 * record.
 *
 * That was a sentence in the audit design and fifteen tests that each happen
 * to see one draft. #35 added a second way a row is written --
 * DecisionRecorder::recordRefusal(), for the escalation that never reaches the
 * pipeline -- and the argument that it is safe rests entirely on it not being
 * part of the draft lifecycle. An argument that rests on a property is worth
 * what the test of that property is worth, so here it is: a scan, in one
 * place, that fails the moment a second class starts a record.
 *
 * Deliberately blunt. It matches two arrow-call spellings over a directory
 * where those two method names have exactly two call sites, and it cannot see
 * a dynamic call. It catches the mistake a person actually makes -- writing
 * `$this->recorder` and one of these two names in a new class -- and a false
 * positive is an edit to the allow-list with a reviewer looking at it, which
 * is the point rather than the cost.
 */
final class RecorderOwnershipTest extends TestCase
{
    private const OWNER = 'Negotiation/NegotiationPipeline.php';

    public function testOnlyTheNegotiationPipelineOpensAndClosesARecord(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $contents) {
            if (!str_contains($contents, '->begin(') && !str_contains($contents, '->finish(')) {
                continue;
            }

            $offenders[] = $relative;
        }

        self::assertSame(
            [self::OWNER],
            $offenders,
            'A second class opens or closes a decision record. The audit design makes '
            . 'NegotiationPipeline the sole owner of that lifecycle, which is what makes '
            . '"exactly one record per pass" provable in one place. A new row written whole, '
            . 'the way DecisionRecorder::recordRefusal() writes one, does not need it.',
        );
    }

    /** @return iterable<string, string> relative path => contents */
    private function sourceFiles(): iterable
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            yield str_replace($root . '/', '', $file->getPathname()) => $contents;
        }
    }

    public function testTheScanActuallyReachesTheSource(): void
    {
        // A path typo would make the test above pass by finding nothing, which
        // is the one way a source scan fails silently.
        self::assertGreaterThan(100, iterator_count($this->sourceFiles()));
    }
}
```

Sort `$offenders` before asserting if iteration order is not stable on this
platform — `assertSame` against a one-element list is the intent, so a `sort()`
costs nothing and removes a filesystem-ordering flake.

- [ ] **Step 2: Verify**

`composer run test -- --filter RecorderOwnershipTest` green. Then prove it bites:
temporarily add `$this->recorder->finish(null);` to any other `src/` class, re-run,
confirm it fails, revert.

- [ ] **Step 3: Commit**

```
test(audit): pin the pipeline as the sole owner of a record

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

### Task 3: `ServicingPreflight` records its escalation

Depends on Task 1.

**Files:**
- Modify: `src/Servicing/ServicingPreflight.php`
- Modify: `src/Servicing/ServiceQuoteHandler.php`
- Modify: `src/Resources/config/services.php` (the `ServicingPreflight` args, ~line 740)
- Modify: `tests/Unit/Servicing/ServicingSettingsFixture.php`
- Test: `tests/Unit/Servicing/ServicingPreflightTest.php`
- Test: `tests/Unit/Servicing/ServiceQuoteHandlerTest.php` (only if the `PassContext` move breaks an assertion)

**Interfaces:**
- Consumes: `DecisionRecorder::recordRefusal()` from Task 1.
- Produces: `ServicingPreflight::__construct` at four parameters; `check()` at three arguments, third is `PassContext`. Every caller of `check()` must pass one.

- [ ] **Step 1: Write the failing tests**

`ServicingSettingsFixture::preflight()` gains an optional writer so its callers
can read what was recorded:

```php
    /**
     * @param \Closure(): ?QuoteAgentSettings $outcome what the config source does when asked
     */
    public static function preflight(
        \Closure $outcome,
        ?QuoteEscalator $escalator = null,
        ?DecisionRecordWriterInterface $writer = null,
    ): ServicingPreflight {
        // ... existing $source ...
        return new ServicingPreflight(
            $source,
            $escalator ?? new QuoteEscalator(settingsSource: $source),
            new NullLogger(),
            new DecisionRecorder($writer ?? new FakeDecisionWriter()),
        );
    }

    public static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
    }
```

Every existing `->check($gateway, QuoteSnapshotFixture::snapshot())` in
`ServicingPreflightTest` becomes
`->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context())`.

Then add:

```php
    public function testAMisconfiguredChannelRecordsTheEscalationItJustPerformed(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $writer = new FakeDecisionWriter();

        ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        }, null, $writer)->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertCount(1, $writer->drafts);
        self::assertSame('escalated', $writer->drafts[0]->outcome);
        self::assertSame('not_configured', $writer->drafts[0]->escalationReason);
        self::assertSame(['No LLM API key is set.'], $writer->drafts[0]->violations);
        self::assertSame('comment_written', $writer->drafts[0]->triggerReason);
    }

    public function testASilentRefusalRecordsNothing(): void
    {
        // The kill switch and a terminal state are not agent actions. A row
        // per buyer comment saying the agent is paused is the noise that makes
        // an audit trail unreadable.
        $writer = new FakeDecisionWriter();
        $preflight = ServicingSettingsFixture::preflight(static fn(): ?QuoteAgentSettings => null, null, $writer);

        $preflight->check(
            new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]),
            QuoteSnapshotFixture::snapshot(),
            ServicingSettingsFixture::context(),
        );
        $preflight->check(
            new FakeQuoteGateway([QuoteSnapshotFixture::snapshot('accepted')]),
            QuoteSnapshotFixture::snapshot('accepted'),
            ServicingSettingsFixture::context(),
        );

        self::assertSame([], $writer->drafts);
    }

    public function testAnAuditWriteFailureDoesNotStopTheEscalation(): void
    {
        // Same trade as NegotiationPipeline::record(): a throw here would roll
        // the message back into Messenger's retry over a row nobody reads
        // before the quote is escalated. A missing record beats a redelivery.
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $writer = new FakeDecisionWriter();
        $writer->throws = new \RuntimeException('the audit table is gone');

        $result = ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        }, null, $writer)->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertNull($result);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
            'The escalation must stand even when its record does not.',
        );
    }
```

- [ ] **Step 2: Implement `ServicingPreflight`**

Fourth constructor parameter `private DecisionRecorder $recorder` (required, not
nullable — there is one wiring site and one fixture, and a nullable recorder
would put a `?->` on the one line that must not be skipped). Extend the class
docblock to say that the misconfigured state is the only one of the three that
records, and why.

`check()` takes `PassContext $context` third. In the `InvalidQuoteAgentConfiguration`
catch, after `escalate()`:

```php
            $this->escalator->escalate($gateway, $snapshot, QuoteEscalationReason::NotConfigured);
            $this->record($snapshot, $context, $e->problems);

            return null;
```

and a private helper, separate rather than inlined because a `try` inside the
`catch` inside `check()` would sit at the nesting cap:

```php
    /**
     * The audit write may never cost the pass. NegotiationPipeline::record()
     * makes the same trade for the same reason: a throw would roll the message
     * back into Messenger's retry, and the redelivery would re-run a preflight
     * whose escalation has already landed. A missing record beats that. The
     * error log line above is the backstop.
     *
     * After escalate(), never before: the escalation is the thing that must
     * happen and this describes it.
     *
     * @param list<string> $problems
     */
    private function record(QuoteSnapshot $snapshot, PassContext $context, array $problems): void
    {
        try {
            $this->recorder->recordRefusal($snapshot, $context, QuoteEscalationReason::NotConfigured, $problems);
        } catch (\Throwable $e) {
            $this->logger->error('The quote agent escalated a misconfigured quote but could not record it; '
            . 'the escalation itself stands.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);
        }
    }
```

- [ ] **Step 3: Implement `ServiceQuoteHandler`**

In `servicePass()`, build the context before the preflight and delete the later
construction:

```php
        $snapshot = $gateway->fetchSnapshot($message->quoteId);

        // Resolved before the preflight, not just before claimAttempt(): the
        // rule the old comment states -- a stale enum value must throw before
        // anything is mutated -- is served strictly better here, since the
        // preflight can write a marker, a comment and a notification on the
        // strength of a trigger nothing can name. The counter is read rather
        // than claimed; a refused pass claims nothing.
        $context = new PassContext(ServicingTriggerReason::from($message->reason), self::attemptsOn($snapshot));
        $settings = $this->preflight->check($gateway, $snapshot, $context);
```

`claimAttempt()` returns `void` (its return value has no remaining reader) and
reads the counter through a new `private static function attemptsOn(QuoteSnapshot $snapshot): int`
holding the two lines that were inline. Keep its existing docblock and the
`MAX_ATTEMPTS` guard exactly as they are; only the counter read and the return
type move. Update the `@return` tag to reflect the change.

- [ ] **Step 4: Wire it**

In `src/Resources/config/services.php`, add `service(DecisionRecorder::class)` as
the fourth argument of `ServicingPreflight` and import the class if it is not
already imported in that file.

- [ ] **Step 5: Verify**

`composer run test` fully green, `composer run quality` exit 0. Check in
particular that `ServiceQuoteHandlerTest::testTheCounterIsIncrementedBeforeTheHandOff`
and `testThePipelineIsToldWhyTheQuoteWasQueuedAndWhichAttemptThisIs` still pass —
they are what prove the `PassContext` move changed nothing the pipeline sees.

- [ ] **Step 6: Commit**

```
feat(servicing): give a misconfigured escalation its audit row

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

### Task 4: Prove it on the shop

Depends on Task 3.

**Files:**
- Create: `tests/Integration/PreflightEscalationRecordTest.php`

**Interfaces:**
- Consumes: the whole chain, wired by the real container.
- Produces: nothing.

**A new file rather than an extension of `ServicingConfigGateTest`, on purpose.**
That class's `testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly` is issue
#140 — it fails on a correctly-configured shop today, and a parallel agent may
be rewriting it. Editing the method this work would otherwise build on
entangles two changes that have nothing to do with each other, and #140's own
resolution may delete the two-invocation setup entirely. So: a separate file,
and **no assertion anywhere in it about how many comments the buyer received.**
The row count is this work's claim; the comment count is #140's.

- [ ] **Step 1: Write the tests**

Model the file on `ServicingConfigGateTest` — same `IntegrationTestCase` base,
same `self::config()` / `static::gateway()` / `QuoteFixture::anyQuoteId()` /
`static::preflight()` helpers, and its own copies of the small
`countingPipeline()` and `handler()` doubles. Three tests:

1. `testAMisconfiguredChannelWritesAnEscalatedRowNamingTheFieldsAtFault` — set
   `enabled` true and `llmApiKey` empty, invoke the handler once, then read the
   quote's rows back through `merchant_quote_agent_decision.repository` filtered
   on `quoteId`. Assert exactly one row, and on it: `outcome === 'escalated'`,
   `escalationReason === 'not_configured'`, `triggerReason === 'comment_written'`,
   the `quoteId` matches, `violations` non-empty. Also assert `durationMs`,
   `band` and `model` are all null — nothing ran, so nothing is claimed. Comment
   that `violations` is what #7's `not_configured` sentence means by "The
   technical details below name the fields".
2. `testEachRefusedPassIsItsOwnRow` — same setup, invoke the handler **twice**
   with the same message, assert **two** rows. Comment that the asymmetry is
   deliberate: the marker makes the buyer hear it once, the table logs passes,
   and two buyer asks went unanswered. Say in the comment that the comment count
   is #140's business and is deliberately not asserted here.
3. `testADisabledSalesChannelWritesNoDecisionRow` — `enabled` false, invoke
   once, assert zero rows. A paused agent is not an audit event.

Shared private helper:

```php
    /** @return list<string> the decision-row ids this quote has, oldest first */
    private static function decisionIds(string $quoteId): array
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addSorting(new FieldSorting('createdAt'));

        return array_values($repository->searchIds($criteria, Context::createDefaultContext())->getIds());
    }
```

Watch the class's method count against mago's `too-many-methods` ceiling of 11:
three tests plus three or four helpers is fine; do not grow it further.

- [ ] **Step 2: Verify**

`composer run test:integration -- --filter PreflightEscalationRecord`, then the
whole integration suite. #139
(`DecisionRecordTest::testAStringLongerThanItsColumnIsRejectedAtWriteTime`) and
#140 (`ServicingConfigGateTest::testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly`)
are known pre-existing failures this work does not touch. Anything else red is
checked against the base commit by a **detached checkout** — never `git stash`,
the stack is shared across worktrees — and re-run once before being called real.

- [ ] **Step 3: Commit**

```
test(integration): prove the refusal row on the real shop

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

---

### Task 5: Full verification

- [ ] `composer run test`
- [ ] `composer run quality`
- [ ] `composer run test:integration`
- [ ] Re-read `src/Audit/DecisionRecorder.php` and `src/Servicing/ServicingPreflight.php` and confirm no docblock contains the literal `->begin(` or `->finish(` (Task 2 scans for those).
