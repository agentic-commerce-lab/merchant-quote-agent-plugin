# Persist the Pre-Negotiation Reference Lines Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store the quote's line prices as the agent first found them, and bound every per-line offer against that baseline instead of against the previous round, so a multi-round per-line negotiation cannot compound past `maxDiscountPercent`.

**Architecture:** A `merchant_quote_agent_baseline` entry on the quote's custom fields, captured inside the `updateQuote` call `OfferApplier` already makes on the first pass that writes prices. It is read back at the two places that currently re-derive a reference per pass — the authorizer's `referenceLines` and the verifier's `VerifyOfferInput::$reference` — and the stopgap that escalates second-round per-line offers is removed.

**Tech Stack:** PHP 8.3, Shopware 6.7 (quote custom fields, shallow-merged through `QuoteUpdate`), PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-09-01-persist-reference-lines-design.md`

## Global Constraints

- **The custom-field key is exactly `merchant_quote_agent_baseline`**, matching the `merchant_quote_agent_*` convention of `…_serviced`, `…_escalated` and `…_attempts`.
- **The stored shape is `{"totalNet": float, "lines": [{"lineItemId": string, "unitPriceNet": float, "quantity": int}]}`.** Quantities and `totalNet` are not optional: `NetFactor::of()` divides `totalNet` by the sum of `unitPriceNet × quantity`, so all three must come from the same moment or the gross-vs-net normalisation is meaningless.
- **A malformed or unparsable baseline reads as `null`, never as a partial list.** Combined with the in-flight guard, that makes it escalate rather than silently mean "no limit".
- **Capturing the baseline must add no write the pass was not already making.** It rides in `OfferApplier`'s existing `QuoteUpdate`; an escalating pass writes nothing and stores nothing, which is correct because nothing changed.
- **The anchor is the quote as the agent first found it**, not the product's catalog price.
- **A reopened or expiration-extended quote keeps its baseline.** Nothing clears the key.
- **A quote serviced before this ships, carrying no baseline, keeps escalating its per-line offers** — detectable as `ServicingFingerprint::MARKER_KEY` present and no baseline.
- **`src/Negotiation/` must import nothing from `Shopware\`** except `IllegalTransitionException`. `NamespacePurityTest` enforces this; the new classes must import no Shopware at all.
- Every file starts `<?php`, blank line, `declare(strict_types=1);`, blank line, `namespace …;`. Classes are `final readonly` unless they cannot be.
- mago gates: class-scoped cyclomatic complexity 10, `excessive-parameter-list` 5 (five is allowed, six is not), `too-many-methods` 10, `too-many-properties` 10, `excessive-nesting` 4, 400 lines/file. **These apply to test classes too** when the pre-commit hook lints changed files by path, even though `composer run quality` only inspects `src`.
- `composer run quality` must exit 0 before every commit, and `composer run format` must be run first — plus `vendor/bin/mago fmt <changed test files>` explicitly, because `mago fmt` alone only covers `src`.
- **Commits must be signed. Never pass `--no-verify`.** If signing fails because the key store is locked, stop and report `BLOCKED: commit signing locked`.
- **Red-test discipline.** Every test must be seen failing for the right reason before the implementation exists. A test that passes on its first run proves nothing — investigate instead.
- Unit tests: `vendor/bin/phpunit`. Integration: `composer run test:integration -- --filter <Name>` (syncs this checkout into the `merchant-quote-shop` container; each test runs in a rolled-back transaction).
- **The integration suite has one pre-existing failure**, `PluginConfigTest::testInstallTimeDefaultsArePersistedWithNativeTypes` (`0` vs `0.0`). It is unrelated to this work and present on `main`. Do not fix it; do not let it mask a new failure.

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `src/Negotiation/QuoteBaseline.php` | The key, `read()` and `stamp()`. The only place that knows the custom field exists. |
| `src/Negotiation/BaselineRow.php` | One stored row in each direction: parse to a policy line, or build from a bridge line. Split out to keep `QuoteBaseline` under the complexity gate, mirroring how `LineReferenceViolation` was split out of `LinePriceOfferCheck`. |
| `src/Negotiation/QuoteBaselineLines.php` | The parsed baseline (`totalNet` + lines) and `asReferenceSnapshot()`, which is where "prices from the baseline, everything else from now" lives. |
| `tests/Unit/Negotiation/QuoteBaselineTest.php` | Round-trip, absent, malformed, quantity preservation, net-factor coherence. |
| `tests/Integration/BaselineCompoundingTest.php` | The three-round chain that proves the cap holds. |

**Modify:**

| File | Change |
|---|---|
| `src/Negotiation/OfferApplier.php` | Stamp the baseline when absent; use it as the verifier's reference when present. |
| `src/Negotiation/OfferRound.php` | Read the baseline and pass it to `propose()`; replace the second-round escalation with the in-flight guard. |
| `src/Negotiation/OfferProposer.php` | Take the baseline as a parameter; `authorize()` takes `array $referenceLines`; drop #47's first-round restriction. |
| `tests/Unit/Negotiation/OfferProposerTest.php` | Bound-against-baseline test; update the #47 round-two test. |
| `tests/Unit/Negotiation/OfferRoundTest.php` | Replace the second-round escalation test with the in-flight one. |
| `tests/Unit/Negotiation/OfferApplierTest.php` | Capture and verifier-reference tests. |

---

### Task 1: The baseline value object, row parser and reader

**Files:**
- Create: `src/Negotiation/QuoteBaselineLines.php`, `src/Negotiation/BaselineRow.php`, `src/Negotiation/QuoteBaseline.php`
- Test: `tests/Unit/Negotiation/QuoteBaselineTest.php`

**Interfaces:**
- Consumes: `Bridge\Data\QuoteSnapshot` (its `lifecycle->customFields`, `content->lines`, `totals->totalNet`), `Bridge\Data\QuoteLineSnapshot` (`identity->lineItemId`, `unitPriceNet`, `quantity`), `Policy\Data\QuoteSnapshot`, `Policy\Data\QuoteLineSnapshot`, `Policy\Data\QuoteLineIdentity`.
- Produces, all consumed by Tasks 2 and 3:
  - `QuoteBaseline::KEY` — the string `'merchant_quote_agent_baseline'`
  - `QuoteBaseline::read(BridgeSnapshot $snapshot): ?QuoteBaselineLines`
  - `QuoteBaseline::stamp(BridgeSnapshot $snapshot): array<string, mixed>`
  - `QuoteBaselineLines::$totalNet` (float), `QuoteBaselineLines::$lines` (`list<PolicyLine>`)
  - `QuoteBaselineLines::asReferenceSnapshot(PolicySnapshot $live): PolicySnapshot`
  - `QuoteBaselineLines::linesMergedWith(list<PolicyLine> $current): list<PolicyLine>`

**Background:** custom fields survive a database round trip as JSON, so a float written as `100.0` can read back as the integer `100`. Use `is_numeric()` and cast, never `is_float()`. `Policy\Data\QuoteLineSnapshot`'s constructor is `(QuoteLineIdentity $identity, int $quantity = 0, float $unitPriceNet = 0.0, float $totalNet = 0.0, ?float $requestedUnitPrice = null)`. `Policy\Data\QuoteSnapshot`'s is `(string $currencyIso, float $totalNet, array $lines, QuoteLifecycle $lifecycle, ?float $buyerTargetNet = null)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/QuoteBaselineTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Policy\NetFactor;
use PHPUnit\Framework\TestCase;

/**
 * Issue #49. The baseline is what stops a multi-round per-line negotiation
 * compounding past the cap, so what it stores and what it refuses to parse
 * both matter.
 */
final class QuoteBaselineTest extends TestCase
{
    public function testItRoundTripsPricesQuantitiesAndTheTotal(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));

        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));

        self::assertNotNull($baseline);
        self::assertSame(1000.0, $baseline->totalNet);
        self::assertCount(2, $baseline->lines);
        self::assertSame('line-1', $baseline->lines[0]->lineItemId());
        self::assertSame(100.0, $baseline->lines[0]->unitPriceNet);
        self::assertSame(4, $baseline->lines[0]->quantity);
    }

    /**
     * Custom fields survive the database as JSON, so a float written as 100.0
     * can come back as the integer 100. Reading must not reject that.
     */
    public function testItAcceptsWholeNumbersThatJsonReturnedAsIntegers(): void
    {
        $baseline = QuoteBaseline::read(self::snapshot(customFields: [QuoteBaseline::KEY => [
            'totalNet' => 1000,
            'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => 100, 'quantity' => 4]],
        ]]));

        self::assertNotNull($baseline);
        self::assertSame(1000.0, $baseline->totalNet);
        self::assertSame(100.0, $baseline->lines[0]->unitPriceNet);
    }

    public function testAnAbsentBaselineReadsAsNull(): void
    {
        self::assertNull(QuoteBaseline::read(self::snapshot(customFields: [])));
    }

    /**
     * A baseline nobody can parse must not become a partial list — a partial
     * list is a smaller cap than the merchant set, silently.
     */
    public function testAMalformedBaselineReadsAsNullRatherThanAPartialList(): void
    {
        foreach ([
            'not an array' => [QuoteBaseline::KEY => 'nonsense'],
            'no total' => [QuoteBaseline::KEY => ['lines' => [['lineItemId' => 'a', 'unitPriceNet' => 1, 'quantity' => 1]]]],
            'no lines' => [QuoteBaseline::KEY => ['totalNet' => 10.0]],
            'empty lines' => [QuoteBaseline::KEY => ['totalNet' => 10.0, 'lines' => []]],
            'row missing quantity' => [QuoteBaseline::KEY => [
                'totalNet' => 10.0,
                'lines' => [['lineItemId' => 'a', 'unitPriceNet' => 1]],
            ]],
            'one bad row among good ones' => [QuoteBaseline::KEY => [
                'totalNet' => 10.0,
                'lines' => [
                    ['lineItemId' => 'a', 'unitPriceNet' => 1, 'quantity' => 1],
                    ['lineItemId' => 42, 'unitPriceNet' => 1, 'quantity' => 1],
                ],
            ]],
        ] as $case => $customFields) {
            self::assertNull(
                QuoteBaseline::read(self::snapshot(customFields: $customFields)),
                sprintf('"%s" was parsed instead of rejected.', $case),
            );
        }
    }

    /**
     * The reason quantities and totalNet are stored at all. NetFactor divides
     * totalNet by the sum of unitPriceNet * quantity to normalise gross-vs-net
     * price space, so a baseline that dropped quantities would produce a
     * different factor and a meaningless per-line comparison. This test fails
     * if anyone later "simplifies" the stored shape back to prices alone.
     */
    public function testTheStoredShapeKeepsTheNetFactorCoherent(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));
        self::assertNotNull($baseline);

        $live = self::policySnapshot();
        $reference = $baseline->asReferenceSnapshot($live);

        // 4 * 100 + 2 * 300 = 1000, and totalNet is 1000, so the factor is 1.0.
        self::assertSame(1.0, NetFactor::of($reference));

        $withoutQuantities = new \MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1000.0,
            lines: array_map(
                static fn(\MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot $l)
                    => new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
                        identity: $l->identity,
                        quantity: 1,
                        unitPriceNet: $l->unitPriceNet,
                    ),
                $baseline->lines,
            ),
            lifecycle: $live->lifecycle,
        );

        self::assertNotSame(
            NetFactor::of($reference),
            NetFactor::of($withoutQuantities),
            'Dropping quantities changed nothing, so the shape is not actually load-bearing.',
        );
    }

    /**
     * A line the buyer added after the agent started has no baseline entry.
     * Its current price is its baseline — it has had no concession yet — and
     * without this LinePriceOfferCheck would reject it as "not on this quote".
     */
    public function testALineAddedMidNegotiationTakesItsCurrentPriceAsItsOwnBaseline(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));
        self::assertNotNull($baseline);

        $added = new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
            identity: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity('line-3'),
            quantity: 1,
            unitPriceNet: 50.0,
        );

        $merged = $baseline->linesMergedWith([$added]);

        self::assertCount(3, $merged);
        self::assertSame('line-3', $merged[2]->lineItemId());
        self::assertSame(50.0, $merged[2]->unitPriceNet);
        self::assertSame(100.0, $merged[0]->unitPriceNet, 'A known line must keep its BASELINE price, not its current one.');
    }

    /** Prices come from the baseline; currency and lifecycle come from now. */
    public function testTheReferenceSnapshotTakesPricesFromTheBaselineAndTheRestFromTheLiveQuote(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));
        self::assertNotNull($baseline);

        $live = self::policySnapshot(totalNet: 700.0, currencyIso: 'CHF');
        $reference = $baseline->asReferenceSnapshot($live);

        self::assertSame(1000.0, $reference->totalNet, 'The reference must keep the ORIGINAL total.');
        self::assertSame('CHF', $reference->currencyIso);
        self::assertSame($live->lifecycle, $reference->lifecycle);
    }

    /** @param array<string, mixed> $customFields */
    private static function snapshot(array $customFields): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-09-01 10:00:00')),
            totals: new QuoteTotals(totalNet: 1000.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open', customFields: $customFields),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget'),
                    quantity: 4,
                    unitPriceNet: 100.0,
                    totalNet: 400.0,
                ),
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-2', 'Gadget'),
                    quantity: 2,
                    unitPriceNet: 300.0,
                    totalNet: 600.0,
                ),
            ]),
        );
    }

    private static function policySnapshot(
        float $totalNet = 1000.0,
        string $currencyIso = 'EUR',
    ): \MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot {
        return new \MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot(
            currencyIso: $currencyIso,
            totalNet: $totalNet,
            lines: [],
            lifecycle: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle(stateTechnicalName: 'open'),
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail for the right reason**

Run: `vendor/bin/phpunit --filter QuoteBaselineTest`
Expected: **Error** — `Class "MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline" not found`, on every test.

If instead you get a constructor arity error from one of the fixtures, fix the fixture — the production classes do not exist yet, so nothing else can be wrong.

- [ ] **Step 3: Write `QuoteBaselineLines`**

Create `src/Negotiation/QuoteBaselineLines.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;

/**
 * The quote's prices as the agent first found them (#49).
 *
 * Carries the total as well as the lines because the verifier reads all three
 * together: NetFactor::of() divides totalNet by the sum of
 * unitPriceNet * quantity to normalise gross-vs-net price space, so prices
 * without their quantities and total would produce a meaningless ratio.
 */
final readonly class QuoteBaselineLines
{
    /** @param list<PolicyLine> $lines */
    public function __construct(
        public float $totalNet,
        public array $lines,
    ) {}

    /**
     * Prices from the baseline, everything else from now. Currency, state and
     * expiry are not price history, and a stored copy of them would be a
     * second source of truth that goes stale.
     */
    public function asReferenceSnapshot(PolicySnapshot $live): PolicySnapshot
    {
        return new PolicySnapshot(
            currencyIso: $live->currencyIso,
            totalNet: $this->totalNet,
            lines: $this->lines,
            lifecycle: $live->lifecycle,
        );
    }

    /**
     * The baseline's lines, plus any current line it does not know about.
     *
     * A line added mid-negotiation has had no agent concession yet, so there
     * is nothing to compound and its current price IS its baseline. Without
     * this, LinePriceOfferCheck would reject it as "line … is not on this
     * quote" and escalate for no reason. A line REMOVED mid-negotiation
     * leaves a stale baseline entry that is simply never looked up.
     *
     * @param list<PolicyLine> $current
     *
     * @return list<PolicyLine>
     */
    public function linesMergedWith(array $current): array
    {
        $known = [];

        foreach ($this->lines as $line) {
            $known[$line->lineItemId()] = true;
        }

        $merged = $this->lines;

        foreach ($current as $line) {
            if (!isset($known[$line->lineItemId()])) {
                $merged[] = $line;
            }
        }

        return $merged;
    }
}
```

- [ ] **Step 4: Write `BaselineRow`**

Create `src/Negotiation/BaselineRow.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot as BridgeLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity as PolicyLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;

/**
 * One stored baseline row, in each direction. Split out of QuoteBaseline to
 * keep that class within the complexity gate, the same way
 * LineReferenceViolation was split out of LinePriceOfferCheck.
 */
final class BaselineRow
{
    /**
     * Null for anything that does not parse. The caller turns one null row
     * into a null baseline: a partial list would be a smaller cap than the
     * merchant set, applied silently.
     *
     * is_numeric rather than is_float because custom fields survive the
     * database as JSON, so 100.0 can come back as the integer 100.
     */
    public static function read(mixed $row): ?PolicyLine
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = $row['lineItemId'] ?? null;
        $unitPriceNet = $row['unitPriceNet'] ?? null;
        $quantity = $row['quantity'] ?? null;

        if (!\is_string($id) || !\is_numeric($unitPriceNet) || !\is_int($quantity)) {
            return null;
        }

        return new PolicyLine(
            identity: new PolicyLineIdentity(lineItemId: $id),
            quantity: $quantity,
            unitPriceNet: (float) $unitPriceNet,
        );
    }

    /** @return array<string, mixed> */
    public static function write(BridgeLine $line): array
    {
        return [
            'lineItemId' => $line->identity->lineItemId,
            'unitPriceNet' => $line->unitPriceNet,
            'quantity' => $line->quantity,
        ];
    }
}
```

- [ ] **Step 5: Write `QuoteBaseline`**

Create `src/Negotiation/QuoteBaseline.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot as BridgeSnapshot;

/**
 * The quote custom field that anchors per-line offers (#49, formerly #2(a)).
 *
 * Before this existed, the reference a per-line offer is bounded against was
 * re-captured every round, so round two was measured against round one's
 * already-reduced prices and compounded straight past maxDiscountPercent —
 * with the authorizer and the verifier both reporting clean.
 *
 * The key sits alongside merchant_quote_agent_serviced / _escalated /
 * _attempts and is shallow-merged by the gateway, so it cannot collide with
 * them or with the A2CN act chain.
 */
final class QuoteBaseline
{
    public const KEY = 'merchant_quote_agent_baseline';

    public static function read(BridgeSnapshot $snapshot): ?QuoteBaselineLines
    {
        $raw = $snapshot->lifecycle->customFields[self::KEY] ?? null;

        if (!\is_array($raw)) {
            return null;
        }

        $totalNet = $raw['totalNet'] ?? null;
        $rows = $raw['lines'] ?? null;

        if (!\is_numeric($totalNet) || !\is_array($rows) || $rows === []) {
            return null;
        }

        $lines = [];

        foreach ($rows as $row) {
            $line = BaselineRow::read($row);

            if ($line === null) {
                return null;
            }

            $lines[] = $line;
        }

        return new QuoteBaselineLines((float) $totalNet, $lines);
    }

    /**
     * The custom-field fragment for QuoteUpdate. Takes the whole snapshot
     * because the total is as load-bearing as the lines.
     *
     * @return array<string, mixed>
     */
    public static function stamp(BridgeSnapshot $snapshot): array
    {
        return [self::KEY => [
            'totalNet' => $snapshot->totals->totalNet,
            'lines' => array_map(BaselineRow::write(...), $snapshot->content->lines),
        ]];
    }
}
```

- [ ] **Step 6: Run the test and watch it pass**

Run: `vendor/bin/phpunit --filter QuoteBaselineTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: Format, lint and run the quality gate**

```bash
composer run format
vendor/bin/mago fmt tests/Unit/Negotiation/QuoteBaselineTest.php
vendor/bin/mago lint src/Negotiation/QuoteBaseline.php src/Negotiation/BaselineRow.php src/Negotiation/QuoteBaselineLines.php tests/Unit/Negotiation/QuoteBaselineTest.php
composer run quality
```

Expected: no lint issues, quality exit 0. If `too-many-methods` fires on the test class, split the malformed-input case into its own test class rather than suppressing it — the limit is 10 including private helpers.

- [ ] **Step 8: Verify the namespace purity guard still passes**

Run: `vendor/bin/phpunit --filter NamespacePurityTest`
Expected: PASS. The three new classes must import nothing from `Shopware\`.

- [ ] **Step 9: Commit**

```bash
git add src/Negotiation/QuoteBaseline.php src/Negotiation/BaselineRow.php src/Negotiation/QuoteBaselineLines.php tests/Unit/Negotiation/QuoteBaselineTest.php
git commit -m "feat: read and write the pre-negotiation quote baseline

The storage half of #49. A merchant_quote_agent_baseline custom field holding
the quote's line prices, quantities and total as the agent first found them.

Quantities and the total are stored because NetFactor divides totalNet by the
sum of unitPriceNet * quantity; prices alone would produce a meaningless
ratio. A malformed baseline reads as null rather than a partial list, because
a partial list is a smaller cap than the merchant set, applied silently.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Capture the baseline, and verify against it

**Files:**
- Modify: `src/Negotiation/OfferApplier.php`, `tests/Unit/Negotiation/NegotiationFixture.php`
- Test: `tests/Unit/Negotiation/OfferApplierTest.php`

**Interfaces:**
- Consumes: `QuoteBaseline::read()`, `QuoteBaseline::stamp()`, `QuoteBaselineLines::asReferenceSnapshot()` from Task 1.
- Produces: nothing new. Task 4 depends on the behaviour, not on a signature.

**Background:** `apply()` already reads `$reference = $gateway->fetchSnapshot($quoteId)` immediately before writing — that is the pre-negotiation state on the first pass that changes anything, which is why the capture belongs here and not at pass start. `write()` currently issues `updateQuote` in both of its branches; the baseline goes into whichever one runs. `VerifyOfferInput`'s own docblock already says its `reference` "must carry the catalog/contract-priced state of the quote structure (before any pricing round)" — this task is the caller finally honouring that.

- [ ] **Step 1: Write the failing tests**

First add a fixture helper, because `NegotiationFixture::snapshot()` is already at five parameters and cannot gain a sixth. In `tests/Unit/Negotiation/NegotiationFixture.php`:

```php
    /**
     * The same snapshot with different quote custom fields. A separate method
     * rather than a sixth parameter on snapshot(), which is already at the
     * parameter-count gate.
     *
     * @param array<string, mixed> $customFields
     */
    public static function withCustomFields(QuoteSnapshot $snapshot, array $customFields): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $snapshot->lifecycle->stateTechnicalName,
                expiresAt: $snapshot->lifecycle->expiresAt,
                customFields: $customFields,
            ),
            content: $snapshot->content,
        );
    }

    /** A stored baseline saying every line started at `$unitPriceNet`. */
    public static function baselineOf(float $totalNet, float $unitPriceNet): array
    {
        return [QuoteBaseline::KEY => [
            'totalNet' => $totalNet,
            'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => $unitPriceNet, 'quantity' => 10]],
        ]];
    }
```

Then add to `tests/Unit/Negotiation/OfferApplierTest.php`. `FakeQuoteGateway` already records every custom-field write in its public `$customFieldWrites`, and serves its snapshot queue in order — `apply()` fetches twice, once before the write and once after.

```php
    /**
     * #49. The pre-write snapshot IS the pre-negotiation state on the first
     * pass that writes, so the baseline rides in the update the applier
     * already makes — no extra write, no extra revision bump.
     */
    public function testTheFirstWritingPassStoresTheBaseline(): void
    {
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(), NegotiationFixture::snapshot()]);

        self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        $stored = null;

        foreach ($gateway->customFieldWrites as $write) {
            $stored ??= $write[QuoteBaseline::KEY] ?? null;
        }

        self::assertIsArray($stored, 'The first writing pass stored no baseline.');
        self::assertSame(1000.0, $stored['totalNet']);
        self::assertSame(100.0, $stored['lines'][0]['unitPriceNet']);
        self::assertSame(10, $stored['lines'][0]['quantity'], 'Quantities are what keep NetFactor coherent.');
    }

    /** A quote that already has one keeps it: the anchor must never move. */
    public function testASecondPassDoesNotOverwriteTheBaseline(): void
    {
        $withBaseline = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 900.0),
            NegotiationFixture::baselineOf(1000.0, 100.0),
        );
        $gateway = new FakeQuoteGateway([$withBaseline, $withBaseline]);

        self::applier()->apply($gateway, $withBaseline, NegotiationFixture::settings(), self::quoteWideOffer());

        foreach ($gateway->customFieldWrites as $write) {
            self::assertArrayNotHasKey(
                QuoteBaseline::KEY,
                $write,
                'The baseline was rewritten, so the anchor moves with every round.',
            );
        }
    }

    /**
     * The verifier must measure against the baseline, not the pre-write
     * snapshot. Asserted through behaviour rather than a spy because
     * OfferVerifier is final: the line is at 90 now but started at 100, and
     * the offer prices it at 85. Against the baseline that is 15% off and
     * breaches the 10% cap; against the pre-write snapshot it is 5.6% off and
     * looks clean. Only one of those fails.
     */
    public function testTheVerifierMeasuresAgainstTheBaselineNotThePreWriteSnapshot(): void
    {
        $before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 900.0),
            NegotiationFixture::baselineOf(1000.0, 100.0),
        );
        $after = NegotiationFixture::snapshot(totalNet: 850.0);
        $gateway = new FakeQuoteGateway([$before, $after]);

        $offer = new ProposedOffer(
            orderTotalNet: 900.0,
            price: new OfferedPrice(linePricesNet: [new QuoteLinePrice('line-1', 85.0)]),
        );

        $applied = self::applier()->apply($gateway, $before, NegotiationFixture::settings(), $offer);

        self::assertFalse(
            $applied->verified,
            'An 85 line against a 100 baseline is 15% off and must breach the 10% cap; '
            . 'passing means the pre-write 90 was used as the reference.',
        );
    }
```

- [ ] **Step 2: Run them and watch them fail for the right reason**

Run: `vendor/bin/phpunit --filter OfferApplierTest`
Expected: the three new tests FAIL — no baseline in the update, and the verifier's reference total is the pre-write value rather than 1000.0. The pre-existing tests in the class must still pass.

- [ ] **Step 3: Capture the baseline in `write()`**

In `src/Negotiation/OfferApplier.php`, `write()` currently builds two `QuoteUpdate`s. Give it the pre-write snapshot so it can stamp, and merge the fragment into whichever branch runs:

```php
        $linePrices = $offer->price->linePricesNet;
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $limits->validityDays));

        // #49: the snapshot read immediately above is the pre-negotiation
        // state on the first pass that writes anything, so the baseline rides
        // in the update this method already issues. A pass that escalates
        // writes nothing and stores nothing, which is correct — nothing
        // changed, so the next pass's prices are still the original ones.
        $baseline = QuoteBaseline::read($reference) === null ? QuoteBaseline::stamp($reference) : null;

        if ($linePrices !== null && $linePrices !== []) {
            $gateway->updateLineItems($quoteId, array_map(self::lineChange(...), $linePrices), $expected);
            $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: $expiresAt, customFields: $baseline));

            return ['updateLineItems', 'updateQuote'];
        }

        $gateway->updateQuote(
            $quoteId,
            new QuoteUpdate(
                discount: new Discount(DiscountType::Percentage, $offer->price->discountPercent ?? 0.0),
                expiresAt: $expiresAt,
                customFields: $baseline,
            ),
            $expected,
        );

        return ['updateQuote'];
```

`write()`'s signature gains the pre-write snapshot in place of the revision it currently takes — pass `$reference` and read `$reference->revision` inside, so the parameter count does not grow past five. Update the call in `apply()` accordingly.

- [ ] **Step 4: Use the baseline as the verifier's reference**

In `apply()`, replace the `VerifyOfferInput` construction:

```php
        $live = SnapshotAdapter::toPolicy($reference);
        $baselineLines = QuoteBaseline::read($reference);

        $after = $gateway->fetchSnapshot($quoteId);
        $violations = $this->verifier->verify(new VerifyOfferInput(
            // #49: the baseline when the quote has one, so the line check, the
            // totals check and NetFactor's normalisation are all anchored to
            // the original prices. On the first pass there is none and the
            // pre-write snapshot IS the original, so this degrades correctly.
            reference: $baselineLines?->asReferenceSnapshot($live) ?? $live,
            final: SnapshotAdapter::toPolicy($after),
            limits: $limits,
            now: new \DateTimeImmutable(),
        ));
```

Add `use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;` — same namespace, so no import is needed; only `QuoteBaselineLines` would need one if referenced by name, which it is not.

- [ ] **Step 5: Run the tests and watch them pass**

Run: `vendor/bin/phpunit --filter OfferApplierTest`
Expected: PASS, including the pre-existing tests.

- [ ] **Step 6: Run the full unit suite and the quality gate**

```bash
vendor/bin/phpunit
composer run format && vendor/bin/mago fmt tests/Unit/Negotiation/OfferApplierTest.php tests/Unit/Negotiation/NegotiationFixture.php
composer run quality
```

Expected: green, exit 0.

- [ ] **Step 7: Commit**

```bash
git add src/Negotiation/OfferApplier.php tests/Unit/Negotiation/OfferApplierTest.php tests/Unit/Negotiation/NegotiationFixture.php
git commit -m "feat: capture the baseline and verify against it

The write half of #49. The snapshot OfferApplier reads immediately before
writing is the pre-negotiation state on the first pass that changes anything,
so the baseline rides in the QuoteUpdate that method already issues — no extra
write and no extra revision bump. A pass that escalates stores nothing, which
is correct because nothing changed.

The verifier's reference becomes the baseline when one exists, which anchors
the line check, the totals check and NetFactor's normalisation together.
VerifyOfferInput's docblock always said its reference must carry the state
before any pricing round; this is the caller finally honouring it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Bound the authorizer against the baseline

**Files:**
- Modify: `src/Negotiation/OfferRound.php`, `src/Negotiation/OfferProposer.php`
- Test: `tests/Unit/Negotiation/OfferProposerTest.php`

**Interfaces:**
- Consumes: `QuoteBaseline::read(BridgeSnapshot): ?QuoteBaselineLines` and `QuoteBaselineLines::$lines` from Task 1.
- Produces: `OfferProposer::propose(QuoteAgentSettings $settings, PolicySnapshot $snapshot, QuoteDecision $decision, BuyerConversation $conversation, ?QuoteBaselineLines $baseline = null): ProposedAnswer` — Task 4 calls this same signature.

**Background:** `OfferProposer::authorize()` currently takes `PolicySnapshot $snapshot` and uses it for exactly one thing, `$snapshot->lines`. It is already at five parameters, so it cannot gain a sixth; changing that parameter to `array $referenceLines` both makes room and states what it actually depends on.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Negotiation/OfferProposerTest.php`:

```php
    /**
     * #49. The distinguishing fixture: the baseline price is HIGHER than the
     * current line price, so a test that confused the two would land on a
     * different number rather than passing by coincidence. The buyer's line
     * is at 90 after an earlier round; the baseline says it started at 100.
     * A 10% cap therefore floors this line at 90, not at 81.
     */
    public function testAPerLineOfferIsBoundedAgainstTheBaselineNotTheCurrentLines(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":85}],"message":"85 each."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(totalNet: 900.0);
        $baseline = new \MerchantQuoteAgentPlugin\Negotiation\QuoteBaselineLines(1000.0, [
            new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
                identity: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity('line-1'),
                quantity: 10,
                unitPriceNet: 100.0,
            ),
        ]);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
                $baseline,
            );

        self::assertNull(
            $answer->offer,
            'An 85 line against a 100 baseline is 15% off and must be rejected by a 10% cap; '
            . 'accepting it means the current 90 line was used as the reference.',
        );
        self::assertSame(QuoteEscalationReason::ProposalRejected, $answer->escalation);
    }

    /** The same offer is fine when the baseline says the line started at 90. */
    public function testTheBaselineIsWhatDecides(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":85}],"message":"85 each."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(totalNet: 900.0);
        $baseline = new \MerchantQuoteAgentPlugin\Negotiation\QuoteBaselineLines(900.0, [
            new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
                identity: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity('line-1'),
                quantity: 10,
                unitPriceNet: 90.0,
            ),
        ]);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
                $baseline,
            );

        self::assertNotNull($answer->offer, 'An 85 line against a 90 baseline is 5.6% off, inside a 10% cap.');
    }
```

- [ ] **Step 2: Run them and watch them fail for the right reason**

Run: `vendor/bin/phpunit --filter OfferProposerTest`
Expected: FAIL — `propose()` does not accept a fifth argument yet (`ArgumentCountError`, or a signature error).

- [ ] **Step 3: Thread the baseline through `OfferProposer`**

Add the parameter and use it as the reference:

```php
    /**
     * @param ?QuoteBaselineLines $baseline the quote's prices as the agent
     *     first found them (#49). Null on the first pass, where the current
     *     lines ARE the original ones.
     *
     * @throws ModelUnavailable
     */
    public function propose(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        BuyerConversation $conversation,
        ?QuoteBaselineLines $baseline = null,
    ): ProposedAnswer {
        $referenceLines = $baseline === null
            ? $snapshot->lines
            : $baseline->linesMergedWith($snapshot->lines);
```

Inside, replace every `$this->authorize($settings, $snapshot, …)` call with `$this->authorize($settings, $referenceLines, …)` — computed once at the top of `propose()`, as shown above — and change `authorize()`:

```php
    /**
     * The bands are checked against the quote's pre-negotiation lines (#49):
     * a per-line offer is bounded line by line against them, and with no
     * reference LinePriceOfferCheck rejects every one of them.
     *
     * @param list<\MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot> $referenceLines
     */
    private function authorize(
        QuoteAgentSettings $settings,
        array $referenceLines,
        ProposedOffer $offer,
        string $message,
        ?string $promptHash,
    ): ProposedAnswer {
        $offer = $offer->withReferenceLines($referenceLines);
```

`atTheBuyersLevel()` keeps taking `PolicySnapshot $snapshot` for now; Task 4 changes its body.

- [ ] **Step 4: Read the baseline in `OfferRound`**

In `src/Negotiation/OfferRound.php`, `play()` already holds the bridge snapshot. Pass the baseline down:

```php
        $conversation = SnapshotAdapter::conversation($snapshot);
        $baseline = QuoteBaseline::read($snapshot);
        $answer = $this->proposer->propose(
            $settings,
            SnapshotAdapter::toPolicy($snapshot),
            $decision->price,
            $conversation,
            $baseline,
        );
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `vendor/bin/phpunit --filter 'OfferProposerTest|OfferRoundTest'`
Expected: PASS.

- [ ] **Step 6: Full suite and quality gate**

```bash
vendor/bin/phpunit
composer run format && vendor/bin/mago fmt tests/Unit/Negotiation/OfferProposerTest.php
composer run quality
```

Expected: green, exit 0.

- [ ] **Step 7: Commit**

```bash
git add src/Negotiation/OfferProposer.php src/Negotiation/OfferRound.php tests/Unit/Negotiation/OfferProposerTest.php
git commit -m "feat: bound per-line offers against the baseline

The authorizer half of #49. OfferRound reads the stored baseline and hands it
to the proposer, which uses it as the reference LinePriceOfferCheck bounds
against instead of the round's own lines. Null on the first pass, where the
current lines are the original ones.

authorize() took a whole PolicySnapshot to read one property from it, and was
already at five parameters. It now takes the reference lines directly, which
makes room and states what it actually depends on.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Remove the stopgap and answer per-line on every round

**Files:**
- Modify: `src/Negotiation/OfferRound.php`, `src/Negotiation/OfferProposer.php`
- Test: `tests/Unit/Negotiation/OfferRoundTest.php`, `tests/Unit/Negotiation/OfferProposerTest.php`

**Interfaces:**
- Consumes: `QuoteBaseline::read()` from Task 1, already called in `OfferRound::play()` by Task 3.
- Produces: nothing.

**Background:** `OfferRound.php:62` escalates any per-line offer once `$conversation->agent !== []`. That guard existed only because the reference was re-derived per round, which Tasks 1-3 fix. It is replaced, not deleted outright: a quote serviced before this shipped has no baseline, and must keep escalating. `ServicingFingerprint::MARKER_KEY` is the "serviced before" signal, and it lives in `$snapshot->lifecycle->customFields`.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Negotiation/OfferRoundTest.php`, replace the existing second-round escalation test with:

```php
    /**
     * #49 removed the blanket second-round escalation, but a quote serviced
     * before the baseline existed still has no anchor, so a per-line offer on
     * it would be measured against already-reduced prices. Those keep
     * escalating until they close.
     */
    public function testAPerLineOfferOnAQuoteServicedBeforeTheBaselineExistedStillEscalates(): void
    {
        $round = self::round(perLineOffer: true);
        $snapshot = self::snapshotWith(
            agentComment: true,
            customFields: [ServicingFingerprint::MARKER_KEY => 'some-old-stamp'],
        );

        $pass = $round->play(self::gateway(), $snapshot, self::settings(), self::decision(), null);

        self::assertSame(NegotiationOutcome::Escalated, $pass->outcome);
    }

    /** With a baseline present, round two is answered rather than handed to a human. */
    public function testAPerLineOfferOnALaterRoundIsAnsweredOnceTheQuoteHasABaseline(): void
    {
        $round = self::round(perLineOffer: true);
        $snapshot = self::snapshotWith(
            agentComment: true,
            customFields: [
                ServicingFingerprint::MARKER_KEY => 'some-old-stamp',
                QuoteBaseline::KEY => [
                    'totalNet' => 1000.0,
                    'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => 100.0, 'quantity' => 10]],
                ],
            ],
        );

        $pass = $round->play(self::gateway(), $snapshot, self::settings(), self::decision(), null);

        self::assertNotSame(
            NegotiationOutcome::Escalated,
            $pass->outcome,
            'A per-line round two with a baseline was escalated: the stopgap is still in place.',
        );
    }
```

Reuse the file's existing fixtures and fake gateway; add `customFields` to its snapshot helper rather than writing a second one.

- [ ] **Step 2: Run them and watch them fail for the right reason**

Run: `vendor/bin/phpunit --filter OfferRoundTest`
Expected: the second test FAILS with "the stopgap is still in place", because the guard escalates regardless of the baseline. The first test passes already — that is expected, and Step 5 proves it is not vacuous.

- [ ] **Step 3: Replace the guard**

In `src/Negotiation/OfferRound.php`, replace the `#2(a)` block:

```php
        if ($answer->offer->price->linePricesNet !== null && $baseline === null && self::servicedBefore($snapshot)) {
            // #49 anchors per-line offers to a stored baseline, so round two
            // is no longer a human's — except on quotes serviced before that
            // baseline existed. Those have no anchor, so a per-line offer on
            // them would still be measured against already-reduced prices.
            // Retires itself as those quotes close.
            $this->logger->info('A per-line ask on a quote with no stored baseline; a human takes it.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalated($gateway, $snapshot, null, $extractHash, $answer->promptHash);
        }
```

and add:

```php
    /** A quote the agent has answered before carries the servicing fingerprint. */
    private static function servicedBefore(QuoteSnapshot $snapshot): bool
    {
        return ($snapshot->lifecycle->customFields[ServicingFingerprint::MARKER_KEY] ?? null) !== null;
    }
```

Import `ServicingFingerprint` and `QuoteBaseline`. `$baseline` is already in scope from Task 3.

- [ ] **Step 4: Lift #47's first-round restriction**

In `src/Negotiation/OfferProposer.php`, `atTheBuyersLevel()` currently returns the offer unchanged once the agent has replied. Remove that condition so the mirror runs on every round, and rewrite the docblock — the restriction's whole justification was the guard Step 3 just replaced:

```php
    /**
     * #47: a buyer who itemised the ask must not be answered with a quote-wide
     * percentage. The rules-only path picks the level from `perLineAsks`
     * already; the model is merely told to, so its answer is corrected here.
     *
     * This ran on the first round only until #49, because OfferRound escalated
     * any per-line offer on a later round and converting one would have turned
     * an answerable quote into a human's. The stored baseline removed that
     * guard, so the correction now applies to every round.
     */
    private static function atTheBuyersLevel(ProposedOffer $offer, PolicySnapshot $snapshot): ProposedOffer
    {
        return OfferLevelMirror::mirror($offer, $snapshot->lines);
    }
```

The `BuyerConversation` parameter goes away; update the call site in `propose()`. Note the mirror still takes the **current** lines, not the baseline: it is re-expressing a percentage as today's prices, and the baseline's job is bounding, not pricing.

Then update `testAQuoteWideAnswerIsLeftAloneOnceTheAgentHasAlreadyReplied` in `OfferProposerTest` — that behaviour is now the opposite. Rename it to `testAQuoteWideAnswerIsConvertedOnALaterRoundToo` and assert `discountPercent` is null and `linePricesNet` is set.

- [ ] **Step 5: Prove the in-flight guard is not vacuous**

Temporarily change the guard's condition from `$baseline === null && self::servicedBefore($snapshot)` to `false`, run `vendor/bin/phpunit --filter testAPerLineOfferOnAQuoteServicedBeforeTheBaselineExistedStillEscalates`, and confirm it FAILS. Restore the condition and re-run to green. Record both outputs in your report, and confirm `git diff src/Negotiation/OfferRound.php` shows only the intended change before committing.

- [ ] **Step 6: Run everything**

```bash
vendor/bin/phpunit
composer run format && vendor/bin/mago fmt tests/Unit/Negotiation/OfferRoundTest.php tests/Unit/Negotiation/OfferProposerTest.php
composer run quality
composer run test:integration
```

Expected: unit green; quality exit 0; integration green **except** the pre-existing `PluginConfigTest` failure. In particular `NegotiationPipelineTest::testAnInBandAskIsAppliedToTheQuoteAndAnswered` and `DecisionRecordTest::testARealPassWritesARealRow` must pass — those are the two that flipped to `escalated` during #47 and are the reason this guard existed.

- [ ] **Step 7: Commit**

```bash
git add src/Negotiation/OfferRound.php src/Negotiation/OfferProposer.php tests/Unit/Negotiation/OfferRoundTest.php tests/Unit/Negotiation/OfferProposerTest.php
git commit -m "feat: answer per-line asks on every round

Removes the stopgap #49 exists to retire. OfferRound escalated any per-line
offer once the agent had replied, because the reference was re-derived each
round; the stored baseline is that reference now, so round two is answerable.

The guard is replaced rather than deleted: a quote serviced before the
baseline existed has no anchor, so per-line offers on it still escalate. That
condition retires itself as those quotes close.

Also lifts #47's first-round restriction on OfferLevelMirror, whose only
justification was the guard this removes.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Prove the cap holds across three rounds

**Files:**
- Create: `tests/Integration/BaselineCompoundingTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-4. Produces nothing.

**Background:** this is #2's own stated fixture requirement — *"a 3+ round per-line chain must not end below `catalogPrice * (1 - maxDiscountPercent/100)` on any line; one that would is rejected or escalated"* — and nothing in the suite exercises it. It is the test that would have caught the original defect, and the one that will catch its return.

Use `PipelineFixture` and `QuoteFixture` the way `NegotiationPipelineTest` does. The cap in `PipelineFixture::enabledSettings()` is `maxDiscountPercent: 10.0`. Drive three rounds by writing a buyer comment and running `$pipeline->service(...)` three times against the same quote, scripting the model to ask for a further cut each round.

- [ ] **Step 1: Write the test**

Create `tests/Integration/BaselineCompoundingTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use Shopware\Core\Framework\Context;

/**
 * #2(a)'s own fixture requirement, finally exercised: a multi-round per-line
 * negotiation must not drift past the cap relative to the ORIGINAL prices.
 *
 * Before #49 the reference was re-captured each round, so three rounds of
 * "10% off" compounded to roughly 27% with the authorizer and the verifier
 * both reporting clean. This is the test that would have caught that.
 */
final class BaselineCompoundingTest extends IntegrationTestCase
{
    use PipelineFixture;

    public function testThreePerLineRoundsCannotDriftPastTheCap(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $original = $gateway->fetchSnapshot($quoteId);
        $originalPrices = self::unitPricesById($original);
        $originalTotal = $original->totals->totalNet;

        for ($round = 1; $round <= 3; $round++) {
            self::writeBuyerComment($quoteId, sprintf('Round %d: I need a better per-unit price.', $round));

            self::pipelineWith([
                '{"additional_discount_percent": 10}',
                self::modelOffersTenPercentOffEveryLine($gateway->fetchSnapshot($quoteId)),
                'Here is our best price.',
            ])->service(
                $gateway->fetchSnapshot($quoteId),
                $gateway,
                self::enabledSettings(),
                NegotiationFixture::context(),
            );
        }

        $final = $gateway->fetchSnapshot($quoteId);
        $floorFactor = 1 - (self::enabledSettings()->policy->price->maxDiscountPercent / 100);

        foreach ($final->content->lines as $line) {
            $originalPrice = $originalPrices[$line->identity->lineItemId] ?? null;

            if ($originalPrice === null) {
                continue; // Shopware-generated line; the totals assertion covers it.
            }

            self::assertGreaterThanOrEqual(
                round($originalPrice * $floorFactor, 2) - 0.01,
                round($line->unitPriceNet, 2),
                sprintf(
                    'Line "%s" ended at %s, below the %s floor set by the ORIGINAL price %s — '
                    . 'the per-line reference is compounding again.',
                    $line->identity->label ?? $line->identity->lineItemId,
                    $line->unitPriceNet,
                    $originalPrice * $floorFactor,
                    $originalPrice,
                ),
            );
        }

        self::assertGreaterThanOrEqual(
            round($originalTotal * $floorFactor, 2) - 0.01,
            round($final->totals->totalNet, 2),
            'The total drifted below the cap relative to the original total.',
        );
    }

    /** @return array<string, float> */
    private static function unitPricesById(\MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot): array
    {
        $prices = [];

        foreach ($snapshot->content->lines as $line) {
            $prices[$line->identity->lineItemId] = $line->unitPriceNet;
        }

        return $prices;
    }

    /** A model answer taking a further 10% off each line's CURRENT price. */
    private static function modelOffersTenPercentOffEveryLine(
        \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot,
    ): string {
        $rows = [];

        foreach ($snapshot->content->lines as $line) {
            $rows[] = [
                'line_item_id' => $line->identity->lineItemId,
                'unit_price_net' => round($line->unitPriceNet * 0.9, 2),
            ];
        }

        return (string) json_encode([
            'action' => 'offer',
            'line_prices' => $rows,
            'message' => 'A further 10% off each line.',
        ]);
    }
}
```

- [ ] **Step 2: Run it**

Run: `composer run test:integration -- --filter BaselineCompoundingTest`
Expected: PASS. Rounds two and three are rejected by the authorizer or the verifier and escalate, so the quote never drifts below the floor.

If it FAILS with a line below the floor, the baseline is not reaching one of the two reference sites — check Task 2's verifier reference and Task 3's authorizer reference before changing this test. **Do not relax the assertion to make it pass.**

- [ ] **Step 3: Prove it is not vacuous**

This test is written after the implementation, so it should pass immediately. Prove it can fail: temporarily change `OfferApplier`'s verifier reference back to `$live` and `OfferProposer`'s to `$snapshot->lines`, re-run, and confirm a line ends below the floor with the "compounding again" message. Restore both and re-run to green. Record both outputs, and confirm `git diff src/` is empty before committing.

- [ ] **Step 4: Full verification**

```bash
vendor/bin/phpunit
composer run quality
composer run test:integration
```

Expected: unit green, quality exit 0, integration green except the pre-existing `PluginConfigTest` failure.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/BaselineCompoundingTest.php
git commit -m "test: three per-line rounds cannot drift past the cap

#2(a)'s own stated fixture requirement, finally exercised. Before #49 the
per-line reference was re-captured each round, so three rounds of 10% off
compounded to roughly 27% with the authorizer and the verifier both reporting
clean.

Asserts the floor on every line AND on the total, because the totals check
reads the same reference and leaks the same way for per-line rounds.

Verified non-vacuous by reverting both reference sites and watching a line end
below the floor.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Done when

Every box above is checked and:

- `vendor/bin/phpunit` is green.
- `composer run test:integration` shows only the pre-existing `PluginConfigTest` failure.
- `composer run quality` exits 0.
- A three-round per-line chain holds the cap against the original prices, on both lines and total.
- `OfferRound`'s blanket second-round escalation is gone, replaced by the narrower no-baseline case.
