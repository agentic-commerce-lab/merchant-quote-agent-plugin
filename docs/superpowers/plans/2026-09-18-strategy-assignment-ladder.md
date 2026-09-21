# Strategy Assignment Ladder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Choose a negotiation strategy per quote from a four-rung ladder — customer pin, rule builder, weighted split, sales-channel config — instead of only from the sales-channel config key.

**Architecture:** One new table holds all three assignment kinds, discriminated by a `kind` column. A `StrategyAssignmentResolver` walks the rungs once per pass and returns a `ResolvedStrategy` plus the source that produced it. `ServicingPreflight` calls it after reading settings and clones the settings object with the result, so `PromptComposer`, `NegotiationPipeline` and `DecisionRecorder` are untouched. Rule evaluation builds a real `CartRuleScope` from the quote via two SwagCommercial services.

**Tech Stack:** PHP 8.3, Shopware 6.7 (DAL attribute entities, hand-written `MigrationStep`s), PHPUnit 11, mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-09-18-strategy-assignment-design.md`

## Global Constraints

- **Support floor is Shopware 6.7.1.0.** Never pass `maxLength:` to `#[Field]` — the argument does not exist at the floor and an unknown named argument is an `Error` during the container build. Column widths live in the migration only.
- **Attribute entities carry no schema generator.** Every entity property must be kept in step with hand-written DDL by hand, and by a test.
- **ADR 0001:** SwagCommercial classes are never named with `::class`. Their service ids are string constants on `CommercialAvailability`, injected as untyped `object`, referenced with `ignoreOnInvalid()`.
- **Everything in this plan registers after `src/Resources/config/services.php:571`**, inside the commercial gate. On a shop without SwagCommercial there is no servicing path at all, so nothing here needs a no-SwagCommercial fallback.
- **Unit tests boot no kernel.** `tests/Unit` runs with `composer test`; `tests/Integration` runs only inside a shop container with `composer test:integration`.
- **Quality gate before every commit:** `composer format:check && composer lint && composer typecheck`. `composer format` fixes formatting.
- **Commit trailer:** every commit ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **Commits are signed.** If `git commit` fails with `1Password: agent returned an error`, prefix with `export SSH_AUTH_SOCK="$HOME/Library/Group Containers/2BUA8C4S2C.com.1password/t/agent.sock"` and retry; if it still fails, the user must unlock 1Password.

---

## File Structure

**Created:**

| Path | Responsibility |
| --- | --- |
| `src/Strategy/StrategyAssignmentSource.php` | The four-value enum naming which rung chose a strategy. |
| `src/Strategy/AssignedStrategy.php` | A resolved strategy plus the rung that produced it. |
| `src/Strategy/StrategyAssignment.php` | DAL entity for the assignment table. |
| `src/Strategy/SplitBucket.php` | The deterministic hash. Pure, no Shopware. |
| `src/Strategy/StrategyAssignmentResolver.php` | The ladder. |
| `src/Bridge/QuoteRuleScopeFactory.php` | Quote id → `CartRuleScope`, via two SwagCommercial services. |
| `src/Bridge/RuleScopeUnavailable.php` | Thrown when the scope cannot be built. |
| `src/Migration/Migration1789600000CreateStrategyAssignment.php` | The assignment table. |
| `src/Migration/Migration1789600001AddAssignmentSourceToDecision.php` | The audit column. |

**Modified:** `src/Config/QuoteAgentSettings.php`, `src/Config/QuoteAgentSettingsFactory.php`, `src/Servicing/ServicingPreflight.php`, `src/Audit/DecisionDraft.php`, `src/Audit/DecisionRecorder.php`, `src/Audit/QuoteDecisionRecord.php`, `src/Audit/Export/AnonymizedDecision.php`, `src/Negotiation/NegotiationPipeline.php`, `src/Bridge/Commercial/CommercialAvailability.php`, `src/Resources/config/services.php`, `src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts`.

---

### Task 1: The source enum and the settings clone

**Files:**
- Create: `src/Strategy/StrategyAssignmentSource.php`
- Modify: `src/Config/QuoteAgentSettings.php`
- Modify: `src/Config/QuoteAgentSettingsFactory.php:62-64`
- Test: `tests/Unit/Config/QuoteAgentSettingsStrategyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `StrategyAssignmentSource` (backed string enum, cases `Pin='pin'`, `Rule='rule'`, `Split='split'`, `Config='config'`); `QuoteAgentSettings::$strategyAssignmentSource: ?StrategyAssignmentSource`; `QuoteAgentSettings::withStrategy(ResolvedStrategy $strategy, StrategyAssignmentSource $source): self`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/QuoteAgentSettingsStrategyTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use PHPUnit\Framework\TestCase;

final class QuoteAgentSettingsStrategyTest extends TestCase
{
    public function testWithStrategyReplacesThePromptTheVersionAndTheSource(): void
    {
        $settings = self::settings();

        $replaced = $settings->withStrategy(
            new ResolvedStrategy('1111111111111111111111111111aaaa', 'hold firm'),
            StrategyAssignmentSource::Split,
        );

        self::assertSame('hold firm', $replaced->strategyPrompt);
        self::assertSame('1111111111111111111111111111aaaa', $replaced->strategyVersionId);
        self::assertSame(StrategyAssignmentSource::Split, $replaced->strategyAssignmentSource);
    }

    public function testWithStrategyCarriesEveryOtherFieldAcross(): void
    {
        $settings = self::settings();

        $replaced = $settings->withStrategy(
            new ResolvedStrategy('1111111111111111111111111111aaaa', 'hold firm'),
            StrategyAssignmentSource::Pin,
        );

        self::assertSame($settings->policy, $replaced->policy);
        self::assertSame($settings->llm, $replaced->llm);
        self::assertSame($settings->notifyBuyerOnEscalation, $replaced->notifyBuyerOnEscalation);
    }

    /**
     * withPolicy() predates the assignment source and must not silently drop
     * it -- a clone that loses the source would make every decision row it
     * touches read as `config`.
     */
    public function testWithPolicyKeepsTheAssignmentSource(): void
    {
        $settings = self::settings()->withStrategy(
            new ResolvedStrategy('1111111111111111111111111111aaaa', 'hold firm'),
            StrategyAssignmentSource::Rule,
        );

        $replaced = $settings->withPolicy($settings->policy);

        self::assertSame(StrategyAssignmentSource::Rule, $replaced->strategyAssignmentSource);
    }

    private static function settings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            self::createStub(NegotiationPolicy::class),
            self::createStub(ModelAccess::class),
            'be nice',
            true,
            '0000000000000000000000000000bbbb',
            StrategyAssignmentSource::Config,
        );
    }
}
```

If `NegotiationPolicy` or `ModelAccess` is `final` and cannot be stubbed, build a real one the way `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php` already does, and copy that construction verbatim rather than inventing one.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter QuoteAgentSettingsStrategyTest`
Expected: FAIL with `Class "MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource" not found`.

- [ ] **Step 3: Write the enum**

Create `src/Strategy/StrategyAssignmentSource.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * Which rung of the assignment ladder chose the strategy this pass sent.
 *
 * Recorded on every decision row beside `strategy_version_id`, which it
 * explains. It is the only way a merchant can see that a rule they configured
 * never actually matched: the negotiation looks identical either way, because
 * the ladder falls through to the next rung rather than escalating.
 *
 * `Config` is the sales-channel configuration key -- the only mechanism that
 * existed before the ladder, and still the bottom rung.
 */
enum StrategyAssignmentSource: string
{
    case Pin = 'pin';
    case Rule = 'rule';
    case Split = 'split';
    case Config = 'config';
}
```

- [ ] **Step 4: Add the field and the clone to `QuoteAgentSettings`**

In `src/Config/QuoteAgentSettings.php`, add the constructor parameter after `strategyVersionId`:

```php
        public ?StrategyAssignmentSource $strategyAssignmentSource = null,
```

Add `use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;` and `use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;` to the imports, then extend `withPolicy()` and add `withStrategy()`:

```php
    public function withPolicy(NegotiationPolicy $policy): self
    {
        return new self(
            $policy,
            $this->llm,
            $this->strategyPrompt,
            $this->notifyBuyerOnEscalation,
            $this->strategyVersionId,
            $this->strategyAssignmentSource,
        );
    }

    /**
     * The assignment ladder's answer, replacing whatever the configuration key
     * resolved. Both the prompt and the version id move together: a row that
     * recorded one strategy's version while another strategy's prompt was sent
     * would make the whole per-strategy dashboard lie.
     */
    public function withStrategy(ResolvedStrategy $strategy, StrategyAssignmentSource $source): self
    {
        return new self(
            $this->policy,
            $this->llm,
            $strategy->prompt,
            $this->notifyBuyerOnEscalation,
            $strategy->versionId,
            $source,
        );
    }
```

- [ ] **Step 5: Make the factory name the bottom rung**

In `src/Config/QuoteAgentSettingsFactory.php`, the call that builds `QuoteAgentSettings` currently ends with `strategyVersionId: RawConfigValue::string($raw, 'negotiationStrategyVersionId'),`. Add after it:

```php
            strategyAssignmentSource: RawConfigValue::string($raw, 'negotiationStrategyVersionId') === null
                ? null
                : StrategyAssignmentSource::Config,
```

A settings object whose strategy came from the config key is `config`; one with no strategy at all stays null, because no rung chose anything.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer test -- --filter 'QuoteAgentSettingsStrategyTest|QuoteAgentSettingsFactoryTest|QuoteAgentSettingsReaderTest'`
Expected: PASS. If `QuoteAgentSettingsFactoryTest` fails on an argument-count or named-argument error, the new parameter was inserted in the wrong position — it must be last.

- [ ] **Step 7: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck
git add src/Strategy/StrategyAssignmentSource.php src/Config/QuoteAgentSettings.php src/Config/QuoteAgentSettingsFactory.php tests/Unit/Config/QuoteAgentSettingsStrategyTest.php
git commit -m "feat(strategy): name which rung chose a strategy

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: The assignment table and entity

**Files:**
- Create: `src/Strategy/StrategyAssignment.php`
- Create: `src/Migration/Migration1789600000CreateStrategyAssignment.php`
- Modify: `src/Resources/config/services.php` (entity registration, near `StrategyResolver` at line 374)
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts`
- Test: `tests/Unit/Strategy/StrategyAssignmentEntityTest.php`

**Interfaces:**
- Consumes: `StrategyAssignmentSource` from Task 1 (for the `kind` values — `Pin`, `Rule`, `Split` only; `Config` is never a row).
- Produces: entity `MerchantQuoteAgentPlugin\Strategy\StrategyAssignment` on table `merchant_quote_agent_strategy_assignment`, repository id `merchant_quote_agent_strategy_assignment.repository`, public properties `id`, `kind`, `salesChannelId`, `customerId`, `ruleId`, `weight`, `strategyId`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Strategy/StrategyAssignmentEntityTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Migration\Migration1789600000CreateStrategyAssignment;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignment;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;

/**
 * Attribute entities carry no schema generator, so the class and the DDL are
 * kept in step by hand. This is the test that notices when they are not.
 */
final class StrategyAssignmentEntityTest extends TestCase
{
    public function testTheEntityNamesTheMigratedTable(): void
    {
        $attribute = (new \ReflectionClass(StrategyAssignment::class))
            ->getAttributes(Entity::class)[0]
            ->newInstance();

        self::assertSame('merchant_quote_agent_strategy_assignment', $attribute->name);
    }

    /**
     * Property name to snake_case column, checked against the CREATE TABLE
     * text itself rather than a second hand-maintained list.
     */
    public function testEveryEntityPropertyHasAColumn(): void
    {
        $ddl = file_get_contents(
            __DIR__ . '/../../../src/Migration/Migration1789600000CreateStrategyAssignment.php',
        );
        self::assertIsString($ddl);

        foreach ((new \ReflectionClass(StrategyAssignment::class))->getProperties() as $property) {
            $column = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $property->getName()));

            self::assertStringContainsString('`' . $column . '`', $ddl, $property->getName());
        }
    }

    public function testNoFieldDeclaresMaxLength(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Strategy/StrategyAssignment.php');
        self::assertIsString($source);

        self::assertStringNotContainsString('maxLength', $source);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter StrategyAssignmentEntityTest`
Expected: FAIL with `Class "MerchantQuoteAgentPlugin\Strategy\StrategyAssignment" not found`.

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1789600000CreateStrategyAssignment.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The assignment ladder's rows: customer pins, rule bindings and split arms,
 * one table discriminated by `kind`.
 *
 * `kind` is explicit rather than derived from which nullable column is set.
 * The derived version reads fine while it is being written and is the thing
 * someone decodes at 3am; the CHECK constraint then makes the two
 * representations unable to disagree.
 *
 * No foreign key on `customer_id`: core's customer rows are deletable, and a
 * pin outliving its account is harmless -- rung 1 simply stops matching. A
 * cascade there would be a silent configuration change on customer deletion.
 *
 * `rule_id` DOES cascade. It is the one dangling reference this plugin does
 * not refuse, because deleting a rule happens in core's rule builder, which
 * offers no hook to refuse from; the choice is a cascade or a row that
 * silently never matches again.
 *
 * The unique key does not catch every duplicate pin: MySQL treats NULLs as
 * distinct in a unique index, so two GLOBAL pins for one customer pass. The
 * resolver orders deterministically for that case; see StrategyAssignmentResolver.
 */
class Migration1789600000CreateStrategyAssignment extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789600000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_strategy_assignment` (
                    `id`               BINARY(16)  NOT NULL,
                    `kind`             VARCHAR(16) NOT NULL,
                    `sales_channel_id` BINARY(16)  NULL,
                    `customer_id`      BINARY(16)  NULL,
                    `rule_id`          BINARY(16)  NULL,
                    `weight`           INT(11)     NULL,
                    `strategy_id`      BINARY(16)  NOT NULL,
                    `created_at`       DATETIME(3) NOT NULL,
                    `updated_at`       DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq.mqasa.customer` (`sales_channel_id`, `customer_id`),
                    KEY `idx.mqasa.kind_channel` (`kind`, `sales_channel_id`),
                    KEY `idx.mqasa.strategy_id` (`strategy_id`),
                    CONSTRAINT `fk.mqasa.rule_id` FOREIGN KEY (`rule_id`)
                        REFERENCES `rule` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk.mqasa.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                        REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `ck.mqasa.kind` CHECK (
                        (`kind` = 'pin'      AND `customer_id` IS NOT NULL AND `rule_id` IS NULL AND `weight` IS NULL)
                     OR (`kind` = 'rule'     AND `rule_id`     IS NOT NULL AND `customer_id` IS NULL AND `weight` IS NULL)
                     OR (`kind` = 'split'    AND `weight`      IS NOT NULL AND `customer_id` IS NULL AND `rule_id` IS NULL)
                    )
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is the merchant's configuration.
    }
}
```

- [ ] **Step 4: Write the entity**

Create `src/Strategy/StrategyAssignment.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One rung's row: a customer pin, a rule binding, or a split arm.
 *
 * `kind` holds a StrategyAssignmentSource VALUE -- `pin`, `rule` or `split`,
 * never `config`, which is the absence of a row rather than a row. The
 * resolver writes and reads it as `StrategyAssignmentSource::Pin->value` and
 * friends, never as a literal, so the column and the audit column it explains
 * cannot drift apart.
 *
 * `ruleId`, `customerId` and `strategyId` are plain UUID columns, not DAL
 * associations, for the reason StrategyVersion gives: association attributes
 * sit outside what CoreFloorCompatibilityTest checks, and that test is skipped
 * entirely without a local core clone, so CI would not catch a floor breakage
 * there. The database-level foreign keys in the migration are what enforce
 * referential integrity.
 *
 * No `maxLength:` on `kind` -- the argument does not exist at the 6.7.1.0
 * support floor. The width lives in the migration.
 *
 * Like Strategy and unlike QuoteDecisionRecord, this carries no Protection
 * attribute: the administration writes these rows through the admin API on the
 * merchant's behalf, so system scope would lock out the only thing that writes
 * them.
 */
#[Entity('merchant_quote_agent_strategy_assignment')]
class StrategyAssignment extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $id = '';

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public string $kind = '';

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $customerId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $ruleId = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public ?int $weight = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $strategyId = '';
}
```

- [ ] **Step 5: Register the entity**

In `src/Resources/config/services.php`, find where `Strategy::class` and `StrategyVersion::class` are registered as entities (search for `Strategy::class` near line 374) and register `StrategyAssignment::class` the same way, with the same `use` import added alphabetically. Copy the surrounding registration idiom exactly — do not invent a different one.

- [ ] **Step 6: Add the ACL privilege**

In `acl/index.ts`, add `'merchant_quote_agent_strategy_assignment:read'` to the `viewer` role's `privileges` array, directly after `'merchant_quote_agent_strategy_version:read'`, and extend the comment block above it with one sentence: the assignment ladder's rows, which the strategies page reads to show which customers and rules point at each strategy.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `composer test -- --filter StrategyAssignmentEntityTest`
Expected: PASS, 3 tests.

- [ ] **Step 8: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin
git add src/Strategy/StrategyAssignment.php src/Migration/Migration1789600000CreateStrategyAssignment.php src/Resources/config/services.php src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts tests/Unit/Strategy/StrategyAssignmentEntityTest.php
git commit -m "feat(strategy): add the assignment table

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: The split hash

**Files:**
- Create: `src/Strategy/SplitBucket.php`
- Test: `tests/Unit/Strategy/SplitBucketTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SplitBucket::of(string $customerId, string $salesChannelId): int`, returning `0..9999`.

This is the highest-value test in the plan. The values it asserts are not merely correct, they are load-bearing across time: a later refactor that changes them silently re-randomizes every live experiment on every shop that has one running.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Strategy/SplitBucketTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Strategy\SplitBucket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frozen fixture. Changing any value below changes which arm every
 * company in every live split lands in, on every shop, silently -- the
 * negotiation looks identical, only the posture differs. Treat a failure here
 * as "the refactor is wrong", never as "the fixture is stale".
 */
final class SplitBucketTest extends TestCase
{
    private const CHANNEL = '0189abcdef0123456789abcdef012345';

    /** @return iterable<string, array{string, int}> */
    public static function frozen(): iterable
    {
        yield 'customer a' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 0];
        yield 'customer b' => ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 0];
        yield 'customer c' => ['cccccccccccccccccccccccccccccccc', 0];
        yield 'customer d' => ['dddddddddddddddddddddddddddddddd', 0];
    }

    #[DataProvider('frozen')]
    public function testTheBucketNeverMoves(string $customerId, int $expected): void
    {
        self::assertSame($expected, SplitBucket::of($customerId, self::CHANNEL));
    }

    public function testTheBucketIsStableAcrossCalls(): void
    {
        $first = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHANNEL);
        $second = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHANNEL);

        self::assertSame($first, $second);
    }

    public function testTheSalesChannelIsPartOfTheHash(): void
    {
        $here = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHANNEL);
        $there = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'ffffffffffffffffffffffffffffffff');

        self::assertNotSame($here, $there);
    }

    public function testEveryBucketIsInRange(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $bucket = SplitBucket::of(str_pad((string) $i, 32, '0', \STR_PAD_LEFT), self::CHANNEL);

            self::assertGreaterThanOrEqual(0, $bucket);
            self::assertLessThan(10000, $bucket);
        }
    }

    /**
     * Not a uniformity proof -- 500 samples cannot be one. It is a smoke
     * alarm for a hash that collapsed to a constant, which is the realistic
     * way this breaks and the way a range check alone would not notice.
     */
    public function testTheHashDoesNotCollapse(): void
    {
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            $seen[SplitBucket::of(str_pad((string) $i, 32, '0', \STR_PAD_LEFT), self::CHANNEL)] = true;
        }

        self::assertGreaterThan(400, \count($seen));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter SplitBucketTest`
Expected: FAIL with `Class "MerchantQuoteAgentPlugin\Strategy\SplitBucket" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Strategy/SplitBucket.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * Which slice of a weighted split a company falls into, from the company id
 * alone.
 *
 * Deterministic and sticky on purpose. A re-rolled bucket would hand the same
 * buyer a different negotiating personality on their next quote, and would
 * contaminate the comparison the split exists to produce with variance that is
 * within one company rather than between two strategies.
 *
 * Hashed on the COMPANY -- QuoteIdentity::$customerId is the B2B company, not
 * a person -- so every employee and every organization unit of an account
 * meets the same posture.
 *
 * The sales channel is in the hash so a company trading on two channels can
 * land in different arms on each, which is what per-channel weights imply.
 *
 * 10000 buckets rather than 100: weights need not sum to 100, and the
 * resolver scales a bucket onto whatever they do sum to. Four digits keep the
 * rounding error below a tenth of a percent for any realistic weight set.
 *
 * sha1, not crc32: the output has to be stable across PHP versions and
 * platforms forever (see SplitBucketTest), and it is not a security boundary.
 */
final class SplitBucket
{
    public static function of(string $customerId, string $salesChannelId): int
    {
        $digest = sha1($customerId . ':' . $salesChannelId);

        return (int) (hexdec(substr($digest, 0, 8)) % 10000);
    }
}
```

- [ ] **Step 4: Run the test and record the real values**

Run: `composer test -- --filter SplitBucketTest`
Expected: the four `frozen` cases FAIL, each reporting the actual bucket; the other four tests PASS.

Now replace the four `0` placeholders in the `frozen` provider with the actual values PHPUnit reported — one per row, copied exactly. This is the only place in this plan where a test is written to match an implementation, and it is deliberate: the fixture's job is to freeze whatever the hash produces, not to assert a value chosen in advance.

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer test -- --filter SplitBucketTest`
Expected: PASS, 8 tests.

- [ ] **Step 6: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck
git add src/Strategy/SplitBucket.php tests/Unit/Strategy/SplitBucketTest.php
git commit -m "feat(strategy): add the sticky split bucket

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: The quote rule scope

**Files:**
- Create: `src/Bridge/QuoteRuleScopeFactory.php`
- Create: `src/Bridge/RuleScopeUnavailable.php`
- Modify: `src/Bridge/Commercial/CommercialAvailability.php`
- Modify: `src/Resources/config/services.php:635-638` (beside `QuoteRecalculator`)
- Test: `tests/Unit/Bridge/QuoteRuleScopeFactoryTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `QuoteRuleScopeFactory::forQuote(string $quoteId, Context $context): CartRuleScope`, throwing `RuleScopeUnavailable`; constant `CommercialAvailability::QUOTE_TO_CART_CONVERTER`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Bridge/QuoteRuleScopeFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class QuoteRuleScopeFactoryTest extends TestCase
{
    private const QUOTE_ID = '0123456789abcdef0123456789abcdef';

    public function testItBuildsACartScopeFromTheRestoredContextAndTheConvertedCart(): void
    {
        $cart = new Cart('token');
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        $factory = new QuoteRuleScopeFactory(
            new class ($salesChannelContext) {
                public function __construct(private readonly SalesChannelContext $context) {}

                public function restoreByQuote(string $quoteId, Context $context): SalesChannelContext
                {
                    return $this->context;
                }
            },
            new class ($cart) {
                public function __construct(private readonly Cart $cart) {}

                public function convertToCart(object $quote, SalesChannelContext $context): Cart
                {
                    return $this->cart;
                }
            },
            $this->repository([new \stdClass()]),
        );

        $scope = $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());

        self::assertInstanceOf(CartRuleScope::class, $scope);
        self::assertSame($cart, $scope->getCart());
        self::assertSame($salesChannelContext, $scope->getSalesChannelContext());
    }

    /**
     * The real failure this guards: a customer with no active shipping address
     * makes QuoteToCartConverter throw. The rung is skipped, so the throw has
     * to arrive as this plugin's own exception rather than SwagCommercial's,
     * which the resolver cannot name under ADR 0001.
     */
    public function testAConversionFailureBecomesRuleScopeUnavailable(): void
    {
        $factory = new QuoteRuleScopeFactory(
            new class {
                public function restoreByQuote(string $quoteId, Context $context): SalesChannelContext
                {
                    throw new \RuntimeException('no customer address');
                }
            },
            new class {
                public function convertToCart(object $quote, SalesChannelContext $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            $this->repository([new \stdClass()]),
        );

        $this->expectException(RuleScopeUnavailable::class);

        $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());
    }

    public function testAMissingQuoteRowBecomesRuleScopeUnavailable(): void
    {
        $factory = new QuoteRuleScopeFactory(
            new class {
                public function restoreByQuote(string $quoteId, Context $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            new class {
                public function convertToCart(object $quote, SalesChannelContext $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            $this->repository([]),
        );

        $this->expectException(RuleScopeUnavailable::class);

        $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());
    }

    /** @param list<object> $entities */
    private function repository(array $entities): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'quote',
                \count($entities),
                new EntityCollection($entities),
                null,
                $criteria,
                $context,
            ),
        );

        return $repository;
    }
}
```

If `EntityCollection` rejects `stdClass`, replace both `new \stdClass()` occurrences with `$this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Entity::class)` and keep everything else identical.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter QuoteRuleScopeFactoryTest`
Expected: FAIL with `Class "MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory" not found`.

- [ ] **Step 3: Write the exception**

Create `src/Bridge/RuleScopeUnavailable.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * This quote cannot be turned into a rule scope right now.
 *
 * Not a misconfiguration and not a bug: the common cause is a customer with no
 * active shipping address, which QuoteToCartConverter refuses to convert. The
 * assignment ladder treats it as "the rule rung does not apply" and falls
 * through, rather than escalating the quote to a human.
 */
final class RuleScopeUnavailable extends \RuntimeException {}
```

- [ ] **Step 4: Write the factory**

Create `src/Bridge/QuoteRuleScopeFactory.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * A quote, as something Shopware's rule builder can be evaluated against.
 *
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer::restoreByQuote()
 * and Shopware\Commercial\B2B\QuoteManagement\Domain\QuoteToCart\QuoteToCartConverter::convertToCart()
 * -- neither is @internal, and QuoteCalculator::recalculate() composes the two
 * exactly this way. QuoteRecalculator already depends on the first.
 *
 * Every RuleScope in core requires a SalesChannelContext, and the cart-shaped
 * conditions additionally require a Cart. Building a CartRuleScope rather than
 * a CheckoutRuleScope is what makes 106 of core's 117 conditions evaluable
 * here: core's conditions open with a scope guard that RETURNS FALSE rather
 * than throwing, so the cheaper scope would leave a merchant's cart condition
 * silently never matching. The 11 flow-only conditions stay in that trap and
 * are documented in the administration instead.
 *
 * The extra quote read is unavoidable: restoreByQuote() loads the quote
 * internally but does not hand it back, and convertToCart() throws unless
 * lineItems, transactions and deliveries are all loaded.
 */
final readonly class QuoteRuleScopeFactory
{
    public function __construct(
        private object $contextRestorer,
        private object $quoteToCartConverter,
        private EntityRepository $quotes,
    ) {}

    /** @throws RuleScopeUnavailable */
    public function forQuote(string $quoteId, Context $context): CartRuleScope
    {
        $criteria = new Criteria([$quoteId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');

        $quote = $this->quotes->search($criteria, $context)->first();

        if ($quote === null) {
            throw new RuleScopeUnavailable('Quote ' . $quoteId . ' was not found, so it has no rule scope.');
        }

        try {
            /** @mago-expect analysis:ambiguous-object-method-access */
            $salesChannelContext = $this->contextRestorer->restoreByQuote($quoteId, $context);
            \assert($salesChannelContext instanceof SalesChannelContext);

            /** @mago-expect analysis:ambiguous-object-method-access */
            $cart = $this->quoteToCartConverter->convertToCart($quote, $salesChannelContext);
        } catch (\Throwable $e) {
            throw new RuleScopeUnavailable(
                'Quote ' . $quoteId . ' could not be converted to a cart: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return new CartRuleScope($cart, $salesChannelContext);
    }
}
```

- [ ] **Step 5: Add the service id constant**

In `src/Bridge/Commercial/CommercialAvailability.php`, add after `QUOTE_CALCULATOR`:

```php
    public const QUOTE_TO_CART_CONVERTER = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteToCart\\QuoteToCartConverter';
```

Then update the docblock above `QUOTE_MANIPULATION`: it says "The four commercial services this bridge injects" and "GatewayWiringTest resolves all four" — both become five.

- [ ] **Step 6: Register the service**

In `src/Resources/config/services.php`, directly after the `QuoteRecalculator::class` registration at line 635:

```php
    $services->set(QuoteRuleScopeFactory::class)->args([
        service(CommercialAvailability::CONTEXT_RESTORER)->ignoreOnInvalid(),
        service(CommercialAvailability::QUOTE_TO_CART_CONVERTER)->ignoreOnInvalid(),
        service('quote.repository'),
    ]);
```

Add `use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;` alphabetically among the other `Bridge\` imports.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `composer test -- --filter 'QuoteRuleScopeFactoryTest|CommercialAvailabilityTest'`
Expected: PASS.

- [ ] **Step 8: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck
git add src/Bridge/QuoteRuleScopeFactory.php src/Bridge/RuleScopeUnavailable.php src/Bridge/Commercial/CommercialAvailability.php src/Resources/config/services.php tests/Unit/Bridge/QuoteRuleScopeFactoryTest.php
git commit -m "feat(bridge): build a cart rule scope from a quote

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: The ladder

**Files:**
- Create: `src/Strategy/AssignedStrategy.php`
- Create: `src/Strategy/StrategyAssignmentResolver.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Strategy/StrategyAssignmentResolverTest.php`

**Interfaces:**
- Consumes: `StrategyAssignmentSource` (Task 1), `StrategyAssignment` (Task 2), `SplitBucket::of()` (Task 3), `QuoteRuleScopeFactory::forQuote()` and `RuleScopeUnavailable` (Task 4), plus the existing `StrategyResolver::resolve(string $strategyId, Context $context): ResolvedStrategy` and `UnknownStrategy`.
- Produces: `AssignedStrategy` (readonly, `ResolvedStrategy $strategy`, `StrategyAssignmentSource $source`); `StrategyAssignmentResolver::assign(string $quoteId, string $customerId, string $salesChannelId, Context $context): ?AssignedStrategy`.

Note the signature: the resolver takes three strings, not a `QuoteSnapshot`. `QuoteSnapshot` lives in `Bridge\Data`, and keeping the resolver off it means the whole ladder is unit-testable without constructing a five-part snapshot.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Strategy/StrategyAssignmentResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignment;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class StrategyAssignmentResolverTest extends TestCase
{
    private const QUOTE = '11111111111111111111111111111111';

    private const CUSTOMER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const CHANNEL = '22222222222222222222222222222222';

    private const PINNED = '3333333333333333333333333333aaaa';

    private const RULED = '3333333333333333333333333333bbbb';

    private const ARM_ONE = '3333333333333333333333333333cccc';

    private const ARM_TWO = '3333333333333333333333333333dddd';

    public function testAPinBeatsEverythingBelowIt(): void
    {
        $assigned = $this->resolver([
            $this->row('pin', self::PINNED, customerId: self::CUSTOMER),
            $this->row('split', self::ARM_ONE, weight: 100),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::PINNED, $assigned->strategy->versionId);
        self::assertSame(StrategyAssignmentSource::Pin, $assigned->source);
    }

    public function testAChannelPinBeatsAGlobalPin(): void
    {
        $assigned = $this->resolver([
            $this->row('pin', self::ARM_ONE, customerId: self::CUSTOMER),
            $this->row('pin', self::PINNED, customerId: self::CUSTOMER, salesChannelId: self::CHANNEL),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::PINNED, $assigned->strategy->versionId);
    }

    public function testAPinForAnotherCustomerIsIgnored(): void
    {
        $assigned = $this->resolver([
            $this->row('pin', self::PINNED, customerId: 'ffffffffffffffffffffffffffffffff'),
            $this->row('split', self::ARM_ONE, weight: 100),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(StrategyAssignmentSource::Split, $assigned->source);
    }

    public function testTheHighestPriorityMatchingRuleWins(): void
    {
        $assigned = $this->resolver(
            [$this->row('rule', self::ARM_ONE, ruleId: 'r1'), $this->row('rule', self::RULED, ruleId: 'r2')],
            rules: [$this->rule('r1', priority: 1, matches: true), $this->rule('r2', priority: 9, matches: true)],
        )->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::RULED, $assigned->strategy->versionId);
        self::assertSame(StrategyAssignmentSource::Rule, $assigned->source);
    }

    public function testANonMatchingRuleFallsThroughToTheSplit(): void
    {
        $assigned = $this->resolver(
            [$this->row('rule', self::RULED, ruleId: 'r1'), $this->row('split', self::ARM_ONE, weight: 100)],
            rules: [$this->rule('r1', priority: 1, matches: false)],
        )->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::ARM_ONE, $assigned->strategy->versionId);
        self::assertSame(StrategyAssignmentSource::Split, $assigned->source);
    }

    /**
     * The whole rung, never part of it. A half-evaluated list would pick a
     * strategy by accident of ordering, which is worse than picking none.
     */
    public function testAnUnavailableScopeSkipsTheEntireRuleRung(): void
    {
        $assigned = $this->resolver(
            [$this->row('rule', self::RULED, ruleId: 'r1'), $this->row('split', self::ARM_ONE, weight: 100)],
            rules: [$this->rule('r1', priority: 1, matches: true)],
            scopeFails: true,
        )->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(StrategyAssignmentSource::Split, $assigned->source);
    }

    public function testAZeroWeightArmIsNeverChosen(): void
    {
        $assigned = $this->resolver([
            $this->row('split', self::ARM_ONE, weight: 0),
            $this->row('split', self::ARM_TWO, weight: 100),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::ARM_TWO, $assigned->strategy->versionId);
    }

    public function testWeightsSummingToZeroFallThrough(): void
    {
        $assigned = $this->resolver([
            $this->row('split', self::ARM_ONE, weight: 0),
            $this->row('split', self::ARM_TWO, weight: 0),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNull($assigned);
    }

    public function testWeightsNeedNotSumToOneHundred(): void
    {
        $assigned = $this->resolver([
            $this->row('split', self::ARM_ONE, weight: 1),
            $this->row('split', self::ARM_TWO, weight: 4),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertContains($assigned->strategy->versionId, [self::ARM_ONE, self::ARM_TWO]);
    }

    public function testNoRowsAtAllMeansNoAssignment(): void
    {
        $assigned = $this->resolver([])
            ->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNull($assigned);
    }

    /**
     * Rung 4 is the configuration key and is refused loudly when it dangles.
     * An assignment row that dangles is the merchant's own configuration too,
     * made in our own UI, so it must not degrade to a different posture.
     */
    public function testAnArchivedTargetSurfacesAsUnknownStrategy(): void
    {
        $this->expectException(\MerchantQuoteAgentPlugin\Strategy\UnknownStrategy::class);

        $this->resolver(
            [$this->row('pin', self::PINNED, customerId: self::CUSTOMER)],
            archived: true,
        )->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());
    }

    /** @param list<StrategyAssignment> $rows @param list<RuleEntity> $rules */
    private function resolver(
        array $rows,
        array $rules = [],
        bool $scopeFails = false,
        bool $archived = false,
    ): StrategyAssignmentResolver {
        $strategies = $this->createMock(StrategyResolver::class);
        $strategies->method('resolve')->willReturnCallback(
            static function (string $strategyId) use ($archived): ResolvedStrategy {
                if ($archived) {
                    throw \MerchantQuoteAgentPlugin\Strategy\UnknownStrategy::archived($strategyId);
                }

                return new ResolvedStrategy($strategyId, 'prompt for ' . $strategyId);
            },
        );

        $scopes = $this->createMock(QuoteRuleScopeFactory::class);
        if ($scopeFails) {
            $scopes->method('forQuote')->willThrowException(new RuleScopeUnavailable('no address'));
        } else {
            $scopes->method('forQuote')->willReturn(
                new CartRuleScope(new Cart('token'), $this->createMock(SalesChannelContext::class)),
            );
        }

        return new StrategyAssignmentResolver(
            $this->repository($rows, 'merchant_quote_agent_strategy_assignment'),
            $this->repository($rules, 'rule'),
            $strategies,
            $scopes,
            new NullLogger(),
        );
    }

    private function row(
        string $kind,
        string $strategyId,
        ?string $customerId = null,
        ?string $ruleId = null,
        ?int $weight = null,
        ?string $salesChannelId = null,
    ): StrategyAssignment {
        $row = new StrategyAssignment();
        $row->setUniqueIdentifier($kind . $strategyId . ($ruleId ?? ''));
        $row->id = $kind . $strategyId;
        $row->kind = $kind;
        $row->strategyId = $strategyId;
        $row->customerId = $customerId;
        $row->ruleId = $ruleId;
        $row->weight = $weight;
        $row->salesChannelId = $salesChannelId;

        return $row;
    }

    private function rule(string $id, int $priority, bool $matches): RuleEntity
    {
        $rule = new RuleEntity();
        $rule->setUniqueIdentifier($id);
        $rule->setId($id);
        $rule->setPriority($priority);
        $rule->setPayload(new class ($matches) extends Rule {
            public function __construct(private readonly bool $matches)
            {
                parent::__construct();
            }

            public function match(RuleScope $scope): bool
            {
                return $this->matches;
            }

            public function getConstraints(): array
            {
                return [];
            }
        });

        return $rule;
    }

    /** @param list<object> $entities */
    private function repository(array $entities, string $definition): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                $definition,
                \count($entities),
                $definition === 'rule' ? new RuleCollection($entities) : new EntityCollection($entities),
                null,
                $criteria,
                $context,
            ),
        );

        return $repository;
    }
}
```

Two things this mock repository cannot prove, and does not try to: it ignores the `Criteria`, so it cannot show that the pin query filtered on `customer_id`, and it returns rules in array order, so it cannot show the resolver asked for `priority DESC`. Both are covered instead by the test rows themselves — the "another customer" and "highest priority" cases fail if the resolver filters or sorts in PHP incorrectly. Sorting is asserted at the database level by the integration test in Task 8.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter StrategyAssignmentResolverTest`
Expected: FAIL with `Class "MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentResolver" not found`.

- [ ] **Step 3: Write `AssignedStrategy`**

Create `src/Strategy/AssignedStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * What the ladder decided: the prompt to send, and which rung decided it.
 *
 * The source travels with the strategy rather than being recomputed later,
 * because by the time a decision row is written the rows the ladder read may
 * already have changed.
 */
final readonly class AssignedStrategy
{
    public function __construct(
        public ResolvedStrategy $strategy,
        public StrategyAssignmentSource $source,
    ) {}
}
```

- [ ] **Step 4: Write the resolver**

Create `src/Strategy/StrategyAssignmentResolver.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Which strategy this negotiation should use, from the assignment ladder.
 *
 * Four rungs, in this order: a customer pin, the highest-priority matching
 * rule, a weighted split, and -- by returning null -- the sales-channel
 * configuration key the caller already resolved. Each rung resolves
 * independently, so a GLOBAL pin beats a channel-specific rule: the ladder
 * order is the precedence, the sales channel is only a filter within a rung.
 *
 * Uncached, once per pass, alongside the reads ServicingPreflight already
 * makes. The rule rung is lazy -- no quote read, no context restore and no
 * cart conversion happen unless rung 2 actually has rows.
 *
 * A rule that cannot be evaluated skips the WHOLE rung and logs a warning: a
 * missing shipping address is a data condition, not a misconfiguration, and
 * escalating every such quote to a human is worse for the merchant than
 * servicing it on the next rung. A half-evaluated list would instead pick by
 * accident of ordering. Because that fall-through is invisible in the
 * negotiation itself, the chosen rung is recorded on the decision row.
 *
 * An assignment naming a missing or archived strategy is the opposite case and
 * is NOT swallowed: UnknownStrategy propagates, ServicingPreflight turns it
 * into the escalation it already turns a dangling config key into. That
 * dangling row is configuration the merchant made in our own UI, and silently
 * negotiating with a different posture than they configured is the failure
 * QuoteAgentSettingsReader exists to refuse.
 */
final readonly class StrategyAssignmentResolver
{
    public function __construct(
        private EntityRepository $assignments,
        private EntityRepository $rules,
        private StrategyResolver $strategies,
        private QuoteRuleScopeFactory $scopes,
        private LoggerInterface $logger,
    ) {}

    /** @throws UnknownStrategy */
    public function assign(
        string $quoteId,
        string $customerId,
        string $salesChannelId,
        Context $context,
    ): ?AssignedStrategy {
        $strategyId = $this->pinned($customerId, $salesChannelId, $context);

        if ($strategyId !== null) {
            return $this->resolve($strategyId, StrategyAssignmentSource::Pin, $context);
        }

        $strategyId = $this->ruled($quoteId, $salesChannelId, $context);

        if ($strategyId !== null) {
            return $this->resolve($strategyId, StrategyAssignmentSource::Rule, $context);
        }

        $strategyId = $this->split($customerId, $salesChannelId, $context);

        if ($strategyId !== null) {
            return $this->resolve($strategyId, StrategyAssignmentSource::Split, $context);
        }

        return null;
    }

    /**
     * MySQL treats NULLs as distinct in a unique index, so two GLOBAL pins for
     * one customer are accepted by the schema. Sorting a non-null sales
     * channel first makes the channel-specific pin win, and orders the
     * duplicate case deterministically rather than by row order.
     */
    private function pinned(string $customerId, string $salesChannelId, Context $context): ?string
    {
        if ($customerId === '') {
            return null;
        }

        $criteria = $this->scoped(StrategyAssignmentSource::Pin->value, $salesChannelId);
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addSorting(new FieldSorting('salesChannelId', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $row = $this->assignments->search($criteria, $context)->first();

        return $row instanceof StrategyAssignment ? $row->strategyId : null;
    }

    private function ruled(string $quoteId, string $salesChannelId, Context $context): ?string
    {
        $rows = $this->assignments
            ->search($this->scoped(StrategyAssignmentSource::Rule->value, $salesChannelId), $context)
            ->getElements();

        /** @var array<string, string> $byRule */
        $byRule = [];

        foreach ($rows as $row) {
            if ($row instanceof StrategyAssignment && $row->ruleId !== null) {
                $byRule[$row->ruleId] = $row->strategyId;
            }
        }

        if ($byRule === []) {
            return null;
        }

        try {
            $scope = $this->scopes->forQuote($quoteId, $context);
        } catch (RuleScopeUnavailable $e) {
            $this->logger->warning('This quote could not be turned into a rule scope, so every rule-based strategy '
            . 'assignment was skipped for it and the next rung of the ladder decided instead.', [
                'quoteId' => $quoteId,
                'salesChannelId' => $salesChannelId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $criteria = new Criteria(array_keys($byRule));
        $criteria->addSorting(new FieldSorting('priority', FieldSorting::DESCENDING));

        foreach ($this->rules->search($criteria, $context)->getElements() as $rule) {
            if (!$rule instanceof RuleEntity) {
                continue;
            }

            if ($rule->getPayload()?->match($scope) === true) {
                return $byRule[$rule->getId()] ?? null;
            }
        }

        return null;
    }

    /**
     * Cumulative weights over a 10000-bucket hash, so weights need not sum to
     * 100 -- 1 and 4 mean the same as 20 and 80. Ordered by strategy id rather
     * than by insertion, so re-creating an arm does not reshuffle the others.
     *
     * Changing any weight DOES reshuffle which companies land in which arm.
     * That is inherent to a stateless split and is why the dashboard reports
     * N per strategy rather than assuming a stable cohort.
     */
    private function split(string $customerId, string $salesChannelId, Context $context): ?string
    {
        $criteria = $this->scoped(StrategyAssignmentSource::Split->value, $salesChannelId);
        $criteria->addSorting(new FieldSorting('strategyId', FieldSorting::ASCENDING));

        $arms = [];
        $total = 0;

        foreach ($this->assignments->search($criteria, $context)->getElements() as $row) {
            if ($row instanceof StrategyAssignment && $row->weight !== null && $row->weight > 0) {
                $arms[] = $row;
                $total += $row->weight;
            }
        }

        if ($total === 0) {
            return null;
        }

        $target = intdiv(SplitBucket::of($customerId, $salesChannelId) * $total, 10000);
        $seen = 0;

        foreach ($arms as $arm) {
            $seen += (int) $arm->weight;

            if ($target < $seen) {
                return $arm->strategyId;
            }
        }

        return $arms[\count($arms) - 1]->strategyId;
    }

    /**
     * A row naming this channel or naming none. Both are read in one query and
     * separated by the caller's own sorting, rather than by a second query for
     * the global fallback.
     */
    private function scoped(string $kind, string $salesChannelId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('kind', $kind));
        $criteria->addFilter(new EqualsAnyFilter('salesChannelId', [$salesChannelId, null]));

        return $criteria;
    }

    /** @throws UnknownStrategy */
    private function resolve(string $strategyId, StrategyAssignmentSource $source, Context $context): AssignedStrategy
    {
        return new AssignedStrategy($this->strategies->resolve($strategyId, $context), $source);
    }
}
```

If `EqualsAnyFilter` rejects a `null` element, replace `scoped()`'s filter with a `MultiFilter(MultiFilter::CONNECTION_OR, [new EqualsFilter('salesChannelId', $salesChannelId), new EqualsFilter('salesChannelId', null)])` and import `Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter`. Run the tests either way.

- [ ] **Step 5: Register the service**

In `src/Resources/config/services.php`, after the `QuoteRuleScopeFactory` registration from Task 4:

```php
    $services->set(StrategyAssignmentResolver::class)->args([
        service('merchant_quote_agent_strategy_assignment.repository'),
        service('rule.repository'),
        service(StrategyResolver::class),
        service(QuoteRuleScopeFactory::class),
        service('logger'),
    ]);
```

Add the `use` import alphabetically.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer test -- --filter StrategyAssignmentResolverTest`
Expected: PASS, 11 tests.

- [ ] **Step 7: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:filesize
git add src/Strategy/AssignedStrategy.php src/Strategy/StrategyAssignmentResolver.php src/Resources/config/services.php tests/Unit/Strategy/StrategyAssignmentResolverTest.php
git commit -m "feat(strategy): walk the assignment ladder

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Call the ladder from the servicing preflight

**Files:**
- Modify: `src/Servicing/ServicingPreflight.php:56-110`
- Modify: `src/Resources/config/services.php:761-766`
- Test: `tests/Unit/Servicing/ServicingPreflightTest.php` (extend the existing file; create it only if it does not exist)

**Interfaces:**
- Consumes: `StrategyAssignmentResolver::assign()` and `AssignedStrategy` (Task 5), `QuoteAgentSettings::withStrategy()` (Task 1).
- Produces: no new public API. `ServicingPreflight::check()` keeps its existing signature and return type `?QuoteAgentSettings`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Servicing/ServicingPreflightTest.php` (match the existing file's fixture helpers; do not invent new ones):

```php
    public function testAnAssignedStrategyReplacesTheConfiguredOne(): void
    {
        // Build the preflight with an assignment resolver returning
        // new AssignedStrategy(new ResolvedStrategy('cafe...', 'hold firm'), StrategyAssignmentSource::Split)
        // and a settings source returning settings whose strategyPrompt is 'be nice'.
        $settings = $preflight->check($gateway, $snapshot, $context);

        self::assertNotNull($settings);
        self::assertSame('hold firm', $settings->strategyPrompt);
        self::assertSame(StrategyAssignmentSource::Split, $settings->strategyAssignmentSource);
    }

    public function testNoAssignmentLeavesTheConfiguredStrategyAlone(): void
    {
        // Same, with the resolver returning null.
        $settings = $preflight->check($gateway, $snapshot, $context);

        self::assertNotNull($settings);
        self::assertSame('be nice', $settings->strategyPrompt);
        self::assertSame(StrategyAssignmentSource::Config, $settings->strategyAssignmentSource);
    }

    /**
     * A dangling assignment is a misconfiguration the merchant made in our own
     * UI, so it takes the path a dangling config key already takes: escalate,
     * do not negotiate with a posture nobody chose.
     */
    public function testADanglingAssignmentEscalates(): void
    {
        // Resolver throws UnknownStrategy::archived(...).
        $settings = $preflight->check($gateway, $snapshot, $context);

        self::assertNull($settings);
        self::assertSame([QuoteEscalationReason::NotConfigured], $escalator->reasons);
    }

    public function testTheAgentBeingOffSkipsTheLadderEntirely(): void
    {
        // Settings source returns null. The resolver must not be called: a
        // paused agent must not pay for a quote read and a cart conversion.
        $settings = $preflight->check($gateway, $snapshot, $context);

        self::assertNull($settings);
        self::assertSame(0, $resolver->calls);
    }
```

Replace each comment with the concrete construction the existing test file already uses for its other cases. If the file has no existing fixtures, build the preflight directly with `createMock()` doubles for `QuoteAgentSettingsSource`, `QuoteEscalator`, `DecisionRecorder` and `StrategyAssignmentResolver`, a `NullLogger`, and the `QuoteSnapshot` fixture from `tests/Unit/Bridge/` if one exists there.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter ServicingPreflightTest`
Expected: FAIL — the constructor takes four arguments, not five.

- [ ] **Step 3: Add the collaborator and the call**

In `src/Servicing/ServicingPreflight.php`, add the constructor parameter last:

```php
        private StrategyAssignmentResolver $assignments,
```

Then, after the `if ($settings === null)` block and before `return $settings;`, replace the tail of `check()` with:

```php
        if ($settings === null) {
            $this->logger->debug('The quote agent is switched off for this sales channel.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);

            return null;
        }

        // After the off-switch, never before it: a paused agent must not pay
        // for the quote read and the cart conversion the rule rung can cost.
        try {
            $assigned = $this->assignments->assign(
                $snapshot->identity->quoteId,
                $snapshot->identity->customerId,
                $snapshot->identity->salesChannelId,
                Context::createDefaultContext(),
            );
        } catch (UnknownStrategy $e) {
            $this->logger->error('A strategy assignment points at a strategy that is missing or archived, so this '
            . 'quote was escalated instead of serviced with a posture nobody chose.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
                'problem' => $e->getMessage(),
            ]);

            $this->escalator->escalate($gateway, $snapshot, QuoteEscalationReason::NotConfigured);
            $this->record($snapshot, $context, [$e->getMessage()]);

            return null;
        }

        return $assigned === null ? $settings : $settings->withStrategy($assigned->strategy, $assigned->source);
    }
```

Add imports for `MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentResolver`, `MerchantQuoteAgentPlugin\Strategy\UnknownStrategy` and `Shopware\Core\Framework\Context`.

Extend the class docblock with one paragraph: the preflight now also answers *which strategy*, not only *whether and with what settings*, and the ladder is consulted after the off-switch so a paused agent pays nothing for it.

- [ ] **Step 4: Register the new argument**

In `src/Resources/config/services.php`, add to the `ServicingPreflight::class` args list at line 761, last:

```php
        service(StrategyAssignmentResolver::class),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `composer test -- --filter 'ServicingPreflightTest|StrategyAssignmentResolverTest'`
Expected: PASS.

- [ ] **Step 6: Run the whole unit suite**

Run: `composer test`
Expected: PASS. Any failure here is a collaborator whose constructor changed — fix the call site, not the test's intent.

- [ ] **Step 7: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck
git add src/Servicing/ServicingPreflight.php src/Resources/config/services.php tests/Unit/Servicing/ServicingPreflightTest.php
git commit -m "feat(servicing): resolve the strategy per quote, not per channel

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Record which rung chose the strategy

**Files:**
- Create: `src/Migration/Migration1789600001AddAssignmentSourceToDecision.php`
- Modify: `src/Audit/QuoteDecisionRecord.php:240-243`
- Modify: `src/Audit/DecisionDraft.php:105-107`
- Modify: `src/Audit/DecisionRecorder.php:139-151`
- Modify: `src/Negotiation/NegotiationPipeline.php:233-237`
- Modify: `src/Audit/Export/AnonymizedDecision.php:58,118`
- Test: `tests/Unit/Audit/AddAssignmentSourceToDecisionMigrationTest.php`, and extend `tests/Unit/Audit/` recorder and export tests

**Interfaces:**
- Consumes: `StrategyAssignmentSource` (Task 1), `QuoteAgentSettings::$strategyAssignmentSource` (Task 1).
- Produces: `QuoteDecisionRecord::$strategyAssignmentSource: ?string`; `DecisionRecorder::recordDecision(NegotiationDecision $decision, float $maxDiscountPercent, ?string $strategyVersionId = null, ?StrategyAssignmentSource $strategyAssignmentSource = null): void`.

- [ ] **Step 1: Write the failing migration test**

Create `tests/Unit/Audit/AddAssignmentSourceToDecisionMigrationTest.php`, modelled line for line on the existing `tests/Unit/Audit/AddStrategyVersionToDecisionMigrationTest.php` — read that file first and mirror its structure, swapping `strategy_version_id` for `strategy_assignment_source` and `1789400003` for `1789600001`.

- [ ] **Step 2: Run it to verify it fails**

Run: `composer test -- --filter AddAssignmentSourceToDecisionMigrationTest`
Expected: FAIL, migration class not found.

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1789600001AddAssignmentSourceToDecision.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Which rung of the assignment ladder chose this pass's strategy.
 *
 * It explains `strategy_version_id` rather than replacing it, and it is the
 * only way a merchant sees that a rule they configured never matched: the
 * ladder falls through silently on purpose, so a row reading `config` where
 * the merchant expected `rule` is the signal.
 *
 * Null on every row written before the ladder existed, and on any pass that
 * ran with no strategy at all. No foreign key and no enum column: the values
 * are StrategyAssignmentSource's four cases, and a widened enum must never be
 * a schema migration on an audit table.
 */
class Migration1789600001AddAssignmentSourceToDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789600001;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'strategy_assignment_source',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `strategy_assignment_source` VARCHAR(16) NULL;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The column is additive.
    }
}
```

- [ ] **Step 4: Add the field to the record and the draft**

In `src/Audit/QuoteDecisionRecord.php`, after `strategyVersionId`:

```php
    /** Which rung of the ladder chose that version — see StrategyAssignmentSource. */
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $strategyAssignmentSource = null;
```

In `src/Audit/DecisionDraft.php`, after `strategyVersionId`:

```php
    /** Which rung of the ladder chose that version — see StrategyAssignmentSource. */
    public ?string $strategyAssignmentSource = null;
```

- [ ] **Step 5: Thread it through the recorder and the pipeline**

In `src/Audit/DecisionRecorder.php`, extend `recordDecision()`:

```php
    public function recordDecision(
        NegotiationDecision $decision,
        float $maxDiscountPercent,
        ?string $strategyVersionId = null,
        ?StrategyAssignmentSource $strategyAssignmentSource = null,
    ): void {
        if ($this->draft === null) {
            return;
        }

        $this->draft->band = $decision->overall->value;
        $this->draft->maxDiscountPercent = $maxDiscountPercent;
        $this->draft->strategyVersionId = $strategyVersionId;
        $this->draft->strategyAssignmentSource = $strategyAssignmentSource?->value;
    }
```

In `src/Negotiation/NegotiationPipeline.php:233-237`, add the fourth argument:

```php
        $this->recorder->recordDecision(
            $decision,
            $settings->policy->price->maxDiscountPercent,
            $settings->strategyVersionId,
            $settings->strategyAssignmentSource,
        );
```

- [ ] **Step 6: Add it to the anonymized export**

In `src/Audit/Export/AnonymizedDecision.php`, add `'strategyAssignmentSource' => 'strategyAssignmentSource',` to the key map at line 58, and at line 118 emit it unpseudonymized:

```php
            'strategyAssignmentSource' => $record->strategyAssignmentSource,
```

Add a comment above it: the value is one of four fixed words describing plugin configuration, not a shop identifier, so pseudonymizing it would destroy the only thing it is for while protecting nothing.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `composer test`
Expected: PASS. The export test will likely fail first on a changed column count or row shape — update its expectation to include the new key, and check the export's documented column list in `src/Command/DecisionExportCommand.php` for a header that also needs the new name.

- [ ] **Step 8: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck
git add src/Migration/Migration1789600001AddAssignmentSourceToDecision.php src/Audit/ src/Negotiation/NegotiationPipeline.php tests/Unit/Audit/
git commit -m "feat(audit): record which rung chose the strategy

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Prove it on a real shop

**Files:**
- Create: `tests/Integration/QuoteRuleScopeTest.php`
- Modify: `tests/Integration/GatewayWiringTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-7.
- Produces: nothing.

This task is the point of the whole design. Everything above is this plan's reading of SwagCommercial; this is the part that finds out. Run it on a shop with SwagCommercial installed — the parity shop `agenticquote-shoelscher.eu-core-1.shopdev.de`, per the access notes.

- [ ] **Step 1: Extend the wiring test**

In `tests/Integration/GatewayWiringTest.php`, find where the four `CommercialAvailability` service ids are resolved against the container and add `QUOTE_TO_CART_CONVERTER` to the same list. Mirror the file's existing assertion exactly; do not add a differently-shaped one.

- [ ] **Step 2: Write the scope test**

Create `tests/Integration/QuoteRuleScopeTest.php`. It must, using the shop's real container and the existing quote fixtures in `tests/Integration/` (read `AddProductAndRecalculateTest.php` first for how a quote with line items is seeded):

1. Seed a quote with at least one product line item and a customer with an active shipping address.
2. Resolve `QuoteRuleScopeFactory` from the container and call `forQuote()`.
3. Assert the result is a `CartRuleScope` whose cart has the seeded line items.
4. Build a real `GoodsPriceRule` (`Shopware\Core\Checkout\Cart\Rule\GoodsPriceRule`) with an amount below the quote's goods total and operator `>=`, and assert `match()` returns `true` against that scope.
5. Build a second one with an amount above the total and assert `match()` returns `false`.

Step 4 is the load-bearing assertion: it is what distinguishes a scope that works from a scope that silently answers `false` to every cart condition. A test that only asserts `instanceof CartRuleScope` would pass against a scope with an empty cart and prove nothing.

- [ ] **Step 3: Write the assignment end-to-end test**

In the same file, add a test that seeds a rule assignment row and asserts `StrategyAssignmentResolver::assign()` returns `StrategyAssignmentSource::Rule` with the seeded strategy. Seed two rules with different `priority` values that both match, and assert the higher-priority one wins — this is the only place the `priority DESC` sort is actually proven, since the unit test's mock repository ignores the `Criteria`.

- [ ] **Step 4: Run the integration suite on the shop**

Per the access notes, from the plugin directory inside the shop:

```bash
SHOPWARE_ROOT=/home/shoelscher/files/agenticquote \
  /home/shoelscher/files/agenticquote/vendor/bin/phpunit \
  -c phpunit.integration.xml.dist --filter 'QuoteRuleScopeTest|GatewayWiringTest'
```

Expected: PASS. Two known shop-side obstacles apply — `config/packages/zz-ucp-test-agent.yaml` must be moved aside for the run under a shell `trap`, and every product quote line on that shop is 0% tax, which does not affect this test but does mean a goods-total assertion must use the value the fixture actually produces rather than an assumed gross figure.

- [ ] **Step 5: Commit**

```bash
composer format && composer lint && composer typecheck
git add tests/Integration/QuoteRuleScopeTest.php tests/Integration/GatewayWiringTest.php
git commit -m "test(integration): prove a quote builds a working cart rule scope

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Not in this plan

The admin tab — three grids, snippets, the `assignment` spread column on the per-strategy measures table — is PR 2 in the spec's landing order and gets its own plan. Until it exists, assignment rows are created through the admin API, which is what makes every task above testable now.
