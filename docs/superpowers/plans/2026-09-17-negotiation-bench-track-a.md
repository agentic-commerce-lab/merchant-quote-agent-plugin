# Track A: the synthetic negotiation bench — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run `scenario × strategy × model` negotiations against a live shop with real model calls, leaving real decision records behind, so negotiation strategies and negotiating models can be compared on the KPIs the system already has.

**Architecture:** The real `NegotiationPipeline` over the real DAL-backed gateway, with only two substitutions against the existing `PipelineFixture` wiring: a real `ModelPlatform` instead of the scripted client, and per-cell `QuoteAgentSettings` carrying the strategy and model under test. A synthetic buyer drives rounds by writing real buyer comments. The pipeline is called directly rather than dispatched through Messenger.

**Tech Stack:** PHP 8.3, PHPUnit 11.5 via `scripts/test-integration.sh`, Shopware 6.7 DAL, `node --experimental-strip-types` for the scorer.

**Spec:** `docs/superpowers/specs/2026-09-17-negotiation-bench-design.md`

## Global Constraints

- **Do not change the negotiation engine.** Not one file under `src/Negotiation/` or `src/Policy/`. If the bench appears to require an engine change, stop and report it as a finding. The bench measures the engine; it does not adjust it.
- **Never edit `measures.ts`.** Track B owns it and is running in parallel. Import it read-only. In particular, do **not** define a token-per-negotiation function here — Track B adds `tokensPerNegotiation` to `measures.ts`, and two definitions of one measure is the drift the shared contract exists to prevent. If the figure is needed before Track B lands, wait.
- **Never touch anything under `src/Resources/app/administration/`.** That is entirely Track B's.
- **`maxRounds` is mandatory.** Issue #142's round cap **never landed** — PR #148 was closed unmerged and `grep roundCap src/` on `main` finds nothing. There is no plugin-side cap underneath this loop. A scenario whose buyer never accepts would otherwise run until the OpenRouter account limit stops it.
- **Real model calls cost real money.** Every bench entry point is env-gated and skips by default, the way `LiveModelSmokeTest` and `LiveHistoryMessageTest` already are. Nothing here may ever gate CI.
- **The target shop is a parameter, not a constant.** `scripts/test-integration.sh` already takes `SHOP_SSH` (user@host) and `SHOP_PATH` (an absolute docroot — `~` will not expand). Two candidates are documented in Task 9; the plan hardcodes neither.
- Run `composer run quality` before every commit.

---

### Task 1: A test case whose writes survive

**Files:**
- Create: `tests/Integration/Bench/BenchTestCase.php`
- Modify: `tests/Integration/IntegrationTestCase.php`
- Create: `tests/Integration/Bench/BenchPersistenceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `BenchTestCase`, an abstract class exposing the same static accessors `IntegrationTestCase` does — `gateway(): QuoteGatewayInterface`, `buyerGateway(): BuyerQuoteGatewayInterface`, `connection(ContainerInterface): Connection`, `repository(ContainerInterface, string): EntityRepository` — but **without** rolling its writes back. Every later task extends it.

**The problem this task exists to solve.** `IntegrationTestCase` uses `DatabaseTransactionBehaviour`; its own docblock says "is rolled back, so tests may write freely to the live shop's database". That is right for a test and fatal for a bench: every decision record the bench produces would vanish when the run ends, and the entire premise — that Track B's admin table displays Track A's output — would be false. `LiveModelSmokeTest` extends plain `TestCase` for a related reason and is the precedent for not inheriting that trait.

**Why a trait rather than a copy.** Copying the four accessors into a second base class would duplicate them, and `composer run quality:dupes` runs jscpd over this tree. Extract them once; both base classes use them.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Bench/BenchPersistenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

/**
 * The one property the whole bench rests on: what it writes is still there
 * afterwards.
 *
 * IntegrationTestCase rolls every write back by design. A bench built on it
 * would produce no decision records at all, and the failure would be silent —
 * the run would pass, the admin would show nothing, and the obvious conclusion
 * would be that the grouping is broken rather than that the rows were never
 * committed.
 */
final class BenchPersistenceTest extends BenchTestCase
{
    public function testAWriteSurvivesTheTestThatMadeIt(): void
    {
        $connection = self::connection(static::getContainer());
        $marker = 'bench-persistence-' . bin2hex(random_bytes(8));

        $connection->executeStatement(
            'INSERT INTO merchant_quote_agent_decision (id, quote_id, error_class, created_at)
             VALUES (UNHEX(:id), UNHEX(:quote), :marker, NOW())',
            [
                'id' => bin2hex(random_bytes(16)),
                'quote' => bin2hex(random_bytes(16)),
                'marker' => $marker,
            ],
        );

        // A fresh connection, so this cannot be read out of an open transaction.
        $found = self::freshConnection()->fetchOne(
            'SELECT COUNT(*) FROM merchant_quote_agent_decision WHERE error_class = :marker',
            ['marker' => $marker],
        );

        self::assertSame(1, (int) $found, 'The bench must leave its rows behind.');

        self::freshConnection()->executeStatement(
            'DELETE FROM merchant_quote_agent_decision WHERE error_class = :marker',
            ['marker' => $marker],
        );
    }
}
```

Implement `freshConnection()` on `BenchTestCase` by building a second DBAL connection from the same parameters as the container's, so the read genuinely cannot see an uncommitted transaction.

- [ ] **Step 2: Run to verify it fails**

```bash
composer run test:integration -- --filter BenchPersistenceTest
```

Expected: fails, because `BenchTestCase` does not exist yet.

- [ ] **Step 3: Extract the accessors into a trait**

Read `tests/Integration/IntegrationTestCase.php` first — the accessors are `requireUcpSurface()`, `commercialService()`, `repository()`, `connection()`, `gateway()`, `buyerGateway()`, `preflight()`, `gatewayFactory()`. Move them into `tests/Integration/ShopServices.php` as a trait, with their docblocks intact. Have `IntegrationTestCase` `use ShopServices;` alongside `KernelTestBehaviour` and `DatabaseTransactionBehaviour`.

Change nothing about the accessors' behaviour. This step must leave the existing suite green.

- [ ] **Step 4: Run the existing suite to prove the extraction was neutral**

```bash
composer run test:integration
```

Expected: the same results as before the extraction. If anything newly fails, the extraction is wrong — fix it before continuing rather than carrying a change in shared test infrastructure forward.

- [ ] **Step 5: Add `BenchTestCase`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

use MerchantQuoteAgentPlugin\Tests\Integration\ShopServices;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * A live-shop test case whose writes COMMIT.
 *
 * Deliberately does not use DatabaseTransactionBehaviour, which every other
 * integration test here does use. The bench's whole output is the decision
 * records it leaves behind for the admin to group; rolling them back would
 * make the run pass and produce nothing.
 *
 * The cost of that decision is that a bench run is not self-cleaning. That is
 * accepted: the spec chose not to isolate bench data, because the target is a
 * test shop.
 */
abstract class BenchTestCase extends TestCase
{
    use KernelTestBehaviour;
    use ShopServices;

    // freshConnection() implemented here.
}
```

- [ ] **Step 6: Run to verify it passes**

```bash
composer run test:integration -- --filter BenchPersistenceTest
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/Integration/
git commit -m "test(bench): a live-shop test case whose writes commit

IntegrationTestCase rolls every write back by design, which is right for a
test and fatal for a bench: the decision records the bench exists to
produce would vanish, silently, leaving a passing run and an empty admin.

Accessors extracted to a ShopServices trait so both base classes share
one copy rather than tripping the duplication gate.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: The scenario format

**Files:**
- Create: `src/../tests/Integration/Bench/Scenario.php`
- Create: `tests/Integration/Bench/scenarios/` (directory for the JSON files)
- Test: `tests/Unit/Bench/ScenarioTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Scenario::fromArray(array $data): self` and `Scenario::load(string $path): self`
  - Public readonly properties: `string $id`, `string $description`, `list<array{productRef: string, quantity: int}> $lines`, `string $openingAsk`, `string $persona`, `int $maxRounds`, `?string $expectedBand`
  - `Scenario::all(string $directory): list<self>`

**Why this format is a deliverable and not an implementation detail.** #22 requires that the nightly replay loop consume the same scenarios, and #21 requires the format be agreed with Juan's buyer agent. It is designed once, here, and changing it later costs both.

`maxRounds` is required with no default. There is no plugin-side round cap (see Global Constraints), so a scenario that omitted it would be unbounded.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Bench/ScenarioTest.php`. It is a unit test — no shop, no container.

```php
public function testARoundCapIsMandatoryBecauseNothingElseBoundsTheLoop(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/maxRounds/');

    Scenario::fromArray([
        'id' => 'plain-percentage',
        'description' => 'A five percent ask inside the band.',
        'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
        'openingAsk' => 'Could you do 5% off?',
        'persona' => 'scripted:moderate',
    ]);
}

public function testAScenarioRoundTripsThroughItsArrayForm(): void
{
    $scenario = Scenario::fromArray([
        'id' => 'plain-percentage',
        'description' => 'A five percent ask inside the band.',
        'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
        'openingAsk' => 'Could you do 5% off?',
        'persona' => 'scripted:moderate',
        'maxRounds' => 6,
        'expectedBand' => 'auto',
    ]);

    self::assertSame('plain-percentage', $scenario->id);
    self::assertSame(6, $scenario->maxRounds);
    self::assertSame('auto', $scenario->expectedBand);
    self::assertSame(3, $scenario->lines[0]['quantity']);
}

public function testAnAbsentExpectedBandIsNullRatherThanAGuess(): void
{
    // Not every scenario asserts an outcome; some exist to observe one.
    $scenario = Scenario::fromArray([... 'maxRounds' => 4]);
    self::assertNull($scenario->expectedBand);
}

public function testARoundCapOfZeroIsRefused(): void
{
    // A scenario that cannot take a single round is a typo, not a
    // configuration — it would report "no offer" for every strategy and
    // look like a finding.
    $this->expectException(\InvalidArgumentException::class);
    Scenario::fromArray([... 'maxRounds' => 0]);
}
```

Fill the `...` with the valid payload from the round-trip test — repeated in full, not elided, when you write the file.

- [ ] **Step 2: Run to verify it fails**

```bash
composer run test -- --filter ScenarioTest
```

Expected: FAIL, class not found.

- [ ] **Step 3: Implement `Scenario`**

A `final readonly class` with a private constructor and the two named constructors. Validate in `fromArray()`: `id` non-empty, `lines` non-empty, `maxRounds` present and `>= 1`. Throw `\InvalidArgumentException` naming the offending key — the message is read by whoever wrote the JSON, so it must say which field and which file.

`productRef` is a symbolic name the runner resolves against the shop, not a UUID. Scenarios must be portable between shops; a hardcoded product id is not.

- [ ] **Step 4: Run to verify it passes**

```bash
composer run test -- --filter ScenarioTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Bench/ tests/Integration/Bench/
git commit -m "feat(bench): the scenario format, shared with #21 and #22

maxRounds is required with no default: #142's round cap never landed, so
the bench's own bound is the only thing terminating a non-converging
negotiation. productRef is symbolic so scenarios port between shops.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: The synthetic buyer interface and the scripted buyer

**Files:**
- Create: `tests/Integration/Bench/SyntheticBuyer.php`
- Create: `tests/Integration/Bench/BuyerMove.php`
- Create: `tests/Integration/Bench/ScriptedBuyer.php`
- Test: `tests/Unit/Bench/ScriptedBuyerTest.php`

**Interfaces:**
- Consumes: `QuoteSnapshot` from `MerchantQuoteAgentPlugin\Bridge\Data`.
- Produces:
  - `enum BuyerMoveKind: string { case Accept = 'accept'; case Counter = 'counter'; case Walk = 'walk'; }`
  - `final readonly class BuyerMove { public BuyerMoveKind $kind; public ?string $comment; }` with named constructors `BuyerMove::accept()`, `BuyerMove::walk()`, `BuyerMove::counter(string $comment)`.
  - `interface SyntheticBuyer { public function respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply, int $round): BuyerMove; }`
  - `ScriptedBuyer::__construct(float $targetDiscountPercent, float $concessionRatio, int $patience)`

**Why scripted buyers exist at all.** They cost nothing and are deterministic, so a scenario can be asserted to still reach the band it used to reach with no provider in the loop. That makes them the regression gate; the LLM buyer in Task 4 is for the benchmark itself.

`ScriptedBuyer` is pure arithmetic over the snapshots, so it is unit-tested with no shop.

- [ ] **Step 1: Write the failing test**

```php
public function testItAcceptsOnceTheOfferReachesItsTarget(): void
{
    $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 4);

    $move = $buyer->respond(
        BenchFixture::snapshot(totalNet: 1000.0),
        BenchFixture::snapshot(totalNet: 890.0),
        'We can offer 11% off.',
        round: 1,
    );

    self::assertSame(BuyerMoveKind::Accept, $move->kind);
}

public function testItCountersAcrossTheRemainingGapWhenTheOfferFallsShort(): void
{
    $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 4);

    $move = $buyer->respond(
        BenchFixture::snapshot(totalNet: 1000.0),
        BenchFixture::snapshot(totalNet: 960.0),
        'We can offer 4% off.',
        round: 1,
    );

    self::assertSame(BuyerMoveKind::Counter, $move->kind);
    self::assertNotNull($move->comment);
}

public function testItWalksOnceItsPatienceIsSpent(): void
{
    // Without this, a scenario whose agent never concedes enough would run to
    // maxRounds every time and the round-count measure would report the cap
    // rather than the negotiation.
    $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 2);

    self::assertSame(
        BuyerMoveKind::Walk,
        $buyer->respond(
            BenchFixture::snapshot(totalNet: 1000.0),
            BenchFixture::snapshot(totalNet: 995.0),
            'We cannot move further.',
            round: 3,
        )->kind,
    );
}

public function testAnUnchangedTotalIsNotReadAsAConcession(): void
{
    // An escalated or clarified pass leaves the price alone. Treating that as
    // a 0% concession and countering against it would measure the agent's
    // silence as a negotiating position.
    $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 4);

    $move = $buyer->respond(
        BenchFixture::snapshot(totalNet: 1000.0),
        BenchFixture::snapshot(totalNet: 1000.0),
        'A colleague will come back to you.',
        round: 1,
    );

    self::assertSame(BuyerMoveKind::Counter, $move->kind);
}
```

`BenchFixture::snapshot()` is a thin unit-level snapshot builder — model it on the existing `tests/Unit/Negotiation/NegotiationFixture.php`, which already builds `QuoteSnapshot`s with a given `totalNet`. Reuse `NegotiationFixture` directly if its visibility allows; add `BenchFixture` only if it does not.

- [ ] **Step 2: Run to verify it fails**

```bash
composer run test -- --filter ScriptedBuyerTest
```

- [ ] **Step 3: Implement**

The rule: compute the realized discount as `(before.totals.totalNet - after.totals.totalNet) / before.totals.totalNet * 100`. Accept if it meets or beats `targetDiscountPercent`. Walk if `round > patience`. Otherwise counter at `realized + (target - realized) * concessionRatio`, phrased as a buyer would phrase it.

Anchor every round on the **scenario's opening** total, never on the previous round's reduced one — the engine anchors the same way in `QuoteBaselineLines::anchor()`, and a buyer measuring against the reduced price would drift apart from what the agent is checking.

- [ ] **Step 4: Run to verify it passes**

```bash
composer run test -- --filter ScriptedBuyerTest
```

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Bench/ tests/Integration/Bench/
git commit -m "feat(bench): synthetic buyer interface and a scripted buyer

Deterministic and free, so a scenario can be asserted to still reach its
band with no provider in the loop. Anchors on the opening total the way
QuoteBaselineLines does, and does not read an unchanged price as a
concession — an escalated pass leaves the total alone.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: The LLM buyer

**Files:**
- Create: `tests/Integration/Bench/LlmBuyer.php`
- Create: `tests/Integration/Bench/BuyerAnswer.php`
- Test: `tests/Unit/Bench/LlmBuyerTest.php`

**Interfaces:**
- Consumes: `SyntheticBuyer`, `BuyerMove` (Task 3); `ModelPlatform`, `ModelAccess` from `src/`.
- Produces: `LlmBuyer::__construct(ModelPlatform $platform, ModelAccess $access, string $persona)`.

Use `ModelPlatform::object($access, $system, $user, BuyerAnswer::class)`, which generates a JSON schema from the DTO and maps the answer onto it — the same mechanism the agent side uses for `CommentInterpretation`. `BuyerAnswer` carries the move kind and the comment text.

The unit test scripts the HTTP layer with `tests/Unit/Negotiation/ScriptedClient.php` — `ScriptedClient::returning([...])` hands back a real `ModelPlatform` whose transport is mocked, so this test costs nothing and needs no key.

- [ ] **Step 1: Write the failing test**

```php
public function testItMapsTheModelsAnswerOntoABuyerMove(): void
{
    $platform = ScriptedClient::returning([
        '{"kind":"counter","comment":"That is still above what I can approve. Can you reach 12%?"}',
    ]);

    $move = (new LlmBuyer($platform, new ModelAccess('sk-test', 'https://api.example.com/v1', 'test-model'), 'A hard bargainer.'))
        ->respond(BenchFixture::snapshot(totalNet: 1000.0), BenchFixture::snapshot(totalNet: 950.0), 'We can do 5%.', round: 1);

    self::assertSame(BuyerMoveKind::Counter, $move->kind);
    self::assertStringContainsString('12%', (string) $move->comment);
}

public function testAnAnswerWithoutACommentCannotCounter(): void
{
    // A counter with nothing to say would write an empty buyer comment, which
    // the interpreter reads as no ask at all — the pass would record
    // nothing_to_do and the round would be silently lost.
    $platform = ScriptedClient::returning(['{"kind":"counter","comment":""}']);

    $this->expectException(\RuntimeException::class);

    (new LlmBuyer($platform, new ModelAccess('sk-test', 'https://api.example.com/v1', 'test-model'), 'A hard bargainer.'))
        ->respond(BenchFixture::snapshot(totalNet: 1000.0), BenchFixture::snapshot(totalNet: 950.0), 'We can do 5%.', round: 1);
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
composer run test -- --filter LlmBuyerTest
```

- [ ] **Step 3: Implement**

The persona is the system prompt; the user prompt carries the opening total, the current total, the agent's reply and the round number. State in the prompt that the buyer must not reveal it is synthetic — a buyer that announces itself changes what the agent replies.

Let `ModelUnavailable` propagate. A buyer that cannot answer is a failed cell, and swallowing it would record a walk the buyer never chose.

- [ ] **Step 4: Run to verify it passes**

```bash
composer run test -- --filter LlmBuyerTest
```

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Bench/ tests/Integration/Bench/
git commit -m "feat(bench): an LLM synthetic buyer behind the same interface

Answers under a generated schema through ModelPlatform::object, the way
the agent side reads a comment. Refuses an empty counter: an empty buyer
comment reads as no ask, and the round would be silently lost.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: The negotiation loop

**Files:**
- Create: `tests/Integration/Bench/BenchNegotiation.php`
- Create: `tests/Integration/Bench/NegotiationResult.php`
- Test: `tests/Integration/Bench/BenchNegotiationTest.php`

**Interfaces:**
- Consumes: `Scenario` (Task 2), `SyntheticBuyer`/`BuyerMove` (Task 3), `BenchTestCase` (Task 1), and `PipelineFixture`'s `writeBuyerComment()` / `agentCommentsAdded()` / `pipelineWithSpy()`.
- Produces: `BenchNegotiation::run(Scenario, SyntheticBuyer, QuoteAgentSettings, string $runId): NegotiationResult`, where `NegotiationResult` carries `quoteId`, `rounds`, the terminal `BuyerMoveKind|null`, and the final `NegotiationOutcome`.

**Wiring.** Copy `PipelineFixture::pipelineWithSpy()`'s resolution of `PromptComposer`, `OfferAuthorizer`, `OfferVerifier`, `NegotiationDecider`, `QuoteEscalator`, `DecisionRecorder` and `CustomerHistoryFactoryInterface` from the container, then build `OfferRound` and `NegotiationPipeline` exactly as it does — but pass a real `ModelPlatform` where it passes `ScriptedClient::spy(...)`. `PipelineFixture` is a trait, so `BenchTestCase` can `use` it.

**Quote creation.** `self::buyerGateway()->requestQuote($context, [['product_id' => $id, 'quantity' => $n]], null)` with a context from `BuyerQuoteContextFixture::contextForCustomer()`, exactly as `HistoryInjectionFixture::freshHistoryQuote()` does. Add `AgentContext::STATE` and `Context::SKIP_TRIGGER_FLOW` to the context, or the servicing trigger will queue passes behind the bench's own.

**The loop.**

```
resolve scenario products → requestQuote → writeBuyerComment(openingAsk)
repeat, round = 1..maxRounds:
    before = gateway->fetchSnapshot(quoteId)
    outcome = pipeline->service(before, gateway, settings, passContext)
    after = gateway->fetchSnapshot(quoteId)
    reply = last of agentCommentsAdded(before, after)   // '' if the pass said nothing
    move = buyer->respond(before, after, reply, round)
    accept | walk → stop
    counter        → writeBuyerComment(move->comment)
```

Stop on `NegotiationOutcome::Escalated` too — an escalated quote is waiting for a human, and continuing to comment at it measures nothing.

- [ ] **Step 1: Write the failing test**

Use a **scripted** model, not a real one, so this test is free and can run unattended:

```php
public function testARefusingBuyerStopsAtTheRoundCapRatherThanRunningOn(): void
{
    // There is no plugin-side round cap on main — #142's PR was closed
    // unmerged. If this loop does not bound itself, nothing does.
    $scenario = Scenario::fromArray([... 'maxRounds' => 3]);

    $result = BenchNegotiation::run($scenario, new AlwaysCountersBuyer(), self::benchSettings(), 'test-run');

    self::assertSame(3, $result->rounds);
}

public function testAnAcceptingBuyerEndsTheNegotiationEarly(): void
{
    $scenario = Scenario::fromArray([... 'maxRounds' => 8]);

    $result = BenchNegotiation::run($scenario, new AcceptsImmediatelyBuyer(), self::benchSettings(), 'test-run');

    self::assertSame(1, $result->rounds);
    self::assertSame(BuyerMoveKind::Accept, $result->terminal);
}

public function testEachRoundLeavesADecisionRecordBehind(): void
{
    $scenario = Scenario::fromArray([... 'maxRounds' => 2]);
    $result = BenchNegotiation::run($scenario, new AlwaysCountersBuyer(), self::benchSettings(), 'test-run');

    self::assertSame(
        $result->rounds,
        (int) self::connection(static::getContainer())->fetchOne(
            'SELECT COUNT(*) FROM merchant_quote_agent_decision WHERE quote_id = UNHEX(:quote)',
            ['quote' => $result->quoteId],
        ),
    );
}
```

`AlwaysCountersBuyer` and `AcceptsImmediatelyBuyer` are two-line `SyntheticBuyer` implementations in the test file.

Every `[... 'maxRounds' => N]` above stands for this payload, written out in
full at each call site — do not elide it in the file you write, and do not
extract it to a helper that hides which field each test is varying:

```php
Scenario::fromArray([
    'id' => 'plain-percentage',
    'description' => 'A five percent ask inside the band.',
    'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
    'openingAsk' => 'Could you do 5% off?',
    'persona' => 'scripted:moderate',
    'maxRounds' => 3,
])
```

- [ ] **Step 2: Run to verify it fails**

```bash
composer run test:integration -- --filter BenchNegotiationTest
```

- [ ] **Step 3: Implement**

Accept a `ModelPlatform` as a constructor argument rather than building one, so the test above can pass a scripted one and the matrix driver can pass a real one.

- [ ] **Step 4: Run to verify it passes**

```bash
composer run test:integration -- --filter BenchNegotiationTest
```

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/Bench/
git commit -m "feat(bench): drive a negotiation to its end over real quotes

Real gateway, real authorizer and verifier, real decision records; only
the model and the buyer are substituted. Bounded by the scenario's own
maxRounds, because no plugin-side cap exists on main. Stops on escalation
rather than commenting at a quote that is waiting for a human.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: The matrix driver

**Files:**
- Create: `tests/Integration/Bench/BenchRunTest.php`
- Create: `tests/Integration/Bench/RunWriter.php`

**Interfaces:**
- Consumes: everything above.
- Produces: a JSONL file per run, and real decision records on the shop.

**Env-gated, like every expensive live test here.** Skip unless `QUOTE_AGENT_BENCH_KEY` and `QUOTE_AGENT_BENCH_MODELS` are set. Follow `LiveModelSmokeTest`'s docblock convention: state in the docblock what it costs and how to run it.

- [ ] **Step 1: Write the entry point**

```php
/**
 * The bench. Opt-in: it makes real model calls against a live shop and leaves
 * its decision records behind. Never gate CI on this.
 *
 *   QUOTE_AGENT_BENCH_KEY=sk-... \
 *   QUOTE_AGENT_BENCH_MODELS=google/gemini-3.7-flash,openai/gpt-5-mini \
 *   QUOTE_AGENT_BENCH_BUYER=scripted \
 *   SHOP_SSH=user@host SHOP_PATH=/abs/docroot \
 *     composer run test:integration -- --filter BenchRunTest
 */
```

Iterate `scenarios × strategies × models`. Strategies come from `BuiltInStrategies::all()`, keyed by id; resolve each one's `strategyVersionId` from `merchant_quote_agent_strategy_version` so the decision rows carry it and Track B can group them. Build `QuoteAgentSettings` per cell with that strategy's prompt, its version id, and the cell's `ModelAccess`.

Continue the matrix on a failed cell. Record the failure and move on — one unreachable model must not cost the other cells' results.

- [ ] **Step 2: Write the JSONL**

One object per pass, field names matching the decision record's own (`quoteId`, `outcome`, `band`, `discountPercentGranted`, `totalNetBefore`, `totalNetAfter`, `strategyVersionId`, `model`, `promptTokens`, `completionTokens`, `terminalState`, `createdAt`, `resolvedAt`) plus `runId`, `scenarioId` and `round`. Matching the record's field names is what lets the scorer in Task 8 feed them straight to `measures.ts`.

- [ ] **Step 3: Verify the rows carry a strategy**

```bash
# on the target shop
SELECT LOWER(HEX(strategy_version_id)), model, COUNT(*)
FROM merchant_quote_agent_decision
GROUP BY strategy_version_id, model
```

Expected: one group per `(strategy, model)` cell. A null group means `strategyVersionId` was not threaded through and Track B will have nothing to group.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Bench/
git commit -m "feat(bench): run the scenario x strategy x model matrix

Env-gated like the other live tests. Resolves each built-in strategy's
version id so the decision rows carry it and the admin can group them.
A failed cell is recorded and the matrix continues.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: The scenario set

**Files:**
- Create: `tests/Integration/Bench/scenarios/*.json`

Write one file per scenario listed in the spec, each with a real `openingAsk` a buyer would type. Chosen from failure classes this codebase has actually shipped:

1. `plain-percentage` — a percentage ask inside the band.
2. `structured-only` — a per-line `requested_price` with **no comment at all**. This path once recorded a real price ask as `nothing_to_do`.
3. `gross-figure-in-comment` — a gross figure typed in a comment, which is stored one tax factor higher than the buyer meant.
4. `exactly-at-the-ceiling` — an ask sitting on the band limit, where a gross ask against a cent-rounded net baseline beats `Epsilon::RATE`.
5. `multi-round-anchoring` — five rounds; by the last, the concession must still be measured against the original baseline.
6. `bundle-ask` and `payment-terms-ask` — non-price asks the agent has no mandate over.
7. `ambiguous-ask` — should draw a clarification, not an offer.
8. `hostile-extraction` — attempts to extract account history or move a cap. Model it on the injection string already in `HistoryInjectionFixture::freshHistoryQuote()`.

- [ ] **Step 1: Write the files**
- [ ] **Step 2: Assert every one loads**

Add a test that `Scenario::all()` over the directory returns one scenario per file and that none throws. A malformed scenario must fail here, not three model calls into a paid run.

- [ ] **Step 3: Run the whole set against the scripted buyer and a scripted model**

Free, and it proves every scenario reaches the pipeline. Expected bands are asserted where the scenario declares one.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Bench/scenarios/
git commit -m "test(bench): seed the scenario set from real failure classes

Each scenario covers a path this codebase has actually got wrong:
structured-only asks, gross figures in comments, the band ceiling,
multi-round anchoring, non-price asks, ambiguity and injection.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: The scorer

**Files:**
- Create: `scripts/bench-score.mjs`

**Interfaces:**
- Consumes: a run's JSONL; `measures.ts` and `decision.ts` from the administration module, **imported read-only**.
- Produces: a printed table, one row per `(strategy, model)`.

**Import, never reimplement.** This is the whole reason the metrics are aligned: the bench and the admin compute the same numbers because they run the same code. `quality:admin` already proves these modules run headless under `node --experimental-strip-types`.

- [ ] **Step 1: Write it**

Read the JSONL, group by `(strategyVersionId, model)`, and for each group call `foldToQuotes`, `autoExecutionRate`, `escalationResolution` and — once Track B has merged — `tokensPerNegotiation`.

`priceRetention` and `dealCycleTime` need `quoteRows` and `orderDates`, which a JSONL of decision records does not carry. Either export them alongside in Task 6, or print those two as unavailable. **Do not fabricate them**, and do not reimplement a simplified version.

Print N per group. The same no-winner rule as Track B applies: the scorer ranks nothing.

- [ ] **Step 2: Run it against a scripted-model run's output**

```bash
node scripts/bench-score.mjs var/bench/<runId>.jsonl
```

- [ ] **Step 3: Commit**

```bash
git add scripts/bench-score.mjs
git commit -m "feat(bench): score a run with the admin's own measure code

Imports measures.ts rather than reimplementing it, so the bench readout
and the dashboard cannot drift apart. Prints N per group and ranks
nothing.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: The first real run

**Files:** none. This task produces a result and a report.

**Choosing the shop.** Measured 2026-09-17:

- **hoelshare / sw-ag.dev** — the spec's choice. `maxQuoteValueNet=500000`, `maxDiscountPercent=15`, `counterOfferMaxPercent=25`, `validityDays=10`. The shop is up (`https://sw-ag.dev/admin` → 200) and port 22 is open, but **SSH authentication hangs** — the 1Password agent needs interactive approval, so an unattended run cannot reach it. `SHOP_SSH=root@hoelshare.com`, `SHOP_PATH=/var/www/shopware`, and prefix with
  `export SSH_AUTH_SOCK="$HOME/Library/Group Containers/2BUA8C4S2C.com.1password/t/agent.sock"`.
- **The shopdev parity shop** — `agenticquote-shoelscher.eu-core-1.shopdev.de`, docroot `~/files/agenticquote` (pass it absolute; `~` will not expand). Answered immediately with no interaction, Shopware 6.7.13.1, `maxQuoteValueNet` 500000, `validityDays` 10, and the integration suite has run there since 2026-09-09.

Confirm with the user which one before spending money. Do not pick silently — the spec names hoelshare, and switching shops changes what the numbers mean.

- [ ] **Step 1: Dry-count the matrix**

Print `scenarios × strategies × models` and the worst-case pass count (`× maxRounds`) before making a single call. Report it.

- [ ] **Step 2: Run one cell end to end with a real model**

One scenario, one strategy, one model. Confirm: a decision row exists, it carries the right `strategy_version_id` and `model`, the reply reached the quote, and nothing escalated for a reason the scenario did not intend.

- [ ] **Step 3: Run the matrix with the scripted buyer**

Cheaper than LLM buyers and still exercises every agent-side call.

- [ ] **Step 4: Score it and report**

Run the scorer. Report the table, the failed cells, and — separately — anything the bench revealed about the *engine* rather than about the strategies. Those are findings to file, not changes to make.

- [ ] **Step 5: Confirm Track B's table shows it**

Open the admin on the target shop. The per-strategy table should now show one row per built-in strategy with a real N. This is the point where the two tracks meet, and the first moment either has been proven end to end.

---

## Verification before calling this done

- [ ] `composer run quality` passes.
- [ ] `composer run test` passes (all unit tests, no shop).
- [ ] `composer run test:integration` passes, including the pre-existing suite after the Task 1 trait extraction.
- [ ] `BenchPersistenceTest` passes — bench writes survive.
- [ ] Every scenario loads and reaches the pipeline under a scripted model.
- [ ] A real run has produced decision rows carrying both `strategy_version_id` and `model`.
- [ ] The scorer's numbers have been checked by hand against at least one group's rows.
- [ ] No file under `src/Negotiation/`, `src/Policy/`, or `src/Resources/app/administration/` was modified.

## Findings to file rather than fix

- **#142's round cap never landed.** PR #148 closed unmerged. The bench's round-count distribution per strategy is the evidence that issue lacked — reopen it with the numbers.
- Anything the bench reveals about the engine. The bench measures; it does not adjust.
