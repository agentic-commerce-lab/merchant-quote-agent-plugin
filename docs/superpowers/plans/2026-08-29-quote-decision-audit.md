# Quote Decision Audit Trail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Write one audit record per servicing pass of a quote, into the merchant's own database, so #21's 10,000-negotiation run has something to read.

**Architecture:** A mutable `DecisionRecorder` is injected as a constructor dependency into each pipeline stage that knows something worth recording. Stages append to a `DecisionDraft` as the pass runs; `NegotiationPipeline::service()` opens the pass with `begin()` and closes it with `finish()` in a `finally`, so a stage that throws still produces a record. `DecisionRecordWriter` persists the draft as a `QuoteDecisionRecord` attribute entity.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL attribute entities, Symfony DI, PHPUnit 11, mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-08-29-quote-decision-audit-design.md`

## Global Constraints

- PHP 8.3, Shopware 6.7. Every PHP file starts with `declare(strict_types=1);`.
- **Gates (all measured, all enforced by `composer run quality`, which must exit 0):** cyclomatic complexity 10 **class-scoped** (it sums across all methods — extracting a private method does not help); `excessive-parameter-list` 5; `too-many-methods` 11; `too-many-properties` **10**; `excessive-nesting` 4; 400 physical lines per file.
- `NegotiationPipeline::__construct` and `OfferRound::play` are **at** the 5-parameter cap. Do not add a parameter to either without removing one.
- Only `Audit\DecisionRecordWriter` may touch Shopware inside the `Audit` namespace. `DecisionRecorder` and `DecisionDraft` are plain PHP.
- SwagCommercial stays behind the bridge per ADR 0001.
- The audit write must never fail a pass. A missing record beats a duplicated buyer message.
- Nothing routed through `Servicing\QuoteEscalator` may carry internal text — that comment is customer-facing.
- Commits are signed. Never bypass signing. If signing fails, stop and ask.
- Unit tests: `./vendor/bin/phpunit --testsuite unit`. Integration tests: `composer run test:integration` (syncs this checkout into the `merchant-quote-shop` container and runs there; `-- --filter X` passes through).

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `src/Audit/QuoteDecisionRecord.php` | the attribute entity — one row per pass |
| `src/Audit/DecisionDraft.php` | mutable accumulator; public properties, no behaviour |
| `src/Audit/DecisionRecorder.php` | what the stages call; owns the draft's lifecycle |
| `src/Audit/DecisionRecordWriter.php` | the only Shopware-touching class in `Audit` |
| `src/Migration/Migration1787998662CreateQuoteAgentDecision.php` | creates the table |
| `src/Servicing/Data/PassContext.php` | trigger reason + attempt number |
| `tests/Unit/Audit/DecisionRecorderTest.php` | draft lifecycle and reset |
| `tests/Unit/Audit/DecisionRecordWriterTest.php` | payload mapping, swallowed failure |
| `tests/Unit/Negotiation/RecordedPassTest.php` | one record per path, across all ten |
| `tests/Integration/DecisionRecordTest.php` | table, registration, real row, aggregations |
| `tests/Unit/Audit/FakeDecisionWriter.php` | test double capturing drafts |

**Modify:** `ChatCompletionClient`, `AskInterpreter`, `OfferProposer`, `OfferApplier`, `ReplyComposer`, `NegotiationPipeline`, `OfferRound`, `QuoteServicingPipelineInterface`, `ServiceQuoteHandler`, `Resources/config/services.php`, `tests/Unit/Negotiation/PipelineHarness.php`.

---

## Task 1: The entity and its migration

**Files:**
- Create: `src/Audit/QuoteDecisionRecord.php`
- Create: `src/Migration/Migration1787998662CreateQuoteAgentDecision.php`
- Test: `tests/Integration/DecisionRecordTest.php`

**Interfaces:**
- Produces: entity name `merchant_quote_agent_decision`; repository service id `merchant_quote_agent_decision.repository`; class `MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord` with the public properties listed below.

**Context:** Shopware 6.7 supports attribute entities — one class, public typed properties, no Definition/Collection/getters. See `vendor/shopware/core/Content/MeasurementSystem/DataAbstractionLayer/MeasurementSystemEntity.php` for a real example. `services.php` already calls `$services->defaults()->autowire()->autoconfigure()`, and `AutoconfigureCompilerPass` registers `Attribute\Entity` for the `shopware.entity` tag — but the class must still be registered as a service (Task 9). There is no schema generation; the migration is hand-written.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/DecisionRecordTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

final class DecisionRecordTest extends IntegrationTestCase
{
    public function testTheTableExistsAndTheEntityIsRegistered(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');

        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = Uuid::randomHex();
        $repository->create([[
            'id' => $id,
            'quoteId' => Uuid::randomHex(),
            'salesChannelId' => Uuid::randomHex(),
            'quoteNumber' => '10001',
            'currencyIso' => 'EUR',
            'trigger' => 'comment_written',
            'attempt' => 0,
            'outcome' => 'offered',
            'band' => 'grant',
            'durationMs' => 1234,
        ]], Context::createDefaultContext());

        $written = $repository->search(new Criteria([$id]), Context::createDefaultContext())->first();

        self::assertNotNull($written, 'The record was not written.');
        self::assertSame('offered', $written->outcome);
        self::assertSame(1234, $written->durationMs);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `composer run test:integration -- --filter DecisionRecordTest`
Expected: FAIL — the `merchant_quote_agent_decision.repository` service does not exist.

- [ ] **Step 3: Write the entity**

Create `src/Audit/QuoteDecisionRecord.php`. Note the `@mago-expect`: `too-many-properties` fires above 10, and an entity's properties are its table's columns, so the rule's suggested fix (group into an object) is unavailable. This is one of exactly two permitted suppressions in this plan.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One row per servicing pass of a quote: what the buyer asked, what the rules
 * allowed, what the model proposed, what was written, and what the buyer was
 * told. #21 reads its whole result set off this table and #7 renders it.
 *
 * Scalar where the field is aggregated or filtered, JSON where it is only
 * read: DAL cannot aggregate inside a JSON column, so anything #21 counts or
 * averages has to be its own column.
 *
 * `terminalState` and `terminalAt` are reserved and never written here. The
 * subscriber that fills them is a follow-up; the columns exist so that
 * follow-up needs no migration.
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10 and these properties ARE the table's columns. The
 * rule's own remedy — group them into an object — is what a DAL entity cannot
 * do, and pushing them into JSON to duck it would cost the aggregation this
 * record exists for.
 */
#[Entity('merchant_quote_agent_decision')]
class QuoteDecisionRecord extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: true)]
    public string $id;

    #[Field(type: FieldType::UUID, api: true)]
    public string $quoteId;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $quoteNumber = null;

    #[Field(type: FieldType::UUID, api: true)]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $currencyIso = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $trigger = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $attempt = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $revisionVersionId = null;

    #[Field(type: FieldType::DATETIME, api: true)]
    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $band = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $outcome = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $escalationReason = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $discountPercentGranted = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $maxDiscountPercent = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $totalNetBefore = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $totalNetAfter = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $model = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $modelHost = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $extractPromptHash = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $negotiatePromptHash = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $replyPromptHash = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $promptTokens = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $completionTokens = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $modelLatencyMs = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $durationMs = null;

    #[Field(type: FieldType::BOOL, api: true)]
    public ?bool $authorized = null;

    #[Field(type: FieldType::BOOL, api: true)]
    public ?bool $verified = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $errorClass = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $terminalState = null;

    #[Field(type: FieldType::DATETIME, api: true)]
    public ?\DateTimeImmutable $terminalAt = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $interpretedAsks = null;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $rawProposal = null;

    /** @var list<string>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $violations = null;

    /** @var list<string>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $writes = null;

    /** @var list<array<string, string>>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $errorChain = null;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $buyerComment = null;
}
```

- [ ] **Step 4: Write the migration**

Create `src/Migration/Migration1787998662CreateQuoteAgentDecision.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The plugin's first migration. Attribute entities carry no schema generator,
 * so the table is hand-written and must stay in step with QuoteDecisionRecord.
 *
 * Indexed on what #21 and #7 actually query: the quote, the outcome, the
 * channel, and time.
 */
class Migration1787998662CreateQuoteAgentDecision extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787998662;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `merchant_quote_agent_decision` (
                `id`                        BINARY(16)   NOT NULL,
                `quote_id`                  BINARY(16)   NOT NULL,
                `quote_number`              VARCHAR(64)  NULL,
                `sales_channel_id`          BINARY(16)   NULL,
                `currency_iso`              VARCHAR(3)   NULL,
                `trigger`                   VARCHAR(64)  NULL,
                `attempt`                   INT(11)      NULL,
                `revision_version_id`       VARCHAR(64)  NULL,
                `revision_updated_at`       DATETIME(3)  NULL,
                `band`                      VARCHAR(32)  NULL,
                `outcome`                   VARCHAR(32)  NULL,
                `escalation_reason`         VARCHAR(64)  NULL,
                `discount_percent_granted`  DOUBLE       NULL,
                `max_discount_percent`      DOUBLE       NULL,
                `total_net_before`          DOUBLE       NULL,
                `total_net_after`           DOUBLE       NULL,
                `model`                     VARCHAR(128) NULL,
                `model_host`                VARCHAR(255) NULL,
                `extract_prompt_hash`       VARCHAR(64)  NULL,
                `negotiate_prompt_hash`     VARCHAR(64)  NULL,
                `reply_prompt_hash`         VARCHAR(64)  NULL,
                `prompt_tokens`             INT(11)      NULL,
                `completion_tokens`         INT(11)      NULL,
                `model_latency_ms`          INT(11)      NULL,
                `duration_ms`               INT(11)      NULL,
                `authorized`                TINYINT(1)   NULL,
                `verified`                  TINYINT(1)   NULL,
                `error_class`               VARCHAR(255) NULL,
                `terminal_state`            VARCHAR(64)  NULL,
                `terminal_at`               DATETIME(3)  NULL,
                `interpreted_asks`          JSON         NULL,
                `raw_proposal`              LONGTEXT     NULL,
                `violations`                JSON         NULL,
                `writes`                    JSON         NULL,
                `error_chain`               JSON         NULL,
                `buyer_comment`             LONGTEXT     NULL,
                `created_at`                DATETIME(3)  NOT NULL,
                `updated_at`                DATETIME(3)  NULL,
                PRIMARY KEY (`id`),
                KEY `idx.mqad.quote_id` (`quote_id`),
                KEY `idx.mqad.created_at` (`created_at`),
                KEY `idx.mqad.outcome` (`outcome`),
                KEY `idx.mqad.sales_channel_id` (`sales_channel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is additive and the merchant's data.
    }
}
```

- [ ] **Step 5: Register the entity as a service**

In `src/Resources/config/services.php`, add the import and registration. Autoconfiguration adds the `shopware.entity` tag from the `#[Entity]` attribute; the class still has to be a service for that to happen.

```php
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;

// ... inside the closure, near the other Audit registrations:
$services->set(QuoteDecisionRecord::class);
```

- [ ] **Step 6: Apply the migration and run the test**

Run: `composer run test:integration -- --filter DecisionRecordTest`

The shop container runs migrations on plugin refresh. If the table is missing, force it:
`docker exec merchant-quote-shop php8.3 /var/www/html/bin/console database:migrate --all MerchantQuoteAgentPlugin`

Expected: PASS.

- [ ] **Step 7: Verify the gates**

Run: `composer run quality`
Expected: exit 0. If `too-many-properties` still fires, the `@mago-expect` docblock is malformed — it must be inside the class's own docblock, on its own line, exactly `@mago-expect lint:too-many-properties`.

- [ ] **Step 8: Commit**

```bash
git add src/Audit/QuoteDecisionRecord.php src/Migration/ src/Resources/config/services.php tests/Integration/DecisionRecordTest.php
git commit -m "feat: add the quote decision audit entity and its table"
```

---

## Task 2: DecisionDraft and DecisionRecorder

**Files:**
- Create: `src/Audit/DecisionDraft.php`
- Create: `src/Audit/DecisionRecorder.php`
- Create: `src/Audit/DecisionRecordWriterInterface.php`
- Create: `tests/Unit/Audit/FakeDecisionWriter.php`
- Test: `tests/Unit/Audit/DecisionRecorderTest.php`

**Interfaces:**
- Consumes: `QuoteDecisionRecord` (Task 1) only conceptually — the draft mirrors its columns but does not depend on it.
- Produces:
  - `DecisionDraft` — a mutable class of public properties mirroring the entity's columns, plus `public float $startedAt`.
  - `DecisionRecorder` with exactly these eight public methods:
    - `begin(QuoteSnapshot $snapshot, PassContext $context): void`
    - `recordAsk(InterpretedAsk $ask): void`
    - `recordDecision(NegotiationDecision $decision, float $maxDiscountPercent): void`
    - `recordProposal(string $rawResponse, ProposedAnswer $answer): void`
    - `recordApplied(AppliedOffer $applied, array $writes): void`
    - `recordReply(string $comment, ?string $promptHash): void`
    - `recordModelCall(string $model, string $host, ?int $promptTokens, ?int $completionTokens, int $latencyMs): void`
    - `finish(?NegotiationPass $pass, ?\Throwable $error = null): void`
  - `DecisionRecordWriterInterface::write(DecisionDraft $draft): void`

**Context:** `PassContext` does not exist yet — it arrives in Task 4. For this task, define it inline as part of Task 4's interface and depend on it; if the implementer needs to compile before Task 4, create `PassContext` here (it is three lines) and Task 4 will only wire it.

Eight methods is deliberate: `too-many-methods` fires at 11, and one method per collaborator taking the value object that collaborator already produces is a better API than a pile of scalar setters.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Audit/DecisionRecorderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;

final class DecisionRecorderTest extends TestCase
{
    public function testAFinishedPassIsHandedToTheWriterOnce(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered, 'extract-hash'));

        self::assertCount(1, $writer->drafts);
        self::assertSame('offered', $writer->drafts[0]->outcome);
        self::assertSame('extract-hash', $writer->drafts[0]->extractPromptHash);
        self::assertSame('q1', $writer->drafts[0]->quoteId);
    }

    public function testASecondPassDoesNotInheritTheFirstPassDraft(): void
    {
        // The one real risk in a mutable recorder living in a long-running
        // worker: a leaked draft answering for the next quote. begin() resets
        // unconditionally, and this is what proves it.
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordReply('We can do 5%.', 'reply-hash');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::NothingToDo));

        self::assertCount(2, $writer->drafts);
        self::assertSame('We can do 5%.', $writer->drafts[0]->buyerComment);
        self::assertNull($writer->drafts[1]->buyerComment, 'Pass one leaked into pass two.');
        self::assertNull($writer->drafts[1]->replyPromptHash);
    }

    public function testFinishWithoutBeginWritesNothing(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame([], $writer->drafts);
    }

    public function testAThrownPassRecordsTheErrorChainAndNoOutcome(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $error = new \RuntimeException('outer', previous: new \LogicException('inner'));

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(null, $error);

        $draft = $writer->drafts[0];
        self::assertNull($draft->outcome);
        self::assertSame(\RuntimeException::class, $draft->errorClass);
        self::assertCount(2, $draft->errorChain ?? []);
        self::assertSame('inner', $draft->errorChain[1]['message']);
    }

    public function testTheDurationIsRecorded(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertIsInt($writer->drafts[0]->durationMs);
        self::assertGreaterThanOrEqual(0, $writer->drafts[0]->durationMs);
    }

    private static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
    }
}
```

Create `tests/Unit/Audit/FakeDecisionWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriterInterface;

/** Captures what the recorder hands over, and can be told to fail. */
final class FakeDecisionWriter implements DecisionRecordWriterInterface
{
    /** @var list<DecisionDraft> */
    public array $drafts = [];

    public ?\Throwable $throws = null;

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        $this->drafts[] = $draft;

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter DecisionRecorderTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Audit\DecisionRecorder" not found`.

- [ ] **Step 3: Write PassContext (if Task 4 has not landed)**

Create `src/Servicing/Data/PassContext.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

/**
 * What only the message knows about THIS attempt: why the quote was queued,
 * and how many times servicing it has already been tried.
 *
 * A value object rather than two scalars on `service()`, which would put that
 * signature at the five-parameter cap with no room left.
 */
final readonly class PassContext
{
    public function __construct(
        public ServicingTriggerReason $reason,
        public int $attempt,
    ) {}
}
```

- [ ] **Step 4: Write the writer interface**

Create `src/Audit/DecisionRecordWriterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * The seam that keeps DecisionRecorder plain PHP: the recorder accumulates,
 * this persists, and only the implementation touches Shopware.
 */
interface DecisionRecordWriterInterface
{
    public function write(DecisionDraft $draft): void;
}
```

- [ ] **Step 5: Write the draft**

Create `src/Audit/DecisionDraft.php`. It mirrors the entity's columns and carries the same suppression for the same reason.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * The record under construction. Public and mutable on purpose: stages append
 * to it as the pass runs, so a pass that dies halfway still carries everything
 * up to the point it died.
 *
 * Deliberately dumb — no behaviour, no validation. DecisionRecorder owns the
 * lifecycle and DecisionRecordWriter owns the mapping.
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10 and these properties mirror a table's columns
 * one-for-one. Grouping them would put a translation layer between the draft
 * and the row for no gain.
 */
final class DecisionDraft
{
    public string $quoteId = '';

    public ?string $quoteNumber = null;

    public ?string $salesChannelId = null;

    public ?string $currencyIso = null;

    public ?string $trigger = null;

    public ?int $attempt = null;

    public ?string $revisionVersionId = null;

    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    public ?string $band = null;

    public ?string $outcome = null;

    public ?string $escalationReason = null;

    public ?float $discountPercentGranted = null;

    public ?float $maxDiscountPercent = null;

    public ?float $totalNetBefore = null;

    public ?float $totalNetAfter = null;

    public ?string $model = null;

    public ?string $modelHost = null;

    public ?string $extractPromptHash = null;

    public ?string $negotiatePromptHash = null;

    public ?string $replyPromptHash = null;

    public ?int $promptTokens = null;

    public ?int $completionTokens = null;

    public ?int $modelLatencyMs = null;

    public ?int $durationMs = null;

    public ?bool $authorized = null;

    public ?bool $verified = null;

    public ?string $errorClass = null;

    /** @var array<string, mixed>|null */
    public ?array $interpretedAsks = null;

    public ?string $rawProposal = null;

    /** @var list<string>|null */
    public ?array $violations = null;

    /** @var list<string>|null */
    public ?array $writes = null;

    /** @var list<array<string, string>>|null */
    public ?array $errorChain = null;

    public ?string $buyerComment = null;

    public float $startedAt = 0.0;
}
```

- [ ] **Step 6: Write the recorder**

Create `src/Audit/DecisionRecorder.php`. Watch the class-scoped complexity budget of 10 — keep the branching to the null-coalescing shown here.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\ProposedAnswer;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;

/**
 * Collects one servicing pass into a DecisionDraft, then hands it to the
 * writer.
 *
 * Stateful, which is the price of not changing every stage's return type: the
 * stages take this as a constructor dependency and append what they know, so
 * NegotiationPipeline and OfferRound stay inside the five-parameter cap and
 * ChatCompletionClient can report tokens without changing what it returns.
 *
 * Safe because a pass is never concurrent with another: Messenger handles one
 * message at a time per worker and the servicing lock is held throughout. The
 * invariant that makes it safe ANYWAY is begin(): it resets unconditionally,
 * so a draft left behind by a crashed pass cannot answer for the next quote.
 *
 * One method per collaborator, each taking the value object that collaborator
 * already produces. A setter per field would trip `too-many-methods`, and this
 * is the better API regardless.
 */
final class DecisionRecorder
{
    private ?DecisionDraft $draft = null;

    public function __construct(
        private readonly DecisionRecordWriterInterface $writer,
    ) {}

    public function begin(QuoteSnapshot $snapshot, PassContext $context): void
    {
        $draft = new DecisionDraft();
        $draft->quoteId = $snapshot->identity->quoteId;
        $draft->quoteNumber = $snapshot->identity->quoteNumber;
        $draft->salesChannelId = $snapshot->identity->salesChannelId;
        $draft->currencyIso = $snapshot->identity->currencyIso;
        $draft->trigger = $context->reason->value;
        $draft->attempt = $context->attempt;
        $draft->revisionVersionId = $snapshot->revision->versionId;
        $draft->revisionUpdatedAt = $snapshot->revision->updatedAt;
        $draft->totalNetBefore = $snapshot->totals->totalNet;
        $draft->startedAt = microtime(true);

        $this->draft = $draft;
    }

    public function recordAsk(InterpretedAsk $ask): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->extractPromptHash = $ask->promptHash;
        $this->draft->interpretedAsks = InterpretationPayload::of($ask->interpretation);
    }

    public function recordDecision(NegotiationDecision $decision, ?float $maxDiscountPercent): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->band = $decision->overall->value;
        $this->draft->maxDiscountPercent = $maxDiscountPercent;
    }

    public function recordProposal(string $rawResponse, ProposedAnswer $answer): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->rawProposal = $rawResponse;
        $this->draft->negotiatePromptHash = $answer->promptHash;
        $this->draft->authorized = $answer->offer !== null;
        $this->draft->escalationReason = $answer->escalation?->value ?? $this->draft->escalationReason;
    }

    /** @param list<string> $writes */
    public function recordApplied(AppliedOffer $applied, array $writes): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->verified = $applied->verified;
        $this->draft->violations = $applied->violations;
        $this->draft->writes = $writes;
        $this->draft->totalNetAfter = $applied->after->totals->totalNet;
    }

    public function recordReply(string $comment, ?string $promptHash): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->buyerComment = $comment;
        $this->draft->replyPromptHash = $promptHash;
    }

    public function recordModelCall(
        string $model,
        string $host,
        ?int $promptTokens,
        ?int $completionTokens,
        int $latencyMs,
    ): void {
        if ($this->draft === null) {
            return;
        }

        $this->draft->model = $model;
        $this->draft->modelHost = $host;
        $this->draft->promptTokens = ($this->draft->promptTokens ?? 0) + ($promptTokens ?? 0);
        $this->draft->completionTokens = ($this->draft->completionTokens ?? 0) + ($completionTokens ?? 0);
        $this->draft->modelLatencyMs = ($this->draft->modelLatencyMs ?? 0) + $latencyMs;
    }

    /**
     * Clears the draft before writing, so a second finish() cannot write a
     * duplicate and a leaked draft cannot outlive the pass.
     */
    public function finish(?NegotiationPass $pass, ?\Throwable $error = null): void
    {
        $draft = $this->draft;
        $this->draft = null;

        if ($draft === null) {
            return;
        }

        $draft->durationMs = (int) round((microtime(true) - $draft->startedAt) * 1000);
        $draft->outcome = $pass?->outcome->value;
        $draft->extractPromptHash = $pass?->extractHash ?? $draft->extractPromptHash;
        $draft->negotiatePromptHash = $pass?->negotiateHash ?? $draft->negotiatePromptHash;
        $draft->replyPromptHash = $pass?->replyHash ?? $draft->replyPromptHash;
        $draft->errorClass = $error === null ? null : $error::class;
        $draft->errorChain = $error === null ? null : ErrorChain::of($error);

        $this->writer->write($draft);
    }
}
```

- [ ] **Step 7: Write the two small payload helpers**

Create `src/Audit/ErrorChain.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** A throwable and its getPrevious() walk, flattened for the JSON column. */
final class ErrorChain
{
    private const MAX_DEPTH = 10;

    private function __construct() {}

    /** @return list<array<string, string>> */
    public static function of(\Throwable $error): array
    {
        $chain = [];
        $current = $error;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $chain[] = [
                'class' => $current::class,
                'message' => $current->getMessage(),
                'at' => $current->getFile() . ':' . $current->getLine(),
            ];
            $current = $current->getPrevious();
            ++$depth;
        }

        return $chain;
    }
}
```

Create `src/Audit/InterpretationPayload.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/**
 * CommentInterpretation as a JSON-shaped array. json_decode(json_encode())
 * rather than a hand-written mapper: the interpretation is a tree of readonly
 * value objects with public properties, and a mapper here would silently drift
 * from it every time #18 adds a field.
 */
final class InterpretationPayload
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(CommentInterpretation $interpretation): array
    {
        $encoded = json_encode($interpretation, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
```

- [ ] **Step 8: Run the tests**

Run: `./vendor/bin/phpunit --testsuite unit --filter DecisionRecorderTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Verify the gates and commit**

```bash
composer run quality
git add src/Audit/ src/Servicing/Data/PassContext.php tests/Unit/Audit/
git commit -m "feat: add the decision draft and the per-pass recorder"
```

---

## Task 3: The DAL writer

**Files:**
- Create: `src/Audit/DecisionRecordWriter.php`
- Test: `tests/Unit/Audit/DecisionRecordWriterTest.php`

**Interfaces:**
- Consumes: `DecisionDraft`, `DecisionRecordWriterInterface` (Task 2); entity name `merchant_quote_agent_decision` (Task 1).
- Produces: `DecisionRecordWriter implements DecisionRecordWriterInterface`, constructed with `Shopware\Core\Framework\DataAbstractionLayer\EntityRepository`.

**Context:** This is the only class in `Audit` allowed to touch Shopware. The write must never fail a pass — but the swallow lives in `DecisionRecorder::finish()`'s caller (the pipeline, Task 6), not here, so the writer can be tested for what it actually throws. Re-read the spec's "The audit write must never fail the pass" before changing that split.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Audit/DecisionRecordWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

final class DecisionRecordWriterTest extends TestCase
{
    public function testTheDraftBecomesOneCreatePayloadWithAGeneratedId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $captured = [];

        $repository->expects(self::once())
            ->method('create')
            ->willReturnCallback(static function (array $payload) use (&$captured): void {
                $captured = $payload;
            });

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->outcome = 'offered';
        $draft->band = 'grant';
        $draft->durationMs = 42;
        $draft->violations = [];

        (new DecisionRecordWriter($repository))->write($draft);

        self::assertCount(1, $captured);
        self::assertTrue(Uuid::isValid($captured[0]['id']), 'The writer must generate the primary key.');
        self::assertSame('offered', $captured[0]['outcome']);
        self::assertSame(42, $captured[0]['durationMs']);
    }

    public function testTheStartedAtWorkingFieldIsNotWritten(): void
    {
        // startedAt is the draft's own stopwatch, not a column. Passing it to
        // an unknown key is silently DROPPED by the DAL, not rejected (measured).
        $repository = $this->createMock(EntityRepository::class);
        $captured = [];

        $repository->method('create')->willReturnCallback(
            static function (array $payload) use (&$captured): void {
                $captured = $payload;
            },
        );

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->startedAt = microtime(true);

        (new DecisionRecordWriter($repository))->write($draft);

        self::assertArrayNotHasKey('startedAt', $captured[0]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter DecisionRecordWriterTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter" not found`.

- [ ] **Step 3: Write the writer**

Create `src/Audit/DecisionRecordWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The only class in Audit that touches Shopware. Everything upstream of it —
 * the recorder, the draft, the stages — stays plain PHP and unit-testable
 * without a kernel.
 *
 * `startedAt` is the draft's own stopwatch, not a column: it is excluded
 * explicitly. Note the DAL does NOT reject an unknown key — it silently
 * drops it — so DraftMirrorsEntityTest is what actually catches drift.
 */
final readonly class DecisionRecordWriter implements DecisionRecordWriterInterface
{
    private const NOT_A_COLUMN = ['startedAt'];

    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        $payload = get_object_vars($draft);

        foreach (self::NOT_A_COLUMN as $field) {
            unset($payload[$field]);
        }

        $payload['id'] = Uuid::randomHex();

        $this->records->create([$payload], Context::createDefaultContext());
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/phpunit --testsuite unit --filter DecisionRecordWriterTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Register the writer**

In `src/Resources/config/services.php`:

```php
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriterInterface;

// The repository is created by the DAL from the #[Entity] attribute; it is
// not autowirable by type, so name it.
$services->set(DecisionRecordWriter::class)->args([service('merchant_quote_agent_decision.repository')]);
$services->alias(DecisionRecordWriterInterface::class, DecisionRecordWriter::class);
$services->set(DecisionRecorder::class);
```

- [ ] **Step 6: Verify the gates and commit**

```bash
composer run quality
git add src/Audit/DecisionRecordWriter.php src/Resources/config/services.php tests/Unit/Audit/DecisionRecordWriterTest.php
git commit -m "feat: persist a decision draft through the DAL"
```

---

## Task 4: PassContext reaches the pipeline

**Files:**
- Modify: `src/Servicing/QuoteServicingPipelineInterface.php`
- Modify: `src/Servicing/ServiceQuoteHandler.php`
- Modify: `src/Negotiation/NegotiationPipeline.php`
- Modify: `tests/Unit/Negotiation/PipelineHarness.php` and every caller of `service()` in tests
- Test: `tests/Unit/Servicing/ServiceQuoteHandlerTest.php` (existing file — add a case)

**Interfaces:**
- Consumes: `PassContext` (Task 2).
- Produces: `QuoteServicingPipelineInterface::service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway, QuoteAgentSettings $settings, PassContext $context): NegotiationOutcome`.

**Context:** No recording happens yet — this task only threads the value through, so the diff that adds recording stays small. The handler already reads the attempt count at `ServiceQuoteHandler.php:193` (`$snapshot->lifecycle->customFields[self::ATTEMPTS_KEY] ?? 0`), and the trigger is `$message->reason`.

`service()` goes from three parameters to four, which is inside the cap of five.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`:

```php
public function testThePipelineIsToldWhyTheQuoteWasQueuedAndWhichAttemptThisIs(): void
{
    // Without the attempt number every redelivery reads as a separate
    // negotiation, which would inflate #21's counts by exactly the number of
    // retries the crash budget allows.
    $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
    $pipeline = new RecordingPipeline();
    $handler = self::handlerWith($gateway, $pipeline);

    $handler(ServiceQuoteMessage::because('q1', ServicingTriggerReason::CommentWritten));

    self::assertNotNull($pipeline->context);
    self::assertSame(ServicingTriggerReason::CommentWritten, $pipeline->context->reason);
    self::assertSame(0, $pipeline->context->attempt);
}
```

If `RecordingPipeline` does not already exist in the test suite, create `tests/Unit/Servicing/RecordingPipeline.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;

/** Captures what the handler hands the pipeline. */
final class RecordingPipeline implements QuoteServicingPipelineInterface
{
    public ?PassContext $context = null;

    public NegotiationOutcome $returns = NegotiationOutcome::Offered;

    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $this->context = $context;

        return $this->returns;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter ServiceQuoteHandlerTest`
Expected: FAIL — `PassContext` is not a parameter of `service()`.

- [ ] **Step 3: Widen the interface**

In `src/Servicing/QuoteServicingPipelineInterface.php`, add the import and the parameter, and extend the docblock:

```php
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;

    /**
     * The context carries what only the message knows — why the quote was
     * queued and which attempt this is. #19 records both: without the attempt
     * number a redelivered quote reads as a second negotiation.
     */
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome;
```

- [ ] **Step 4: Build the context in the handler**

In `src/Servicing/ServiceQuoteHandler.php::servicePass()`, where the pipeline is currently invoked, build and pass the context. The attempt value is read the same way the crash budget already reads it:

```php
$attempts = $snapshot->lifecycle->customFields[self::ATTEMPTS_KEY] ?? 0;
$context = new PassContext($message->reason, \is_int($attempts) ? $attempts : 0);

$outcome = $pipeline->service($snapshot, $gateway, $settings, $context);
```

`$message->reason` is a `string` on `ServiceQuoteMessage`; convert it with `ServicingTriggerReason::from($message->reason)`. If that conversion is already done elsewhere in the handler, reuse it rather than converting twice.

- [ ] **Step 5: Accept the parameter in the pipeline**

In `src/Negotiation/NegotiationPipeline.php`, add the parameter to `service()` and mark it unused for now with a comment — the analyzer has `find-unused-parameters = true`, so an unused parameter is an error. Wire it into `negotiate()`'s signature so it is genuinely used, or leave the wiring to Task 6 and temporarily pass it to the existing structured log event:

```php
$this->logger->info('A quote negotiation pass finished.', [
    'outcome' => $pass->outcome->value,
    'trigger' => $context->reason->value,
    'attempt' => $context->attempt,
    'quoteId' => $snapshot->identity->quoteId,
    // ... existing keys
]);
```

That is a genuine improvement to the log line regardless, and it keeps the analyzer quiet.

- [ ] **Step 6: Update every test caller**

`tests/Integration/NegotiationPipelineTest.php` and `tests/Unit/Negotiation/*` call `service()` directly. Add a fourth argument to each:

```php
new PassContext(ServicingTriggerReason::CommentWritten, 0)
```

Consider adding `NegotiationFixture::context()` returning exactly that, so the change is one call per site.

- [ ] **Step 7: Run the full suite**

Run: `./vendor/bin/phpunit --testsuite unit && composer run test:integration`
Expected: all green, no behaviour change.

- [ ] **Step 8: Verify the gates and commit**

```bash
composer run quality
git add -A
git commit -m "feat: tell the pipeline why the quote was queued and which attempt this is"
```

---

## Task 5: The pipeline sheds QuoteEscalator

**Files:**
- Modify: `src/Negotiation/OfferRound.php`
- Modify: `src/Negotiation/NegotiationPipeline.php`
- Modify: `tests/Unit/Negotiation/PipelineHarness.php`
- Test: existing `tests/Unit/Negotiation/NegotiationPipelineTest.php` must stay green unchanged

**Interfaces:**
- Produces: `OfferRound::escalated()` becomes `public`, signature unchanged:
  `public function escalated(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, ?QuoteEscalationReason $reason, ?string $extractHash, ?string $negotiateHash): NegotiationPass`
- Produces: `NegotiationPipeline::__construct(AskInterpreter, NegotiationDecider, OfferRound, DecisionRecorder, LoggerInterface)` — `QuoteEscalator` gone, the slot free for Task 6.

**Context:** This is the manoeuvre that lets the recorder into the pipeline without breaking the five-parameter cap, and it is a genuine simplification: `NegotiationPipeline::escalate()` is a line-for-line duplicate of `OfferRound::escalated()`. Both do "escalate through `QuoteEscalator`, return `NegotiationPass(Escalated, …)`".

This task removes the duplication only. The recorder arrives in Task 6.

- [ ] **Step 1: Confirm the duplication before removing it**

Run: `sed -n '/private function escalated/,/^    }/p' src/Negotiation/OfferRound.php` and `sed -n '/private function escalate(/,/^    }/p' src/Negotiation/NegotiationPipeline.php`

Read both. They must be equivalent apart from parameter names and the `?? QuoteEscalationReason::NeedsHumanReview` default. If they have diverged, stop and re-read the spec's "How the pipeline stays at five parameters" before proceeding.

- [ ] **Step 2: Make `escalated()` public**

In `src/Negotiation/OfferRound.php`, change the visibility and document why:

```php
    /**
     * Public because NegotiationPipeline's gate escalates through it too: its
     * own escalate() was a duplicate of this, and routing both through one
     * method is what keeps the pipeline inside the five-parameter cap once it
     * takes the audit recorder.
     */
    public function escalated(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        ?QuoteEscalationReason $reason,
        ?string $extractHash,
        ?string $negotiateHash,
    ): NegotiationPass {
```

- [ ] **Step 3: Delete the pipeline's escalate() and route through the round**

In `src/Negotiation/NegotiationPipeline.php`, remove the `private function escalate(...)` method and the `QuoteEscalator` constructor property and its import. Replace each of the four call sites:

```php
// was: return $this->escalate($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable);
$pass = $this->round->escalated($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable, null, null);

// was: return $this->escalate($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview, $ask->promptHash);
return $this->round->escalated($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview, $ask->promptHash, null);

// was: return $this->escalate($gateway, $snapshot, $reason, $ask->promptHash);
return $this->round->escalated($gateway, $snapshot, $reason, $ask->promptHash, null);
```

- [ ] **Step 4: Update the harness**

In `tests/Unit/Negotiation/PipelineHarness.php`, drop the fifth `$escalator` argument to `new NegotiationPipeline(...)`. The `OfferRound` still takes it.

- [ ] **Step 5: Run the suite — the point is that nothing changes**

Run: `./vendor/bin/phpunit --testsuite unit && composer run test:integration`
Expected: all green with **no test edits**. Every existing escalation test still passes, which is the evidence the refactor is behaviour-preserving. If a test needed changing, the refactor was not equivalent — revert and re-read Step 1.

- [ ] **Step 6: Verify the gates and commit**

```bash
composer run quality
git add -A
git commit -m "refactor: route the pipeline's escalations through OfferRound"
```

---

## Task 6: The pipeline opens and closes the pass

**Files:**
- Modify: `src/Negotiation/NegotiationPipeline.php`
- Modify: `tests/Unit/Negotiation/PipelineHarness.php`
- Test: `tests/Unit/Negotiation/RecordedPassTest.php`

**Interfaces:**
- Consumes: `DecisionRecorder` (Task 2), the free constructor slot (Task 5), `PassContext` (Task 4).
- Produces: every pass through `service()` writes exactly one draft.

**Context:** The `finally` is the whole point — it is what makes "exactly one record, including every failure path" true rather than aspirational. `$pass` must be initialised to `null` before the `try`: when an exception unwinds before the assignment, a `finally` referencing an unassigned variable is itself a fatal error.

The swallow lives here, not in the writer: an audit failure must never fail a pass, because a thrown write would roll the message into Messenger's retry and re-answer the buyer.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/RecordedPassTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * One record per pass, on every path. The paths are the point: a record that
 * only appears when the agent succeeds tells #21 nothing about why the other
 * 3,000 quotes did not get an offer.
 */
final class RecordedPassTest extends TestCase
{
    public function testAnOfferedPassWritesOneRecordCarryingWhatTheBuyerWasTold(): void
    {
        $harness = PipelineHarness::with([
            '{"additional_discount_percent":5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertCount(1, $harness->writer->drafts);

        $draft = $harness->writer->drafts[0];
        self::assertSame('offered', $draft->outcome);
        self::assertSame('grant', $draft->band);
        self::assertSame('q1', $draft->quoteId);
        self::assertSame(1000.0, $draft->totalNetBefore);
        self::assertNotNull($draft->buyerComment);
        self::assertNotNull($draft->interpretedAsks);
        self::assertIsInt($draft->durationMs);
    }

    public function testAnEscalatedPassWritesOneRecordCarryingTheReason(): void
    {
        $harness = PipelineHarness::with([
            '{"line_changes":[{"line_item_id":"line-1","quantity":20,"target_unit_price":null,"remove":false}]}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('make it 20 units', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
    }

    public function testANothingToDoPassStillWritesARecord(): void
    {
        // #21 needs to distinguish "the agent decided not to act" from "the
        // agent never ran". Without a record for this path they look the same.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('nothing_to_do', $harness->writer->drafts[0]->outcome);
    }

    public function testAModelFailureWritesARecordWithTheEscalatedOutcome(): void
    {
        $harness = PipelineHarness::with([]);
        $harness->spy->failNext = true;
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
    }

    public function testAnAuditWriteFailureDoesNotFailThePass(): void
    {
        // A thrown audit write would roll the message back into Messenger's
        // retry and re-answer the buyer. A missing record beats a duplicated
        // buyer message.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent":5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ]);
        $harness->writer->throws = new \RuntimeException('the database is on fire');
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertNotNull($harness->logger->contextOf('could not be recorded'));
    }
}
```

`ScriptedClient` may not have a `failNext` flag. If it does not, drop `testAModelFailureWritesARecordWithTheEscalatedOutcome`'s use of it and instead script an empty reply list, which already raises `ModelUnavailable` on the first call ("Mock queue is empty" surfaces as a Guzzle failure).

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter RecordedPassTest`
Expected: FAIL — `PipelineHarness` has no `writer` property.

- [ ] **Step 3: Take the recorder in the pipeline**

In `src/Negotiation/NegotiationPipeline.php`, add `DecisionRecorder` to the constructor in the slot `QuoteEscalator` vacated:

```php
    public function __construct(
        private AskInterpreter $interpreter,
        private NegotiationDecider $decider,
        private OfferRound $round,
        private DecisionRecorder $recorder,
        private LoggerInterface $logger,
    ) {}
```

- [ ] **Step 4: Open and close the pass**

Rewrite `service()`:

```php
    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $this->recorder->begin($snapshot, $context);
        $pass = null;
        $error = null;

        try {
            $pass = $this->run($snapshot, $gateway, $settings);

            return $pass->outcome;
        } catch (\Throwable $e) {
            $error = $e;

            throw $e;
        } finally {
            $this->record($pass, $error, $snapshot, $context);
        }
    }

    /** Catches ModelUnavailable; every other throwable belongs to the caller. */
    private function run(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationPass {
        try {
            return $this->negotiate($snapshot, $gateway, $settings);
        } catch (ModelUnavailable $e) {
            $this->logger->error('The model was unavailable, so this quote goes to a human.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);

            return $this->round->escalated($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable, null, null);
        }
    }

    /**
     * The audit write must never fail a pass: a throw here would roll the
     * message back into Messenger's retry and re-answer the buyer, which is
     * the one failure #18 spends the most effort preventing. The structured
     * log event below stays as the backstop when the write is lost.
     */
    private function record(
        ?NegotiationPass $pass,
        ?\Throwable $error,
        QuoteSnapshot $snapshot,
        PassContext $context,
    ): void {
        try {
            $this->recorder->finish($pass, $error);
        } catch (\Throwable $e) {
            $this->logger->error('The negotiation pass could not be recorded; the pass itself stands.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);
        }

        $this->logger->info('A quote negotiation pass finished.', [
            'outcome' => $pass?->outcome->value,
            'trigger' => $context->reason->value,
            'attempt' => $context->attempt,
            'quoteId' => $snapshot->identity->quoteId,
            'salesChannelId' => $snapshot->identity->salesChannelId,
            'extractPromptHash' => $pass?->extractHash,
            'negotiatePromptHash' => $pass?->negotiateHash,
            'replyPromptHash' => $pass?->replyHash,
        ]);
    }
```

**Watch the complexity budget.** `NegotiationPipeline` is class-scoped at 10 and this adds branches. Run `./vendor/bin/mago lint src/Negotiation/NegotiationPipeline.php` after this step, not at the end. If it trips, move `record()` into a small collaborator rather than deleting a branch.

- [ ] **Step 5: Record the decision**

In `negotiate()`, right after `$decision = $this->decider->decide(...)`:

```php
$this->recorder->recordDecision($decision, $settings->policy->price->maxDiscountPercent);
```

- [ ] **Step 6: Give the harness a writer**

In `tests/Unit/Negotiation/PipelineHarness.php`, add the fake writer and expose it:

```php
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;

    private function __construct(
        public NegotiationPipeline $pipeline,
        public FakeQuoteGateway $gateway,
        public ScriptedClient $spy,
        public RecordingLogger $logger,
        public FakeDecisionWriter $writer,
    ) {}
```

Build `$writer = new FakeDecisionWriter();` and `$recorder = new DecisionRecorder($writer);` in `with()`, pass the recorder into `new NegotiationPipeline(...)`, and return the writer.

`PipelineHarness::__construct` now has five parameters — at the cap. Do not add a sixth.

Add `NegotiationFixture::context()`:

```php
    public static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
    }
```

- [ ] **Step 7: Run everything**

Run: `./vendor/bin/phpunit --testsuite unit --filter RecordedPassTest`
Expected: PASS.

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: all green.

- [ ] **Step 8: Verify the gates and commit**

```bash
composer run quality
git add -A
git commit -m "feat: write one audit record per negotiation pass"
```

---

## Task 7: The stages record what they know

**Files:**
- Modify: `src/Negotiation/AskInterpreter.php`
- Modify: `src/Negotiation/OfferProposer.php`
- Modify: `src/Negotiation/OfferApplier.php`
- Modify: `src/Negotiation/ReplyComposer.php`
- Modify: `tests/Unit/Negotiation/PipelineHarness.php`
- Test: extend `tests/Unit/Negotiation/RecordedPassTest.php`

**Interfaces:**
- Consumes: `DecisionRecorder` (Task 2). Each stage takes it as one extra constructor parameter; all four are below the cap (2, 3, 2, 3 parameters respectively).

**Context:** Four small, same-shaped edits. `OfferProposer` needs one real change beyond injection: it currently passes `$this->client->complete(...)` straight into `NegotiateResponse::read()` at line 57, so the raw model text is never in a variable and cannot be recorded. Capture it first.

- [ ] **Step 1: Write the failing assertions**

Add to `tests/Unit/Negotiation/RecordedPassTest.php`:

```php
public function testAnOfferedPassRecordsEveryStageItPassedThrough(): void
{
    $harness = PipelineHarness::with([
        '{"additional_discount_percent":5}',
        '{"action":"offer","discount_percent":5,"message":"5% off."}',
        'We can offer 5% off.',
    ]);
    $snapshot = NegotiationFixture::snapshot(comments: [
        NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
    ]);

    $harness->pipeline->service(
        $snapshot,
        $harness->gateway,
        NegotiationFixture::settings(),
        NegotiationFixture::context(),
    );

    $draft = $harness->writer->drafts[0];

    self::assertNotNull($draft->interpretedAsks, 'AskInterpreter did not record.');
    self::assertNotNull($draft->rawProposal, 'OfferProposer did not record the raw answer.');
    self::assertTrue($draft->authorized, 'OfferProposer did not record the authorization result.');
    self::assertTrue($draft->verified, 'OfferApplier did not record the verification result.');
    self::assertNotNull($draft->writes, 'OfferApplier did not record what it wrote.');
    self::assertContains('recalculate', $draft->writes);
    self::assertSame(950.0, $draft->totalNetAfter);
    self::assertStringContainsString('%', (string) $draft->buyerComment);
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter testAnOfferedPassRecordsEveryStageItPassedThrough`
Expected: FAIL on `interpretedAsks` being null.

- [ ] **Step 3: AskInterpreter**

Add `private DecisionRecorder $recorder` to the constructor (2 → 3 parameters). At the end of `interpret()`, before returning:

```php
$ask = new InterpretedAsk(ExtractResponse::toInterpretation($answer), $prompt->hash);
$this->recorder->recordAsk($ask);

return $ask;
```

- [ ] **Step 4: OfferProposer**

Add `private DecisionRecorder $recorder` to the constructor (3 → 4 parameters). Capture the raw response before parsing:

```php
// was: $response = NegotiateResponse::read($this->client->complete(...));
$raw = $this->client->complete($access, $prompt->text, $userPrompt, json: true);
$response = NegotiateResponse::read($raw);
```

Then, wherever the method builds its `ProposedAnswer` — on both the offer and the escalate paths — record before returning:

```php
$this->recorder->recordProposal($raw, $answer);

return $answer;
```

If the method has multiple returns, extract the recording into a tiny private helper `private function recorded(string $raw, ProposedAnswer $answer): ProposedAnswer` that records and returns, and wrap each return in it. Watch the class-scoped complexity budget of 10.

- [ ] **Step 5: OfferApplier**

Add `private DecisionRecorder $recorder` to the constructor (2 → 3 parameters). Track the writes as they happen and record with the result:

```php
    public function apply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
    ): AppliedOffer {
        // ... existing body, collecting write names:
        $writes = ['claim'];
        // in write(): 'updateLineItems' / 'updateQuote' as taken
        $writes[] = 'recalculate';

        $applied = new AppliedOffer($violations === [], $violations, $after);
        $this->recorder->recordApplied($applied, $writes);

        return $applied;
    }
```

`write()` currently returns `void`. Change it to `: array` returning the list of write names it performed, and merge that into `$writes`. Keep the method's docblock and its revision-precondition comment intact — that comment records a bug that took a live-shop probe to find.

- [ ] **Step 6: ReplyComposer**

Add `private DecisionRecorder $recorder` to the constructor (3 → 4 parameters). In `reply()`, after the comment is posted:

```php
$gateway->addComment($after->identity->quoteId, $text);
$this->recorder->recordReply($text, $hash);
$this->send($gateway, $after->identity->quoteId);
```

Record the text that was actually sent, after any rewording — that is what the buyer read, and it is the field #19 calls out as the most useful and the most sensitive.

- [ ] **Step 7: Wire the recorder through the harness**

In `tests/Unit/Negotiation/PipelineHarness.php`, pass `$recorder` into each of the four stage constructors.

- [ ] **Step 8: Run everything**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: all green.

- [ ] **Step 9: Verify the gates and commit**

```bash
composer run quality
git add -A
git commit -m "feat: record each negotiation stage into the pass's audit draft"
```

---

## Task 8: The model client reports tokens and latency

**Files:**
- Modify: `src/Negotiation/ChatCompletionClient.php`
- Modify: `tests/Unit/Negotiation/PipelineHarness.php` and `tests/Unit/Negotiation/ScriptedClient.php` if it constructs the client
- Test: `tests/Unit/Negotiation/ChatCompletionClientTest.php` (existing file — add a case)

**Interfaces:**
- Consumes: `DecisionRecorder::recordModelCall(string $model, string $host, ?int $promptTokens, ?int $completionTokens, int $latencyMs): void`.
- Produces: no change to `complete()`'s signature or return type.

**Context:** This is the payoff for the recorder approach. `complete()` keeps returning `string`, so all three call sites are untouched; the client reports usage from inside `send()`. Tokens come from the OpenAI-shaped `usage` block, which is absent on some providers — hence the nullable ints.

Values accumulate across the pass: three calls produce one summed `promptTokens` and one summed `modelLatencyMs`. That is what #21 wants — cost and latency per negotiation, not per call.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Negotiation/ChatCompletionClientTest.php`:

```php
public function testUsageAndLatencyAreRecordedWithoutChangingWhatIsReturned(): void
{
    $writer = new FakeDecisionWriter();
    $recorder = new DecisionRecorder($writer);
    $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

    $body = json_encode([
        'choices' => [['message' => ['content' => 'the answer']]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
    ], JSON_THROW_ON_ERROR);

    $client = self::clientReturning($body, $recorder);
    $access = new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini');

    $answer = $client->complete($access, 'system', 'user', json: false);

    self::assertSame('the answer', $answer, 'The return type must not change.');

    $recorder->finish(null);
    $draft = $writer->drafts[0];

    self::assertSame(120, $draft->promptTokens);
    self::assertSame(30, $draft->completionTokens);
    self::assertSame('gpt-4o-mini', $draft->model);
    self::assertSame('api.example.com', $draft->modelHost);
    self::assertNotNull($draft->modelLatencyMs);
}

public function testAProviderThatSendsNoUsageBlockStillRecordsTheCall(): void
{
    $writer = new FakeDecisionWriter();
    $recorder = new DecisionRecorder($writer);
    $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

    $body = json_encode(
        ['choices' => [['message' => ['content' => 'the answer']]]],
        JSON_THROW_ON_ERROR,
    );

    self::clientReturning($body, $recorder)->complete(
        new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
        'system',
        'user',
        json: false,
    );

    $recorder->finish(null);

    self::assertSame('gpt-4o-mini', $writer->drafts[0]->model);
    self::assertSame(0, $writer->drafts[0]->promptTokens);
}
```

Add a `clientReturning(string $body, DecisionRecorder $recorder): ChatCompletionClient` helper following the Guzzle `MockHandler` pattern already used in that file.

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter ChatCompletionClientTest`
Expected: FAIL — the constructor takes two arguments.

- [ ] **Step 3: Record inside send()**

In `src/Negotiation/ChatCompletionClient.php`, add `private DecisionRecorder $recorder` (2 → 3 parameters) and time the request:

```php
    /** @throws ModelUnavailable|GuzzleException */
    private function send(ModelAccess $access, string $system, string $user, bool $json): string
    {
        // ... existing $payload build

        $startedAt = microtime(true);

        $response = $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $access->apiKey],
            'json' => $payload,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        $body = (string) $response->getBody();

        $this->recorder->recordModelCall(
            $access->model,
            parse_url($access->baseUrl, PHP_URL_HOST) ?: $access->baseUrl,
            self::usage($body, 'prompt_tokens'),
            self::usage($body, 'completion_tokens'),
            (int) round((microtime(true) - $startedAt) * 1000),
        );

        return self::content($body);
    }

    /**
     * The `usage` block is OpenAI's shape and not every provider sends it, so
     * a missing count is null rather than an error — a model call that
     * happened is worth recording even when its cost is unknown.
     */
    private static function usage(string $body, string $key): ?int
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $value = \is_array($decoded) ? ($decoded['usage'][$key] ?? null) : null;

        return \is_int($value) ? $value : null;
    }
```

Record the base-URL **host only**, never the full URL — the URL can carry a key in a query string on some gateways.

**Watch the class-scoped complexity budget.** `ChatCompletionClient` now has `complete`, `send`, `content`, `usage`. Run `./vendor/bin/mago lint src/Negotiation/ChatCompletionClient.php` immediately.

- [ ] **Step 4: Update the harness and any direct constructions**

Every `new ChatCompletionClient(...)` in tests takes a third argument. `ScriptedClient::spy()` builds one — pass a recorder through.

- [ ] **Step 5: Run everything**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: all green.

- [ ] **Step 6: Verify the gates and commit**

```bash
composer run quality
git add -A
git commit -m "feat: record model tokens and latency without changing the client's return"
```

---

## Task 9: Every remaining path writes exactly one record

**Files:**
- Test: `tests/Unit/Negotiation/RecordedPassTest.php`

**Interfaces:** none — this task adds only tests.

**Context:** Tasks 6 and 7 covered four of the ten paths. This closes the rest, so the Done-when clause "every path through #18 writes exactly one record, including every failure path" is evidenced rather than asserted.

The ten paths: nothing-to-do; structural ask; non-price ask; band-escalate; no offer proposed; per-line round two; verification failed; offered; countered; a thrown non-`ModelUnavailable` error.

- [ ] **Step 1: Add the remaining path tests**

```php
public function testACounteredPassRecordsTheCounterBand(): void
{
    $harness = PipelineHarness::with([
        '{"additional_discount_percent":15}',
        '{"action":"offer","discount_percent":10,"message":"10% is our limit."}',
        'We can offer 10%.',
    ]);
    $snapshot = NegotiationFixture::snapshot(comments: [
        NegotiationFixture::buyerComment('15% off?', '2026-08-28 09:00:00'),
    ]);

    $harness->pipeline->service(
        $snapshot,
        $harness->gateway,
        NegotiationFixture::settings(),
        NegotiationFixture::context(),
    );

    self::assertCount(1, $harness->writer->drafts);
    self::assertSame('countered', $harness->writer->drafts[0]->outcome);
    self::assertSame('counter', $harness->writer->drafts[0]->band);
}

public function testANonPriceAskRecordsOneEscalatedRecord(): void
{
    $harness = PipelineHarness::with([
        '{"additional_discount_percent":5,"negotiation":{"payment":{"requested_net_days":30}}}',
    ]);
    $snapshot = NegotiationFixture::snapshot(comments: [
        NegotiationFixture::buyerComment('5% and net 30?', '2026-08-28 09:00:00'),
    ]);

    $harness->pipeline->service(
        $snapshot,
        $harness->gateway,
        NegotiationFixture::settings(),
        NegotiationFixture::context(),
    );

    self::assertCount(1, $harness->writer->drafts);
    self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
    self::assertNotNull($harness->writer->drafts[0]->interpretedAsks);
}

public function testAnOutOfAuthorityAskRecordsTheBandThatRefusedIt(): void
{
    $harness = PipelineHarness::with(['{"additional_discount_percent":80}']);
    $snapshot = NegotiationFixture::snapshot(comments: [
        NegotiationFixture::buyerComment('80% off?', '2026-08-28 09:00:00'),
    ]);

    $harness->pipeline->service(
        $snapshot,
        $harness->gateway,
        NegotiationFixture::settings(),
        NegotiationFixture::context(),
    );

    self::assertCount(1, $harness->writer->drafts);
    self::assertSame('escalate', $harness->writer->drafts[0]->band);
    self::assertSame(10.0, $harness->writer->drafts[0]->maxDiscountPercent);
}

public function testASecondRoundPerLineAskRecordsOneRecord(): void
{
    $harness = PipelineHarness::with([
        '{"line_changes":[{"line_item_id":"line-1","quantity":null,"target_unit_price":85,"remove":false}]}',
        '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":95}],"message":"95 each."}',
    ]);
    $snapshot = NegotiationFixture::snapshot(comments: [
        NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
        NegotiationFixture::agentComment('95 each it is.', '2026-08-28 09:30:00'),
        NegotiationFixture::buyerComment('make it 85 per unit', '2026-08-28 10:00:00'),
    ]);

    $harness->pipeline->service(
        $snapshot,
        $harness->gateway,
        NegotiationFixture::settings(),
        NegotiationFixture::context(),
    );

    self::assertCount(1, $harness->writer->drafts);
    self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
}

public function testAThrownGatewayFailureStillWritesARecordAndRethrows(): void
{
    // The finally is the whole point: a pass that dies mid-write is exactly
    // the pass a merchant most needs a record of.
    $harness = PipelineHarness::with([
        '{"additional_discount_percent":5}',
        '{"action":"offer","discount_percent":5,"message":"5% off."}',
    ]);
    $harness->gateway->updateQuoteThrows = new \RuntimeException('the shop is down');
    $snapshot = NegotiationFixture::snapshot(comments: [
        NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
    ]);

    try {
        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );
        self::fail('The gateway failure should have propagated.');
    } catch (\RuntimeException) {
        // expected
    }

    self::assertCount(1, $harness->writer->drafts);
    self::assertNull($harness->writer->drafts[0]->outcome, 'A thrown pass has no outcome.');
    self::assertSame(\RuntimeException::class, $harness->writer->drafts[0]->errorClass);
    self::assertNotNull($harness->writer->drafts[0]->errorChain);
}
```

`FakeQuoteGateway` may not have `updateQuoteThrows`. Add it beside the existing `transitionThrows` property, following the same pattern (throw once, then clear).

- [ ] **Step 2: Run them**

Run: `./vendor/bin/phpunit --testsuite unit --filter RecordedPassTest`
Expected: PASS. Any failure here is a real gap in coverage, not a test bug — fix the pipeline, not the assertion.

- [ ] **Step 3: Verify the gates and commit**

```bash
composer run quality
git add -A
git commit -m "test: every path through the pipeline writes exactly one record"
```

---

## Task 10: The record works against the real shop

**Files:**
- Test: `tests/Integration/DecisionRecordTest.php` (extend Task 1's file)

**Interfaces:** none — this task adds only tests.

**Context:** The parts that cannot be faked: that a real pass writes a real row, and that the aggregations #21 depends on actually run against these column types. Discovering during a 10,000-negotiation run that `discountPercentGranted` will not average is not a discovery worth making then.

`tests/Integration/NegotiationPipelineTest.php` already builds a real pipeline from the container with only the model scripted — reuse `pipelineWith()` and `enabledSettings()` from it, or lift them into a shared trait.

- [ ] **Step 1: Add the real-pass test**

```php
public function testARealPassWritesARealRow(): void
{
    $gateway = static::gateway();
    $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
    self::writeBuyerComment($quoteId, 'Could you do 5% off?');

    self::pipelineWith([
        '{"additional_discount_percent": 5}',
        '{"action":"offer","discount_percent":5,"message":"5% off."}',
        'We can offer 5% off.',
    ])->service(
        $gateway->fetchSnapshot($quoteId),
        $gateway,
        self::enabledSettings(),
        new PassContext(ServicingTriggerReason::CommentWritten, 0),
    );

    $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
    self::assertInstanceOf(EntityRepository::class, $repository);

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
    $record = $repository->search($criteria, Context::createDefaultContext())->first();

    self::assertNotNull($record, 'A real pass wrote no audit record.');
    self::assertSame('offered', $record->outcome);
    self::assertSame('grant', $record->band);
    self::assertSame('comment_written', $record->trigger);
    self::assertNotNull($record->buyerComment);
    self::assertNotNull($record->interpretedAsks);
    self::assertIsInt($record->durationMs);
}
```

- [ ] **Step 2: Add the aggregation test**

```php
public function testTheAggregationsTheTestRoundNeedsActuallyRun(): void
{
    // #21 reads outcome shares and average granted discount off this table.
    // Proving the column types aggregate is cheap now and expensive later.
    $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
    self::assertInstanceOf(EntityRepository::class, $repository);

    $quoteId = Uuid::randomHex();
    $repository->create([
        self::row($quoteId, 'offered', 5.0),
        self::row($quoteId, 'offered', 7.0),
        self::row($quoteId, 'escalated', null),
    ], Context::createDefaultContext());

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
    $criteria->addAggregation(new TermsAggregation('by-outcome', 'outcome'));
    $criteria->addAggregation(new AvgAggregation('avg-discount', 'discountPercentGranted'));

    $result = $repository->aggregate($criteria, Context::createDefaultContext());

    $byOutcome = $result->get('by-outcome');
    self::assertInstanceOf(TermsResult::class, $byOutcome);
    self::assertSame(2, $byOutcome->get('offered')?->getCount());

    $average = $result->get('avg-discount');
    self::assertInstanceOf(AvgResult::class, $average);
    self::assertSame(6.0, $average->getAvg());
}

/** @return array<string, mixed> */
private static function row(string $quoteId, string $outcome, ?float $discount): array
{
    return [
        'id' => Uuid::randomHex(),
        'quoteId' => $quoteId,
        'outcome' => $outcome,
        'discountPercentGranted' => $discount,
        'durationMs' => 100,
    ];
}
```

Imports needed: `Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation`, `...\Bucket\TermsAggregation`, `...\AggregationResult\Metric\AvgResult`, `...\AggregationResult\Bucket\TermsResult`, `...\Search\Filter\EqualsFilter`.

- [ ] **Step 3: Run the integration suite**

Run: `composer run test:integration -- --filter DecisionRecordTest`
Expected: PASS.

Run: `composer run test:integration`
Expected: all green — the `service()` signature change in Task 4 touched every integration caller.

- [ ] **Step 4: Full verification**

```bash
./vendor/bin/phpunit --testsuite unit
composer run test:integration
composer run quality
```

All three must be clean.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test: a real pass writes a real row and the run's aggregations work"
```

---

## Task 11: File the follow-ups

**Files:** none — GitHub only.

**Context:** The spec defers four things, and a deferral with no issue behind it is just a thing that got dropped. #19 itself stays **open** after this work: the export carries its third Done-when.

- [ ] **Step 1: Open the terminal-outcome issue**

Title: `2.4a Attach the terminal quote outcome to each decision record`
Body must state: the columns `terminal_state` and `terminal_at` already exist and need no migration; the work is an event subscriber on quote state transitions writing back onto the most recent record for that quote; #22 is the consumer; without it nobody can tell a good offer from a bad one.

- [ ] **Step 2: Open the export issue**

Title: `2.4b Anonymized JSONL export of the decision records`
Body must state: `bin/console merchant-quote-agent:export --from --to [--include-comments]`; anonymization on by default with customer, employee and user ids pseudonymized under a per-shop HMAC salt; names, emails and addresses dropped; amounts, discounts, decisions and prompt version kept; buyer comments excluded unless `--include-comments`, and the command says so. Carries #19's third Done-when: a test greps the export for the fixture's email and customer number and finds neither.

- [ ] **Step 3: Open the preflight-coverage issue**

Title: `2.4c Record the passes that never reach the pipeline`
Body must state: `ServicingPreflight` escalates a misconfigured channel's quote, and that quote currently has an empty audit trail; the pipeline is the sole owner of the record lifecycle by design, so this needs a deliberate second entry point; file against #7, which is what makes the absence visible.

- [ ] **Step 4: Comment on #19**

State what shipped, that the entity and write path are complete, and that #19 stays open on the export. Link all three follow-ups.

---

## Self-Review

**Spec coverage:** entity and migration → Task 1. Recorder and draft → Task 2. Writer → Task 3. `PassContext` → Task 4. Dropping `QuoteEscalator` → Task 5. `begin`/`finish` in a `finally`, and the swallow → Task 6. Stage recording → Task 7. Tokens and latency → Task 8. Ten-path coverage, reset, and writer-throws → Tasks 2, 6, 9. Integration coverage → Tasks 1, 10. Deferred items → Task 11. The `api: true`/admin-only constraint is carried in Task 1's entity; the Store API is never touched, so there is nothing to exclude.

**Placeholders:** none. Every code step carries the code.

**Type consistency:** `DecisionRecorder`'s eight methods are declared identically in Task 2's Interfaces block and used unchanged in Tasks 6, 7 and 8. `recordDecision` takes `?float $maxDiscountPercent` in both the interface block and the implementation. `recordProposal(string $rawResponse, ProposedAnswer $answer)` matches Task 7's usage. `finish(?NegotiationPass $pass, ?\Throwable $error = null)` matches Task 6's call. `DecisionRecordWriterInterface::write(DecisionDraft): void` matches `FakeDecisionWriter` and `DecisionRecordWriter`.

**Field names, verified against the source rather than assumed:** `QuoteIdentity` carries `quoteId`, `quoteNumber`, `currencyIso`, `salesChannelId`; `QuoteRevision` carries `versionId` and `updatedAt`. Task 2's `begin()` uses exactly these. `ServiceQuoteMessage::$reason` is a `string`, so Task 4's `ServicingTriggerReason::from($message->reason)` is required — the enum is not stored on the message.

**The one thing the implementer must watch rather than trust: the class-scoped complexity budget.** Tasks 6, 7 and 8 each add branches to classes that were already built up against that gate, and it sums across every method in the class, so extracting a private helper does not relieve it. Lint the single file at the end of each of those steps rather than discovering it in the final `composer run quality`.
