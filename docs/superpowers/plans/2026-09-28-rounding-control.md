# Rounding Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A merchant setting (`roundingMode` + `roundingStep`) makes the agent's own figures come out round: the model's discount percentage floored to a step, or a quote-wide offer written as an absolute discount that lands the buyer-facing total on a step. Rounding only ever means less discount.

**Architecture:** Two config fields on `Policy\Data\QuoteLimits`, the `minMarginPercent` path. `discount_percent` mode is one pure Policy function (`DiscountRounding::offer()`) that `OfferProposer` calls between the model's answer and `OfferLevelMirror`/`authorize()`. `quote_total` mode is `Negotiation\QuoteTotalRounding`, which `OfferApplier` calls right after `OfferWrite::of()` and before the pre-write verification: it turns the percentage write into `OfferWrite::absolute()`, and `PredictedWrite` learns an absolute discount through a new `OfferWrite::$discountFactor`. Each rounding is traced as a new `rounding` trace event.

**Tech Stack:** PHP 8.3, Shopware 6.7 / SwagCommercial quote discount (`QuoteDiscountProcessor`), PHPUnit, Mago (fmt/lint/analyze), Symfony Validator.

**Spec:** `docs/superpowers/specs/2026-09-28-rounding-control-design.md`. Read it before starting any task. It is the authority, except where "Deviations from spec" below says why this plan does something else.

## Deviations from spec

1. **The audit is its own trace event, not a key on `policy_verdict`.** `policy_verdict` is appended in `NegotiationPipeline::answer()` (`DecisionRecorder::recordDecision()`), before the negotiate call. Rounding happens after the model answers (`discount_percent`) or just before the write (`quote_total`), so its figures do not exist when `policy_verdict` is written. The plan adds `TraceKind::Rounding = 'rounding'`. Its meta is exactly the spec's shape, `{mode, step, unrounded, rounded, skipped}`. No migration: `kind` is `VARCHAR(32)`.
2. **`quote_total` does not round a net quote that adds tax on top.** The spec says the total is "net on a net quote — `QuoteTotals::buyerFacingTotal()` space". That space is not net. `buyerFacingTotal()` returns `totalGross` (`amountTotal`), and on a `net`-tax-state quote that total still includes tax. `ReplyComposer` states that same figure. On such a quote the absolute discount is net (`Bridge\Data\Discount`), and Shopware rounds the tax back on per rate after the discount, so no absolute amount is guaranteed to land on a round total. These quotes are written unrounded and traced with a fifth skip value, `skipped: "tax_on_top"`. The detection is: the goods carry no tax in the snapshot (`netRatio` 1.0) but `buyerFacingTotal() > totalNet`. Tax-free quotes (buyer-facing = net) and gross quotes land exactly and are rounded. All 36 live-shop quotes are gross.
3. **Rule 2 is measured as one percentage, within 0.01 points.** The buyer's figure is `AskedDiscountCeiling::percent()` on the anchored snapshot. That one number already covers an asked percentage, asked line prices and an asked target total, the way `CappedAuthority` caps the round. The offer's `discountPercent` counts as the buyer's figure when it is within `Epsilon::MONEY` (0.01 percentage points), not within `Epsilon::RATE`. A target total converts to a long percentage (2500 on 2614.xx is 4.3629…%), and the model answers to two places (4.36). At `RATE` tolerance that answer would not count as the buyer's figure, so their own budget would get rounded down. `quote_total` mode uses the same percentage test. The buyer's target total is only held net (`BuyerPriceSpace`), not in the buyer-facing space the total is rounded in.
4. **Rule 3 in `discount_percent` mode uses `CappedAuthority::standing()`, not `PredictedWrite`.** The percentage is rounded inside `OfferProposer`, before any write exists. The proposer holds only Policy snapshots, and `OfferWrite::of()` needs the Bridge snapshot. `standing()` is the discount the buyer already holds, measured the way both verifier checks measure it: on the total, and on the deepest line. `quote_total` mode uses `PredictedWrite` exactly as the spec says.
5. **`OfferLanding` is unchanged.** It answers only whether the margin floor binds, and it answers that on the unrounded `ProposedOffer`. When the floor binds, the offer is written per line and never rounded. The absolute write only ever replaces an unfloored quote-wide write, and it gives less discount. `PredictedWrite` is the one class that has to learn an absolute discount.
6. **Rule 5 is a post-write warning.** The existing backstops (the verifier, the never-raise total check) are untouched and still decide the pass. If the landed buyer-facing total misses the round target by more than a cent, `OfferApplier` logs a warning instead of accepting the miss silently. The warning adds no new escalation.

## Global Constraints

- `declare(strict_types=1)` in every file; target PHP 8.3.
- Gate thresholds: cyclomatic complexity 10 **per class, summed** (`if`, `&&`, `||`, `??`, ternary and each `match` arm count; `?->` does not), nesting depth 4, **at most 5 parameters**, ~400 lines/file. Measured on this branch: `OfferRound` = 10, `OfferProposer` = 10, so the edits to those two classes add **none** of the counted constructs. `OfferApplier` = 8 (this plan adds one `if`), `PredictedWrite` = 9 (unchanged), and `QuoteLimits` = 10 after Task 1 (measured with the planned code).
- `composer run lint` covers `tests/` too. `too-many-methods` fails a class at 11 methods (private helpers and constructors count), and an unfulfilled `@mago-expect` is only a warning. New test classes in this plan stay at or below 10 methods.
- `src/Policy` imports no Shopware; `src/Negotiation` imports none beyond `IllegalTransitionException` (`NamespacePurityTest`). `Policy\Data` imports nothing from `Policy`.
- Symfony Validator runs only in `QuoteAgentSettingsFactory`. The only new constraint is `Assert\PositiveOrZero` on `QuoteLimits::$roundingStep`, a file already on `ValidatedConstraintsTest`'s list.
- Rounding means less discount only: `discount_percent` floors, `quote_total` rounds the total **up**. No new policy check is added.
- Never round the buyer's own figure, never go below the standing price, never round to nothing. Each of these writes the unrounded offer and traces the reason.
- Money: `Epsilon::MONEY` (0.01), `MoneyMath::roundMoney()`. Rates: `Epsilon::RATE`.
- An `Absolute` discount value is in the quote's own tax space (`Bridge\Data\Discount`). SwagCommercial applies `-abs($value)`, so a negative value would become a discount. The to-zero rule stops that value from ever being written.
- Per-task checks: `composer run format:check && composer run lint && composer run typecheck && composer run test`. Run `composer run format` to fix formatting.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Commits are signed through the 1Password desktop app: if signing fails, retry. Never disable signing.

---

## File Structure

| File | Task | Responsibility |
|---|---|---|
| `src/Policy/Data/RoundingMode.php` (new) | 1 | `off` / `discount_percent` / `quote_total` |
| `src/Policy/Data/QuoteLimits.php` | 1 | two fields, `stepFor()`, copy-constructor, `fromArray()` |
| `src/Config/NegotiationPolicyArray.php` | 1 | raw → `price.roundingMode` / `price.roundingStep` |
| `src/Config/QuoteAgentSettingsReader.php` | 1 | read the two keys |
| `src/Resources/config/config.xml` | 1 | single-select + float |
| `src/Policy/Data/RoundingSkip.php` (new) | 2 | why a rounding was not written |
| `src/Policy/Data/Rounding.php` (new) | 2 | what rounding did to one offer |
| `src/Policy/RoundingStep.php` (new) | 2 | floor/ceil to a step, rule-2 test |
| `src/Policy/DiscountRounding.php` (new) | 2 | `discount_percent` mode, rules 2–4 |
| `src/Audit/TraceKind.php`, `src/Audit/DecisionRecorder.php` | 3 | `rounding` event |
| `src/Negotiation/CappedAuthority.php` | 3 | `standing()` becomes public |
| `src/Negotiation/OfferProposer.php`, `src/Negotiation/OfferRound.php` | 3, 5 | wire the buyer's ask and the rounding |
| `src/Negotiation/OfferWrite.php` | 4 | `$discountFactor`, `absolute()` |
| `src/Negotiation/PredictedWrite.php` | 4 | predict any discount through `$discountFactor` |
| `src/Negotiation/BuyerFacingGoods.php` (new) | 5 | goods / other costs in the buyer-facing space |
| `src/Negotiation/QuoteTotalRounding.php` (new) | 5 | `quote_total` mode, rules 2–5 |
| `src/Negotiation/OfferApplier.php` | 5 | call it, record it, warn on a miss |
| `src/Bridge/Data/Discount.php` | 5 | docblock: an `Absolute` value is now emitted |
| `tests/Unit/Negotiation/RoundingFixture.php` (new) | 3, 4 | settings, trace/discount readers, mixed-tax quotes |
| `tests/Integration/LegacyGrossQuoteTest.php`, `tests/Integration/PluginConfigTest.php` | 6 | shop proof |
| `docs/for-merchants.md`, `docs/end-to-end.md` | 6 | merchant docs |

---

### Task 1: The setting

**Files:**
- Create: `src/Policy/Data/RoundingMode.php`
- Modify: `src/Policy/Data/QuoteLimits.php`, `src/Config/NegotiationPolicyArray.php`, `src/Config/QuoteAgentSettingsReader.php:39-51`, `src/Resources/config/config.xml` (after the `minMarginPercent` field)
- Test: `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php`, `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`, `tests/Unit/Config/QuoteAgentSettingsReaderTest.php`, `tests/Unit/Config/ConfigXmlSchemaTest.php`

**Interfaces:**
- Produces: `enum Policy\Data\RoundingMode: string { Off = 'off'; DiscountPercent = 'discount_percent'; QuoteTotal = 'quote_total' }`
- Produces: `QuoteLimits::$roundingMode: RoundingMode` (default `Off`), `QuoteLimits::$roundingStep: ?float` (default null), `QuoteLimits::stepFor(RoundingMode $mode): ?float`. `stepFor()` returns the step only when `$mode` is the configured mode and the step is > 0; otherwise null.
- Produces: config keys `roundingMode` (string) and `roundingStep` (float) under `MerchantQuoteAgentPlugin.config.`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php` (add `use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;`):

```php
    public function testANegativeRoundingStepIsRejected(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            validityDays: 14,
            roundingMode: RoundingMode::DiscountPercent,
            roundingStep: -0.5,
        ));

        self::assertSame(['price.roundingStep'], self::paths(self::validator()->validate($policy)));
    }
```

In the same file, replace the existing `testTighteningTheDiscountCapKeepsTheMinimumMargin()` with the version below, which covers both fields. The class has 9 methods and mago's `too-many-methods` fails at 11, so extending this test beats adding a second one:

```php
    public function testTighteningTheDiscountCapKeepsTheMarginAndTheRounding(): void
    {
        // CappedAuthority rebuilds the limits through withMaxDiscountPercent()
        // on every round where the buyer asks for less than the cap. Dropping
        // either field there would switch it off on exactly those rounds.
        $limits = new QuoteLimits(
            maxDiscountPercent: 15.0,
            validityDays: 14,
            minMarginPercent: 10.0,
            roundingMode: RoundingMode::QuoteTotal,
            roundingStep: 10.0,
        );
        $tightened = $limits->withMaxDiscountPercent(5.0);

        self::assertSame(10.0, $tightened->minMarginPercent);
        self::assertSame(10.0, $tightened->stepFor(RoundingMode::QuoteTotal));
    }
```

In `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`, add `use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;`. Add three yields to `invalidConfigurations()`:

```php
        yield 'unknown rounding mode' => [['roundingMode' => 'nearest'], 'RoundingMode'];
        yield 'negative rounding step' => [['roundingStep' => -0.5], 'price.roundingStep'];
        yield 'rounding step wrong type' => [['roundingStep' => '0.5'], 'roundingStep'];
```

Append these tests:

```php
    public function testTheRoundingSettingsMapOntoThePriceLimits(): void
    {
        $settings = self::build(['roundingMode' => 'quote_total', 'roundingStep' => 10.0]);

        self::assertNotNull($settings);
        self::assertSame(RoundingMode::QuoteTotal, $settings->policy->price->roundingMode);
        self::assertSame(10.0, $settings->policy->price->stepFor(RoundingMode::QuoteTotal));
        self::assertNull(
            $settings->policy->price->stepFor(RoundingMode::DiscountPercent),
            'A step only counts for the mode it is set for.',
        );
    }

    public function testAnUntouchedInstallDoesNotRound(): void
    {
        $settings = self::build();

        self::assertNotNull($settings);
        self::assertSame(RoundingMode::Off, $settings->policy->price->roundingMode);
        self::assertNull($settings->policy->price->stepFor(RoundingMode::DiscountPercent));
        self::assertNull($settings->policy->price->stepFor(RoundingMode::QuoteTotal));
    }

    public function testABlankOrZeroStepIsOffWhateverTheMode(): void
    {
        foreach ([null, 0.0] as $step) {
            $settings = self::build(['roundingMode' => 'discount_percent', 'roundingStep' => $step]);

            self::assertNotNull($settings);
            self::assertNull($settings->policy->price->stepFor(RoundingMode::DiscountPercent));
        }
    }
```

Append to `tests/Unit/Config/QuoteAgentSettingsReaderTest.php` (add `use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;`):

```php
    public function testTheRoundingSettingsAreReadFromSystemConfig(): void
    {
        $settings = $this->reader(['roundingMode' => 'discount_percent', 'roundingStep' => 0.5])
            ->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame(0.5, $settings->policy->price->stepFor(RoundingMode::DiscountPercent));
    }
```

Append to `tests/Unit/Config/ConfigXmlSchemaTest.php` (add `use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;`):

```php
    /** The admin's options and RoundingMode::from() must agree, or a saved option takes the channel out of service. */
    public function testTheRoundingModeOptionsAreTheEnumsCasesAndDefaultToOff(): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../src/Resources/config/config.xml'));

        $xpath = new \DOMXPath($document);
        $field = $xpath->query('//input-field[name="roundingMode"]')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame('single-select', $field->getAttribute('type'));
        self::assertSame('off', $xpath->query('defaultValue', $field)?->item(0)?->textContent);

        $nodes = $xpath->query('options/option/id', $field);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node->textContent;
        }

        self::assertSame(array_map(static fn(RoundingMode $mode): string => $mode->value, RoundingMode::cases()), $ids);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter 'NegotiationPolicyValidationTest|QuoteAgentSettingsFactoryTest|QuoteAgentSettingsReaderTest|ConfigXmlSchemaTest'`
Expected: FAIL/ERROR, `Class "MerchantQuoteAgentPlugin\Policy\Data\RoundingMode" not found` / unknown named parameter `roundingMode`.

- [ ] **Step 3: Create the enum**

`src/Policy/Data/RoundingMode.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Whether the agent's own figures come out round (spec 2026-09-28). The
 * values are config.xml's option ids for `roundingMode`, and
 * ConfigXmlSchemaTest pins the two lists together.
 */
enum RoundingMode: string
{
    case Off = 'off';

    /** The model's discount percentage, floored to the step (percentage points). */
    case DiscountPercent = 'discount_percent';

    /** A quote-wide offer's buyer-facing total, raised to the step (currency units). */
    case QuoteTotal = 'quote_total';
}
```

- [ ] **Step 4: Extend `QuoteLimits`**

In `src/Policy/Data/QuoteLimits.php`, put this docblock directly above `public function __construct(`:

```php
    /**
     * @mago-expect lint:excessive-parameter-list
     * Seven merchant settings, each named at the one production construction
     * site (fromArray()) and by name in every fixture; the two rounding
     * fields are one setting's two halves, and a sub-object for two scalars
     * would add a validated class for nothing.
     */
```

Add after the `minMarginPercent` parameter:

```php
        /**
         * Rounding control (spec 2026-09-28). Off unless the merchant picks a
         * mode AND a step above zero — see stepFor().
         */
        public RoundingMode $roundingMode = RoundingMode::Off,
        /** Percentage points in discount_percent mode; currency units of the buyer-facing total in quote_total mode. */
        #[Assert\PositiveOrZero]
        public ?float $roundingStep = null,
```

Add after the constructor:

```php
    /** The step `$mode` rounds to; null when `$mode` is not the one set, or the step is blank or zero. */
    public function stepFor(RoundingMode $mode): ?float
    {
        $step = $this->roundingStep ?? 0.0;

        return $this->roundingMode === $mode && $step > 0.0 ? $step : null;
    }
```

In `withMaxDiscountPercent()`, after `minMarginPercent: $this->minMarginPercent,` add:

```php
            roundingMode: $this->roundingMode,
            roundingStep: $this->roundingStep,
```

In `fromArray()`, after `minMarginPercent: OptionalShape::float($data, 'minMarginPercent'),` add:

```php
            // from(), not tryFrom(): a mode nothing knows must refuse the
            // channel (ValueError -> InvalidQuoteAgentConfiguration), not
            // quietly read as off.
            roundingMode: RoundingMode::from(OptionalShape::string($data, 'roundingMode') ?? RoundingMode::Off->value),
            roundingStep: OptionalShape::float($data, 'roundingStep'),
```

- [ ] **Step 5: Assemble and read the keys**

In `src/Config/NegotiationPolicyArray.php`, inside `$price = [...]`, after the `validityDays` entry:

```php
            // Null when the merchant never picked one, which QuoteLimits reads
            // as off; an unknown mode is refused there.
            'roundingMode' => RawConfigValue::string($raw, 'roundingMode'),
            // Blank or 0 means off whatever the mode (QuoteLimits::stepFor()).
            'roundingStep' => RawConfigValue::float($raw, 'roundingStep'),
```

In `src/Config/QuoteAgentSettingsReader.php` `KEYS`, after `'minMarginPercent',`:

```php
        'roundingMode',
        'roundingStep',
```

- [ ] **Step 6: The admin fields**

In `src/Resources/config/config.xml`, directly after the `minMarginPercent` `</input-field>`:

```xml
        <input-field type="single-select">
            <name>roundingMode</name>
            <label>Round the agent's offers</label>
            <defaultValue>off</defaultValue>
            <options>
                <option>
                    <id>off</id>
                    <name>Off: offers go out exactly as computed</name>
                </option>
                <option>
                    <id>discount_percent</id>
                    <name>Round the discount percentage down</name>
                </option>
                <option>
                    <id>quote_total</id>
                    <name>Round the quote total up</name>
                </option>
            </options>
            <helpText>Rounding only ever gives less discount, so it never breaks the limits above. It never rounds a figure the customer asked for, never takes back a discount they already hold, and never rounds a discount away to nothing: in each case the offer goes out unrounded. Quote-total rounding works on quote-wide offers only and writes a fixed-amount discount.</helpText>
        </input-field>
        <input-field type="float">
            <name>roundingStep</name>
            <label>Rounding step</label>
            <helpText>Percentage points when rounding the discount (0.5 turns 7.34% into 7.0%); your currency when rounding the total the customer sees, shipping included (10 turns 12,356.12 into 12,360.00). Blank or 0 means off whatever you picked above.</helpText>
        </input-field>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `composer run test -- --filter 'NegotiationPolicyValidationTest|QuoteAgentSettingsFactoryTest|QuoteAgentSettingsReaderTest|ConfigXmlSchemaTest|ValidatedConstraintsTest'`
Expected: PASS. `ValidatedConstraintsTest` is unchanged and still green, because `QuoteLimits.php` is already on its list and `RoundingMode.php` carries no constraint.

- [ ] **Step 8: Full check and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green. If lint reports `cyclomatic-complexity` on `QuoteLimits`, it has gone above the measured 10. Move the `?? RoundingMode::Off->value` default into a `RoundingMode::fromConfig(?string $value): self` static method, and do not raise the threshold.

```bash
git add src/Policy/Data/RoundingMode.php src/Policy/Data/QuoteLimits.php src/Config/NegotiationPolicyArray.php src/Config/QuoteAgentSettingsReader.php src/Resources/config/config.xml tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php tests/Unit/Config/QuoteAgentSettingsFactoryTest.php tests/Unit/Config/QuoteAgentSettingsReaderTest.php tests/Unit/Config/ConfigXmlSchemaTest.php
git commit -m "feat(config): roundingMode and roundingStep settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Pure rounding in Policy

**Files:**
- Create: `src/Policy/Data/RoundingSkip.php`, `src/Policy/Data/Rounding.php`, `src/Policy/RoundingStep.php`, `src/Policy/DiscountRounding.php`
- Test: `tests/Unit/Policy/RoundingStepTest.php`, `tests/Unit/Policy/DiscountRoundingTest.php`

**Interfaces:**
- Consumes: `RoundingMode`, `QuoteLimits::stepFor()` (Task 1).
- Produces: `enum RoundingSkip: string { BuyerFigure = 'buyer_figure'; StandingPrice = 'standing_price'; ToZero = 'to_zero'; TaxOnTop = 'tax_on_top' }`
- Produces: `final readonly class Rounding { __construct(RoundingMode $mode, float $step, float $unrounded, float $rounded, ?RoundingSkip $skipped); written(): float }`
- Produces: `RoundingStep::down(float $value, float $step): float`, `RoundingStep::up(float $value, float $step): float`, `RoundingStep::isBuyersFigure(?float $offeredPercent, ?float $askedPercent): bool`
- Produces: `DiscountRounding::offer(QuoteLimits $limits, ProposedOffer $offer, ?float $askedPercent, float $standingPercent): array{0: ProposedOffer, 1: ?Rounding}`. The `Rounding` is null when rounding did not run: another mode, a blank step, no percentage, or a per-line offer.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Policy/RoundingStepTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\RoundingStep;
use PHPUnit\Framework\TestCase;

final class RoundingStepTest extends TestCase
{
    public function testDownFloorsToAMultipleOfTheStep(): void
    {
        self::assertSame(7.0, RoundingStep::down(7.34, 0.5));
        self::assertSame(0.0, RoundingStep::down(0.3, 0.5));
    }

    public function testAFigureAlreadyOnTheStepSurvivesFloatNoise(): void
    {
        // 7.5 / 0.5 and 8.2 / 0.1 are 14.999… and 81.999… in binary floating point.
        self::assertSame(7.5, RoundingStep::down(7.5, 0.5));
        self::assertSame(8.2, RoundingStep::down(8.2, 0.1));
        self::assertSame(1350.0, RoundingStep::up(1350.0, 10.0));
        self::assertSame(0.3, RoundingStep::up(0.1 + 0.2, 0.1));
    }

    public function testUpRaisesToTheNextMultipleOfTheStep(): void
    {
        self::assertSame(1360.0, RoundingStep::up(1356.47, 10.0));
        self::assertSame(1357.0, RoundingStep::up(1356.47, 1.0));
    }

    public function testTheBuyersFigureIsMatchedToTwoDecimals(): void
    {
        self::assertTrue(RoundingStep::isBuyersFigure(7.34, 7.34));
        // A budget of 2500 on 2614.05 is 4.3629…%; the model answers 4.36.
        self::assertTrue(RoundingStep::isBuyersFigure(4.36, 4.3629));
        self::assertFalse(RoundingStep::isBuyersFigure(7.34, 8.0));
        self::assertFalse(RoundingStep::isBuyersFigure(7.34, null));
        self::assertFalse(RoundingStep::isBuyersFigure(null, 7.34));
    }
}
```

`tests/Unit/Policy/DiscountRoundingTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;
use MerchantQuoteAgentPlugin\Policy\DiscountRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscountRoundingTest extends TestCase
{
    private static function limits(RoundingMode $mode = RoundingMode::DiscountPercent, ?float $step = 0.5): QuoteLimits
    {
        return new QuoteLimits(maxDiscountPercent: 10.0, validityDays: 14, roundingMode: $mode, roundingStep: $step);
    }

    private static function offer(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: $percent));
    }

    public function testTheModelsPercentageIsFlooredToTheStep(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.34), null, 0.0);

        self::assertSame(7.0, $offer->price->discountPercent);
        self::assertEquals(new Rounding(RoundingMode::DiscountPercent, 0.5, 7.34, 7.0, null), $rounding);
    }

    public function testAPercentageAlreadyOnTheStepStaysExactlyThere(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.5), null, 0.0);

        self::assertSame(7.5, $offer->price->discountPercent);
        self::assertNull($rounding?->skipped);
    }

    public function testRoundingToNothingWritesTheUnroundedOffer(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(0.3), null, 0.0);

        self::assertSame(0.3, $offer->price->discountPercent);
        self::assertSame(RoundingSkip::ToZero, $rounding?->skipped);
        self::assertSame(0.0, $rounding?->rounded);
    }

    public function testTheBuyersOwnFigureIsNeverRounded(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.34), 7.34, 0.0);

        self::assertSame(7.34, $offer->price->discountPercent);
        self::assertSame(RoundingSkip::BuyerFigure, $rounding?->skipped);
    }

    public function testRoundingBelowWhatTheBuyerAlreadyHoldsWritesTheUnroundedOffer(): void
    {
        // Rounded, 7.0% would price a line above the 7.2% the buyer holds.
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.34), null, 7.2);

        self::assertSame(7.34, $offer->price->discountPercent);
        self::assertSame(RoundingSkip::StandingPrice, $rounding?->skipped);
    }

    /** @return iterable<string, array{0: RoundingMode, 1: ?float}> */
    public static function inactive(): iterable
    {
        yield 'off' => [RoundingMode::Off, 0.5];
        yield 'blank step' => [RoundingMode::DiscountPercent, null];
        yield 'zero step' => [RoundingMode::DiscountPercent, 0.0];
        yield 'the other mode' => [RoundingMode::QuoteTotal, 10.0];
    }

    #[DataProvider('inactive')]
    public function testNothingRoundsUnlessThisModeHasAStep(RoundingMode $mode, ?float $step): void
    {
        $offer = self::offer(7.34);

        self::assertSame([$offer, null], DiscountRounding::offer(self::limits($mode, $step), $offer, null, 0.0));
    }

    public function testAPerLineOfferIsNotRounded(): void
    {
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 92.66),
        ]));

        self::assertSame([$offer, null], DiscountRounding::offer(self::limits(), $offer, null, 0.0));
    }
}
```

`Rounding::written()` is covered by the tests above: the floored case writes the rounded rate, and each skip writes the unrounded one. The class stays at 10 methods, and mago's `too-many-methods` fails at 11.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter 'RoundingStepTest|DiscountRoundingTest'`
Expected: ERROR, `Class "MerchantQuoteAgentPlugin\Policy\RoundingStep" not found`.

- [ ] **Step 3: The two data types**

`src/Policy/Data/RoundingSkip.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/** Why a rounding was computed and the unrounded offer written instead (spec 2026-09-28, rules 2–4). */
enum RoundingSkip: string
{
    /** Rule 2: the offer is the buyer's own figure. */
    case BuyerFigure = 'buyer_figure';

    /** Rule 3: rounded, it would price a line above what the buyer already holds. */
    case StandingPrice = 'standing_price';

    /** Rule 4: rounded, no discount would be left. */
    case ToZero = 'to_zero';

    /**
     * quote_total only: a net quote with tax added on top. The absolute
     * discount is net there and the tax is rounded per rate after it, so no
     * amount is guaranteed to land on a round total.
     */
    case TaxOnTop = 'tax_on_top';
}
```

`src/Policy/Data/Rounding.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * What rounding control did to one offer, for the `rounding` trace event.
 * `unrounded` and `rounded` are percentages in discount_percent mode and
 * buyer-facing totals in quote_total mode.
 */
final readonly class Rounding
{
    public function __construct(
        public RoundingMode $mode,
        public float $step,
        public float $unrounded,
        public float $rounded,
        /** Null when the rounded figure is the one written. */
        public ?RoundingSkip $skipped,
    ) {}

    /** The figure that goes out: the rounded one, unless a rule skipped it. */
    public function written(): float
    {
        return $this->skipped === null ? $this->rounded : $this->unrounded;
    }
}
```

- [ ] **Step 4: The step arithmetic**

`src/Policy/RoundingStep.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

/**
 * Multiples of a merchant's rounding step (spec 2026-09-28), with the same
 * float-noise guard as MoneyMath::floorToCent(): round() first, so 7.5 / 0.5
 * = 14.999… still counts as 15 and 7.5 stays 7.5.
 */
final class RoundingStep
{
    private function __construct() {}

    public static function down(float $value, float $step): float
    {
        return round(floor(round($value / $step, precision: 6)) * $step, precision: 6);
    }

    public static function up(float $value, float $step): float
    {
        return round(ceil(round($value / $step, precision: 6)) * $step, precision: 6);
    }

    /**
     * Rule 2: an offer within 0.01 percentage points of what the buyer asked
     * (AskedDiscountCeiling::percent(), which already folds a percentage,
     * line prices and a budget into one figure) is their own. Two decimals,
     * not Epsilon::RATE: a budget converts to a long percentage the model
     * answers to two places, and RATE would round the buyer's own budget away.
     */
    public static function isBuyersFigure(?float $offeredPercent, ?float $askedPercent): bool
    {
        return (
            $offeredPercent !== null
            && $askedPercent !== null
            && abs($offeredPercent - $askedPercent) <= Epsilon::MONEY
        );
    }
}
```

- [ ] **Step 5: `discount_percent` mode**

`src/Policy/DiscountRounding.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;

/**
 * Rounding control, discount_percent mode (spec 2026-09-28): the model's
 * quote-wide percentage floored to the merchant's step, before
 * OfferLevelMirror and OfferAuthorizer, so the per-line conversion, the
 * checks and the reply all see the rate that is written.
 *
 * Flooring means less discount, so no limit can be breached by it and no
 * check is added. The three rules that write the unrounded rate instead are
 * skipped(). A per-line answer is left alone: it is mostly the buyer's own
 * line prices.
 */
final class DiscountRounding
{
    private function __construct() {}

    /**
     * @param ?float $askedPercent the buyer's ask on the anchored total (AskedDiscountCeiling::percent()); null when they named no number
     * @param float $standingPercent what the buyer already holds, on the total and the deepest line (CappedAuthority::standing())
     *
     * @return array{0: ProposedOffer, 1: ?Rounding} the offer to authorize, and what rounding did (null when it did not run)
     */
    public static function offer(
        QuoteLimits $limits,
        ProposedOffer $offer,
        ?float $askedPercent,
        float $standingPercent,
    ): array {
        $step = $limits->stepFor(RoundingMode::DiscountPercent);
        $percent = $offer->price->discountPercent;
        if ($step === null || $percent === null || $offer->price->linePricesNet !== null) {
            return [$offer, null];
        }

        $rounded = RoundingStep::down($percent, $step);
        $rounding = new Rounding(
            RoundingMode::DiscountPercent,
            $step,
            $percent,
            $rounded,
            self::skipped($percent, $rounded, $askedPercent, $standingPercent),
        );

        return [
            new ProposedOffer(
                orderTotalNet: $offer->orderTotalNet,
                price: new OfferedPrice(
                    discountPercent: $rounding->written(),
                    referenceLines: $offer->price->referenceLines,
                ),
            ),
            $rounding,
        ];
    }

    /** Null when the rounded rate may be written; a figure rounding did not move needs no rule. */
    private static function skipped(float $unrounded, float $rounded, ?float $asked, float $standing): ?RoundingSkip
    {
        if (abs($unrounded - $rounded) <= Epsilon::RATE) {
            return null;
        }

        return match (true) {
            RoundingStep::isBuyersFigure($unrounded, $asked) => RoundingSkip::BuyerFigure,
            $rounded <= Epsilon::RATE => RoundingSkip::ToZero,
            $rounded < ($standing - Epsilon::RATE) => RoundingSkip::StandingPrice,
            default => null,
        };
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer run test -- --filter 'RoundingStepTest|DiscountRoundingTest|ValidatedConstraintsTest'`
Expected: PASS.

- [ ] **Step 7: Full check and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green.

```bash
git add src/Policy/Data/RoundingSkip.php src/Policy/Data/Rounding.php src/Policy/RoundingStep.php src/Policy/DiscountRounding.php tests/Unit/Policy/RoundingStepTest.php tests/Unit/Policy/DiscountRoundingTest.php
git commit -m "feat(policy): floor a quote-wide discount percentage to the rounding step

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Wire `discount_percent` into the proposal, and trace it

**Files:**
- Create: `tests/Unit/Negotiation/RoundingFixture.php`, `tests/Unit/Negotiation/OfferProposerRoundingTest.php`
- Modify: `src/Audit/TraceKind.php`, `src/Audit/DecisionRecorder.php`, `src/Negotiation/CappedAuthority.php:86`, `src/Negotiation/OfferProposer.php` (`propose()`), `src/Negotiation/OfferRound.php:61`
- Test: `tests/Unit/Audit/DecisionRecorderTest.php`, `tests/Unit/Audit/Export/TraceMetaCoverageTest.php`

**Interfaces:**
- Consumes: `DiscountRounding::offer()`, `Rounding`, `RoundingSkip`, `RoundingMode` (Tasks 1–2); `AskedDiscountCeiling::percent(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): ?float` (existing).
- Produces: `TraceKind::Rounding` (`'rounding'`), meta keys `['mode', 'step', 'unrounded', 'rounded', 'skipped']`.
- Produces: `DecisionRecorder::recordRounding(?Rounding $rounding): void`. Null records nothing.
- Produces: `CappedAuthority::standing(PolicySnapshot $anchored, PolicySnapshot $live): float`, now public.
- Produces: `OfferProposer::propose(QuoteAgentSettings, PolicySnapshot, QuoteDecision, NegotiationContext, ?float $askedDiscountPercent = null): ProposedAnswer`.
- Produces (tests): `RoundingFixture::settings(RoundingMode $mode, float $step): QuoteAgentSettings` (max 10%, counter 20%, 14 days), `RoundingFixture::roundingMeta(FakeDecisionWriter $writer): ?array`, `RoundingFixture::writtenDiscount(FakeQuoteGateway $gateway): ?Discount`.

- [ ] **Step 1: The test fixture**

`tests/Unit/Negotiation/RoundingFixture.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/**
 * Shared inputs and readers for the rounding-control tests (spec 2026-09-28).
 * No private constructor: Task 4 brings this to ten methods, and mago's
 * too-many-methods fails at eleven.
 */
final class RoundingFixture
{
    /** NegotiationFixture::settings()'s bands, with rounding on. */
    public static function settings(RoundingMode $mode, float $step): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: 10.0,
                counterOfferMaxPercent: 20.0,
                validityDays: 14,
                roundingMode: $mode,
                roundingStep: $step,
            )),
            llm: NegotiationFixture::modelAccess(),
            strategyPrompt: null,
        );
    }

    /** @return array<string, mixed>|null the meta of the first pass's `rounding` event; null when none was recorded */
    public static function roundingMeta(FakeDecisionWriter $writer): ?array
    {
        foreach ($writer->drafts[0]->trace as $event) {
            if ($event->kind === TraceKind::Rounding) {
                return $event->meta;
            }
        }

        return null;
    }

    /** The first quote discount written, past updates that set none. */
    public static function writtenDiscount(FakeQuoteGateway $gateway): ?Discount
    {
        foreach ($gateway->quoteUpdates as $update) {
            if ($update->discount !== null) {
                return $update->discount;
            }
        }

        return null;
    }
}
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/Audit/DecisionRecorderTest.php` (add `use MerchantQuoteAgentPlugin\Policy\Data\Rounding;`, `use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;`, `use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;`):

```php
    public function testARoundingIsTracedWithItsFiguresAndNoRoundingIsNotTraced(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordRounding(null);
        $recorder->recordRounding(new Rounding(RoundingMode::DiscountPercent, 0.5, 7.34, 7.0, RoundingSkip::BuyerFigure));
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $events = array_values(array_filter(
            $writer->drafts[0]->trace,
            static fn(TraceDraft $t): bool => $t->kind === TraceKind::Rounding,
        ));
        self::assertCount(1, $events);
        self::assertSame(
            ['mode' => 'discount_percent', 'step' => 0.5, 'unrounded' => 7.34, 'rounded' => 7.0, 'skipped' => 'buyer_figure'],
            $events[0]->meta,
        );
        self::assertNull($events[0]->content);
    }
```

In `tests/Unit/Audit/Export/TraceMetaCoverageTest.php`, add `use MerchantQuoteAgentPlugin\Policy\Data\Rounding;`, `use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;`, `use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;`. In `samples()`, directly after the `recordDecision(...); // policy_verdict` statement, add:

```php
        $recorder->recordRounding(
            new Rounding(RoundingMode::QuoteTotal, 10.0, 1356.47, 1360.0, RoundingSkip::StandingPrice),
        ); // rounding
```

`tests/Unit/Negotiation/OfferProposerRoundingTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationContext;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ProposedAnswer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Rounding control, discount_percent mode, where the model's answer arrives (spec 2026-09-28). */
final class OfferProposerRoundingTest extends TestCase
{
    /** @return array{0: ProposedAnswer, 1: array<string, mixed>|null} the answer, and the pass's rounding meta */
    private static function propose(
        QuoteAgentSettings $settings,
        float $modelPercent,
        ?float $asked,
        ?float $requestedUnitPrice = null,
    ): array {
        [$client] = ScriptedClient::spy([
            sprintf('{"action":"offer","message":"ok","terms":{"discountPercent":%s}}', $modelPercent),
        ]);
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: $requestedUnitPrice);
        $recorder->begin($snapshot, NegotiationFixture::context());
        $policy = SnapshotAdapter::toPolicy($snapshot);

        $answer = (new OfferProposer(
            $client,
            new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}'),
            new OfferAuthorizer(),
            $recorder,
            new FakeCustomerHistoryFactory(),
        ))->propose(
            $settings,
            $policy,
            (new QuoteBandDecider())->decide($policy->withBuyerTargetNet(950.0), $settings->policy->price),
            new NegotiationContext(
                $snapshot->identity->customerId,
                $snapshot->identity->quoteId,
                SnapshotAdapter::conversation($snapshot),
            ),
            $asked,
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return [$answer, RoundingFixture::roundingMeta($writer)];
    }

    public function testTheModelsPercentageIsAuthorizedRoundedDown(): void
    {
        [$answer, $meta] = self::propose(RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5), 7.34, null);

        self::assertSame(7.0, $answer->offer?->price->discountPercent);
        self::assertSame(
            ['mode' => 'discount_percent', 'step' => 0.5, 'unrounded' => 7.34, 'rounded' => 7.0, 'skipped' => null],
            $meta,
        );
    }

    public function testAPerLineConversionInheritsTheRoundedRate(): void
    {
        // The buyer asked 90.00 on the 100.00 line (10%); the model answered
        // 7.34% quote-wide, and OfferLevelMirror prices the line at the
        // ROUNDED 7.0%.
        [$answer] = self::propose(RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5), 7.34, 10.0, 90.0);

        self::assertEquals([new QuoteLinePrice('line-1', 93.0)], $answer->offer?->price->linePricesNet);
    }

    public function testTheBuyersOwnPercentageIsAuthorizedAsAsked(): void
    {
        [$answer, $meta] = self::propose(RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5), 7.34, 7.34);

        self::assertSame(7.34, $answer->offer?->price->discountPercent);
        self::assertSame('buyer_figure', $meta['skipped'] ?? null);
    }

    public function testWithRoundingOffNothingIsRoundedOrTraced(): void
    {
        [$answer, $meta] = self::propose(NegotiationFixture::settings(), 7.34, null);

        self::assertSame(7.34, $answer->offer?->price->discountPercent);
        self::assertNull($meta);
    }

    /** @return iterable<string, array{0: float, 1: float}> */
    public static function asks(): iterable
    {
        yield 'the model offers exactly what the buyer asked' => [7.34, 7.34];
        yield 'the model offers less than the buyer asked' => [8.0, 7.0];
    }

    /** End to end: OfferRound must hand the buyer's ask to the proposer, or rule 2 never fires. */
    #[DataProvider('asks')]
    public function testThePipelineLeavesOnlyTheBuyersOwnFigureUnrounded(float $asked, float $written): void
    {
        $harness = PipelineHarness::with([
            sprintf('{"price":{"additionalDiscountPercent":%s}}', $asked),
            '{"action":"offer","message":"ok","terms":{"discountPercent":7.34}}',
            PipelineHarness::rewordedReply(),
        ]);

        $harness->pipeline->service(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment(sprintf('%s%% please', $asked), '2026-08-28 09:00:00'),
            ]),
            $harness->gateway,
            RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5),
            NegotiationFixture::context(),
        );

        self::assertEquals(
            new Discount(DiscountType::Percentage, $written),
            RoundingFixture::writtenDiscount($harness->gateway),
        );
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `composer run test -- --filter 'DecisionRecorderTest|TraceMetaCoverageTest|OfferProposerRoundingTest'`
Expected: ERROR, `Call to undefined method ...DecisionRecorder::recordRounding()`.

- [ ] **Step 4: The trace kind**

In `src/Audit/TraceKind.php`, add the case after `case SellerAct = 'seller_act';`:

```php
    case Rounding = 'rounding';
```

and the arm in `metaKeys()`, after the `self::SellerAct` arm:

```php
            self::Rounding => ['mode', 'step', 'unrounded', 'rounded', 'skipped'],
```

- [ ] **Step 5: Record it**

In `src/Audit/DecisionRecorder.php`, add `use MerchantQuoteAgentPlugin\Policy\Data\Rounding;`. Add this method after `recordProposal()`:

```php
    /**
     * What rounding control did to this pass's offer (spec 2026-09-28). Its
     * own event rather than a key on `policy_verdict`: that one is recorded
     * before the model has proposed anything there is to round. Null means
     * rounding did not run and records nothing. Only enums and figures, so
     * all of it is meta.
     */
    public function recordRounding(?Rounding $rounding): void
    {
        if ($rounding === null || $this->draft === null) {
            return;
        }

        TraceDraft::appendTo($this->draft, TraceKind::Rounding, [
            'mode' => $rounding->mode->value,
            'step' => $rounding->step,
            'unrounded' => $rounding->unrounded,
            'rounded' => $rounding->rounded,
            'skipped' => $rounding->skipped?->value,
        ], null);
    }
```

- [ ] **Step 6: Expose the standing concession**

In `src/Negotiation/CappedAuthority.php`, change `private static function standing(` to `public static function standing(`. Add this as the last paragraph of its docblock:

```php
     *
     * Public for rounding control (spec 2026-09-28, rule 3): OfferProposer
     * hands it to DiscountRounding, so a rounded rate never prices a line
     * above what the buyer already holds.
```

- [ ] **Step 7: Round in the proposer**

In `src/Negotiation/OfferProposer.php`, add `use MerchantQuoteAgentPlugin\Policy\DiscountRounding;`. Change the `propose()` signature to:

```php
    /** @throws ModelUnavailable */
    public function propose(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        NegotiationContext $context,
        ?float $askedDiscountPercent = null,
    ): ProposedAnswer {
```

Replace

```php
        $snapshot = $context->baseline?->anchor($snapshot) ?? $snapshot;
```

with

```php
        $live = $snapshot;
        $snapshot = $context->baseline?->anchor($snapshot) ?? $snapshot;
```

Replace

```php
        $offer = LinePriceNormalizer::normalize(
            self::atTheBuyersLevel($response->toOffer($snapshot->totalNet), $snapshot),
            $snapshot->lines,
        );
```

with

```php
        // Rounding control (spec 2026-09-28), discount_percent mode: before
        // the mirror, so a per-line conversion inherits the rounded rate, and
        // before authorize(), so the checks and the reply see the rate that
        // is written. Null (and nothing traced) in every other case.
        [$proposed, $rounding] = DiscountRounding::offer(
            $settings->policy->price,
            $response->toOffer($snapshot->totalNet),
            $askedDiscountPercent,
            CappedAuthority::standing($snapshot, $live),
        );
        $this->recorder->recordRounding($rounding);

        $offer = LinePriceNormalizer::normalize(self::atTheBuyersLevel($proposed, $snapshot), $snapshot->lines);
```

These edits add no `if`, `&&`, `||`, `??` or ternary: `OfferProposer` is at 10.

- [ ] **Step 8: Hand the buyer's ask over in `OfferRound`**

In `src/Negotiation/OfferRound.php`, add `use MerchantQuoteAgentPlugin\Policy\AskedDiscountCeiling;`. Replace

```php
        $answer = $this->proposer->propose($settings, SnapshotAdapter::toPolicy($snapshot), $decision->price, $context);
```

with

```php
        // Rounding control never rounds the buyer's own figure (spec
        // 2026-09-28, rule 2). This is that figure, measured the way
        // CappedAuthority measured it for this round's cap.
        $asked = AskedDiscountCeiling::percent(SnapshotAdapter::anchored($snapshot), $ask?->interpretation);
        $answer = $this->proposer->propose(
            $settings,
            SnapshotAdapter::toPolicy($snapshot),
            $decision->price,
            $context,
            $asked,
        );
```

Again, no counted construct: `OfferRound` is at 10, and `?->` does not count.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `composer run test -- --filter 'DecisionRecorderTest|TraceMetaCoverageTest|TraceKindTest|OfferProposerRoundingTest|OfferProposerTest|NegotiationPipelineTest|OfferRoundTest'`
Expected: PASS.

- [ ] **Step 10: Full check and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green. `NamespacePurityTest` stays green because the new Negotiation imports are all `MerchantQuoteAgentPlugin\Policy\*`.

```bash
git add src/Audit/TraceKind.php src/Audit/DecisionRecorder.php src/Negotiation/CappedAuthority.php src/Negotiation/OfferProposer.php src/Negotiation/OfferRound.php tests/Unit/Negotiation/RoundingFixture.php tests/Unit/Negotiation/OfferProposerRoundingTest.php tests/Unit/Audit/DecisionRecorderTest.php tests/Unit/Audit/Export/TraceMetaCoverageTest.php
git commit -m "feat(negotiation): round the model's discount percentage before authorization

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `PredictedWrite` learns an absolute discount

**Files:**
- Modify: `src/Negotiation/OfferWrite.php`, `src/Negotiation/PredictedWrite.php:39`, `tests/Unit/Negotiation/RoundingFixture.php`
- Create: `tests/Unit/Negotiation/PredictedWriteTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `OfferWrite::$discountFactor: ?float`. It is the share of its live net price each positive line costs once `$discount` is written, and null exactly when `$discount` is null.
- Produces: `OfferWrite::absolute(float $value, float $factor): self`, which writes no lines and an `Absolute` discount.
- Produces (tests): `RoundingFixture::mixedGross(): QuoteSnapshot` is 1255.00 net / 1463.45 gross. It has line-1 (10 × 100.00 net at 19%), line-2 (5 × 50.00 net at 7%) and 5.95 gross shipping. `RoundingFixture::mixedGrossHolding(): QuoteSnapshot` is the same quote holding 7.2% quote-wide. `RoundingFixture::mixedGrossLanded(float $totalGross = 1360.0): QuoteSnapshot` is the same quote after an absolute 103.45 has landed. `RoundingFixture::netQuote(float $totalGross): QuoteSnapshot` has the same goods at netRatio 1.0 and 1255.00 net.

- [ ] **Step 1: The quote fixtures**

Add to `tests/Unit/Negotiation/RoundingFixture.php`:

```php
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
```

and these methods:

```php
    /**
     * A gross quote with two VAT rates and shipping: 10 × 100.00 net at 19%
     * (1190.00 gross), 5 × 50.00 net at 7% (267.50 gross), 5.95 gross
     * shipping (5.00 net). 1255.00 net, 1463.45 gross.
     */
    public static function mixedGross(): QuoteSnapshot
    {
        return self::quote(self::goods(1000.0 / 1190.0, 250.0 / 267.5), 1255.0, 1463.45);
    }

    /** mixedGross() already holding 7.2% quote-wide: a discount line of -90.00 net, -104.94 gross. */
    public static function mixedGrossHolding(): QuoteSnapshot
    {
        return self::quote(
            [...self::goods(1000.0 / 1190.0, 250.0 / 267.5), self::discountLine(-90.0, -104.94)],
            1165.0,
            1358.51,
            new Discount(DiscountType::Percentage, 7.2),
        );
    }

    /**
     * mixedGross() once an absolute 103.45 has landed: the buyer-facing total
     * on 1360.00, net relief 88.72 (103.45 split 1190 : 267.5 over 19% and 7%).
     */
    public static function mixedGrossLanded(float $totalGross = 1360.0): QuoteSnapshot
    {
        return self::quote(
            [...self::goods(1000.0 / 1190.0, 250.0 / 267.5), self::discountLine(-88.72, -103.45)],
            1166.28,
            $totalGross,
            new Discount(DiscountType::Absolute, 103.45),
        );
    }

    /**
     * The same goods and shipping on a `net` quote (stored prices net, ratio
     * 1.0): 1255.00 net. A `$totalGross` of 1255.00 is tax-free; above it, tax
     * is added on top.
     */
    public static function netQuote(float $totalGross): QuoteSnapshot
    {
        return self::quote(self::goods(1.0, 1.0), 1255.0, $totalGross);
    }

    /** @return list<QuoteLineSnapshot> */
    private static function goods(float $ratio19, float $ratio7): array
    {
        return [
            new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: 100.0,
                totalNet: 1000.0,
                netRatio: $ratio19,
            ),
            new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-2', 'Gadget', 'prod-2'),
                quantity: 5,
                unitPriceNet: 50.0,
                totalNet: 250.0,
                netRatio: $ratio7,
            ),
        ];
    }

    private static function discountLine(float $net, float $gross): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity('discount', 'Quote discount'),
            quantity: 1,
            unitPriceNet: $net,
            totalNet: $net,
            netRatio: $net / $gross,
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private static function quote(
        array $lines,
        float $totalNet,
        float $totalGross,
        ?Discount $discount = null,
    ): QuoteSnapshot {
        $base = NegotiationFixture::snapshot(state: 'in_review');

        return new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: new QuoteTotals(totalNet: $totalNet, discount: $discount, totalGross: $totalGross),
            lifecycle: $base->lifecycle,
            content: new QuoteContent(lines: $lines),
        );
    }
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/Negotiation/PredictedWriteTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\OfferWrite;
use MerchantQuoteAgentPlugin\Negotiation\PredictedWrite;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use PHPUnit\Framework\TestCase;

final class PredictedWriteTest extends TestCase
{
    public function testAPercentageWriteMovesEveryGoodByItsPercentage(): void
    {
        $reference = NegotiationFixture::snapshot();
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));

        $predicted = PredictedWrite::of(OfferWrite::of($offer, null, $reference, 5.0), SnapshotAdapter::toPolicy($reference));

        self::assertEqualsWithDelta(950.0, $predicted->snapshot->totalNet, 1e-9);
        self::assertSame([], $predicted->refusals);
    }

    /**
     * Spec 2026-09-28: the net relief of an absolute discount is its value
     * scaled by the goods' net/gross ratio. SwagCommercial spreads it over the
     * goods by gross value, so every good keeps 1 − 103.45 / 1457.50 of its
     * net price: 1250.00 × 7.098% = 88.72 net off.
     */
    public function testAnAbsoluteWriteTakesItsNetShareOffEveryGood(): void
    {
        $predicted = PredictedWrite::of(
            OfferWrite::absolute(103.45, 1 - (103.45 / 1457.5)),
            SnapshotAdapter::toPolicy(RoundingFixture::mixedGross()),
        );

        self::assertEqualsWithDelta(1166.28, $predicted->snapshot->totalNet, 0.005);
        self::assertEqualsWithDelta(92.902, $predicted->snapshot->lines[0]->unitPriceNet, 0.001);
        self::assertSame([], $predicted->refusals);
    }

    public function testAnAbsoluteWriteBelowAStandingDiscountIsRefused(): void
    {
        // 7.1% of the goods against the 7.2% the buyer already holds.
        $predicted = PredictedWrite::of(
            OfferWrite::absolute(103.45, 1 - (103.45 / 1457.5)),
            SnapshotAdapter::toPolicy(RoundingFixture::mixedGrossHolding()),
        );

        self::assertNotSame([], $predicted->refusals);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `composer run test -- --filter PredictedWriteTest`
Expected: ERROR, `Call to undefined method ...OfferWrite::absolute()`.

- [ ] **Step 4: `OfferWrite` carries its factor**

In `src/Negotiation/OfferWrite.php`, replace the constructor and `of()` with:

```php
    /**
     * @param list<QuoteLinePrice> $lines unit prices to write; empty writes none
     * @param ?Discount $discount the quote discount to set; null leaves it as it is
     * @param ?float $discountFactor what share of its live net price each
     *     positive line costs once `$discount` is written (PredictedWrite);
     *     null exactly when `$discount` is. A percentage states it; an
     *     absolute amount does not, so whoever builds one works it out
     *     (QuoteTotalRounding).
     */
    private function __construct(
        public array $lines,
        public ?Discount $discount,
        public ?float $discountFactor = null,
    ) {}

    /**
     * A per-line offer writes its line prices and leaves the quote discount
     * alone; a quote-wide offer writes only the discount. A `$floored` offer
     * (MarginFloorClamp, spec 2026-09-24) prices every line with the old quote
     * discount folded in, so that discount goes to 0% — left on, it would stack
     * under the floor.
     *
     * A quote-wide offer writes `$quoteWidePercent`, the model's percentage
     * re-expressed on the live prices (QuoteWidePercent::of()),
     * never the raw one: that would stack on or replace a standing discount.
     */
    public static function of(
        ProposedOffer $offer,
        ?ProposedOffer $floored,
        QuoteSnapshot $reference,
        float $quoteWidePercent,
    ): self {
        if ($floored !== null) {
            $moved = self::moved($floored->price->linePricesNet ?? [], $reference);

            return $reference->totals->discount === null ? new self($moved, null) : self::percentage($moved, 0.0);
        }

        $lines = $offer->price->linePricesNet ?? [];

        return $lines !== [] ? new self($lines, null) : self::percentage([], $quoteWidePercent);
    }

    /**
     * An absolute quote discount of `$value` in the quote's own tax space
     * (Bridge\Data\Discount), leaving every positive line at `$factor` of its
     * live net price. Rounding control's quote_total mode (spec 2026-09-28).
     */
    public static function absolute(float $value, float $factor): self
    {
        return new self([], new Discount(DiscountType::Absolute, $value), $factor);
    }

    /** @param list<QuoteLinePrice> $lines */
    private static function percentage(array $lines, float $percent): self
    {
        return new self($lines, new Discount(DiscountType::Percentage, $percent), 1 - ($percent / 100));
    }
```

`moved()` is unchanged.

- [ ] **Step 5: `PredictedWrite` reads it**

In `src/Negotiation/PredictedWrite.php`, replace

```php
        $factor = $write->discount === null ? $goodsFactor : 1 - ($write->discount->value / 100);
```

with

```php
        // Any discount the write sets, percentage or absolute (spec
        // 2026-09-28): OfferWrite states what share of its live price every
        // good keeps under it. None keeps today's.
        $factor = $write->discountFactor ?? $goodsFactor;
```

In the class docblock, replace `(the quote discount folded in, as OfferLanding prices it)` with `(the quote discount folded in: OfferWrite::$discountFactor for a discount the write sets, whether a percentage or an absolute amount)`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer run test -- --filter 'PredictedWriteTest|OfferApplier|NeverRetractConcessionTest|StandingConcessionTest'`
Expected: PASS. For every existing write the factor is exactly what `PredictedWrite` computed before (`1 - value/100`, or the goods factor when there is no discount).

- [ ] **Step 7: Full check and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green.

```bash
git add src/Negotiation/OfferWrite.php src/Negotiation/PredictedWrite.php tests/Unit/Negotiation/RoundingFixture.php tests/Unit/Negotiation/PredictedWriteTest.php
git commit -m "feat(negotiation): predict an absolute quote discount before writing it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `quote_total` mode

**Files:**
- Create: `src/Negotiation/BuyerFacingGoods.php`, `src/Negotiation/QuoteTotalRounding.php`, `tests/Unit/Negotiation/BuyerFacingGoodsTest.php`, `tests/Unit/Negotiation/QuoteTotalRoundingTest.php`
- Modify: `src/Negotiation/OfferApplier.php` (`apply()`), `src/Negotiation/OfferRound.php:102`, `src/Bridge/Data/Discount.php` (docblock)

**Interfaces:**
- Consumes: `OfferWrite::absolute()`, `OfferWrite::$discountFactor`, `PredictedWrite::of()` (Task 4); `Rounding`, `RoundingSkip`, `RoundingStep::up()`, `RoundingStep::isBuyersFigure()`, `QuoteLimits::stepFor()` (Tasks 1–2); `DecisionRecorder::recordRounding()`, and `$asked` in `OfferRound::play()` (Task 3).
- Produces: `BuyerFacingGoods::of(QuoteSnapshot $quote): self` with `float $gross`, `float $otherCosts`, `bool $taxOnTop`, plus `factorAfter(float $absolute): float`.
- Produces: `QuoteTotalRounding::of(OfferWrite $write, QuoteSnapshot $reference, PolicySnapshot $live, QuoteLimits $limits, bool $buyersFigure): array{0: OfferWrite, 1: ?Rounding}` and `QuoteTotalRounding::missed(?Rounding $rounding, float $landed): bool`.
- Produces: `OfferApplier::apply(QuoteGatewayInterface, QuoteSnapshot, QuoteAgentSettings, ProposedOffer, ?float $askedDiscountPercent = null): AppliedOffer`.

The arithmetic, on `RoundingFixture::mixedGross()` at 7.34% with a step of 10:
- Goods before discount: 1190.00 + 267.50 = 1457.50. Other costs: 1463.45 − 1457.50 = 5.95.
- Unrounded total: 1457.50 × 0.9266 + 5.95 = 1356.47. Target, rounded up: 1360.00.
- Absolute discount: 1457.50 + 5.95 − 1360.00 = 103.45 gross. That is less than the unrounded 106.98.
- Shopware's `AbsolutePriceCalculator` keeps the gross discount line at exactly −103.45 and splits it over the tax rates by gross share. The gross total therefore drops by exactly 103.45 and lands on 1360.00.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Negotiation/BuyerFacingGoodsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\BuyerFacingGoods;
use PHPUnit\Framework\TestCase;

final class BuyerFacingGoodsTest extends TestCase
{
    public function testAGrossQuotesGoodsAndShippingAreReadBackInItsOwnTaxSpace(): void
    {
        $goods = BuyerFacingGoods::of(RoundingFixture::mixedGross());

        self::assertEqualsWithDelta(1457.5, $goods->gross, 1e-9);
        self::assertEqualsWithDelta(5.95, $goods->otherCosts, 1e-9);
        self::assertFalse($goods->taxOnTop);
    }

    public function testAHeldDiscountIsNeitherGoodsNorOtherCosts(): void
    {
        $goods = BuyerFacingGoods::of(RoundingFixture::mixedGrossHolding());

        self::assertEqualsWithDelta(1457.5, $goods->gross, 1e-9);
        self::assertEqualsWithDelta(5.95, $goods->otherCosts, 1e-9);
    }

    public function testTaxAddedOnTopOfNetLinesIsRecognised(): void
    {
        self::assertTrue(BuyerFacingGoods::of(RoundingFixture::netQuote(1493.45))->taxOnTop);
        self::assertFalse(BuyerFacingGoods::of(RoundingFixture::netQuote(1255.0))->taxOnTop);
    }

    public function testAnAbsoluteAmountIsSpreadOverTheGoodsByValue(): void
    {
        self::assertEqualsWithDelta(0.929022, BuyerFacingGoods::of(RoundingFixture::mixedGross())->factorAfter(103.45), 1e-6);
    }
}
```

`tests/Unit/Negotiation/QuoteTotalRoundingTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rounding control, quote_total mode, at the one place offers are written
 * (spec 2026-09-28). Ten methods, the most mago's too-many-methods allows.
 * The four rules share one data-provided test for that reason.
 */
final class QuoteTotalRoundingTest extends TestCase
{
    /** @return array{0: AppliedOffer, 1: array<string, mixed>|null, 2: RecordingLogger} */
    private static function apply(
        FakeQuoteGateway $gateway,
        ProposedOffer $offer,
        ?float $asked = null,
        float $step = 10.0,
    ): array {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $logger = new RecordingLogger();
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        $applied = (new OfferApplier(
            new OfferVerifier(),
            $logger,
            $recorder,
            new MarginFloorGuard(new FakePurchasePrices()),
        ))->apply(
            $gateway,
            NegotiationFixture::snapshot(),
            RoundingFixture::settings(RoundingMode::QuoteTotal, $step),
            $offer,
            $asked,
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return [$applied, RoundingFixture::roundingMeta($writer), $logger];
    }

    private static function quoteWide(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1255.0, price: new OfferedPrice(discountPercent: $percent));
    }

    public function testAGrossQuoteWithTwoRatesAndShippingLandsOnTheRoundTotal(): void
    {
        $gateway = new FakeQuoteGateway([RoundingFixture::mixedGross(), RoundingFixture::mixedGrossLanded()]);

        [, $meta, $logger] = self::apply($gateway, self::quoteWide(7.34));

        self::assertEquals(new Discount(DiscountType::Absolute, 103.45), RoundingFixture::writtenDiscount($gateway));
        self::assertEquals(
            ['mode' => 'quote_total', 'step' => 10.0, 'unrounded' => 1356.47, 'rounded' => 1360.0, 'skipped' => null],
            $meta,
        );
        // Shopware keeps an absolute discount line at exactly -value gross.
        self::assertEqualsWithDelta(1360.0, 1457.5 - 103.45 + 5.95, 1e-9);
        self::assertNull($logger->contextOf('did not land'));
    }

    public function testATaxFreeQuoteLandsOnTheRoundTotalToo(): void
    {
        // 1250.00 × 0.9266 + 5.00 = 1163.25 → 1170.00; 1255.00 − 1170.00 = 85.00.
        $gateway = new FakeQuoteGateway([RoundingFixture::netQuote(1255.0)]);

        self::apply($gateway, self::quoteWide(7.34));

        self::assertEquals(new Discount(DiscountType::Absolute, 85.0), RoundingFixture::writtenDiscount($gateway));
    }

    /** @return iterable<string, array{0: \Closure(): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot, 1: float, 2: ?float, 3: float, 4: string}> */
    public static function skips(): iterable
    {
        yield 'tax added on top of net prices' => [
            static fn() => RoundingFixture::netQuote(1493.45), 7.34, null, 10.0, 'tax_on_top',
        ];
        yield 'rounded, it would take back the 7.2% the buyer holds' => [
            static fn() => RoundingFixture::mixedGrossHolding(), 7.34, null, 10.0, 'standing_price',
        ];
        yield 'the buyer asked for exactly this' => [
            static fn() => RoundingFixture::mixedGross(), 7.34, 7.34, 10.0, 'buyer_figure',
        ];
        yield 'rounded up to 1500.00, no discount is left' => [
            static fn() => RoundingFixture::mixedGross(), 0.3, null, 100.0, 'to_zero',
        ];
    }

    /** @param \Closure(): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $quote */
    #[DataProvider('skips')]
    public function testEachRuleWritesTheUnroundedPercentage(
        \Closure $quote,
        float $percent,
        ?float $asked,
        float $step,
        string $skipped,
    ): void {
        $gateway = new FakeQuoteGateway([$quote()]);

        [, $meta] = self::apply($gateway, self::quoteWide($percent), $asked, $step);

        self::assertEquals(new Discount(DiscountType::Percentage, $percent), RoundingFixture::writtenDiscount($gateway));
        self::assertSame($skipped, $meta['skipped'] ?? null);
    }

    public function testAPerLineOfferIsNotRounded(): void
    {
        $gateway = new FakeQuoteGateway([RoundingFixture::mixedGross()]);
        $offer = new ProposedOffer(orderTotalNet: 1255.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 95.0),
        ]));

        [, $meta] = self::apply($gateway, $offer);

        self::assertNull($meta);
        self::assertSame(95.0, $gateway->lineItemChanges[0]->unitPriceNet);
    }

    public function testATotalThatMissedItsRoundFigureIsLoggedNotHidden(): void
    {
        $gateway = new FakeQuoteGateway([RoundingFixture::mixedGross(), RoundingFixture::mixedGrossLanded(1361.0)]);

        [, , $logger] = self::apply($gateway, self::quoteWide(7.34));

        self::assertSame(['quoteId' => 'q1', 'landed' => 1361.0], $logger->contextOf('did not land'));
    }

    /** @return iterable<string, array{0: float, 1: Discount}> */
    public static function asks(): iterable
    {
        yield 'the model offers exactly what the buyer asked' => [7.34, new Discount(DiscountType::Percentage, 7.34)];
        // 1000.00 × 0.9266 = 926.60 → 930.00; 1000.00 − 930.00 = 70.00.
        yield 'the model offers less than the buyer asked' => [8.0, new Discount(DiscountType::Absolute, 70.0)];
    }

    /** End to end: OfferRound must hand the buyer's ask to the applier, or rule 2 never fires. */
    #[DataProvider('asks')]
    public function testThePipelineLeavesOnlyTheBuyersOwnFigureUnrounded(float $asked, Discount $written): void
    {
        $harness = PipelineHarness::with([
            sprintf('{"price":{"additionalDiscountPercent":%s}}', $asked),
            '{"action":"offer","message":"ok","terms":{"discountPercent":7.34}}',
            PipelineHarness::rewordedReply(),
        ]);

        $harness->pipeline->service(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment(sprintf('%s%% please', $asked), '2026-08-28 09:00:00'),
            ]),
            $harness->gateway,
            RoundingFixture::settings(RoundingMode::QuoteTotal, 10.0),
            NegotiationFixture::context(),
        );

        self::assertEquals($written, RoundingFixture::writtenDiscount($harness->gateway));
    }
}
```

Do not assert `$applied->verified` on the shipping fixtures. `NetFactor` folds shipping into every line, and it reads the post-write lines (the discount line included) with a slightly higher factor than the pre-write reference. On this fixture the line check therefore reports 100.43 against 100.40. That behaviour predates this plan and is listed under open risks. The rounding tests pin the write and the trace, not the verifier.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter 'BuyerFacingGoodsTest|QuoteTotalRoundingTest'`
Expected: ERROR, `Class "MerchantQuoteAgentPlugin\Negotiation\BuyerFacingGoods" not found`.

- [ ] **Step 3: The goods in the buyer-facing space**

`src/Negotiation/BuyerFacingGoods.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;

/**
 * A quote's goods in the space the buyer is shown (rounding control, spec
 * 2026-09-28): the quote's own tax space, which is what an Absolute discount
 * is denominated in (Bridge\Data\Discount) and what
 * QuoteTotals::buyerFacingTotal() adds up.
 *
 * `totalNet / netRatio` gives back the stored `totalPrice` each line was read
 * from (QuoteLineNet), so no second read of the quote is needed.
 */
final readonly class BuyerFacingGoods
{
    private function __construct(
        /** The positive lines before any quote discount: what SwagCommercial takes an absolute discount off. */
        public float $gross,
        /** Everything in the buyer-facing total that is not a line: shipping, cash rounding. */
        public float $otherCosts,
        /**
         * Net lines with tax added on top (a `net` quote): the absolute
         * discount is net there and the tax is rounded per rate after it, so
         * no amount is guaranteed to land on a round total.
         */
        public bool $taxOnTop,
    ) {}

    public static function of(QuoteSnapshot $quote): self
    {
        $gross = 0.0;
        $net = 0.0;
        $lines = 0.0;
        foreach ($quote->content->lines as $line) {
            $stored = MoneyMath::roundMoney($line->totalNet / $line->netRatio);
            $lines += $stored;
            if ($line->totalNet > 0.0) {
                $gross += $stored;
                $net += $line->totalNet;
            }
        }

        $total = $quote->totals->buyerFacingTotal();

        return new self(
            $gross,
            MoneyMath::roundMoney($total - $lines),
            abs($gross - $net) <= Epsilon::MONEY && $total > ($quote->totals->totalNet + Epsilon::MONEY),
        );
    }

    /**
     * What share of its net price every good keeps under an absolute discount
     * of `$absolute`. AbsolutePriceCalculator splits it over the tax rates by
     * gross value, which takes the same fraction off every good's net price.
     */
    public function factorAfter(float $absolute): float
    {
        return $this->gross > 0.0 ? 1 - ($absolute / $this->gross) : 1.0;
    }
}
```

- [ ] **Step 4: The mode itself**

`src/Negotiation/QuoteTotalRounding.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;
use MerchantQuoteAgentPlugin\Policy\RoundingStep;

/**
 * Rounding control, quote_total mode (spec 2026-09-28). A quote-wide write
 * becomes an absolute SwagCommercial discount that lands the buyer-facing
 * total on the next multiple of the merchant's step:
 * `goods before discount − (rounded target − other costs)`, in the quote's
 * own tax space.
 *
 * An absolute amount rather than an adjusted percentage, because per-rate
 * cent rounding keeps a percentage from landing on an exact figure. The
 * target is rounded UP, so the discount only shrinks and no limit can be
 * breached by it.
 */
final class QuoteTotalRounding
{
    private function __construct() {}

    /**
     * @param OfferWrite $write the unrounded write (OfferWrite::of())
     * @param QuoteSnapshot $reference the pre-write quote, read fresh
     * @param PolicySnapshot $live the same quote in policy terms
     * @param bool $buyersFigure the offer is the buyer's own ask (RoundingStep::isBuyersFigure())
     *
     * @return array{0: OfferWrite, 1: ?Rounding} the write to make, and what rounding did (null when it did not run)
     */
    public static function of(
        OfferWrite $write,
        QuoteSnapshot $reference,
        PolicySnapshot $live,
        QuoteLimits $limits,
        bool $buyersFigure,
    ): array {
        $step = $limits->stepFor(RoundingMode::QuoteTotal);
        $factor = $write->discountFactor;
        if ($step === null || $factor === null || $write->lines !== []) {
            return [$write, null];
        }

        $goods = BuyerFacingGoods::of($reference);
        $unrounded = MoneyMath::roundMoney(($goods->gross * $factor) + $goods->otherCosts);
        $target = RoundingStep::up($unrounded, $step);
        $absolute = MoneyMath::roundMoney($goods->gross + $goods->otherCosts - $target);
        $rounded = OfferWrite::absolute($absolute, $goods->factorAfter($absolute));
        $skipped = self::skipped($rounded, $live, $goods, $absolute, $buyersFigure);

        return [
            $skipped === null ? $rounded : $write,
            new Rounding(RoundingMode::QuoteTotal, $step, $unrounded, $target, $skipped),
        ];
    }

    /** Rule 5: a rounded total that landed more than a cent off its round figure. */
    public static function missed(?Rounding $rounding, float $landed): bool
    {
        return (
            $rounding !== null
            && $rounding->mode === RoundingMode::QuoteTotal
            && $rounding->skipped === null
            && abs($landed - $rounding->rounded) > Epsilon::MONEY
        );
    }

    /**
     * To-zero comes before standing-price for a reason beyond order:
     * SwagCommercial writes `-abs($value)`, so a negative amount would turn
     * into a discount, and nothing negative may get past this method.
     *
     * ponytail: standing_price is any PredictedWrite refusal. That includes
     * "total below its lines with no discount line", which the unrounded
     * write then trips too and escalates on, unrelated to rounding. Compare
     * the two writes' refusals if that trace reading ever misleads someone.
     */
    private static function skipped(
        OfferWrite $rounded,
        PolicySnapshot $live,
        BuyerFacingGoods $goods,
        float $absolute,
        bool $buyersFigure,
    ): ?RoundingSkip {
        return match (true) {
            $buyersFigure => RoundingSkip::BuyerFigure,
            $goods->taxOnTop => RoundingSkip::TaxOnTop,
            $absolute < Epsilon::MONEY => RoundingSkip::ToZero,
            PredictedWrite::of($rounded, $live)->refusals !== [] => RoundingSkip::StandingPrice,
            default => null,
        };
    }
}
```

- [ ] **Step 5: Wire it into `OfferApplier`**

In `src/Negotiation/OfferApplier.php`, add `use MerchantQuoteAgentPlugin\Policy\RoundingStep;`. Change the `apply()` signature to:

```php
    public function apply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
        ?float $askedDiscountPercent = null,
    ): AppliedOffer {
```

Directly after the `$write = OfferWrite::of(...);` statement, add:

```php
        // Rounding control, quote_total mode (spec 2026-09-28): a quote-wide
        // write becomes an absolute discount that lands the buyer-facing
        // total on the merchant's step. Before rejected(), so the prediction
        // below measures the write that will actually be made.
        [$write, $rounding] = QuoteTotalRounding::of(
            $write,
            $reference,
            $live,
            $limits,
            RoundingStep::isBuyersFigure($offer->price->discountPercent, $askedDiscountPercent),
        );
        $this->recorder->recordRounding($rounding);
```

Directly before `$applied = new AppliedOffer($violations === [], $violations, $after, $reference->totals->totalNet);`, add:

```php
        // Rule 5: a round target the write missed is not accepted silently.
        // The checks above still decide the pass; this says the absolute
        // arithmetic and SwagCommercial's recalculation disagreed (a shipping
        // cost that moved with the cart value, say). The target is on the
        // rounding trace event.
        if (QuoteTotalRounding::missed($rounding, $after->totals->buyerFacingTotal())) {
            $this->logger->warning('The rounded quote total did not land on its round figure.', [
                'quoteId' => $quoteId,
                'landed' => $after->totals->buyerFacingTotal(),
            ]);
        }
```

That is one new `if`, which takes `OfferApplier` from 8 to 9.

- [ ] **Step 6: Hand the ask to the applier**

In `src/Negotiation/OfferRound.php`, replace

```php
        $applied = $this->applier->apply($gateway, $snapshot, $settings, $answer->offer);
```

with

```php
        $applied = $this->applier->apply($gateway, $snapshot, $settings, $answer->offer, $asked);
```

- [ ] **Step 7: Correct the `Discount` docblock**

In `src/Bridge/Data/Discount.php`, replace the last paragraph (`A caller that needs a guaranteed net reduction should express it as` … `which is all `src/Policy` emits today.`) with:

```php
 * A caller that needs a guaranteed net reduction should express it as
 * `Percentage`. The one caller that writes `Absolute` does so for exactly
 * this tax-state behaviour: Negotiation\QuoteTotalRounding computes the value
 * in the buyer-facing space so that the gross total lands on a round figure,
 * and it leaves a quote with tax added on top unrounded.
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `composer run test -- --filter 'BuyerFacingGoodsTest|QuoteTotalRoundingTest|PredictedWriteTest|OfferApplier|OfferRoundTest|NegotiationPipelineTest|OfferProposerRoundingTest'`
Expected: PASS.

- [ ] **Step 9: Full check and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green.

```bash
git add src/Negotiation/BuyerFacingGoods.php src/Negotiation/QuoteTotalRounding.php src/Negotiation/OfferApplier.php src/Negotiation/OfferRound.php src/Bridge/Data/Discount.php tests/Unit/Negotiation/BuyerFacingGoodsTest.php tests/Unit/Negotiation/QuoteTotalRoundingTest.php
git commit -m "feat(negotiation): land a quote-wide offer on a round buyer-facing total

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Shop proof and merchant docs

**Needs the test shop.** `composer run test:integration` syncs this checkout into `merchant-quote-shop` (see the README). Also run it with `SHOP_CONTAINER=merchant-quote-shop-6712` if that container is up, because `LegacyGrossQuoteTest` is the released-SwagCommercial lane.

**Files:**
- Modify: `tests/Integration/LegacyGrossQuoteTest.php`, `tests/Integration/PluginConfigTest.php`, `docs/for-merchants.md` (§2 "Set the limits", "Limits worth knowing"), `docs/end-to-end.md` (the config table after `minMarginPercent`)

**Interfaces:**
- Consumes: `QuoteTotalRounding::of()`, `OfferWrite::of()`, `OfferWrite::$discountFactor`, `PredictedWrite::of()`, `QuoteWidePercent::of()`, `GoodsFactor::of()`, `SnapshotAdapter::toPolicy()`, `QuoteLimits` (Tasks 1–5).

- [ ] **Step 1: Write the landing test**

In `tests/Integration/LegacyGrossQuoteTest.php`, add these imports:

```php
use MerchantQuoteAgentPlugin\Negotiation\OfferWrite;
use MerchantQuoteAgentPlugin\Negotiation\PredictedWrite;
use MerchantQuoteAgentPlugin\Negotiation\QuoteTotalRounding;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\GoodsFactor;
use MerchantQuoteAgentPlugin\Policy\QuoteWidePercent;
```

Then add this public test method after `testBothMerchantLeversWorkOnAGrossQuote()`:

```php
    /**
     * Rounding control, quote_total mode (spec 2026-09-28), through
     * SwagCommercial's own recalculation: the absolute discount
     * QuoteTotalRounding computes lands the buyer-facing total exactly on the
     * step; PredictedWrite's net prediction of it is what the shop books, so
     * the pre-write checks measure the real result; and the next round reads
     * it off the discount line as the same goods factor.
     */
    public function testARoundedAbsoluteDiscountLandsOnTheRoundTotal(): void
    {
        $context = self::grossContext();
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
        $created = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 20]],
            'Rounding landing proof.',
        );
        self::assertSame(CartPrice::TAX_STATE_GROSS, $created->taxStatus);

        $gateway = static::gateway();
        $reference = $gateway->fetchSnapshot($created->id);
        $live = SnapshotAdapter::toPolicy($reference);
        $offer = new ProposedOffer($live->totalNet, new OfferedPrice(discountPercent: 7.34));
        [$write, $rounding] = QuoteTotalRounding::of(
            OfferWrite::of($offer, null, $reference, QuoteWidePercent::of($offer, $live->lines, $live->lines)),
            $reference,
            $live,
            new QuoteLimits(
                maxDiscountPercent: 10.0,
                validityDays: 14,
                roundingMode: RoundingMode::QuoteTotal,
                roundingStep: 1.0,
            ),
            false,
        );

        self::assertNotNull($rounding);
        self::assertNull($rounding->skipped, 'A fresh gross quote must round, not skip: ' . $rounding->skipped?->value);
        self::assertSame(DiscountType::Absolute, $write->discount?->type);

        $gateway->updateQuote($created->id, new QuoteUpdate(discount: $write->discount));
        $gateway->recalculate($created->id);
        $after = $gateway->fetchSnapshot($created->id);

        self::assertEqualsWithDelta(
            $rounding->rounded,
            $after->totals->buyerFacingTotal(),
            0.001,
            'The absolute discount did not land the buyer-facing total on the round figure.',
        );
        self::assertEqualsWithDelta(
            PredictedWrite::of($write, $live)->snapshot->totalNet,
            $after->totals->totalNet,
            0.02,
            'PredictedWrite mispredicts the net relief of an absolute discount.',
        );
        self::assertEqualsWithDelta(
            $write->discountFactor,
            GoodsFactor::of(SnapshotAdapter::toPolicy($after)->lines),
            0.001,
            'The next round reads the absolute discount off its line as a different goods factor.',
        );
    }
```

- [ ] **Step 2: Pin the persisted default**

Append to `tests/Integration/PluginConfigTest.php`:

```php
    /**
     * roundingMode ships `off` as a string, the type RoundingMode::from()
     * reads. It is saved here the way install and update save config.xml's
     * defaults (override on, inside this rolled-back transaction), because the
     * test-shop sync never re-saves them. A shop installed before the field
     * existed has no row for it at all, and that reads as off too, just not
     * through this path.
     */
    public function testTheRoundingModeDefaultIsPersistedAsItsEnumString(): void
    {
        $config = self::systemConfig();
        $config->savePluginConfiguration(static::getKernel()->getBundle('MerchantQuoteAgentPlugin'), true);

        self::assertSame('off', $config->get(QuoteAgentSettingsReader::DOMAIN . 'roundingMode'));
    }
```

- [ ] **Step 3: Run the integration tests**

Run: `composer run test:integration -- --filter 'LegacyGrossQuoteTest|PluginConfigTest|UpdateQuoteTest'`
Expected: PASS. If the landing assertion is off by a cent, the shop's cash rounding (`totalRounding`) interval is not a divisor of 1.00. Record the shop's interval in the failure message and use a step that is a multiple of it. Do not widen the delta.

- [ ] **Step 4: Merchant docs**

In `docs/for-merchants.md`, §"2. Set the limits", insert after the **Maximum quote value for negotiation (net)** bullet:

```markdown
- **Round the agent's offers** and **Rounding step** — optional, off by
  default. Left alone, the agent's figures can read like machine output: a
  total of 12,356.12 € or 4.34 % off. Pick how they come out round:
  - *Round the discount percentage down*: the step is in percentage points.
    With `0.5`, 7.34 % becomes 7.0 %.
  - *Round the quote total up*: the step is in your currency, on the total the
    customer sees (gross on a gross quote, shipping included). With `10`,
    12,356.12 € becomes 12,360.00 €. The agent writes this as a fixed-amount
    quote discount, so the quote shows "Discount 103.45 €" rather than a
    percentage.

  Rounding only ever gives *less* discount, so it can never break your
  maximum, counter band, margin floor or value ceiling. It never rounds a
  figure the customer asked for themselves (their percentage, their line
  prices or their budget). It never takes back a discount they already hold,
  and it never rounds a discount away to nothing. In each of those cases the
  offer goes out unrounded. A blank or `0` step means off, whichever option
  you picked.
```

In "Limits worth knowing", add this bullet after the "It negotiates **price and offer validity**" bullet:

```markdown
- **Rounding the quote total** works on quote-wide offers only. A per-line
  offer (mostly the customer's own line prices) goes out unrounded. It is also
  skipped on a quote that adds tax on top of net prices: the tax is rounded
  per rate after the discount, so no fixed amount is guaranteed to land on a
  round total. Shopware's own cash rounding is unaffected as long as your step
  is a multiple of its interval.
```

In `docs/end-to-end.md`, add these rows to the configuration table directly after the `minMarginPercent` row:

```markdown
| `roundingMode` | `off` | `off`, `discount_percent` (the model's quote-wide percentage is floored to the step before authorization, so the checks, the per-line conversion and the reply all see it) or `quote_total` (a quote-wide write becomes an absolute discount that lands the buyer-facing total, shipping included, on the next multiple of the step). Never the buyer's own figure, never below a standing concession, never to nothing, and not on a net quote with tax on top; each skip is recorded on the `rounding` trace event. |
| `roundingStep` | — | Percentage points in `discount_percent`, currency units of the buyer-facing total in `quote_total`. Blank or `0` means off whatever the mode. |
```

- [ ] **Step 5: Full check and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green.

```bash
git add tests/Integration/LegacyGrossQuoteTest.php tests/Integration/PluginConfigTest.php docs/for-merchants.md docs/end-to-end.md
git commit -m "test(integration): a rounded absolute discount lands on the round total; docs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Open risks

- **Shipping that moves with the cart value.** SwagCommercial recalculates the delivery. If shipping depends on the price, the landed total misses the target. Rule 5 logs a warning and the existing checks decide the pass.
- **`NetFactor` with shipping.** This predates the plan and is not fixed by it. `LineOfferVerifier` normalises lines with `totalNet / Σ lines`, and after the write that sum includes the negative discount line. On a quote with shipping, the post-write factor is therefore higher than the reference factor. On `RoundingFixture` that makes the line check report 100.43 against 100.40, the "above its reference price" check. Any quote-wide discount on a quote with shipping can trip it, rounded or not. The live quotes' summed `totalPrice` equals `amount_total`, which suggests they carry no shipping. Worth its own issue.
- **Mixed tax rates on a real shop.** The integration test uses one product, so one rate. The landing with two rates rests on `AbsolutePriceCalculator`'s semantics (the gross line is exactly `-value`), which the unit tests assume.
- **Buyer-visible label.** A `quote_total` rounding shows "Discount 103.45 €" on the quote, not a percentage. The reply still states a reduction percentage measured from the baseline (for example 7.07 %).
- **Deployed shops.** The `off` default is only persisted by install or update. A branch deployed with `database:migrate --all` has no row, which reads as off. That is safe, but the admin field then shows empty until someone saves it.

## Self-review

- **Spec coverage.**
  - The setting, and blank/0 meaning off: Task 1.
  - Rule 1: `down`/`up` only, so no new check.
  - Rule 2: `RoundingStep::isBuyersFigure()` in both modes (Tasks 2, 3, 5).
  - Rule 3: `DiscountRounding` via `standing()`, and `QuoteTotalRounding` via `PredictedWrite` (Tasks 2, 5).
  - Rule 4: `ToZero` in both modes.
  - `discount_percent` before authorize, with the per-line conversion inheriting the rate: Task 3.
  - `quote_total` steps 1–4: Task 5, plus `PredictedWrite` in Task 4. Step 5 is the Task 5 warning.
  - Audit: Task 3 (deviation 1).
  - Reply unchanged: nothing touches `ReplyComposer`.
  - Out-of-scope items are untouched.
  - Every spec test bullet has a test. The one exception is "net quote": deviation 2 turns it into a tax-free landing test plus a `tax_on_top` skip test.
- **Placeholder scan.** No TBD/TODO. Every code step shows its code.
- **Type consistency.**
  - `stepFor()`, `Rounding(mode, step, unrounded, rounded, skipped)` and `written()` are used the same way everywhere.
  - `DiscountRounding::offer(limits, offer, asked, standing)`, `QuoteTotalRounding::of(write, reference, live, limits, buyersFigure)` and `QuoteTotalRounding::missed(rounding, landed)` match their callers.
  - `OfferWrite::absolute(value, factor)`, `OfferWrite::$discountFactor`, `DecisionRecorder::recordRounding(?Rounding)` and `TraceKind::Rounding` also match their callers.
  - The fixtures (`RoundingFixture::settings/roundingMeta/writtenDiscount/mixedGross/mixedGrossHolding/mixedGrossLanded/netQuote`) are created before their first use.
