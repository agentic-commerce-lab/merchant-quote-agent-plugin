# Quote Agent Admin Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One admin module under Orders showing what the quote agent has been doing — a decisions list with aggregate figures, and a per-quote decision trail — plus the write protection that makes that trail worth trusting.

**Architecture:** The audit entity from #19 already has admin API `search` and `aggregate` routes, generated automatically, so the page reads through `repositoryFactory` and `Criteria` and **no PHP is written for data**. The PHP in this plan is `#[Protection]` attributes and tests. Shopware's own `bin/build-administration.sh` compiles the module, so no JavaScript toolchain enters this repository.

**Tech Stack:** PHP 8.3, Shopware 6.7 (attribute entities, DAL aggregations, admin Vue 3 + Meteor components, TypeScript), PHPUnit 11, mago.

**Spec:** `docs/superpowers/specs/2026-08-31-quote-agent-admin-page-design.md`

## Global Constraints

- PHP 8.3, Shopware 6.7. Every PHP file starts with `declare(strict_types=1);`.
- **Gates**, all enforced by `composer run quality` which must exit 0: cyclomatic complexity 10 **class-scoped** (it sums across every method, so extracting a private helper does NOT relieve it); `excessive-parameter-list` 5; `too-many-methods` **10**; `too-many-properties` **10**; `excessive-nesting` 4; 400 physical lines per file.
- `QuoteDecisionRecord` carries a sanctioned `@mago-expect lint:too-many-properties`. Adding `Protection` attributes must not introduce another suppression.
- **The audit entity is admin-API only and must never reach the Store API.**
- `mago` reads only `src/`. It does not see TypeScript — that is a known gap the plan addresses with ESLint, not something to work around.
- **Stage files by name** (`git add <paths>`) — never `git add -A` or `git add .`.
- Commits are signed. Verify with `git cat-file commit HEAD | head -8 | grep -c gpgsig` = 1. Do **not** trust `git log --show-signature`: this machine has no `allowedSignersFile` and reports "No signature" on correctly signed commits.
- **If signing fails, STOP, leave everything staged, and report BLOCKED with the exact error — never `--no-verify`.** The 1Password vault has locked repeatedly; it recovers on its own within minutes.
- Unit tests: `./vendor/bin/phpunit --testsuite unit`. Integration tests: `composer run test:integration` (syncs this checkout into the `merchant-quote-shop` container and runs there; `-- --filter X` passes through).

---

## File Structure

**Create — PHP and tooling:**

| File | Responsibility |
|---|---|
| `tests/Unit/Audit/RecordFieldGuardsTest.php` | reflection guard: every field admin-api-only and write-protected |
| `tests/Integration/DecisionRecordGuardsTest.php` | behavioural: admin-scope write rejected; the page's aggregations |
| `scripts/lint-administration.sh` | ESLint over the plugin's admin source, inside the container |

**Create — the admin module**, all under `src/Resources/app/administration/`:

| File | Responsibility |
|---|---|
| `src/main.ts` | imports the module |
| `src/module/merchant-quote-agent/index.ts` | module + privilege registration, routes, navigation |
| `src/module/merchant-quote-agent/acl/index.ts` | privilege mapping |
| `src/module/merchant-quote-agent/snippet/en.json` | English strings |
| `src/module/merchant-quote-agent/snippet/de.json` | German strings |
| `.../page/merchant-quote-agent-list/index.ts` + `.html.twig` | list, time range, aggregate figures |
| `.../page/merchant-quote-agent-detail/index.ts` + `.html.twig` | the per-quote trail |

**Modify:** `src/Audit/QuoteDecisionRecord.php` (36 `#[Protection]` attributes).

---

## Task 1: Write-protect the audit record

**Files:**
- Modify: `src/Audit/QuoteDecisionRecord.php`
- Test: `tests/Unit/Audit/RecordFieldGuardsTest.php`

**Interfaces:**
- Produces: every `#[Field]` on `QuoteDecisionRecord` also carries `#[Protection(write: [Protection::SYSTEM_SCOPE])]`.

**Context.** `Protection` compiles to `WriteProtected`, whose constructor takes the scopes that **are allowed** to write. `Context::createDefaultContext()` — what `DecisionRecordWriter` uses — runs in `system` scope; admin-API requests run in `user`/`crud`. So this blocks `PATCH` and `POST` through the API while leaving the plugin's own writer untouched. Every existing test writes through `createDefaultContext()`, so none should break — if one does, stop and report it rather than adjusting the test.

This task absorbs issue #36. Both attributes it guards fail **open** when mistyped: `AttributeEntityCompiler` recognises only the literal keys `'admin-api'` and `'store-api'`, and an unrecognised key yields an empty `ApiAware` source list, which `ApiAware::__construct` expands to *both* `/api/` and `/store-api/`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Audit/RecordFieldGuardsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Protection;

/**
 * Both attributes this pins fail OPEN when mistyped, which is why they need a
 * test rather than a comment.
 *
 * `AttributeEntityCompiler` recognises only the literal keys 'admin-api' and
 * 'store-api'; an unrecognised or misspelt key yields an empty ApiAware source
 * list, and ApiAware expands an empty list to BOTH /api/ and /store-api/. A
 * one-character typo would silently publish the raw model proposal and the
 * merchant's authority limits to the Store API.
 *
 * A missing Protection attribute is quieter still: that field simply becomes
 * PATCH-able through the admin API, with nothing to notice.
 */
final class RecordFieldGuardsTest extends TestCase
{
    private const ADMIN_ONLY = ['admin-api' => true, 'store-api' => false];

    public function testEveryFieldIsAdminApiOnly(): void
    {
        foreach (self::attributes(Field::class) as $property => $field) {
            self::assertSame(
                self::ADMIN_ONLY,
                $field->api,
                sprintf('%s must be admin-api only; any other shape falls open to both APIs.', $property),
            );
        }
    }

    public function testEveryFieldIsWriteProtectedToSystemScope(): void
    {
        $protections = self::attributes(Protection::class);

        foreach (array_keys(self::attributes(Field::class)) as $property) {
            self::assertArrayHasKey(
                $property,
                $protections,
                sprintf('%s has no #[Protection]; it is PATCH-able through the admin API.', $property),
            );
            self::assertSame([Protection::SYSTEM_SCOPE], $protections[$property]->write, $property);
        }
    }

    public function testTheRecordHasFieldsAtAll(): void
    {
        // Guards the two tests above against passing vacuously if the
        // reflection ever stops finding attributes.
        self::assertNotEmpty(self::attributes(Field::class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return array<string, T>
     */
    private static function attributes(string $attribute): array
    {
        $found = [];

        foreach ((new \ReflectionClass(QuoteDecisionRecord::class))->getProperties() as $property) {
            $instances = $property->getAttributes($attribute);

            if ($instances !== []) {
                $found[$property->getName()] = $instances[0]->newInstance();
            }
        }

        return $found;
    }
}
```

- [ ] **Step 2: Run it to verify the protection test fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter RecordFieldGuardsTest`
Expected: `testEveryFieldIsAdminApiOnly` and `testTheRecordHasFieldsAtAll` PASS; `testEveryFieldIsWriteProtectedToSystemScope` FAILS with "has no #[Protection]".

- [ ] **Step 3: Add the Protection attribute to every field**

In `src/Audit/QuoteDecisionRecord.php`, add the import and one attribute line above **every** `#[Field(...)]`, including the primary key:

```php
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Protection;

    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $id = '';
```

All 36 fields get it. Missing one leaves that field patchable; a uniform rule has no judgment calls in it.

Extend the class docblock to say why:

```php
 * Write-protected to system scope on every field: DecisionRecordWriter writes
 * through Context::createDefaultContext(), which is system scope, while
 * admin-API requests are user/crud scope. So the plugin can write its own
 * record and nobody can PATCH it afterwards. This does NOT cover DELETE —
 * WriteProtected is enforced during field encoding and a delete encodes no
 * fields — which is why the admin module's ACL grants delete only to an
 * explicit deleter role.
```

- [ ] **Step 4: Run the guard test and the full unit suite**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: all green, including all three guard tests.

- [ ] **Step 5: Prove the api guard catches a typo**

Temporarily change one field's `api:` key to `'admin_api'` (underscore). Run `./vendor/bin/phpunit --testsuite unit --filter testEveryFieldIsAdminApiOnly`. Expected: FAIL. Restore the key, confirm `git diff --stat src/` is empty apart from your intended change, and re-run green. Report the failure output.

- [ ] **Step 6: Run the integration suite — nothing should break**

Run: `composer run test:integration`
Expected: 75 tests green. Every existing test writes through `createDefaultContext()` (system scope) and is unaffected. **If any integration test now fails on a write, STOP and report it** — that means something writes in a non-system scope and the protection needs rethinking, not the test.

- [ ] **Step 7: Verify the gates and commit**

```bash
composer run quality
git add src/Audit/QuoteDecisionRecord.php tests/Unit/Audit/RecordFieldGuardsTest.php
git commit -m "feat: write-protect every audit field and pin both exposure attributes"
```

---

## Task 2: Prove the protection behaviourally, and pin the page's aggregations

**Files:**
- Test: `tests/Integration/DecisionRecordGuardsTest.php`

**Interfaces:**
- Consumes: the `#[Protection]` attributes from Task 1.
- Produces: nothing later tasks import; this pins contracts the admin page depends on.

**Context.** A **new** test class, not `DecisionRecordTest` — measured: that class sits at 6 own methods plus 3 from the `PipelineFixture` trait, and trait methods count toward `too-many-methods`, leaving one slot against a cap of 10. The split is coherent anyway: `DecisionRecordTest` proves the record round-trips, this one proves the guards and the figures.

Attribute inspection is not proof. This task proves the rejection actually happens, and pins the exact aggregations the page will run so the numbers it shows have a server-side test behind them.

A write-protected field rejection builds a `ConstraintViolation` carrying the message `This field is write-protected.`, collected into the context's exceptions and surfacing as a `WriteException` at the end of the write.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/DecisionRecordGuardsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The guards behind the admin page: that the trail cannot be edited through
 * the API, and that the figures the page shows are queries the database
 * actually answers.
 */
final class DecisionRecordGuardsTest extends IntegrationTestCase
{
    public function testAnAdminScopedWriteIsRejected(): void
    {
        $repository = self::records();
        $id = Uuid::randomHex();

        $this->expectException(WriteException::class);

        Context::createDefaultContext()->scope(
            Context::USER_SCOPE,
            static function (Context $userContext) use ($repository, $id): void {
                $repository->create([self::row($id, Uuid::randomHex(), 'offered', 5.0)], $userContext);
            },
        );
    }

    public function testTheSameWriteSucceedsInSystemScope(): void
    {
        // The other half of the finding: protection must stop the admin API
        // without stopping DecisionRecordWriter, which writes in system scope.
        $repository = self::records();
        $id = Uuid::randomHex();

        $repository->create([self::row($id, Uuid::randomHex(), 'offered', 5.0)], Context::createDefaultContext());

        self::assertNotNull($repository->search(new Criteria([$id]), Context::createDefaultContext())->first());
    }

    public function testTheValueHandledAggregationCountsEachQuoteOnce(): void
    {
        // A quote serviced twice has two rows. Summing totalNetBefore would
        // report its value twice, so the page takes the max per quoteId and
        // sums the buckets. This pins that shape.
        $repository = self::records();
        $quoteA = Uuid::randomHex();
        $quoteB = Uuid::randomHex();

        $repository->create([
            self::row(Uuid::randomHex(), $quoteA, 'offered', 5.0, 1000.0),
            self::row(Uuid::randomHex(), $quoteA, 'countered', 7.0, 1000.0),
            self::row(Uuid::randomHex(), $quoteB, 'offered', 3.0, 500.0),
        ], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('quoteId', [$quoteA, $quoteB]));
        $criteria->addAggregation(
            new TermsAggregation('per-quote', 'quoteId', null, null, new MaxAggregation('value', 'totalNetBefore')),
        );

        $result = $repository->aggregate($criteria, Context::createDefaultContext())->get('per-quote');
        self::assertInstanceOf(TermsResult::class, $result);
        self::assertCount(2, $result->getBuckets(), 'One bucket per quote, not per pass.');

        $total = 0.0;
        foreach ($result->getBuckets() as $bucket) {
            $max = $bucket->getResult();
            self::assertInstanceOf(MaxResult::class, $max);
            $total += (float) $max->getMax();
        }

        self::assertSame(1500.0, $total, 'Quote A counted once at 1000, not twice.');
    }

    public function testTheDiscountAverageExcludesEscalatedPasses(): void
    {
        // A verification-failed pass carries a real granted discount, because
        // the write happened and the database shows the reduction. Averaging
        // those in would mix discounts the agent stood behind with ones it
        // applied and then escalated over.
        $repository = self::records();
        $quoteId = Uuid::randomHex();

        $repository->create([
            self::row(Uuid::randomHex(), $quoteId, 'offered', 5.0),
            self::row(Uuid::randomHex(), $quoteId, 'countered', 7.0),
            self::row(Uuid::randomHex(), $quoteId, 'escalated', 40.0),
        ], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addFilter(new EqualsAnyFilter('outcome', ['offered', 'countered']));
        $criteria->addAggregation(new AvgAggregation('granted', 'discountPercentGranted'));

        $average = $repository->aggregate($criteria, Context::createDefaultContext())->get('granted');
        self::assertInstanceOf(AvgResult::class, $average);
        self::assertSame(6.0, $average->getAvg(), 'The 40% escalated row must not be averaged in.');
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /** @return array<string, mixed> */
    private static function row(
        string $id,
        string $quoteId,
        string $outcome,
        float $granted,
        float $totalNetBefore = 1000.0,
    ): array {
        return [
            'id' => $id,
            'quoteId' => $quoteId,
            'outcome' => $outcome,
            'discountPercentGranted' => $granted,
            'maxDiscountPercent' => 10.0,
            'totalNetBefore' => $totalNetBefore,
            'durationMs' => 100,
        ];
    }
}
```

That is 4 test methods plus 2 helpers — 6, within the cap of 10.

- [ ] **Step 2: Run them**

Run: `composer run test:integration -- --filter DecisionRecordGuardsTest`
Expected: 4 tests PASS. `testAnAdminScopedWriteIsRejected` passes only because Task 1 landed; if it fails, the protection is not doing what the attribute claims.

- [ ] **Step 3: Prove the rejection test is not passing for the wrong reason**

`expectException(WriteException::class)` is broad — a missing required field also raises it. Confirm the exception is specifically about protection: temporarily catch it instead of expecting it, and assert its message contains `write-protected`. If it does not, tighten the test to assert on that message permanently and say so. Report which you found.

- [ ] **Step 4: Run everything and commit**

```bash
./vendor/bin/phpunit --testsuite unit
composer run test:integration
composer run quality
git add tests/Integration/DecisionRecordGuardsTest.php
git commit -m "test: prove the trail resists admin writes and pins the page's figures"
```

---

## Task 3: The admin module skeleton

**Files:**
- Create: `src/Resources/app/administration/src/main.ts`
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/index.ts`
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/acl/index.ts`
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`
- Create: `.../page/merchant-quote-agent-list/index.ts` and `merchant-quote-agent-list.html.twig`
- Create: `scripts/lint-administration.sh`

**Interfaces:**
- Produces: module `merchant-quote-agent`; routes `merchant.quote.agent.index` and `merchant.quote.agent.detail`; components `merchant-quote-agent-list` and (Task 5) `merchant-quote-agent-detail`; ACL keys `merchant_quote_agent.viewer` and `merchant_quote_agent.deleter`; snippet root `merchant-quote-agent`.

**Context.** This is the plugin's first TypeScript. The deliverable is narrow and checkable: the page appears in the admin under Orders, gated by ACL, with an empty list. Content arrives in Tasks 4–6.

Shopware's `bin/build-administration.sh` in the container compiles it — **no `package.json`, no bundler config in this repository**. `scripts/sync-to-shop.sh` already copies everything except `vendor`, `.git`, `report` and `node_modules`, so admin sources reach the container.

The reference module is SwagCommercial's `sw-settings-warehouse` at `/Users/sebastian/projects/SwagCommercial/src/MultiWarehouse/Resources/app/administration/` — read it for conventions before writing.

- [ ] **Step 1: The ACL privileges**

Create `acl/index.ts`:

```ts
export const privileges = {
    category: 'permissions',
    parent: null,
    key: 'merchant_quote_agent',
    roles: {
        viewer: {
            privileges: ['merchant_quote_agent_decision:read'],
            dependencies: [],
        },
        deleter: {
            privileges: ['merchant_quote_agent_decision:delete'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};
```

No `creator`, no `editor`. Nothing should create or update an audit row through the admin, and omitting the roles states that more clearly than a comment.

- [ ] **Step 2: The module registration**

Create `module/merchant-quote-agent/index.ts`:

```ts
import { privileges } from './acl';
import './page/merchant-quote-agent-list';

import deDE from './snippet/de.json';
import enGB from './snippet/en.json';

Shopware.Service('privileges').addPrivilegeMappingEntry(privileges);

Shopware.Module.register('merchant-quote-agent', {
    type: 'plugin',
    name: 'merchant-quote-agent',
    title: 'merchant-quote-agent.general.mainMenuItemGeneral',
    description: 'merchant-quote-agent.general.description',
    color: '#57D9A3',
    icon: 'regular-robot',
    entity: 'merchant_quote_agent_decision',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'merchant-quote-agent-list',
            path: 'index',
            meta: {
                privilege: 'merchant_quote_agent.viewer',
            },
        },
        detail: {
            component: 'merchant-quote-agent-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'merchant.quote.agent.index',
                privilege: 'merchant_quote_agent.viewer',
            },
        },
    },

    navigation: [
        {
            label: 'merchant-quote-agent.general.mainMenuItemGeneral',
            color: '#57D9A3',
            path: 'merchant.quote.agent.index',
            icon: 'regular-robot',
            parent: 'sw-order',
            position: 30,
            privilege: 'merchant_quote_agent.viewer',
        },
    ],
});
```

`parent: 'sw-order'`, `position: 30` puts it directly after SwagCommercial's Quotes, which registers at position 20.

The `detail` route is registered now even though Task 5 builds the component — registering a module with routes is what keeps a later settings view cheap to add.

- [ ] **Step 3: The entry point**

Create `src/main.ts`:

```ts
import './module/merchant-quote-agent';
```

- [ ] **Step 4: The snippets**

Create `snippet/en.json`:

```json
{
    "merchant-quote-agent": {
        "general": {
            "mainMenuItemGeneral": "Quote Agent",
            "description": "What the quote agent has been doing"
        },
        "list": {
            "columnCreatedAt": "When",
            "columnQuoteNumber": "Quote",
            "columnOutcome": "Outcome",
            "columnBand": "Band",
            "columnGranted": "Granted",
            "columnDuration": "Duration",
            "columnEscalationReason": "Escalation reason",
            "emptyStateTitle": "No decisions yet",
            "emptyStateSubline": "The agent has not serviced a quote in this period."
        }
    }
}
```

Create `snippet/de.json` with the same structure and German values:

```json
{
    "merchant-quote-agent": {
        "general": {
            "mainMenuItemGeneral": "Quote Agent",
            "description": "Was der Quote Agent bisher getan hat"
        },
        "list": {
            "columnCreatedAt": "Wann",
            "columnQuoteNumber": "Angebot",
            "columnOutcome": "Ergebnis",
            "columnBand": "Rahmen",
            "columnGranted": "Gewährt",
            "columnDuration": "Dauer",
            "columnEscalationReason": "Eskalationsgrund",
            "emptyStateTitle": "Noch keine Entscheidungen",
            "emptyStateSubline": "Der Agent hat in diesem Zeitraum kein Angebot bearbeitet."
        }
    }
}
```

Both ship from the start: the string set is small now, and retrofitting German later means revisiting every label.

- [ ] **Step 5: A minimal list page so the route resolves**

Create `page/merchant-quote-agent-list/merchant-quote-agent-list.html.twig`:

```twig
{% block merchant_quote_agent_list %}
<sw-page class="merchant-quote-agent-list">
    {% block merchant_quote_agent_list_content %}
    <template #content>
        <sw-card-view>
            <sw-empty-state
                :title="$tc('merchant-quote-agent.list.emptyStateTitle')"
                :subline="$tc('merchant-quote-agent.list.emptyStateSubline')"
            />
        </sw-card-view>
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

Create `page/merchant-quote-agent-list/index.ts`:

```ts
import template from './merchant-quote-agent-list.html.twig';

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['acl'],

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },
});
```

- [ ] **Step 6: The lint script**

Create `scripts/lint-administration.sh`, mirroring `scripts/test-integration.sh`:

```bash
#!/usr/bin/env bash
# Lint this plugin's administration sources with the shop's own ESLint.
# mago only reads src/*.php, so nothing in this repo's own gates sees the
# TypeScript. The shop ships the config; we borrow it, the same way
# test-integration.sh borrows the shop's PHPUnit.
#
#   composer run lint:administration
#   SHOP_CONTAINER=shopware-trunk composer run lint:administration
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
cd "$(dirname "$0")/.."

SHOP_CONTAINER="$CONTAINER" scripts/sync-to-shop.sh

docker exec -w /var/www/html/vendor/shopware/administration/Resources/app/administration "$CONTAINER" \
  npx eslint \
  --no-error-on-unmatched-pattern \
  "/var/www/html/custom/plugins/MerchantQuoteAgentPlugin/src/Resources/app/administration/src/**/*.ts" "$@"
```

Make it executable (`chmod +x`) and add to `composer.json`'s scripts:

```json
"lint:administration": "scripts/lint-administration.sh"
```

**If ESLint cannot run in the container** — a missing `node_modules`, or the config not resolving from that working directory — do not spend more than a few minutes on it. Report exactly what failed and commit the rest of the task; the lint gap is already named in the spec as known, and a broken script is worse than an absent one.

- [ ] **Step 7: Build the administration and verify the page appears**

```bash
scripts/sync-to-shop.sh
docker exec merchant-quote-shop php8.3 /var/www/html/bin/console plugin:refresh
docker exec merchant-quote-shop sh -lc 'cd /var/www/html && ./bin/build-administration.sh'
```

The build takes several minutes. Expected: it completes without error and mentions the plugin.

Then confirm the module is registered: open `http://localhost:8095/admin` and check that **Quote Agent** appears under Orders and opens an empty state.

**If you cannot reach a browser, say so and report the build output instead** — a clean build plus the route registered is acceptable evidence for this task. Do not claim you saw the page if you did not.

- [ ] **Step 8: Verify the gates and commit**

`composer run quality` covers only PHP and must still exit 0 (nothing PHP changed here, so this is a regression check).

```bash
composer run quality
./vendor/bin/phpunit --testsuite unit
git add src/Resources/app/administration scripts/lint-administration.sh composer.json
git commit -m "feat: register the Quote Agent admin module under Orders"
```

---

## Task 4: The decisions list

**Files:**
- Modify: `.../page/merchant-quote-agent-list/index.ts` and `merchant-quote-agent-list.html.twig`
- Modify: `.../snippet/en.json`, `.../snippet/de.json`

**Interfaces:**
- Consumes: the module and component registered in Task 3.
- Produces: a `criteria` computed property Task 5's aggregates reuse for its time filter.

**Context.** `sw-entity-listing` handles sorting, paging and delete. Verified props include `dataSource`, `columns`, `repository`, `isLoading`, `sortBy`, `sortDirection`, `showSelection`, `showSettings`, `detailRoute`, `allowEdit`, `allowView`, `allowDelete`.

`allowEdit` must be **false** — nothing may update an audit row — and `allowDelete` is bound to the ACL deleter role.

The verified admin `Criteria` static helpers: `Criteria.range(field, params)`, `Criteria.equalsAny(field, values)`, `Criteria.equals(field, value)`, `Criteria.terms(name, field, limit, sorting, aggregation)`, `Criteria.max(name, field)`, `Criteria.count(name, field)`.

- [ ] **Step 1: Add the list snippets**

Add to both snippet files under `merchant-quote-agent`, English shown:

```json
"range": {
    "label": "Period",
    "last7": "Last 7 days",
    "last30": "Last 30 days",
    "last90": "Last 90 days"
}
```

German: `"label": "Zeitraum"`, `"last7": "Letzte 7 Tage"`, `"last30": "Letzte 30 Tage"`, `"last90": "Letzte 90 Tage"`.

- [ ] **Step 2: Implement the component**

Replace `page/merchant-quote-agent-list/index.ts`:

```ts
import template from './merchant-quote-agent-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            decisions: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            rangeDays: 30,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        /** The range every query on this page shares, so the figures and the rows agree. */
        rangeFilter() {
            const from = new Date();
            from.setDate(from.getDate() - this.rangeDays);

            return Criteria.range('createdAt', { gte: from.toISOString() });
        },

        listCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(this.rangeFilter);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            return criteria;
        },

        columns() {
            return [
                { property: 'createdAt', label: 'merchant-quote-agent.list.columnCreatedAt', primary: true },
                { property: 'quoteNumber', label: 'merchant-quote-agent.list.columnQuoteNumber' },
                { property: 'outcome', label: 'merchant-quote-agent.list.columnOutcome' },
                { property: 'band', label: 'merchant-quote-agent.list.columnBand' },
                { property: 'discountPercentGranted', label: 'merchant-quote-agent.list.columnGranted' },
                { property: 'durationMs', label: 'merchant-quote-agent.list.columnDuration' },
                { property: 'escalationReason', label: 'merchant-quote-agent.list.columnEscalationReason' },
            ];
        },

        rangeOptions() {
            return [
                { value: 7, label: this.$tc('merchant-quote-agent.range.last7') },
                { value: 30, label: this.$tc('merchant-quote-agent.range.last30') },
                { value: 90, label: this.$tc('merchant-quote-agent.range.last90') },
            ];
        },
    },

    watch: {
        rangeDays() {
            this.load();
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            try {
                this.decisions = await this.decisionRepository.search(this.listCriteria, Shopware.Context.api);
            } finally {
                this.isLoading = false;
            }
        },

        onSortColumn(column) {
            if (this.sortBy === column.property) {
                this.sortDirection = this.sortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sortBy = column.property;
                this.sortDirection = 'ASC';
            }

            this.load();
        },
    },
});
```

- [ ] **Step 3: Implement the template**

Replace `merchant-quote-agent-list.html.twig`:

```twig
{% block merchant_quote_agent_list %}
<sw-page class="merchant-quote-agent-list">
    {% block merchant_quote_agent_list_smart_bar_header %}
    <template #smart-bar-header>
        <h2>{{ $tc('merchant-quote-agent.general.mainMenuItemGeneral') }}</h2>
    </template>
    {% endblock %}

    {% block merchant_quote_agent_list_content %}
    <template #content>
        <sw-card-view>
            {% block merchant_quote_agent_list_range %}
            <sw-card :title="$tc('merchant-quote-agent.range.label')">
                <sw-single-select
                    v-model:value="rangeDays"
                    :options="rangeOptions"
                    :label="$tc('merchant-quote-agent.range.label')"
                />
            </sw-card>
            {% endblock %}

            {% block merchant_quote_agent_list_grid %}
            <sw-card :title="$tc('merchant-quote-agent.general.mainMenuItemGeneral')">
                <sw-entity-listing
                    v-if="decisions"
                    :repository="decisionRepository"
                    :data-source="decisions"
                    :columns="columns"
                    :is-loading="isLoading"
                    :sort-by="sortBy"
                    :sort-direction="sortDirection"
                    :show-selection="false"
                    :show-settings="false"
                    :allow-edit="false"
                    :allow-view="true"
                    :allow-delete="acl.can('merchant_quote_agent.deleter')"
                    detail-route="merchant.quote.agent.detail"
                    @column-sort="onSortColumn"
                />

                <sw-empty-state
                    v-else
                    :title="$tc('merchant-quote-agent.list.emptyStateTitle')"
                    :subline="$tc('merchant-quote-agent.list.emptyStateSubline')"
                />
            </sw-card>
            {% endblock %}
        </sw-card-view>
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

`:allow-edit="false"` is load-bearing, not cosmetic: an audit row must never be editable from the UI, and Task 1's write protection would reject the write anyway — but a UI that offers an action the API refuses is a bug in itself.

- [ ] **Step 4: Build and verify**

```bash
scripts/sync-to-shop.sh
docker exec merchant-quote-shop sh -lc 'cd /var/www/html && ./bin/build-administration.sh'
```

Expected: clean build. Then check the page lists decisions, the range selector re-queries, sorting works, and no edit affordance is offered.

If the shop has no decision rows to show, create a few first:

```bash
composer run test:integration -- --filter testARealPassWritesARealRow
```

That test rolls back, so instead insert rows directly with a short `bin/console` script or accept the empty state and say so in the report.

- [ ] **Step 5: Lint, gates and commit**

```bash
composer run lint:administration
composer run quality
git add src/Resources/app/administration
git commit -m "feat: list the agent's decisions with a time range"
```

---

## Task 5: The aggregate figures

**Files:**
- Modify: `.../page/merchant-quote-agent-list/index.ts` and `merchant-quote-agent-list.html.twig`
- Modify: `.../snippet/en.json`, `.../snippet/de.json`

**Interfaces:**
- Consumes: `rangeFilter` from Task 4.

**Context.** These are the figures #7 exists for. Task 2 pinned the two non-obvious ones server-side; this task builds the same shapes in TypeScript. **If a figure here disagrees with Task 2's test, the TypeScript is wrong, not the test.**

Three of the figures cannot come from the audit table, which holds only quotes the agent serviced. `received` and `expired unanswered` need the `quote` entity, which exposes `stateMachineState` and `amountNet` as `ApiAware`.

Definitions, from the spec:

- **received** — count of quotes created in range
- **auto-answered** — decisions with `outcome IN ('offered','countered')`
- **escalated** — decisions with `outcome = 'escalated'`
- **quotes handled** — bucket count of the per-quote terms aggregation
- **value handled** — sum of per-quote `max(totalNetBefore)`, never a plain sum
- **granted vs cap** — two averages, both filtered to `outcome IN ('offered','countered')`
- **expired unanswered** — quotes `expired` in range with no decision whose outcome is offered or countered

Shares are computed against **received**.

- [ ] **Step 1: Add the figure snippets**

Add to both files under `merchant-quote-agent`, English shown:

```json
"figures": {
    "title": "This period",
    "received": "Quotes received",
    "handled": "Handled by the agent",
    "autoAnswered": "Answered automatically",
    "escalated": "Escalated to a human",
    "expiredUnanswered": "Expired unanswered",
    "valueHandled": "Value handled (net)",
    "granted": "Average discount granted",
    "grantedAgainstCap": "{granted}% against a {cap}% cap"
}
```

German: `"title": "Dieser Zeitraum"`, `"received": "Eingegangene Angebote"`, `"handled": "Vom Agenten bearbeitet"`, `"autoAnswered": "Automatisch beantwortet"`, `"escalated": "An Menschen eskaliert"`, `"expiredUnanswered": "Unbeantwortet abgelaufen"`, `"valueHandled": "Bearbeiteter Wert (netto)"`, `"granted": "Durchschnittlich gewährter Rabatt"`, `"grantedAgainstCap": "{granted}% bei einem Limit von {cap}%"`.

- [ ] **Step 2: Add the aggregate queries**

Add to the component's `data()`: `figures: null`.

Add these methods:

```ts
        /** One aggregate request against the decisions, one against the quotes. */
        async loadFigures() {
            const [decisions, quotes] = await Promise.all([
                this.aggregateDecisions(),
                this.aggregateQuotes(),
            ]);

            const received = quotes.received;
            const handled = decisions.perQuote.buckets.length;

            this.figures = {
                received,
                handled,
                autoAnswered: decisions.autoAnswered,
                escalated: decisions.escalated,
                expiredUnanswered: quotes.expired - decisions.answeredQuoteIds.size,
                valueHandled: decisions.perQuote.buckets.reduce(
                    (sum, bucket) => sum + Number(bucket.value?.max ?? 0),
                    0,
                ),
                granted: decisions.granted,
                cap: decisions.cap,
            };
        },

        async aggregateDecisions() {
            const criteria = new Criteria(1, 1);
            criteria.addFilter(this.rangeFilter);
            criteria.addAggregation(
                Criteria.terms('perQuote', 'quoteId', null, null, Criteria.max('value', 'totalNetBefore')),
            );

            const answered = new Criteria(1, 1);
            answered.addFilter(this.rangeFilter);
            answered.addFilter(Criteria.equalsAny('outcome', ['offered', 'countered']));
            answered.addAggregation(Criteria.count('answered', 'id'));
            answered.addAggregation(Criteria.terms('answeredQuotes', 'quoteId'));
            answered.addAggregation(Criteria.avg('granted', 'discountPercentGranted'));
            answered.addAggregation(Criteria.avg('cap', 'maxDiscountPercent'));

            const escalated = new Criteria(1, 1);
            escalated.addFilter(this.rangeFilter);
            escalated.addFilter(Criteria.equals('outcome', 'escalated'));
            escalated.addAggregation(Criteria.count('escalated', 'id'));

            const [all, answeredResult, escalatedResult] = await Promise.all([
                this.decisionRepository.aggregate(criteria, Shopware.Context.api),
                this.decisionRepository.aggregate(answered, Shopware.Context.api),
                this.decisionRepository.aggregate(escalated, Shopware.Context.api),
            ]);

            return {
                perQuote: all.perQuote,
                autoAnswered: answeredResult.answered?.count ?? 0,
                escalated: escalatedResult.escalated?.count ?? 0,
                granted: answeredResult.granted?.avg ?? null,
                cap: answeredResult.cap?.avg ?? null,
                answeredQuoteIds: new Set(
                    (answeredResult.answeredQuotes?.buckets ?? []).map((bucket) => bucket.key),
                ),
            };
        },

        async aggregateQuotes() {
            const quoteRepository = this.repositoryFactory.create('quote');

            const received = new Criteria(1, 1);
            received.addFilter(this.rangeFilter);
            received.addAggregation(Criteria.count('received', 'id'));

            const expired = new Criteria(1, 1);
            expired.addFilter(this.rangeFilter);
            expired.addFilter(Criteria.equals('stateMachineState.technicalName', 'expired'));
            expired.addAggregation(Criteria.count('expired', 'id'));

            const [receivedResult, expiredResult] = await Promise.all([
                quoteRepository.aggregate(received, Shopware.Context.api),
                quoteRepository.aggregate(expired, Shopware.Context.api),
            ]);

            return {
                received: receivedResult.received?.count ?? 0,
                expired: expiredResult.expired?.count ?? 0,
            };
        },

        share(value) {
            const received = this.figures?.received ?? 0;

            return received > 0 ? Math.round((value / received) * 100) : null;
        },
```

Call `this.loadFigures()` from `load()`, alongside the list query.

**Verify `Criteria.avg` exists** in the installed Meteor SDK before relying on it — it was not observable in this repo's vendor tree. If it does not, use `Criteria.stats('granted', 'discountPercentGranted')` and read `.avg` off the stats result, which returns min/max/avg/sum. Report which you used.

**`expiredUnanswered` subtracts a set size from a count**, which is only correct because both are scoped to the same range. If you change one filter, change both.

- [ ] **Step 3: Render the figures**

Insert before the grid card in the template:

```twig
{% block merchant_quote_agent_list_figures %}
<sw-card
    v-if="figures"
    :title="$tc('merchant-quote-agent.figures.title')"
    class="merchant-quote-agent-figures"
>
    <sw-description-list>
        <dt>{{ $tc('merchant-quote-agent.figures.received') }}</dt>
        <dd>{{ figures.received }}</dd>

        <dt>{{ $tc('merchant-quote-agent.figures.handled') }}</dt>
        <dd>{{ figures.handled }} <template v-if="share(figures.handled) !== null">({{ share(figures.handled) }}%)</template></dd>

        <dt>{{ $tc('merchant-quote-agent.figures.autoAnswered') }}</dt>
        <dd>{{ figures.autoAnswered }} <template v-if="share(figures.autoAnswered) !== null">({{ share(figures.autoAnswered) }}%)</template></dd>

        <dt>{{ $tc('merchant-quote-agent.figures.escalated') }}</dt>
        <dd>{{ figures.escalated }} <template v-if="share(figures.escalated) !== null">({{ share(figures.escalated) }}%)</template></dd>

        <dt>{{ $tc('merchant-quote-agent.figures.expiredUnanswered') }}</dt>
        <dd>{{ figures.expiredUnanswered }}</dd>

        <dt>{{ $tc('merchant-quote-agent.figures.valueHandled') }}</dt>
        <dd>{{ figures.valueHandled }}</dd>

        <dt>{{ $tc('merchant-quote-agent.figures.granted') }}</dt>
        <dd v-if="figures.granted !== null">
            {{ $tc('merchant-quote-agent.figures.grantedAgainstCap', 0, {
                granted: figures.granted.toFixed(1),
                cap: (figures.cap ?? 0).toFixed(1),
            }) }}
        </dd>
        <dd v-else>&ndash;</dd>
    </sw-description-list>
</sw-card>
{% endblock %}
```

- [ ] **Step 4: Check the figures against the database**

Build, then compare at least the average granted discount and the value handled against a direct query. The easiest cross-check is Task 2's tests, which assert the same shapes — if the page shows a different average over the same rows, the TypeScript criteria differ from the tested ones.

Report the two numbers you compared and whether they matched.

- [ ] **Step 5: Lint, gates and commit**

```bash
composer run lint:administration
composer run quality
git add src/Resources/app/administration
git commit -m "feat: show what the agent did this period, and what it cost"
```

---

## Task 6: The decision detail

**Files:**
- Create: `.../page/merchant-quote-agent-detail/index.ts` and `merchant-quote-agent-detail.html.twig`
- Modify: `.../module/merchant-quote-agent/index.ts` (import the new page)
- Modify: `.../snippet/en.json`, `.../snippet/de.json`

**Interfaces:**
- Consumes: the `detail` route registered in Task 3.

**Context.** #7 requires this to be *"readable by a merchant, not a log dump"*, so the record's 36 fields become six labelled sections rather than a field table. Four columns are JSON — `interpretedAsks`, `violations`, `writes`, `errorChain` — and get light structured rendering, not a pretty-printed blob.

**Two fields mislead a reader who assumes the obvious**, and the UI must not let them:

- `modelLatencyMs` **excludes a failed retry attempt's time.** Label it model time and show `durationMs` beside it as the pass's own clock.
- `attempt` is the **crash-budget counter at pass start, not a delivery number.** A thrown-and-redelivered pass records `0` again. Omit it, rather than labelling it in a way a reader will misread.

- [ ] **Step 1: Add the detail snippets**

Add to both files under `merchant-quote-agent`, English shown:

```json
"detail": {
    "asked": "What the buyer asked",
    "allowed": "What policy allowed",
    "offered": "What was offered",
    "model": "What the model was told, and what it cost",
    "told": "What the buyer was told",
    "failed": "What went wrong",
    "band": "Band",
    "cap": "Cap",
    "escalationReason": "Escalation reason",
    "before": "Total before (net)",
    "after": "Total after (net)",
    "granted": "Granted",
    "writes": "Writes performed",
    "authorized": "Authorized",
    "verified": "Verified",
    "violations": "Verifier objections",
    "modelName": "Model",
    "modelHost": "Host",
    "tokens": "Tokens (prompt / completion)",
    "modelLatency": "Model time (excludes a failed retry)",
    "duration": "Pass duration",
    "promptHashes": "Prompt versions (extract / negotiate / reply)",
    "noError": "Nothing went wrong on this pass."
}
```

German equivalents follow the same keys: `"asked": "Was der Käufer gefragt hat"`, `"allowed": "Was die Richtlinie erlaubt hat"`, `"offered": "Was angeboten wurde"`, `"model": "Was das Modell erhalten hat und was es gekostet hat"`, `"told": "Was dem Käufer gesagt wurde"`, `"failed": "Was schiefgegangen ist"`, `"band": "Rahmen"`, `"cap": "Limit"`, `"escalationReason": "Eskalationsgrund"`, `"before": "Summe vorher (netto)"`, `"after": "Summe nachher (netto)"`, `"granted": "Gewährt"`, `"writes": "Ausgeführte Schreibvorgänge"`, `"authorized": "Autorisiert"`, `"verified": "Verifiziert"`, `"violations": "Einwände der Prüfung"`, `"modelName": "Modell"`, `"modelHost": "Host"`, `"tokens": "Tokens (Prompt / Antwort)"`, `"modelLatency": "Modellzeit (ohne fehlgeschlagenen Wiederholungsversuch)"`, `"duration": "Dauer des Durchlaufs"`, `"promptHashes": "Prompt-Versionen (Extract / Negotiate / Reply)"`, `"noError": "Bei diesem Durchlauf ist nichts schiefgegangen."`.

- [ ] **Step 2: Implement the component**

Create `page/merchant-quote-agent-detail/index.ts`:

```ts
import template from './merchant-quote-agent-detail.html.twig';

Shopware.Component.register('merchant-quote-agent-detail', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            record: null,
            isLoading: false,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            try {
                this.record = await this.decisionRepository.get(this.$route.params.id, Shopware.Context.api);
            } finally {
                this.isLoading = false;
            }
        },

        /** JSON columns rendered as readable pairs rather than a dumped blob. */
        entries(value) {
            if (value === null || value === undefined) {
                return [];
            }

            if (Array.isArray(value)) {
                return value.map((item, index) => ({
                    key: String(index),
                    value: typeof item === 'object' ? JSON.stringify(item) : String(item),
                }));
            }

            return Object.entries(value).map(([key, item]) => ({
                key,
                value: typeof item === 'object' ? JSON.stringify(item) : String(item),
            }));
        },
    },
});
```

- [ ] **Step 3: Implement the template**

Create `page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig`:

```twig
{% block merchant_quote_agent_detail %}
<sw-page class="merchant-quote-agent-detail">
    <template #smart-bar-header>
        <h2>{{ record ? record.quoteNumber : '' }}</h2>
    </template>

    <template #content>
        <sw-card-view v-if="record">
            {% block merchant_quote_agent_detail_asked %}
            <sw-card :title="$tc('merchant-quote-agent.detail.asked')" :is-loading="isLoading">
                <sw-description-list>
                    <template v-for="entry in entries(record.interpretedAsks)" :key="entry.key">
                        <dt>{{ entry.key }}</dt>
                        <dd>{{ entry.value }}</dd>
                    </template>
                </sw-description-list>
            </sw-card>
            {% endblock %}

            {% block merchant_quote_agent_detail_allowed %}
            <sw-card :title="$tc('merchant-quote-agent.detail.allowed')">
                <sw-description-list>
                    <dt>{{ $tc('merchant-quote-agent.detail.band') }}</dt>
                    <dd>{{ record.band }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.cap') }}</dt>
                    <dd>{{ record.maxDiscountPercent }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.escalationReason') }}</dt>
                    <dd>{{ record.escalationReason || '&ndash;' }}</dd>
                </sw-description-list>
            </sw-card>
            {% endblock %}

            {% block merchant_quote_agent_detail_offered %}
            <sw-card :title="$tc('merchant-quote-agent.detail.offered')">
                <sw-description-list>
                    <dt>{{ $tc('merchant-quote-agent.detail.before') }}</dt>
                    <dd>{{ record.totalNetBefore }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.after') }}</dt>
                    <dd>{{ record.totalNetAfter }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.granted') }}</dt>
                    <dd>{{ record.discountPercentGranted }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.authorized') }}</dt>
                    <dd>{{ record.authorized }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.verified') }}</dt>
                    <dd>{{ record.verified }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.writes') }}</dt>
                    <dd>{{ (record.writes || []).join(', ') || '&ndash;' }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.violations') }}</dt>
                    <dd>{{ (record.violations || []).join(', ') || '&ndash;' }}</dd>
                </sw-description-list>
            </sw-card>
            {% endblock %}

            {% block merchant_quote_agent_detail_model %}
            <sw-card :title="$tc('merchant-quote-agent.detail.model')">
                <sw-description-list>
                    <dt>{{ $tc('merchant-quote-agent.detail.modelName') }}</dt>
                    <dd>{{ record.model || '&ndash;' }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.modelHost') }}</dt>
                    <dd>{{ record.modelHost || '&ndash;' }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.tokens') }}</dt>
                    <dd>{{ record.promptTokens }} / {{ record.completionTokens }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.modelLatency') }}</dt>
                    <dd>{{ record.modelLatencyMs }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.duration') }}</dt>
                    <dd>{{ record.durationMs }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.promptHashes') }}</dt>
                    <dd>{{ record.extractPromptHash }} / {{ record.negotiatePromptHash }} / {{ record.replyPromptHash }}</dd>
                </sw-description-list>
            </sw-card>
            {% endblock %}

            {% block merchant_quote_agent_detail_told %}
            <sw-card :title="$tc('merchant-quote-agent.detail.told')">
                <p>{{ record.buyerComment || '&ndash;' }}</p>
            </sw-card>
            {% endblock %}

            {% block merchant_quote_agent_detail_failed %}
            <sw-card :title="$tc('merchant-quote-agent.detail.failed')">
                <p v-if="!record.errorClass">{{ $tc('merchant-quote-agent.detail.noError') }}</p>
                <sw-description-list v-else>
                    <dt>{{ record.errorClass }}</dt>
                    <dd>
                        <template v-for="entry in entries(record.errorChain)" :key="entry.key">
                            {{ entry.value }}<br>
                        </template>
                    </dd>
                </sw-description-list>
            </sw-card>
            {% endblock %}
        </sw-card-view>
    </template>
</sw-page>
{% endblock %}
```

- [ ] **Step 4: Import the page in the module**

In `module/merchant-quote-agent/index.ts`, add beside the list import:

```ts
import './page/merchant-quote-agent-detail';
```

- [ ] **Step 5: Build and verify**

Build, open a decision from the list, and confirm every section renders — including one record that escalated (so `escalationReason` is populated) and one that succeeded (so `buyerComment` is). Report what you saw, or say plainly if you could not reach a browser.

- [ ] **Step 6: Lint, gates and commit**

```bash
composer run lint:administration
composer run quality
./vendor/bin/phpunit --testsuite unit
git add src/Resources/app/administration
git commit -m "feat: show the full decision trail for one quote"
```

---

## Task 7: Close the absorbed issue and record what shipped

**Files:** none — GitHub only.

- [ ] **Step 1: Close #36 as absorbed**

`RecordFieldGuardsTest` from Task 1 covers both the `api` and `Protection` attributes, which is what #36 asked for plus the half it did not know it needed. Close it with a comment saying the guard shipped here, naming the test file, and explaining that both attributes fail open when mistyped — so the one test guards two failure modes.

- [ ] **Step 2: Comment on #7**

State what shipped: the module under Orders, the list with its time range, the aggregate figures, the per-quote trail, the ACL roles, and the write protection. Note explicitly that the in-module settings area remains deferred, with the reasoning already recorded on the issue.

Say which of #7's requirements are met and which are not, rather than implying completeness.

- [ ] **Step 3: File the TypeScript coverage gap**

Only if `scripts/lint-administration.sh` could not be made to work in Task 3. Title it after the gap — that the plugin's admin sources have no automated check — and record what failed, so the next person does not rediscover it.

---

## Self-Review

**Spec coverage.** Module structure, placement and snippets → Task 3. ACL roles → Task 3. `Protection` on every field → Task 1. The reflection guard absorbing #36 → Task 1, closed in Task 7. Behavioural scope rejection → Task 2. Aggregation contracts → Task 2. List with time range → Task 4. All seven aggregate figures including the two `quote`-entity queries → Task 5. Per-quote trail with JSON rendering → Task 6. The `modelLatencyMs` and `attempt` labelling caveats → Task 6. ESLint script → Task 3. Deferred settings area → out of scope, recorded on #7.

**Placeholder scan.** None. Every code step carries its code, including both snippet languages.

**Type consistency.** The module key `merchant-quote-agent`, entity `merchant_quote_agent_decision`, ACL keys `merchant_quote_agent.viewer` / `.deleter`, routes `merchant.quote.agent.index` / `.detail`, component names `merchant-quote-agent-list` / `-detail`, and the snippet root `merchant-quote-agent` are identical everywhere they appear. `rangeFilter` is defined in Task 4 and consumed in Task 5 under that name.

**Verified against the running shop rather than assumed:** the entity's admin API routes including `POST /api/aggregate/merchant-quote-agent-decision`; `TermsAggregation`'s nested-aggregation and `limit` parameters; `Context::createDefaultContext()` running in `system` scope; `WriteProtected` being enforced during field encoding and therefore not covering `DELETE`; `sw-entity-listing`'s props; SwagCommercial's Quotes registering at `parent: 'sw-order', position: 20`; and `Criteria.terms(name, field, limit, sorting, aggregation)`, `Criteria.max`, `Criteria.count`, `Criteria.range`, `Criteria.equalsAny`, `Criteria.equals` in real admin code.

**The one thing an implementer must verify rather than trust:** `Criteria.avg`. It was not observable in this repo's vendor tree because the Meteor SDK ships as `node_modules`. Task 5 names `Criteria.stats(...).avg` as the fallback and asks which was used.

**Known gap, stated rather than implied:** nothing enforces that the TypeScript builds the same criteria Task 2's tests verify. If the page's figures disagree with a direct query, that is the seam — which is why Task 5 Step 4 asks for an explicit comparison.
