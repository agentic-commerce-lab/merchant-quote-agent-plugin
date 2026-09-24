# Minimum-Margin Floor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A merchant-configured `minMarginPercent` puts a per-line price floor of `purchasePrice × (1 + m/100)` under every offer the agent writes. The floor clamps the price down to it; it never escalates.

**Architecture:** A config field on `QuoteLimits`. A Bridge reader for `product.purchasePrices`, behind a Negotiation interface. Three pure Policy classes: floor math, offer clamp, post-write check. `OfferApplier`, the only place offers are written, wires them together: compute floors → clamp the offer → write (resetting the quote discount when the clamp fired) → verify.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL, PHPUnit, Mago (fmt/lint/analyze), Symfony Validator.

**Spec:** `docs/superpowers/specs/2026-09-24-min-margin-floor-design.md` — read it before starting any task.

## Global Constraints

- `declare(strict_types=1)` in every file; target PHP 8.3.
- Gate thresholds: cyclomatic complexity 10 per class, nesting depth 4, **at most 5 parameters**, ~400 lines/file.
- `src/Negotiation` must not import Shopware (except `IllegalTransitionException`); `src/Policy` imports no Shopware at all (`NamespacePurityTest`).
- Symfony Validator runs only in `QuoteAgentSettingsFactory`. Do not add a validation site.
- **The model never sees a purchase price or a floor.** Nothing touches `OfferProposer`, `AuthorityBrief`, `PromptComposer`, `Policy\Data\QuoteLineSnapshot` or `Bridge\Data\QuoteLineSnapshot`.
- Log context carries the quote id only: no purchase prices, no floors, no line ids.
- Money tolerance is `Epsilon::MONEY` (0.01). Rounding is `MoneyMath::roundMoney()`. Floors round **up** to the cent.
- Per-task checks: `composer run format:check && composer run lint`, `composer run typecheck`, `composer run test`. Run `composer run format` to fix formatting.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Commits are signed through the 1Password desktop app: if signing fails, retry the same commit. Do not disable signing.

---

### Task 1: `minMarginPercent` configuration

**Files:**
- Modify: `src/Resources/config/config.xml` (Negotiation policies card, after `counterOfferMaxPercent`)
- Modify: `src/Config/QuoteAgentSettingsReader.php` (`KEYS`)
- Modify: `src/Config/NegotiationPolicyArray.php` (`build()`)
- Modify: `src/Policy/Data/QuoteLimits.php`
- Modify: `docs/end-to-end.md` (configuration table, §6)
- Test: `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php`, `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`, `tests/Unit/Config/QuoteAgentSettingsReaderTest.php`

**Interfaces:**
- Produces: `QuoteLimits::$minMarginPercent` (`?float`, the last constructor parameter, default `null`). Later tasks read `$settings->policy->price->minMarginPercent`.

- [ ] **Step 1: Write the failing tests**

Add to `NegotiationPolicyValidationTest`:

```php
    public function testANegativeMinimumMarginIsRejected(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            validityDays: 14,
            minMarginPercent: -1.0,
        ));

        self::assertSame(['price.minMarginPercent'], self::paths(self::validator()->validate($policy)));
    }

    public function testAZeroMinimumMarginIsValidAndMeansNeverBelowCost(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            validityDays: 14,
            minMarginPercent: 0.0,
        ));

        self::assertSame([], self::paths(self::validator()->validate($policy)));
    }

    public function testTighteningTheDiscountCapKeepsTheMinimumMargin(): void
    {
        // CappedAuthority rebuilds the limits through withMaxDiscountPercent()
        // on every round where the buyer asks for less than the cap. Dropping
        // the margin there would switch the floor off on exactly those rounds.
        $limits = new QuoteLimits(maxDiscountPercent: 15.0, validityDays: 14, minMarginPercent: 10.0);

        self::assertSame(10.0, $limits->withMaxDiscountPercent(5.0)->minMarginPercent);
    }
```

Add to `QuoteAgentSettingsFactoryTest`:

```php
    public function testTheMinimumMarginMapsOntoThePriceLimits(): void
    {
        self::assertSame(10.0, self::build(['minMarginPercent' => 10.0])?->policy->price->minMarginPercent);
    }

    public function testABlankMinimumMarginMeansNoFloor(): void
    {
        self::assertNull(self::build()?->policy->price->minMarginPercent);
        self::assertNull(self::build(['minMarginPercent' => null])?->policy->price->minMarginPercent);
    }
```

Add to `QuoteAgentSettingsReaderTest`. This pins the `KEYS` entry: without it the field is silently never read.

```php
    public function testTheMinimumMarginIsReadFromSystemConfig(): void
    {
        $settings = $this->reader(['minMarginPercent' => 12.5])->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame(12.5, $settings->policy->price->minMarginPercent);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter 'MinimumMargin'`
Expected: FAIL. `Unknown named parameter $minMarginPercent`, or `Undefined property`.

- [ ] **Step 3: Implement**

`QuoteLimits`: add as the LAST constructor parameter, after `validityDays`:

```php
        /**
         * Markup on the purchase price below which no offer may price a line
         * (spec 2026-09-24). Null means off. Zero means "never below cost".
         * No upper bound: a markup can exceed 100%.
         */
        #[Assert\PositiveOrZero]
        public ?float $minMarginPercent = null,
```

In `withMaxDiscountPercent()` pass `minMarginPercent: $this->minMarginPercent,`. In `fromArray()` add `minMarginPercent: OptionalShape::float($data, 'minMarginPercent'),`.

`NegotiationPolicyArray::build()`: add to `$price` after `counterOfferMaxPercent`:

```php
            // Null when cleared, and null is the safe reading: no floor, so
            // the agent behaves exactly as it did before the field existed.
            'minMarginPercent' => RawConfigValue::float($raw, 'minMarginPercent'),
```

`QuoteAgentSettingsReader::KEYS`: add `'minMarginPercent',` after `'counterOfferMaxPercent',`.

`config.xml`: insert after the `counterOfferMaxPercent` input-field:

```xml
        <input-field type="float">
            <name>minMarginPercent</name>
            <label>Minimum margin on purchase price (%)</label>
            <helpText>The agent never prices a product below its purchase price plus this markup: a purchase price of 100 and 10 here make 110 the lowest it may offer. It lowers an offer to that floor instead of escalating. Products without a purchase price have no floor. Blank means off; 0 means never below cost.</helpText>
        </input-field>
```

`docs/end-to-end.md` §6 table: add a row after `counterOfferMaxPercent`:

```markdown
| `minMarginPercent` | — | Markup on each product's purchase price that no offer may go below (`purchase × (1 + m/100)`, rounded up to the cent). Clamps the offer to that floor rather than escalating. Products without a purchase price have no floor. Blank means off; `0` means never below cost. The purchase price never reaches the model or the buyer. |
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter 'MinimumMargin|ConfigXmlSchemaTest|NegotiationPolicyValidationTest|QuoteAgentSettings'`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Resources/config/config.xml src/Config src/Policy/Data/QuoteLimits.php docs/end-to-end.md tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php tests/Unit/Config
git commit -m "feat(config): minimum margin on purchase price

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Floor math (`GoodsFactor`, `MarginFloors`)

**Files:**
- Create: `src/Policy/GoodsFactor.php`
- Create: `src/Policy/MarginFloors.php`
- Test: `tests/Unit/Policy/MarginFloorsTest.php`

**Interfaces:**
- Produces:
  - `GoodsFactor::of(list<Policy\Data\QuoteLineSnapshot> $lines): float`: (Σ positive line totals + Σ negative line totals) / Σ positive, clamped to [0, 1]; 1.0 when there are no positive lines. A line total here is `unitPriceNet × quantity`.
  - `MarginFloors::of(Policy\Data\QuoteSnapshot $live, array<string, float> $purchaseNetByProduct, float $marginPercent): array<string, float>`: lineItemId → effective floor net.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Policy/MarginFloorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\GoodsFactor;
use MerchantQuoteAgentPlugin\Policy\MarginFloors;
use PHPUnit\Framework\TestCase;

final class MarginFloorsTest extends TestCase
{
    public static function line(string $id, float $unit, int $quantity = 1, ?string $productId = null): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($id, $id, $productId ?? 'prod-' . $id),
            quantity: $quantity,
            unitPriceNet: $unit,
            totalNet: $unit * $quantity,
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    public static function quote(array $lines): QuoteSnapshot
    {
        $total = array_sum(array_map(static fn(QuoteLineSnapshot $l): float => $l->totalNet, $lines));

        return new QuoteSnapshot('EUR', $total, $lines, new QuoteLifecycle('open'));
    }

    public function testTheUsersWorkedExample(): void
    {
        // Listed at 120, purchase price 100, minimum margin 10%: 110.
        $floors = MarginFloors::of(self::quote([self::line('a', 120.0)]), ['prod-a' => 100.0], 10.0);

        self::assertSame(['a' => 110.0], $floors);
    }

    public function testTheFloorRoundsUpToTheCent(): void
    {
        // 33.33 × 1.10 = 36.663 -> 36.67, never 36.66.
        $floors = MarginFloors::of(self::quote([self::line('a', 50.0)]), ['prod-a' => 33.33], 10.0);

        self::assertSame(36.67, $floors['a']);
    }

    public function testAnExactCentIsNotRoundedUpByFloatNoise(): void
    {
        // 100 × 1.1 is 110.00000000000001 in floating point.
        $floors = MarginFloors::of(self::quote([self::line('a', 200.0)]), ['prod-a' => 100.0], 10.0);

        self::assertSame(110.0, $floors['a']);
    }

    public function testALinePricedBelowItsFloorIsFlooredAtItsOwnPrice(): void
    {
        // Never raised: the floor for a loss leader is the price it already has.
        $floors = MarginFloors::of(self::quote([self::line('a', 90.0)]), ['prod-a' => 100.0], 10.0);

        self::assertSame(90.0, $floors['a']);
    }

    public function testAnExistingQuoteDiscountCountsTowardsTheLivePrice(): void
    {
        // Two lines of 100 and a -10 quote-discount line: every line costs the
        // buyer 95 today, so a floor of 99 is already undercut and stays at 95.
        $floors = MarginFloors::of(
            self::quote([self::line('a', 100.0), self::line('b', 100.0), self::line('discount', -10.0, 1, null)]),
            ['prod-a' => 50.0, 'prod-b' => 90.0],
            10.0,
        );

        self::assertSame(['a' => 55.0, 'b' => 95.0], $floors);
    }

    public function testLinesWithoutAPurchasePriceHaveNoFloor(): void
    {
        $floors = MarginFloors::of(self::quote([self::line('a', 100.0), self::line('b', 100.0)]), ['prod-a' => 50.0], 0.0);

        self::assertSame(['a' => 50.0], $floors);
    }

    public function testTheGoodsFactorIsTheShareTheNegativeLinesLeave(): void
    {
        self::assertSame(1.0, GoodsFactor::of([]));
        self::assertSame(1.0, GoodsFactor::of([self::line('a', 100.0, 3)]));
        self::assertSame(0.95, GoodsFactor::of([self::line('a', 100.0, 2), self::line('d', -10.0)]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter MarginFloorsTest`
Expected: FAIL, `Class "MerchantQuoteAgentPlugin\Policy\MarginFloors" not found`.

- [ ] **Step 3: Implement**

`src/Policy/GoodsFactor.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * What share of its line price a positive line actually costs the buyer, once
 * the negative lines are taken off. A quote-wide percentage discount is a
 * negative line SwagCommercial generates (see QuoteBaselineLines), so this is
 * the factor that discount applies.
 *
 * Built from the lines rather than from `totalNet`, unlike NetFactor, because
 * `totalNet` carries shipping and would make every line look dearer than it is.
 */
final class GoodsFactor
{
    private function __construct() {}

    /** @param list<QuoteLineSnapshot> $lines */
    public static function of(array $lines): float
    {
        $positive = 0.0;
        $negative = 0.0;

        foreach ($lines as $line) {
            $total = $line->unitPriceNet * $line->quantity;
            if ($total > 0.0) {
                $positive += $total;
            } else {
                $negative += $total;
            }
        }

        return $positive > 0.0 ? min(1.0, max(0.0, ($positive + $negative) / $positive)) : 1.0;
    }
}
```

`src/Policy/MarginFloors.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * The lowest net unit price each line may be offered at (spec 2026-09-24):
 * `purchase × (1 + margin/100)`, rounded UP to the cent, and never above what
 * the line costs the buyer today. That `min` is what keeps the floor from ever
 * raising a price: a line already priced below its floor gets no further
 * discount, but is not pushed back up either.
 */
final class MarginFloors
{
    private function __construct() {}

    /**
     * @param array<string, float> $purchaseNetByProduct productId => net purchase price per unit
     *
     * @return array<string, float> lineItemId => effective floor net
     */
    public static function of(QuoteSnapshot $live, array $purchaseNetByProduct, float $marginPercent): array
    {
        $goodsFactor = GoodsFactor::of($live->lines);
        $floors = [];

        foreach ($live->lines as $line) {
            $purchase = $purchaseNetByProduct[$line->identity->productId ?? ''] ?? null;

            if ($purchase === null || $line->unitPriceNet <= 0.0) {
                continue;
            }

            $floors[$line->lineItemId()] = min(
                self::ceilToCent($purchase * (1 + ($marginPercent / 100))),
                MoneyMath::roundMoney($line->unitPriceNet * $goodsFactor),
            );
        }

        return $floors;
    }

    /** round() first, so 110.00000000000001 stays 110.00 instead of becoming 110.01. */
    private static function ceilToCent(float $value): float
    {
        return ceil(round($value * 100, 6)) / 100;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter MarginFloorsTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Policy/GoodsFactor.php src/Policy/MarginFloors.php tests/Unit/Policy/MarginFloorsTest.php
git commit -m "feat(policy): per-line minimum-margin floors

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Offer clamp (`MarginFloorClamp`)

**Files:**
- Create: `src/Policy/MarginFloorClamp.php`
- Test: `tests/Unit/Policy/MarginFloorClampTest.php`

**Interfaces:**
- Consumes: `GoodsFactor::of()` (Task 2); the floors map shape from `MarginFloors::of()`.
- Produces: `MarginFloorClamp::clamp(ProposedOffer $offer, list<QuoteLineSnapshot> $liveLines, array<string, float> $floors): ?ProposedOffer`. Returns null when nothing binds (write the offer as proposed). Otherwise returns a per-line offer covering every positive live line, with `discountPercent` null. A non-null result means the caller must reset the quote-level discount.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Policy/MarginFloorClampTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\MarginFloorClamp;
use PHPUnit\Framework\TestCase;

final class MarginFloorClampTest extends TestCase
{
    private static function quoteWide(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 0.0, price: new OfferedPrice(discountPercent: $percent));
    }

    /** @param array<string, float> $prices */
    private static function perLine(array $prices): ProposedOffer
    {
        $lines = [];
        foreach ($prices as $id => $price) {
            $lines[] = new QuoteLinePrice($id, $price);
        }

        return new ProposedOffer(orderTotalNet: 0.0, price: new OfferedPrice(linePricesNet: $lines));
    }

    /** @return array<string, float> */
    private static function prices(?ProposedOffer $offer): array
    {
        self::assertNotNull($offer);
        self::assertNull($offer->price->discountPercent);
        $prices = [];
        foreach ($offer->price->linePricesNet ?? [] as $price) {
            $prices[$price->lineItemId] = $price->unitPriceNet;
        }

        return $prices;
    }

    public function testTheUsersWorkedExample(): void
    {
        // 120 listed, 15% asked -> 102, floor 110: written at 110.
        $clamped = MarginFloorClamp::clamp(self::quoteWide(15.0), [MarginFloorsTest::line('a', 120.0)], ['a' => 110.0]);

        self::assertSame(['a' => 110.0], self::prices($clamped));
    }

    public function testEachLineGoesAsLowAsItsOwnFloorAllows(): void
    {
        $clamped = MarginFloorClamp::clamp(
            self::quoteWide(15.0),
            [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('b', 100.0)],
            ['a' => 55.0, 'b' => 99.0],
        );

        self::assertSame(['a' => 85.0, 'b' => 99.0], self::prices($clamped));
    }

    public function testNothingBindingLeavesTheOfferAlone(): void
    {
        self::assertNull(MarginFloorClamp::clamp(self::quoteWide(5.0), [MarginFloorsTest::line('a', 120.0)], ['a' => 110.0]));
        self::assertNull(MarginFloorClamp::clamp(self::quoteWide(50.0), [MarginFloorsTest::line('a', 120.0)], []));
    }

    public function testAPerLineOfferIsRaisedToTheFloor(): void
    {
        $clamped = MarginFloorClamp::clamp(self::perLine(['a' => 80.0]), [MarginFloorsTest::line('a', 100.0)], ['a' => 88.0]);

        self::assertSame(['a' => 88.0], self::prices($clamped));
    }

    public function testUnnamedLinesCarryTheOldQuoteDiscountIntoTheirOwnPrice(): void
    {
        // Round one wrote 5% off the quote (the -10 line). Round two names only
        // line a. Once floored, the discount is reset, so b must keep its 95.
        $clamped = MarginFloorClamp::clamp(
            self::perLine(['a' => 90.0]),
            [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('b', 100.0), MarginFloorsTest::line('d', -10.0, 1, null)],
            ['a' => 88.0, 'b' => 95.0],
        );

        self::assertSame(['a' => 88.0, 'b' => 95.0], self::prices($clamped));
    }

    public function testAQuoteWidePercentReplacesTheOldDiscountRatherThanStacking(): void
    {
        // A percentage write replaces the existing discount, so 10% of 100 is
        // 90, not 90 × 0.95. The floor of 92 binds.
        $clamped = MarginFloorClamp::clamp(
            self::quoteWide(10.0),
            [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('d', -5.0, 1, null)],
            ['a' => 92.0],
        );

        self::assertSame(['a' => 92.0], self::prices($clamped));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter MarginFloorClampTest`
Expected: FAIL, `Class "MerchantQuoteAgentPlugin\Policy\MarginFloorClamp" not found`.

- [ ] **Step 3: Implement**

`src/Policy/MarginFloorClamp.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * Raises an offer to the minimum-margin floor (spec 2026-09-24) instead of
 * letting it escalate.
 *
 * It works out what each positive line would actually cost the buyer after the
 * write: a quote-wide percentage REPLACES any existing quote discount, while a
 * per-line write keeps it on top. If every floored line lands at or above its
 * floor, it returns null and the offer goes out exactly as proposed. Otherwise
 * it returns a complete per-line offer, with the old discount folded into every
 * line's own price, so the caller can reset that discount to 0% without taking
 * anything away from the buyer.
 */
final class MarginFloorClamp
{
    private function __construct() {}

    /**
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote, never the baseline
     * @param array<string, float> $floors lineItemId => effective floor net (MarginFloors::of)
     */
    public static function clamp(ProposedOffer $offer, array $liveLines, array $floors): ?ProposedOffer
    {
        $landing = self::landing($offer, $liveLines);

        if (!self::binds($landing, $floors)) {
            return null;
        }

        $prices = [];
        foreach ($landing as $lineItemId => $price) {
            $prices[] = new QuoteLinePrice(
                lineItemId: $lineItemId,
                unitPriceNet: max(MoneyMath::roundMoney($price), $floors[$lineItemId] ?? 0.0),
            );
        }

        return new ProposedOffer(
            orderTotalNet: $offer->orderTotalNet,
            price: new OfferedPrice(linePricesNet: $prices, referenceLines: $offer->price->referenceLines),
        );
    }

    /**
     * @param list<QuoteLineSnapshot> $liveLines
     *
     * @return array<string, float> lineItemId => net unit price the buyer would pay
     */
    private static function landing(ProposedOffer $offer, array $liveLines): array
    {
        $named = [];
        foreach ($offer->price->linePricesNet ?? [] as $price) {
            $named[$price->lineItemId] = $price->unitPriceNet;
        }

        // An empty per-line list is written as a quote-wide discount
        // (OfferApplier::write()), so it is priced as one here too.
        $factor = $named === []
            ? 1 - (($offer->price->discountPercent ?? 0.0) / 100)
            : GoodsFactor::of($liveLines);

        $landing = [];
        foreach ($liveLines as $line) {
            if ($line->unitPriceNet > 0.0) {
                $landing[$line->lineItemId()] = ($named[$line->lineItemId()] ?? $line->unitPriceNet) * $factor;
            }
        }

        return $landing;
    }

    /**
     * @param array<string, float> $landing
     * @param array<string, float> $floors
     */
    private static function binds(array $landing, array $floors): bool
    {
        foreach ($floors as $lineItemId => $floor) {
            if (($landing[$lineItemId] ?? INF) < ($floor - Epsilon::MONEY)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter MarginFloorClampTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Policy/MarginFloorClamp.php tests/Unit/Policy/MarginFloorClampTest.php
git commit -m "feat(policy): clamp an offer to the minimum-margin floor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Post-write floor check (`MarginFloorVerifier`)

**Files:**
- Create: `src/Policy/MarginFloorVerifier.php`
- Modify: `src/Policy/Data/VerifyOfferInput.php`
- Modify: `src/Policy/OfferVerifier.php`
- Test: `tests/Unit/Policy/MarginFloorVerifierTest.php`

**Interfaces:**
- Consumes: `GoodsFactor::of()` (Task 2).
- Produces: `VerifyOfferInput::$floors` (`array<string, float>`, the last constructor parameter, default `[]`); `MarginFloorVerifier::verify(QuoteSnapshot $final, array<string, float> $floors): list<string>`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Policy/MarginFloorVerifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\MarginFloorVerifier;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use PHPUnit\Framework\TestCase;

final class MarginFloorVerifierTest extends TestCase
{
    public function testALineAtItsFloorPasses(): void
    {
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 110.0)]);

        self::assertSame([], (new MarginFloorVerifier())->verify($final, ['a' => 110.0]));
    }

    public function testALineBelowItsFloorIsAViolation(): void
    {
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 108.0)]);

        self::assertSame(
            ['line "a" priced 108.00 net below its minimum-margin floor 110.00'],
            (new MarginFloorVerifier())->verify($final, ['a' => 110.0]),
        );
    }

    public function testAQuoteDiscountStackedUnderTheFloorIsAViolation(): void
    {
        // The line itself says 110, but a -11 quote-discount line takes it to 99.
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 110.0), MarginFloorsTest::line('d', -11.0, 1, null)]);

        self::assertCount(1, (new MarginFloorVerifier())->verify($final, ['a' => 110.0]));
    }

    public function testNoFloorsChecksNothing(): void
    {
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 1.0)]);

        self::assertSame([], (new MarginFloorVerifier())->verify($final, []));
    }

    public function testOfferVerifierRunsTheFloorCheck(): void
    {
        $reference = MarginFloorsTest::quote([MarginFloorsTest::line('a', 120.0)]);
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 108.0)]);

        $violations = (new OfferVerifier())->verify(new VerifyOfferInput(
            reference: $reference,
            final: $final,
            limits: new QuoteLimits(maxDiscountPercent: 20.0, validityDays: 14, minMarginPercent: 10.0),
            now: new \DateTimeImmutable(),
            floors: ['a' => 110.0],
        ));

        self::assertContains('line "a" priced 108.00 net below its minimum-margin floor 110.00', $violations);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter MarginFloorVerifierTest`
Expected: FAIL, `Class "MerchantQuoteAgentPlugin\Policy\MarginFloorVerifier" not found`.

- [ ] **Step 3: Implement**

`src/Policy/MarginFloorVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * The authoritative half of the minimum-margin floor (spec 2026-09-24): after
 * the write, every floored line must still cost the buyer at least its floor,
 * counting any quote discount that stacked on top. MarginFloorClamp keeps the
 * write there; this catches whatever the clamp could not see (a rounding
 * surprise, an absolute discount whose share shifted, a bug in the clamp).
 *
 * The message carries the floor, never the purchase price. It reaches the log
 * and the audit record, which only the merchant reads.
 */
final class MarginFloorVerifier
{
    /**
     * @param array<string, float> $floors lineItemId => effective floor net
     *
     * @return list<string>
     */
    public function verify(QuoteSnapshot $final, array $floors): array
    {
        $goodsFactor = GoodsFactor::of($final->lines);
        $violations = [];

        foreach ($final->lines as $line) {
            $floor = $floors[$line->lineItemId()] ?? null;
            $effective = $line->unitPriceNet * $goodsFactor;

            if ($floor !== null && $line->unitPriceNet > 0.0 && $effective < ($floor - Epsilon::MONEY)) {
                $violations[] = sprintf(
                    'line "%s" priced %s net below its minimum-margin floor %s',
                    $line->label() ?? $line->lineItemId(),
                    number_format($effective, decimals: 2, thousands_separator: ''),
                    number_format($floor, decimals: 2, thousands_separator: ''),
                );
            }
        }

        return $violations;
    }
}
```

`VerifyOfferInput`: add as the last constructor parameter, with a doc line above it:

```php
        /** @var array<string, float> lineItemId => effective minimum-margin floor net; empty checks nothing */
        public array $floors = [],
```

`OfferVerifier`: add a constructor collaborator `private readonly MarginFloorVerifier $floors = new MarginFloorVerifier(),`, and spread its result at the end of `verify()`'s array:

```php
            ...$this->floors->verify($input->final, $input->floors),
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter 'MarginFloorVerifierTest|OfferVerifierTest'`
Expected: PASS. The existing `OfferVerifierTest` fixtures stay green, because they pass no floors.

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Policy/MarginFloorVerifier.php src/Policy/Data/VerifyOfferInput.php src/Policy/OfferVerifier.php tests/Unit/Policy/MarginFloorVerifierTest.php
git commit -m "feat(policy): verify written offers against the minimum-margin floor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Purchase-price reader (Bridge)

**Files:**
- Create: `src/Negotiation/PurchasePricesInterface.php`
- Create: `src/Bridge/PurchasePriceReader.php`
- Modify: `src/Resources/config/services.php` (register the reader, alias the interface; near the `CustomerHistoryFactory` block around line 639)
- Test: `tests/Integration/PurchasePriceReaderTest.php`

**Interfaces:**
- Produces: `Negotiation\PurchasePricesInterface::netUnitPrices(list<string> $productIds, string $currencyIso): array<string, float>`, implemented by `Bridge\PurchasePriceReader` (constructor: `EntityRepository $products, EntityRepository $currencies`). The container resolves `PurchasePricesInterface` to it.

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/PurchasePriceReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\Currency\CurrencyEntity;

/** Real DAL inheritance and currency fallback; every write is rolled back. */
final class PurchasePriceReaderTest extends IntegrationTestCase
{
    public function testAVariantInheritsItsParentsPurchasePrice(): void
    {
        $variantId = self::variantPricedOnlyOnItsParent(40.0);

        $prices = self::reader()->netUnitPrices([$variantId], self::defaultCurrency()->getIsoCode());

        self::assertSame([$variantId => 40.0], $prices);
    }

    public function testADefaultCurrencyOnlyPriceIsConvertedByTheQuoteCurrencysFactor(): void
    {
        $variantId = self::variantPricedOnlyOnItsParent(40.0);
        $usd = self::currency('USD');

        $prices = self::reader()->netUnitPrices([$variantId], 'USD');

        self::assertEqualsWithDelta(40.0 * $usd->getFactor(), $prices[$variantId] ?? null, 0.0001);
    }

    public function testNonProductIdsAndUnknownCurrenciesYieldNothing(): void
    {
        $variantId = self::variantPricedOnlyOnItsParent(40.0);

        self::assertSame([], self::reader()->netUnitPrices(['not-a-uuid', ''], 'EUR'));
        self::assertSame([], self::reader()->netUnitPrices([$variantId], 'XXX'));
    }

    private static function reader(): PurchasePricesInterface
    {
        $reader = static::getContainer()->get(PurchasePricesInterface::class);
        self::assertInstanceOf(PurchasePricesInterface::class, $reader);

        return $reader;
    }

    /** A variant with no purchase price of its own, whose parent's is `$net` in the default currency only. */
    private static function variantPricedOnlyOnItsParent(float $net): string
    {
        $criteria = (new Criteria())
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('parentId', null)]))
            ->setLimit(1);
        $variant = self::repository(static::getContainer(), 'product.repository')
            ->search($criteria, Context::createDefaultContext())
            ->first();
        self::assertNotNull($variant, 'The test shop has no variant product to read.');
        $parentId = $variant->get('parentId');
        self::assertIsString($parentId);
        $variantId = (string) $variant->get('id');

        self::repository(static::getContainer(), 'product.repository')->update([
            [
                'id' => $parentId,
                'purchasePrices' => [['currencyId' => Defaults::CURRENCY, 'net' => $net, 'gross' => $net, 'linked' => false]],
            ],
            ['id' => $variantId, 'purchasePrices' => null],
        ], Context::createDefaultContext());

        return $variantId;
    }

    private static function defaultCurrency(): CurrencyEntity
    {
        $currency = self::repository(static::getContainer(), 'currency.repository')
            ->search(new Criteria([Defaults::CURRENCY]), Context::createDefaultContext())
            ->first();
        self::assertInstanceOf(CurrencyEntity::class, $currency);

        return $currency;
    }

    private static function currency(string $iso): CurrencyEntity
    {
        $currency = self::repository(static::getContainer(), 'currency.repository')
            ->search((new Criteria())->addFilter(new EqualsFilter('isoCode', $iso)), Context::createDefaultContext())
            ->first();
        self::assertInstanceOf(CurrencyEntity::class, $currency, "The test shop has no {$iso} currency.");
        self::assertNotSame(Defaults::CURRENCY, $currency->getId(), "{$iso} must not be the default currency.");

        return $currency;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `composer run test:integration -- --filter PurchasePriceReaderTest` (needs the test shop; see README).
Expected: FAIL, `PurchasePricesInterface` not found, or the service is missing from the container.
If the test shop is unreachable, say so in your report and continue. Do not mark this step done without running it.

- [ ] **Step 3: Implement**

`src/Negotiation/PurchasePricesInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The merchant's own cost per product (spec 2026-09-24). Internal only: what
 * this returns feeds the minimum-margin floor and must never reach a model call
 * or a buyer comment.
 */
interface PurchasePricesInterface
{
    /**
     * @param list<string> $productIds ids that are not product ids are ignored
     *
     * @return array<string, float> productId => net purchase price per unit in
     *     $currencyIso; products without one are left out
     */
    public function netUnitPrices(array $productIds, string $currencyIso): array;
}
```

`src/Bridge/PurchasePriceReader.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;

/**
 * Reads `product.purchasePrices` for the minimum-margin floor.
 *
 * Inheritance is enabled because the field is `Inherited`: a variant usually
 * carries no purchase price of its own. `PriceCollection::getCurrencyPrice()`
 * falls back to the default-currency price UNCONVERTED, so a fallback is
 * multiplied by the quote currency's factor here.
 */
final readonly class PurchasePriceReader implements PurchasePricesInterface
{
    /**
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $products
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $currencies
     */
    public function __construct(
        private EntityRepository $products,
        private EntityRepository $currencies,
    ) {}

    #[\Override]
    public function netUnitPrices(array $productIds, string $currencyIso): array
    {
        // Custom lines carry a referencedId that is no product id; a Criteria
        // built from one would throw rather than find nothing.
        $ids = array_values(array_unique(array_filter($productIds, Uuid::isValid(...))));
        $context = Context::createDefaultContext();
        $currency = $ids === [] ? null : $this->currency($currencyIso, $context);

        if ($currency === null) {
            return [];
        }

        $products = $context->enableInheritance(
            fn(Context $inheriting) => $this->products->search(new Criteria($ids), $inheriting)->getEntities(),
        );

        $prices = [];
        foreach ($products as $product) {
            $price = $product instanceof ProductEntity
                ? $product->getPurchasePrices()?->getCurrencyPrice($currency->getId())
                : null;

            if (!$product instanceof ProductEntity || $price === null) {
                continue;
            }

            $prices[$product->getId()] = $price->getCurrencyId() === $currency->getId()
                ? $price->getNet()
                : $price->getNet() * $currency->getFactor();
        }

        return $prices;
    }

    private function currency(string $iso, Context $context): ?CurrencyEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('isoCode', $iso))->setLimit(1);
        $currency = $this->currencies->search($criteria, $context)->getEntities()->first();

        return $currency instanceof CurrencyEntity ? $currency : null;
    }
}
```

`services.php`: add the imports (`use MerchantQuoteAgentPlugin\Bridge\PurchasePriceReader;`, `use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;`) and, after the `CustomerHistoryFactoryInterface` alias:

```php
    // The merchant's purchase prices, for the minimum-margin floor. Core
    // repositories only, so this exists whether or not SwagCommercial does.
    $services->set(PurchasePriceReader::class)->args([
        service('product.repository'),
        service('currency.repository'),
    ]);
    $services->alias(PurchasePricesInterface::class, PurchasePriceReader::class)->public();
```

`->public()` because nothing depends on the alias until Task 6, and the container removes an unused private alias. Without it the integration test below could not fetch the service.

- [ ] **Step 4: Run it to verify it passes**

Run: `composer run test:integration -- --filter PurchasePriceReaderTest`
Expected: PASS (3 tests). Also run `composer run test -- --filter NamespacePurityTest`, expected PASS: the interface imports nothing from Shopware.

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck && composer run quality:depcheck
git add src/Negotiation/PurchasePricesInterface.php src/Bridge/PurchasePriceReader.php src/Resources/config/services.php tests/Integration/PurchasePriceReaderTest.php
git commit -m "feat(bridge): read product purchase prices for the margin floor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Enforce the floor in `OfferApplier`

**Files:**
- Create: `src/Negotiation/MarginFloorGuard.php`
- Create: `tests/Unit/Negotiation/FakePurchasePrices.php`
- Modify: `src/Negotiation/OfferApplier.php`
- Modify: `src/Resources/config/services.php` (register `MarginFloorGuard`, next to `OfferApplier` around line 884)
- Modify (construction sites; add the new fourth argument): `tests/Unit/Negotiation/PipelineHarness.php:135`, `tests/Unit/Negotiation/OfferApplierBaselineTest.php:29`, `tests/Unit/Negotiation/OfferRoundTest.php:73,271,307,361,406`, `tests/Unit/Negotiation/OfferApplierTest.php:33,132`, `tests/Integration/Bench/BenchNegotiation.php:272`, `tests/Integration/PipelineFixture.php:123`
- Modify: `docs/end-to-end.md` (pricing section, after the band table around line 287)
- Test: `tests/Unit/Negotiation/OfferApplierMarginFloorTest.php`

**Interfaces:**
- Consumes: `PurchasePricesInterface` (Task 5), `MarginFloors::of()` (Task 2), `MarginFloorClamp::clamp()` (Task 3), `VerifyOfferInput::$floors` (Task 4), `QuoteLimits::$minMarginPercent` (Task 1).
- Produces: `MarginFloorGuard::floors(Policy\Data\QuoteSnapshot $live, QuoteLimits $limits): array<string, float>`; `OfferApplier::__construct(OfferVerifier, LoggerInterface, DecisionRecorder, MarginFloorGuard)`.

- [ ] **Step 1: Write the fake and the failing test**

`tests/Unit/Negotiation/FakePurchasePrices.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;

final class FakePurchasePrices implements PurchasePricesInterface
{
    /** @var list<array{list<string>, string}> */
    public array $calls = [];

    /** @param array<string, float> $prices productId => net purchase price */
    public function __construct(
        private readonly array $prices = [],
    ) {}

    #[\Override]
    public function netUnitPrices(array $productIds, string $currencyIso): array
    {
        $this->calls[] = [$productIds, $currencyIso];

        return array_intersect_key($this->prices, array_flip($productIds));
    }
}
```

`tests/Unit/Negotiation/OfferApplierMarginFloorTest.php`. The fixture quote has one line, `line-1` (product `prod-1`), 10 × 100.00 net:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The minimum-margin floor at the one place offers are written (spec 2026-09-24). */
final class OfferApplierMarginFloorTest extends TestCase
{
    private static function applier(FakePurchasePrices $prices): OfferApplier
    {
        return new OfferApplier(
            new OfferVerifier(),
            new NullLogger(),
            new DecisionRecorder(new FakeDecisionWriter()),
            new MarginFloorGuard($prices),
        );
    }

    private static function settings(?float $minMarginPercent): QuoteAgentSettings
    {
        $settings = NegotiationFixture::settings(maxDiscountPercent: 25.0);

        return $settings->withPolicy($settings->policy->withPrice(new QuoteLimits(
            maxDiscountPercent: 25.0,
            validityDays: 14,
            minMarginPercent: $minMarginPercent,
        )));
    }

    private static function quoteWide(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: $percent));
    }

    private static function withQuoteDiscount(QuoteSnapshot $snapshot): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: new QuoteTotals(1000.0, new Discount(DiscountType::Percentage, 5.0), 1000.0),
            lifecycle: $snapshot->lifecycle,
            content: $snapshot->content,
        );
    }

    public function testAQuoteWideOfferBelowTheFloorIsWrittenAsLinePricesAtTheFloor(): void
    {
        // Purchase 80, margin 10% -> floor 88. 15% off 100 would be 85.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        $applied = self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(10.0), self::quoteWide(15.0));

        self::assertContains('updateLineItems', $gateway->calls);
        self::assertSame(88.0, $gateway->lineItemChanges[0]->unitPriceNet);
        self::assertNull($gateway->quoteUpdates[0]->discount, 'No quote discount to reset, so none is written.');
        self::assertTrue($applied->verified);
    }

    public function testAFlooredOfferResetsAnExistingQuoteDiscount(): void
    {
        $snapshot = self::withQuoteDiscount(NegotiationFixture::snapshot());
        $gateway = new FakeQuoteGateway([$snapshot]);

        self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, $snapshot, self::settings(10.0), self::quoteWide(15.0));

        self::assertSame(DiscountType::Percentage, $gateway->quoteUpdates[0]->discount?->type);
        self::assertSame(0.0, $gateway->quoteUpdates[0]->discount?->value);
    }

    public function testAnOfferAboveTheFloorIsWrittenExactlyAsProposed(): void
    {
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(10.0), self::quoteWide(5.0));

        self::assertNotContains('updateLineItems', $gateway->calls);
        self::assertSame(5.0, $gateway->quoteUpdates[0]->discount?->value);
    }

    public function testWithoutAMarginThePurchasePricesAreNeverRead(): void
    {
        $prices = new FakePurchasePrices(['prod-1' => 99.0]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier($prices)->apply($gateway, NegotiationFixture::snapshot(), self::settings(null), self::quoteWide(15.0));

        self::assertSame([], $prices->calls);
        self::assertSame(15.0, $gateway->quoteUpdates[0]->discount?->value);
    }

    public function testAWriteTheDatabaseLandsBelowTheFloorFailsVerification(): void
    {
        // The offer is fine (88 = the floor); the database says 80 afterwards.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(), NegotiationFixture::snapshot(totalNet: 800.0)]);
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 88.0),
        ]));

        $applied = self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(10.0), $offer);

        self::assertFalse($applied->verified);
        self::assertContains('line "Widget" priced 80.00 net below its minimum-margin floor 88.00', $applied->violations);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `composer run test -- --filter OfferApplierMarginFloorTest`
Expected: FAIL, `Class "MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard" not found`.

- [ ] **Step 3: Implement `MarginFloorGuard`**

`src/Negotiation/MarginFloorGuard.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\MarginFloors;

/**
 * The effective minimum-margin floors for one write (spec 2026-09-24). Reads
 * the purchase prices only when the merchant configured a margin, so a shop
 * without one pays no query and behaves exactly as before.
 */
final readonly class MarginFloorGuard
{
    public function __construct(
        private PurchasePricesInterface $purchasePrices,
    ) {}

    /** @return array<string, float> lineItemId => effective floor net; empty when no margin is set */
    public function floors(PolicySnapshot $live, QuoteLimits $limits): array
    {
        $margin = $limits->minMarginPercent;

        if ($margin === null) {
            return [];
        }

        $productIds = [];
        foreach ($live->lines as $line) {
            if ($line->identity->productId !== null) {
                $productIds[] = $line->identity->productId;
            }
        }

        return MarginFloors::of($live, $this->purchasePrices->netUnitPrices($productIds, $live->currencyIso), $margin);
    }
}
```

- [ ] **Step 4: Wire `OfferApplier`**

In `src/Negotiation/OfferApplier.php`:

1. Add imports: `MerchantQuoteAgentPlugin\Policy\MarginFloorClamp`, `MerchantQuoteAgentPlugin\Policy\MoneyMath`.
2. Add the constructor parameter `private MarginFloorGuard $marginFloors,` after `$recorder` (not `$floors`: `apply()` uses `$floors` for the map).
3. In `apply()`, replace everything from `$reference = $gateway->fetchSnapshot($quoteId);` through the `$after`/`$violations` block's opening with the version below. Keep the existing long comments on the fresh read and on `VerifyOfferInput`'s reference verbatim where marked.

```php
        // (keep the existing "Read fresh, right before the write" comment here)
        $reference = $gateway->fetchSnapshot($quoteId);
        $live = SnapshotAdapter::toPolicy($reference);

        // The minimum-margin floor (spec 2026-09-24), measured on the LIVE
        // quote because that is what a percentage write acts on. A null clamp
        // means nothing binds and the offer goes out exactly as proposed.
        $floors = $this->marginFloors->floors($live, $limits);
        $floored = MarginFloorClamp::clamp($offer, $live->lines, $floors);
        if ($floored !== null) {
            $this->logger->info('The offer was raised to the minimum-margin floor.', ['quoteId' => $quoteId]);
        }

        array_push($writes, ...$this->write($gateway, $reference, $limits, $floored ?? $offer, $floored !== null));
        $gateway->recalculate($quoteId);
        $writes[] = 'recalculate';

        $baselineLines = QuoteBaseline::read($reference);

        $after = $gateway->fetchSnapshot($quoteId);
        $violations = $this->verifier->verify(new VerifyOfferInput(
            // (keep the existing #49 / #54 comment here)
            reference: $baselineLines?->anchor($live) ?? $live,
            final: SnapshotAdapter::toPolicy($after),
            limits: $limits,
            now: new \DateTimeImmutable(),
            floors: $floors,
        ));
```

4. Replace `write()`. `$quoteId` is dropped as a parameter to stay within five and taken from `$reference` instead. Keep the existing docblock and the #49/#54 comment; add the one below:

```php
    /**
     * (existing docblock)
     *
     * `$replacesQuoteDiscount` is set when the minimum-margin clamp rewrote the
     * offer: it then prices every line with the old quote discount folded in,
     * so that discount goes to 0% — left on, it would stack under the floor.
     *
     * @return list<string> the write names performed, for the audit trail
     */
    private function write(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $reference,
        QuoteLimits $limits,
        ProposedOffer $offer,
        bool $replacesQuoteDiscount,
    ): array {
        $quoteId = $reference->identity->quoteId;
        $expected = $reference->revision;
        $linePrices = $offer->price->linePricesNet;
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $limits->validityDays));

        // (keep the existing #49 / #54 baseline comment here)
        $fragment = QuoteBaseline::stampOrExtend($reference);
        $baseline = $fragment === [] ? null : $fragment;

        if ($linePrices !== null && $linePrices !== []) {
            // A floored offer writes only the lines that move: rewriting an
            // unchanged price through the gross conversion can shift it a
            // cent, which the never-raise check escalates. Whichever write
            // goes first carries the revision precondition.
            $changes = $replacesQuoteDiscount ? self::moved($linePrices, $reference) : $linePrices;
            $writes = [];

            if ($changes !== []) {
                $gateway->updateLineItems($quoteId, array_map(self::lineChange(...), $changes), $expected);
                $expected = null;
                $writes[] = 'updateLineItems';
            }

            $gateway->updateQuote($quoteId, new QuoteUpdate(
                discount: $replacesQuoteDiscount && $reference->totals->discount !== null
                    ? new Discount(DiscountType::Percentage, 0.0)
                    : null,
                expiresAt: $expiresAt,
                customFields: $baseline,
            ), $expected);
            $writes[] = 'updateQuote';

            return $writes;
        }

        // (the quote-wide branch below stays exactly as it is)
```

5. Add below `lineChange()`:

```php
    /**
     * @param list<QuoteLinePrice> $prices
     *
     * @return list<QuoteLinePrice> the prices that differ from the line's live unit price
     */
    private static function moved(array $prices, QuoteSnapshot $reference): array
    {
        $live = [];
        foreach ($reference->content->lines as $line) {
            $live[$line->identity->lineItemId] = MoneyMath::roundMoney($line->unitPriceNet);
        }

        return array_values(array_filter(
            $prices,
            static fn(QuoteLinePrice $price): bool => abs($price->unitPriceNet - ($live[$price->lineItemId] ?? INF))
                > Epsilon::RATE,
        ));
    }
```

6. `services.php`: add `use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;` and `$services->set(MarginFloorGuard::class);` directly above `$services->set(OfferApplier::class);`. Autowiring resolves the interface through the Task 5 alias.

7. Every construction site in **Files** gets the fourth argument `new MarginFloorGuard(new FakePurchasePrices())`, plus the imports `MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard` and `MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\FakePurchasePrices` (the latter only where the file lives in another namespace).

If `composer run lint` reports `cyclomatic-complexity` on `OfferApplier`, move `moved()` to `MarginFloorGuard` as `public static function movedLines(array $prices, QuoteSnapshot $reference): array` (Bridge `QuoteSnapshot`; Negotiation already imports Bridge data classes) and call it from `write()`. Do not add a `@mago-expect`.

- [ ] **Step 5: Docs**

`docs/end-to-end.md`, directly after the band table (the `| escalate |` row) and before "Two other checks escalate here:", add:

```markdown
**Minimum-margin floor.** With `minMarginPercent` set, `OfferApplier` never
writes a line below `purchase price × (1 + minMarginPercent/100)`. It does not
escalate: a deeper offer is raised to the floor, per line, and a quote-wide
percentage that would undercut any floor is written as line prices with the
quote discount reset to 0%. A post-write check escalates as
`verification_failed` if the database still lands a line below its floor. The
purchase price and the floor never reach the model or the buyer; the buyer's
reply reports the reduction the database actually shows.
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer run test`
Expected: PASS, the whole unit suite including `OfferApplierMarginFloorTest` (5 tests), `OfferApplierTest`, `OfferApplierBaselineTest`, `OfferRoundTest`, `NegotiationPipelineTest`, `NamespacePurityTest`.

- [ ] **Step 7: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck && composer run quality:depcheck
git add src/Negotiation/MarginFloorGuard.php src/Negotiation/OfferApplier.php src/Resources/config/services.php docs/end-to-end.md tests/Unit/Negotiation tests/Integration/Bench/BenchNegotiation.php tests/Integration/PipelineFixture.php
git commit -m "feat(negotiation): hold every written offer at the minimum-margin floor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Full gate and spec status

**Files:**
- Modify: `docs/superpowers/specs/2026-09-24-min-margin-floor-design.md` (`## Status`)

- [ ] **Step 1: Run the full quality aggregate**

Run: `composer run quality`
Expected: PASS. A failure is fixed at its cause. Never relax a threshold or add a baseline entry for new code.

- [ ] **Step 2: Run the integration suite on the test shop**

Run: `composer run test:integration`
Expected: PASS, apart from failures the memory notes describe as pre-existing on this shop (`PluginConfigTest` on a configured shop). List any failure, and whether it also fails on `main`, in your report.

- [ ] **Step 3: Mark the spec implemented**

Replace the status body with `Implemented. 2026-09-24.`

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-09-24-min-margin-floor-design.md
git commit -m "docs(spec): mark the minimum-margin floor implemented

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
