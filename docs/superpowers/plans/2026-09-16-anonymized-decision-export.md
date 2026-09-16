# Anonymized Decision Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `bin/console merchant-quote-agent:export --from --to [--include-comments]` writes the plugin's decision records to stdout as anonymized JSONL, with a test that proves no raw identifier survives.

**Architecture:** An allowlist mapper (`AnonymizedDecision`) builds each output row field by field from `QuoteDecisionRecord`, so a column added later cannot leak by omission; a reflection test fails when the entity gains a field the mapper has not classified. Shop-local UUIDs become HMAC pseudonyms under a per-shop salt held in `system_config` and created on first export. Every free-text field — including `rawProposal`, `violations` and the model's clarification questions, not only `replyToBuyer` — is withheld unless `--include-comments` is passed.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL (`RepositoryIterator`, `RangeFilter`), Symfony Console, PHPUnit.

**Spec:** [`docs/superpowers/specs/2026-09-16-anonymized-decision-export-design.md`](../specs/2026-09-16-anonymized-decision-export-design.md)

## Global Constraints

- `declare(strict_types=1)` in every file. Mago analyze runs at full strictness — no `mixed` escapes, no unsafe casts.
- Gate thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines per file.
- Target PHP 8.3. Do not use a DAL attribute argument or core API newer than the floor `CoreFloorCompatibilityTest` pins.
- `echo`/`var_dump`/`print_r` are blocked by Mago `no-debug-symbols` in application code but **allowed in commands** — writing JSONL through `OutputInterface` is what this uses anyway.
- Never read `system_config` from application code — except plugin-owned key material, which follows `A2cnKeyStore`. `ExportPseudonym` is that exception and its docblock must say so.
- Commit messages end with: `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`
- Do not push, do not open a PR, do not comment on the issue.
- Run unit tests with `composer run test`; the integration suite with `composer run test:integration` (needs `merchant-quote-shop`).
- Use `/usr/bin/git` explicitly — the shell wrapper refuses bare `git` in this worktree.

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Audit/Export/ExportPseudonym.php` (create) | Reads or creates the per-shop salt in `system_config`; turns one id into one pseudonym. |
| `src/Audit/Export/AnonymizedDecision.php` (create) | The allowlist mapper and its field classification table. |
| `src/Command/DecisionExportCommand.php` (create) | Options, date range, iteration, JSONL to stdout, notices to stderr. |
| `src/Resources/config/services.php` (modify) | Registers the command with `->tag('console.command')`. |
| `tests/Unit/Audit/Export/ExportPseudonymTest.php` (create) | Salt reuse, salt creation, stability, per-shop difference. |
| `tests/Unit/Audit/Export/AnonymizedDecisionTest.php` (create) | Field-by-field behaviour of the mapper, both flag states. |
| `tests/Unit/Audit/Export/ExportFieldCoverageTest.php` (create) | Every entity field is classified; the mapper emits what the table claims. |
| `tests/Unit/Command/DecisionExportCommandTest.php` (create) | Option validation and the exclusion notice. |
| `tests/Integration/DecisionExportTest.php` (create) | The no-raw-identifier grep against the real DAL, with its controls. |
| `docs/for-merchants.md` (modify) | What leaves and what does not, in merchant words. |
| `README.md` (modify) | One line in the documentation list. |

---

### Task 1: The per-shop pseudonym

**Files:**
- Create: `src/Audit/Export/ExportPseudonym.php`
- Test: `tests/Unit/Audit/Export/ExportPseudonymTest.php`

**Interfaces:**
- Consumes: `Shopware\Core\System\SystemConfig\SystemConfigService`
- Produces:
  - `ExportPseudonym::CONFIG_KEY` — `'MerchantQuoteAgentPlugin.export.pseudonymSalt'`
  - `ExportPseudonym::__construct(string $salt)`
  - `ExportPseudonym::forShop(SystemConfigService $systemConfig): self`
  - `ExportPseudonym::of(?string $id): ?string`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Audit/Export/ExportPseudonymTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ExportPseudonym::class)]
final class ExportPseudonymTest extends TestCase
{
    public function testTheSameIdPseudonymizesToTheSameStringUnderTheSameSalt(): void
    {
        $pseudonym = new ExportPseudonym('a-salt');

        self::assertSame($pseudonym->of('0191d3d0a0b071bd9c1a0d9d1a3f9f01'), $pseudonym->of('0191d3d0a0b071bd9c1a0d9d1a3f9f01'));
    }

    public function testADifferentSaltGivesADifferentPseudonymForTheSameId(): void
    {
        $id = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

        self::assertNotSame((new ExportPseudonym('one'))->of($id), (new ExportPseudonym('two'))->of($id));
    }

    public function testThePseudonymIsNotTheIdAndIsFixedWidthHex(): void
    {
        $id = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';
        $pseudonym = (new ExportPseudonym('a-salt'))->of($id);

        self::assertNotNull($pseudonym);
        self::assertNotSame($id, $pseudonym);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pseudonym);
    }

    public function testAnAbsentIdStaysAbsent(): void
    {
        $pseudonym = new ExportPseudonym('a-salt');

        self::assertNull($pseudonym->of(null));
        self::assertNull($pseudonym->of(''));
    }

    public function testAStoredSaltIsReusedRatherThanReplaced(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn('the-stored-salt');
        $config->expects(self::never())->method('set');

        self::assertSame(
            (new ExportPseudonym('the-stored-salt'))->of('abc'),
            ExportPseudonym::forShop($config)->of('abc'),
        );
    }

    public function testAMissingSaltIsCreatedAndPersistedOnce(): void
    {
        $written = null;
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn(null);
        $config
            ->expects(self::once())
            ->method('set')
            ->with(ExportPseudonym::CONFIG_KEY, self::callback(static function (mixed $value) use (&$written): bool {
                $written = $value;

                return \is_string($value) && \strlen($value) === 64;
            }));

        $pseudonym = ExportPseudonym::forShop($config);

        self::assertIsString($written);
        self::assertSame((new ExportPseudonym($written))->of('abc'), $pseudonym->of('abc'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter ExportPseudonymTest`
Expected: FAIL — `MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym` not found.

- [ ] **Step 3: Write the implementation**

Create `src/Audit/Export/ExportPseudonym.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Turns one shop-local id into a stable string that means nothing outside this
 * shop. The same customer is the same pseudonym in every export this shop
 * makes, and a different pseudonym in every other shop's — which is what lets
 * repeat-buyer effects be studied without anyone learning who the buyer is.
 *
 * The salt lives in `system_config`, deliberately OUTSIDE the
 * `MerchantQuoteAgentPlugin.config.*` prefix the admin settings form renders,
 * for the reason A2cnKeyStore gives for the signing key: material like this
 * must not appear in a config screen where it gets copied into a support
 * ticket. AGENTS.md's "never read system_config directly" rule is about
 * MERCHANT configuration, which goes through QuoteAgentSettingsReader; this is
 * plugin-owned key material the merchant never sets, so it follows
 * A2cnKeyStore instead.
 *
 * Unlike A2cnKeyStore, an absent salt is generated rather than refused. A
 * signing key generated twice by two racing requests silently invalidates
 * every act signed with the loser, which is why that class throws. A salt
 * generated twice costs at most the linkability between two exports, it cannot
 * race in practice (one console process, run by hand), and refusing would lock
 * every install made before this version out of exporting at all until it was
 * reinstalled.
 *
 * Deleting the config row therefore breaks the link between past and future
 * exports — a valid way to sever it on purpose, and documented as such in
 * docs/for-merchants.md.
 */
final readonly class ExportPseudonym
{
    public const CONFIG_KEY = 'MerchantQuoteAgentPlugin.export.pseudonymSalt';

    /** Half a sha256, which is plenty against collision at any volume this table reaches, and keeps a JSONL line readable. */
    private const WIDTH = 32;

    public function __construct(
        private string $salt,
    ) {}

    /** @throws \Random\RandomException */
    public static function forShop(SystemConfigService $systemConfig): self
    {
        $stored = $systemConfig->get(self::CONFIG_KEY);

        if (\is_string($stored) && $stored !== '') {
            return new self($stored);
        }

        $salt = bin2hex(random_bytes(32));
        $systemConfig->set(self::CONFIG_KEY, $salt);

        return new self($salt);
    }

    public function of(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $id, $this->salt), 0, self::WIDTH);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter ExportPseudonymTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Check the gates for this file**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: all clean. If `mago fmt --check` complains, run `composer run format` and re-check.

- [ ] **Step 6: Commit**

```bash
/usr/bin/git add src/Audit/Export/ExportPseudonym.php tests/Unit/Audit/Export/ExportPseudonymTest.php
/usr/bin/git commit -m "feat(export): pseudonymize ids with a per-shop salt (#34)

The salt lives in system_config outside the admin-rendered config
prefix, and is created on first use rather than refused when absent --
a salt cannot fail the way a signing key can, and refusing would lock
every pre-existing install out of exporting.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: The allowlist mapper

**Files:**
- Create: `src/Audit/Export/AnonymizedDecision.php`
- Test: `tests/Unit/Audit/Export/AnonymizedDecisionTest.php`
- Test: `tests/Unit/Audit/Export/ExportFieldCoverageTest.php`

**Interfaces:**
- Consumes: `ExportPseudonym::of()` from Task 1; `MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord`
- Produces:
  - `AnonymizedDecision::of(QuoteDecisionRecord $record, ExportPseudonym $pseudonym, bool $freeText): array<string, mixed>`
  - `AnonymizedDecision::PSEUDONYMIZED` — `array<string, string>`, entity property => export key
  - `AnonymizedDecision::VERBATIM`, `::RESHAPED`, `::FREE_TEXT`, `::DROPPED` — `list<string>` of entity property names

Read the spec's "Decisions 2, 3 and 4" before writing this file; every choice below is argued there.

- [ ] **Step 1: Write the failing mapper test**

Create `tests/Unit/Audit/Export/AnonymizedDecisionTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnonymizedDecision::class)]
final class AnonymizedDecisionTest extends TestCase
{
    private const CUSTOMER_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f02';

    public function testEveryIdIsReplacedByItsPseudonymUnderAShorterName(): void
    {
        $row = self::export(self::record());

        self::assertSame(self::pseudonym()->of(self::CUSTOMER_ID), $row['customer']);
        self::assertNotContains(self::CUSTOMER_ID, array_map(strval(...), array_filter($row, is_scalar(...))));
        self::assertArrayNotHasKey('customerId', $row);
        self::assertArrayHasKey('quote', $row);
        self::assertArrayHasKey('salesChannel', $row);
        self::assertArrayHasKey('revision', $row);
        self::assertArrayHasKey('strategyVersion', $row);
    }

    public function testTheQuoteNumberNeverLeaves(): void
    {
        $row = self::export(self::record());

        self::assertArrayNotHasKey('quoteNumber', $row);
        self::assertStringNotContainsString('QU10042', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testTheAnalyticFieldsAreKeptAsTheyAre(): void
    {
        $row = self::export(self::record());

        self::assertSame('offered', $row['outcome']);
        self::assertSame('grant', $row['band']);
        self::assertSame(12.5, $row['discountPercentGranted']);
        self::assertSame('EUR', $row['currencyIso']);
        self::assertSame('unparsable-host', $row['modelHost']);
        self::assertSame('accepted', $row['terminalState']);
        self::assertSame(['updateQuote', 'recalculate'], $row['writes']);
    }

    public function testFreeTextIsAbsentByDefaultAndPresentWithTheFlag(): void
    {
        $withheld = json_encode(self::export(self::record()), JSON_THROW_ON_ERROR);
        $included = json_encode(self::export(self::record(), freeText: true), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('anna.mueller@acme.example', $withheld);
        self::assertStringContainsString('anna.mueller@acme.example', $included);
    }

    public function testTheRawProposalIsGatedByTheSameFlagAsTheReply(): void
    {
        $default = self::export(self::record());
        $included = self::export(self::record(), freeText: true);

        self::assertArrayNotHasKey('rawProposal', $default);
        self::assertArrayNotHasKey('replyToBuyer', $default);
        self::assertArrayNotHasKey('violations', $default);
        self::assertArrayHasKey('rawProposal', $included);
        self::assertArrayHasKey('replyToBuyer', $included);
        self::assertArrayHasKey('violations', $included);
    }

    public function testTheStructuredAskSurvivesButTheModelsQuestionsDoNot(): void
    {
        $row = self::export(self::record());

        self::assertIsArray($row['interpretedAsks']);
        self::assertSame(['additionalDiscountPercent' => 15.0], $row['interpretedAsks']['price']);
        self::assertArrayNotHasKey('clarificationQuestions', $row['interpretedAsks']);
        self::assertArrayNotHasKey('humanReviewRequests', $row['interpretedAsks']);
    }

    public function testTheAccountHistoryBlockNeverLeavesEvenWithTheFlag(): void
    {
        foreach ([false, true] as $freeText) {
            $row = self::export(self::record(), $freeText);

            self::assertIsArray($row['historyReads']);
            self::assertSame(7, $row['historyReads']['quotesSeen']);
            self::assertSame(['orders'], $row['historyReads']['rounds']);
            self::assertStringNotContainsString('QU10042', json_encode($row['historyReads'], JSON_THROW_ON_ERROR));
        }
    }

    public function testAnErrorKeepsItsClassAndPlaceButNotItsMessage(): void
    {
        $default = self::export(self::record());
        $included = self::export(self::record(), freeText: true);

        self::assertSame(
            [['class' => 'RuntimeException', 'at' => '/srv/Foo.php:12']],
            $default['errorChain'],
        );
        self::assertIsArray($included['errorChain'][0]);
        self::assertArrayHasKey('message', $included['errorChain'][0]);
    }

    public function testEmptyColumnsStayNullRatherThanDisappearing(): void
    {
        $row = self::export(new QuoteDecisionRecord());

        self::assertNull($row['customer']);
        self::assertNull($row['outcome']);
        self::assertNull($row['interpretedAsks']);
        self::assertNull($row['historyReads']);
        self::assertNull($row['errorChain']);
        self::assertNull($row['terminalAt']);
    }

    public function testTimestampsAreIso8601(): void
    {
        $row = self::export(self::record());

        self::assertSame('2026-09-14T10:00:00+00:00', $row['terminalAt']);
    }

    /** @return array<string, mixed> */
    private static function export(QuoteDecisionRecord $record, bool $freeText = false): array
    {
        return AnonymizedDecision::of($record, self::pseudonym(), $freeText);
    }

    private static function pseudonym(): ExportPseudonym
    {
        return new ExportPseudonym('a-fixed-test-salt');
    }

    private static function record(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = '0191d3d0a0b071bd9c1a0d9d1a3f9f00';
        $record->quoteId = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';
        $record->customerId = self::CUSTOMER_ID;
        $record->salesChannelId = '0191d3d0a0b071bd9c1a0d9d1a3f9f03';
        $record->revisionVersionId = '0191d3d0a0b071bd9c1a0d9d1a3f9f04';
        $record->strategyVersionId = '0191d3d0a0b071bd9c1a0d9d1a3f9f05';
        $record->quoteNumber = 'QU10042';
        $record->currencyIso = 'EUR';
        $record->outcome = 'offered';
        $record->band = 'grant';
        $record->discountPercentGranted = 12.5;
        $record->modelHost = 'unparsable-host';
        $record->terminalState = 'accepted';
        $record->terminalAt = new \DateTimeImmutable('2026-09-14T10:00:00+00:00');
        $record->writes = ['updateQuote', 'recalculate'];
        $record->rawProposal = '{"message":"Dear Anna, anna.mueller@acme.example, ..."}';
        $record->replyToBuyer = 'Hello Anna Mueller (anna.mueller@acme.example), account 10042 ...';
        $record->violations = ['The buyer anna.mueller@acme.example insists on 30%.'];
        $record->interpretedAsks = [
            'price' => ['additionalDiscountPercent' => 15.0],
            'clarificationQuestions' => ['Does anna.mueller@acme.example mean per unit?'],
            'humanReviewRequests' => ['Account 10042 wants to speak to a person.'],
        ];
        $record->historyReads = [
            'quotesSeen' => 7,
            'rounds' => [['kind' => 'orders', 'productId' => 'deadbeef', 'result' => 'order 10009, quote QU10042, ...']],
        ];
        $record->errorChain = [
            ['class' => 'RuntimeException', 'message' => 'anna.mueller@acme.example refused', 'at' => '/srv/Foo.php:12'],
        ];

        return $record;
    }
}
```

- [ ] **Step 2: Write the failing coverage test**

Create `tests/Unit/Audit/Export/ExportFieldCoverageTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;

/**
 * The export is an allowlist, so a column added to QuoteDecisionRecord cannot
 * leak into an exported file. It could, however, be forgotten -- and a
 * merchant promise of "this is what leaves" is only kept if every column has
 * had a decision made about it. This test makes that decision mandatory: a new
 * field fails the build until it is named in one of the five lists.
 *
 * It also pins the lists against the mapper itself, so the classification
 * table cannot drift into a description of what the code used to do.
 */
#[CoversClass(AnonymizedDecision::class)]
final class ExportFieldCoverageTest extends TestCase
{
    public function testEveryEntityFieldIsClassifiedExactlyOnce(): void
    {
        $classified = [
            ...array_keys(AnonymizedDecision::PSEUDONYMIZED),
            ...AnonymizedDecision::VERBATIM,
            ...AnonymizedDecision::RESHAPED,
            ...AnonymizedDecision::FREE_TEXT,
            ...AnonymizedDecision::DROPPED,
        ];

        self::assertSame(
            array_unique($classified),
            $classified,
            'A QuoteDecisionRecord field is classified twice by the export.',
        );
        self::assertSame(
            [],
            array_values(array_diff(self::entityFieldNames(), $classified)),
            'A QuoteDecisionRecord field has no export classification. Add it to one of'
            . ' AnonymizedDecision\'s five lists and to the mapper, and say what it means'
            . ' in docs/for-merchants.md.',
        );
        self::assertSame(
            [],
            array_values(array_diff($classified, self::entityFieldNames())),
            'The export classifies a field QuoteDecisionRecord does not have.',
        );
    }

    public function testTheMapperEmitsEveryFieldItClaimsToAndNothingElse(): void
    {
        $row = AnonymizedDecision::of(new QuoteDecisionRecord(), new ExportPseudonym('salt'), freeText: true);

        $expected = [
            ...array_values(AnonymizedDecision::PSEUDONYMIZED),
            ...AnonymizedDecision::VERBATIM,
            ...AnonymizedDecision::RESHAPED,
            ...AnonymizedDecision::FREE_TEXT,
            'createdAt',
        ];

        sort($expected);
        $actual = array_keys($row);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /** @return list<string> */
    private static function entityFieldNames(): array
    {
        $fields = array_filter(
            (new \ReflectionClass(QuoteDecisionRecord::class))->getProperties(),
            static fn(\ReflectionProperty $property): bool => $property->getAttributes(Field::class) !== [],
        );

        return array_values(array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            $fields,
        ));
    }
}
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `composer run test -- --filter 'AnonymizedDecisionTest|ExportFieldCoverageTest'`
Expected: FAIL — `MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision` not found.

- [ ] **Step 4: Write the implementation**

Create `src/Audit/Export/AnonymizedDecision.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;

/**
 * One decision record as one line of the anonymized export.
 *
 * An ALLOWLIST, not a redactor, and that is the whole design. This table has
 * gained six columns in three weeks from four unrelated issues, none of which
 * had this export in view; under a denylist every one of them would have
 * shipped straight into a file a merchant had been told was anonymized, and
 * silently. Here a new column simply does not appear -- and
 * ExportFieldCoverageTest fails until someone says which of the five lists
 * below it belongs to, so it cannot be decided by omission either.
 *
 * FREE_TEXT covers more than the reply. `rawProposal` is json_encode() of the
 * model's answer, written by a model whose prompt carried the buyer's message
 * verbatim, so it can repeat a name, an address or a phone number the buyer
 * typed. `violations` holds the model's own escalation sentence on one path
 * (OfferProposer passes $response->escalationReason straight through) and
 * machine strings on the others, and export time cannot tell them apart, so
 * the column follows its worst path -- the analytic signal is in
 * `escalationReason`, an enum, which is always exported.
 *
 * `historyReads.rounds[].result` is NOT free text under a flag: it is
 * HistoryRequestResolver's rendered block of past quote numbers, order
 * numbers, product labels and money for one named account, and there is no
 * reading of "anonymized export" under which it leaves. Only the `kind` of
 * each read survives, which is what answers the question the export exists to
 * ask -- does letting the agent read history change what it decides.
 */
final class AnonymizedDecision
{
    /**
     * Shop-local ids: the grouping is the value, the identity is the risk, so
     * they are salted rather than dropped. Renamed on the way out because the
     * exported string is a pseudonym, not the id the name would promise.
     *
     * @var array<string, string> entity property => export key
     */
    public const PSEUDONYMIZED = [
        'id' => 'id',
        'quoteId' => 'quote',
        'customerId' => 'customer',
        'salesChannelId' => 'salesChannel',
        'revisionVersionId' => 'revision',
        'strategyVersionId' => 'strategyVersion',
    ];

    /**
     * Numbers, enums, hashes, timestamps and closed vocabularies. `writes` is
     * here because OfferApplier builds it from method names, never from data.
     *
     * @var list<string>
     */
    public const VERBATIM = [
        'currencyIso',
        'triggerReason',
        'attempt',
        'revisionUpdatedAt',
        'band',
        'outcome',
        'escalationReason',
        'discountPercentGranted',
        'maxDiscountPercent',
        'totalNetBefore',
        'totalNetAfter',
        'model',
        'modelHost',
        'extractPromptHash',
        'negotiatePromptHash',
        'replyPromptHash',
        'promptTokens',
        'completionTokens',
        'modelLatencyMs',
        'durationMs',
        'authorized',
        'verified',
        'errorClass',
        'terminalState',
        'terminalAt',
        'resolvedAt',
        'resolvedState',
        'writes',
    ];

    /** JSON columns exported with their free text or business data removed. @var list<string> */
    public const RESHAPED = ['interpretedAsks', 'historyReads', 'errorChain'];

    /** Exported only under --include-comments. @var list<string> */
    public const FREE_TEXT = ['rawProposal', 'replyToBuyer', 'violations'];

    /** A document number a human reads, printed on the buyer's quote. Nothing cross-shop needs it. @var list<string> */
    public const DROPPED = ['quoteNumber'];

    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(QuoteDecisionRecord $record, ExportPseudonym $pseudonym, bool $freeText): array
    {
        $row = [
            'id' => $pseudonym->of($record->id),
            'quote' => $pseudonym->of($record->quoteId),
            'customer' => $pseudonym->of($record->customerId),
            'salesChannel' => $pseudonym->of($record->salesChannelId),
            'revision' => $pseudonym->of($record->revisionVersionId),
            'strategyVersion' => $pseudonym->of($record->strategyVersionId),
            'createdAt' => self::at($record->getCreatedAt()),
            'currencyIso' => $record->currencyIso,
            'triggerReason' => $record->triggerReason,
            'attempt' => $record->attempt,
            'revisionUpdatedAt' => self::at($record->revisionUpdatedAt),
            'band' => $record->band,
            'outcome' => $record->outcome,
            'escalationReason' => $record->escalationReason,
            'discountPercentGranted' => $record->discountPercentGranted,
            'maxDiscountPercent' => $record->maxDiscountPercent,
            'totalNetBefore' => $record->totalNetBefore,
            'totalNetAfter' => $record->totalNetAfter,
            'model' => $record->model,
            'modelHost' => $record->modelHost,
            'extractPromptHash' => $record->extractPromptHash,
            'negotiatePromptHash' => $record->negotiatePromptHash,
            'replyPromptHash' => $record->replyPromptHash,
            'promptTokens' => $record->promptTokens,
            'completionTokens' => $record->completionTokens,
            'modelLatencyMs' => $record->modelLatencyMs,
            'durationMs' => $record->durationMs,
            'authorized' => $record->authorized,
            'verified' => $record->verified,
            'errorClass' => $record->errorClass,
            'terminalState' => $record->terminalState,
            'terminalAt' => self::at($record->terminalAt),
            'resolvedAt' => self::at($record->resolvedAt),
            'resolvedState' => $record->resolvedState,
            'writes' => $record->writes,
            'interpretedAsks' => self::asks($record->interpretedAsks, $freeText),
            'historyReads' => self::history($record->historyReads),
            'errorChain' => self::errors($record->errorChain, $freeText),
        ];

        if (!$freeText) {
            return $row;
        }

        return [
            ...$row,
            'rawProposal' => $record->rawProposal,
            'replyToBuyer' => $record->replyToBuyer,
            'violations' => $record->violations,
        ];
    }

    private static function at(?\DateTimeInterface $at): ?string
    {
        return $at?->format(\DateTimeInterface::ATOM);
    }

    /**
     * Every typed ask survives -- prices, quantities, delivery, payment,
     * bundle -- because that structure is what the export is for. The two
     * lists of sentences the extraction model wrote while reading the buyer's
     * message do not.
     *
     * @param array<string, mixed>|null $asks
     *
     * @return array<string, mixed>|null
     */
    private static function asks(?array $asks, bool $freeText): ?array
    {
        if ($asks === null || $freeText) {
            return $asks;
        }

        unset($asks['clarificationQuestions'], $asks['humanReviewRequests']);

        return $asks;
    }

    /**
     * The summary half is counts and totals -- the account's shape, not its
     * identity -- and survives whole. Each round keeps only what was asked.
     *
     * @param array<string, mixed>|null $reads
     *
     * @return array<string, mixed>|null
     */
    private static function history(?array $reads): ?array
    {
        if ($reads === null) {
            return null;
        }

        $rounds = $reads['rounds'] ?? [];
        $reads['rounds'] = array_values(array_map(
            static fn(mixed $round): mixed => \is_array($round) ? ($round['kind'] ?? null) : null,
            \is_array($rounds) ? $rounds : [],
        ));

        return $reads;
    }

    /**
     * The class and the file:line are ours. The message is whatever the
     * throwing code chose to put in it, which on the model path can be a
     * provider response body quoting the prompt.
     *
     * @param list<array<string, string>>|null $chain
     *
     * @return list<array<string, string>>|null
     */
    private static function errors(?array $chain, bool $freeText): ?array
    {
        if ($chain === null || $freeText) {
            return $chain;
        }

        return array_values(array_map(
            static fn(array $link): array => ['class' => $link['class'] ?? '', 'at' => $link['at'] ?? ''],
            $chain,
        ));
    }
}
```

- [ ] **Step 5: Run both tests to verify they pass**

Run: `composer run test -- --filter 'AnonymizedDecisionTest|ExportFieldCoverageTest'`
Expected: PASS. If `ExportFieldCoverageTest::testEveryEntityFieldIsClassifiedExactlyOnce` fails, the entity has a field the lists miss — add it to the right list and to `of()`, do not delete the assertion.

- [ ] **Step 6: Run the whole unit suite and the gates**

Run: `composer run test && composer run format:check && composer run lint && composer run typecheck && composer run quality:filesize`
Expected: all clean.

- [ ] **Step 7: Commit**

```bash
/usr/bin/git add src/Audit/Export/AnonymizedDecision.php tests/Unit/Audit/Export/AnonymizedDecisionTest.php tests/Unit/Audit/Export/ExportFieldCoverageTest.php
/usr/bin/git commit -m "feat(export): map a decision record to an anonymized row (#34)

An allowlist rather than a redactor: a column added to the entity
cannot leak, and ExportFieldCoverageTest fails until someone says what
it means. --include-comments gates rawProposal, violations and the
model's clarification questions as well as the reply -- all four can
repeat what the buyer typed. The rendered account-history block never
leaves, flag or no flag.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: The command

**Files:**
- Create: `src/Command/DecisionExportCommand.php`
- Modify: `src/Resources/config/services.php` (import block at the top, and the audit registrations around line 385)
- Test: `tests/Unit/Command/DecisionExportCommandTest.php`

**Interfaces:**
- Consumes: `AnonymizedDecision::of()`, `ExportPseudonym::forShop()` from Tasks 1–2
- Produces: console command `merchant-quote-agent:export` with options `--from` (VALUE_REQUIRED), `--to` (VALUE_REQUIRED), `--include-comments` (VALUE_NONE)

Read `src/Command/AllowAnyAgentCommand.php` first — the shape, the docblock habit and the `SymfonyStyle` usage are the house pattern to follow.

The range is **half-open**: `--from` inclusive, `--to` exclusive, so `--from=2026-09-01 --to=2026-10-01` is September and no merchant loses the last day to a midnight boundary. Say so in the option description.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Command/DecisionExportCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Command;

use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Command\DecisionExportCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DecisionExportCommand::class)]
final class DecisionExportCommandTest extends TestCase
{
    public function testItRefusesWithoutADateRange(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-09-01']));
        self::assertStringContainsString('--from and --to', $tester->getDisplay());
    }

    public function testItRefusesADateItCannotRead(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--from' => 'last tuesday-ish', '--to' => '2026-10-01']));
        self::assertStringContainsString('could not be read as a date', $tester->getDisplay());
    }

    public function testItRefusesARangeThatRunsBackwards(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-10-01', '--to' => '2026-09-01']));
        self::assertStringContainsString('--to must be after --from', $tester->getDisplay());
    }

    public function testItSaysOnStderrThatItIsExcludingFreeText(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['--from' => '2026-09-01', '--to' => '2026-10-01'], ['capture_stderr_separately' => true]);

        $errors = $tester->getErrorOutput();
        self::assertStringContainsString('--include-comments', $errors);
        self::assertStringContainsString('excluded', $errors);
        self::assertSame('', $tester->getDisplay(), 'JSONL goes to stdout; with no rows, stdout must be empty.');
    }

    public function testItDoesNotRepeatTheExclusionNoticeWhenNothingIsExcluded(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(
            ['--from' => '2026-09-01', '--to' => '2026-10-01', '--include-comments' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertStringNotContainsString('excluded', $tester->getErrorOutput());
        self::assertStringContainsString('free text', $tester->getErrorOutput());
    }

    private function command(): DecisionExportCommand
    {
        $repository = $this->createMock(EntityRepository::class);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn('a-fixed-test-salt');

        return new DecisionExportCommand($repository, $config);
    }
}
```

Note: the four refusal tests return before touching the repository, and the two notice tests run `RepositoryIterator` over a mocked `EntityRepository` whose `search()` returns nothing by default, so no fixture data is needed here. The rows themselves are Task 4's job, against the real DAL.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter DecisionExportCommandTest`
Expected: FAIL — `MerchantQuoteAgentPlugin\Command\DecisionExportCommand` not found.

- [ ] **Step 3: Write the implementation**

Create `src/Command/DecisionExportCommand.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Override;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the agent's decision records for a date range to stdout as JSONL,
 * anonymized, so a merchant can send them to Shopware for the collective
 * strategy work #19 describes.
 *
 * A merchant action, never automatic: nothing schedules this and nothing calls
 * it. Sharing business records with a third party is a decision a person
 * makes, once, deliberately.
 *
 * JSONL goes to stdout and every notice to stderr, so
 * `... > september.jsonl` produces a file containing only records. The
 * exclusion notice is not optional: a merchant who does not know free text was
 * withheld will not know what they sent, and one who does not know it was
 * INCLUDED will not know what they sent either -- so both runs say which.
 *
 * The range is half-open, `--from` inclusive and `--to` exclusive. A bare date
 * parses to midnight, so an inclusive `--to` would quietly drop the last day
 * for everyone who typed one.
 *
 * What leaves and what does not is in docs/for-merchants.md, and the
 * classification that decides it is AnonymizedDecision's five lists.
 */
#[AsCommand(
    name: 'merchant-quote-agent:export',
    description: 'Export the agent\'s decision records for a date range as anonymized JSONL.',
)]
final class DecisionExportCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $decisions,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the range, inclusive (e.g. 2026-09-01).');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'End of the range, exclusive (e.g. 2026-10-01).');
        $this->addOption(
            'include-comments',
            null,
            InputOption::VALUE_NONE,
            'Also export free text: the agent\'s replies, the model\'s raw answers and questions, escalation prose'
            . ' and error messages. Withheld by default because a model can repeat whatever the buyer typed.',
        );
    }

    /** @throws \Random\RandomException */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = $input->getOption('from');
        $to = $input->getOption('to');

        if (!\is_string($from) || !\is_string($to) || $from === '' || $to === '') {
            $io->error('Both --from and --to are required: merchant-quote-agent:export --from=2026-09-01 --to=2026-10-01');

            return Command::INVALID;
        }

        try {
            $start = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
        } catch (\Exception $e) {
            $io->error(\sprintf('"%s" or "%s" could not be read as a date: %s', $from, $to, $e->getMessage()));

            return Command::INVALID;
        }

        if ($end <= $start) {
            $io->error('--to must be after --from; the range is half-open, so September is --from=2026-09-01 --to=2026-10-01.');

            return Command::INVALID;
        }

        $freeText = (bool) $input->getOption('include-comments');
        $written = $this->write($output, $start, $end, $freeText);

        $this->report($io, $written, $freeText);

        return Command::SUCCESS;
    }

    /** @throws \Random\RandomException */
    private function write(OutputInterface $output, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $freeText): int
    {
        $pseudonym = ExportPseudonym::forShop($this->systemConfig);
        $iterator = new RepositoryIterator($this->decisions, Context::createDefaultContext(), self::criteria($from, $to));
        $written = 0;

        while (($result = $iterator->fetch()) !== null) {
            foreach ($result->getEntities() as $record) {
                if (!$record instanceof QuoteDecisionRecord) {
                    continue;
                }

                $output->writeln((string) json_encode(
                    AnonymizedDecision::of($record, $pseudonym, $freeText),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
                ++$written;
            }
        }

        return $written;
    }

    private static function criteria(\DateTimeImmutable $from, \DateTimeImmutable $to): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $to->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(500);

        return $criteria;
    }

    /** Notices go to stderr so a redirect of stdout captures only JSONL. */
    private function report(SymfonyStyle $io, int $written, bool $freeText): void
    {
        $errors = $io->getErrorStyle();
        $errors->writeln(\sprintf(
            '%d record(s). Customer, quote, channel, revision and strategy ids are pseudonymized with this shop\'s'
            . ' salt; names, addresses and the quote number are not exported at all.',
            $written,
        ));

        $errors->writeln($freeText
            ? 'Free text IS included (--include-comments): the agent\'s replies, the model\'s raw answers and'
            . ' questions, escalation prose and error messages. These can repeat whatever the buyer typed.'
            : 'Free text is excluded: the agent\'s replies, the model\'s raw answers and questions, escalation prose'
            . ' and error messages. Pass --include-comments to include them.');
    }
}
```

- [ ] **Step 4: Register the command**

In `src/Resources/config/services.php`, add to the `use` block at the top (keep it alphabetical among the `Command` imports):

```php
use MerchantQuoteAgentPlugin\Command\DecisionExportCommand;
```

and next to the other audit registrations (around line 385, after `DecisionRecordWriter`):

```php
    // #34: the merchant's own anonymized export of this table. A console
    // command and nothing else -- no schedule, no route -- because sending
    // business records to a third party is a decision a person makes.
    $services->set(DecisionExportCommand::class)->args([
        service('merchant_quote_agent_decision.repository'),
        service(SystemConfigService::class),
    ])->tag('console.command');
```

If `SystemConfigService` is not already imported in that file, add `use Shopware\Core\System\SystemConfig\SystemConfigService;` — check first, `AllowAnyAgentCommand` already takes it, so it probably is.

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer run test -- --filter DecisionExportCommandTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Run the gates**

Run: `composer run test && composer run format:check && composer run lint && composer run typecheck && composer run quality:filesize`
Expected: all clean.

- [ ] **Step 7: Commit**

```bash
/usr/bin/git add src/Command/DecisionExportCommand.php src/Resources/config/services.php tests/Unit/Command/DecisionExportCommandTest.php
/usr/bin/git commit -m "feat(export): add merchant-quote-agent:export (#34)

JSONL on stdout, notices on stderr, so a redirect captures only
records. Half-open range so a bare --to date does not silently drop its
own day. Every run says whether free text was included or excluded.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: #19's Done-when, as a test that can fail

**Files:**
- Create: `tests/Integration/DecisionExportTest.php`

**Interfaces:**
- Consumes: the registered command from Task 3; `tests/Integration/IntegrationTestCase.php` (`KernelTestBehaviour` gives `static::getKernel()` and `static::getContainer()`; `DatabaseTransactionBehaviour` rolls each test back)

This is the task the issue exists for. The grep must be able to fail: the fixture plants the email and the customer number in every field that can really carry them, and a positive control proves the assertion is looking at bytes that would have shown them.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/DecisionExportTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #19's third Done-when, which #34 carries:
 *
 *   "The export contains no raw identifier; a test greps for the fixture's
 *   email and customer number and finds neither."
 *
 * A grep passes trivially against a fixture that never held the string, so
 * four things here stop it meaning nothing:
 *
 *  1. The fixture plants the email and the customer number in every column
 *     that can really carry them -- the agent's reply, the model's raw
 *     answer, the escalation prose, the model's human-review question, the
 *     rendered account-history block and an exception message -- plus a real
 *     customerId and a real quote number in their own columns.
 *  2. testTheSameFixtureDoesLeakItWhenFreeTextIsAskedFor is the positive
 *     control: the same record exported with --include-comments DOES contain
 *     the email. If the fixture failed to carry it, or the exporter emitted
 *     nothing, or this were greping the wrong stream, that assertion fails.
 *  3. The default run asserts the analytic fields ARE present, so the test
 *     cannot pass by exporting a blank line.
 *  4. --include-comments is asserted not to widen anything else: the flag
 *     adds free text, it never turns anonymization off.
 */
final class DecisionExportTest extends IntegrationTestCase
{
    private const EMAIL = 'anna.mueller@acme.example';

    private const CUSTOMER_NUMBER = 'KD-10042-X';

    private const QUOTE_NUMBER = 'QU-99001-Z';

    public function testTheDefaultExportContainsNoRawIdentifier(): void
    {
        $customerId = Uuid::randomHex();
        $this->seed($customerId);

        $jsonl = $this->export();

        self::assertNotSame('', $jsonl, 'Nothing was exported, so the grep below would prove nothing.');
        self::assertStringNotContainsString(self::EMAIL, $jsonl);
        self::assertStringNotContainsString(self::CUSTOMER_NUMBER, $jsonl);
        self::assertStringNotContainsString(self::QUOTE_NUMBER, $jsonl);
        self::assertStringNotContainsString($customerId, $jsonl);
        self::assertStringNotContainsString('Anna Mueller', $jsonl);
    }

    public function testTheSameFixtureDoesLeakItWhenFreeTextIsAskedFor(): void
    {
        $this->seed(Uuid::randomHex());

        $jsonl = $this->export(freeText: true);

        self::assertStringContainsString(
            self::EMAIL,
            $jsonl,
            'The positive control failed: the fixture did not carry the email into the export at all, so the'
            . ' default run finding no email proves nothing.',
        );
    }

    public function testTheDefaultExportStillCarriesWhatTheExportIsFor(): void
    {
        $this->seed(Uuid::randomHex());

        $row = $this->firstRow($this->export());

        self::assertSame('offered', $row['outcome']);
        self::assertSame('grant', $row['band']);
        self::assertSame(12.5, $row['discountPercentGranted']);
        self::assertSame('accepted', $row['terminalState']);
        self::assertIsString($row['customer']);
        self::assertIsString($row['quote']);
    }

    public function testTheFlagWidensFreeTextAndNothingElse(): void
    {
        $customerId = Uuid::randomHex();
        $this->seed($customerId);

        $jsonl = $this->export(freeText: true);

        self::assertStringNotContainsString($customerId, $jsonl);
        self::assertStringNotContainsString(self::QUOTE_NUMBER, $jsonl);
        self::assertStringNotContainsString(self::CUSTOMER_NUMBER, $this->firstRow($jsonl)['customer'] ?? '');
        self::assertStringNotContainsString('order 10009', $jsonl, 'The account history block never leaves.');
    }

    public function testTheJsonlStreamIsCleanEnoughToRedirect(): void
    {
        $this->seed(Uuid::randomHex());

        foreach (explode("\n", trim($this->export())) as $line) {
            self::assertIsArray(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    private function seed(string $customerId): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $repository->create([[
            'id' => Uuid::randomHex(),
            'quoteId' => Uuid::randomHex(),
            'customerId' => $customerId,
            'salesChannelId' => Uuid::randomHex(),
            'quoteNumber' => self::QUOTE_NUMBER,
            'currencyIso' => 'EUR',
            'triggerReason' => 'comment_written',
            'attempt' => 0,
            'band' => 'grant',
            'outcome' => 'offered',
            'discountPercentGranted' => 12.5,
            'terminalState' => 'accepted',
            'durationMs' => 1234,
            'errorClass' => 'RuntimeException',
            // Every field below can really carry what the buyer typed: the
            // model is shown their message and is free to repeat it.
            'replyToBuyer' => 'Hello Anna Mueller, regarding ' . self::EMAIL . ' (customer ' . self::CUSTOMER_NUMBER . ').',
            'rawProposal' => '{"action":"offer","message":"Anna Mueller, ' . self::EMAIL . ', customer '
                . self::CUSTOMER_NUMBER . '"}',
            'violations' => ['Anna Mueller (' . self::EMAIL . ') insists on 30%.'],
            'interpretedAsks' => [
                'price' => ['additionalDiscountPercent' => 15.0],
                'humanReviewRequests' => ['Customer ' . self::CUSTOMER_NUMBER . ' wants to speak to a person.'],
            ],
            'historyReads' => [
                'quotesSeen' => 7,
                'rounds' => [[
                    'kind' => 'orders',
                    'productId' => Uuid::randomHex(),
                    'result' => 'order 10009, quote ' . self::QUOTE_NUMBER . ', Anna Mueller, ' . self::EMAIL,
                ]],
            ],
            'errorChain' => [[
                'class' => 'RuntimeException',
                'message' => 'The provider rejected the prompt quoting ' . self::EMAIL,
                'at' => '/srv/Foo.php:12',
            ]],
        ]], Context::createDefaultContext());
    }

    private function export(bool $freeText = false): string
    {
        $application = new Application(static::getKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('merchant-quote-agent:export'));

        $options = ['--from' => '2000-01-01', '--to' => '2100-01-01'];

        if ($freeText) {
            $options['--include-comments'] = true;
        }

        $tester->execute($options, ['capture_stderr_separately' => true]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /** @return array<string, mixed> */
    private function firstRow(string $jsonl): array
    {
        $lines = array_values(array_filter(explode("\n", trim($jsonl))));
        self::assertNotSame([], $lines, 'The export wrote no rows.');

        $row = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }
}
```

- [ ] **Step 2: Run it to verify it fails for the right reason**

Run: `composer run test:integration -- --filter DecisionExportTest`
Expected: on a tree without Task 3 it fails with "command not found"; with Task 3 present it should pass. If it fails on the transaction-rolled-back `--from=2000-01-01` range picking up rows from other suites, that is fine — the assertions are about the seeded strings, not the row count, except `firstRow()`. If `firstRow()` picks up a foreign row, tighten `export()` to a narrow range around `new \DateTimeImmutable('now')` and stamp the fixture's `createdAt` explicitly.

- [ ] **Step 3: Make it pass**

If a real anonymization hole is found, fix `AnonymizedDecision` and add the matching unit assertion in `tests/Unit/Audit/Export/AnonymizedDecisionTest.php`. Do not weaken this test.

- [ ] **Step 4: Run it again, twice**

Run: `composer run test:integration -- --filter DecisionExportTest`
Expected: PASS both times. Other agents run the suite concurrently against the same shop; re-run once before concluding a failure is real.

- [ ] **Step 5: Commit**

```bash
/usr/bin/git add tests/Integration/DecisionExportTest.php
/usr/bin/git commit -m "test(export): prove no raw identifier survives (#19, #34)

#19's third Done-when, written so it can fail: the fixture plants the
email and the customer number in every column that can really carry
them, and the --include-comments run is the positive control that
proves the grep is looking at bytes where they would have shown.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Say what leaves, in the merchant's words

**Files:**
- Modify: `docs/for-merchants.md` (the `## Costs and data` section, which already ends with "Everything else stays in your shop")
- Modify: `README.md` (the `## Documentation` list)
- Modify: `docs/superpowers/specs/2026-09-16-anonymized-decision-export-design.md` (record the half-open range under "Questions I would have asked")

#34 puts the docs in this issue, not in a follow-up, because the merchant has to be able to check the boundary by reading rather than by trusting.

- [ ] **Step 1: Add the export section to `docs/for-merchants.md`**

Insert after the "**Everything else stays in your shop.**" paragraph and before "**One thing to be aware of:**", in the existing voice of that file (second person, bold lead-ins, no bullet soup where a sentence does):

````markdown
**If you want to share your logs with us.** There is one command that sends
data out of your shop, and it only runs when you run it:

```
bin/console merchant-quote-agent:export --from=2026-09-01 --to=2026-10-01 > september.jsonl
```

It writes one line of JSON per negotiation pass. Nothing schedules it, nothing
calls it, and it sends nothing anywhere — it writes a file, and what you do
with that file is your decision. We ask for it because negotiation strategies
get better when they can be measured across more than one shop.

Three lists, and they are the whole boundary:

*Leaves, as a scrambled code.* The row, the quote, the customer, the sales
channel, the quote revision and the strategy version. Each is replaced by a
code computed from a secret unique to your shop. The same customer is the same
code in every export you make, so repeat-buyer patterns are still visible, and
a different code from every other shop's, so nobody can line your customers up
against anyone else's — or against your own database. The secret is created the
first time you export and kept in your shop's configuration. If you delete it,
future exports stop lining up with past ones.

*Leaves, as it is.* When the pass ran and what triggered it; what the customer
asked for as the agent understood it; what your rules allowed; the amounts,
the discounts and the percentages; the decision and the reason for it; which
prompt version ran; the model name and the host it was called on; token counts
and timings; and how the quote ended.

*Does not leave, ever.* Names, e-mail addresses, postal addresses, phone
numbers and company names. The quote number. The block of past quotes and
orders the agent read about the account. And the customer's own message, which
is not stored in this record in the first place — only the agent's reading of
it.

**The comments are the exception you have to opt into.** The agent's replies,
the model's raw answers, the reasons it gave for escalating, the questions it
raised and any error messages are withheld unless you add
`--include-comments`. They are the most useful part of the data and the most
sensitive: the model is shown the customer's message, so anything the customer
typed — a signature, a phone number, an order reference — can come back in the
model's own words. The command tells you on every run which of the two you just
produced.

**One oddity you will see and should not report as a bug.** The `modelHost`
field sometimes reads `unparsable-host`. That means the AI base URL in your
settings was not a URL the shop could read a hostname out of — usually a typo.
The agent records that placeholder rather than the address you typed, because
a base URL can carry your API key in it and that must never reach a log or an
export.
````

- [ ] **Step 2: Add the README line**

In `README.md`, under `## Documentation`, add the export to the list in the same style as the existing entries, pointing at the `for-merchants.md` section. Read the surrounding lines first and match them; do not restructure the list.

- [ ] **Step 3: Record the half-open range in the spec**

In `docs/superpowers/specs/2026-09-16-anonymized-decision-export-design.md`, under "Questions I would have asked", append:

```markdown
6. **Is `--to` inclusive or exclusive?** *Assumption: exclusive, a half-open
   range.* A bare `--to=2026-09-30` parses to midnight, so an inclusive
   boundary would quietly drop the last day for everyone who types a date
   rather than a timestamp. `--from=2026-09-01 --to=2026-10-01` is September,
   which is the convention every date range in every tool already uses, and
   the option description says so.
```

- [ ] **Step 4: Verify the docs describe the code**

Read `src/Audit/Export/AnonymizedDecision.php` beside the three lists you just wrote and check each field lands in the list the docs put it in. In particular: `modelHost` is in *leaves as it is*; `quoteNumber` is in *does not leave*; `violations` is in the opt-in paragraph, not in *leaves as it is*.

Run: `composer run test && composer run quality`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
/usr/bin/git add docs/for-merchants.md README.md docs/superpowers/specs/2026-09-16-anonymized-decision-export-design.md
/usr/bin/git commit -m "docs(export): state what leaves and what does not (#34)

Three lists a merchant can check by reading, the opt-in paragraph for
free text, and the line about unparsable-host that keeps the next
reader from filing it as a bug.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage.** Decision 1 (allowlist) → Task 2, plus `ExportFieldCoverageTest`. Decision 2 (`--include-comments` gates `rawProposal`, `violations`, the model's questions, error messages) → Task 2 `FREE_TEXT`/`asks()`/`errors()`, asserted in Tasks 2 and 4. Decision 3 (history rounds) → Task 2 `history()`, asserted in Tasks 2 and 4. Decision 4 (`quoteNumber` dropped, UUIDs pseudonymized) → Task 2 `PSEUDONYMIZED`/`DROPPED`, asserted in Tasks 2 and 4. Decision 5 (salt) → Task 1. Decision 6 (`unparsable-host`) → Task 5's last paragraph. Decision 7 (shape, stdout/stderr, `RepositoryIterator`) → Task 3. The non-vacuous test → Task 4. The merchant-facing statement → Task 5.

**Placeholders.** None: every code step carries the code, every run step carries the command and the expected result.

**Type consistency.** `ExportPseudonym::of(?string): ?string` is called only as `$pseudonym->of($record->…)` in Task 2. `AnonymizedDecision::of(QuoteDecisionRecord, ExportPseudonym, bool): array<string, mixed>` is called with that exact signature in Tasks 2, 3 and the coverage test. The export key names (`id`, `quote`, `customer`, `salesChannel`, `revision`, `strategyVersion`) are the same in `PSEUDONYMIZED`'s values, in `of()`, in `AnonymizedDecisionTest` and in `DecisionExportTest`.
