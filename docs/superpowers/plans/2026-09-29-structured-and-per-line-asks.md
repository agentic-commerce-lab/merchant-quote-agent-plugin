# Structured and Per-Line Asks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three fixes, found by the live eval runs:
- a comment-less per-line requested price is banded at its real percentage (#223);
- the negotiate model sees a per-line ask in net, next to a label for the buyer's gross figures (#222);
- a model-chosen escalation is recorded as `model_declined`, not `model_unavailable` (#222).

**Architecture:**
- **#223** is fixed at the shared policy seam `CommentTargetMerger::merge()`, which every band decision passes through: it rolls the snapshot's own per-line requested prices up into `buyerTargetNet` when nothing else set it.
- **#222's prompt fix** carries two things on `NegotiationContext`: the comment's adopted per-line targets (from `CommentTargetMerger::adopted()`), and whether the quote is stored gross. `NegotiateLineBlock` and `OfferProposer::userPrompt()` render them.
- **#222's label** is a new `QuoteEscalationReason` case, used only where the model itself chose `escalate`.

**Tech Stack:** PHP 8.3, PHPUnit 11, Mago; Shopware admin snippets (JSON).

**Spec:** `docs/superpowers/specs/2026-09-29-structured-and-per-line-asks-design.md`

## Global Constraints

- `declare(strict_types=1)`. Mago gates: cyclomatic complexity 10, nesting 4, parameters 5, about 400 lines per file. After PHP changes, run `composer run format:check && composer run lint`, and `composer run typecheck` for `src/`.
- `src/Negotiation` must not import Shopware beyond `IllegalTransitionException` (`NamespacePurityTest`), and `src/Policy` imports none at all.
- No migration: `escalation_reason` is a plain string column.
- The new enum value is exactly `model_declined`.
- `model_unavailable` keeps "could not be reached or answered unusably", including the history budget running out (`OfferProposer.php:82-90`).
- Old rows are never rewritten.
- Admin reason texts live only in `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/{en,de}.json`, under `merchant-quote-agent.escalation.<value>` (label) and `merchant-quote-agent.escalationWhy.<value>` (sentence). The admin renders both generically by key.
- Commits are signed through the 1Password app; if signing fails, retry. End each commit message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A storefront requested price *above* the quoted price** (not an ask). The rollup must not turn it into a markup, and no band change may follow. Pinned in Task 1.
2. **Both a storefront ask and a comment line target on one quote.** `buyerTargetNet` must come from the merged lines exactly once, with no double counting. The existing comment path is unchanged. Pinned in Task 1.
3. **A comment ask on a net-stored quote** (`netRatio` 1). No gross label may appear, because that would mislead the other way. Pinned in Task 2.
4. **A storefront requested price and a comment target on the same line.** The column shows the storefront one: it is what `CommentLineTargets::adoptedBy()` lets win. Pinned in Task 2.
5. **The history budget running out** stays `model_unavailable`, and only the model's own `escalate` becomes `model_declined`. Pinned in Task 3.

---

### Task 1: #223 — comment-less per-line asks reach the band

**Files:**
- Modify: `src/Policy/CommentTargetMerger.php` (`merge()`, around lines 27-45)
- Test: `tests/Unit/Policy/CommentTargetMergerTest.php`, `tests/Unit/Negotiation/StructuredAskGateTest.php`

**Interfaces:**
- Produces: `CommentTargetMerger::merge(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): QuoteSnapshot` keeps its signature. It now also returns a snapshot with `buyerTargetNet` set when the comment had no line targets, `buyerTargetNet` was null, and at least one line's `requestedUnitPrice` is below its `unitPriceNet`.

- [ ] **Step 1: Write the failing policy tests.** Add these to `CommentTargetMergerTest`, reusing the file's own imports; add `QuoteLineIdentity`, `QuoteLineSnapshot`, `QuoteLifecycle` and `QuoteSnapshot` if they're missing.

```php
    /** One 10 x 100.00 net line, optionally carrying a storefront requested price. */
    private static function structured(?float $requestedUnitPrice, ?float $buyerTargetNet = null): QuoteSnapshot
    {
        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1000.0,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1'),
                quantity: 10,
                unitPriceNet: 100.0,
                totalNet: 1000.0,
                requestedUnitPrice: $requestedUnitPrice,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            buyerTargetNet: $buyerTargetNet,
        );
    }

    public function testAStorefrontRequestedPriceBecomesTheBuyersTargetWithoutAComment(): void
    {
        // #223: with no comment nothing set buyerTargetNet, so the band
        // decider measured a 99.9% storefront ask as 0% and granted it.
        $merged = (new CommentTargetMerger())->merge(self::structured(1.0), null);

        self::assertSame(10.0, $merged->buyerTargetNet);
    }

    public function testARequestedPriceAboveTheQuotedOneIsNoAsk(): void
    {
        // Review Focus 1: a requested price above the quote is not a request
        // for a markup; the snapshot stays as it was.
        $merged = (new CommentTargetMerger())->merge(self::structured(120.0), null);

        self::assertNull($merged->buyerTargetNet);
    }

    public function testATargetAlreadySetIsNeverOverridden(): void
    {
        $merged = (new CommentTargetMerger())->merge(self::structured(1.0, buyerTargetNet: 900.0), null);

        self::assertSame(900.0, $merged->buyerTargetNet);
    }
```

  Review Focus 2 (a storefront ask plus a comment target) is covered by the existing tests of the comment path. Run them in Step 4, and do not change them.

- [ ] **Step 2: Write the failing pipeline test.** Append to `StructuredAskGateTest`:

```php
    public function testAStorefrontAskFarBeyondTheCounterCeilingEscalatesBeforeAnyModelCall(): void
    {
        // #223, sw-ag.dev quote 1206: 0.84 net requested against 727.23, no
        // comment, was granted 15% and ordered. Here: 1.00 against 100.00 is
        // a 99% ask; NegotiationFixture's settings cap at 10% and counter up
        // to 20%, so the band must escalate as discount_limit_exceeded.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 1.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(0, $harness->spy->calls, 'The band decides before any model call.');
        self::assertSame(
            QuoteEscalationReason::DiscountLimitExceeded->value,
            $harness->writer->drafts[0]->escalationReason,
        );
    }
```

  Add `use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;` if it's missing. Check `NegotiationFixture::settings()` for the cap and counter ceiling first. If they aren't 10 and 20, keep the requested price far enough out that 99% is above the counter ceiling, and adjust only the comment.

- [ ] **Step 3: Run the tests and confirm they fail.**

  Run: `vendor/bin/phpunit --filter 'CommentTargetMergerTest|StructuredAskGateTest'`

  Expected:
  - the two new "becomes the target" and "escalates" tests FAIL: `buyerTargetNet` is null, and the outcome is `Offered`, or the spy runs out of scripted answers;
  - the "above the quoted one" and "already set" tests pass already; they guard the fix.

- [ ] **Step 4: Implement it in `CommentTargetMerger::merge()`.** Replace the early return:

```php
        $targets = $this->lineTargets->extract($interpretation);
        if ($targets === []) {
            return self::withStructuredTarget($snapshot);
        }
```

  Then add the helper:

```php
    /**
     * #223: a storefront per-line requested price is an ask on its own, but
     * only rescaledBuyerTarget() rolls line asks up into the quote-level
     * target the band decider reads, and it ran only for a comment's line
     * targets. With no comment the band measured the ask as 0% and granted a
     * 99.9% request (sw-ag.dev quote 1206). The retired TS snapshot builder
     * supplied this field; the port lost it.
     *
     * Never overrides a target something else already set, and only when a
     * line asks for less than it is quoted at: a requested price above the
     * quote is no ask for a markup.
     */
    private static function withStructuredTarget(QuoteSnapshot $snapshot): QuoteSnapshot
    {
        if ($snapshot->buyerTargetNet !== null) {
            return $snapshot;
        }

        foreach ($snapshot->lines as $line) {
            if ($line->requestedUnitPrice !== null && $line->requestedUnitPrice < $line->unitPriceNet) {
                return $snapshot->withBuyerTargetNet(self::rescaledBuyerTarget($snapshot, $snapshot->lines));
            }
        }

        return $snapshot;
    }
```

- [ ] **Step 5: Run the tests and confirm they pass, including every existing policy and pipeline test.**

  Run: `vendor/bin/phpunit --filter 'CommentTargetMerger|StructuredAskGate|QuoteDecider|QuoteBandDecider|ScenarioPipeline|AskedDiscountCeiling'`, then `composer run test`.

  Expected: PASS.
  - If an existing test changes its outcome, read why before touching it. A storefront ask inside the band must still be `Offered`, which is `testAStructuredPriceAskIsAnsweredWithoutAComment`.
  - A test that asserted a 0%-banded structured ask was pinning the bug. Report which, rather than silently re-pinning it.

- [ ] **Step 6: Run the gates and commit.**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Policy/CommentTargetMerger.php tests/Unit/Policy/CommentTargetMergerTest.php tests/Unit/Negotiation/StructuredAskGateTest.php
git commit -m "fix(policy): band a comment-less per-line ask at its real percentage

A storefront requested price with no comment never reached buyerTargetNet,
so the band decider measured a 99.9% ask as 0% and granted it (sw-ag.dev
quote 1206, #223). CommentTargetMerger now rolls the snapshot's own line
asks up when nothing else set a target.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: #222 — the negotiate model sees the per-line ask in net, and knows the buyer writes gross

**Files:**
- Modify:
  - `src/Negotiation/NegotiationContext.php`
  - `src/Negotiation/OfferRound.php` (the `new NegotiationContext(...)` call, around lines 49-55)
  - `src/Negotiation/NegotiateLineBlock.php`
  - `src/Negotiation/OfferProposer.php` (`userPrompt()`, around lines 187-240)
- Test: `tests/Unit/Negotiation/NegotiateTargetSpaceTest.php`

**Interfaces:**
- Produces:
  - `NegotiationContext` gains two named, defaulted constructor parameters after `buyerTargetNet`:
    - `/** @var array<string, float> */ public array $lineAsksNet = []` maps line item id to the adopted comment target, net;
    - `public bool $buyerWritesGross = false`.
  - `NegotiateLineBlock::of(array $lines, array $lineAsksNet = []): string`.
- Consumes: `CommentTargetMerger::adopted(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): array` (existing, public).

- [ ] **Step 1: Write the failing tests.** Append to `NegotiateTargetSpaceTest`:

```php
    public function testAPerLineAskTypedInACommentReachesTheLineTableInNet(): void
    {
        // #222, sw-ag.dev quote 1202: "770.21 a unit" on a gross quote was
        // filed as 647.24 net, but the negotiate prompt showed only the raw
        // comment beside NET unit prices, and the model escalated because
        // "770.21 is higher than 727.23". The gross fixture's net ratio is
        // 0.8: 90 gross is 72.00 net.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
            '{"action":"offer","message":"Done.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('Can you do 90 a unit?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service($harness->before, $harness->gateway, NegotiationFixture::settings(), NegotiationFixture::context());

        $negotiatePrompt = $harness->spy->userPrompts[1] ?? '';
        self::assertMatchesRegularExpression('/line-1 \|[^\n]*\| 72\.00$/m', $negotiatePrompt, 'The line row must carry the converted ask in the "buyer asks per unit net" column.');
        self::assertStringContainsString('include tax', $negotiatePrompt, "On a gross quote the model must be told the buyer's own figures are gross.");
    }

    public function testANetQuoteGetsNoGrossLabel(): void
    {
        // Review Focus 3: a net-stored quote's buyer writes net; a gross label
        // there would mislead the other way.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"Done.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service($harness->before, $harness->gateway, NegotiationFixture::settings(), NegotiationFixture::context());

        self::assertStringNotContainsString('include tax', $harness->spy->userPrompts[1] ?? '');
    }

    public function testAStorefrontPriceWinsTheColumnOverACommentTargetOnTheSameLine(): void
    {
        // Review Focus 4: CommentLineTargets::adoptedBy() lets a standing
        // storefront ask win over a comment target; the column must show the
        // number the policy layer prices against, not the one it ignored.
        self::assertStringEndsWith(
            '| 70.00',
            NegotiateLineBlock::of([self::line(requestedUnitPrice: 70.0)], ['line-1' => 72.0]),
        );
    }

    private static function line(?float $requestedUnitPrice): \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot
    {
        return new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
            identity: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity('line-1'),
            quantity: 1,
            unitPriceNet: 80.0,
            totalNet: 80.0,
            requestedUnitPrice: $requestedUnitPrice,
        );
    }
```

  Before using `line-1`, check `NegotiationFixture::grossSnapshot()` for the line item id it builds. If it differs, use the fixture's own id in both the scripted `lineChanges` and the regex. Replace the fully qualified names with `use` imports, in the file's style.

- [ ] **Step 2: Run the tests and confirm they fail.**

  Run: `vendor/bin/phpunit --filter NegotiateTargetSpaceTest`

  Expected:
  - the per-line test FAILS: the column is empty, and there's no label;
  - the storefront-wins test FAILS: `of()` takes one argument;
  - the net-quote test passes; it guards against the label leaking.

- [ ] **Step 3: Extend `NegotiationContext`.** After `buyerTargetNet`, add:

```php
        /**
         * The per-line targets the buyer's comment asked for, as the policy
         * layer adopts them (CommentTargetMerger::adopted()), net. #222: the
         * prompt's "buyer asks per unit net" column read only the storefront
         * field, so a price typed in the comment reached the model solely as
         * the buyer's gross sentence (sw-ag.dev quote 1202).
         *
         * @var array<string, float> line item id => target unit price, net
         */
        public array $lineAsksNet = [],
        /** True when the quote is stored gross, so the figures the buyer writes include tax. */
        public bool $buyerWritesGross = false,
```

- [ ] **Step 4: Populate it in `OfferRound`.** Extend the `new NegotiationContext(...)` call:

```php
        $context = new NegotiationContext(
            $snapshot->identity->customerId,
            $snapshot->identity->quoteId,
            SnapshotAdapter::conversation($snapshot),
            QuoteBaseline::read($snapshot),
            $ask?->interpretation->price->targetTotal,
            lineAsksNet: (new CommentTargetMerger())->adopted(SnapshotAdapter::toPolicy($snapshot), $ask?->interpretation),
            buyerWritesGross: self::storedGross($snapshot),
        );
```

  Add the helper, plus `use MerchantQuoteAgentPlugin\Policy\CommentTargetMerger;`:

```php
    /** A line whose stored price had tax taken off on the way in: the buyer's page shows gross. */
    private static function storedGross(QuoteSnapshot $snapshot): bool
    {
        foreach ($snapshot->content->lines as $line) {
            if ($line->netRatio < 1.0 - 1e-6) {
                return true;
            }
        }

        return false;
    }
```

  `QuoteSnapshot` here is the Bridge one that `OfferRound` already receives. Check the import, and that `QuoteLineSnapshot::$netRatio` exists on the Bridge line (`src/Bridge/Data/QuoteLineSnapshot.php`). If `OfferRound` crosses mago's method-count or complexity gate, move `storedGross` to a static on `SnapshotAdapter` and say so in the report.

- [ ] **Step 5: Render the column in `NegotiateLineBlock`.**

```php
    /**
     * @param list<QuoteLineSnapshot> $lines
     * @param array<string, float> $lineAsksNet a comment's adopted per-line targets, net (NegotiationContext)
     */
    public static function of(array $lines, array $lineAsksNet = []): string
```

  In the row formatter, replace the last `sprintf` argument with:

```php
                // The storefront's per-line "Requested price", else the target
                // the buyer typed in their comment, both net. Without the first
                // a structured-only ask reached the model as no ask at all
                // (sw-ag.dev quotes 1097/1099); without the second a comment's
                // gross per-unit figure did (quote 1202, #222).
                self::ask($l->requestedUnitPrice ?? $lineAsksNet[$l->lineItemId()] ?? null),
```

  Add the helper:

```php
    private static function ask(?float $netPrice): string
    {
        return $netPrice === null ? '' : sprintf('%.2f', $netPrice);
    }
```

  The storefront field wins because `adoptedBy()` lets it win, so the column matches what is priced.

- [ ] **Step 6: Label the comment in `OfferProposer::userPrompt()`.**
  - Pass the targets to the table: `NegotiateLineBlock::of($snapshot->lines, $context->lineAsksNet)`.
  - Build the label before the `return`:

```php
        // #222: the table is net, the buyer's sentence is not. Said once, above
        // both the earlier rounds and the latest comment, so no raw figure in
        // either is read against a net price.
        $buyerSpace = $context->buyerWritesGross
            ? "The buyer's own figures below include tax (gross); every price above is net.\n"
            : '';
```

  - Change the format string's tail from `"%sBuyer's latest comment:\n%s"` to `"%s%sBuyer's latest comment:\n%s"`, and pass `$buyerSpace` before `$earlierRounds`.

- [ ] **Step 7: Run the tests and confirm they pass.**

  Run: `vendor/bin/phpunit --filter 'NegotiateTargetSpaceTest|NegotiateRequestedPriceTest|OfferProposerTest|DiscountCeilingTest'`, then `composer run test`.

  Expected: PASS. Any existing test that asserts the exact prompt text may need the new label, but only on gross fixtures. Update it to assert the label's presence; don't delete its assertion.

- [ ] **Step 8: Run the gates and commit.**

```bash
composer run format:check && composer run lint && composer run typecheck && vendor/bin/phpunit --filter NamespacePurityTest
git add src/Negotiation tests/Unit/Negotiation/NegotiateTargetSpaceTest.php
git commit -m "fix(negotiation): show a comment's per-line ask in net, label gross figures

The negotiate prompt is net throughout but pasted the buyer's comment with
their gross per-unit figure, and never showed the converted target: the
model read 770.21 gross against 727.23 net and escalated a valid 11% ask
(sw-ag.dev quote 1202, #222). The line table's ask column now carries the
adopted comment target, and a gross quote says the buyer's figures include
tax.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: #222 — `model_declined` for a model-chosen escalation

**Files:**
- Modify:
  - `src/Policy/Data/QuoteEscalationReason.php`
  - `src/Negotiation/OfferProposer.php` (around lines 102-111)
  - `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
  - `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`
  - `docs/superpowers/specs/2026-09-24-never-answer-zero-discount-design.md` (a supersession note)
- Test: `tests/Unit/Negotiation/OfferProposerTest.php`, `tests/Unit/Negotiation/RecordedOutcomePathsTest.php`

**Interfaces:**
- Produces: `QuoteEscalationReason::ModelDeclined = 'model_declined'`.

- [ ] **Step 1: Change the pinned tests first**, so they fail.
  - In `OfferProposerTest::testTheModelMayDeclineAndItsReasonIsKept` (around line 264), expect `QuoteEscalationReason::ModelDeclined`.
  - In `RecordedOutcomePathsTest::testANoOfferProposedPassRecordsOneEscalatedRecord` (around line 103), expect `QuoteEscalationReason::ModelDeclined->value`, and update its comment: the model declining is now its own reason (#222), no longer "one of ModelUnavailable's three scenarios".
  - Add a test to `OfferProposerTest` for Review Focus 5, unless one already asserts that the history budget running out gives `ModelUnavailable`. Grep for `HistoryBudgetExhausted` in `tests/Unit/Negotiation`. `HistoryBoundaryTest` and `HistoryLoopTest` reference `ModelUnavailable`; if one of them pins the budget case, point to it in the report and add nothing.

- [ ] **Step 2: Run the tests and confirm they fail.**

  Run: `vendor/bin/phpunit --filter 'OfferProposerTest|RecordedOutcomePathsTest'`

  Expected: FAIL with an undefined case `ModelDeclined`.

- [ ] **Step 3: Add the case to `QuoteEscalationReason`**, and narrow `ModelUnavailable`'s comment:

```php
    // Issue #18. The model could not be reached or answered unusably (the
    // history budget ran out), or the database disagreed with what we applied.
    case ModelUnavailable = 'model_unavailable';
    // #222: the negotiate model itself chose to escalate. Split out of
    // ModelUnavailable (#169 had folded it in) because the dashboard read
    // every such decision as an outage.
    case ModelDeclined = 'model_declined';
```

- [ ] **Step 4: Map it in `OfferProposer`.** In the `if ($response->escalates())` branch, use `QuoteEscalationReason::ModelDeclined`, and replace the #169 comment:

```php
            // #222: the model itself declined to offer anything. Recorded as
            // its own reason: the model was reachable and answered, so this is
            // a judgement to review, not an outage (it was ModelUnavailable
            // under #169).
```

- [ ] **Step 5: Add the admin snippets.** Put these entries directly after the existing `model_unavailable` entry in both blocks.

  `en.json`, in `merchant-quote-agent.escalation`:

```json
            "model_declined": "Agent declined",
```

  `en.json`, in `merchant-quote-agent.escalationWhy`:

```json
            "model_declined": "The agent reviewed this ask and chose to hand it to a person instead of making an offer. Nothing was written to the quote.",
```

  `de.json`, same keys:

```json
            "model_declined": "Agent hat abgelehnt",
```

```json
            "model_declined": "Der Agent hat diese Anfrage geprüft und sie an eine Person übergeben, statt ein Angebot zu machen. Am Angebot wurde nichts geändert.",
```

  In `escalationWhy.model_unavailable`, drop the "or declined to make an offer" clause, in both languages, so the two sentences don't overlap. Keep the key order and JSON validity, and check with `node -e "JSON.parse(require('fs').readFileSync('<file>','utf8'))"`.

- [ ] **Step 6: Record the supersession.** In `docs/superpowers/specs/2026-09-24-never-answer-zero-discount-design.md`, directly below the sentence at lines 97-99 about the model's own `escalate` keeping `model_unavailable`, add:

```markdown
> **Superseded 2026-09-29 (#222):** a model-chosen `escalate` is now recorded as `model_declined`; `model_unavailable` keeps unreachable or unusable answers. See `2026-09-29-structured-and-per-line-asks-design.md`.
```

- [ ] **Step 7: Run everything and commit.**

  Run:
  - `composer run test`
  - `composer run quality:admin`
  - `composer run format:check && composer run lint && composer run typecheck`

  Expected: all pass.

```bash
git add src/Policy/Data/QuoteEscalationReason.php src/Negotiation/OfferProposer.php src/Resources/app/administration/src/module/merchant-quote-agent/snippet tests/Unit/Negotiation docs/superpowers/specs/2026-09-24-never-answer-zero-discount-design.md
git commit -m "feat(negotiation): record a model-chosen escalation as model_declined

The negotiate model's own escalate was recorded as model_unavailable
(#169), so the dashboard read a reachable model's judgement as an outage
(#222). model_unavailable keeps unreachable and unusable answers.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## After the tasks

- Push the branch and open a PR that closes #222 and #223.
- Once the branch is deployed to sw-ag.dev, run `scripts/eval.sh`. Deploy with `database:migrate --all` and never `plugin:update`; no migration is needed. Expected result: 20/21, with only `margin-floor-holds` H4 (the unfiled one-cent rise) still red.
