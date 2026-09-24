# Run Trace Capture — PR 1 (Pass Traces) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every servicing pass leaves a trace behind: every model call (full prompt, raw answer, per-call figures, failures), the policy verdict, a rejected reply rewrite, and the quote before and after. The existing export carries all of it.

**Architecture:**
- A new DAL attribute entity, `Audit\TraceEvent` (table `merchant_quote_agent_trace`), holds one row per event.
- `DecisionRecorder` buffers a pass's events on the `DecisionDraft`. `DecisionRecordWriter` writes them after the decision row, under the decision id that `begin()` now generates.
- `DecisionExportStream` nests each decision's events into its JSONL line as `trace`.
- `meta` (structured, allowlisted per kind) always leaves. `content` (prompts, answers, snapshots) leaves only with free text, and the eraser clears it.

**Tech Stack:**
- PHP 8.3
- Shopware 6.7 DAL attribute entities
- Symfony HttpClient (`RetryableHttpClient`, `MockHttpClient`)
- PHPUnit
- Mago (fmt, lint, analyze)

**Spec:** `docs/superpowers/specs/2026-09-23-run-trace-capture-design.md`. This plan implements its **Delivery → PR 1** and only that. PR 2 (skipped runs) and PR 3 (protocol traffic) get their own plans.

## Global Constraints

- **Language rules:**
  - `declare(strict_types=1);` in every PHP file.
  - Target PHP 8.3.
  - Mago analyze runs at full strictness, so no `mixed` escape hatches and no unsafe casts.
- **Gate thresholds** (per method or class as Mago applies them):
  - cyclomatic complexity 10;
  - nesting depth 4;
  - 5 parameters;
  - about 400 lines per file.
- **DAL attributes:** no `maxLength:` argument on any `#[Field]`. It does not exist at the 6.7.1 support floor, and an unknown attribute argument takes the whole shop down during the container build. Use only the attribute arguments `QuoteDecisionRecord` already uses: `type`, `api`, and `#[Protection(write: [...])]`.
- **Namespace boundaries:**
  - `src/Negotiation` must not import Shopware (only `IllegalTransitionException` is allowed, per `NamespacePurityTest`).
  - `src/Policy` imports nothing from Shopware and nothing from Audit.
- **Audit writes never fail a pass.** Anything on the recording path must not throw into `NegotiationPipeline`.
- **Secrets stay out of traces.** Nothing records HTTP headers or the model API key. The key travels as `auth_bearer`, never in the JSON body.
- **Cap on stored strings:** every string leaf in a trace's `content` is capped at **65536 bytes** (`TracePayload::MAX_STRING_BYTES`). A cut is recorded in `meta.truncated` as a list of dotted paths.
- **Trace kinds and their `meta` keys** (exact, from spec §2):
  - `model_call`: `purpose`, `requestedModel`, `servedModel`, `host`, `status`, `httpStatus`, `latencyMs`, `promptTokens`, `completionTokens`, `cachedTokens`, `reasoningTokens`, `finishReason`, `retries`, `errorClass`;
  - `reply_guard`: `accepted`;
  - `policy_verdict`: `overall`, `priceKind`, `escalationReason`, `requestedDiscountPercent`, `discountPercent`, `perLineAsks`, `validityDays`, `counteredRequestPercent`, `escalationReasons`;
  - `quote_before` / `quote_after`: `lineCount`.

  The optional `truncated` key may appear on any kind.
- **Values of `model_call.status`:** `ok`, `failed`, `unusable_answer`.
- **Values of `model_call.purpose`:** `extract`, `negotiate`, `reply`, `other`.
- **Commands:**
  - unit tests: `vendor/bin/phpunit --filter <Name>`;
  - integration tests: `composer run test:integration -- --filter <Name>` (needs the test shop, see README);
  - before every commit: `composer run format && composer run lint && composer run typecheck`.
- **Commits:** end the message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Commit signing goes through the 1Password desktop app. If a commit fails on signing, retry the same command once; do not disable signing.
- **Comment style:** match the codebase. Docblocks explain *why*, in full sentences, and reference the spec or issue. Deliberate shortcuts are marked `// ponytail:` with their ceiling.

---

## File Structure

**Create:**
- `src/Audit/TraceKind.php`: enum of event kinds, plus each kind's declared `meta` keys (the allowlist).
- `src/Audit/TraceEvent.php`: DAL attribute entity `merchant_quote_agent_trace`.
- `src/Audit/TracePayload.php`: turns any value into a JSON-shaped array (never throws) and applies the 64 KB string cap.
- `src/Audit/TraceDraft.php`: one buffered event. It appends itself to a draft and becomes a DAL payload.
- `src/Audit/VerdictTrace.php`: maps `NegotiationDecision` to `[meta, content]`.
- `src/Audit/Erasure.php`: what one erasure changed, as `{decisions, traces}`.
- `src/Audit/Export/AnonymizedTrace.php`: one trace row as one element of a decision line's `trace`.
- `src/Migration/Migration1789800000CreateQuoteAgentTrace.php`: creates the table.
- `src/Negotiation/ModelCallPurpose.php`: enum for which prompt a call served, read off the answer type.
- `src/Negotiation/ModelCallTrace.php`: one model call as `[meta, content]`.
- Tests:
  - `tests/Unit/Audit/TraceKindTest.php`
  - `tests/Unit/Audit/CreateQuoteAgentTraceMigrationTest.php`
  - `tests/Unit/Audit/TracePayloadTest.php`
  - `tests/Unit/Audit/TraceDraftTest.php`
  - `tests/Unit/Audit/VerdictTraceTest.php`
  - `tests/Unit/Audit/Export/AnonymizedTraceTest.php`
  - `tests/Unit/Audit/Export/TraceMetaCoverageTest.php`
  - `tests/Unit/Negotiation/ModelPlatformTraceTest.php`
  - `tests/Integration/TraceEventTest.php`

**Modify:**
- `src/Audit/DecisionDraft.php`: `$id` generated in the constructor, plus `$trace`.
- `src/Audit/DecisionRecorder.php`: `trace()` and `decisionId()`; the `quote_before`, `policy_verdict` and `quote_after` events.
- `src/Audit/DecisionRecordWriter.php`: use the draft's id; write the trace rows; log a failed trace write and swallow it.
- `src/Audit/DecisionEraser.php` and `src/Audit/DecisionEraserInterface.php`: clear trace `content` and `customerId`; return `Erasure`.
- `src/Audit/Export/DecisionExportStream.php`: `record: "decision"` and nested `trace`.
- `src/Command/DecisionExportCommand.php`: notice and option wording.
- `src/Command/DecisionForgetCommand.php`: report both counts.
- `src/Negotiation/ModelPlatform.php`: trace every call.
- `src/Negotiation/ModelRetryStrategy.php`: remember the attempts that were retried.
- `src/Negotiation/Response/ChatEnvelope.php`: usage by path, `finishReason()`, `servedModel()`.
- `src/Negotiation/ReplyComposer.php`: the `reply_guard` event.
- `src/Negotiation/NegotiationPipeline.php`: `decisionId` in the per-pass log line.
- `src/MerchantQuoteAgentPlugin.php`: the uninstall drop list.
- `src/Resources/config/services.php`: entity and repository wiring.
- `src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts`: viewer privilege.
- `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json` and `de.json`: export menu label.
- `docs/for-merchants.md`: traces, and the changed promise.
- `docs/superpowers/specs/2026-09-23-run-trace-capture-design.md`: one paragraph on the changed promise (Task 6).
- Tests:
  - `tests/Unit/Audit/DraftMirrorsEntityTest.php`
  - `tests/Unit/Audit/DecisionRecordWriterTest.php`
  - `tests/Unit/Audit/DecisionRecorderTest.php`
  - `tests/Unit/Audit/DecisionEraserTest.php`
  - `tests/Unit/Audit/Export/DecisionExportControllerTest.php`
  - `tests/Unit/Command/DecisionExportCommandTest.php`
  - `tests/Unit/Command/DecisionForgetCommandTest.php`
  - `tests/Unit/Negotiation/ReplyComposerTest.php`
  - `tests/Integration/DecisionRecordTest.php`
  - `tests/Integration/DecisionExportTest.php`

---

### Task 1: The trace table

**Files:**
- Create: `src/Audit/TraceKind.php`, `src/Audit/TraceEvent.php`, `src/Migration/Migration1789800000CreateQuoteAgentTrace.php`
- Modify: `src/MerchantQuoteAgentPlugin.php` (the drop list near line 237), `src/Resources/config/services.php` (near line 372), `src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts` (the viewer `privileges`, line 49)
- Test: `tests/Unit/Audit/TraceKindTest.php`, `tests/Unit/Audit/CreateQuoteAgentTraceMigrationTest.php`, `tests/Integration/TraceEventTest.php`

**Interfaces:**
- Produces:
  - `enum TraceKind: string` with cases `ModelCall = 'model_call'`, `ReplyGuard = 'reply_guard'`, `PolicyVerdict = 'policy_verdict'`, `QuoteBefore = 'quote_before'` and `QuoteAfter = 'quote_after'`, and `public function metaKeys(): list<string>`;
  - `class TraceEvent extends Entity` with public properties `string $id`, `?string $decisionId`, `?string $quoteId`, `?string $customerId`, `string $kind`, `?int $position`, `?\DateTimeImmutable $occurredAt`, `?array $meta` and `?array $content`;
  - DAL repository service `merchant_quote_agent_trace.repository`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Audit/TraceKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use PHPUnit\Framework\TestCase;

final class TraceKindTest extends TestCase
{
    public function testEveryKindDeclaresAUniqueNonEmptyMetaAllowlist(): void
    {
        foreach (TraceKind::cases() as $kind) {
            $keys = $kind->metaKeys();

            self::assertNotSame([], $keys, $kind->value . ' declares no meta keys.');
            self::assertSame(array_values(array_unique($keys)), $keys, $kind->value . ' declares a key twice.');
            self::assertNotContains(
                'truncated',
                $keys,
                'truncated is added by TraceDraft when a cut happens; declaring it would make it always present.',
            );
        }
    }

    public function testTheModelCallAllowlistIsTheSpecsList(): void
    {
        self::assertSame(
            [
                'purpose',
                'requestedModel',
                'servedModel',
                'host',
                'status',
                'httpStatus',
                'latencyMs',
                'promptTokens',
                'completionTokens',
                'cachedTokens',
                'reasoningTokens',
                'finishReason',
                'retries',
                'errorClass',
            ],
            TraceKind::ModelCall->metaKeys(),
        );
    }
}
```

`tests/Unit/Audit/CreateQuoteAgentTraceMigrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Migration\Migration1789800000CreateQuoteAgentTrace;
use PHPUnit\Framework\TestCase;

final class CreateQuoteAgentTraceMigrationTest extends TestCase
{
    public function testTheTimestampIsExact(): void
    {
        self::assertSame(1789800000, (new Migration1789800000CreateQuoteAgentTrace())->getCreationTimestamp());
    }
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/phpunit --filter 'TraceKindTest|CreateQuoteAgentTraceMigrationTest|UninstallDropsEveryTableTest'`
Expected: the first two FAIL (classes not found). `UninstallDropsEveryTableTest` still passes, because no migration creates the table yet.

- [ ] **Step 3: Write `TraceKind`**

`src/Audit/TraceKind.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * What one row of `merchant_quote_agent_trace` records. A closed list: the
 * export and the eraser both reason per kind, and an open string would let a
 * new event reach a merchant's file without anyone having decided what it is.
 *
 * `metaKeys()` is the allowlist for `meta`, the half of an event that leaves
 * in every export. TraceDraft keeps exactly these keys and drops anything
 * else, so a new field has to be declared here before it can leave -- the
 * same rule AnonymizedDecision applies to decision columns, enforced at write
 * time instead of at export time. Everything that can carry the buyer's
 * words, a model's words or account data goes into `content` instead.
 *
 * See docs/superpowers/specs/2026-09-23-run-trace-capture-design.md §2.
 */
enum TraceKind: string
{
    case ModelCall = 'model_call';
    case ReplyGuard = 'reply_guard';
    case PolicyVerdict = 'policy_verdict';
    case QuoteBefore = 'quote_before';
    case QuoteAfter = 'quote_after';

    /** @return list<string> */
    public function metaKeys(): array
    {
        return match ($this) {
            self::ModelCall => [
                'purpose',
                'requestedModel',
                'servedModel',
                'host',
                'status',
                'httpStatus',
                'latencyMs',
                'promptTokens',
                'completionTokens',
                'cachedTokens',
                'reasoningTokens',
                'finishReason',
                'retries',
                'errorClass',
            ],
            self::ReplyGuard => ['accepted'],
            self::PolicyVerdict => [
                'overall',
                'priceKind',
                'escalationReason',
                'requestedDiscountPercent',
                'discountPercent',
                'perLineAsks',
                'validityDays',
                'counteredRequestPercent',
                'escalationReasons',
            ],
            self::QuoteBefore, self::QuoteAfter => ['lineCount'],
        };
    }
}
```

- [ ] **Step 4: Write the entity**

`src/Audit/TraceEvent.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Protection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One thing that happened during a run: a model call, a policy verdict, a
 * rejected reply, a quote snapshot. The decision record says what a pass
 * concluded; this says how it got there, in enough detail to replay it.
 *
 * A sibling table rather than a JSON column on the decision row: the
 * dashboard list loads up to 500 decision rows with every column, and a
 * pass's prompts are 100-150 KB. No association is declared, like every
 * other entity in this plugin -- `decisionId` is a plain id, read with a
 * filter.
 *
 * `meta` versus `content` is the privacy boundary. `meta` holds only the keys
 * TraceKind::metaKeys() declares and always leaves in an export; `content`
 * holds anything that can carry the buyer's words, a model's words or account
 * data, leaves only with free text, and is what DecisionEraser clears.
 *
 * Write-protected to system scope and without `maxLength:` for the reasons
 * QuoteDecisionRecord's docblock gives; the migration holds the widths.
 */
#[Entity('merchant_quote_agent_trace')]
class TraceEvent extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $id = '';

    /** The pass this event belongs to; null outside a pass (PR 2 and 3). */
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $decisionId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $quoteId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $customerId = null;

    /** A TraceKind value. */
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $kind = '';

    /** Order within the pass, from 0. */
    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $position = null;

    /** When it happened. Pass events are buffered, so this is not `createdAt`. */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $occurredAt = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $meta = null;

    /** @var array<array-key, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $content = null;
}
```

- [ ] **Step 5: Write the migration**

`src/Migration/Migration1789800000CreateQuoteAgentTrace.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The run trace (docs/superpowers/specs/2026-09-23-run-trace-capture-design.md).
 * Hand-written like every table here, and in step with Audit\TraceEvent.
 *
 * No foreign key to the decision table: a trace row outlives a deleted
 * decision (the export skips it), and events outside a pass have no decision
 * at all. Indexed on what the export and the eraser filter by. `occurred_at`
 * is indexed now, for PR 2's event lines, so that PR does not need a second
 * migration.
 */
class Migration1789800000CreateQuoteAgentTrace extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789800000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_trace` (
                    `id`           BINARY(16)  NOT NULL,
                    `decision_id`  BINARY(16)  NULL,
                    `quote_id`     BINARY(16)  NULL,
                    `customer_id`  BINARY(16)  NULL,
                    `kind`         VARCHAR(32) NOT NULL,
                    `position`     INT(11)     NULL,
                    `occurred_at`  DATETIME(3) NOT NULL,
                    `meta`         JSON        NULL,
                    `content`      JSON        NULL,
                    `created_at`   DATETIME(3) NOT NULL,
                    `updated_at`   DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.mqat.decision_id` (`decision_id`),
                    KEY `idx.mqat.quote_id` (`quote_id`),
                    KEY `idx.mqat.customer_id` (`customer_id`),
                    KEY `idx.mqat.occurred_at` (`occurred_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is additive and the merchant's data.
    }
}
```

- [ ] **Step 6: Run the unit tests, and see the uninstall guard fail**

Run: `vendor/bin/phpunit --filter 'TraceKindTest|CreateQuoteAgentTraceMigrationTest|UninstallDropsEveryTableTest'`
Expected: TraceKindTest and the migration test PASS. `UninstallDropsEveryTableTest::testEveryTableAMigrationCreatesIsAlsoDropped` FAILS and names `merchant_quote_agent_trace`.

- [ ] **Step 7: Drop the table on uninstall, register the entity, grant the viewer read**

In `src/MerchantQuoteAgentPlugin.php`, add `'merchant_quote_agent_trace',` to the table list in `dropPluginTables()`, directly above `'merchant_quote_agent_decision',`.

In `src/Resources/config/services.php`, directly under `$services->set(QuoteDecisionRecord::class);` (line 372), add the following, with a `use MerchantQuoteAgentPlugin\Audit\TraceEvent;` import:

```php
    // The run trace (2026-09-23 spec). Registered unconditionally for the same
    // reason as QuoteDecisionRecord: a pass writes it whether or not
    // SwagCommercial is installed.
    $services->set(TraceEvent::class);
```

In `acl/index.ts`, add `'merchant_quote_agent_trace:read',` directly under `'merchant_quote_agent_decision:read',` in the viewer's `privileges`. Add this line to the comment block above that list:

```ts
            // `merchant_quote_agent_trace:read` because a decision's trace is
            // part of the decision record: the export reads it under the
            // viewer's own context.
```

- [ ] **Step 8: Write the integration test**

`tests/Integration/TraceEventTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The migration and the entity agree. CI never executes migration SQL, so
 * this is the first place the CREATE TABLE meets a real MySQL 8.
 */
final class TraceEventTest extends IntegrationTestCase
{
    public function testTheTableExistsAndTheEntityRoundTripsItsJson(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_trace.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = Uuid::randomHex();
        $repository->create([[
            'id' => $id,
            'decisionId' => Uuid::randomHex(),
            'quoteId' => Uuid::randomHex(),
            'customerId' => Uuid::randomHex(),
            'kind' => 'model_call',
            'position' => 3,
            'occurredAt' => new \DateTimeImmutable('2031-05-05 12:00:00.123'),
            'meta' => ['purpose' => 'extract', 'retries' => [['httpStatus' => 503, 'transportError' => false]]],
            'content' => ['request' => ['messages' => [['role' => 'user', 'content' => 'Grüße, 5% bitte']]]],
        ]], Context::createDefaultContext());

        $written = $repository->search(new Criteria([$id]), Context::createDefaultContext())->first();

        self::assertNotNull($written, 'The trace row was not written.');
        self::assertSame('model_call', $written->kind);
        self::assertSame(3, $written->position);
        self::assertSame('extract', $written->meta['purpose'] ?? null);
        self::assertSame(
            'Grüße, 5% bitte',
            $written->content['request']['messages'][0]['content'] ?? null,
            'Multibyte text must survive the JSON column unchanged.',
        );
        self::assertSame('123', $written->occurredAt?->format('v'), 'occurred_at must keep milliseconds.');
    }
}
```

- [ ] **Step 9: Run everything for this task**

Run: `vendor/bin/phpunit --filter 'TraceKindTest|CreateQuoteAgentTraceMigrationTest|UninstallDropsEveryTableTest'`
Expected: PASS.

Run: `composer run test:integration -- --filter TraceEventTest`
Expected: PASS. If the shop has not run the new migration yet, the script's sync step runs it. If the table is still missing, run `bin/console database:migrate --all MerchantQuoteAgentPlugin` on the shop. Never run `plugin:update`: it can corrupt the shop's `vendor/`.

Run: `composer run format && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add src/Audit/TraceKind.php src/Audit/TraceEvent.php src/Migration/Migration1789800000CreateQuoteAgentTrace.php \
  src/MerchantQuoteAgentPlugin.php src/Resources/config/services.php \
  src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts \
  tests/Unit/Audit/TraceKindTest.php tests/Unit/Audit/CreateQuoteAgentTraceMigrationTest.php tests/Integration/TraceEventTest.php
git commit -m "feat(audit): add the run trace table

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Buffer events on the draft and write them with the decision

**Files:**
- Create: `src/Audit/TracePayload.php`, `src/Audit/TraceDraft.php`
- Modify:
  - `src/Audit/DecisionDraft.php`
  - `src/Audit/DecisionRecorder.php` (add `trace()` and `decisionId()`)
  - `src/Audit/DecisionRecordWriter.php`
  - `src/Resources/config/services.php` (the writer's args, line 401)
  - `src/Negotiation/NegotiationPipeline.php` (`record()`, around line 123)
- Test:
  - `tests/Unit/Audit/TracePayloadTest.php`
  - `tests/Unit/Audit/TraceDraftTest.php`
  - `tests/Unit/Audit/DecisionRecordWriterTest.php`
  - `tests/Unit/Audit/DecisionRecorderTest.php`
  - `tests/Unit/Audit/DraftMirrorsEntityTest.php`
  - `tests/Integration/DecisionRecordTest.php` (line 140 constructor only)

**Interfaces:**
- Consumes: `TraceKind` (Task 1).
- Produces:
  - `TracePayload::MAX_STRING_BYTES = 65536`;
  - `TracePayload::of(array|object $value): array`, which never throws;
  - `TracePayload::capped(array $value): array{0: array, 1: list<string>}`;
  - `final readonly class TraceDraft` with public `kind`, `position`, `occurredAt`, `meta` and `content`, `static appendTo(DecisionDraft $draft, TraceKind $kind, array $meta, ?array $content): void` and `payload(DecisionDraft $draft): array`;
  - `DecisionDraft::$id` (a hex UUID generated in the constructor) and `DecisionDraft::$trace` (`list<TraceDraft>`);
  - `DecisionRecorder::trace(TraceKind $kind, array $meta, ?array $content = null): void`, a no-op without an open draft;
  - `DecisionRecorder::decisionId(): ?string`;
  - `DecisionRecordWriter::__construct(EntityRepository $records, EntityRepository $traces, LoggerInterface $logger)`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Audit/TracePayloadTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TracePayload;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use PHPUnit\Framework\TestCase;

final class TracePayloadTest extends TestCase
{
    public function testAnObjectTreeBecomesPlainArraysWithEnumsAsTheirValues(): void
    {
        $value = new class {
            public Band $band = Band::Grant;

            public array $lines = [['qty' => 2]];
        };

        self::assertSame(['band' => 'grant', 'lines' => [['qty' => 2]]], TracePayload::of($value));
    }

    public function testInvalidUtf8IsSubstitutedRatherThanThrown(): void
    {
        // A provider error body is raw bytes. Recording it must never throw
        // into the pass, and the DAL's own JSON encode would.
        $out = TracePayload::of(['body' => "ok \xB1\x31 end"]);

        self::assertIsString($out['body']);
        self::assertStringContainsString("\u{FFFD}", $out['body']);
    }

    public function testAStringOverTheCapIsCutAtACharacterBoundaryAndItsPathReported(): void
    {
        $long = str_repeat('ä', TracePayload::MAX_STRING_BYTES); // 2 bytes each, so 2x the cap
        [$capped, $cut] = TracePayload::capped(['request' => ['messages' => [['content' => $long]]], 'short' => 'x']);

        $kept = $capped['request']['messages'][0]['content'];
        self::assertLessThanOrEqual(TracePayload::MAX_STRING_BYTES, \strlen($kept));
        self::assertTrue(mb_check_encoding($kept, 'UTF-8'), 'The cut split a multibyte character.');
        self::assertSame('x', $capped['short']);
        self::assertSame(['request.messages.0.content'], $cut);
    }

    public function testNothingUnderTheCapIsTouched(): void
    {
        $value = ['a' => 'short', 'b' => [1, 2.5, true, null]];

        self::assertSame([$value, []], TracePayload::capped($value));
    }
}
```

`tests/Unit/Audit/TraceDraftTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\TracePayload;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

final class TraceDraftTest extends TestCase
{
    public function testEventsAreNumberedInTheOrderTheyArrive(): void
    {
        $draft = new DecisionDraft();

        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 1], null);
        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false], null);

        self::assertSame([0, 1], array_map(static fn(TraceDraft $t): int => $t->position, $draft->trace));
    }

    public function testMetaIsAnAllowlistUndeclaredKeysAreDroppedAndMissingOnesAreNull(): void
    {
        // meta leaves in every export, free text or not. A key nobody
        // declared in TraceKind::metaKeys() must not be able to reach it.
        $draft = new DecisionDraft();

        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false, 'buyerSaid' => 'secret'], null);
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, [], null);

        self::assertSame(['accepted' => false], $draft->trace[0]->meta);
        self::assertSame(['lineCount' => null], $draft->trace[1]->meta);
    }

    public function testACutInContentIsReportedInMeta(): void
    {
        $draft = new DecisionDraft();

        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false], [
            'reworded' => str_repeat('x', TracePayload::MAX_STRING_BYTES + 1),
        ]);

        self::assertSame(['accepted' => false, 'truncated' => ['reworded']], $draft->trace[0]->meta);
        self::assertSame(TracePayload::MAX_STRING_BYTES, \strlen($draft->trace[0]->content['reworded'] ?? ''));
    }

    public function testThePayloadCarriesTheDecisionsIdsAndAFreshId(): void
    {
        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->customerId = Uuid::randomHex();
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 2], ['identity' => []]);

        $payload = $draft->trace[0]->payload($draft);

        self::assertTrue(Uuid::isValid($payload['id']));
        self::assertNotSame($draft->id, $payload['id']);
        self::assertSame($draft->id, $payload['decisionId']);
        self::assertSame($draft->quoteId, $payload['quoteId']);
        self::assertSame($draft->customerId, $payload['customerId']);
        self::assertSame('quote_before', $payload['kind']);
        self::assertSame(0, $payload['position']);
        self::assertInstanceOf(\DateTimeImmutable::class, $payload['occurredAt']);
        self::assertSame(['lineCount' => 2], $payload['meta']);
        self::assertSame(['identity' => []], $payload['content']);
    }
}
```

Append to `tests/Unit/Audit/DecisionRecordWriterTest.php`:
- update the two existing `new DecisionRecordWriter($repository)` calls to `new DecisionRecordWriter($repository, $this->createMock(EntityRepository::class), new NullLogger())`;
- in `testTheDraftBecomesOneCreatePayloadWithAGeneratedId`, add `self::assertSame($draft->id, $captured[0]['id'], 'The writer must use the id begin() generated, so trace rows and log lines can point at it.');`;
- add `use Psr\Log\NullLogger;`, `use MerchantQuoteAgentPlugin\Audit\TraceDraft;`, `use MerchantQuoteAgentPlugin\Audit\TraceKind;` and `use Psr\Log\AbstractLogger;`.

Then add these tests:

```php
    public function testTheTraceIsWrittenAfterTheDecisionUnderTheDecisionsId(): void
    {
        $order = [];
        $event = $this->createStub(EntityWrittenContainerEvent::class);
        $records = $this->createMock(EntityRepository::class);
        $records->method('create')->willReturnCallback(static function () use (&$order, $event) {
            $order[] = 'decision';

            return $event;
        });
        $traces = $this->createMock(EntityRepository::class);
        $captured = [];
        $traces
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(static function (array $payload) use (&$order, &$captured, $event) {
                $order[] = 'trace';
                $captured = $payload;

                return $event;
            });

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 1], null);
        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false], null);

        (new DecisionRecordWriter($records, $traces, new NullLogger()))->write($draft);

        self::assertSame(['decision', 'trace'], $order);
        self::assertCount(2, $captured);
        self::assertSame($draft->id, $captured[0]['decisionId']);
        self::assertSame('reply_guard', $captured[1]['kind']);
    }

    public function testAPassWithNoTraceMakesNoTraceWrite(): void
    {
        $records = $this->createMock(EntityRepository::class);
        $records->method('create')->willReturn($this->createStub(EntityWrittenContainerEvent::class));
        $traces = $this->createMock(EntityRepository::class);
        $traces->expects(self::never())->method('create');

        (new DecisionRecordWriter($records, $traces, new NullLogger()))->write(new DecisionDraft());
    }

    public function testAFailedTraceWriteIsLoggedAndTheDecisionStands(): void
    {
        $records = $this->createMock(EntityRepository::class);
        $records->expects(self::once())->method('create')->willReturn(
            $this->createStub(EntityWrittenContainerEvent::class),
        );
        $traces = $this->createMock(EntityRepository::class);
        $traces->method('create')->willThrowException(new \RuntimeException('JSON column rejected'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message];
            }
        };

        $draft = new DecisionDraft();
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 1], null);

        (new DecisionRecordWriter($records, $traces, $logger))->write($draft);

        self::assertSame('error', $logger->lines[0][0] ?? null, 'A lost trace must be loud.');
    }
```

`tests/Unit/Audit/DraftMirrorsEntityTest.php` changes:
- remove the `ID_IS_WRITER_GENERATED` constant and its entry in the second test's `$excluded`. `id` is now a draft property, so both directions match;
- change `DRAFT_ONLY_WORKING_FIELDS` to `['startedAt', 'trace']` and its comment to: `/** The draft's own stopwatch and its buffered trace events; DecisionRecordWriter excludes both, neither is a column. */`.

Add to `tests/Unit/Audit/DecisionRecorderTest.php` (add the imports `MerchantQuoteAgentPlugin\Audit\TraceKind` and `Shopware\Core\Framework\Uuid\Uuid`):

```php
    public function testATraceEventIsBufferedOnTheOpenPass(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->trace(TraceKind::ReplyGuard, ['accepted' => false], ['reason' => 'it is empty']);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $kinds = array_map(static fn($t): string => $t->kind->value, $writer->drafts[0]->trace);
        self::assertContains('reply_guard', $kinds);
    }

    public function testATraceWithoutAnOpenPassIsDropped(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->trace(TraceKind::ReplyGuard, ['accepted' => false]);

        self::assertNull($recorder->decisionId());
        self::assertSame([], $writer->drafts);
    }

    public function testTheDecisionIdIsKnownFromBeginAndIsTheWrittenRowsId(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $id = $recorder->decisionId();
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame($id, $writer->drafts[0]->id);
        self::assertNull($recorder->decisionId(), 'finish() closes the pass; no id may outlive it.');
    }
```

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/phpunit --filter 'TracePayloadTest|TraceDraftTest|DecisionRecordWriterTest|DecisionRecorderTest|DraftMirrorsEntityTest'`
Expected: FAIL (the classes, properties and methods do not exist yet).

- [ ] **Step 3: Write `TracePayload`**

`src/Audit/TracePayload.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Turns whatever a recording site hands over into what a JSON column can hold,
 * without ever throwing.
 *
 * Not InterpretationPayload: that one throws on purpose, because a decision
 * column that silently lost its value would be worse than a failed write. A
 * trace is the opposite trade: it is recorded from inside a running pass,
 * from values nobody validated (a provider's raw error body, a quote's custom
 * fields), and a trace that is partly substituted beats a pass that fails.
 *
 * The cap is a trust-boundary control, not tidiness (spec §1): the buyer's
 * comment reaches the prompt verbatim and has no length limit, and PR 3 puts
 * request bodies from any storefront caller in here.
 */
final class TracePayload
{
    public const MAX_STRING_BYTES = 65536;

    private function __construct() {}

    /** @return array<array-key, mixed> */
    public static function of(array|object $value): array
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $decoded = \is_string($encoded) ? json_decode($encoded, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array{0: array<array-key, mixed>, 1: list<string>} the capped value, and the dotted path of every cut string
     */
    public static function capped(array $value, string $path = ''): array
    {
        $cut = [];

        foreach ($value as $key => $leaf) {
            $at = $path === '' ? (string) $key : $path . '.' . $key;

            if (\is_array($leaf)) {
                [$value[$key], $inner] = self::capped($leaf, $at);
                $cut = [...$cut, ...$inner];
            } elseif (\is_string($leaf) && \strlen($leaf) > self::MAX_STRING_BYTES) {
                $value[$key] = mb_strcut($leaf, 0, self::MAX_STRING_BYTES, 'UTF-8');
                $cut[] = $at;
            }
        }

        return [$value, $cut];
    }
}
```

- [ ] **Step 4: Write `TraceDraft`**

`src/Audit/TraceDraft.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * One event of the pass under way. Buffered on the DecisionDraft and written
 * by DecisionRecordWriter after the decision row, so a pass's events share
 * its lifecycle: a pass that dies halfway still leaves what it had recorded,
 * and nothing is written for a pass that never opened.
 *
 * `appendTo()` is where TraceKind::metaKeys() is enforced: every declared key
 * is present (null when the site had no value), nothing undeclared survives.
 */
final readonly class TraceDraft
{
    /**
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public function __construct(
        public TraceKind $kind,
        public int $position,
        public \DateTimeImmutable $occurredAt,
        public array $meta,
        public ?array $content,
    ) {}

    /**
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public static function appendTo(DecisionDraft $draft, TraceKind $kind, array $meta, ?array $content): void
    {
        [$content, $cut] = $content === null ? [null, []] : TracePayload::capped(TracePayload::of($content));
        $declared = array_fill_keys($kind->metaKeys(), null);
        $meta = [...$declared, ...array_intersect_key($meta, $declared)];

        if ($cut !== []) {
            $meta['truncated'] = $cut;
        }

        $draft->trace[] = new self($kind, \count($draft->trace), new \DateTimeImmutable(), $meta, $content);
    }

    /** @return array<string, mixed> one create payload for `merchant_quote_agent_trace` */
    public function payload(DecisionDraft $draft): array
    {
        return [
            'id' => Uuid::randomHex(),
            'decisionId' => $draft->id,
            'quoteId' => $draft->quoteId === '' ? null : $draft->quoteId,
            'customerId' => $draft->customerId,
            'kind' => $this->kind->value,
            'position' => $this->position,
            'occurredAt' => $this->occurredAt,
            'meta' => $this->meta,
            'content' => $this->content,
        ];
    }
}
```

- [ ] **Step 5: Give the draft its id and its buffer**

In `src/Audit/DecisionDraft.php`:
- add `use Shopware\Core\Framework\Uuid\Uuid;`;
- replace the docblock sentence "minus `id` (the writer generates it)" with "plus `id`, generated here so that a pass's trace events and its log line can name the row before it exists".

Then add these as the first property and the constructor:

```php
    /** Generated at construction, not at write time: see the class docblock. */
    public string $id;
```

```php
    public function __construct()
    {
        $this->id = Uuid::randomHex();
    }
```

Add this directly above `public float $startedAt = 0.0;`:

```php
    /**
     * The pass's trace events, in order. Not a column: DecisionRecordWriter
     * writes them to `merchant_quote_agent_trace`.
     *
     * @var list<TraceDraft>
     */
    public array $trace = [];
```

- [ ] **Step 6: Add `trace()` and `decisionId()` to the recorder**

In `src/Audit/DecisionRecorder.php`, add after `recordHistoryRound()`:

```php
    /**
     * One event for the open pass's trace (see TraceKind). For the
     * collaborators that are not the recorder's to map -- ModelPlatform and
     * ReplyComposer build their own event. Dropped when no pass is open, like
     * every other record* call.
     *
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public function trace(TraceKind $kind, array $meta, ?array $content = null): void
    {
        if ($this->draft === null) {
            return;
        }

        TraceDraft::appendTo($this->draft, $kind, $meta, $content);
    }

    /** The id the open pass's row will have, or null when no pass is open. */
    public function decisionId(): ?string
    {
        return $this->draft?->id;
    }
```

- [ ] **Step 7: Write the trace in the writer**

Replace the body of `src/Audit/DecisionRecordWriter.php` from the constructor down with the code below, and add the imports `Psr\Log\LoggerInterface` and `MerchantQuoteAgentPlugin\Audit\TraceDraft` (same namespace, so no import is needed for the latter). Remove the `Uuid` import.

```php
    private const NOT_A_COLUMN = ['startedAt', 'trace'];

    public function __construct(
        private EntityRepository $records,
        private EntityRepository $traces,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($draft);

        foreach (self::NOT_A_COLUMN as $field) {
            unset($payload[$field]);
        }

        $context = Context::createDefaultContext();
        $this->records->create([$payload], $context);

        if ($draft->trace === []) {
            return;
        }

        // After the decision row, and never able to take it back: a trace
        // that could not be written is a loss of detail, a decision that
        // could not be written is a loss of the record. Logged at error
        // because a missing trace is otherwise invisible until someone
        // exports that day and wonders why the prompts are gone.
        try {
            $this->traces->create(
                array_map(static fn(TraceDraft $event): array => $event->payload($draft), $draft->trace),
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('The pass trace could not be recorded; the decision record stands.', [
                'decisionId' => $draft->id,
                'quoteId' => $draft->quoteId,
                'exception' => $e,
            ]);
        }
    }
```

Update the class docblock: replace "`startedAt` is the draft's own stopwatch, not a column: it is excluded explicitly." with "`startedAt` (the draft's stopwatch) and `trace` (its buffered events, written to their own table below) are not columns, so both are excluded explicitly."

In `src/Resources/config/services.php` line 401, change the writer's args to:

```php
    $services->set(DecisionRecordWriter::class)->args([
        service('merchant_quote_agent_decision.repository'),
        service('merchant_quote_agent_trace.repository'),
        service('logger'),
    ]);
```

In `tests/Integration/DecisionRecordTest.php` line 140, change the constructor call to:

```php
        $writer = new DecisionRecordWriter(
            $repository,
            static::getContainer()->get('merchant_quote_agent_trace.repository'),
            new \Psr\Log\NullLogger(),
        );
```

- [ ] **Step 8: Put the decision id in the per-pass log line**

In `src/Negotiation/NegotiationPipeline.php` `record()`, inside the outer `try`, first line (before the inner `try` that calls `finish()`):

```php
            // Read before finish(): finish() closes the pass and the id with it.
            $decisionId = $this->recorder->decisionId();
```

Then add `'decisionId' => $decisionId,` as the first key of the `'A quote negotiation pass finished.'` context array.

- [ ] **Step 9: Run the tests**

Run: `vendor/bin/phpunit --filter 'TracePayloadTest|TraceDraftTest|DecisionRecordWriterTest|DecisionRecorderTest|DraftMirrorsEntityTest|RecordedPassTest|NegotiationPipeline'`
Expected: PASS.

Run: `vendor/bin/phpunit`
Expected: the whole unit suite PASSES. If a test built `DecisionDraft` and asserted on a `null`/empty `id`, it now sees a UUID; update the assertion to `Uuid::isValid()`.

Run: `composer run format && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add src/Audit src/Negotiation/NegotiationPipeline.php src/Resources/config/services.php tests/Unit/Audit tests/Integration/DecisionRecordTest.php
git commit -m "feat(audit): buffer trace events on the pass and write them with its record

The decision id is generated when the pass opens, so every event and the
per-pass log line can name the row they belong to.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The quote before and after, and the policy verdict

**Files:**
- Create: `src/Audit/VerdictTrace.php`
- Modify: `src/Audit/DecisionRecorder.php` (`draftFor()`, `recordDecision()`, `recordApplied()`)
- Test: `tests/Unit/Audit/VerdictTraceTest.php`, `tests/Unit/Audit/DecisionRecorderTest.php`

**Interfaces:**
- Consumes: `TraceDraft::appendTo`, `TracePayload::of` and `TraceKind` (Tasks 1–2).
- Produces:
  - `VerdictTrace::of(NegotiationDecision $decision): array{0: array<string, mixed>, 1: array<array-key, mixed>}`;
  - events `quote_before` (always position 0, including on `recordRefusal()` rows), `policy_verdict` and `quote_after`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Audit/VerdictTraceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\VerdictTrace;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use PHPUnit\Framework\TestCase;

final class VerdictTraceTest extends TestCase
{
    public function testAGrantCarriesItsFiguresInMetaAndTheWholeDecisionInContent(): void
    {
        $decision = new NegotiationDecision(
            Band::Grant,
            QuoteDecision::autoReply(new QuoteAutoReplyDetails(5.0, false, [], 14, 7.5)),
        );

        [$meta, $content] = VerdictTrace::of($decision);

        self::assertSame(TraceKind::PolicyVerdict->metaKeys(), array_keys($meta));
        self::assertSame('grant', $meta['overall']);
        self::assertSame(5.0, $meta['discountPercent']);
        self::assertSame(14, $meta['validityDays']);
        self::assertSame(7.5, $meta['counteredRequestPercent']);
        self::assertNull($meta['escalationReason']);
        self::assertSame('grant', $content['overall']);
    }

    public function testTheModelsHumanReviewSentencesStayInContent(): void
    {
        // QuoteEscalationDetails::$humanReviewRequests are sentences the
        // extract model wrote out of the buyer's message: free text, so never
        // meta, which leaves in every export.
        $decision = new NegotiationDecision(
            Band::Escalate,
            QuoteDecision::escalate(new QuoteEscalationDetails(
                QuoteEscalationReason::NeedsHumanReview,
                30.0,
                ['Anna wants to talk to a person.'],
            )),
            ['price: needs_human_review'],
        );

        [$meta, $content] = VerdictTrace::of($decision);

        self::assertSame(QuoteEscalationReason::NeedsHumanReview->value, $meta['escalationReason']);
        self::assertSame(30.0, $meta['requestedDiscountPercent']);
        self::assertSame(['price: needs_human_review'], $meta['escalationReasons']);
        self::assertStringNotContainsString('Anna', json_encode($meta, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Anna', json_encode($content, JSON_THROW_ON_ERROR));
    }
}
```

Add to `tests/Unit/Audit/DecisionRecorderTest.php` (import `MerchantQuoteAgentPlugin\Audit\TraceDraft`):

```php
    public function testAPassOpensWithTheQuoteAsItWas(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $snapshot = NegotiationFixture::snapshot();

        $recorder->begin($snapshot, self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::NothingToDo));

        $first = $writer->drafts[0]->trace[0];
        self::assertSame('quote_before', $first->kind->value);
        self::assertSame(0, $first->position);
        self::assertSame(['lineCount' => \count($snapshot->content->lines)], $first->meta);
        self::assertSame($snapshot->identity->quoteId, $first->content['identity']['quoteId'] ?? null);
    }

    public function testARefusalRowCarriesTheQuoteItRefusedToo(): void
    {
        $writer = new FakeDecisionWriter();

        (new DecisionRecorder($writer))->recordRefusal(
            NegotiationFixture::snapshot(),
            self::context(),
            QuoteEscalationReason::NotConfigured,
            ['no model'],
        );

        self::assertSame(['quote_before'], array_map(
            static fn(TraceDraft $t): string => $t->kind->value,
            $writer->drafts[0]->trace,
        ));
    }

    public function testTheVerdictAndTheAppliedQuoteAreTraced(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $after = NegotiationFixture::snapshot(totalNet: 950.0);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordDecision(
            new NegotiationDecision(Band::Grant, QuoteDecision::autoReply(new QuoteAutoReplyDetails(5.0, false, [], 14))),
            10.0,
        );
        $recorder->recordApplied(new AppliedOffer(true, [], $after, 1000.0), ['updateLineItems']);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $kinds = array_map(static fn(TraceDraft $t): string => $t->kind->value, $writer->drafts[0]->trace);
        self::assertSame(['quote_before', 'policy_verdict', 'quote_after'], $kinds);
        self::assertSame(950.0, $writer->drafts[0]->trace[2]->content['totals']['totalNet'] ?? null);
    }
```

`QuoteEscalationReason::NotConfigured`, `::NeedsHumanReview`, `Band::Grant` and `AppliedOffer(bool $verified, array $violations, QuoteSnapshot $after, float $beforeNet)` were checked against `main` at 8d395f1.

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/phpunit --filter 'VerdictTraceTest|DecisionRecorderTest'`
Expected: FAIL (`VerdictTrace` is missing, and no `quote_before`/`policy_verdict`/`quote_after` events are recorded).

- [ ] **Step 3: Write `VerdictTrace`**

`src/Audit/VerdictTrace.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;

/**
 * The policy's verdict as a trace event. `recordDecision()` has always kept
 * only the band; this keeps what the band was made of.
 *
 * Flattened into meta, figure by figure, rather than meta being the encoded
 * decision: `QuoteEscalationDetails::$humanReviewRequests` are sentences the
 * extract model wrote, and `lineUnitPricesNet` carries line-item ids, so the
 * whole tree is content and only the figures and enums are meta.
 * `escalationReasons` qualifies because PriceEscalationReasons builds each one
 * from an enum value.
 */
final class VerdictTrace
{
    private function __construct() {}

    /** @return array{0: array<string, mixed>, 1: array<array-key, mixed>} */
    public static function of(NegotiationDecision $decision): array
    {
        $price = $decision->price;

        return [
            [
                'overall' => $decision->overall->value,
                'priceKind' => $price->kind->value,
                'escalationReason' => $price->escalation?->reason->value,
                'requestedDiscountPercent' => $price->escalation?->requestedDiscountPercent,
                'discountPercent' => $price->autoReply?->discountPercent,
                'perLineAsks' => $price->autoReply?->perLineAsks,
                'validityDays' => $price->autoReply?->validityDays,
                'counteredRequestPercent' => $price->autoReply?->counteredRequestPercent,
                'escalationReasons' => $decision->escalationReasons,
            ],
            TracePayload::of($decision),
        ];
    }
}
```

- [ ] **Step 4: Record the three events**

In `src/Audit/DecisionRecorder.php`:

In `draftFor()`, directly before `return $draft;`:

```php
        // Position 0 of every row, refusals included: what the quote looked
        // like when the agent picked it up is the one thing every later event
        // is read against. Content, all of it -- product labels and the
        // quote's own identity are in there.
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, [
            'lineCount' => \count($snapshot->content->lines),
        ], TracePayload::of($snapshot));
```

In `recordDecision()`, after the four existing assignments:

```php
        [$meta, $content] = VerdictTrace::of($decision);
        TraceDraft::appendTo($this->draft, TraceKind::PolicyVerdict, $meta, $content);
```

In `recordApplied()`, after the `discountPercentGranted` assignment:

```php
        TraceDraft::appendTo($this->draft, TraceKind::QuoteAfter, [
            'lineCount' => \count($applied->after->content->lines),
        ], TracePayload::of($applied->after));
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter 'VerdictTraceTest|DecisionRecorderTest|RecordedPassTest|RejectedProposalRecordTest'`
Expected: PASS. If an existing test asserted `trace === []` or an exact trace count, update it to the new events.

Run: `composer run format && composer run lint && composer run typecheck`
Expected: clean. If Mago's class-level cyclomatic count for `DecisionRecorder` rises, the existing `@mago-expect lint:cyclomatic-complexity` already covers it; do not add a second expectation.

- [ ] **Step 6: Commit**

```bash
git add src/Audit/VerdictTrace.php src/Audit/DecisionRecorder.php tests/Unit/Audit/VerdictTraceTest.php tests/Unit/Audit/DecisionRecorderTest.php
git commit -m "feat(audit): trace the quote before and after a pass, and the full policy verdict

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Trace every model call

**Files:**
- Create: `src/Negotiation/ModelCallPurpose.php`, `src/Negotiation/ModelCallTrace.php`
- Modify: `src/Negotiation/ModelPlatform.php`, `src/Negotiation/ModelRetryStrategy.php`, `src/Negotiation/Response/ChatEnvelope.php`
- Test: `tests/Unit/Negotiation/ModelPlatformTraceTest.php`

**Interfaces:**
- Consumes:
  - `DecisionRecorder::trace()` and `TraceKind::ModelCall` (Tasks 1–2);
  - the existing test doubles `ScriptedClient::spy/responding(…, ?DecisionRecorder $recorder)`, `NegotiationFixture::modelAccess()`, `NegotiationFixture::modelReply()` and `FakeDecisionWriter`.
- Produces:
  - `enum ModelCallPurpose: string` with `Extract`, `Negotiate`, `Reply`, `Other` and `static answering(string $type): self`;
  - `final readonly class ModelCallTrace` with `__construct(ModelCallPurpose, ModelAccess, array $request, int $latencyMs, array $retries)`, `answered(int $httpStatus, array $decoded, ?ModelUnavailable $unusable): array{0: array, 1: array}`, `failed(ModelUnavailable $error): array{0: array, 1: array}` and `static host(string $baseUrl): string`;
  - `ModelRetryStrategy::reset(): void` and `ModelRetryStrategy::attempts(): list<array{httpStatus: int, transportError: bool}>`;
  - `ChatEnvelope::usage(array $decoded, string ...$path): ?int`, `ChatEnvelope::finishReason(array $decoded): ?string` and `ChatEnvelope::servedModel(array $decoded): ?string`.
- The public signatures of `ModelPlatform::text()` and `::object()` are unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Negotiation/ModelPlatformTraceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every logical model call leaves one `model_call` event, whatever happened to
 * it. Before this, only a successful call reached the recorder, and even then
 * as a running total: the prompt, the answer and every failure were gone.
 */
final class ModelPlatformTraceTest extends TestCase
{
    public function testASuccessfulCallRecordsThePromptTheAnswerAndItsOwnFigures(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([new MockResponse(json_encode([
            'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 8,
                'prompt_tokens_details' => ['cached_tokens' => 64],
                'completion_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ], JSON_THROW_ON_ERROR))], $recorder);

        $platform->text(NegotiationFixture::modelAccess(), 'SYSTEM', 'USER');
        $event = self::modelCalls($writer, $recorder)[0];

        self::assertSame(TraceKind::ModelCall->metaKeys(), array_keys($event->meta));
        self::assertSame('reply', $event->meta['purpose']);
        self::assertSame('ok', $event->meta['status']);
        self::assertSame(200, $event->meta['httpStatus']);
        self::assertSame('gpt-4o-mini', $event->meta['requestedModel']);
        self::assertSame('gpt-4o-mini-2024-07-18', $event->meta['servedModel']);
        self::assertSame('api.example.com', $event->meta['host']);
        self::assertSame(120, $event->meta['promptTokens']);
        self::assertSame(64, $event->meta['cachedTokens']);
        self::assertSame(0, $event->meta['reasoningTokens']);
        self::assertSame('stop', $event->meta['finishReason']);
        self::assertSame([], $event->meta['retries']);
        self::assertSame('SYSTEM', $event->content['request']['messages'][0]['content'] ?? null);
        self::assertSame('USER', $event->content['request']['messages'][1]['content'] ?? null);
        self::assertSame('Hello', $event->content['response']['choices'][0]['message']['content'] ?? null);
    }

    public function testTheApiKeyIsNowhereInTheTrace(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['Hello'], $recorder);

        $platform->text(NegotiationFixture::modelAccess(), 'SYSTEM', 'USER');

        self::assertStringNotContainsString(
            'sk-test',
            json_encode(self::modelCalls($writer, $recorder), JSON_THROW_ON_ERROR),
        );
    }

    public function testARetriedCallRecordsTheAttemptThatFailed(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            NegotiationFixture::modelReply('recovered'),
        ], $recorder);

        $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
        $event = self::modelCalls($writer, $recorder)[0];

        self::assertSame('ok', $event->meta['status']);
        self::assertSame([['httpStatus' => 503, 'transportError' => false]], $event->meta['retries']);
    }

    public function testAFailedCallRecordsTheStatusAndTheProvidersErrorBody(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([
            new MockResponse('{"error":"overloaded"}', ['http_code' => 503]),
            new MockResponse('{"error":"still overloaded"}', ['http_code' => 503]),
        ], $recorder);

        try {
            $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
            self::fail('Two 503s must escalate.');
        } catch (ModelUnavailable) {
        }

        $event = self::modelCalls($writer, $recorder)[0];
        self::assertSame('failed', $event->meta['status']);
        self::assertSame(503, $event->meta['httpStatus']);
        self::assertCount(1, $event->meta['retries']);
        self::assertNotNull($event->meta['errorClass']);
        self::assertSame('{"error":"still overloaded"}', $event->content['error']['body'] ?? null);
        self::assertSame('sys', $event->content['request']['messages'][0]['content'] ?? null);
    }

    public function testAnAnswerThatDoesNotMapIsKeptAndMarkedUnusable(): void
    {
        // The call worked and the answer is what went wrong -- exactly the
        // answer worth reading afterwards, and until now thrown away.
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['{"action":"sing"}'], $recorder);

        try {
            $platform->object(NegotiationFixture::modelAccess(), 'sys', 'usr', NegotiateResponse::class);
            self::fail('An unmappable answer must escalate.');
        } catch (ModelUnavailable) {
        }

        $event = self::modelCalls($writer, $recorder)[0];
        self::assertSame('unusable_answer', $event->meta['status']);
        self::assertSame('negotiate', $event->meta['purpose']);
        self::assertSame(
            '{"action":"sing"}',
            $event->content['response']['choices'][0]['message']['content'] ?? null,
        );
        self::assertArrayHasKey('error', $event->content);
    }

    public function testThePurposeIsReadOffTheAnswerType(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['{"price":{"additionalDiscountPercent":5}}'], $recorder);

        $platform->object(NegotiationFixture::modelAccess(), 'sys', 'usr', CommentInterpretation::class);

        self::assertSame('extract', self::modelCalls($writer, $recorder)[0]->meta['purpose']);
    }

    /** @return array{0: FakeDecisionWriter, 1: DecisionRecorder} */
    private static function openPass(): array
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        return [$writer, $recorder];
    }

    /** @return list<TraceDraft> */
    private static function modelCalls(FakeDecisionWriter $writer, DecisionRecorder $recorder): array
    {
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return array_values(array_filter(
            $writer->drafts[0]->trace,
            static fn(TraceDraft $t): bool => $t->kind === TraceKind::ModelCall,
        ));
    }
}
```

If `CommentInterpretation` needs more fields than `{"price":{…}}` to map, copy the minimal valid extract answer from `tests/Unit/Negotiation/AskInterpreterTest.php`. `NegotiationFixture::context()` exists (`tests/Unit/Negotiation/NegotiationFixture.php:114`).

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/phpunit --filter ModelPlatformTraceTest`
Expected: FAIL (no `model_call` events are recorded).

- [ ] **Step 3: Extend `ChatEnvelope`**

In `src/Negotiation/Response/ChatEnvelope.php`, replace `usage()` and add two readers:

```php
    /**
     * The `usage` block is OpenAI's shape and not every provider sends it, so a
     * missing count is null rather than an error — a model call that happened
     * is worth recording even when its cost is unknown. A path reaches the
     * nested detail blocks (`prompt_tokens_details.cached_tokens`).
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function usage(array $decoded, string ...$path): ?int
    {
        $value = $decoded['usage'] ?? null;

        foreach ($path as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return \is_int($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $decoded */
    public static function finishReason(array $decoded): ?string
    {
        $reason = $decoded['choices'][0]['finish_reason'] ?? null;

        return \is_string($reason) ? $reason : null;
    }

    /**
     * The model that actually answered, which a router or an alias can make
     * different from the one requested.
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function servedModel(array $decoded): ?string
    {
        $model = $decoded['model'] ?? null;

        return \is_string($model) ? $model : null;
    }
```

- [ ] **Step 4: Make the retry strategy remember its retries**

In `src/Negotiation/ModelRetryStrategy.php`:
- add to the class docblock: "Also remembers each attempt it sent round again, for the call's trace (ModelPlatform resets it per logical call). Mutable on a shared service, which is safe for the reason DecisionRecorder gives: a worker runs one pass, and one call, at a time.";
- add the property and two methods;
- rewrite `shouldRetry()`.

```php
    /** @var list<array{httpStatus: int, transportError: bool}> */
    private array $attempts = [];

    public function reset(): void
    {
        $this->attempts = [];
    }

    /** @return list<array{httpStatus: int, transportError: bool}> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    #[\Override]
    public function shouldRetry(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception,
    ): ?bool {
        $after = $context->getHeaders()['retry-after'][0] ?? null;
        $retry = $after !== null && (!is_string($after) || self::exceedsBudget($after))
            ? false
            : parent::shouldRetry($context, $responseContent, $exception);

        // Only an attempt that is tried again: the last one's fate is the
        // call's own status, recorded by ModelPlatform. 0 is "no response".
        if ($retry === true) {
            $this->attempts[] = ['httpStatus' => $context->getStatusCode(), 'transportError' => $exception !== null];
        }

        return $retry;
    }
```

- [ ] **Step 5: Write `ModelCallPurpose`**

`src/Negotiation/ModelCallPurpose.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/** Which of the agent's prompts a model call served, for its trace event. */
enum ModelCallPurpose: string
{
    case Extract = 'extract';
    case Negotiate = 'negotiate';
    case Reply = 'reply';
    case Other = 'other';

    // ponytail: read off the answer type instead of a parameter on every
    // object() call (18 call sites in tests). A new structured call reads
    // `other` until it is added here -- visible in the first export.
    public static function answering(string $type): self
    {
        return match ($type) {
            CommentInterpretation::class => self::Extract,
            NegotiateResponse::class => self::Negotiate,
            default => self::Other,
        };
    }
}
```

- [ ] **Step 6: Write `ModelCallTrace`**

`src/Negotiation/ModelCallTrace.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\Response\ChatEnvelope;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as TransportException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * One model call as a `model_call` trace event: `meta` is the call's figures
 * (TraceKind::ModelCall's allowlist), `content` is what was sent and what came
 * back. Built only after the call is over, so it never has to be amended.
 *
 * `request` is the exact body that was POSTed. The API key is not in it: it
 * travels as `auth_bearer`, a header, and nothing here reads headers.
 */
final readonly class ModelCallTrace
{
    /**
     * @param array<string, mixed> $request
     * @param list<array{httpStatus: int, transportError: bool}> $retries
     */
    public function __construct(
        private ModelCallPurpose $purpose,
        private ModelAccess $access,
        private array $request,
        public int $latencyMs,
        private array $retries,
    ) {}

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function answered(int $httpStatus, array $decoded, ?ModelUnavailable $unusable): array
    {
        $meta = [
            ...$this->common($unusable === null ? 'ok' : 'unusable_answer', $httpStatus, $unusable),
            'servedModel' => ChatEnvelope::servedModel($decoded),
            'promptTokens' => ChatEnvelope::usage($decoded, 'prompt_tokens'),
            'completionTokens' => ChatEnvelope::usage($decoded, 'completion_tokens'),
            'cachedTokens' => ChatEnvelope::usage($decoded, 'prompt_tokens_details', 'cached_tokens'),
            'reasoningTokens' => ChatEnvelope::usage($decoded, 'completion_tokens_details', 'reasoning_tokens'),
            'finishReason' => ChatEnvelope::finishReason($decoded),
        ];
        $content = ['request' => $this->request, 'response' => $decoded];

        if ($unusable !== null) {
            $content['error'] = self::error($unusable, null);
        }

        return [$meta, $content];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    public function failed(ModelUnavailable $error): array
    {
        $cause = $error->getPrevious();
        $response = $cause instanceof HttpExceptionInterface ? $cause->getResponse() : null;

        return [
            $this->common('failed', $response?->getStatusCode(), $error),
            ['request' => $this->request, 'error' => self::error($error, $cause)],
        ];
    }

    /**
     * A scheme-less baseUrl (a merchant typo) makes parse_url() read the
     * whole string as a path, so retry with an assumed scheme to recover the
     * host anyway. Never fall back to the raw string: it can carry a key in
     * its query, and this value lands in a merchant-readable audit column.
     */
    public static function host(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: parse_url('http://' . $baseUrl, PHP_URL_HOST);

        return \is_string($host) ? $host : 'unparsable-host';
    }

    /** @return array<string, mixed> */
    private function common(string $status, ?int $httpStatus, ?ModelUnavailable $error): array
    {
        return [
            'purpose' => $this->purpose->value,
            'requestedModel' => $this->access->model,
            'host' => self::host($this->access->baseUrl),
            'status' => $status,
            'httpStatus' => $httpStatus,
            'latencyMs' => $this->latencyMs,
            'retries' => $this->retries,
            'errorClass' => $error === null ? null : ($error->getPrevious() ?? $error)::class,
        ];
    }

    /**
     * The provider's error body is read with `getContent(false)`, which does
     * not throw on a 4xx/5xx -- but can on a body that never finished
     * arriving, and a trace must not throw into the pass it describes.
     *
     * @return array{message: string, cause: ?string, body: ?string}
     */
    private static function error(ModelUnavailable $error, ?\Throwable $cause): array
    {
        $body = null;

        if ($cause instanceof HttpExceptionInterface) {
            try {
                $body = $cause->getResponse()->getContent(false);
            } catch (TransportException) {
                $body = null;
            }
        }

        return ['message' => $error->getMessage(), 'cause' => $error->getPrevious()?->getMessage(), 'body' => $body];
    }
}
```

Note that the `failed` meta has no token, served-model or finish-reason keys. `TraceDraft::appendTo()` fills every declared key that is missing with `null`, so the event still carries the full allowlist.

- [ ] **Step 7: Rewrite `ModelPlatform` around one traced call**

In `src/Negotiation/ModelPlatform.php`:
- keep the class docblock;
- add to its end: "Every logical call leaves one `model_call` trace event (ModelCallTrace), including a call that failed and one whose answer did not map — the two a merchant most needs to read afterwards.";
- add the imports `MerchantQuoteAgentPlugin\Audit\TraceKind` and `MerchantQuoteAgentPlugin\Negotiation\ModelCallTrace` (the same namespace, so no import is needed for the second);
- replace everything from the `$http` property down to the end of the class with:

```php
    private HttpClientInterface $http;

    private ModelRetryStrategy $retries;

    public function __construct(
        HttpClientInterface $http,
        LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {
        // Symfony subtracts elapsed request time from max_duration on retry.
        // An idle timeout alone does not bound a slowly arriving response.
        // ModelRetryStrategy also refuses long provider Retry-After values,
        // which RetryableHttpClient otherwise honors without a delay cap.
        //
        // One consequence worth knowing when reading the audit trail: a retried
        // call's modelLatencyMs includes the failed attempt and the backoff.
        $this->retries = new ModelRetryStrategy();
        $this->http = new RetryableHttpClient(
            $http->withOptions(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS]),
            $this->retries,
            self::MAX_RETRIES,
            $logger,
        );
    }

    /** @throws ModelUnavailable */
    public function text(ModelAccess $access, string $system, string $user): string
    {
        return $this->call(
            ModelCallPurpose::Reply,
            $access,
            self::payload($access, $system, $user, []),
            static fn(string $answer): string => $answer,
        );
    }

    /**
     * The model answers under a JSON schema generated from `$type`, and the
     * answer is mapped straight onto it. Anything unusable throws: there is no
     * partial read and no default-and-carry-on, because an answer the model did
     * not actually produce would put words in the buyer's mouth and the
     * deciders would act on them.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ModelUnavailable
     */
    public function object(ModelAccess $access, string $system, string $user, string $type): object
    {
        $format = (new ResponseFormatFactory())->create($type);

        return $this->call(
            ModelCallPurpose::answering($type),
            $access,
            self::payload($access, $system, $user, ['response_format' => $format]),
            static fn(string $answer): object => self::mapped($answer, $type),
        );
    }

    /**
     * One logical call, traced whatever happens to it. `$read` turns the
     * message content into the caller's answer and may throw
     * ModelUnavailable: that is the `unusable_answer` case, where the call
     * worked and its answer is what went wrong.
     *
     * @template R
     *
     * @param array<string, mixed> $payload
     * @param \Closure(string): R $read
     *
     * @return R
     *
     * @throws ModelUnavailable
     */
    private function call(ModelCallPurpose $purpose, ModelAccess $access, array $payload, \Closure $read): mixed
    {
        // ModelAccess is built from merchant config where the model name may be
        // left blank (RawConfigValue::llm reports that as a credential problem
        // but still constructs the object). Escalating here beats POSTing an
        // empty `model` and letting the provider decide what that means. No
        // trace: no call happened.
        if ($access->model === '') {
            throw new ModelUnavailable('No model name is configured for this sales channel.');
        }

        $this->retries->reset();
        $startedAt = microtime(true);

        try {
            [$httpStatus, $decoded] = $this->post($access, $payload);
        } catch (ModelUnavailable $e) {
            $this->recorder->trace(TraceKind::ModelCall, ...$this->traceOf($purpose, $access, $payload, $startedAt)->failed($e));

            throw $e;
        }

        $trace = $this->traceOf($purpose, $access, $payload, $startedAt);

        // The running totals on the decision row stay: the dashboard and
        // bench-score.mjs read them. Not every OpenAI-compatible provider
        // sends `usage`, so a missing count is null rather than an error.
        $this->recorder->recordModelCall(
            $access->model,
            ModelCallTrace::host($access->baseUrl),
            ChatEnvelope::usage($decoded, 'prompt_tokens'),
            ChatEnvelope::usage($decoded, 'completion_tokens'),
            $trace->latencyMs,
        );

        try {
            $answer = $read(ChatEnvelope::content($decoded));
        } catch (ModelUnavailable $e) {
            $this->recorder->trace(TraceKind::ModelCall, ...$trace->answered($httpStatus, $decoded, $e));

            throw $e;
        }

        $this->recorder->trace(TraceKind::ModelCall, ...$trace->answered($httpStatus, $decoded, null));

        return $answer;
    }

    /** @param array<string, mixed> $payload */
    private function traceOf(ModelCallPurpose $purpose, ModelAccess $access, array $payload, float $startedAt): ModelCallTrace
    {
        return new ModelCallTrace(
            $purpose,
            $access,
            $payload,
            (int) round((microtime(true) - $startedAt) * 1000),
            $this->retries->attempts(),
        );
    }

    /**
     * @param array<string, mixed> $options extra top-level request body fields
     *
     * @return array<string, mixed>
     */
    private static function payload(ModelAccess $access, string $system, string $user, array $options): array
    {
        return [
            ...$options,
            'model' => $access->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ModelUnavailable
     */
    private static function mapped(string $answer, string $type): object
    {
        try {
            $mapped = (new ModelAnswerSerializer())->deserialize($answer, $type, 'json');
        } catch (SerializerException|\JsonException $e) {
            throw new ModelUnavailable('The model did not answer in the requested shape.', previous: $e);
        }

        if (!$mapped instanceof $type) {
            throw new ModelUnavailable('The model did not answer in the requested shape.');
        }

        return $mapped;
    }

    /**
     * ResponseInterface::toArray() is typed on array-key, but a JSON object
     * body decodes to string keys and a non-object body throws out of it.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: array<array-key, mixed>}
     *
     * @throws ModelUnavailable
     */
    private function post(ModelAccess $access, array $payload): array
    {
        try {
            // toArray() is what turns a non-2xx into an exception AND decodes
            // the body, so both failure modes land in the one catch below.
            $response = $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
                'auth_bearer' => $access->apiKey,
                'json' => $payload,
            ]);

            return [$response->getStatusCode(), $response->toArray()];
        } catch (TransportException $e) {
            // The transport contract covers a connection that never landed, a
            // 4xx/5xx the retry could not rescue, and a body that would not
            // decode. Every one of them means the same thing here: escalate.
            throw new ModelUnavailable('The model could not be reached.', previous: $e);
        }
    }
}
```

**If Mago's analyzer rejects the template flow through the closure** (e.g. "`object()` returns `object`, `T` expected"), do not add `@var` casts. Keep `object()` returning the closure's result, and add the same `instanceof $type` guard after `$this->call(...)` that `mapped()` has, with a one-line comment that it exists for the analyzer. If the line with `...$this->traceOf(...)->failed($e)` exceeds the formatter's width, let `composer run format` wrap it.

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit --filter 'ModelPlatform|ScriptedClient|AskInterpreter|OfferProposer|ReplyComposer|RecordedPass|HistoryRounds|LlmBuyer'`
Expected: PASS, including the existing `ModelPlatformTest` and `ModelPlatformRetryTest`, unchanged.

Run: `vendor/bin/phpunit`
Expected: PASS.

Run: `composer run format && composer run lint && composer run typecheck`
Expected: clean. If class-level cyclomatic complexity on `ModelPlatform` trips, first check that `hostOnly()` was removed; its two branches moved to `ModelCallTrace::host()`.

- [ ] **Step 9: Commit**

```bash
git add src/Negotiation tests/Unit/Negotiation/ModelPlatformTraceTest.php
git commit -m "feat(negotiation): trace every model call with its prompt, answer and figures

Failed calls, retried attempts and answers that did not map are recorded
too; until now only a successful call left anything, as a running total.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Trace a rejected reply rewrite

**Files:**
- Modify: `src/Negotiation/ReplyComposer.php` (`reword()`, the `$unsafe !== null` branch, around line 176)
- Test: `tests/Unit/Negotiation/ReplyComposerTest.php`

**Interfaces:**
- Consumes: `DecisionRecorder::trace()` and `TraceKind::ReplyGuard` (Tasks 1–2).
- Produces: a `reply_guard` event with meta `{accepted: false}` and content `{reason: string, reworded: string}`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Negotiation/ReplyComposerTest.php` (imports: `MerchantQuoteAgentPlugin\Audit\DecisionRecorder`, `MerchantQuoteAgentPlugin\Audit\TraceDraft`, `MerchantQuoteAgentPlugin\Audit\TraceKind`, `MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome`, `MerchantQuoteAgentPlugin\Negotiation\NegotiationPass`, `MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter`, `Psr\Log\NullLogger`, whichever are not imported yet):

```php
    public function testARejectedRewordingIsTracedWithTheGuardsReasonAndTheModelsText(): void
    {
        // Until now the rejected text and the reason lived only in a warning
        // log. They are what shows whether the guard over-fires.
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        [$client] = ScriptedClient::spy(['Thanks for your interest! We will be in touch soon.'], $recorder);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        (new ReplyComposer($client, self::prompts(), new NullLogger(), $recorder))
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $guards = array_values(array_filter(
            $writer->drafts[0]->trace,
            static fn(TraceDraft $t): bool => $t->kind === TraceKind::ReplyGuard,
        ));
        self::assertCount(1, $guards);
        self::assertSame(['accepted' => false], $guards[0]->meta);
        self::assertSame('Thanks for your interest! We will be in touch soon.', $guards[0]->content['reworded'] ?? null);
        self::assertIsString($guards[0]->content['reason'] ?? null);
    }

    public function testAnAcceptedRewordingLeavesNoGuardEvent(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $reworded =
            'We can bring this quote down by 5% to 950.00 EUR, valid until ' . NegotiationFixture::expires() . '.';
        [$client] = ScriptedClient::spy([$reworded], $recorder);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        (new ReplyComposer($client, self::prompts(), new NullLogger(), $recorder))
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame([], array_filter(
            $writer->drafts[0]->trace,
            static fn(TraceDraft $t): bool => $t->kind === TraceKind::ReplyGuard,
        ));
    }
```

- [ ] **Step 2: Run it and see it fail**

Run: `vendor/bin/phpunit --filter ReplyComposerTest`
Expected: the first new test FAILS (0 guard events). The second passes.

- [ ] **Step 3: Record the event**

In `src/Negotiation/ReplyComposer.php` `reword()`, inside `if ($unsafe !== null) {`, before `return $this->fallback(`, add the code below and import `MerchantQuoteAgentPlugin\Audit\TraceKind`:

```php
            // The reason goes to content, not meta: RewordingGuard quotes the
            // model's own words in it ("it names a concession nobody
            // authorised: 10%"), and meta leaves in every export.
            $this->recorder->trace(TraceKind::ReplyGuard, ['accepted' => false], [
                'reason' => $unsafe,
                'reworded' => $reworded,
            ]);
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter ReplyComposerTest`
Expected: PASS.

Run: `composer run format && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add src/Negotiation/ReplyComposer.php tests/Unit/Negotiation/ReplyComposerTest.php
git commit -m "feat(negotiation): trace a reply rewrite the guard rejected

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Carry the trace through the export

**Files:**
- Create: `src/Audit/Export/AnonymizedTrace.php`
- Modify:
  - `src/Audit/Export/DecisionExportStream.php`
  - `src/Command/DecisionExportCommand.php` (option description and `report()` notices)
  - `src/Resources/config/services.php` (the stream's args, line 410)
  - `snippet/en.json` and `de.json` (`export.withoutComments`)
  - `docs/for-merchants.md`
  - `docs/superpowers/specs/2026-09-23-run-trace-capture-design.md` (§3 "Free text")
- Test:
  - `tests/Unit/Audit/Export/AnonymizedTraceTest.php`
  - `tests/Unit/Audit/Export/TraceMetaCoverageTest.php`
  - `tests/Unit/Audit/Export/DecisionExportControllerTest.php` (constructor)
  - `tests/Unit/Command/DecisionExportCommandTest.php` (constructor)
  - `tests/Integration/DecisionExportTest.php`

**Interfaces:**
- Consumes: `TraceEvent` and `TraceKind` (Task 1), plus the events of Tasks 3–5.
- Produces:
  - `AnonymizedTrace::of(TraceEvent $event, bool $freeText, array $pseudonyms = []): array{kind, occurredAt, meta, content?}`, where `$pseudonyms` maps raw id to pseudonym and is applied inside `content`;
  - `DecisionExportStream::__construct(EntityRepository $decisions, EntityRepository $traces, SystemConfigService $systemConfig)`;
  - every exported line begins `{"record":"decision", …}` and ends with `"trace":[…]`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Audit/Export/AnonymizedTraceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedTrace;
use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use PHPUnit\Framework\TestCase;

final class AnonymizedTraceTest extends TestCase
{
    public function testWithoutFreeTextOnlyTheKindTheTimeAndTheMetaLeave(): void
    {
        self::assertSame(
            ['kind' => 'model_call', 'occurredAt' => '2031-05-05T12:00:00.123+00:00', 'meta' => ['purpose' => 'extract']],
            AnonymizedTrace::of(self::event(), freeText: false),
        );
    }

    public function testWithFreeTextTheContentLeavesToo(): void
    {
        $line = AnonymizedTrace::of(self::event(), freeText: true);

        self::assertSame(['request' => ['messages' => [['content' => 'anna@acme.example']]]], $line['content']);
    }

    public function testTheDecisionsOwnIdsInsideContentLeaveAsTheirPseudonyms(): void
    {
        // A quote snapshot and a prompt carry the raw quote, customer and
        // channel ids. The export promises those only ever leave pseudonymized,
        // free text or not, so they are swapped inside content too.
        $event = self::event();
        $event->content = ['identity' => ['quoteId' => 'aaaa', 'customerId' => 'bbbb'], 'note' => 'quote aaaa'];

        $line = AnonymizedTrace::of($event, freeText: true, pseudonyms: ['aaaa' => 'p-quote', 'bbbb' => 'p-cust']);

        self::assertSame(
            ['identity' => ['quoteId' => 'p-quote', 'customerId' => 'p-cust'], 'note' => 'quote p-quote'],
            $line['content'],
        );
    }

    private static function event(): TraceEvent
    {
        $event = new TraceEvent();
        $event->kind = 'model_call';
        $event->occurredAt = new \DateTimeImmutable('2031-05-05T12:00:00.123+00:00');
        $event->meta = ['purpose' => 'extract'];
        $event->content = ['request' => ['messages' => [['content' => 'anna@acme.example']]]];

        return $event;
    }
}
```

`tests/Unit/Audit/Export/TraceMetaCoverageTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `meta` leaves in every export, with or without free text. So every kind is
 * produced here through its real recording path, and every leaf of its meta
 * is checked to be something that cannot carry a person: a number, a bool,
 * null, or a short machine string. The same obligation ExportFieldCoverageTest
 * puts on decision columns -- a new kind fails here until it has a sample,
 * which forces someone to look at what its meta holds.
 */
final class TraceMetaCoverageTest extends TestCase
{
    /** Machine strings: enums, hashes, hosts, model names, class names, dotted paths. */
    private const MACHINE_STRING = '/^[A-Za-z0-9_.:\\\\\/ -]{0,160}$/';

    public function testEveryKindHasASampleFromItsRealRecordingPath(): void
    {
        $seen = array_unique(array_map(static fn(TraceDraft $t): string => $t->kind->value, self::samples()));
        $all = array_map(static fn(TraceKind $k): string => $k->value, TraceKind::cases());
        sort($seen);
        sort($all);

        self::assertSame($all, $seen, 'A TraceKind has no sample here. Add its real recording path to samples().');
    }

    public function testEveryMetaIsExactlyItsDeclaredKeysAndHoldsOnlyMachineValues(): void
    {
        foreach (self::samples() as $event) {
            $keys = array_values(array_diff(array_keys($event->meta), ['truncated']));
            self::assertSame($event->kind->metaKeys(), $keys, $event->kind->value . ' meta drifted from its allowlist.');

            array_walk_recursive($event->meta, static function (mixed $leaf, int|string $key) use ($event): void {
                if (\is_string($leaf)) {
                    self::assertMatchesRegularExpression(
                        self::MACHINE_STRING,
                        $leaf,
                        \sprintf('%s meta.%s holds text that is not a machine value: "%s"', $event->kind->value, $key, $leaf),
                    );

                    return;
                }

                self::assertTrue(
                    $leaf === null || \is_int($leaf) || \is_float($leaf) || \is_bool($leaf),
                    \sprintf('%s meta.%s is not a scalar.', $event->kind->value, $key),
                );
            });
        }
    }

    /** @return list<TraceDraft> */
    private static function samples(): array
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context()); // quote_before

        $recorder->recordDecision(new NegotiationDecision(
            Band::Grant,
            QuoteDecision::autoReply(new QuoteAutoReplyDetails(5.0, false, [], 14)),
        ), 10.0); // policy_verdict

        // model_call (the reply) and reply_guard: a rewording the guard rejects.
        [$client] = ScriptedClient::spy(['Anna, we will be in touch soon.'], $recorder);
        $after = NegotiationFixture::snapshot(state: 'in_review', totalNet: 950.0);
        (new ReplyComposer($client, new PromptComposer('E', 'N', 'R {{tone}}'), new NullLogger(), $recorder))->reply(
            new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]),
            $after,
            NegotiationFixture::settings(),
            5.0,
            SnapshotAdapter::conversation($after),
        );

        $recorder->recordApplied(new AppliedOffer(true, [], $after, 1000.0), ['updateLineItems']); // quote_after
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return $writer->drafts[0]->trace;
    }
}
```

In `DecisionExportControllerTest.php` line 96 and `DecisionExportCommandTest.php` line 73, add a second repository argument: `new DecisionExportStream($repository, $this->createMock(EntityRepository::class), $config)`. The controller test passes `$this->createMock(EntityRepository::class)` twice.

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/phpunit --filter 'AnonymizedTraceTest|TraceMetaCoverageTest|DecisionExportControllerTest|DecisionExportCommandTest'`
Expected:
- `AnonymizedTraceTest` FAILS (class missing);
- the two constructor tests FAIL (too many arguments);
- `TraceMetaCoverageTest` should PASS already. If it fails, a recording site from Tasks 3–5 put something non-machine into `meta`. Fix that site, not the regex.

- [ ] **Step 3: Write `AnonymizedTrace`**

`src/Audit/Export/AnonymizedTrace.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\TraceEvent;

/**
 * One trace row as one element of its decision line's `trace` list.
 *
 * Nothing to classify per field here, unlike AnonymizedDecision: the split
 * was made when the event was recorded. `meta` is TraceKind's allowlist and
 * leaves as it is (TraceMetaCoverageTest holds what it may contain); `content`
 * is everything else and leaves only with free text. No id of the event's
 * own leaves: a nested event is identified by its decision line and its place
 * in the list.
 *
 * `content` does carry raw ids -- a quote snapshot names the quote, the
 * customer and the channel, and so can a prompt -- and the export promises
 * those leave only as pseudonyms, free text or not. So the decision's own ids
 * are swapped for their pseudonyms inside the encoded content. Line-item and
 * product ids are not on that promise's list and stay as they are.
 */
final class AnonymizedTrace
{
    private function __construct() {}

    /**
     * @param array<string, string> $pseudonyms raw id => its export pseudonym
     *
     * @return array<string, mixed>
     */
    public static function of(TraceEvent $event, bool $freeText, array $pseudonyms = []): array
    {
        $line = [
            'kind' => $event->kind,
            'occurredAt' => $event->occurredAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'meta' => $event->meta,
        ];

        return $freeText ? [...$line, 'content' => self::pseudonymized($event->content, $pseudonyms)] : $line;
    }

    /**
     * On the encoded string, not by walking keys: an id can sit inside prose
     * ("quote 3f2a…") as well as in a field. strtr() replaces each id once and
     * never re-reads what it wrote.
     *
     * @param array<array-key, mixed>|null $content
     * @param array<string, string> $pseudonyms
     *
     * @return array<array-key, mixed>|null
     */
    private static function pseudonymized(?array $content, array $pseudonyms): ?array
    {
        if ($content === null || $pseudonyms === []) {
            return $content;
        }

        $encoded = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $decoded = \is_string($encoded) ? json_decode(strtr($encoded, $pseudonyms), true) : null;

        return \is_array($decoded) ? $decoded : null;
    }
}
```

- [ ] **Step 4: Nest the trace in the stream**

In `src/Audit/Export/DecisionExportStream.php`:
- add the constructor parameter `private EntityRepository $traces,` between `$decisions` and `$systemConfig`;
- import `MerchantQuoteAgentPlugin\Audit\TraceEvent`;
- at the top of `lines()` add `$context ??= Context::createDefaultContext();`, and pass `$context` to `RepositoryIterator` in place of `$context ?? Context::createDefaultContext()`;
- replace the `json_encode(AnonymizedDecision::of(...), ...)` argument with the `$row` built below.

```php
                $row = [
                    'record' => 'decision',
                    ...AnonymizedDecision::of($record, $pseudonym, $freeText),
                    'trace' => $this->traceOf($record, $pseudonym, $context, $freeText),
                ];
```

Add these methods above `criteria()`:

```php
    /**
     * The ids AnonymizedDecision pseudonymizes, as raw => pseudonym, for the
     * same swap inside trace content (see AnonymizedTrace).
     *
     * @return array<string, string>
     *
     * @throws \Random\RandomException
     */
    private static function pseudonymsOf(QuoteDecisionRecord $record, ExportPseudonym $pseudonym): array
    {
        $map = [];

        foreach ([$record->id, $record->quoteId, $record->customerId, $record->salesChannelId, $record->revisionVersionId, $record->strategyVersionId] as $raw) {
            $alias = $pseudonym->of($raw);

            if ($raw !== null && $alias !== null) {
                $map[$raw] = $alias;
            }
        }

        return $map;
    }

    /**
     * A decision's events in the order the pass recorded them.
     *
     * ponytail: one query per decision, so memory holds one pass's prompts
     * (~150 KB) rather than a page's (500 passes, ~75 MB). The ceiling is
     * query count: a 90-day export on a busy shop makes a few thousand. Batch
     * per page with a smaller page size if exports get slow.
     *
     * @return list<array<string, mixed>>
     */
    private function traceOf(
        QuoteDecisionRecord $record,
        ExportPseudonym $pseudonym,
        Context $context,
        bool $freeText,
    ): array {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('decisionId', $record->id));
        $criteria->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));

        $pseudonyms = $freeText ? self::pseudonymsOf($record, $pseudonym) : [];
        $trace = [];

        foreach ($this->traces->search($criteria, $context)->getEntities() as $event) {
            if ($event instanceof TraceEvent) {
                $trace[] = AnonymizedTrace::of($event, $freeText, $pseudonyms);
            }
        }

        return $trace;
    }
```

Add to the class docblock: "Each line starts with `record: \"decision\"` and carries the pass's trace events under `trace` (spec 2026-09-23 §3). PR 2 adds `record: \"event\"` lines for events outside a pass."

In `src/Resources/config/services.php` line 410, change the stream's args to:

```php
    $services->set(DecisionExportStream::class)->args([
        service('merchant_quote_agent_decision.repository'),
        service('merchant_quote_agent_trace.repository'),
        service(SystemConfigService::class),
    ]);
```

- [ ] **Step 5: Say what free text now includes**

In `src/Command/DecisionExportCommand.php`:
- replace the `include-comments` option description with:

```php
            'Also export free text: the buyer\'s own comments, the agent\'s replies, the model\'s full prompts and'
            . ' raw answers, snapshots of the quote, escalation prose and error messages. Withheld by default:'
            . ' these are the buyer\'s words, verbatim or repeated back by a model, and the prompts carry the'
            . ' account history the agent read.',
```

- in `report()`, replace the two notice strings with:

```php
            $freeText
                ? 'Free text IS included (--include-comments): the buyer\'s own comments, exactly as they were'
                . ' written, the agent\'s replies, the model\'s full prompts and raw answers (including the account'
                . ' history it read), snapshots of the quote, escalation prose and error messages.'
                : 'Free text is excluded: the buyer\'s own comments, the agent\'s replies, the model\'s full prompts'
                . ' and raw answers, snapshots of the quote, escalation prose and error messages. Pass'
                . ' --include-comments to include them.',
```

- in the `report()` record-count line, change `'%d record(s).` to `'%d decision record(s), each with its trace.`.

In `snippet/en.json`, set `"withoutComments": "Export without comments or prompts"`. In `snippet/de.json`, set `"withoutComments": "Ohne Kommentare und Prompts exportieren"`.

- [ ] **Step 6: Update the merchant docs and the spec**

In `docs/for-merchants.md`, in the "If you want to share your logs with us" section:

1. Replace "Either way you get one line of JSON for every decision the agent recorded in that range" with "Either way you get one line of JSON for every decision the agent recorded in that range, each carrying the step-by-step trace of how the agent got there".

2. In *Leaves, as it is.*, add a bullet after "Which prompt version ran, …":

   ```markdown
   - **For every step of every decision:** which call it was, the model that
     answered, token counts, timings, retries and whether it failed; the policy's
     verdict and its figures; whether a reworded reply was rejected; and how many
     lines the quote had before and after.
   ```

3. In *Does not leave, ever.*, delete these two sentences: "The quote number." and "The details behind a history lookup: which past quotes and orders the agent read, their numbers, products and prices, and which product it asked about." Add a new paragraph after that list:

   ```markdown
   *Leaves only with the comments.* The quote number and the details behind a
   history lookup — which past quotes and orders the agent read, their numbers,
   products and prices — never leave in their own fields. But the model's full
   prompts do contain them, because that is what the model was shown, and the
   full prompts are part of the comments below.
   ```

4. In "**The comments are the part to decide about …**", change "The customer's own message, the agent's replies, the model's raw answers, …" to "The customer's own message, the agent's replies, the model's full prompts and raw answers, snapshots of the quote, …".

5. Add a new paragraph after "…Nothing shows it to anyone outside your shop unless you export it.":

   ```markdown
   **The trace is kept, and nothing cleans it up.** Since this version the agent
   also stores, for every decision, exactly what it sent to the model and what came
   back — about 100 to 150 KB per decision. It stays in your shop, in its own table,
   until you uninstall the extension with "remove all data". `merchant-quote-agent:forget`
   clears it for one customer along with their comments.
   ```

6. Change "**Export without comments**" to "**Export without comments or prompts**".

In `docs/superpowers/specs/2026-09-23-run-trace-capture-design.md` §3, at the end of the "Free text" subsection, add:

```markdown
**This moves two things out of the "never leaves" list** in
`docs/for-merchants.md`: the quote number and the details behind a history
lookup. Neither leaves in its own field, but both are in the prompts the model
was shown, and the prompts are free text. The chat choice was "same as
comments", made knowing that traces carry customer history.

The pseudonym promise does hold inside `content`: the decision's own ids
(record, quote, customer, channel, revision, strategy version) are swapped
for their pseudonyms in the encoded content at export time
(`AnonymizedTrace`), because a quote snapshot carries them raw.
```

- [ ] **Step 7: Extend the integration test**

In `tests/Integration/DecisionExportTest.php`:
- change `seed()` to generate `$decisionId = Uuid::randomHex();`, use it as the decision's `'id'`, and return it (`: string`);
- after the decision `create`, seed one trace row:

```php
        $traces = static::getContainer()->get('merchant_quote_agent_trace.repository');
        self::assertInstanceOf(EntityRepository::class, $traces);
        $traces->create([[
            'id' => Uuid::randomHex(),
            'decisionId' => $decisionId,
            'quoteId' => $quoteId,
            'customerId' => $customerId,
            'kind' => 'model_call',
            'position' => 0,
            'occurredAt' => self::CREATED_AT,
            'meta' => ['purpose' => 'negotiate', 'status' => 'ok', 'promptTokens' => 812],
            // The negotiate prompt carries the rendered history block, which
            // is exactly what free text now lets leave (spec §3).
            'content' => [
                'request' => ['messages' => [[
                    'role' => 'user',
                    'content' => 'Account history: order 10009, quote ' . self::QUOTE_NUMBER . ', ' . self::EMAIL,
                ]]],
                // What a quote_before snapshot really carries: the raw ids.
                'identity' => ['quoteId' => $quoteId, 'customerId' => $customerId],
            ],
        ]], Context::createDefaultContext());
```

The existing free-text test `testTheFlagWidensFreeTextAndNothingElse` already asserts `assertStringNotContainsString($customerId, $jsonl)`. With this seed, that assertion now covers trace content: the raw customer id is in it and must leave only as its pseudonym.

Hoist the decision's `quoteId` into a `$quoteId = Uuid::randomHex();` local so the trace can reuse it.

In `testTheFlagWidensFreeTextAndNothingElse`, the assertion `self::assertStringNotContainsString('order 10009', $jsonl, 'The account history block never leaves.');` is no longer true under `--include-comments`. Replace it with:

```php
        $row = $this->firstRow($jsonl);
        self::assertSame([], array_values(array_filter(
            $row['historyReads']['rounds'] ?? [],
            static fn(mixed $round): bool => \is_array($round),
        )), 'The history column still exports only the kind of each read, never its result.');
        self::assertStringContainsString(
            'order 10009',
            json_encode($row['trace'] ?? [], JSON_THROW_ON_ERROR),
            'With free text the full negotiate prompt leaves, and the prompt carries the history block (spec §3).',
        );
```

The same test also asserts `self::assertStringNotContainsString(self::QUOTE_NUMBER, $jsonl);`. Under free text the prompt now carries the quote number, so narrow that assertion to the decision fields: `self::assertArrayNotHasKey('quoteNumber', $this->firstRow($jsonl));`.

Add three tests:

```php
    public function testEveryLineIsADecisionRecordCarryingItsTrace(): void
    {
        $this->seed(Uuid::randomHex());

        $row = $this->firstRow($this->export());

        self::assertSame('decision', $row['record'] ?? null);
        self::assertSame('model_call', $row['trace'][0]['kind'] ?? null);
        self::assertSame(812, $row['trace'][0]['meta']['promptTokens'] ?? null);
        self::assertArrayNotHasKey('content', $row['trace'][0], 'Trace content is free text.');
    }

    public function testTheDefaultExportKeepsTheTracesPromptBehind(): void
    {
        $this->seed(Uuid::randomHex());

        $jsonl = $this->export();

        self::assertStringNotContainsString('Account history', $jsonl);
        self::assertStringNotContainsString('order 10009', $jsonl);
    }

    public function testWithFreeTextTheTracesPromptLeaves(): void
    {
        $this->seed(Uuid::randomHex());

        $row = $this->firstRow($this->export(freeText: true));

        self::assertStringContainsString(
            'Account history',
            (string) ($row['trace'][0]['content']['request']['messages'][0]['content'] ?? ''),
        );
    }
```

Update the class docblock's `@mago-expect lint:too-many-methods` count sentence ("Nine cases …") to name the three new cases.

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit`
Expected: PASS.

Run: `composer run test:integration -- --filter DecisionExportTest`
Expected: PASS.

Run: `composer run quality:admin`
Expected: PASS (the snippet change only).

Run: `composer run format && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add src/Audit/Export src/Command/DecisionExportCommand.php src/Resources/config/services.php \
  src/Resources/app/administration/src/module/merchant-quote-agent/snippet docs/for-merchants.md \
  docs/superpowers/specs/2026-09-23-run-trace-capture-design.md tests/Unit/Audit/Export tests/Unit/Command tests/Integration/DecisionExportTest.php
git commit -m "feat(export): carry each decision's trace in the export

Every line is now record=decision with a nested trace. meta always leaves;
content, including the full prompts, only with free text.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Erase a customer's traces too

**Files:**
- Create: `src/Audit/Erasure.php`
- Modify: `src/Audit/DecisionEraser.php`, `src/Audit/DecisionEraserInterface.php`, `src/Command/DecisionForgetCommand.php`, `src/Resources/config/services.php` (the eraser's args, line 422)
- Test: `tests/Unit/Audit/DecisionEraserTest.php`, `tests/Unit/Command/DecisionForgetCommandTest.php`

**Interfaces:**
- Consumes: the `merchant_quote_agent_trace.repository` (Task 1).
- Produces:
  - `final readonly class Erasure { public int $decisions; public int $traces; }`;
  - `DecisionEraserInterface::forget(string $customerId, ?Context $context = null): Erasure`;
  - `DecisionEraser::__construct(EntityRepository $decisions, EntityRepository $traces)`.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Audit/DecisionEraserTest.php`:

- every `new DecisionEraser($repository)` becomes `new DecisionEraser($repository, self::traces())`;
- `$changed = …->forget('cust-1'); self::assertSame(1, $changed);` becomes `self::assertSame(1, …->forget('cust-1')->decisions);`;
- `self::assertSame(0, (new DecisionEraser(...))->forget('cust-unknown'));` becomes `->forget('cust-unknown')->decisions`.

Then add the helper and these tests, with the imports `MerchantQuoteAgentPlugin\Audit\Erasure`, `Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult` and `Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter`:

```php
    public function testTheCustomersTraceContentAndIdAreClearedAndItsMetaKept(): void
    {
        $record = self::record();
        $traces = self::traces(['t1', 't2']);

        $erased = (new DecisionEraser(self::repository($record), $traces))->forget('cust-1');

        self::assertSame(2, $erased->traces);
        self::assertSame(
            [['id' => 't1', 'content' => null, 'customerId' => null], ['id' => 't2', 'content' => null, 'customerId' => null]],
            $traces->updates[0],
            'meta is not in the payload, so it stays: the figures are the merchant\'s record, not the person.',
        );
    }

    public function testTracesAreFoundByCustomerAndByTheCustomersQuotes(): void
    {
        // A trace written before the quote had a pass (PR 3's UCP request)
        // knows the quote but not the customer. The quote ids come from the
        // decision rows, read before those rows lose their customerId.
        $record = self::record();
        $traces = self::traces(['t1']);

        (new DecisionEraser(self::repository($record), $traces))->forget('cust-1');

        $filter = $traces->criteria[0]->getFilters()[0] ?? null;
        self::assertInstanceOf(OrFilter::class, $filter);
        self::assertStringContainsString($record->quoteId, json_encode($filter, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('cust-1', json_encode($filter, JSON_THROW_ON_ERROR));
    }

    public function testNoTracesMeansNoTraceUpdate(): void
    {
        $traces = self::traces([]);

        $erased = (new DecisionEraser(self::repository(self::record()), $traces))->forget('cust-1');

        self::assertSame(0, $erased->traces);
        self::assertSame([], $traces->updates);
    }

    /** @param list<string> $ids */
    private static function traces(array $ids = []): EntityRepository
    {
        return new class($ids) extends EntityRepository {
            /** @var list<list<array<string, mixed>>> */
            public array $updates = [];

            /** @var list<Criteria> */
            public array $criteria = [];

            /** @param list<string> $ids */
            public function __construct(
                private readonly array $ids,
            ) {}

            public function searchIds(Criteria $criteria, Context $context): IdSearchResult
            {
                $this->criteria[] = $criteria;

                return new IdSearchResult(
                    \count($this->ids),
                    array_map(static fn(string $id): array => ['primaryKey' => $id, 'data' => []], $this->ids),
                    $criteria,
                    $context,
                );
            }

            public function update(array $data, Context $context): EntityWrittenContainerEvent
            {
                $this->updates[] = array_values($data);

                return new EntityWrittenContainerEvent($context, new \Shopware\Core\Framework\Event\NestedEventCollection(), []);
            }
        };
    }
```

`self::record()` in this file must carry a `quoteId`. If it does not, set `$record->quoteId = 'quote-1';` in it. Copy the anonymous-class constructor bypass exactly as the existing `repository()` helper at the bottom of this file does it: it overrides `__construct` without calling the parent. Mirror its `update()` return value too.

In `tests/Unit/Command/DecisionForgetCommandTest.php`:
- the fake's `forget()` returns `new Erasure($this->changed, 2)` with return type `Erasure`;
- import `MerchantQuoteAgentPlugin\Audit\Erasure`;
- add this assertion to the test that checks `'3 decision record(s)'`:

```php
        self::assertStringContainsString('2 trace event(s)', $tester->getDisplay());
```

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/phpunit --filter 'DecisionEraserTest|DecisionForgetCommandTest'`
Expected: FAIL.

- [ ] **Step 3: Write `Erasure` and change the interface**

`src/Audit/Erasure.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** What one erasure changed, so the merchant answering the request can say so. */
final readonly class Erasure
{
    public function __construct(
        public int $decisions,
        public int $traces,
    ) {}
}
```

In `DecisionEraserInterface`, change the method to:

```php
    public function forget(string $customerId, ?Context $context = null): Erasure;
```

and its docblock to `/** The decision records and trace events changed. */`.

- [ ] **Step 4: Erase the traces**

In `src/Audit/DecisionEraser.php`:
- add the constructor parameter `private EntityRepository $traces,`;
- import `OrFilter`, `EqualsAnyFilter` and `EqualsFilter` (the last is already imported);
- rewrite `forget()`:

```php
    #[\Override]
    public function forget(string $customerId, ?Context $context = null): Erasure
    {
        $context ??= Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $records = $this->decisions->search($criteria, $context)->getEntities();
        $payload = [];
        $quoteIds = [];

        foreach ($records as $record) {
            if (!$record instanceof QuoteDecisionRecord) {
                continue;
            }

            $payload[] = self::erased($record);
            $quoteIds[] = $record->quoteId;
        }

        // Before the decision rows lose their customerId: the quote ids read
        // off them are how a trace that never knew the customer is found.
        $traces = $this->forgetTraces($customerId, array_values(array_unique(array_filter($quoteIds))), $context);

        if ($payload !== []) {
            $this->decisions->update($payload, $context);
        }

        return new Erasure(\count($payload), $traces);
    }

    /**
     * `content` is the half of an event that can carry the person (see
     * TraceEvent); `meta` is the merchant's record of what happened and stays,
     * for the reason the decision rows stay. Ids only, never the rows: a
     * customer's content can be megabytes of prompts.
     *
     * @param list<string> $quoteIds
     */
    private function forgetTraces(string $customerId, array $quoteIds, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new OrFilter([
            new EqualsFilter('customerId', $customerId),
            ...($quoteIds === [] ? [] : [new EqualsAnyFilter('quoteId', $quoteIds)]),
        ]));

        $ids = array_values(array_filter($this->traces->searchIds($criteria, $context)->getIds(), \is_string(...)));

        if ($ids === []) {
            return 0;
        }

        $this->traces->update(array_map(
            static fn(string $id): array => ['id' => $id, 'content' => null, 'customerId' => null],
            $ids,
        ), $context);

        return \count($ids);
    }
```

Add this sentence to the class docblock's "WHAT IS CLEARED" paragraph: "The trace follows the same split: its `content` is cleared and its `meta` kept (2026-09-23 spec §3)."

In `services.php` line 422, change the eraser's args to:

```php
    $services->set(DecisionEraser::class)->args([
        service('merchant_quote_agent_decision.repository'),
        service('merchant_quote_agent_trace.repository'),
    ]);
```

- [ ] **Step 5: Report both counts**

In `DecisionForgetCommand::execute()`:
- replace `$changed = …` and the `success` line with:

```php
        $erased = $this->eraser->forget($customerId);

        // Zero is an answer, not a failure: a customer the agent never
        // negotiated with has nothing here, and a merchant answering an
        // erasure request needs to be able to say so.
        $io->success(\sprintf(
            '%d decision record(s) and %d trace event(s) no longer identify that customer.',
            $erased->decisions,
            $erased->traces,
        ));
```

- change the confirmation question to "…from every decision record and its trace, including the prompts sent to the model. The decisions themselves stay. Continue?".

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter 'DecisionEraserTest|DecisionForgetCommandTest'`
Expected: PASS.

Run: `vendor/bin/phpunit && composer run format && composer run lint && composer run typecheck`
Expected: PASS and clean.

- [ ] **Step 7: Commit**

```bash
git add src/Audit/Erasure.php src/Audit/DecisionEraser.php src/Audit/DecisionEraserInterface.php \
  src/Command/DecisionForgetCommand.php src/Resources/config/services.php tests/Unit/Audit/DecisionEraserTest.php tests/Unit/Command/DecisionForgetCommandTest.php
git commit -m "feat(audit): erase a customer's trace content along with their comments

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Prove it end to end on the test shop

**Files:**
- Modify: `tests/Integration/DecisionRecordTest.php` (`testARealPassWritesARealRow`, line 217), `tests/Integration/DecisionExportTest.php` (one ACL case)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: A real pass writes a real trace**

At the end of `testARealPassWritesARealRow`, add:

```php
        $traces = static::getContainer()->get('merchant_quote_agent_trace.repository');
        self::assertInstanceOf(EntityRepository::class, $traces);
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('decisionId', $record->id))
            ->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));
        $events = array_values(iterator_to_array($traces->search($criteria, Context::createDefaultContext())->getEntities()));
        $kinds = array_map(static fn($e): string => $e->kind, $events);

        self::assertSame('quote_before', $kinds[0] ?? null, 'Every pass opens with the quote as it was.');
        self::assertContains('policy_verdict', $kinds);
        self::assertContains('quote_after', $kinds);
        self::assertSame(
            ['extract', 'negotiate', 'reply'],
            array_values(array_map(
                static fn($e): string => $e->meta['purpose'] ?? '',
                array_filter($events, static fn($e): bool => $e->kind === 'model_call'),
            )),
            'One model_call per call the pass made, in order.',
        );
```

Import `Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting`.

- [ ] **Step 2: A viewer-role export reads the trace**

Add to `tests/Integration/DecisionExportTest.php`:

```php
    public function testAViewerRoleExportCarriesTheTrace(): void
    {
        // The dashboard's Export runs under the logged-in user's context, not
        // system scope. A viewer holds merchant_quote_agent_trace:read
        // (acl/index.ts); this pins that the trace comes through for them.
        $this->seed(Uuid::randomHex());
        $source = new \Shopware\Core\Framework\Api\Context\AdminApiSource(Uuid::randomHex());
        $source->setIsAdmin(false);
        $source->setPermissions(['merchant_quote_agent_decision:read', 'merchant_quote_agent_trace:read']);
        $context = new Context($source);

        $stream = static::getContainer()->get(\MerchantQuoteAgentPlugin\Audit\Export\DecisionExportStream::class);
        $lines = iterator_to_array($stream->lines(
            new \DateTimeImmutable('2031-05-05'),
            new \DateTimeImmutable('2031-05-06'),
            false,
            $context,
        ), false);

        self::assertNotSame([], $lines);
        $row = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('model_call', $row['trace'][0]['kind'] ?? null);
    }
```

If `DecisionExportStream` is not public in the container, get it through `static::getContainer()->get(DecisionExportController::class)` instead, or mark the stream `->public()` in services.php only if the existing integration tests already do that for another service. Check `tests/Integration/IntegrationTestCase.php` for how private services are reached (a test container usually exposes them).

- [ ] **Step 3: Run the integration suite**

Run: `composer run test:integration -- --filter 'DecisionRecordTest|DecisionExportTest|TraceEventTest'`
Expected: PASS.

Run: `composer run test:integration`
Expected: the suite is as green as it was on `main`. Known pre-existing failures (e.g. `PluginConfigTest` on a configured shop, the no-AC tests) are not caused by this PR; list them in the PR body instead of fixing them.

- [ ] **Step 4: The full gate**

Run: `composer run quality`
Expected: PASS. Then run `composer run quality:depcheck`: `psr/log` is already required, and nothing else new is imported.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/DecisionRecordTest.php tests/Integration/DecisionExportTest.php
git commit -m "test(audit): a real pass leaves a real trace, and a viewer exports it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
