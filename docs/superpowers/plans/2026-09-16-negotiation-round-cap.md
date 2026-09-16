# Negotiation Round Cap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bound one quote's model spend by escalating to a human after fifteen agent passes.

**Architecture:** A counter on the quote's `customFields`, incremented in the
`updateQuote()` call `ServiceQuoteHandler::claimAttempt()` already makes before
every pass, and read at the top of `NegotiationPipeline::negotiate()` before the
extract model call. Past the cap the pipeline escalates through the existing
`OfferRound::escalated()` → `QuoteEscalator` path under a new
`QuoteEscalationReason::RoundLimitExceeded`, so the refusal costs no model call
and still gets its audit row from the recorder that has already begun the pass.

**Tech Stack:** PHP 8.4, Shopware 6.7 plugin, PHPUnit 11, mago (format + lint +
analyze), Node `--experimental-strip-types` for the admin checks.

**Spec:** `docs/superpowers/specs/2026-09-16-negotiation-round-cap-design.md`

## Global Constraints

- The counter key is `merchant_quote_agent_rounds`; the cap is `15`. Both live
  on `NegotiationRounds` and nowhere else — no literal `15` and no literal key
  string anywhere in `src/`.
- The counter is **never cleared** on a normal exit. `ATTEMPTS_KEY` is, and
  copying that line is the mistake Task 3's second test exists to catch.
- `config.xml` gains nothing. No migration. No new field in `QuoteAgentSettings`,
  `NegotiationPolicyArray`, `QuoteLimits` or `QuoteAgentSettingsReader::KEYS`.
- The cap check runs **before** `$this->interpreter->interpret(...)`. A test that
  does not assert the model was never called has not tested the feature.
- Escalation goes through `$this->round->escalated(...)`, never through
  `QuoteEscalator` directly: that is what records the reason on the audit draft.
- mago enforces a five-parameter limit on constructors and methods, and a file
  length check (`composer run quality:filesize`). Nothing in this plan needs a
  new constructor parameter anywhere — if you find yourself adding one, stop and
  re-read the task.
- Commit message trailer, on every commit:
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`
- Run `php vendor/bin/mago fmt src tests` before every commit; the pre-commit
  hook runs a format check and a lint over staged files and will reject the
  commit otherwise.

---

### Task 1: `NegotiationRounds`

The counter's only owner: the key, the cap, the read and the increment fragment.
Shaped after `src/Negotiation/QuoteBaseline.php`, which is the customFields owner
it sits beside — read that file first for the house style of these classes.

**Files:**
- Create: `src/Servicing/NegotiationRounds.php`
- Test: `tests/Unit/Servicing/NegotiationRoundsTest.php`

**Interfaces:**
- Consumes: `MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot` (note: the
  *Bridge* snapshot, which is what the handler and the pipeline both hold — not
  `Policy\Data\QuoteSnapshot`, which is a different class).
- Produces:
  - `NegotiationRounds::KEY` — `string`, `'merchant_quote_agent_rounds'`
  - `NegotiationRounds::MAX` — `int`, `15`
  - `NegotiationRounds::completed(QuoteSnapshot $snapshot): int`
  - `NegotiationRounds::exhausted(QuoteSnapshot $snapshot): bool`
  - `NegotiationRounds::increment(QuoteSnapshot $snapshot): array<string, int>`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Servicing/NegotiationRoundsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;
use PHPUnit\Framework\TestCase;

final class NegotiationRoundsTest extends TestCase
{
    public function testAQuoteWithNoCounterHasCompletedNoRounds(): void
    {
        self::assertSame(0, NegotiationRounds::completed(ServicingHandlerFixture::snapshot()));
        self::assertFalse(NegotiationRounds::exhausted(ServicingHandlerFixture::snapshot()));
    }

    public function testTheLastPassInsideTheCapStillNegotiates(): void
    {
        // The boundary, stated from both sides so an off-by-one cannot pass:
        // MAX - 1 completed passes means this quote has had 14 and may have a
        // 15th; MAX completed means the budget is spent.
        $snapshot = ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => NegotiationRounds::MAX - 1,
        ]);

        self::assertSame(NegotiationRounds::MAX - 1, NegotiationRounds::completed($snapshot));
        self::assertFalse(NegotiationRounds::exhausted($snapshot));
    }

    public function testTheCapIsReachedAtTheConfiguredNumberOfPasses(): void
    {
        self::assertTrue(NegotiationRounds::exhausted(ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => NegotiationRounds::MAX,
        ])));
    }

    public function testAQuoteDrivenPastTheCapStaysExhausted(): void
    {
        self::assertTrue(NegotiationRounds::exhausted(ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => NegotiationRounds::MAX + 7,
        ])));
    }

    public function testAValueThatIsNotAnIntReadsAsNoRounds(): void
    {
        // customFields is a JSON column and nothing stops another writer
        // putting a string there. A count that is not a number is not a count,
        // and reading it as one would either crash the pass or cap a quote
        // that has negotiated nothing.
        foreach (['12', null, [], 3.5] as $stored) {
            self::assertSame(0, NegotiationRounds::completed(
                ServicingHandlerFixture::snapshot([NegotiationRounds::KEY => $stored]),
            ));
        }
    }

    public function testIncrementReturnsTheStoredValuePlusOne(): void
    {
        self::assertSame(
            [NegotiationRounds::KEY => 4],
            NegotiationRounds::increment(ServicingHandlerFixture::snapshot([NegotiationRounds::KEY => 3])),
        );
        self::assertSame(
            [NegotiationRounds::KEY => 1],
            NegotiationRounds::increment(ServicingHandlerFixture::snapshot()),
        );
    }

    public function testTheCapIsTheNumberTheSpecArguedFor(): void
    {
        // Pinned on purpose: the value is a product decision with an argument
        // behind it (docs/superpowers/specs/2026-09-16-negotiation-round-cap-design.md),
        // not a number to tune casually.
        self::assertSame(15, NegotiationRounds::MAX);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter NegotiationRoundsTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\NegotiationRounds" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Servicing/NegotiationRounds.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * How many agent passes one quote has already bought, and the point at which a
 * human takes it over (#142).
 *
 * The discount a negotiation can concede is bounded — QuoteBaselineLines::anchor()
 * measures every round against the baseline the first pass stamped — but until
 * this existed nothing bounded what a negotiation could COST. Each new buyer
 * comment moves ServicingFingerprint, which is the only gate between a trigger
 * and the pipeline, so a buyer who keeps commenting keeps buying model calls.
 *
 * Counted as PASSES, not as rounds the agent answered. Everything past the
 * fingerprint gate buys at least the extract call, and a quote that escalates on
 * every pass answers nobody while paying for every attempt — a cap on answers
 * would never fire on exactly the quote that is spending.
 *
 * The key sits alongside merchant_quote_agent_serviced / _escalated / _attempts
 * / _baseline and is shallow-merged by the gateway, so it cannot collide with
 * them or with the A2CN act chain.
 *
 * DELIBERATELY NOT CLEARED by a successful pass, unlike
 * ServiceQuoteHandler::ATTEMPTS_KEY. That counter is a crash budget and
 * clearing it on a normal exit is what makes it one; clearing this one would
 * remove the bound entirely. The escape hatch is manual and rare by design:
 * clear `merchant_quote_agent_rounds` on the quote to hand it back to the agent.
 */
final class NegotiationRounds
{
    public const KEY = 'merchant_quote_agent_rounds';

    /**
     * Fifteen passes, which bounds one quote at roughly 45 model calls.
     *
     * Not configurable, and that is an argued position rather than an omission:
     * every optional number in config.xml reads blank as "no limit", a safety
     * control cannot, and #57 established that a new config.xml field reaches no
     * shop that already has the plugin without a migration. See the design doc
     * for the shape the field should take if a merchant ever earns it.
     */
    public const MAX = 15;

    private function __construct() {}

    public static function completed(QuoteSnapshot $snapshot): int
    {
        $stored = $snapshot->lifecycle->customFields[self::KEY] ?? null;

        // customFields is a JSON column. Anything that is not an int is not a
        // count, and the same coercion guards the crash budget next door.
        return \is_int($stored) ? $stored : 0;
    }

    public static function exhausted(QuoteSnapshot $snapshot): bool
    {
        return self::completed($snapshot) >= self::MAX;
    }

    /**
     * The customFields fragment for QuoteUpdate, spread-friendly so the caller
     * needs no conditional.
     *
     * @return array<string, int>
     */
    public static function increment(QuoteSnapshot $snapshot): array
    {
        return [self::KEY => self::completed($snapshot) + 1];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter NegotiationRoundsTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Format, lint, commit**

```bash
php vendor/bin/mago fmt src tests
/usr/bin/git add src/Servicing/NegotiationRounds.php tests/Unit/Servicing/NegotiationRoundsTest.php
/usr/bin/git commit -m "$(cat <<'EOF'
feat(servicing): count the passes one quote has bought (#142)

The counter only. Nothing writes it and nothing reads it yet.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: The escalation reason and its merchant-facing words

The enum case and the admin vocabulary, in one task because a reason with no
snippet shows the merchant a four-word fallback instead of a sentence — the half
of this feature they actually read.

**Files:**
- Modify: `src/Policy/Data/QuoteEscalationReason.php`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`
- Test: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `QuoteEscalationReason::RoundLimitExceeded`, whose `->value` is
  `'round_limit_exceeded'`. Tasks 4 and 5 use it.

- [ ] **Step 1: Write the failing test**

Append to `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`,
directly after the existing `quote_value_limit_exceeded` assertion block (search
for `// The value ceiling sentence needs the quote's value in its own currency.`
and place this after that block ends):

```javascript
// A quote that ran out of agent passes gets its own sentence, not the short
// label: the merchant has to be told that clearing the counter is what hands
// the quote back, and the four-word label cannot say that.
const capped = escalationExplanation(vm, {
    escalationReason: 'round_limit_exceeded',
    maxDiscountPercent: 5,
});

assert.ok(
    capped.includes('agent passes'),
    'A capped negotiation must compose its own sentence rather than falling back to the short label.',
);
```

- [ ] **Step 2: Run the check to verify it fails**

Run: `composer run quality:admin`
Expected: FAIL — the assertion message above, because `escalationExplanation()`
finds no `escalationWhy.round_limit_exceeded` snippet and falls back to the short
label (which is itself missing, so the label lookup returns the raw value).

- [ ] **Step 3: Add the enum case and the snippets**

In `src/Policy/Data/QuoteEscalationReason.php`, add the case after
`VerificationFailed`:

```php
    // Issue #142. The quote has had its full budget of agent passes. Not a
    // fault: the negotiation simply reached the end of what the agent is
    // authorised to spend on one quote, and a human takes it from here.
    case RoundLimitExceeded = 'round_limit_exceeded';
```

In `en.json`, add to the `escalation` map (after `verification_failed`):

```json
            "round_limit_exceeded": "Too many rounds"
```

and to the `escalationWhy` map (after `verification_failed`):

```json
            "round_limit_exceeded": "This negotiation reached the maximum number of agent passes, so it was handed to a person. Clear the quote's round counter to let the agent continue."
```

In `de.json`, add to the `escalation` map:

```json
            "round_limit_exceeded": "Zu viele Runden"
```

and to the `escalationWhy` map:

```json
            "round_limit_exceeded": "Diese Verhandlung hat die maximale Anzahl an Agentendurchläufen erreicht und wurde an einen Menschen übergeben. Setzen Sie den Rundenzähler des Angebots zurück, damit der Agent weitermacht."
```

Remember the comma on the line before each insertion — both files are JSON and a
missing comma fails `composer run quality:admin` with a parse error rather than
an assertion.

- [ ] **Step 4: Run the check to verify it passes**

Run: `composer run quality:admin`
Expected: PASS, no output beyond the checks' own.

- [ ] **Step 5: Commit**

```bash
/usr/bin/git add src/Policy/Data/QuoteEscalationReason.php src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs
/usr/bin/git commit -m "$(cat <<'EOF'
feat(policy): give a capped negotiation its own escalation reason (#142)

Nothing raises it yet. The admin sentence ships with the case so the
merchant is never shown the four-word label for it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Increment the counter with the crash budget

**Files:**
- Modify: `src/Servicing/ServiceQuoteHandler.php` (`claimAttempt()`, around line 238)
- Test: `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`

**Interfaces:**
- Consumes: `NegotiationRounds::KEY` and `NegotiationRounds::increment()` from Task 1.
- Produces: every pass that reaches the pipeline has already committed its
  incremented counter. Task 4 reads it from the pass-start snapshot, which is the
  one taken *before* this write.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`, and add
`use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;` to its imports:

```php
    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testEachPassRaisesTheRoundCounterBeforeTheHandOff(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => 3,
        ])]);

        ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline())(
            ServicingHandlerFixture::message(),
        );

        // The claim write, not the stamp: committed before the pipeline runs,
        // so a pass that kills the worker still counts against the budget.
        self::assertSame(4, $gateway->customFieldWrites[0][NegotiationRounds::KEY] ?? null);
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testASuccessfulPassDoesNotClearTheRoundCounter(): void
    {
        // The counter sits in the same write as ATTEMPTS_KEY, which IS cleared
        // on a normal exit because it is a crash budget. Clearing this one by
        // symmetry would remove the spend bound entirely and leave every test
        // above still passing.
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => 3,
        ])]);

        ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline())(
            ServicingHandlerFixture::message(),
        );

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayNotHasKey(NegotiationRounds::KEY, $stamp);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit --filter ServiceQuoteHandlerTest`
Expected: the first new test FAILS with `Failed asserting that null is identical
to 4`. The second PASSES already (nothing writes the key yet) — that is expected
and fine; it is a regression guard, not a driver.

- [ ] **Step 3: Write the implementation**

In `src/Servicing/ServiceQuoteHandler.php`, in `claimAttempt()`, extend the
existing `updateQuote()` call:

```php
        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            self::ATTEMPTS_KEY => $attempts + 1,
            ...QuoteBaseline::stampOrExtend($snapshot),
            // #142: the spend bound rides the write that already exists, and
            // inherits its ordering — committed BEFORE the pipeline, so a pass
            // that dies mid-flight still counts against the budget it spent.
            // Never cleared afterwards: see NegotiationRounds.
            ...NegotiationRounds::increment($snapshot),
        ]));
```

No import is needed: `NegotiationRounds` is in the same namespace as
`ServiceQuoteHandler`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter ServiceQuoteHandler`
Expected: PASS, the whole `ServiceQuoteHandler*Test` family green.

- [ ] **Step 5: Format, lint, commit**

```bash
php vendor/bin/mago fmt src tests
/usr/bin/git add src/Servicing/ServiceQuoteHandler.php tests/Unit/Servicing/ServiceQuoteHandlerTest.php
/usr/bin/git commit -m "$(cat <<'EOF'
feat(servicing): raise the round counter with the crash budget (#142)

One spread into the write claimAttempt() already makes, so the counter
inherits its ordering: committed before the pipeline runs. Nothing reads
it yet.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Refuse a pass past the cap, before the model call

**Files:**
- Modify: `src/Negotiation/NegotiationPipeline.php` (`negotiate()`, from line 136)
- Test: `tests/Unit/Negotiation/NegotiationPipelineTest.php`

**Interfaces:**
- Consumes: `NegotiationRounds::exhausted()` and `::completed()` (Task 1),
  `QuoteEscalationReason::RoundLimitExceeded` (Task 2).
- Produces: `NegotiationOutcome::Escalated` with
  `escalationReason = round_limit_exceeded` on the audit draft, and a
  `merchant_quote_agent_escalated` marker holding `'round_limit_exceeded'`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Negotiation/NegotiationPipelineTest.php`, adding
`use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;` to its imports:

```php
    public function testAQuotePastItsRoundCapEscalatesWithoutPayingForAModelCall(): void
    {
        // The whole point of the cap: a buyer who keeps commenting stops
        // buying model calls. The script is deliberately non-empty — if the
        // pipeline reaches the interpreter at all, it will consume an entry
        // and the call count will not be zero.
        $harness = PipelineHarness::with(['{"price":{"additionalDiscountPercent":5}}']);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment('and now 5%?', '2026-08-28 09:00:00'),
            ]),
            [NegotiationRounds::KEY => NegotiationRounds::MAX],
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(0, $harness->spy->calls, 'A capped quote must not reach the model at all.');
        self::assertSame(
            [[QuoteEscalator::MARKER_KEY => QuoteEscalationReason::RoundLimitExceeded->value]],
            $harness->gateway->customFieldWrites,
            'The cap must escalate with its own reason, not a generic one.',
        );
        self::assertSame(
            QuoteEscalationReason::RoundLimitExceeded->value,
            $harness->writer->drafts[0]->escalationReason,
            'The refusal must reach the audit trail: it is what #21 counts.',
        );
    }

    public function testTheLastPassInsideTheCapStillNegotiates(): void
    {
        // The boundary from the other side. MAX - 1 completed passes means
        // fourteen have run, so this one is the fifteenth and is allowed.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off, valid until 2026-09-11.","terms":{"discountPercent":5}}',
            'We can offer 5% off. Valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
            ]),
            [NegotiationRounds::KEY => NegotiationRounds::MAX - 1],
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(3, $harness->spy->calls);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit --filter NegotiationPipelineTest`
Expected: the first new test FAILS (`Offered` returned, and the spy reports
calls); the second PASSES already, and is the guard against an off-by-one in
Step 3.

- [ ] **Step 3: Write the implementation**

In `src/Negotiation/NegotiationPipeline.php`, make this the first statement of
`negotiate()`, above the `$ask = $this->interpreter->interpret(...)` line:

```php
        // #142, and BEFORE the extract call on purpose: past the cap a pass
        // costs one snapshot read, one customFields write and one audit row —
        // no model call at all. The snapshot is the one ServiceQuoteHandler
        // fetched before claimAttempt() wrote to it, so this count is the
        // passes that finished BEFORE this one: `>= MAX` lets passes one
        // through MAX run and refuses the next.
        //
        // Here rather than in ServicingPreflight because this is the same
        // question AskGate asks — is this ask inside the mandate — and the
        // answer has to reach the audit trail, which the preflight's refusals
        // do not (#35).
        if (NegotiationRounds::exhausted($snapshot)) {
            $this->logger->info('This quote has had its full budget of agent passes; a human takes it from here. '
            . 'Clear the "{key}" custom field on the quote to hand it back to the agent.', [
                'key' => NegotiationRounds::KEY,
                'quoteId' => $snapshot->identity->quoteId,
                'rounds' => NegotiationRounds::completed($snapshot),
            ]);

            return $this->round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::RoundLimitExceeded,
                null,
                null,
            );
        }
```

`QuoteEscalationReason` is already imported in this file. Add
`use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;` to the imports.

If mago's cyclomatic-complexity lint complains about `negotiate()` after this,
do **not** restructure the method: extract the guard into a `private function
roundCapReached(...)` returning `?NegotiationPass` in the same class, keeping
every comment above with it, and call it from the same position.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter NegotiationPipelineTest`
Expected: PASS.

Then run the whole unit suite, because this file is on every negotiation's path:

Run: `composer run test`
Expected: PASS.

- [ ] **Step 5: Format, lint, commit**

```bash
php vendor/bin/mago fmt src tests
php vendor/bin/mago lint src tests
/usr/bin/git add src/Negotiation/NegotiationPipeline.php tests/Unit/Negotiation/NegotiationPipelineTest.php
/usr/bin/git commit -m "$(cat <<'EOF'
feat(negotiation): hand a quote to a human after fifteen passes (#142)

Checked before the extract call, so a refused round costs no model call,
and from inside the pipeline, so the escalation still gets its audit row.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The cap against a real quote

The unit tests prove the arithmetic and the wiring. This proves the counter
survives a real `customFields` round trip through `QuoteWriter`'s shallow merge
and that the handler and the cap agree about which pass is which — the two things
a fake gateway cannot show.

**Files:**
- Create: `tests/Integration/ServicingRoundCapTest.php`
- Read first: `tests/Integration/ServicingCrashBudgetTest.php`, which drives the
  other counter exactly this way. `static::gateway()` and `static::preflight()`
  come from `IntegrationTestCase`; `QuoteFixture::anyQuoteId()` from
  `tests/Integration/QuoteFixture.php`. `locks()` and `countingPipeline()` are
  **private to that test class**, so the new file needs its own copies — they are
  in the code below.

**Interfaces:**
- Consumes: everything from Tasks 1, 3 and 4.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/ServicingRoundCapTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The spend bound against a real quote (#142). The counter has to survive
 * QuoteWriter's shallow merge alongside the crash budget and the baseline, and
 * the handler's claim write has to be the thing the cap later reads.
 */
final class ServicingRoundCapTest extends IntegrationTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', 'sk-integration');
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmModel', 'gpt-4o-mini');
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'validityDays', 14);
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testEachPassRaisesTheStoredCounter(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            NegotiationRounds::KEY => 3,
        ]));

        $handler = new ServiceQuoteHandler(
            self::locks(),
            new NullLogger(),
            static::preflight(),
            $gateway,
            self::countingPipeline(),
        );
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        $customFields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;
        self::assertSame(
            4,
            $customFields[NegotiationRounds::KEY] ?? null,
            'The round counter did not survive the pass, so the spend bound never accumulates.',
        );
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testTheCounterOutlivesASuccessfulPass(): void
    {
        // The regression this test exists for: ATTEMPTS_KEY is cleared in the
        // same stamp write, and a counter cleared on success bounds nothing.
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $handler = new ServiceQuoteHandler(
            self::locks(),
            new NullLogger(),
            static::preflight(),
            $gateway,
            self::countingPipeline(),
        );
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        $customFields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;
        self::assertNull(
            $customFields[ServiceQuoteHandler::ATTEMPTS_KEY] ?? null,
            'The crash budget must still clear on a normal exit.',
        );
        self::assertSame(
            1,
            $customFields[NegotiationRounds::KEY] ?? null,
            'The round counter must NOT clear on a normal exit; it is a spend bound, not a crash budget.',
        );
    }

    private static function locks(): QuoteServicingLock
    {
        return new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
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
                PassContext $context,
            ): NegotiationOutcome {
                ++$this->passes;

                return NegotiationOutcome::Offered;
            }
        };
    }
}
```

The two private helpers bring these imports with them, on top of the ones in the
file header above:

```php
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
```

`countingPipeline()` is the third copy of that anonymous class in
`tests/Integration/` (`ServicingCrashBudgetTest` and `ServicingConfigGateTest`
have the other two, both private). If `composer run quality:dupes` flags it, do
not build a shared fixture for it — drop `testEachPassRaisesTheStoredCounter`,
keep `testTheCounterOutlivesASuccessfulPass` (which is the regression that
matters) and inline the pipeline into that one method.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test:integration -- --filter ServicingRoundCapTest`

If that argument form is rejected, run `composer run test:integration` and read
the script at `scripts/test-integration.sh` for how it passes arguments through.

Expected before Tasks 1–4 are in place: FAIL. After them: PASS — write it anyway
and confirm it passes, since the point is the database round trip rather than a
red-to-green transition.

- [ ] **Step 3: Run the full integration suite**

Run: `composer run test:integration`
Expected: PASS. Other agents are running the suite against the same container
concurrently, so re-run once before treating any failure as real, and check
whether the failure names a file this branch touched.

- [ ] **Step 4: Commit**

```bash
php vendor/bin/mago fmt src tests
/usr/bin/git add tests/Integration/ServicingRoundCapTest.php
/usr/bin/git commit -m "$(cat <<'EOF'
test(servicing): drive the round cap against a real quote (#142)

Proves the counter survives QuoteWriter's shallow merge and that it
outlives a successful pass, which the crash budget deliberately does not.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Gates

**Files:** none.

- [ ] **Step 1: Unit suite**

Run: `composer run test`
Expected: PASS, no skipped tests that were passing on the base commit.

- [ ] **Step 2: Quality gate**

Run: `composer run quality`
Expected: PASS. This runs format:check, lint, mago analyze, the file-length
check, the three admin `.mjs` checks, jscpd, the dependency analyser and
`composer audit`.

- [ ] **Step 3: Integration suite**

Run: `composer run test:integration`
Expected: PASS. Re-run once before concluding a failure is real.

- [ ] **Step 4: Confirm the base commit agrees**

If any gate fails in a file this branch did not touch, check out the base commit
detached and run the same gate there:

```bash
/usr/bin/git checkout --detach main
composer run test
/usr/bin/git checkout feat/142-round-cap-and-spend-ceiling
```

Do **not** use `git stash` — the stash stack is shared with every other worktree
in this repository.
