# Baseline extension and offer lower bound — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Anchor lines added after the baseline was stamped at their first-seen price (#54), and bound a quote-wide offer from below while deleting the constraint attributes that never run (#56).

**Architecture:** One new operation, `QuoteBaselineLines::extendedWith()`, appends unknown non-negative live lines to the baseline and scales `totalNet` so `NetFactor` is unchanged. `anchor()` applies it in memory; `QuoteBaseline::stampOrExtend()` persists it through the `customFields` fragment both existing stamping sites already write. `asReferenceSnapshot()` is deleted so authorize and verify read one reference. Separately, `PriceOfferCheck` and `DiscountTotalViolation` gain a lower bound, and the decorative `Assert` attributes are removed and pinned by a test.

**Tech Stack:** PHP 8.3, PHPUnit 11, Mago (format/lint/analyze), Shopware 6.7 plugin conventions.

**Spec:** `docs/superpowers/specs/2026-09-16-baseline-extension-and-offer-lower-bound-design.md`

## Global Constraints

- `declare(strict_types=1)` in every file; Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- Gate thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines per file.
- `src/Negotiation` must not import Shopware beyond `IllegalTransitionException`; `src/Policy` imports no Shopware at all (`NamespacePurityTest`).
- Docblocks in this area cite the measured quote number and the failure they prevent. Quote 1101 is #49's worked example; do not invent new quote numbers.
- Two commits only: tasks 1–4 are #54, tasks 5–6 are #56. Each commit message ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Run `git` as `/usr/bin/git` from the worktree root.
- Gates: `composer run test` and `composer run quality`.

---

### Task 1: `QuoteBaselineLines::extendedWith()`

**Files:**
- Modify: `src/Negotiation/QuoteBaselineLines.php`
- Test: `tests/Unit/Negotiation/QuoteBaselineTest.php`

**Interfaces:**
- Produces: `QuoteBaselineLines::extendedWith(list<MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot> $live): self` — returns `$this` unchanged when nothing is added, so callers may compare with `===`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Negotiation/QuoteBaselineTest.php`. The existing private helpers `snapshot(array $customFields)` and `policySnapshot()` are already in that file; reuse them. Add a small line builder next to them:

```php
    private static function policyLine(string $id, float $unitPriceNet, int $quantity = 1): PolicyLine
    {
        return new PolicyLine(
            identity: new PolicyLineIdentity($id),
            quantity: $quantity,
            unitPriceNet: $unitPriceNet,
        );
    }
```

with `use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;` and `use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity as PolicyLineIdentity;` added to the imports.

```php
    /**
     * #54. The baseline is stamped once and never moved, so a line added
     * afterwards had no anchor at all: the merge re-derived one every round
     * from a price the previous round had already cut.
     */
    public function testAnUnknownLineIsAppendedAtItsCurrentPrice(): void
    {
        $baseline = self::storedBaseline();

        $extended = $baseline->extendedWith([...$baseline->lines, self::policyLine('line-3', 50.0, 2)]);

        self::assertCount(3, $extended->lines);
        self::assertSame('line-3', $extended->lines[2]->lineItemId());
        self::assertSame(50.0, $extended->lines[2]->unitPriceNet);
    }

    /** Extension is one-way: a known line keeps the price it was stamped at. */
    public function testAKnownLineKeepsItsStoredPriceEvenWhenTheLivePriceMoved(): void
    {
        $baseline = self::storedBaseline();

        $extended = $baseline->extendedWith([self::policyLine('line-1', 10.0, 4)]);

        self::assertSame($baseline, $extended, 'A baseline that knows every line must not be rebuilt.');
        self::assertSame(100.0, $extended->lines[0]->unitPriceNet);
    }

    /**
     * Shopware generates a negative line for a quote-wide percentage
     * discount. Stamping it would fold round one's own concession into the
     * anchor round two is measured against — #49's compounding, reopened.
     */
    public function testANegativeLineIsNeverTakenIntoTheBaseline(): void
    {
        $baseline = self::storedBaseline();

        $extended = $baseline->extendedWith([...$baseline->lines, self::policyLine('discount', -80.0)]);

        self::assertSame($baseline, $extended);
    }

    /**
     * The scaling rule. NetFactor divides totalNet by the sum of
     * unitPriceNet * quantity; appending a line without moving the total
     * would grow the denominator alone, shrink every reference price and make
     * the verifier report lines "priced above their reference" that nobody
     * touched.
     */
    public function testExtendingLeavesTheNetFactorExactlyWhereItWas(): void
    {
        $baseline = self::storedBaseline();
        $live = self::policySnapshot();

        $before = NetFactor::of($baseline->anchor($live));
        $extended = $baseline->extendedWith([...$baseline->lines, self::policyLine('line-3', 50.0, 2)]);

        self::assertSame(1100.0, $extended->totalNet, 'The added line enters the total at its own value.');
        self::assertEqualsWithDelta($before, NetFactor::of($extended->anchor($live)), 1e-9);
    }

    private static function storedBaseline(): QuoteBaselineLines
    {
        $baseline = QuoteBaseline::read(self::snapshot(customFields: QuoteBaseline::stamp(self::snapshot([]))));
        self::assertNotNull($baseline);

        return $baseline;
    }
```

Add `use MerchantQuoteAgentPlugin\Negotiation\QuoteBaselineLines;` to the imports.

The fixture snapshot in that file holds `line-1` at 100.0 × 4 and `line-2` at 300.0 × 2, total 1000.0 — so the stored factor is exactly 1.0 and a `50.0 × 2` line adds 100.0.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter QuoteBaselineTest`
Expected: FAIL — `Call to undefined method ...::extendedWith()`.

- [ ] **Step 3: Implement**

In `src/Negotiation/QuoteBaselineLines.php`, add:

```php
    /**
     * This baseline plus every live line it does not know, each at the price
     * it carries right now (#54).
     *
     * `linesMergedWith()` already treats an unknown line's current price as
     * its own baseline, and is right to: the line has had no agent concession
     * behind it. That only held for the round the line appeared, because the
     * merge was thrown away and recomputed the next round from a price round
     * N had already cut — the per-line compounding #49 closed, reopened for
     * exactly those lines. Persisting the merge is what makes the judgement
     * hold on every later round.
     *
     * Negative lines are never taken. Shopware generates one for a quote-wide
     * percentage discount, so stamping it would fold the agent's own round-one
     * concession into the anchor round two is measured against.
     * LineNetViolation skips them for the same reason.
     *
     * Returns `$this` when there is nothing to add, so a caller can ask
     * whether anything changed with `===`.
     *
     * @param list<PolicyLine> $live
     */
    public function extendedWith(array $live): self
    {
        $known = [];

        foreach ($this->lines as $line) {
            $known[$line->lineItemId()] = true;
        }

        $added = [];

        foreach ($live as $line) {
            if (!isset($known[$line->lineItemId()]) && $line->unitPriceNet >= 0.0) {
                $added[] = $line;
            }
        }

        if ($added === []) {
            return $this;
        }

        return new self($this->totalNet + $this->scaledValueOf($added), [...$this->lines, ...$added]);
    }

    /**
     * The added lines' value expressed in the baseline's own price space, so
     * that NetFactor::of() reads the same ratio before and after: with
     * f = totalNet / stored line sum, (f*S + f*v) / (S + v) is f exactly.
     *
     * The added line's own `totalNet` is deliberately not used — line totals
     * are read in the cart's DISPLAY space, gross on a gross-calculated cart,
     * while the quote total is always net. That mismatch is the whole reason
     * NetFactor exists, and adding one to the other would reintroduce it.
     *
     * @param list<PolicyLine> $added
     */
    private function scaledValueOf(array $added): float
    {
        $raw = 0.0;

        foreach ($added as $line) {
            $raw += $line->unitPriceNet * $line->quantity;
        }

        $stored = 0.0;

        foreach ($this->lines as $line) {
            $stored += $line->unitPriceNet * $line->quantity;
        }

        // Mirrors NetFactor's own 1.0 fallback: with no usable stored ratio
        // there is nothing to express the value in but itself.
        return $stored > 0 && $this->totalNet > 0 ? $raw * ($this->totalNet / $stored) : $raw;
    }
```

And change `anchor()` to anchor on the extended baseline:

```php
    public function anchor(PolicySnapshot $live): PolicySnapshot
    {
        $extended = $this->extendedWith($live->lines);

        return new PolicySnapshot(
            currencyIso: $live->currencyIso,
            totalNet: $extended->totalNet,
            lines: $extended->linesMergedWith($live->lines),
            lifecycle: $live->lifecycle,
        );
    }
```

Extend `anchor()`'s existing docblock with a sentence naming #54: the extension runs here as well as at the stamping sites because `ServiceQuoteHandler` hands the pipeline the pass-start snapshot, whose custom fields predate the extension that pass just wrote — without it the proposer and the applier would anchor on different numbers.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter QuoteBaselineTest`
Expected: PASS.

- [ ] **Step 5: No commit yet** — task 4 commits #54 as one change.

---

### Task 2: one reference for authorize and verify

**Files:**
- Modify: `src/Negotiation/QuoteBaselineLines.php` (delete `asReferenceSnapshot()`)
- Modify: `src/Negotiation/OfferApplier.php:76`
- Test: `tests/Unit/Negotiation/QuoteBaselineTest.php`

**Interfaces:**
- Consumes: `QuoteBaselineLines::anchor()` from task 1.
- Produces: `asReferenceSnapshot()` no longer exists. Any caller uses `anchor()`.

- [ ] **Step 1: Write the failing test**

Replace the two existing tests in `QuoteBaselineTest.php` that call `asReferenceSnapshot()` — `testTheStoredShapeKeepsTheNetFactorCoherent()` and `testTheReferenceSnapshotTakesPricesFromTheBaselineAndTheRestFromTheLiveQuote()` — with the same assertions against `anchor()`, and add:

```php
    /**
     * #54. The applier read the STORED lines and the proposer read the stored
     * lines MERGED with the live ones, so a line added after the stamp was
     * bounded on the authorize side and invisible on the verify side —
     * LineOfferVerifier skips a line the reference does not hold. One method
     * now produces both.
     */
    public function testTheVerifiersReferenceHoldsALineAddedAfterTheStamp(): void
    {
        $baseline = self::storedBaseline();
        $live = self::policySnapshot();
        $withAdded = new PolicySnapshot(
            currencyIso: $live->currencyIso,
            totalNet: $live->totalNet,
            lines: [...$live->lines, self::policyLine('line-3', 50.0, 2)],
            lifecycle: $live->lifecycle,
        );

        $ids = array_map(
            static fn(PolicyLine $line): string => $line->lineItemId(),
            $baseline->anchor($withAdded)->lines,
        );

        self::assertContains('line-3', $ids);
        self::assertFalse(
            method_exists($baseline, 'asReferenceSnapshot'),
            'Two reference builders are what let the verify side fall behind the authorize side.',
        );
    }
```

Add `use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;` if the file does not already alias it.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter QuoteBaselineTest`
Expected: FAIL on the `method_exists` assertion.

- [ ] **Step 3: Implement**

Delete `asReferenceSnapshot()` from `src/Negotiation/QuoteBaselineLines.php`. Move its "Known limitation" paragraph — the one about a quantity reduction or line removal inflating the totals check — onto `anchor()`'s docblock, since that is now the only reference builder and the limitation is still true there. Add one sentence recording that the mirror case is closed: a line **added** mid-negotiation no longer pulls the measured discount down, because the baseline total grows with it.

In `src/Negotiation/OfferApplier.php`, change the verifier input:

```php
            // #49: the baseline when the quote has one, so the line check, the
            // totals check and NetFactor's normalisation are all anchored to
            // the original prices. On the first pass there is none and the
            // pre-write snapshot IS the original, so this degrades correctly.
            //
            // #54: anchor(), not a second builder of its own. The applier used
            // to read the STORED lines while the proposer read them merged
            // with the live ones, so a line added after the stamp was bounded
            // on one side and skipped entirely on the other.
            reference: $baselineLines?->anchor($live) ?? $live,
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter 'QuoteBaselineTest|OfferApplier|OfferVerifier'`
Expected: PASS.

- [ ] **Step 5: No commit yet.**

---

### Task 3: persist the extension — `QuoteBaseline::stampOrExtend()`

**Files:**
- Modify: `src/Negotiation/BaselineRow.php`
- Modify: `src/Negotiation/QuoteBaseline.php`
- Modify: `src/Negotiation/OfferApplier.php:137`
- Modify: `src/Servicing/ServiceQuoteHandler.php:243`
- Test: `tests/Unit/Negotiation/QuoteBaselineTest.php`

**Interfaces:**
- Consumes: `QuoteBaselineLines::extendedWith()` from task 1.
- Produces: `QuoteBaseline::stampOrExtend(BridgeSnapshot $snapshot): array<string, mixed>`; `BaselineRow::writePolicy(PolicyLine $line): array<string, mixed>`. `QuoteBaseline::stampIfAbsent()` is gone.

- [ ] **Step 1: Write the failing tests**

```php
    /** No baseline at all: the full stamp, exactly as #49 wrote it. */
    public function testStampOrExtendStampsAQuoteThatHasNoBaseline(): void
    {
        $fragment = QuoteBaseline::stampOrExtend(self::snapshot(customFields: []));

        self::assertArrayHasKey(QuoteBaseline::KEY, $fragment);
        self::assertSame(1000.0, $fragment[QuoteBaseline::KEY]['totalNet']);
        self::assertCount(2, $fragment[QuoteBaseline::KEY]['lines']);
    }

    /** #54: the fragment keeps stored prices and adds the row that is missing. */
    public function testStampOrExtendAddsAMissingLineAndKeepsTheStoredPrices(): void
    {
        $stored = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $cut = self::snapshotWithLines(
            customFields: $stored,
            lines: [
                self::bridgeLine('line-1', 60.0, 4),
                self::bridgeLine('line-2', 300.0, 2),
                self::bridgeLine('line-3', 50.0, 2),
            ],
        );

        $fragment = QuoteBaseline::stampOrExtend($cut);
        $rows = array_column($fragment[QuoteBaseline::KEY]['lines'], 'unitPriceNet', 'lineItemId');

        self::assertSame(100.0, $rows['line-1'], 'A stored line must keep the price it was stamped at.');
        self::assertSame(50.0, $rows['line-3'], 'The added line is stamped at the price it has now.');
        self::assertSame(1100.0, $fragment[QuoteBaseline::KEY]['totalNet']);
    }

    /** A baseline that already knows every line writes nothing. */
    public function testStampOrExtendWritesNothingWhenTheBaselineIsComplete(): void
    {
        $stored = QuoteBaseline::stamp(self::snapshot(customFields: []));

        self::assertSame([], QuoteBaseline::stampOrExtend(self::snapshot(customFields: $stored)));
    }
```

Add the two bridge helpers to the test file:

```php
    private static function bridgeLine(string $id, float $unitPriceNet, int $quantity): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($id),
            quantity: $quantity,
            unitPriceNet: $unitPriceNet,
            totalNet: $unitPriceNet * $quantity,
        );
    }

    /**
     * @param array<string, mixed> $customFields
     * @param list<QuoteLineSnapshot> $lines
     */
    private static function snapshotWithLines(array $customFields, array $lines): QuoteSnapshot
    {
        $base = self::snapshot($customFields);

        return new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: $base->totals,
            lifecycle: $base->lifecycle,
            content: new QuoteContent(lines: $lines),
        );
    }
```

`QuoteLineSnapshot`, `QuoteLineIdentity`, `QuoteContent` and `QuoteSnapshot` here are the **Bridge** ones already imported by this test file.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter QuoteBaselineTest`
Expected: FAIL — `Call to undefined method ...::stampOrExtend()`.

- [ ] **Step 3: Implement**

`src/Negotiation/BaselineRow.php` — one field-name list, two directions:

```php
    /** @return array<string, mixed> */
    public static function write(BridgeLine $line): array
    {
        return self::row($line->identity->lineItemId, $line->unitPriceNet, $line->quantity);
    }

    /**
     * A row the baseline already holds, re-serialised (#54). An extension
     * rewrites the whole list, so the stored rows travel back out through the
     * same field names they came in by.
     *
     * @return array<string, mixed>
     */
    public static function writeStored(PolicyLine $line): array
    {
        return self::row($line->lineItemId(), $line->unitPriceNet, $line->quantity);
    }

    /** @return array<string, mixed> */
    private static function row(string $lineItemId, float $unitPriceNet, int $quantity): array
    {
        return [
            'lineItemId' => $lineItemId,
            'unitPriceNet' => $unitPriceNet,
            'quantity' => $quantity,
        ];
    }
```

`src/Negotiation/QuoteBaseline.php` — replace `stampIfAbsent()`:

```php
    /**
     * `stamp()` when the quote has no baseline, the baseline EXTENDED with any
     * line it does not yet know when it has one, and an empty fragment when it
     * already knows them all (#54) — spread-friendly, so a caller building a
     * customFields array needs no conditional of its own.
     *
     * Extension is one-way and never revalues: a line the baseline already
     * holds keeps its stored price forever, so the anchor still cannot move.
     * Only a row with no entry at all is appended, at the price it carries on
     * this pass — which is its price before any agent concession, because both
     * callers compute this from a snapshot read BEFORE the pass writes.
     *
     * Kept as one function with two callers on purpose: OfferApplier::write()
     * and ServiceQuoteHandler::claimAttempt() both used to ask only "is there
     * a baseline?", and neither asked whether it covered the lines that are on
     * the quote now.
     *
     * @return array<string, mixed>
     */
    public static function stampOrExtend(BridgeSnapshot $snapshot): array
    {
        $baseline = self::read($snapshot);

        if ($baseline === null) {
            return self::stamp($snapshot);
        }

        $extended = $baseline->extendedWith(SnapshotAdapter::toPolicy($snapshot)->lines);

        if ($extended === $baseline) {
            return [];
        }

        return [
            self::KEY => [
                'totalNet' => $extended->totalNet,
                'lines' => array_map(BaselineRow::writeStored(...), $extended->lines),
            ],
        ];
    }
```

`SnapshotAdapter` is the class that owns bridge-to-policy mapping, so the conversion belongs there rather than being duplicated here.

`src/Negotiation/OfferApplier.php::write()` — replace the `QuoteBaseline::read(...) === null ? ... : null` line:

```php
        // #49: the snapshot read immediately above is the pre-negotiation
        // state on the first pass that writes anything, so the baseline rides
        // in the update this method already issues. A pass that escalates
        // writes nothing and stores nothing, which is correct — nothing
        // changed, so the next pass's prices are still the original ones.
        //
        // #54: and a line added since the stamp is appended to it here, at the
        // price this pre-write read gives it. Still no extra write: this
        // method's updateQuote goes out either way.
        $fragment = QuoteBaseline::stampOrExtend($reference);
        $baseline = $fragment === [] ? null : $fragment;
```

`src/Servicing/ServiceQuoteHandler.php::claimAttempt()` — change the call and extend the existing comment:

```php
            ...QuoteBaseline::stampOrExtend($snapshot),
```

Add to that comment block: `#54` — the same write also appends a line added since the stamp, at the price it has at pass start, which is before this pass concedes anything.

- [ ] **Step 4: Run the full unit suite**

Run: `composer run test`
Expected: PASS. `OfferApplierBaselineTest::testASecondPassDoesNotOverwriteTheBaseline` must still pass — its fixture quote has only `line-1`, which the baseline knows, so the fragment is `[]` and no baseline key is written.

- [ ] **Step 5: No commit yet.**

---

### Task 4: the two-round regression test, then commit #54

**Files:**
- Test: `tests/Unit/Negotiation/OfferApplierBaselineTest.php`

**Interfaces:**
- Consumes: everything from tasks 1–3.

- [ ] **Step 1: Write the failing test**

This is the test #54 asks for. It drives `OfferApplier` twice over a quote that gains a line between the rounds, then asserts round three's reference bounds the added line at its first-seen price. `FakeQuoteGateway` takes a list of snapshots returned by successive `fetchSnapshot()` calls and records `customFieldWrites`; the file already uses both.

```php
    /**
     * #54, the case that reopened #49's compounding. A line added between
     * rounds had no baseline entry, so the merge re-derived one every round
     * from whatever the price was by then: round two discounted it, round
     * three measured "no concession yet" against round two's cut price, and
     * the two rounds stacked past maxDiscountPercent with both sides clean.
     */
    public function testALineAddedBetweenRoundsIsAnchoredAtItsFirstSeenPrice(): void
    {
        $roundTwo = self::withLines(
            NegotiationFixture::withCustomFields(
                NegotiationFixture::snapshot(),
                NegotiationFixture::baselineOf(1000.0, 100.0),
            ),
            [self::line('line-1', 100.0, 10), self::line('line-2', 200.0, 1)],
        );

        $gateway = new FakeQuoteGateway([$roundTwo, $roundTwo]);
        self::applier()->apply($gateway, $roundTwo, NegotiationFixture::settings(), self::quoteWideOffer());

        $stored = null;

        foreach ($gateway->customFieldWrites as $write) {
            $stored ??= $write[QuoteBaseline::KEY] ?? null;
        }

        self::assertIsArray($stored, 'The pass that first saw the added line stored no anchor for it.');
        $rows = array_column($stored['lines'], 'unitPriceNet', 'lineItemId');
        self::assertSame(200.0, $rows['line-2']);
        self::assertSame(100.0, $rows['line-1'], 'The anchor for a known line must not move.');

        // Round three: the same quote with line-2 already cut to 180. The
        // reference the checks read must still say 200, not 180.
        $roundThree = self::withLines(
            NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(), [QuoteBaseline::KEY => $stored]),
            [self::line('line-1', 100.0, 10), self::line('line-2', 180.0, 1)],
        );

        $baseline = QuoteBaseline::read($roundThree);
        self::assertNotNull($baseline);

        $anchored = $baseline->anchor(SnapshotAdapter::toPolicy($roundThree));
        $prices = [];

        foreach ($anchored->lines as $line) {
            $prices[$line->lineItemId()] = $line->unitPriceNet;
        }

        self::assertSame(
            200.0,
            $prices['line-2'],
            'Round three is bounded against round two\'s reduced price, so the discounts compound.',
        );
    }
```

Add the two helpers to the same test class:

```php
    /** @param list<QuoteLineSnapshot> $lines */
    private static function withLines(QuoteSnapshot $snapshot, array $lines): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: $snapshot->lifecycle,
            content: new QuoteContent(lines: $lines, comments: $snapshot->content->comments),
        );
    }

    private static function line(string $id, float $unitPriceNet, int $quantity): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($id, $id),
            quantity: $quantity,
            unitPriceNet: $unitPriceNet,
            totalNet: $unitPriceNet * $quantity,
        );
    }
```

with the Bridge imports `QuoteContent`, `QuoteLineIdentity`, `QuoteLineSnapshot`, `QuoteSnapshot` and `MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter`.

- [ ] **Step 2: Verify it fails on `main`'s behaviour**

Run: `vendor/bin/phpunit --filter testALineAddedBetweenRoundsIsAnchoredAtItsFirstSeenPrice`
Expected with tasks 1–3 applied: PASS. To confirm the test has teeth, `git stash` is not available in this worktree — instead temporarily change `QuoteBaseline::stampOrExtend()` to return `[]` whenever a baseline exists, re-run, see the first assertion fail with "stored no anchor for it", then restore.

- [ ] **Step 3: Run the whole gate**

Run: `composer run test && composer run quality`
Expected: both green.

- [ ] **Step 4: Commit #54**

```bash
/usr/bin/git add src/Negotiation tests/Unit/Negotiation src/Servicing/ServiceQuoteHandler.php
/usr/bin/git commit -m "fix(negotiation): anchor lines added after the baseline stamp

... (message body per the spec's Part 1)

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: the missing lower bound (#56)

**Files:**
- Modify: `src/Policy/PriceOfferCheck.php`
- Modify: `src/Policy/DiscountTotalViolation.php`
- Test: `tests/Unit/Policy/OfferAuthorizerTest.php`, `tests/Unit/Policy/OfferVerifierTest.php`

**Interfaces:** unchanged signatures; both now return an extra violation shape.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Policy/OfferAuthorizerTest.php`, following the existing fixtures in that file:

```php
    /**
     * #56. A negative discount is written as a SwagCommercial percentage
     * discount and lands on the quote as a SURCHARGE, while
     * ReplyTemplate::reduction() floors its reported figure at 0 — so the
     * buyer reads "came down by 0%" on a quote that went up. The cap was
     * one-sided by construction and bounded nothing below.
     */
    public function testANegativeDiscountIsRefused(): void
    {
        $authorization = (new OfferAuthorizer())->authorize(
            new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: -5.0)),
            self::policy(),
        );

        self::assertFalse($authorization->approved);
    }

    public function testZeroIsNotANegativeDiscount(): void
    {
        $authorization = (new OfferAuthorizer())->authorize(
            new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 0.0)),
            self::policy(),
        );

        self::assertTrue($authorization->approved);
    }
```

Use whatever policy/offer builders already exist in that file rather than adding new ones; `self::policy()` above stands for the existing one.

In `tests/Unit/Policy/OfferVerifierTest.php`, add a case whose final total is above the reference total and assert the returned violations are not empty.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter 'OfferAuthorizerTest|OfferVerifierTest'`
Expected: FAIL — the negative discount is approved and the raised total verifies clean.

- [ ] **Step 3: Implement**

`src/Policy/PriceOfferCheck.php`:

```php
    /** @return list<string> */
    public function check(OfferedPrice $offer, NegotiationPolicy $policy): array
    {
        $discount = $offer->discountPercent;

        if ($discount === null) {
            return [];
        }

        if ($discount > ($policy->price->maxDiscountPercent + Epsilon::RATE)) {
            return [sprintf(
                'discount %s%% exceeds the %s%% limit',
                $discount,
                $policy->price->maxDiscountPercent,
            )];
        }

        // #56: the cap bounded the offer from above only. A negative discount
        // is written as a SwagCommercial percentage discount and arrives as a
        // surcharge, and ReplyTemplate::reduction() floors its figure at 0, so
        // the buyer is told the quote came down by 0% while the total rose.
        return $discount < -Epsilon::RATE
            ? [sprintf('discount %s%% is negative; an offer may not raise the quote', $discount)]
            : [];
    }
```

`src/Policy/DiscountTotalViolation.php` — insert before the existing `return`:

```php
        // #56, the same one-sidedness at the verify site. A final total ABOVE
        // the reference means the write moved the quote the wrong way.
        //
        // It has one false-positive mode, kept deliberately: a buyer who
        // RAISES a quantity mid-negotiation raises the final total against a
        // baseline that did not move, and that now escalates. It is the
        // mirror of the quantity-REDUCTION limitation #49 documented and
        // fails the same safe way — a human gets it, nothing is under-charged.
        if ($totalDiscount < -Epsilon::MONEY) {
            return sprintf(
                'total discount %s%% raises the quote above its reference total',
                number_format($totalDiscount, decimals: 1, thousands_separator: ''),
            );
        }
```

- [ ] **Step 4: Run**

Run: `composer run test`
Expected: PASS.

- [ ] **Step 5: No commit yet** — task 6 commits #56 as one change.

---

### Task 6: delete the decorative constraints and pin it, then commit #56

**Files:**
- Modify: `src/Policy/Data/ProposedOffer.php`, `OfferedPrice.php`, `QuoteSnapshot.php`, `QuoteLineSnapshot.php`, `PriceAsk.php`, `DeliveryAsk.php`, `PaymentAsk.php`, `InterpretedLineChange.php`, `InterpretedProductAddition.php`
- Modify: `AGENTS.md`
- Create: `tests/Unit/Policy/ValidatedConstraintsTest.php`

**Interfaces:** no signature changes; only attributes and the now-unused `use ... Constraints as Assert;` imports are removed.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Policy/ValidatedConstraintsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;

/**
 * A constraint attribute that nothing evaluates is worse than no constraint:
 * it reads as enforcement at the exact place a reviewer looks for it.
 *
 * ValidatorInterface::validate() runs in one place in this plugin —
 * QuoteAgentSettingsFactory, on the merchant's NegotiationPolicy — and
 * Assert\Valid carries that run to QuoteLimits and on to QuoteValueCeiling.
 * Those three files are the whole of the validated tree. Every other
 * Policy\Data DTO carried constraints that were never evaluated: #56 found a
 * negative discountPercent passing an OfferedPrice annotated
 * Range(min: 0, max: 100).
 *
 * The check is on the SOURCE rather than on reflection so that it cannot be
 * defeated by importing a constraint under a different alias, and so that it
 * needs no class to be autoloadable.
 */
final class ValidatedConstraintsTest extends TestCase
{
    private const VALIDATED = [
        'Policy/Data/NegotiationPolicy.php',
        'Policy/Data/QuoteLimits.php',
        'Policy/Data/QuoteValueCeiling.php',
    ];

    public function testOnlyTheValidatedTreeReferencesSymfonyConstraints(): void
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $carriers = [];

        foreach (self::phpFiles($root) as $file) {
            if (str_contains((string) file_get_contents($file), 'Symfony\\Component\\Validator\\Constraints')) {
                $carriers[] = str_replace($root . '/', '', $file);
            }
        }

        sort($carriers);
        $expected = self::VALIDATED;
        sort($expected);

        self::assertSame(
            $expected,
            $carriers,
            'A constraint outside the validated tree is decoration; one missing from it is a check that stopped running.',
        );
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ValidatedConstraintsTest`
Expected: FAIL, listing the nine decorative files.

- [ ] **Step 3: Implement**

From each of the nine files, delete every `#[Assert\...]` attribute line and the now-unused `use Symfony\Component\Validator\Constraints as Assert;` import. Nothing else changes — no property, type or default moves.

In `src/Policy/Data/QuoteLineSnapshot.php` the comment above `$unitPriceNet` currently reads as a note about a sibling attribute. Reword it to state the domain fact on its own:

```php
        // Legitimately negative: Shopware generates a quote-discount line item
        // that is not a priced position. LineNetViolation skips the price-band
        // check for exactly that reason, and QuoteBaselineLines::extendedWith()
        // never takes one into the baseline.
        public float $unitPriceNet = 0.0,
```

`src/Negotiation/Response/ResponseFormatFactory.php` mentions `Assert\Positive` only inside a docblock, describing the JSON-schema shape. Leave it — the test matches the fully-qualified namespace string, which that docblock does not contain. Verify this by running the test.

In `AGENTS.md`, replace the "Shared contracts" first bullet's claim that boundary data is validated "with constraint attributes on the `Policy\Data` DTOs" with the accurate statement: the Symfony Validator runs in exactly one place, `Config\QuoteAgentSettingsFactory`, over `NegotiationPolicy` → `QuoteLimits` → `QuoteValueCeiling`; every other boundary is checked by the explicit policy checks or mapped by cuyz/valinor, and `ValidatedConstraintsTest` pins that no DTO carries a constraint nothing evaluates.

- [ ] **Step 4: Run the whole gate**

Run: `composer run test && composer run quality`
Expected: both green. `composer run quality:depcheck` must not start reporting `symfony/validator` as unused — it is still a real dependency through `QuoteAgentSettingsFactory` and the three validated DTOs.

- [ ] **Step 5: Commit #56**

```bash
/usr/bin/git add src/Policy tests/Unit/Policy AGENTS.md
/usr/bin/git commit -m "fix(policy): bound the offer from below, delete the constraints that never ran

... (message body per the spec's Part 2)

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: integration attempt

- [ ] **Step 1:** Run `composer run test:integration`.
- [ ] **Step 2:** If the test shop is unreachable, record that plainly in the final report. Do not claim the integration suite passed.
