# Plugin Config Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the merchant a per-sales-channel `config.xml` that populates the policy layer's own `NegotiationPolicy`, supplies their own LLM credentials, and carries a kill switch the servicing loop honours.

**Architecture:** A pure factory turns a flat array of raw config values into a validated `QuoteAgentSettings`, running the `Assert` constraints already declared on the policy objects. A thin reader pulls those raw values out of `SystemConfigService` for a sales channel. `ServicingPreflight` sits in `ServiceQuoteHandler::servicePass()` beside the terminal-state gate and answers one question — may this quote be serviced — returning the settings, or `null` after logging (disabled) or escalating (misconfigured).

**Tech Stack:** PHP 8.3, Shopware 6.7 (`dev-trunk`), SwagCommercial 7.13.1, Symfony 7.4 (Validator), PHPUnit 11, Mago (format/lint/analyze). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-28-plugin-config-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file.
- Mago analyze runs at full strictness. No `mixed` leaking, no unsafe casts. Narrow with `\is_string()` / `\is_int()` / `\is_float()` before use.
- Thresholds: cyclomatic complexity 10, nesting depth 4, **parameters 5**, ~400 lines/file. `ServiceQuoteHandler` ends this plan at exactly 5 constructor parameters — do not add a sixth.
- PSR-3 logger only. Never `echo`/`var_dump`/`print_r` in `src/`.
- `#[\Override]` on every interface/parent method implementation — house style.
- **Never reference a SwagCommercial class with `::class`.** ADR 0001. Nothing in this plan needs one.
- Branch: `feat/5-plugin-config`. Commit after every task.
- Unit tests: `composer run test`. Integration tests run only inside the shop container: `composer run test:integration`.
- Config domain, fixed: `MerchantQuoteAgentPlugin.config.` — Shopware derives it from the bundle name. Task 5 asserts this against the live shop rather than trusting it.
- Marker key, fixed: `merchant_quote_agent_escalated`. It joins the two that already exist, `merchant_quote_agent_serviced` and `merchant_quote_agent_attempts`.
- Default LLM base URL, fixed: `https://api.openai.com/v1`.

## Two refinements to the spec

Both are shape, not behaviour. Flagged here because the spec says otherwise.

1. **`QuoteAgentSettings` has no `enabled` field.** The factory returns `null` for a disabled channel and throws for a misconfigured one, so the object always means "enabled and valid" and `policy` is never nullable. Illegal states stop being representable, and `#18` never has to null-check a policy.
2. **`VolumeTierParser` returns `list<array{minQty: int, discountPercent: float}>`, not `list<VolumeTier>`.** `BundlePolicy` is built through `ArrayMapper::mapObject()`, which wants arrays; returning objects would mean converting them straight back.
3. **The reader is split in two.** The spec has `QuoteAgentSettingsReader` take `SystemConfigService` and `ValidatorInterface`. Here the validator and all the mapping live in a pure `QuoteAgentSettingsFactory`, and the reader only pulls raw values out of `SystemConfigService` and delegates. The interesting half is then unit-testable without a kernel, matching the grain of the policy layer it feeds. A one-method interface, `QuoteAgentSettingsSource`, is what `ServicingPreflight` depends on, so it can be doubled without constructing either.

---

### Task 1: Cascade validation into the policy graph

Symfony does not descend into a nested object unless the property carrying it is marked `#[Assert\Valid]`. No property in `Policy/Data/` is. So validating a `NegotiationPolicy` with a 150% discount cap reports **zero** violations today, and the whole-config-refusal rule would silently pass everything.

**Files:**
- Modify: `src/Policy/Data/NegotiationPolicy.php`
- Modify: `src/Policy/Data/QuoteLimits.php`
- Modify: `src/Policy/Data/PaymentPolicy.php`
- Modify: `src/Policy/Data/BundlePolicy.php`
- Test: `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `validate(new NegotiationPolicy(...))` returns violations whose `getPropertyPath()` reads `price.maxDiscountPercent`, `delivery.committedLeadTimeDaysMin`, `bundle.volumeTiers[0].discountPercent`, and so on. Task 4 turns those paths into the merchant-facing problem list.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy\Data;

use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteValueCeiling;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The Assert attributes on the policy objects have always been declared and
 * never run. Issue #5 runs them, and a nested constraint only fires when the
 * property holding the object is marked Assert\Valid.
 */
final class NegotiationPolicyValidationTest extends TestCase
{
    public function testAnOutOfRangePriceCapIsReportedAtItsNestedPath(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 150.0));

        self::assertSame(
            ['price.maxDiscountPercent'],
            self::paths(self::validator()->validate($policy)),
        );
    }

    public function testAnOutOfRangeVolumeTierIsReportedThroughTwoLevelsOfNesting(): void
    {
        $policy = new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 5.0),
            bundle: new BundlePolicy(volumeTiers: [new VolumeTier(minQty: 10, discountPercent: 150.0)]),
        );

        self::assertSame(
            ['bundle.volumeTiers[0].discountPercent'],
            self::paths(self::validator()->validate($policy)),
        );
    }

    public function testANegativeLeadTimeAndABadCurrencyAreBothReported(): void
    {
        $policy = new NegotiationPolicy(
            price: new QuoteLimits(
                maxDiscountPercent: 5.0,
                valueCeiling: new QuoteValueCeiling(net: 100.0, currencyIso: 'NOPE'),
            ),
            delivery: new DeliveryPolicy(committedLeadTimeDaysMin: -1),
        );

        self::assertSame(
            ['price.valueCeiling.currencyIso', 'delivery.committedLeadTimeDaysMin'],
            self::paths(self::validator()->validate($policy)),
        );
    }

    public function testAValidPolicyReportsNothing(): void
    {
        $policy = new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 5.0),
            delivery: new DeliveryPolicy(committedLeadTimeDaysMin: 3),
            bundle: new BundlePolicy(volumeTiers: [new VolumeTier(minQty: 10, discountPercent: 7.5)]),
        );

        self::assertSame([], self::paths(self::validator()->validate($policy)));
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @param \Symfony\Component\Validator\ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations
     *
     * @return list<string>
     */
    private static function paths(iterable $violations): array
    {
        $paths = [];

        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter NegotiationPolicyValidationTest`

Expected: the first three tests FAIL, each asserting `[] === ['price.maxDiscountPercent']` or similar. Nothing cascades yet. The fourth test passes vacuously — that is fine, it is the regression guard.

- [ ] **Step 3: Add the cascade attributes**

In `src/Policy/Data/NegotiationPolicy.php`, add the import and mark all four properties:

```php
use Symfony\Component\Validator\Constraints as Assert;
```

```php
    public function __construct(
        #[Assert\Valid]
        public QuoteLimits $price,
        #[Assert\Valid]
        public ?DeliveryPolicy $delivery = null,
        #[Assert\Valid]
        public ?PaymentPolicy $payment = null,
        #[Assert\Valid]
        public ?BundlePolicy $bundle = null,
    ) {}
```

In `src/Policy/Data/QuoteLimits.php`, mark the ceiling:

```php
        #[Assert\Valid]
        public ?QuoteValueCeiling $valueCeiling = null,
```

In `src/Policy/Data/PaymentPolicy.php`, mark the term list:

```php
    /** @param list<PaymentTerm> $allowedTerms */
    public function __construct(
        #[Assert\Valid]
        public array $allowedTerms = [],
```

In `src/Policy/Data/BundlePolicy.php`, add the import and mark the tiers:

```php
use Symfony\Component\Validator\Constraints as Assert;
```

```php
    /** @param list<VolumeTier> $volumeTiers */
    public function __construct(
        #[Assert\Valid]
        public array $volumeTiers = [],
    ) {}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit --filter NegotiationPolicyValidationTest`

Expected: PASS, 4 tests.

- [ ] **Step 5: Run the whole unit suite — these are shared objects**

Run: `php vendor/bin/phpunit`

Expected: PASS. `Assert\Valid` changes no runtime behaviour outside a validator call, so every existing test must be untouched. If anything fails, the attribute went on the wrong property.

- [ ] **Step 6: Commit**

```bash
git add src/Policy/Data tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php
git commit -m "fix: cascade validation into the nested policy objects"
```

---

### Task 2: The volume-tier parser

The one place the plugin parses free text a merchant typed, and therefore the one place a parse can fail. A malformed line is a failure with a line number, never a skipped line — a silently dropped tier changes the discount ladder without telling anyone.

**Files:**
- Create: `src/Config/VolumeTierParser.php`
- Test: `tests/Unit/Config/VolumeTierParserTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `VolumeTierParser::parse(string $text): array` returning `list<array{minQty: int, discountPercent: float}>`, throwing `\UnexpectedValueException` whose message names the 1-based line number. Task 4 catches that exception and folds its message into the problem list.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/VolumeTierParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\VolumeTierParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VolumeTierParserTest extends TestCase
{
    public function testItParsesOneTierPerLine(): void
    {
        self::assertSame(
            [
                ['minQty' => 10, 'discountPercent' => 5.0],
                ['minQty' => 50, 'discountPercent' => 7.5],
            ],
            VolumeTierParser::parse("10:5\n50:7.5"),
        );
    }

    public function testBlankLinesAndSurroundingWhitespaceAreIgnored(): void
    {
        self::assertSame(
            [['minQty' => 10, 'discountPercent' => 5.0]],
            VolumeTierParser::parse("\n  10 : 5  \n\n"),
        );
    }

    public function testAnEmptyTextareaIsAnEmptyLadderRatherThanAFailure(): void
    {
        self::assertSame([], VolumeTierParser::parse('   '));
    }

    public function testWindowsLineEndingsParse(): void
    {
        self::assertSame(
            [['minQty' => 10, 'discountPercent' => 5.0]],
            VolumeTierParser::parse("10:5\r\n"),
        );
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function malformed(): iterable
    {
        yield 'no separator' => ["10\n", 1];
        yield 'non-numeric quantity' => ["abc:5\n", 1];
        yield 'non-numeric percent' => ["10:abc\n", 1];
        yield 'fractional quantity' => ["10.5:5\n", 1];
        yield 'too many parts' => ["10:5:7\n", 1];
        yield 'reported on the offending line, not the first' => ["10:5\n50:7.5\nbroken\n", 3];
    }

    #[DataProvider('malformed')]
    public function testAMalformedLineFailsAndNamesItsLineNumber(string $text, int $line): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('line %d', $line));

        VolumeTierParser::parse($text);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter VolumeTierParserTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Config\VolumeTierParser" not found`.

- [ ] **Step 3: Write the parser**

Create `src/Config/VolumeTierParser.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * `minQty:discountPercent`, one per line. A textarea because `config.xml`
 * cannot express a repeatable field.
 *
 * A line that does not parse is a failure, never a skipped line: a silently
 * dropped tier changes the discount ladder while looking like it applied,
 * which is the class of silent behaviour change issue #5 exists to remove.
 * The message carries the 1-based line number because "invalid volume tiers"
 * is not something a merchant can act on.
 */
final class VolumeTierParser
{
    private function __construct() {}

    /**
     * @return list<array{minQty: int, discountPercent: float}>
     *
     * @throws \UnexpectedValueException when any non-blank line is not `<int>:<number>`
     */
    public static function parse(string $text): array
    {
        $tiers = [];

        foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $tiers[] = self::tier($trimmed, $index + 1);
        }

        return $tiers;
    }

    /**
     * @return array{minQty: int, discountPercent: float}
     *
     * @throws \UnexpectedValueException
     */
    private static function tier(string $line, int $lineNumber): array
    {
        $parts = array_map(trim(...), explode(':', $line));

        if (\count($parts) !== 2 || !self::isInteger($parts[0]) || !is_numeric($parts[1])) {
            throw new \UnexpectedValueException(sprintf(
                'Volume tiers, line %d: expected "minQty:discountPercent" such as "10:5", got "%s".',
                $lineNumber,
                $line,
            ));
        }

        return ['minQty' => (int) $parts[0], 'discountPercent' => (float) $parts[1]];
    }

    private static function isInteger(string $value): bool
    {
        return $value !== '' && ctype_digit(ltrim($value, '-'));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit --filter VolumeTierParserTest`

Expected: PASS, 10 tests.

- [ ] **Step 5: Lint and commit**

```bash
vendor/bin/mago fmt src/Config tests/Unit/Config
vendor/bin/mago lint src/Config/VolumeTierParser.php tests/Unit/Config/VolumeTierParserTest.php
git add src/Config tests/Unit/Config
git commit -m "feat: parse the volume-tier textarea, failing loudly on a bad line"
```

---

### Task 3: The settings value objects

Plain PHP with no Shopware in it, so #18 consumes them without acquiring a framework dependency.

**Files:**
- Create: `src/Config/ModelAccess.php`
- Create: `src/Config/QuoteAgentSettings.php`
- Create: `src/Config/InvalidQuoteAgentConfiguration.php`
- Test: `tests/Unit/Config/QuoteAgentSettingsTest.php`

**Interfaces:**
- Consumes: `Policy\Data\NegotiationPolicy` from #2.
- Produces:
  - `new ModelAccess(string $apiKey, string $baseUrl)`, properties `$apiKey`, `$baseUrl`.
  - `new QuoteAgentSettings(NegotiationPolicy $policy, bool $rulesOnly, ?ModelAccess $llm, ?string $strategyPrompt)`.
  - `new InvalidQuoteAgentConfiguration(list<string> $problems)` with `$problems` readable and folded into `getMessage()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/QuoteAgentSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\TestCase;

final class QuoteAgentSettingsTest extends TestCase
{
    public function testSettingsCarryThePolicyAndTheModelAccess(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0));
        $llm = new ModelAccess('sk-test', 'https://api.openai.com/v1');

        $settings = new QuoteAgentSettings($policy, rulesOnly: false, llm: $llm, strategyPrompt: 'concede slowly');

        self::assertSame($policy, $settings->policy);
        self::assertSame($llm, $settings->llm);
        self::assertFalse($settings->rulesOnly);
        self::assertSame('concede slowly', $settings->strategyPrompt);
    }

    public function testRulesOnlySettingsMayCarryNoModelAccessAtAll(): void
    {
        $settings = new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            rulesOnly: true,
            llm: null,
            strategyPrompt: null,
        );

        self::assertTrue($settings->rulesOnly);
        self::assertNull($settings->llm);
    }

    public function testTheExceptionKeepsEveryProblemAndListsThemInItsMessage(): void
    {
        $exception = new InvalidQuoteAgentConfiguration(['first problem', 'second problem']);

        self::assertSame(['first problem', 'second problem'], $exception->problems);
        self::assertStringContainsString('first problem', $exception->getMessage());
        self::assertStringContainsString('second problem', $exception->getMessage());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter QuoteAgentSettingsTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Config\ModelAccess" not found`.

- [ ] **Step 3: Write the three classes**

Create `src/Config/ModelAccess.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * The merchant's own LLM credentials. Theirs, not ours: per-tenant model cost
 * stops being the plugin's problem, and the base URL is what lets them point
 * at Azure, their own gateway or a self-hosted model rather than the default.
 */
final readonly class ModelAccess
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
    ) {}
}
```

Create `src/Config/QuoteAgentSettings.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * Everything the agent needs to service one sales channel, already validated.
 *
 * There is deliberately no `enabled` flag. QuoteAgentSettingsFactory returns
 * null for a disabled channel and throws for a misconfigured one, so an
 * instance of this class always means "enabled and valid" and `$policy` is
 * never nullable — #18 cannot be handed settings it must re-check.
 *
 * No Shopware in it. The reader touches SystemConfigService; this does not,
 * so the negotiation engine stays framework-free.
 */
final readonly class QuoteAgentSettings
{
    public function __construct(
        public NegotiationPolicy $policy,
        public bool $rulesOnly,
        public ?ModelAccess $llm,
        public ?string $strategyPrompt,
    ) {}
}
```

Create `src/Config/InvalidQuoteAgentConfiguration.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Every reason the configuration is unusable, collected rather than
 * short-circuited: a merchant fixing one field at a time and re-saving is a
 * worse experience than being told all of it at once.
 */
final class InvalidQuoteAgentConfiguration extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct('Quote agent configuration is invalid: ' . implode('; ', $problems));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit --filter QuoteAgentSettingsTest`

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago fmt src/Config tests/Unit/Config
git add src/Config tests/Unit/Config
git commit -m "feat: settings value objects for the quote agent configuration"
```

---

### Task 4: The settings factory

The whole of the interesting logic, and pure — no Shopware, so it is unit-testable end to end. Flat raw values in, validated `QuoteAgentSettings` out, `null` when disabled, `InvalidQuoteAgentConfiguration` when not.

**Files:**
- Create: `src/Config/QuoteAgentSettingsFactory.php`
- Test: `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`

**Interfaces:**
- Consumes: `VolumeTierParser::parse()` (Task 2), `QuoteAgentSettings` / `ModelAccess` / `InvalidQuoteAgentConfiguration` (Task 3), the cascade from Task 1.
- Produces: `new QuoteAgentSettingsFactory(ValidatorInterface $validator)` and `fromValues(array $raw): ?QuoteAgentSettings`. `$raw` is keyed by the short config names — `enabled`, `rulesOnlyMode`, `llmApiKey`, `llmBaseUrl`, `negotiationStrategy`, `maxDiscountPercent`, `counterOfferMaxPercent`, `maxQuoteValueNet`, `maxQuoteValueCurrency`, `validityDays`, `replyTone`, `deliveryFreeShippingAboveNet`, `deliveryMaxShippingWaiverNet`, `deliveryExpeditedAllowed`, `deliveryCommittedLeadTimeDaysMin`, `paymentAllowedTerms`, `paymentMaxNetDays`, `paymentMinDepositPercent`, `bundleVolumeTiers`. Task 5's reader produces exactly that array.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class QuoteAgentSettingsFactoryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function validRaw(): array
    {
        return [
            'enabled' => true,
            'rulesOnlyMode' => false,
            'llmApiKey' => 'sk-test',
            'llmBaseUrl' => 'https://api.openai.com/v1',
            'negotiationStrategy' => 'open at 2%',
            'maxDiscountPercent' => 12.0,
            'counterOfferMaxPercent' => 18.0,
            'maxQuoteValueNet' => 50000.0,
            'maxQuoteValueCurrency' => 'EUR',
            'validityDays' => 14,
            'replyTone' => 'formal',
            'deliveryFreeShippingAboveNet' => 500.0,
            'deliveryMaxShippingWaiverNet' => 80.0,
            'deliveryExpeditedAllowed' => true,
            'deliveryCommittedLeadTimeDaysMin' => 3,
            'paymentAllowedTerms' => ['net_30', 'net_60'],
            'paymentMaxNetDays' => 60,
            'paymentMinDepositPercent' => 10.0,
            'bundleVolumeTiers' => "10:5\n50:7.5",
        ];
    }

    private static function factory(): QuoteAgentSettingsFactory
    {
        return new QuoteAgentSettingsFactory(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private static function build(array $overrides = []): mixed
    {
        return self::factory()->fromValues([...self::validRaw(), ...$overrides]);
    }

    public function testAFullConfigurationMapsOntoTheWholePolicyGraph(): void
    {
        $settings = self::build();

        self::assertNotNull($settings);
        self::assertSame(12.0, $settings->policy->price->maxDiscountPercent);
        self::assertSame(18.0, $settings->policy->price->counterOfferMaxPercent);
        self::assertSame(50000.0, $settings->policy->price->valueCeiling?->net);
        self::assertSame('EUR', $settings->policy->price->valueCeiling?->currencyIso);
        self::assertSame(14, $settings->policy->price->validityDays);
        self::assertSame('formal', $settings->policy->price->replyTone);
        self::assertSame(500.0, $settings->policy->delivery?->freeShippingAboveNet);
        self::assertSame(3, $settings->policy->delivery?->committedLeadTimeDaysMin);
        self::assertSame(60, $settings->policy->payment?->maxNetDays);
        self::assertCount(2, $settings->policy->payment?->allowedTerms ?? []);
        self::assertCount(2, $settings->policy->bundle?->volumeTiers ?? []);
        self::assertSame(50, $settings->policy->bundle?->volumeTiers[1]->minQty);
        self::assertSame('sk-test', $settings->llm?->apiKey);
        self::assertSame('open at 2%', $settings->strategyPrompt);
    }

    public function testADisabledChannelReturnsNullAndIsNeverValidated(): void
    {
        // Every other field is garbage. A paused agent does not complain about
        // its own configuration.
        self::assertNull(self::build([
            'enabled' => false,
            'maxDiscountPercent' => 500.0,
            'llmApiKey' => '',
            'bundleVolumeTiers' => 'nonsense',
        ]));
    }

    public function testABlankSubPolicySectionBecomesNullRatherThanAnAllNullObject(): void
    {
        $settings = self::build([
            'deliveryFreeShippingAboveNet' => null,
            'deliveryMaxShippingWaiverNet' => null,
            'deliveryExpeditedAllowed' => false,
            'deliveryCommittedLeadTimeDaysMin' => null,
            'paymentAllowedTerms' => [],
            'paymentMaxNetDays' => null,
            'paymentMinDepositPercent' => null,
            'bundleVolumeTiers' => '',
        ]);

        self::assertNotNull($settings);
        self::assertNull($settings->policy->delivery, 'A blank delivery section must escalate as "no delivery policy configured".');
        self::assertNull($settings->policy->payment);
        self::assertNull($settings->policy->bundle);
    }

    public function testAnUntouchedInstallEscalatesEverythingRatherThanFailing(): void
    {
        $settings = self::build([
            'maxDiscountPercent' => null,
            'counterOfferMaxPercent' => null,
            'maxQuoteValueNet' => null,
            'maxQuoteValueCurrency' => null,
            'validityDays' => null,
            'replyTone' => null,
        ]);

        self::assertNotNull($settings);
        self::assertSame(0.0, $settings->policy->price->maxDiscountPercent);
        self::assertNull($settings->policy->price->valueCeiling);
        self::assertSame(0, $settings->policy->price->validityDays);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'discount cap over 100' => [['maxDiscountPercent' => 150.0], 'price.maxDiscountPercent'];
        yield 'negative lead time' => [['deliveryCommittedLeadTimeDaysMin' => -1], 'delivery.committedLeadTimeDaysMin'];
        yield 'bad ceiling currency' => [['maxQuoteValueCurrency' => 'NOPE'], 'price.valueCeiling.currencyIso'];
        yield 'tier percent over 100' => [['bundleVolumeTiers' => '10:150'], 'bundle.volumeTiers[0].discountPercent'];
        yield 'malformed tier line' => [['bundleVolumeTiers' => "10:5\nbroken"], 'line 2'];
        yield 'unknown payment term' => [['paymentAllowedTerms' => ['net_45']], 'payment'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefusedWholeAndNamesTheField(array $overrides, string $expected): void
    {
        try {
            self::build($overrides);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        try {
            self::build(['maxDiscountPercent' => 150.0, 'deliveryCommittedLeadTimeDaysMin' => -1]);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertCount(2, $e->problems);
        }
    }

    public function testAnEnabledChannelWithoutAKeyIsAMisconfiguration(): void
    {
        try {
            self::build(['llmApiKey' => '   ']);
            self::fail('An empty API key was accepted, which is the silent fallback #5 removes.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString('API key', $e->getMessage());
        }
    }

    public function testRulesOnlyModeIsTheOneStateThatNeedsNoKey(): void
    {
        $settings = self::build(['llmApiKey' => '', 'rulesOnlyMode' => true]);

        self::assertNotNull($settings);
        self::assertTrue($settings->rulesOnly);
        self::assertNull($settings->llm, 'Rules-only carries no model access, so nothing downstream can call a model by accident.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter QuoteAgentSettingsFactoryTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory" not found`.

- [ ] **Step 3: Write the factory**

Create `src/Config/QuoteAgentSettingsFactory.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Raw config values to validated settings. Pure — no Shopware — so the whole
 * of the mapping and every refusal is unit-testable without a kernel.
 *
 * Returns null for a disabled channel and throws for a misconfigured one, so
 * a QuoteAgentSettings instance always means "enabled and valid".
 */
final readonly class QuoteAgentSettingsFactory
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private ValidatorInterface $validator,
    ) {}

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidQuoteAgentConfiguration
     */
    public function fromValues(array $raw): ?QuoteAgentSettings
    {
        // Read first, validate never: a paused agent is silent about
        // everything, including its own bad configuration.
        if (self::bool($raw, 'enabled') !== true) {
            return null;
        }

        $problems = [];
        $tiers = [];

        try {
            $tiers = VolumeTierParser::parse(self::string($raw, 'bundleVolumeTiers') ?? '');
        } catch (\UnexpectedValueException $e) {
            $problems[] = $e->getMessage();
        }

        $policy = null;

        try {
            $policy = NegotiationPolicy::fromArray(self::policyArray($raw, $tiers));
        } catch (MappingError | \TypeError | \ValueError $e) {
            // Valinor maps the sub-policies; an unknown PaymentTerm lands here
            // rather than as a constraint violation.
            $problems[] = $e->getMessage();
        }

        if ($policy !== null) {
            foreach ($this->validator->validate($policy) as $violation) {
                $problems[] = $violation->getPropertyPath() . ': ' . (string) $violation->getMessage();
            }
        }

        $rulesOnly = self::bool($raw, 'rulesOnlyMode') === true;
        $apiKey = trim(self::string($raw, 'llmApiKey') ?? '');

        if (!$rulesOnly && $apiKey === '') {
            $problems[] = 'No LLM API key is set. Supply one, or switch on rules-only mode to '
                . 'decide deterministically without a model.';
        }

        if ($policy === null || $problems !== []) {
            throw new InvalidQuoteAgentConfiguration(array_values($problems));
        }

        return new QuoteAgentSettings(
            policy: $policy,
            rulesOnly: $rulesOnly,
            llm: $apiKey === '' ? null : new ModelAccess($apiKey, self::baseUrl($raw)),
            strategyPrompt: self::string($raw, 'negotiationStrategy'),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<array{minQty: int, discountPercent: float}> $tiers
     *
     * @return array<string, mixed>
     */
    private static function policyArray(array $raw, array $tiers): array
    {
        $ceilingNet = self::float($raw, 'maxQuoteValueNet');

        $price = [
            // Null means the merchant cleared the field. Zero is the safe
            // reading: every price ask escalates.
            'maxDiscountPercent' => self::float($raw, 'maxDiscountPercent') ?? 0.0,
            'counterOfferMaxPercent' => self::float($raw, 'counterOfferMaxPercent'),
            'validityDays' => self::int($raw, 'validityDays') ?? 0,
            'replyTone' => self::string($raw, 'replyTone'),
        ];

        if ($ceilingNet !== null) {
            $price['maxQuoteValueNet'] = $ceilingNet;
            $price['maxQuoteValueCurrency'] = self::string($raw, 'maxQuoteValueCurrency');
        }

        return array_filter([
            'price' => $price,
            'delivery' => self::section([
                'freeShippingAboveNet' => self::float($raw, 'deliveryFreeShippingAboveNet'),
                'maxShippingWaiverNet' => self::float($raw, 'deliveryMaxShippingWaiverNet'),
                'expeditedAllowed' => self::bool($raw, 'deliveryExpeditedAllowed') === true ? true : null,
                'committedLeadTimeDaysMin' => self::int($raw, 'deliveryCommittedLeadTimeDaysMin'),
            ]),
            'payment' => self::section([
                'allowedTerms' => self::terms($raw),
                'maxNetDays' => self::int($raw, 'paymentMaxNetDays'),
                'minDepositPercent' => self::float($raw, 'paymentMinDepositPercent'),
            ]),
            'bundle' => self::section(['volumeTiers' => $tiers === [] ? null : $tiers]),
        ], static fn(mixed $section): bool => $section !== null);
    }

    /**
     * A sub-policy is emitted only when the merchant set at least one of its
     * fields. Blank means null, and null is what makes DeliveryDecider answer
     * "no delivery policy configured" rather than a subtly different per-field
     * reason.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>|null
     */
    private static function section(array $fields): ?array
    {
        $set = array_filter($fields, static fn(mixed $value): bool => $value !== null);

        return $set === [] ? null : $set;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>|null
     */
    private static function terms(array $raw): ?array
    {
        $terms = $raw['paymentAllowedTerms'] ?? null;

        if (!\is_array($terms)) {
            return null;
        }

        $strings = array_values(array_filter($terms, \is_string(...)));

        return $strings === [] ? null : $strings;
    }

    /** @param array<string, mixed> $raw */
    private static function baseUrl(array $raw): string
    {
        $url = trim(self::string($raw, 'llmBaseUrl') ?? '');

        return $url === '' ? self::DEFAULT_BASE_URL : $url;
    }

    /** @param array<string, mixed> $raw */
    private static function bool(array $raw, string $key): ?bool
    {
        $value = $raw[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    private static function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    private static function float(array $raw, string $key): ?float
    {
        $value = $raw[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $raw */
    private static function int(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        return \is_int($value) ? $value : null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit --filter QuoteAgentSettingsFactoryTest`

Expected: PASS, 13 tests.

If `unknown payment term` fails because Valinor's message does not contain the string `payment`, adjust the expectation in the data provider to a substring the real message does contain — run the single case with `--filter 'unknown payment term'` and read the message. Do not weaken the assertion to something that would pass on any message.

- [ ] **Step 5: Check file length and complexity**

Run: `php scripts/check_file_length.php src && vendor/bin/mago lint src/Config`

Expected: no offenders. If `policyArray()` trips cyclomatic complexity, extract the `price` array into its own private method rather than raising the threshold.

- [ ] **Step 6: Commit**

```bash
vendor/bin/mago fmt src/Config tests/Unit/Config
git add src/Config tests/Unit/Config
git commit -m "feat: assemble validated agent settings from raw config values"
```

---

### Task 5: config.xml and the reader

The admin surface, and the thin adapter from `SystemConfigService` to Task 4's raw array.

**Files:**
- Create: `src/Resources/config/config.xml`
- Create: `src/Config/QuoteAgentSettingsReader.php`
- Test: `tests/Integration/PluginConfigTest.php`

**Interfaces:**
- Consumes: `QuoteAgentSettingsFactory::fromValues()` (Task 4).
- Produces: `new QuoteAgentSettingsReader(SystemConfigService $config, QuoteAgentSettingsFactory $factory)` and `forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings`, throwing `InvalidQuoteAgentConfiguration`. Task 7 calls it.

- [ ] **Step 1: Write the config.xml**

Create `src/Resources/config/config.xml`. Every net field says "(net)" in its label — the policy layer is net throughout, and leaving that implicit is how a ceiling gets compared against the wrong number.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/master/src/Core/System/SystemConfig/Schema/config.xsd">

    <card>
        <title>Quote agent</title>
        <input-field type="bool">
            <name>enabled</name>
            <label>Enable the quote agent</label>
            <defaultValue>false</defaultValue>
            <helpText>Off by default. While off the agent is completely silent: it queues nothing and writes nothing.</helpText>
        </input-field>
        <input-field type="bool">
            <name>rulesOnlyMode</name>
            <label>Rules-only mode (no model call)</label>
            <defaultValue>false</defaultValue>
            <helpText>Decide grant / counter / escalate deterministically without calling a model. An explicit choice, never a fallback on error.</helpText>
        </input-field>
    </card>

    <card>
        <title>Model access</title>
        <input-field type="password">
            <name>llmApiKey</name>
            <label>LLM API key</label>
            <helpText>Your own key. Stored in system_config, obscured here but NOT encrypted at rest. Required unless rules-only mode is on.</helpText>
        </input-field>
        <input-field type="text">
            <name>llmBaseUrl</name>
            <label>LLM base URL</label>
            <defaultValue>https://api.openai.com/v1</defaultValue>
            <helpText>Point at Azure, your own gateway or a self-hosted model.</helpText>
        </input-field>
        <input-field type="textarea">
            <name>negotiationStrategy</name>
            <label>Negotiation strategy</label>
            <helpText>Tone and commercial posture, e.g. "open at 2%, concede in 1% steps, never lead with the maximum". Appended to the base prompt. It can never move a cap — the bands below are the guardrail.</helpText>
        </input-field>
    </card>

    <card>
        <title>Price bands</title>
        <input-field type="float">
            <name>maxDiscountPercent</name>
            <label>Maximum discount (%)</label>
            <defaultValue>0</defaultValue>
            <helpText>0 means every price ask escalates to a human.</helpText>
        </input-field>
        <input-field type="float">
            <name>counterOfferMaxPercent</name>
            <label>Counter-offer ceiling (%)</label>
            <helpText>An ask above the maximum but at or below this gets a deterministic counter at the maximum. Blank means no counter band.</helpText>
        </input-field>
        <input-field type="float">
            <name>maxQuoteValueNet</name>
            <label>Maximum quote value for auto-reply (net)</label>
            <helpText>Above this the quote always escalates. Blank means no ceiling.</helpText>
        </input-field>
        <input-field type="text">
            <name>maxQuoteValueCurrency</name>
            <label>Currency of that ceiling (ISO 4217, e.g. EUR)</label>
            <helpText>Blank means the ceiling is compared without a currency check.</helpText>
        </input-field>
        <input-field type="int">
            <name>validityDays</name>
            <label>Offer validity (days)</label>
            <defaultValue>0</defaultValue>
        </input-field>
        <input-field type="text">
            <name>replyTone</name>
            <label>Reply tone</label>
        </input-field>
    </card>

    <card>
        <title>Delivery — blank escalates</title>
        <input-field type="float">
            <name>deliveryFreeShippingAboveNet</name>
            <label>Grant free shipping above (net)</label>
        </input-field>
        <input-field type="float">
            <name>deliveryMaxShippingWaiverNet</name>
            <label>Maximum shipping waiver (net)</label>
        </input-field>
        <input-field type="bool">
            <name>deliveryExpeditedAllowed</name>
            <label>Expedited shipping may be granted</label>
            <defaultValue>false</defaultValue>
        </input-field>
        <input-field type="int">
            <name>deliveryCommittedLeadTimeDaysMin</name>
            <label>Shortest lead time the agent may commit to (days)</label>
        </input-field>
    </card>

    <card>
        <title>Payment — blank escalates</title>
        <input-field type="multi-select">
            <name>paymentAllowedTerms</name>
            <label>Payment terms the agent may grant</label>
            <options>
                <option><id>prepaid</id><name>Prepaid</name></option>
                <option><id>net_15</id><name>Net 15</name></option>
                <option><id>net_30</id><name>Net 30</name></option>
                <option><id>net_60</id><name>Net 60</name></option>
                <option><id>net_90</id><name>Net 90</name></option>
            </options>
        </input-field>
        <input-field type="int">
            <name>paymentMaxNetDays</name>
            <label>Maximum net days</label>
        </input-field>
        <input-field type="float">
            <name>paymentMinDepositPercent</name>
            <label>Minimum deposit (%)</label>
        </input-field>
    </card>

    <card>
        <title>Volume tiers — blank escalates</title>
        <input-field type="textarea">
            <name>bundleVolumeTiers</name>
            <label>Volume tiers, one per line</label>
            <helpText>Format minQty:discountPercent, e.g. "10:5" then "50:7.5" on the next line. A line that does not parse makes the whole configuration invalid — the agent escalates rather than applying half a ladder.</helpText>
        </input-field>
    </card>

</config>
```

- [ ] **Step 2: Write the reader**

Create `src/Config/QuoteAgentSettingsReader.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The one place that touches Shopware's configuration store. Everything it
 * knows is which keys exist; the meaning of the values is the factory's.
 *
 * Values come through `get()`, never `getFloat()` / `getInt()`, because those
 * coerce an unset value to 0 — and "unset" is load-bearing here: a blank
 * sub-policy field must stay null so the dimension escalates.
 *
 * A null sales-channel id reads the global value, and a sales-channel id falls
 * back to it. That is Shopware's own semantics and the reason a merchant can
 * configure once and override for a pilot channel.
 */
final readonly class QuoteAgentSettingsReader
{
    public const DOMAIN = 'MerchantQuoteAgentPlugin.config.';

    private const KEYS = [
        'enabled',
        'rulesOnlyMode',
        'llmApiKey',
        'llmBaseUrl',
        'negotiationStrategy',
        'maxDiscountPercent',
        'counterOfferMaxPercent',
        'maxQuoteValueNet',
        'maxQuoteValueCurrency',
        'validityDays',
        'replyTone',
        'deliveryFreeShippingAboveNet',
        'deliveryMaxShippingWaiverNet',
        'deliveryExpeditedAllowed',
        'deliveryCommittedLeadTimeDaysMin',
        'paymentAllowedTerms',
        'paymentMaxNetDays',
        'paymentMinDepositPercent',
        'bundleVolumeTiers',
    ];

    public function __construct(
        private SystemConfigService $config,
        private QuoteAgentSettingsFactory $factory,
    ) {}

    /** @throws InvalidQuoteAgentConfiguration */
    public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
    {
        $raw = [];

        foreach (self::KEYS as $key) {
            $raw[$key] = $this->config->get(self::DOMAIN . $key, $salesChannelId);
        }

        return $this->factory->fromValues($raw);
    }
}
```

- [ ] **Step 3: Register both in the container**

In `src/Resources/config/services.php`, add the imports:

```php
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
```

and register them inside the `CommercialAvailability::isAvailableByClass()` guard, next to the servicing block:

```php
    // Configuration (issue #5). Autowired: the factory takes ValidatorInterface,
    // which Shopware aliases to HappyPathValidator — harmless, because a
    // validate() call with no explicit constraints delegates straight to the
    // real Symfony validator.
    $services->set(QuoteAgentSettingsFactory::class);
    $services->set(QuoteAgentSettingsReader::class);
```

- [ ] **Step 4: Write the integration test**

Create `tests/Integration/PluginConfigTest.php`. The first test is the one that earns its place: a malformed `config.xml` fails **silently** — the plugin simply has no settings, and every unit test still passes.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class PluginConfigTest extends IntegrationTestCase
{
    public function testEveryConfiguredKeyIsReachableThroughSystemConfig(): void
    {
        $config = self::systemConfig();

        // Writing then reading proves the key round-trips under the domain the
        // reader uses. A typo in config.xml or in the domain shows up here and
        // nowhere else — a broken config.xml is silent, not an error.
        foreach (['enabled' => true, 'maxDiscountPercent' => 12.0, 'llmApiKey' => 'sk-probe'] as $key => $value) {
            $config->set(QuoteAgentSettingsReader::DOMAIN . $key, $value);
            self::assertSame($value, $config->get(QuoteAgentSettingsReader::DOMAIN . $key));
        }
    }

    public function testTheDefaultBaseUrlIsShippedByConfigXmlRatherThanOnlyByTheFactory(): void
    {
        self::assertSame(
            'https://api.openai.com/v1',
            self::systemConfig()->get(QuoteAgentSettingsReader::DOMAIN . 'llmBaseUrl'),
        );
    }

    public function testASalesChannelValueOverridesTheGlobalOne(): void
    {
        $config = self::systemConfig();
        $salesChannelId = self::anySalesChannelId();
        $key = QuoteAgentSettingsReader::DOMAIN . 'maxDiscountPercent';

        $config->set($key, 5.0);
        $config->set($key, 25.0, $salesChannelId);

        self::assertSame(5.0, $config->get($key));
        self::assertSame(25.0, $config->get($key, $salesChannelId));
    }

    public function testTheReaderResolvesFromTheContainerAndHonoursTheKillSwitch(): void
    {
        $reader = static::getContainer()->get(QuoteAgentSettingsReader::class);
        self::assertInstanceOf(QuoteAgentSettingsReader::class, $reader);

        self::systemConfig()->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', false);

        self::assertNull($reader->forSalesChannel(null), 'A disabled channel must read as no settings at all.');
    }

    public function testAnEnabledChannelWithoutAKeyThrowsRatherThanFallingBackQuietly(): void
    {
        $reader = static::getContainer()->get(QuoteAgentSettingsReader::class);
        self::assertInstanceOf(QuoteAgentSettingsReader::class, $reader);

        $config = self::systemConfig();
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'rulesOnlyMode', false);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        $this->expectException(\MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration::class);

        $reader->forSalesChannel(null);
    }

    private static function systemConfig(): SystemConfigService
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
    }

    private static function anySalesChannelId(): string
    {
        $repository = static::getContainer()->get('sales_channel.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = $repository->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($id, 'The shop has no sales channel.');

        return $id;
    }
}
```

- [ ] **Step 5: Reinstall the plugin so Shopware reads the new config.xml**

`config.xml` defaults are written into `system_config` at plugin install/update, so a freshly added file needs the plugin refreshed:

```bash
docker exec merchant-quote-shop bash -lc 'cd /var/www/html && php8.3 bin/console plugin:refresh && php8.3 bin/console plugin:update MerchantQuoteAgentPlugin && php8.3 bin/console cache:clear'
```

If `plugin:update` reports nothing to do, run `plugin:deactivate` then `plugin:activate` for the same plugin.

- [ ] **Step 6: Run the integration test**

Run: `composer run test:integration -- --filter PluginConfigTest`

Expected: PASS, 5 tests. A failure on the first test means `config.xml` did not load — check the XML against the XSD before changing anything else, because nothing else will report it.

- [ ] **Step 7: Commit**

```bash
vendor/bin/mago fmt src tests
git add src/Resources/config src/Config tests/Integration/PluginConfigTest.php
git commit -m "feat: per-sales-channel config.xml and the settings reader"
```

---

### Task 6: The escalator

Nothing in the plugin can currently write an escalation to a quote. `QuoteDecision::escalate()` produces a decision; the actuator is #18's. #5 needs a fraction of it, so it builds that fraction properly rather than inlining a comment the next issue has to reconcile.

**Files:**
- Modify: `src/Policy/Data/QuoteEscalationReason.php`
- Create: `src/Servicing/QuoteEscalator.php`
- Test: `tests/Unit/Servicing/QuoteEscalatorTest.php`

**Interfaces:**
- Consumes: `QuoteGatewayInterface::addComment()` / `updateQuote()` from #3, `FakeQuoteGateway` from #4's test suite.
- Produces: `QuoteEscalator::MARKER_KEY` (`'merchant_quote_agent_escalated'`) and `escalate(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, QuoteEscalationReason $reason, string $detail): void`. Tasks 7 and 8 use both.

- [ ] **Step 1: Teach the fake gateway to record comments**

`FakeQuoteGateway::addComment()` is currently an empty method that records
nothing, so an assertion on `$gateway->calls` would never see it. In
`tests/Unit/Servicing/FakeQuoteGateway.php`, replace it with:

```php
    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $this->calls[] = 'addComment';
        $this->comments[] = $comment;
    }
```

and add the property beside the existing `$calls`:

```php
    /** @var list<string> */
    public array $comments = [];
```

Run `php vendor/bin/phpunit` — expected PASS, unchanged. Recording is additive;
no existing test asserts on the absence of an `addComment` entry.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Servicing/QuoteEscalatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

final class QuoteEscalatorTest extends TestCase
{
    public function testItWritesOneCommentAndMarksTheQuote(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
            'No LLM API key is set.',
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    public function testItSkipsAQuoteAlreadyMarkedWithTheSameReason(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $marked = QuoteSnapshotFixture::snapshot(customFields: [
            QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value,
        ]);

        (new QuoteEscalator())->escalate($gateway, $marked, QuoteEscalationReason::NotConfigured, 'again');

        self::assertSame([], $gateway->calls, 'A misconfigured shop must not add one comment per buyer comment.');
    }

    public function testADifferentReasonStillEscalates(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $marked = QuoteSnapshotFixture::snapshot(customFields: [
            QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value,
        ]);

        (new QuoteEscalator())->escalate($gateway, $marked, QuoteEscalationReason::NotConfigured, 'different');

        self::assertContains('addComment', $gateway->calls);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter QuoteEscalatorTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\QuoteEscalator" not found`.

- [ ] **Step 4: Add the escalation reason**

In `src/Policy/Data/QuoteEscalationReason.php`, add one case:

```php
    // Issue #5: the agent is enabled but cannot run — no API key, or config
    // that fails its own constraints.
    case NotConfigured = 'not_configured';
```

- [ ] **Step 5: Write the escalator**

Create `src/Servicing/QuoteEscalator.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * Puts an escalation where the merchant is already looking: a comment on the
 * quote. The comment goes through the gateway, so it carries
 * AgentContext::STATE and cannot re-trigger servicing.
 *
 * Once per quote per reason. Without the marker a misconfigured shop with a
 * talkative buyer collects one comment per buyer comment, which is loud in
 * the wrong sense. The marker joins the two the servicing loop already keeps
 * on customFields, and QuoteWriter shallow-merges, so it cannot disturb the
 * A2CN act chain.
 *
 * Issue #18 owns the full "reply or escalate" surface and should route its
 * escalations through here rather than growing a second path.
 */
final class QuoteEscalator
{
    public const MARKER_KEY = 'merchant_quote_agent_escalated';

    public function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteEscalationReason $reason,
        string $detail,
    ): void {
        $quoteId = $snapshot->identity->quoteId;

        if (($snapshot->lifecycle->customFields[self::MARKER_KEY] ?? null) === $reason->value) {
            return;
        }

        $gateway->addComment($quoteId, sprintf(
            'This quote needs a human: the automated agent could not handle it (%s). %s',
            $reason->value,
            $detail,
        ));

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [self::MARKER_KEY => $reason->value]));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter QuoteEscalatorTest`

Expected: PASS, 3 tests.

- [ ] **Step 7: Run the whole unit suite — the enum is shared**

Run: `php vendor/bin/phpunit`

Expected: PASS. If a test enumerates `QuoteEscalationReason::cases()` exhaustively, update it to include the new case.

- [ ] **Step 8: Commit**

```bash
vendor/bin/mago fmt src tests
git add src/Policy/Data/QuoteEscalationReason.php src/Servicing/QuoteEscalator.php tests/Unit/Servicing
git commit -m "feat: escalate a quote the agent cannot handle, once per reason"
```

---

### Task 7: The preflight

One collaborator that owns the reader and the escalator, so `ServiceQuoteHandler` gains one constructor parameter rather than two and stays at mago's limit of five.

**Files:**
- Create: `src/Servicing/ServicingPreflight.php`
- Test: `tests/Unit/Servicing/ServicingPreflightTest.php`

**Interfaces:**
- Consumes: `QuoteAgentSettingsReader::forSalesChannel()` (Task 5), `QuoteEscalator::escalate()` (Task 6).
- Produces: `new ServicingPreflight(QuoteAgentSettingsSource $reader, QuoteEscalator $escalator, LoggerInterface $logger)` and `check(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): ?QuoteAgentSettings`. Task 8 injects it.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Servicing/ServicingPreflightTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ServicingPreflightTest extends TestCase
{
    public function testAnEnabledValidChannelReturnsItsSettingsAndWritesNothing(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settings = self::settings();

        $result = self::preflight(static fn(): ?QuoteAgentSettings => $settings)
            ->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertSame($settings, $result);
        self::assertSame([], $gateway->calls);
    }

    public function testADisabledChannelReturnsNullSilently(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = self::preflight(static fn(): ?QuoteAgentSettings => null)
            ->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame([], $gateway->calls, 'A paused agent must not touch the quote.');
    }

    public function testAMisconfiguredChannelEscalatesTheQuoteAndReturnsNull(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = self::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        })->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    private static function settings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            rulesOnly: false,
            llm: new ModelAccess('sk-test', 'https://api.openai.com/v1'),
            strategyPrompt: null,
        );
    }

    /** @param \Closure(): ?QuoteAgentSettings $outcome */
    private static function preflight(\Closure $outcome): ServicingPreflight
    {
        $source = new class($outcome) implements QuoteAgentSettingsSource {
            /** @param \Closure(): ?QuoteAgentSettings $outcome */
            public function __construct(private readonly \Closure $outcome) {}

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return ($this->outcome)();
            }
        };

        return new ServicingPreflight($source, new QuoteEscalator(), new NullLogger());
    }
}
```

- [ ] **Step 2: Extract the settings source interface**

`ServicingPreflight` needs one method from configuration, and the test above
needs to supply it without constructing a `SystemConfigService`. Create
`src/Config/QuoteAgentSettingsSource.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/** What ServicingPreflight needs from configuration, and nothing else. */
interface QuoteAgentSettingsSource
{
    /** @throws InvalidQuoteAgentConfiguration */
    public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings;
}
```

Have `QuoteAgentSettingsReader` implement it — add
`implements QuoteAgentSettingsSource` to the class declaration and `#[\Override]`
above `forSalesChannel()`. It stays `final readonly`.

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter ServicingPreflightTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\ServicingPreflight" not found`.

- [ ] **Step 4: Write the preflight**

Create `src/Servicing/ServicingPreflight.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;

/**
 * May this quote be serviced, and with what settings.
 *
 * Two off states, deliberately different. A kill switch the merchant threw is
 * silent — they paused the agent and do not want it talking. A missing API key
 * or config that fails its own constraints is a misconfiguration, and silence
 * there is the exact bug issue #5 exists to remove, so it escalates where the
 * merchant is already looking.
 *
 * Exists as one collaborator rather than two so ServiceQuoteHandler stays at
 * five constructor parameters.
 */
final readonly class ServicingPreflight
{
    public function __construct(
        private QuoteAgentSettingsSource $reader,
        private QuoteEscalator $escalator,
        private LoggerInterface $logger,
    ) {}

    /** Null means "do not service this quote"; the reason has already been handled. */
    public function check(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): ?QuoteAgentSettings
    {
        try {
            $settings = $this->reader->forSalesChannel($snapshot->identity->salesChannelId);
        } catch (InvalidQuoteAgentConfiguration $e) {
            $this->logger->error('The quote agent is enabled but its configuration is unusable, so this quote '
            . 'was escalated instead of serviced.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
                'problems' => $e->problems,
            ]);

            $this->escalator->escalate(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NotConfigured,
                implode(' ', $e->problems),
            );

            return null;
        }

        if ($settings === null) {
            $this->logger->debug('The quote agent is switched off for this sales channel.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);
        }

        return $settings;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php vendor/bin/phpunit --filter ServicingPreflightTest`

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/mago fmt src tests
git add src/Config src/Servicing tests/Unit/Servicing/ServicingPreflightTest.php
git commit -m "feat: preflight a quote against the sales channel's agent settings"
```

---

### Task 8: Wire the preflight into the handler

**Files:**
- Modify: `src/Servicing/ServiceQuoteHandler.php`
- Modify: `src/Servicing/QuoteServicingPipelineInterface.php`
- Modify: `src/Resources/config/services.php`
- Modify: `tests/Unit/Servicing/ServicingHandlerFixture.php`
- Modify: `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`
- Test: `tests/Unit/Servicing/ServiceQuoteHandlerSettingsTest.php`

**Interfaces:**
- Consumes: `ServicingPreflight::check()` (Task 7).
- Produces: `ServiceQuoteHandler::__construct(QuoteServicingLock $locks, LoggerInterface $logger, ServicingPreflight $preflight, ?QuoteGatewayInterface $gateway = null, ?QuoteServicingPipelineInterface $pipeline = null)` — five parameters, the limit. `QuoteServicingPipelineInterface::service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway, QuoteAgentSettings $settings): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Servicing/ServiceQuoteHandlerSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

final class ServiceQuoteHandlerSettingsTest extends TestCase
{
    /** @throws \Throwable the handler's own declared surface */
    public function testTheSettingsReachThePipeline(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?QuoteAgentSettings $seen = null;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                $this->seen = $settings;
            }
        };

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertNotNull($pipeline->seen, '#18 cannot read config itself; the handler must hand the settings over.');
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testADisabledChannelIsNeitherServicedNorStamped(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        ServicingHandlerFixture::handler(
            $gateway,
            $pipeline,
            preflight: ServicingHandlerFixture::preflightReturning(null),
        )(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes);
        self::assertSame(
            [],
            $gateway->customFieldWrites,
            'Nothing was serviced, so nothing may be stamped — a stamp would suppress the next real trigger '
            . 'once the agent is switched back on.',
        );
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testASuccessfulPassClearsTheEscalationMarker(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);

        ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline())(
            ServicingHandlerFixture::message(),
        );

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayHasKey(ServicingFingerprint::MARKER_KEY, $stamp);
        self::assertNull(
            $stamp[\MerchantQuoteAgentPlugin\Servicing\QuoteEscalator::MARKER_KEY],
            'A fixed configuration must be able to escalate again if it breaks again.',
        );
    }
}
```

- [ ] **Step 2: Extend the fixture**

`ServicingPreflight` already depends on `QuoteAgentSettingsSource` (Task 7), so
the double is three lines. In `tests/Unit/Servicing/ServicingHandlerFixture.php`
add these imports:

```php
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
```

Replace `handler()` and add two helpers:

```php
    public static function handler(
        FakeQuoteGateway $gateway,
        QuoteServicingPipelineInterface $pipeline,
        ?QuoteServicingLock $locks = null,
        ?ServicingPreflight $preflight = null,
    ): ServiceQuoteHandler {
        return new ServiceQuoteHandler(
            $locks ?? self::locks(),
            new NullLogger(),
            $preflight ?? self::preflightReturning(self::settings()),
            $gateway,
            $pipeline,
        );
    }

    public static function settings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            rulesOnly: false,
            llm: new ModelAccess('sk-test', 'https://api.openai.com/v1'),
            strategyPrompt: null,
        );
    }

    public static function preflightReturning(?QuoteAgentSettings $settings): ServicingPreflight
    {
        $source = new class($settings) implements QuoteAgentSettingsSource {
            public function __construct(private readonly ?QuoteAgentSettings $settings) {}

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return $this->settings;
            }
        };

        return new ServicingPreflight($source, new QuoteEscalator(), new NullLogger());
    }
```

`ServicingHandlerFixture` is at mago's `too-many-methods` ceiling — the same
one that forced `ServiceQuoteHandlerRefusalTest` into its own class in #27. If
adding these two trips it, move `settings()` and `preflightReturning()` into a
new `tests/Unit/Servicing/ServicingSettingsFixture.php` and reference them from
both there and in `ServicingPreflightTest`.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit --filter 'ServiceQuoteHandlerSettingsTest|ServicingPreflightTest'`

Expected: FAIL — `ServiceQuoteHandler::__construct()` does not take a preflight yet.

- [ ] **Step 4: Widen the pipeline interface**

In `src/Servicing/QuoteServicingPipelineInterface.php`, add the import and the third parameter:

```php
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
```

```php
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway, QuoteAgentSettings $settings): void;
```

Extend the docblock:

```
 * The settings are handed over rather than read again, for the same reason
 * the gateway is: the handler has already resolved them for this quote's
 * sales channel and refused the quote if they were unusable. #18 cannot end
 * up reading a different sales channel's bands.
```

- [ ] **Step 5: Wire the handler**

In `src/Servicing/ServiceQuoteHandler.php`, add the imports:

```php
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
```

Insert `$preflight` as the third constructor parameter — third, so the two nullable collaborators stay last:

```php
    public function __construct(
        private QuoteServicingLock $locks,
        private LoggerInterface $logger,
        private ServicingPreflight $preflight,
        private ?QuoteGatewayInterface $gateway = null,
        private ?QuoteServicingPipelineInterface $pipeline = null,
    ) {}
```

In `servicePass()`, immediately after the terminal-state gate and **before** the fingerprint comparison:

```php
        $settings = $this->preflight->check($gateway, $snapshot);

        if ($settings === null) {
            // Returns BEFORE stamping, like every other refusal: the quote was
            // not serviced, and a stamp would suppress the next real trigger
            // once the agent is switched back on or the config is fixed.
            return;
        }
```

Change the hand-off to pass the settings:

```php
            $pipeline->service($snapshot, $gateway, $settings);
```

And add the escalation-marker clear to the success write, alongside the stamp:

```php
        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::stamp(
                $snapshot,
                $after->lifecycle->stateTechnicalName,
            ),
            self::ATTEMPTS_KEY => null,
            QuoteEscalator::MARKER_KEY => null,
        ]));
```

- [ ] **Step 6: Wire the container**

In `src/Resources/config/services.php`, add the imports for `QuoteAgentSettingsSource`, `QuoteEscalator` and `ServicingPreflight`, then inside the same guard:

```php
    $services->alias(QuoteAgentSettingsSource::class, QuoteAgentSettingsReader::class);
    $services->set(QuoteEscalator::class);
    $services->set(ServicingPreflight::class)->args([
        service(QuoteAgentSettingsSource::class),
        service(QuoteEscalator::class),
        service('logger'),
    ]);
```

and add the preflight to the handler's arguments, keeping the existing order:

```php
    $services->set(ServiceQuoteHandler::class)->args([
        service(QuoteServicingLock::class),
        service('logger'),
        service(ServicingPreflight::class),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
        service(QuoteServicingPipelineInterface::class)->ignoreOnInvalid(),
    ]);
```

- [ ] **Step 7: Run the whole unit suite**

Run: `php vendor/bin/phpunit`

Expected: PASS. Every existing `ServiceQuoteHandlerTest` and `ServiceQuoteHandlerRefusalTest` case goes through `ServicingHandlerFixture::handler()`, so they pick up the default preflight automatically — except any that construct `new ServiceQuoteHandler(...)` directly. `testANullGatewayParksTheMessageWithoutServicingOrLocking` and `testANullPipelineReturnsWithoutStamping` do; give them `ServicingHandlerFixture::preflightReturning(ServicingHandlerFixture::settings())` as the third argument.

- [ ] **Step 8: Run the integration suite**

Run: `composer run test:integration`

Expected: PASS. `ServicingReentrancyTest` and `ServicingCrashBudgetTest` build handlers by hand and implement `QuoteServicingPipelineInterface` inline — update each anonymous pipeline's `service()` to the three-parameter signature and each `new ServiceQuoteHandler(...)` to pass a preflight. Their sales channel must be enabled and validly configured for the pass to run, so set the config in `setUp()`:

```php
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', 'sk-integration');
```

`IntegrationTestCase` uses `DatabaseTransactionBehaviour`, so these writes roll back.

- [ ] **Step 9: Full quality gate**

Run: `composer run quality`

Expected: exit 0. If `excessive-parameter-list` fires on `ServiceQuoteHandler`, you added a sixth parameter — fold it into the preflight instead.

- [ ] **Step 10: Commit**

```bash
vendor/bin/mago fmt src tests
git add -A
git commit -m "feat: honour the kill switch and hand the settings to the pipeline"
```

---

### Task 9: Prove it against the live shop, and write the onboarding copy

Two behaviours nothing has yet exercised end to end, plus the two documentation statements the spec calls for by name.

**Files:**
- Create: `tests/Integration/ServicingConfigGateTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing further.

- [ ] **Step 1: Write the integration test**

Create `tests/Integration/ServicingConfigGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The kill switch and the misconfigured state, against the real container and
 * the real configuration store.
 */
final class ServicingConfigGateTest extends IntegrationTestCase
{
    public function testADisabledSalesChannelIsNeverServicedAndTheQuoteIsUntouched(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        self::config()->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', false);

        $pipeline = self::countingPipeline();
        $before = $gateway->fetchSnapshot($quoteId);

        self::handler($gateway, $pipeline)(
            ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten),
        );

        self::assertSame(0, $pipeline->passes, 'A disabled sales channel was serviced.');
        self::assertTrue(
            $gateway->fetchSnapshot($quoteId)->revision->matches($before->revision),
            'A paused agent wrote to the quote.',
        );
    }

    public function testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $config = self::config();
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'rulesOnlyMode', false);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        $pipeline = self::countingPipeline();
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);
        $before = \count($gateway->fetchSnapshot($quoteId)->content->comments);

        self::handler($gateway, $pipeline)($message);
        self::handler($gateway, $pipeline)($message);

        self::assertSame(0, $pipeline->passes, 'A misconfigured agent serviced the quote anyway.');
        self::assertSame(
            $before + 1,
            \count($gateway->fetchSnapshot($quoteId)->content->comments),
            'The misconfigured state must produce exactly one comment, not one per trigger.',
        );
        self::assertSame(
            'not_configured',
            $gateway->fetchSnapshot($quoteId)->lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null,
        );
    }

    private static function config(): SystemConfigService
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    private static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                ++$this->passes;
            }
        };
    }

    private static function handler(
        QuoteGatewayInterface $gateway,
        QuoteServicingPipelineInterface $pipeline,
    ): ServiceQuoteHandler {
        $preflight = static::getContainer()->get(ServicingPreflight::class);
        self::assertInstanceOf(ServicingPreflight::class, $preflight);

        return new ServiceQuoteHandler(
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x'),
            new NullLogger(),
            $preflight,
            $gateway,
            $pipeline,
        );
    }
}
```

- [ ] **Step 2: Confirm the preflight resolves from the test container**

No `->public()` is needed. `IntegrationTestCase` already resolves private
services — its own docblock records that the commercial adapters "are
resolvable through `test.service_container` even though they are private" —
and the test above uses the same `static::getContainer()`. If resolution fails,
the service was not registered in Task 8 Step 6; fix the registration rather
than making a production service public.

- [ ] **Step 3: Run the integration test**

Run: `composer run test:integration -- --filter ServicingConfigGateTest`

Expected: PASS, 2 tests.

- [ ] **Step 4: Write the onboarding copy**

In `README.md`, after the "Operating the servicing loop" section, add:

```markdown
## Configuring the agent

Everything is in the plugin's own settings, per sales channel:
**Settings → Extensions → Merchant Quote Agent**. A sales channel inherits the
global value until you override it, so you can configure once and raise the
ceiling on a pilot channel first.

**The agent ships switched off, and a fresh install answers nothing.** That is
deliberate on two counts: `enabled` defaults to false, and `maxDiscountPercent`
defaults to `0` with every non-price dimension blank, so even once enabled the
agent escalates every ask until you set bands. A silent agent is far more often
"not configured yet" than "broken".

**You supply the model credentials.** The API key is yours, so per-tenant model
cost is not the plugin's, and the base URL lets you point at Azure, your own
gateway or a self-hosted model. To state plainly rather than imply: the key is
stored in Shopware's `system_config` table, obscured behind a password field in
the admin but **not encrypted at rest** — the same posture as every other
secret a Shopware plugin holds.

**An empty API key is never a quiet fall back to deterministic decisions.** An
enabled channel with no key is a misconfiguration: the agent escalates the
quote with a comment and logs which fields are wrong. If you want deterministic
decisions without a model, switch on **rules-only mode** — that is the one
state in which no key is needed.

**Invalid configuration is refused whole.** A discount cap above 100, a bad
ceiling currency or a volume-tier line that does not parse makes the whole
sales channel unusable and escalates, rather than applying the half of the
policy that happened to be valid.
```

- [ ] **Step 5: Full verification**

Run each and confirm before claiming the task is done:

```bash
php vendor/bin/phpunit
composer run test:integration
composer run quality
```

Expected: unit PASS, integration PASS, quality exit 0.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "test: prove the kill switch and the misconfigured state against the live shop"
```

---

## Handover to #18

State these in the pull request, because #18's plan depends on them:

- `QuoteServicingPipelineInterface::service()` now takes `QuoteAgentSettings` third. Model access, the strategy prompt and the rules-only flag arrive with the call; #18 must not read configuration itself.
- `QuoteEscalator` is the escalation actuator. #18 routes its own escalations through it rather than growing a second path, and clears `QuoteEscalator::MARKER_KEY` on a successful pass the way the handler already does.
- `rulesOnly` is read but not acted on. #5 uses it only to decide whether a missing API key is legitimate; skipping the model call is #18's.
- `counterOfferMaxPercent` is now populated and still unread. #18 wires the deterministic counter band `PriceBandClassifier` documents as unreachable.
