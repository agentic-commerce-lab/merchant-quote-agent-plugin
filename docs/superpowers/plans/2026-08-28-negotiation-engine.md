# Negotiation Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill the servicing seam with a working negotiation engine — deterministic bands decide what is permitted, a gated LLM decides how much within it and how to say it.

**Architecture:** A new Shopware-free `src/Negotiation/` namespace. `NegotiationPipeline` implements `QuoteServicingPipelineInterface` and calls six single-method stages in order: adapt → interpret → classify → propose → apply/verify → reply. Three model calls, gated so an out-of-authority ask escalates after one call rather than three. Every step is idempotent; the handler's fingerprint stamp remains the single commit point.

**Tech Stack:** PHP 8.3, Shopware 6.7, SwagCommercial 7.13.1, Symfony 7.4, Guzzle 7 (already a direct dependency of `shopware/core`), PHPUnit 11, Mago.

**Spec:** `docs/superpowers/specs/2026-08-28-negotiation-engine-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file.
- Mago analyze at full strictness. No `mixed` leaking, no unsafe casts. Narrow with `\is_string()` / `\is_int()` / `\is_float()` before use.
- Thresholds: cyclomatic complexity 10 **class-scoped, level=error** (it sums decision points across every method in a class — extracting a private method does NOT lower it; split the class instead), nesting 4, parameters 5, ~400 lines/file.
- `#[\Override]` on every interface/parent method implementation, including anonymous classes in tests.
- PSR-3 logger only. Never `echo`/`var_dump`/`print_r` in `src/`.
- **Never reference a SwagCommercial class with `::class`.** ADR 0001. Nothing in this plan needs one.
- **`src/Negotiation/` imports only** `Bridge\Data\*`, `Bridge\QuoteGatewayInterface`, `Policy\*`, `Config\QuoteAgentSettings`, PSR-3 and Guzzle. No `SystemConfigService`, no filesystem, no container. A test asserting this lands in Task 13.
- Branch: `feat/18-negotiation-engine`. Commit after every task.
- **Run `composer run quality` in EVERY task's final step**, not only at the end. On the previous branch a shadow dependency made it red for five tasks before anyone noticed.
- The pre-commit hook runs mago format + lint on staged files **including tests**. Fix rejections; never `--no-verify`.
- Integration tests run only inside the shop container: `composer run test:integration`.
- Two names that are easy to swap: **`NegotiationProposal`** is the *buyer's* interpreted ask (input to `NegotiationDecider`). **`ProposedOffer`** is the *agent's* offer (input to `OfferAuthorizer`).
- Fixed values: extract/negotiate/reply prompt files live at `config/agents/quote-{extract,negotiate,reply}-agent.prompt.md`. Model timeout 30s, one retry after 2s. Lock TTL is 300s and three calls must fit inside it.

## Resolved before planning

The spec left `llmModel`'s default open. **Decision: no default.** A blank `llmModel` on an enabled channel is a misconfiguration, exactly as a blank API key already is — the merchant names their model the same way they supply their key. Shipping a guessed default would silently pick a price/quality point on their behalf. Adding a default later is a one-line `config.xml` change.

---

### Task 1: Amend #5 — model name, and rules-only needs a key

Two consequences the spec discovered. Both are in the config layer and both must land before anything can call a model.

**Files:**
- Modify: `src/Resources/config/config.xml`
- Modify: `src/Config/ModelAccess.php`
- Modify: `src/Config/RawConfigValue.php`
- Modify: `src/Config/QuoteAgentSettingsFactory.php`
- Modify: `src/Config/QuoteAgentSettingsReader.php`
- Modify: `README.md`
- Test: `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`
- Test: `tests/Integration/PluginConfigTest.php`

**Interfaces:**
- Produces: `ModelAccess(string $apiKey, string $baseUrl, string $model)` — three constructor params, `$model` third. `QuoteAgentSettings::$llm` is non-null whenever the agent is enabled and valid.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`, add `'llmModel' => 'gpt-4o-mini'` to `validRaw()`, then add these three methods:

```php
    public function testTheModelNameReachesModelAccess(): void
    {
        $settings = self::build();

        self::assertNotNull($settings);
        self::assertSame('gpt-4o-mini', $settings->llm?->model);
    }

    public function testRulesOnlyStillRequiresAnApiKey(): void
    {
        try {
            self::build(['rulesOnlyMode' => true, 'llmApiKey' => '']);
            self::fail('Rules-only accepted a blank key, but interpreting a free-text ask is a model call.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString('API key', $e->getMessage());
        }
    }

    public function testAnEnabledChannelWithoutAModelNameIsAMisconfiguration(): void
    {
        try {
            self::build(['llmModel' => '']);
            self::fail('A blank model name was accepted; the request would have no model to send.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString('model', $e->getMessage());
        }
    }
```

`testRulesOnlyModeIsTheOneStateThatNeedsNoKey` now asserts the opposite of the new rule — **delete it**; `testRulesOnlyStillRequiresAnApiKey` replaces it.

In `tests/Integration/PluginConfigTest.php`, add `'llmModel'` to the key list exercised by `testEveryConfiguredKeyIsReachableThroughSystemConfig`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit --filter QuoteAgentSettingsFactoryTest`
Expected: the three new tests FAIL — `$settings->llm?->model` is an unknown property, and both blank cases are currently accepted.

- [ ] **Step 3: Add the config field**

In `src/Resources/config/config.xml`, inside the **Model access** card, immediately after `llmBaseUrl`:

```xml
        <input-field type="text">
            <name>llmModel</name>
            <label>Model name</label>
            <helpText>The model to send each request to, e.g. gpt-4o-mini. Required: there is deliberately no default, because guessing one would pick a price and quality point for you.</helpText>
        </input-field>
```

- [ ] **Step 4: Widen ModelAccess**

In `src/Config/ModelAccess.php`, add the third promoted property and extend the docblock:

```php
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $baseUrl,
        public string $model,
    ) {}
```

- [ ] **Step 5: Read and validate it**

In `src/Config/RawConfigValue.php`, the `llm()` helper currently returns null when `$rulesOnly`. Rules-only now needs model access for the extract call, so that clause goes; replace the method body with:

```php
        return $apiKey === '' ? null : new ModelAccess($apiKey, self::baseUrl($raw), self::stringOrEmpty($raw, 'llmModel'));
```

and drop the now-unused `bool $rulesOnly` parameter from its signature and from the call site in `QuoteAgentSettingsFactory`.

In `QuoteAgentSettingsFactory::fromValues()`, replace the key rule with two rules:

```php
        if ($apiKey === '') {
            $problems[] = 'No LLM API key is set. The agent needs one even in rules-only mode: '
                . 'interpreting a buyer\'s free-text ask is a model call, only the decision is deterministic.';
        }

        if (RawConfigValue::string($raw, 'llmModel') === null) {
            $problems[] = 'No model name is set. Name the model to send requests to, for example gpt-4o-mini.';
        }
```

Add `'llmModel'` to `QuoteAgentSettingsReader::KEYS`, keeping it directly after `'llmBaseUrl'`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit`
Expected: PASS. If `QuoteAgentSettingsFactory` trips class-scoped cyclomatic complexity, move the two key/model checks into a small `RawConfigValue::credentialProblems(array $raw, string $apiKey): array` returning the problem strings, and merge its result — do not raise a threshold.

- [ ] **Step 7: Update the README**

In the "Configuring the agent" section, replace the rules-only paragraph with:

```markdown
**An empty API key is never a quiet fall back to deterministic decisions.** An
enabled channel with no key, or no model name, is a misconfiguration: the agent
escalates the quote with a comment and logs which fields are wrong.

**Rules-only mode still needs a key.** It means *no model decides or writes* —
the band picks the number and a template writes the reply — but reading a
buyer's free-text ask is itself a model call, and nothing else can do it. There
is no mode in which the agent negotiates without an API key.
```

- [ ] **Step 8: Refresh the plugin and run the full gate**

```bash
docker exec merchant-quote-shop bash -lc 'cd /var/www/html && php8.3 bin/console plugin:refresh && php8.3 bin/console plugin:update MerchantQuoteAgentPlugin && php8.3 bin/console cache:clear'
composer run test:integration -- --filter PluginConfigTest
composer run quality
```
Expected: integration 6/6, quality exit 0.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: require a model name, and an API key even in rules-only mode"
```

---

### Task 2: The counter-offer band

`QuoteLimits::$counterOfferMaxPercent` has been carried and never read since #2; `PriceBandClassifier` documents `Band::Counter` as unreachable. #5 now populates the field. This task makes the band real, in the policy layer, without touching the classifier.

**Files:**
- Modify: `src/Policy/QuoteBandDecider.php`
- Test: `tests/Unit/Policy/QuoteBandDeciderTest.php`

**Interfaces:**
- Consumes: `QuoteLimits::$maxDiscountPercent`, `$counterOfferMaxPercent`, `QuoteAutoReplyPricer::price()`.
- Produces: a `QuoteDecision::autoReply()` whose `QuoteAutoReplyDetails::$counteredRequestPercent` is the buyer's original ask when the ask fell in the counter band, and null otherwise. `PriceBandClassifier::classify()` then returns `Band::Counter` unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Policy/QuoteBandDeciderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecisionKind;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\PriceBandClassifier;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The counter band, reachable for the first time. Before this, an ask above
 * maxDiscountPercent always escalated — a faithful port of the TypeScript
 * "no auto counter-offer", which #18 replaces with the PM's fixed counter.
 */
final class QuoteBandDeciderTest extends TestCase
{
    /** A 1000.00 net quote whose buyer asks for $askPercent off. */
    private static function snapshotAsking(float $askPercent): QuoteSnapshot
    {
        $total = 1000.0;

        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: $total,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1'),
                quantity: 1,
                unitPriceNet: $total,
                totalNet: $total,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            buyerTargetNet: $total * (1 - ($askPercent / 100)),
        );
    }

    /** @return iterable<string, array{0: float, 1: Band, 2: float|null}> */
    public static function bands(): iterable
    {
        // ask, expected band, expected counteredRequestPercent
        yield 'below the cap grants' => [5.0, Band::Grant, null];
        yield 'exactly the cap grants' => [10.0, Band::Grant, null];
        yield 'just inside the counter band counters' => [10.5, Band::Counter, 10.5];
        yield 'at the counter ceiling counters' => [20.0, Band::Counter, 20.0];
        yield 'above the counter ceiling escalates' => [20.5, Band::Escalate, null];
    }

    #[DataProvider('bands')]
    public function testTheAskLandsInTheRightBand(float $ask, Band $expected, ?float $countered): void
    {
        $limits = new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0);

        $decision = (new QuoteBandDecider())->decide(self::snapshotAsking($ask), $limits);

        self::assertSame($expected, (new PriceBandClassifier())->classify($decision));
        self::assertEqualsWithDelta($countered, $decision->autoReply?->counteredRequestPercent, 0.001);
    }

    public function testACounterIsPricedAtTheCapNotAtTheAsk(): void
    {
        $limits = new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0);

        $decision = (new QuoteBandDecider())->decide(self::snapshotAsking(15.0), $limits);

        self::assertSame(QuoteDecisionKind::AutoReply, $decision->kind);
        self::assertEqualsWithDelta(10.0, $decision->autoReply?->discountPercent, 0.001);
    }

    public function testWithNoCounterCeilingConfiguredAnAboveCapAskStillEscalates(): void
    {
        $limits = new QuoteLimits(maxDiscountPercent: 10.0);

        $decision = (new QuoteBandDecider())->decide(self::snapshotAsking(15.0), $limits);

        self::assertSame(QuoteDecisionKind::Escalate, $decision->kind);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter QuoteBandDeciderTest`
Expected: the three counter rows and `testACounterIsPricedAtTheCapNotAtTheAsk` FAIL — every above-cap ask currently escalates.

- [ ] **Step 3: Wire the band**

In `src/Policy/QuoteBandDecider.php`, replace the discount-limit branch with:

```php
        $discountPercent = max(0.0, MoneyMath::requestedDiscount($effective) ?? 0.0);
        $counterCeiling = $limits->counterOfferMaxPercent;

        if ($discountPercent > ($limits->maxDiscountPercent + Epsilon::RATE)) {
            // The counter band, configured by #5: an ask the agent may not grant
            // outright but may answer with a fixed counter at its own cap. Above
            // the ceiling — or with no ceiling set — the pre-#18 behaviour stands
            // and a human decides.
            if ($counterCeiling === null || $discountPercent > ($counterCeiling + Epsilon::RATE)) {
                return QuoteDecision::escalate(new QuoteEscalationDetails(
                    reason: QuoteEscalationReason::DiscountLimitExceeded,
                    requestedDiscountPercent: $discountPercent,
                ));
            }

            return QuoteDecision::autoReply($this->pricer->price(
                $effective,
                $limits->maxDiscountPercent,
                $limits->validityDays,
                counteredRequestPercent: $discountPercent,
            ));
        }

        return QuoteDecision::autoReply($this->pricer->price($effective, $discountPercent, $limits->validityDays));
```

`QuoteAutoReplyPricer::price()` gains a fourth parameter, defaulted so every existing call site is unchanged:

```php
    public function price(
        QuoteSnapshot $effective,
        float $discountPercent,
        int $validityDays,
        ?float $counteredRequestPercent = null,
    ): QuoteAutoReplyDetails {
```

and passes it through to the `QuoteAutoReplyDetails` it builds.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter 'QuoteBandDeciderTest|PriceBandClassifier|QuoteAutoReplyPricer'`
Expected: PASS.

- [ ] **Step 5: Run the full suite — this is shared policy code**

Run: `php vendor/bin/phpunit`
Expected: PASS. Any existing test asserting that an above-cap ask escalates must still pass, because those fixtures set no `counterOfferMaxPercent`. If one fails, it configured a counter ceiling and expected an escalation — read it before changing it.

- [ ] **Step 6: Quality gate and commit**

```bash
composer run quality
git add src/Policy tests/Unit/Policy/QuoteBandDeciderTest.php
git commit -m "feat: make the counter-offer band reachable"
```

---

### Task 3: The snapshot adapter

The pipeline receives `Bridge\Data\QuoteSnapshot`; the deciders read `Policy\Data\QuoteSnapshot`. Both namespaces define their own `QuoteLineSnapshot` and `QuoteLineIdentity`, so this is a field-by-field map, not a cast. It also splits comments by authorship, which every later stage depends on.

**Files:**
- Create: `src/Negotiation/SnapshotAdapter.php`
- Create: `src/Negotiation/BuyerConversation.php`
- Test: `tests/Unit/Negotiation/SnapshotAdapterTest.php`

**Interfaces:**
- Consumes: `Bridge\Data\QuoteSnapshot`, `Bridge\Data\QuoteComment::isAuthored()`.
- Produces:
  - `SnapshotAdapter::toPolicy(BridgeSnapshot $snapshot): PolicySnapshot`
  - `SnapshotAdapter::conversation(BridgeSnapshot $snapshot): BuyerConversation`
  - `BuyerConversation` readonly with `list<QuoteComment> $buyer`, `list<QuoteComment> $agent`, `hasNewBuyerAsk(): bool`, `newestBuyerText(): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/SnapshotAdapterTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use PHPUnit\Framework\TestCase;

final class SnapshotAdapterTest extends TestCase
{
    /** @param list<QuoteComment> $comments */
    private static function bridgeSnapshot(array $comments = [], ?float $requestedUnitPrice = null): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 250.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            content: new QuoteContent(
                lines: [new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                    quantity: 5,
                    unitPriceNet: 50.0,
                    totalNet: 250.0,
                    requestedUnitPrice: $requestedUnitPrice,
                )],
                comments: $comments,
            ),
        );
    }

    private static function buyer(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, customerId: 'cust-1', createdAt: new \DateTimeImmutable($at));
    }

    private static function agent(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, createdAt: new \DateTimeImmutable($at));
    }

    public function testItMapsTotalsCurrencyStateAndLines(): void
    {
        $policy = SnapshotAdapter::toPolicy(self::bridgeSnapshot());

        self::assertSame('EUR', $policy->currencyIso);
        self::assertSame(250.0, $policy->totalNet);
        self::assertSame('open', $policy->lifecycle->stateTechnicalName);
        self::assertCount(1, $policy->lines);
        self::assertSame('line-1', $policy->lines[0]->identity->lineItemId);
        self::assertSame('Widget', $policy->lines[0]->identity->label);
        self::assertSame(5, $policy->lines[0]->quantity);
        self::assertSame(50.0, $policy->lines[0]->unitPriceNet);
    }

    public function testALineLevelTargetPriceSurvivesTheMapping(): void
    {
        // The one structured ask that reaches the deciders without any comment.
        $policy = SnapshotAdapter::toPolicy(self::bridgeSnapshot(requestedUnitPrice: 45.0));

        self::assertSame(45.0, $policy->lines[0]->requestedUnitPrice);
    }

    public function testCommentsSplitByAuthorship(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::buyer('can you do better?', '2026-08-28 09:00:00'),
            self::agent('here is our offer', '2026-08-28 09:30:00'),
        ]));

        self::assertCount(1, $conversation->buyer);
        self::assertCount(1, $conversation->agent);
        self::assertSame('can you do better?', $conversation->buyer[0]->comment);
    }

    public function testABuyerCommentNewerThanTheAgentsReplyIsANewAsk(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::agent('here is our offer', '2026-08-28 09:00:00'),
            self::buyer('still too expensive', '2026-08-28 09:30:00'),
        ]));

        self::assertTrue($conversation->hasNewBuyerAsk());
        self::assertSame('still too expensive', $conversation->newestBuyerText());
    }

    public function testAnAgentReplyNewerThanEveryBuyerCommentIsNotANewAsk(): void
    {
        // The re-trigger case: nothing has happened since we answered, so the
        // extract call must be skipped entirely.
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::buyer('can you do better?', '2026-08-28 09:00:00'),
            self::agent('here is our offer', '2026-08-28 09:30:00'),
        ]));

        self::assertFalse($conversation->hasNewBuyerAsk());
    }

    public function testAQuoteWithNoCommentsAtAllHasNoAsk(): void
    {
        self::assertFalse(SnapshotAdapter::conversation(self::bridgeSnapshot())->hasNewBuyerAsk());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter SnapshotAdapterTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter" not found`.

- [ ] **Step 3: Write BuyerConversation**

Create `src/Negotiation/BuyerConversation.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;

/**
 * The quote's comments split by who wrote them, which is the whole basis for
 * "is there anything new to answer?".
 *
 * Authorship is the discriminator because #3 measured an agent comment as null
 * on createdById, customerId and employeeId alike, pinned by AddCommentTest.
 * The day SwagCommercial starts stamping an author is the day this switches to
 * reading createdById — and that test is what will tell us.
 */
final readonly class BuyerConversation
{
    /**
     * @param list<QuoteComment> $buyer
     * @param list<QuoteComment> $agent
     */
    public function __construct(
        public array $buyer,
        public array $agent,
    ) {}

    /**
     * True when a buyer comment is newer than every agent reply. A re-trigger
     * with nothing new must not cost a model call.
     */
    public function hasNewBuyerAsk(): bool
    {
        $newestBuyer = self::newest($this->buyer);

        if ($newestBuyer === null) {
            return false;
        }

        $newestAgent = self::newest($this->agent);

        return $newestAgent === null || $newestBuyer > $newestAgent;
    }

    /** Every buyer comment, oldest first, as the extract prompt expects. */
    public function buyerText(): string
    {
        return implode("\n", array_map(static fn(QuoteComment $c): string => $c->comment, $this->buyer));
    }

    public function newestBuyerText(): string
    {
        $newest = null;
        $text = '';

        foreach ($this->buyer as $comment) {
            $at = $comment->createdAt?->format('U.u') ?? '0';

            if ($newest === null || $at > $newest) {
                $newest = $at;
                $text = $comment->comment;
            }
        }

        return $text;
    }

    /** @param list<QuoteComment> $comments */
    private static function newest(array $comments): ?string
    {
        $newest = null;

        foreach ($comments as $comment) {
            $at = $comment->createdAt?->format('U.u');

            if ($at !== null && ($newest === null || $at > $newest)) {
                $newest = $at;
            }
        }

        return $newest;
    }
}
```

- [ ] **Step 4: Write SnapshotAdapter**

Create `src/Negotiation/SnapshotAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot as BridgeLine;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot as BridgeSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle as PolicyLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity as PolicyLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;

/**
 * Between the bridge's full-fidelity read model and the policy layer's trimmed
 * one. Not a cast: both namespaces define their own QuoteLineSnapshot and
 * QuoteLineIdentity, deliberately, so that negotiation-core stays free of
 * anything it does not read.
 *
 * `requestedUnitPrice` is the one ask that arrives structured — a per-line
 * target entered in the storefront — so it reaches the deciders even when the
 * buyer left no comment at all.
 */
final class SnapshotAdapter
{
    private function __construct() {}

    public static function toPolicy(BridgeSnapshot $snapshot): PolicySnapshot
    {
        return new PolicySnapshot(
            currencyIso: $snapshot->identity->currencyIso,
            totalNet: $snapshot->totals->totalNet,
            lines: array_map(self::line(...), $snapshot->content->lines),
            lifecycle: new PolicyLifecycle(
                stateTechnicalName: $snapshot->lifecycle->stateTechnicalName,
                expirationDate: $snapshot->lifecycle->expiresAt?->format('Y-m-d'),
            ),
        );
    }

    public static function conversation(BridgeSnapshot $snapshot): BuyerConversation
    {
        $buyer = [];
        $agent = [];

        foreach ($snapshot->content->comments as $comment) {
            if ($comment->isAuthored()) {
                $buyer[] = $comment;

                continue;
            }

            $agent[] = $comment;
        }

        return new BuyerConversation($buyer, $agent);
    }

    private static function line(BridgeLine $line): PolicyLine
    {
        return new PolicyLine(
            identity: new PolicyLineIdentity(
                lineItemId: $line->identity->lineItemId,
                label: $line->identity->label,
                productId: $line->identity->productId,
            ),
            quantity: $line->quantity,
            unitPriceNet: $line->unitPriceNet,
            totalNet: $line->totalNet,
            requestedUnitPrice: $line->requestedUnitPrice,
        );
    }
}
```

Note the deliberate omission: `buyerTargetNet` is left null here. It is derived inside the policy layer by `QuoteDiscountApplier` from an interpretation — the adapter must not invent one.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter SnapshotAdapterTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Quality gate and commit**

```bash
vendor/bin/mago fmt src/Negotiation tests/Unit/Negotiation
composer run quality
git add src/Negotiation tests/Unit/Negotiation
git commit -m "feat: adapt the bridge snapshot to the policy model"
```

---

### Task 4: The chat-completion client

One client, three uses. Guzzle is already a direct dependency of `shopware/core` (`^7.5`), so this installs nothing — but it must be declared, or `composer-dependency-analyser` fails the quality gate on a shadow dependency.

**Files:**
- Create: `src/Negotiation/ChatCompletionClient.php`
- Create: `src/Negotiation/ModelUnavailable.php`
- Modify: `composer.json`
- Test: `tests/Unit/Negotiation/ChatCompletionClientTest.php`

**Interfaces:**
- Consumes: `Config\ModelAccess` (`$apiKey`, `$baseUrl`, `$model`).
- Produces: `ChatCompletionClient::__construct(ClientInterface $http, LoggerInterface $logger)` and `complete(ModelAccess $access, string $system, string $user, bool $json): string` returning the assistant message content, throwing `ModelUnavailable`.

- [ ] **Step 1: Declare Guzzle**

In `composer.json`, add to `require`, keeping the block alphabetical:

```json
        "guzzlehttp/guzzle": "^7.5",
```

Then `composer update --lock` to refresh the content hash only. Verify with `composer run quality:depcheck` — expected "No composer issues found".

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Negotiation/ChatCompletionClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatCompletionClientTest extends TestCase
{
    private static function access(): ModelAccess
    {
        return new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini');
    }

    private static function ok(string $content): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<mixed> $queue */
    private static function client(array $queue, ?array &$sent = null): ChatCompletionClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(static function (callable $handler) use (&$sent) {
            return static function ($request, array $options) use ($handler, &$sent) {
                $sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new ChatCompletionClient(new Client(['handler' => $stack]), new NullLogger());
    }

    public function testItReturnsTheAssistantMessageContent(): void
    {
        $client = self::client([self::ok('{"ok":true}')]);

        self::assertSame('{"ok":true}', $client->complete(self::access(), 'sys', 'usr', json: true));
    }

    public function testItPostsToTheMerchantsBaseUrlWithTheirKeyAndModel(): void
    {
        $sent = [];
        self::client([self::ok('x')], $sent)->complete(self::access(), 'sys', 'usr', json: true);

        self::assertCount(1, $sent);
        self::assertSame('POST', $sent[0]->getMethod());
        self::assertSame('https://api.example.com/v1/chat/completions', (string) $sent[0]->getUri());
        self::assertSame('Bearer sk-test', $sent[0]->getHeaderLine('Authorization'));

        $body = json_decode((string) $sent[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('gpt-4o-mini', $body['model']);
        self::assertSame('sys', $body['messages'][0]['content']);
        self::assertSame('usr', $body['messages'][1]['content']);
        self::assertSame(['type' => 'json_object'], $body['response_format']);
    }

    public function testJsonFalseOmitsTheResponseFormat(): void
    {
        // The reply prompt returns prose, not JSON — asking for json_object
        // there would make the model wrap the sentence in a JSON envelope.
        $sent = [];
        self::client([self::ok('a sentence')], $sent)->complete(self::access(), 'sys', 'usr', json: false);

        $body = json_decode((string) $sent[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('response_format', $body);
    }

    public function testATransientFailureIsRetriedOnce(): void
    {
        $sent = [];
        $client = self::client([new Response(503), self::ok('recovered')], $sent);

        self::assertSame('recovered', $client->complete(self::access(), 'sys', 'usr', json: true));
        self::assertCount(2, $sent, 'The 503 was not retried.');
    }

    public function testASecondFailureThrowsModelUnavailable(): void
    {
        $sent = [];
        $client = self::client([new Response(503), new Response(503)], $sent);

        $this->expectException(ModelUnavailable::class);

        try {
            $client->complete(self::access(), 'sys', 'usr', json: true);
        } finally {
            self::assertCount(2, $sent, 'Retried more than once; three calls must fit inside the 300s lock TTL.');
        }
    }

    public function testAConnectionFailureIsAlsoTransient(): void
    {
        $client = self::client([
            new ConnectException('timed out', new Request('POST', 'https://api.example.com/v1/chat/completions')),
            self::ok('recovered'),
        ]);

        self::assertSame('recovered', $client->complete(self::access(), 'sys', 'usr', json: true));
    }

    public function testAMalformedEnvelopeThrowsModelUnavailable(): void
    {
        $client = self::client([new Response(200, [], '{"choices":[]}')]);

        $this->expectException(ModelUnavailable::class);

        $client->complete(self::access(), 'sys', 'usr', json: true);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter ChatCompletionClientTest`
Expected: FAIL — the class does not exist.

- [ ] **Step 4: Write the exception**

Create `src/Negotiation/ModelUnavailable.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The model could not be reached, or answered with something unusable.
 *
 * Always escalates. There is deliberately no fall back to rules-only on error:
 * a shop whose negotiation quietly changes character when a provider has a bad
 * minute is the silent behaviour change this whole design exists to remove.
 */
final class ModelUnavailable extends \RuntimeException {}
```

- [ ] **Step 5: Write the client**

Create `src/Negotiation/ChatCompletionClient.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use Psr\Log\LoggerInterface;

/**
 * One `POST /chat/completions`, used by all three prompts. No agent loop, no
 * tool calling — the model answers once and the rules decide what that answer
 * is allowed to do.
 *
 * Exactly ONE retry. A servicing pass makes up to three calls and the quote
 * lock's TTL is 300 seconds; at a 30s timeout plus one 2s backoff, three calls
 * worst-case is already about three minutes. A second retry would risk the
 * lock expiring mid-pass, which is a far worse failure than escalating.
 */
final readonly class ChatCompletionClient
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_DELAY_MICROSECONDS = 2_000_000;

    public function __construct(
        private ClientInterface $http,
        private LoggerInterface $logger,
    ) {}

    /** @throws ModelUnavailable */
    public function complete(ModelAccess $access, string $system, string $user, bool $json): string
    {
        try {
            return $this->send($access, $system, $user, $json);
        } catch (ModelUnavailable | GuzzleException $first) {
            $this->logger->info('The model call failed; retrying once.', ['exception' => $first]);
            usleep(self::RETRY_DELAY_MICROSECONDS);
        }

        try {
            return $this->send($access, $system, $user, $json);
        } catch (GuzzleException $second) {
            throw new ModelUnavailable('The model could not be reached.', previous: $second);
        }
    }

    /** @throws ModelUnavailable|GuzzleException */
    private function send(ModelAccess $access, string $system, string $user, bool $json): string
    {
        $payload = [
            'model' => $access->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $access->apiKey],
            'json' => $payload,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        return self::content((string) $response->getBody());
    }

    /** @throws ModelUnavailable */
    private static function content(string $body): string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelUnavailable('The model returned a body that is not JSON.', previous: $e);
        }

        $content = \is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;

        if (!\is_string($content) || $content === '') {
            throw new ModelUnavailable('The model response carried no message content.');
        }

        return $content;
    }
}
```

`MockHandler` returns a 503 as a `RequestException`, which is a `GuzzleException`, so the retry path covers both HTTP failures and connection failures without enumerating status codes.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter ChatCompletionClientTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: Quality gate and commit**

```bash
composer run quality
git add composer.json composer.lock src/Negotiation tests/Unit/Negotiation
git commit -m "feat: a chat-completion client with one retry and a hard ceiling"
```

---

### Task 5: Prompt composition and versioning

The three prompts are strings handed in at construction, never read from disk by this namespace. Composition is where the merchant's strategy and tone enter, and the hash is what lets #22 later say which prompt produced which outcome.

**Files:**
- Create: `src/Negotiation/PromptComposer.php`
- Create: `src/Negotiation/ComposedPrompt.php`
- Test: `tests/Unit/Negotiation/PromptComposerTest.php`

**Interfaces:**
- Consumes: `Config\QuoteAgentSettings` (`$strategyPrompt`, and `$policy->price->replyTone`).
- Produces:
  - `ComposedPrompt` readonly with `string $text` and `string $hash`.
  - `PromptComposer::__construct(string $extract, string $negotiate, string $reply)`
  - `extract(): ComposedPrompt`, `negotiate(QuoteAgentSettings $settings): ComposedPrompt`, `reply(QuoteAgentSettings $settings): ComposedPrompt`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/PromptComposerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\TestCase;

final class PromptComposerTest extends TestCase
{
    private static function composer(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY BASE {{tone}} END');
    }

    private static function settings(?string $strategy = null, ?string $tone = null): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0, replyTone: $tone)),
            rulesOnly: false,
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: $strategy,
        );
    }

    public function testTheExtractPromptIsVerbatim(): void
    {
        self::assertSame('EXTRACT BASE', self::composer()->extract()->text);
    }

    public function testAMerchantStrategyIsAppendedInADelimitedSection(): void
    {
        $composed = self::composer()->negotiate(self::settings(strategy: 'open at 2%'))->text;

        self::assertSame(
            "NEGOTIATE BASE\n\n## Merchant strategy\n\nopen at 2%",
            $composed,
            'The strategy must be delimited so the model cannot read it as part of the base instructions.',
        );
    }

    public function testNoStrategyLeavesTheBasePromptUntouched(): void
    {
        self::assertSame('NEGOTIATE BASE', self::composer()->negotiate(self::settings())->text);
    }

    public function testTheToneIsSubstitutedIntoTheReplyPrompt(): void
    {
        self::assertSame('REPLY BASE formal END', self::composer()->reply(self::settings(tone: 'formal'))->text);
    }

    public function testABlankToneGetsANeutralInstructionRatherThanAnEmptyHole(): void
    {
        $composed = self::composer()->reply(self::settings())->text;

        self::assertStringNotContainsString('{{tone}}', $composed);
        self::assertStringContainsString('neutral', $composed);
    }

    public function testTheHashIsTheSha256OfTheComposedText(): void
    {
        $composed = self::composer()->negotiate(self::settings(strategy: 'open at 2%'));

        self::assertSame(hash('sha256', $composed->text), $composed->hash);
    }

    public function testChangingTheStrategyMovesTheNegotiateHash(): void
    {
        // This is what lets #22 say which prompt produced which outcome.
        $a = self::composer()->negotiate(self::settings(strategy: 'open at 2%'))->hash;
        $b = self::composer()->negotiate(self::settings(strategy: 'open at 4%'))->hash;

        self::assertNotSame($a, $b);
    }

    public function testChangingTheToneMovesTheReplyHashButNotTheExtractHash(): void
    {
        $composer = self::composer();

        self::assertNotSame(
            $composer->reply(self::settings(tone: 'formal'))->hash,
            $composer->reply(self::settings(tone: 'warm'))->hash,
        );
        self::assertSame(
            $composer->extract()->hash,
            $composer->extract()->hash,
            'The extract prompt takes no merchant input, so its hash is constant per deploy.',
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter PromptComposerTest`
Expected: FAIL — the classes do not exist.

- [ ] **Step 3: Write ComposedPrompt**

Create `src/Negotiation/ComposedPrompt.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * A system prompt and the hash of exactly the text that was sent. The hash is
 * recorded on the outcome so a later analysis (#22) can attribute a result to
 * the prompt that produced it — which only works if it hashes the COMPOSED
 * text, merchant strategy and tone included, not the base file.
 */
final readonly class ComposedPrompt
{
    public string $hash;

    public function __construct(
        public string $text,
    ) {
        $this->hash = hash('sha256', $text);
    }
}
```

- [ ] **Step 4: Write PromptComposer**

Create `src/Negotiation/PromptComposer.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;

/**
 * Composes the three system prompts. The base texts are handed in as strings
 * by the container, never read from disk here — that is what keeps this
 * namespace free of Shopware and of any path assumption.
 *
 * The merchant's strategy tunes tone and posture and CANNOT move a cap: it
 * lands in a delimited section below the base instructions, and OfferAuthorizer
 * rejects anything outside authority regardless of what the prompt asked for.
 */
final readonly class PromptComposer
{
    private const STRATEGY_HEADING = '## Merchant strategy';

    private const TONE_PLACEHOLDER = '{{tone}}';

    private const NEUTRAL_TONE = 'neutral and professional';

    public function __construct(
        private string $extractBase,
        private string $negotiateBase,
        private string $replyBase,
    ) {}

    public function extract(): ComposedPrompt
    {
        return new ComposedPrompt($this->extractBase);
    }

    public function negotiate(QuoteAgentSettings $settings): ComposedPrompt
    {
        $strategy = $settings->strategyPrompt;

        if ($strategy === null || trim($strategy) === '') {
            return new ComposedPrompt($this->negotiateBase);
        }

        return new ComposedPrompt(
            $this->negotiateBase . "\n\n" . self::STRATEGY_HEADING . "\n\n" . trim($strategy),
        );
    }

    public function reply(QuoteAgentSettings $settings): ComposedPrompt
    {
        $tone = $settings->policy->price->replyTone;
        $tone = $tone === null || trim($tone) === '' ? self::NEUTRAL_TONE : trim($tone);

        return new ComposedPrompt(str_replace(self::TONE_PLACEHOLDER, $tone, $this->replyBase));
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter PromptComposerTest`
Expected: PASS, 8 tests.

- [ ] **Step 6: Add a golden-file test for the real prompts**

Append to `tests/Unit/Negotiation/PromptComposerTest.php`:

```php
    public function testTheRealNegotiatePromptComposesWithAStrategySection(): void
    {
        // Against the shipped prompt files, so an edit to them is a visible
        // diff in this test rather than a silent change in agent behaviour.
        $composer = new PromptComposer(
            (string) file_get_contents(__DIR__ . '/../../../config/agents/quote-extract-agent.prompt.md'),
            (string) file_get_contents(__DIR__ . '/../../../config/agents/quote-negotiate-agent.prompt.md'),
            (string) file_get_contents(__DIR__ . '/../../../config/agents/quote-reply-agent.prompt.md'),
        );

        $composed = $composer->negotiate(self::settings(strategy: 'concede in 1% steps'))->text;

        self::assertStringStartsWith('You are a merchant\'s B2B sales agent', $composed);
        self::assertStringEndsWith("## Merchant strategy\n\nconcede in 1% steps", $composed);
        self::assertStringNotContainsString('{{tone}}', $composer->reply(self::settings(tone: 'warm'))->text);
    }
```

Run: `php vendor/bin/phpunit --filter PromptComposerTest` — expected PASS, 9 tests. If the `assertStringStartsWith` fails, read the first line of the real file and use its exact opening rather than weakening the assertion.

- [ ] **Step 7: Quality gate and commit**

```bash
composer run quality
git add src/Negotiation tests/Unit/Negotiation
git commit -m "feat: compose and hash the three system prompts"
```

---

### Task 6: Response readers and the new escalation reasons

The prompts speak snake_case; the policy DTOs' `fromArray()` expects camelCase. These two readers do the translation and are the only place a model's output becomes a typed object.

**Files:**
- Create: `src/Negotiation/Response/ExtractResponse.php`
- Create: `src/Negotiation/Response/NegotiateResponse.php`
- Modify: `src/Policy/Data/QuoteEscalationReason.php`
- Test: `tests/Unit/Negotiation/Response/ExtractResponseTest.php`
- Test: `tests/Unit/Negotiation/Response/NegotiateResponseTest.php`

**Interfaces:**
- Produces:
  - `ExtractResponse::toInterpretation(string $json): CommentInterpretation` — throws `ModelUnavailable` on unparseable input.
  - `NegotiateResponse::read(string $json): NegotiateResponse` with `bool $escalate`, `?string $escalationReason`, `string $message`, and `toOffer(float $orderTotalNet): ProposedOffer`.
  - `QuoteEscalationReason` gains `ModelUnavailable = 'model_unavailable'`, `ProposalRejected = 'proposal_rejected'`, `VerificationFailed = 'verification_failed'`.

- [ ] **Step 1: Add the escalation reasons**

In `src/Policy/Data/QuoteEscalationReason.php`, after the existing cases:

```php
    // Issue #18. The model could not be reached or answered unusably; the
    // model itself declined; or the database disagreed with what we applied.
    case ModelUnavailable = 'model_unavailable';
    case ProposalRejected = 'proposal_rejected';
    case VerificationFailed = 'verification_failed';
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/Negotiation/Response/ExtractResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\ExtractResponse;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtractResponseTest extends TestCase
{
    public function testItReadsTheFullDocumentedShape(): void
    {
        $json = <<<'JSON'
        {
          "additional_discount_percent": 5,
          "best_price_requested": false,
          "line_changes": [{"line_item_id": "line-1", "quantity": 10, "target_unit_price": 45.5, "remove": false}],
          "add_products": [],
          "validity_until": "2026-09-30",
          "clarification_questions": ["when do you need it?"],
          "human_review_requests": [],
          "negotiation": {
            "delivery": {"free_shipping": true, "expedited": false, "requested_lead_time_days": 5},
            "payment": {"requested_term": "net_30", "requested_net_days": 30, "requested_deposit_percent": 10},
            "bundle": {"requested": false}
          }
        }
        JSON;

        $interpretation = ExtractResponse::toInterpretation($json);

        self::assertSame(5.0, $interpretation->price->additionalDiscountPercent);
        self::assertFalse($interpretation->price->bestPriceRequested);
        self::assertCount(1, $interpretation->structural->lineChanges);
        self::assertSame('2026-09-30', $interpretation->structural->validityUntilIsoDate);
        self::assertSame(['when do you need it?'], $interpretation->clarificationQuestions);
        self::assertTrue($interpretation->negotiation?->delivery?->freeShipping);
        self::assertSame(5, $interpretation->negotiation?->delivery?->requestedLeadTimeDays);
        self::assertSame(PaymentTerm::Net30, $interpretation->negotiation?->payment?->requestedTerm);
    }

    public function testAMinimalResponseIsAnEmptyInterpretationNotAFailure(): void
    {
        // "The buyer asked for nothing we can act on" is a valid answer and
        // must reach the deciders, which then escalate or no-op on their own.
        $interpretation = ExtractResponse::toInterpretation('{"best_price_requested": false}');

        self::assertNull($interpretation->price->additionalDiscountPercent);
        self::assertSame([], $interpretation->humanReviewRequests);
    }

    public function testANullNegotiationBlockIsAccepted(): void
    {
        $interpretation = ExtractResponse::toInterpretation('{"negotiation": null}');

        self::assertNull($interpretation->negotiation);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusable(): iterable
    {
        yield 'not json' => ['I cannot help with that.'];
        yield 'truncated' => ['{"additional_discount_percent": 5'];
        yield 'a json array, not an object' => ['[1,2,3]'];
        yield 'wrong type for a number' => ['{"additional_discount_percent": "five"}'];
        yield 'unknown payment term' => ['{"negotiation":{"payment":{"requested_term":"net_45"}}}'];
    }

    #[DataProvider('unusable')]
    public function testAnUnusableResponseEscalatesRatherThanGuessing(string $json): void
    {
        $this->expectException(ModelUnavailable::class);

        ExtractResponse::toInterpretation($json);
    }
}
```

Create `tests/Unit/Negotiation/Response/NegotiateResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use PHPUnit\Framework\TestCase;

final class NegotiateResponseTest extends TestCase
{
    public function testItReadsAQuoteWideOffer(): void
    {
        $response = NegotiateResponse::read('{"action":"offer","discount_percent":7.5,"message":"Here is 7.5% off."}');

        self::assertFalse($response->escalate);
        self::assertSame('Here is 7.5% off.', $response->message);

        $offer = $response->toOffer(1000.0);
        self::assertSame(7.5, $offer->price->discountPercent);
        self::assertSame(1000.0, $offer->orderTotalNet);
        self::assertNull($offer->price->linePricesNet);
    }

    public function testItReadsAPerLineOffer(): void
    {
        $json = '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":45.5}],"message":"ok"}';

        $offer = NegotiateResponse::read($json)->toOffer(1000.0);

        self::assertNotNull($offer->price->linePricesNet);
        self::assertCount(1, $offer->price->linePricesNet);
        self::assertSame('line-1', $offer->price->linePricesNet[0]->lineItemId);
        self::assertSame(45.5, $offer->price->linePricesNet[0]->unitPriceNet);
    }

    public function testItReadsNonPriceTerms(): void
    {
        $json = '{"action":"offer","free_shipping":true,"expedited":false,"committed_lead_time_days":3,'
            . '"payment_term":"net_60","net_days":60,"deposit_percent":15,"message":"ok"}';

        $offer = NegotiateResponse::read($json)->toOffer(1000.0);

        self::assertTrue($offer->delivery->freeShipping);
        self::assertSame(3, $offer->delivery->committedLeadTimeDays);
        self::assertSame(PaymentTerm::Net60, $offer->payment->paymentTerm);
        self::assertSame(60, $offer->payment->netDays);
        self::assertSame(15.0, $offer->payment->depositPercent);
    }

    public function testTheModelMayDeclineAndSayWhy(): void
    {
        $response = NegotiateResponse::read(
            '{"action":"escalate","escalation_reason":"buyer wants terms I cannot offer","message":""}',
        );

        self::assertTrue($response->escalate);
        self::assertSame('buyer wants terms I cannot offer', $response->escalationReason);
    }

    public function testAnUnknownActionIsUnusable(): void
    {
        $this->expectException(ModelUnavailable::class);

        NegotiateResponse::read('{"action":"maybe","message":"hmm"}');
    }

    public function testUnparseableJsonIsUnusable(): void
    {
        $this->expectException(ModelUnavailable::class);

        NegotiateResponse::read('Sure! Here is my offer: 10% off.');
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit --filter 'ExtractResponseTest|NegotiateResponseTest'`
Expected: FAIL — the reader classes do not exist.

- [ ] **Step 4: Write ExtractResponse**

Create `src/Negotiation/Response/ExtractResponse.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/**
 * The extract prompt's JSON to the policy layer's CommentInterpretation.
 *
 * Two vocabularies meet here: the prompt speaks snake_case because that is
 * what reads naturally in a JSON schema a model is asked to follow, and the
 * DTOs speak camelCase because that is PHP. Rekeying is the whole job.
 *
 * Anything unusable throws. There is no partial read and no default-and-carry-
 * on: an interpretation the model did not actually produce would put words in
 * the buyer's mouth, and the deciders would act on them.
 */
final class ExtractResponse
{
    private function __construct() {}

    /** @throws ModelUnavailable */
    public static function toInterpretation(string $json): CommentInterpretation
    {
        $raw = Json::object($json);

        try {
            return CommentInterpretation::fromArray([
                'price' => [
                    'additionalDiscountPercent' => $raw['additional_discount_percent'] ?? null,
                    'bestPriceRequested' => $raw['best_price_requested'] ?? null,
                ],
                'structural' => [
                    'lineChanges' => self::lineChanges($raw),
                    'addProducts' => self::addProducts($raw),
                    'validityUntilIsoDate' => $raw['validity_until'] ?? null,
                ],
                'clarificationQuestions' => $raw['clarification_questions'] ?? [],
                'humanReviewRequests' => $raw['human_review_requests'] ?? [],
                'negotiation' => self::negotiation($raw),
            ]);
        } catch (MappingError | \TypeError | \ValueError $e) {
            throw new ModelUnavailable('The extract response did not match the expected shape.', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    private static function lineChanges(array $raw): array
    {
        $out = [];

        foreach (Json::rows($raw, 'line_changes') as $row) {
            $out[] = [
                'lineItemId' => $row['line_item_id'] ?? null,
                'quantity' => $row['quantity'] ?? null,
                'targetUnitPrice' => $row['target_unit_price'] ?? null,
                'remove' => $row['remove'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    private static function addProducts(array $raw): array
    {
        $out = [];

        foreach (Json::rows($raw, 'add_products') as $row) {
            $out[] = [
                'product' => $row['product'] ?? null,
                'quantity' => $row['quantity'] ?? null,
                'targetUnitPrice' => $row['target_unit_price'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private static function negotiation(array $raw): ?array
    {
        $negotiation = $raw['negotiation'] ?? null;

        if (!\is_array($negotiation)) {
            return null;
        }

        $delivery = \is_array($negotiation['delivery'] ?? null) ? $negotiation['delivery'] : [];
        $payment = \is_array($negotiation['payment'] ?? null) ? $negotiation['payment'] : [];
        $bundle = \is_array($negotiation['bundle'] ?? null) ? $negotiation['bundle'] : [];

        return [
            'delivery' => [
                'freeShipping' => $delivery['free_shipping'] ?? null,
                'expedited' => $delivery['expedited'] ?? null,
                'requestedLeadTimeDays' => $delivery['requested_lead_time_days'] ?? null,
            ],
            'payment' => [
                'requestedTerm' => $payment['requested_term'] ?? null,
                'requestedNetDays' => $payment['requested_net_days'] ?? null,
                'requestedDepositPercent' => $payment['requested_deposit_percent'] ?? null,
            ],
            'bundle' => ['requested' => $bundle['requested'] ?? null],
        ];
    }
}
```

- [ ] **Step 5: Write the shared JSON helper**

Both readers need the same decode-and-narrow, and duplicating it would trip the duplication gate. Create `src/Negotiation/Response/Json.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;

/** Decoding a model's answer, where every failure means "escalate". */
final class Json
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     *
     * @throws ModelUnavailable
     */
    public static function object(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelUnavailable('The model did not return JSON.', previous: $e);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new ModelUnavailable('The model returned JSON that is not an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $raw, string $key): array
    {
        $rows = $raw[$key] ?? null;

        if (!\is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, \is_array(...)));
    }
}
```

- [ ] **Step 6: Write NegotiateResponse**

Create `src/Negotiation/Response/NegotiateResponse.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedDelivery;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPayment;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;

/**
 * The negotiate prompt's JSON to a ProposedOffer — the AGENT's offer, which
 * OfferAuthorizer then checks against the merchant's authority. Not to be
 * confused with NegotiationProposal, which is the BUYER's interpreted ask.
 *
 * `action: escalate` is a first-class answer, not a failure: the model is
 * allowed to say it cannot serve this buyer, and its reason travels to the log.
 */
final readonly class NegotiateResponse
{
    /** @param list<QuoteLinePrice>|null $linePrices */
    private function __construct(
        public bool $escalate,
        public ?string $escalationReason,
        public string $message,
        private ?float $discountPercent,
        private ?array $linePrices,
        private OfferedDelivery $delivery,
        private OfferedPayment $payment,
    ) {}

    /** @throws ModelUnavailable */
    public static function read(string $json): self
    {
        $raw = Json::object($json);
        $action = $raw['action'] ?? null;

        if ($action !== 'offer' && $action !== 'escalate') {
            throw new ModelUnavailable('The negotiate response carried no usable action.');
        }

        return new self(
            escalate: $action === 'escalate',
            escalationReason: \is_string($raw['escalation_reason'] ?? null) ? $raw['escalation_reason'] : null,
            message: \is_string($raw['message'] ?? null) ? $raw['message'] : '',
            discountPercent: self::number($raw, 'discount_percent'),
            linePrices: self::linePrices($raw),
            delivery: new OfferedDelivery(
                freeShipping: self::bool($raw, 'free_shipping'),
                expedited: self::bool($raw, 'expedited'),
                committedLeadTimeDays: self::int($raw, 'committed_lead_time_days'),
            ),
            payment: new OfferedPayment(
                paymentTerm: self::term($raw),
                netDays: self::int($raw, 'net_days'),
                depositPercent: self::number($raw, 'deposit_percent'),
            ),
        );
    }

    public function toOffer(float $orderTotalNet): ProposedOffer
    {
        return new ProposedOffer(
            orderTotalNet: $orderTotalNet,
            price: new OfferedPrice(
                discountPercent: $this->discountPercent,
                linePricesNet: $this->linePrices,
            ),
            delivery: $this->delivery,
            payment: $this->payment,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<QuoteLinePrice>|null
     */
    private static function linePrices(array $raw): ?array
    {
        $prices = [];

        foreach (Json::rows($raw, 'line_prices') as $row) {
            $id = $row['line_item_id'] ?? null;
            $price = $row['unit_price_net'] ?? null;

            if (\is_string($id) && (\is_int($price) || \is_float($price))) {
                $prices[] = new QuoteLinePrice($id, (float) $price);
            }
        }

        return $prices === [] ? null : $prices;
    }

    /** @param array<string, mixed> $raw */
    private static function term(array $raw): ?PaymentTerm
    {
        $term = $raw['payment_term'] ?? null;

        return \is_string($term) ? PaymentTerm::tryFrom($term) : null;
    }

    /** @param array<string, mixed> $raw */
    private static function number(array $raw, string $key): ?float
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

    /** @param array<string, mixed> $raw */
    private static function bool(array $raw, string $key): ?bool
    {
        $value = $raw[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }
}
```

Check `QuoteLinePrice`'s real constructor before writing this — if its parameters are not `(string $lineItemId, float $unitPriceNet)`, use the actual names and adjust the test's assertions to match the real property names.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter 'ExtractResponseTest|NegotiateResponseTest'`
Expected: PASS, 15 tests.

The `unknown payment term` row expects `ModelUnavailable` from `CommentInterpretation::fromArray`'s Valinor mapping. If it instead maps to null and no exception is raised, that is a real gap: change `ExtractResponse` to reject a non-null `requested_term` that `PaymentTerm::tryFrom()` does not recognise, rather than weakening the test.

- [ ] **Step 8: Full suite, quality gate, commit**

```bash
php vendor/bin/phpunit
composer run quality
git add src/Negotiation src/Policy/Data/QuoteEscalationReason.php tests/Unit/Negotiation
git commit -m "feat: read the extract and negotiate responses into policy objects"
```

---

### Task 7: The ask interpreter

Model call 1, and the one stage that is skipped outright when nothing new has happened.

**Files:**
- Create: `src/Negotiation/AskInterpreter.php`
- Test: `tests/Unit/Negotiation/AskInterpreterTest.php`
- Test helper: `tests/Unit/Negotiation/ScriptedClient.php`

**Interfaces:**
- Consumes: `ChatCompletionClient::complete()`, `PromptComposer::extract()`, `ExtractResponse::toInterpretation()`, `BuyerConversation`.
- Produces: `AskInterpreter::__construct(ChatCompletionClient $client, PromptComposer $prompts)` and `interpret(QuoteAgentSettings $settings, BridgeSnapshot $snapshot, BuyerConversation $conversation): ?InterpretedAsk`, returning null when there is no new buyer ask. `InterpretedAsk` readonly with `CommentInterpretation $interpretation` and `string $promptHash`.

- [ ] **Step 1: Write the scripted client double**

Create `tests/Unit/Negotiation/ScriptedClient.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use Psr\Log\NullLogger;

/**
 * A ChatCompletionClient whose HTTP layer is scripted, so a test can assert
 * both what came back AND how many calls were paid for. The call count is the
 * point: an assertion on the outcome alone still passes when the pipeline made
 * a model call it should have gated away.
 */
final class ScriptedClient
{
    /** @var list<string> */
    public array $systemPrompts = [];

    /** @var list<string> */
    public array $userPrompts = [];

    public int $calls = 0;

    /** @param list<string> $replies each becomes one assistant message, in order */
    public static function returning(array $replies): ChatCompletionClient
    {
        return self::spy($replies)[0];
    }

    /**
     * @param list<string> $replies
     *
     * @return array{0: ChatCompletionClient, 1: self}
     */
    public static function spy(array $replies): array
    {
        $spy = new self();
        $queue = array_map(
            static fn(string $r): Response => new Response(200, [], json_encode(
                ['choices' => [['message' => ['content' => $r]]]],
                JSON_THROW_ON_ERROR,
            )),
            $replies,
        );

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(static function (callable $handler) use ($spy) {
            return static function ($request, array $options) use ($handler, $spy) {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                ++$spy->calls;
                $spy->systemPrompts[] = $body['messages'][0]['content'];
                $spy->userPrompts[] = $body['messages'][1]['content'];

                return $handler($request, $options);
            };
        });

        return [new ChatCompletionClient(new Client(['handler' => $stack]), new NullLogger()), $spy];
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Negotiation/AskInterpreterTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\BuyerConversation;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use PHPUnit\Framework\TestCase;

final class AskInterpreterTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    public function testItInterpretsANewBuyerAsk(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"additional_discount_percent": 8}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNotNull($result);
        self::assertSame(8.0, $result->interpretation->price->additionalDiscountPercent);
        self::assertSame(1, $spy->calls);
        self::assertSame('EXTRACT BASE', $spy->systemPrompts[0]);
        self::assertStringContainsString('8% please', $spy->userPrompts[0]);
    }

    public function testTheUserPromptCarriesTheLinesSoTheModelCanNameThem(): void
    {
        // The extract prompt's contract: "You will get the quote's line items
        // (id | label | quantity | unit price) and the buyer comments."
        [$client, $spy] = ScriptedClient::spy(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('cheaper on the widgets', '2026-08-28 09:00:00'),
        ]);

        (new AskInterpreter($client, self::prompts()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertStringContainsString('line-1', $spy->userPrompts[0]);
        self::assertStringContainsString('Widget', $spy->userPrompts[0]);
    }

    public function testNoNewAskCostsNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"additional_discount_percent": 8}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNull($result);
        self::assertSame(0, $spy->calls, 'A re-trigger with nothing new must not cost a model call.');
    }

    public function testTheHashIsTheExtractPromptsHash(): void
    {
        [$client] = ScriptedClient::spy(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('hi', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertSame(self::prompts()->extract()->hash, $result?->promptHash);
    }

    public function testAnUnusableResponsePropagates(): void
    {
        [$client] = ScriptedClient::spy(['not json at all']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('hi', '2026-08-28 09:00:00'),
        ]);

        $this->expectException(ModelUnavailable::class);

        (new AskInterpreter($client, self::prompts()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );
    }
}
```

- [ ] **Step 3: Write the shared fixture**

Create `tests/Unit/Negotiation/NegotiationFixture.php` — every later task's tests use it, so it is written once here:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;

/** Shared fixtures for the negotiation stages. */
final class NegotiationFixture
{
    private function __construct() {}

    /** @param list<QuoteComment> $comments */
    public static function snapshot(
        array $comments = [],
        string $state = 'open',
        float $totalNet = 1000.0,
        ?float $requestedUnitPrice = null,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: $totalNet),
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state),
            content: new QuoteContent(
                lines: [new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                    quantity: 10,
                    unitPriceNet: $totalNet / 10,
                    totalNet: $totalNet,
                    requestedUnitPrice: $requestedUnitPrice,
                )],
                comments: $comments,
            ),
        );
    }

    public static function buyerComment(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, customerId: 'cust-1', createdAt: new \DateTimeImmutable($at));
    }

    public static function agentComment(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, createdAt: new \DateTimeImmutable($at));
    }

    public static function settings(
        bool $rulesOnly = false,
        float $maxDiscountPercent = 10.0,
        ?float $counterOfferMaxPercent = 20.0,
        ?string $strategy = null,
        ?string $tone = null,
    ): QuoteAgentSettings {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: $maxDiscountPercent,
                counterOfferMaxPercent: $counterOfferMaxPercent,
                validityDays: 14,
                replyTone: $tone,
            )),
            rulesOnly: $rulesOnly,
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: $strategy,
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit --filter AskInterpreterTest`
Expected: FAIL — `AskInterpreter` does not exist.

- [ ] **Step 5: Write AskInterpreter**

Create `src/Negotiation/AskInterpreter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\Response\ExtractResponse;

/**
 * Model call 1: the buyer's free text becomes a structured ask.
 *
 * This is the one call rules-only mode still makes. Nothing else in the plugin
 * can read a sentence — no bridge path populates a structured target price —
 * so without it the agent cannot know what was asked and could only escalate.
 * The model READS here; it never decides and never writes.
 *
 * Returns null when no buyer comment is newer than the agent's last reply. A
 * duplicate trigger then costs nothing at all.
 */
final readonly class AskInterpreter
{
    public function __construct(
        private ChatCompletionClient $client,
        private PromptComposer $prompts,
    ) {}

    /** @throws ModelUnavailable */
    public function interpret(
        QuoteAgentSettings $settings,
        QuoteSnapshot $snapshot,
        BuyerConversation $conversation,
    ): ?InterpretedAsk {
        if (!$conversation->hasNewBuyerAsk()) {
            return null;
        }

        $access = $settings->llm;

        if ($access === null) {
            throw new ModelUnavailable('No model access is configured for this sales channel.');
        }

        $prompt = $this->prompts->extract();
        $answer = $this->client->complete($access, $prompt->text, self::userPrompt($snapshot, $conversation), json: true);

        return new InterpretedAsk(ExtractResponse::toInterpretation($answer), $prompt->hash);
    }

    /**
     * The shape the extract prompt states it will receive: the line items as
     * `id | label | quantity | unit price`, then the buyer's comments.
     */
    private static function userPrompt(QuoteSnapshot $snapshot, BuyerConversation $conversation): string
    {
        $lines = array_map(
            static fn(QuoteLineSnapshot $l): string => sprintf(
                '%s | %s | %d | %.2f',
                $l->identity->lineItemId,
                $l->identity->label ?? '',
                $l->quantity,
                $l->unitPriceNet,
            ),
            $snapshot->content->lines,
        );

        return "Line items:\n" . implode("\n", $lines) . "\n\nBuyer comments:\n" . $conversation->buyerText();
    }
}
```

Create `src/Negotiation/InterpretedAsk.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/** What the buyer asked for, and the hash of the prompt that read it. */
final readonly class InterpretedAsk
{
    public function __construct(
        public CommentInterpretation $interpretation,
        public string $promptHash,
    ) {}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter AskInterpreterTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Quality gate and commit**

```bash
composer run quality
git add src/Negotiation tests/Unit/Negotiation
git commit -m "feat: interpret the buyer's ask, and skip the call when nothing is new"
```

---

### Task 8: The offer proposer

Model call 2, reached only for an in-band ask. Also the rules-only path, where the band's own price becomes the offer with no model involved.

**Files:**
- Create: `src/Negotiation/OfferProposer.php`
- Create: `src/Negotiation/ProposedAnswer.php`
- Test: `tests/Unit/Negotiation/OfferProposerTest.php`

**Interfaces:**
- Consumes: `ChatCompletionClient`, `PromptComposer::negotiate()`, `NegotiateResponse`, `OfferAuthorizer::authorize()`, `OfferLimitsBuilder::build()`, `QuoteDecision`.
- Produces: `OfferProposer::__construct(ChatCompletionClient $client, PromptComposer $prompts, OfferAuthorizer $authorizer)` and `propose(QuoteAgentSettings $settings, PolicySnapshot $policySnapshot, QuoteDecision $decision, BuyerConversation $conversation): ProposedAnswer`. `ProposedAnswer` readonly with `?ProposedOffer $offer`, `?QuoteEscalationReason $escalation`, `string $escalationDetail`, `string $modelMessage`, `?string $promptHash`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/OfferProposerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use PHPUnit\Framework\TestCase;

final class OfferProposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    private static function proposer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): OfferProposer
    {
        return new OfferProposer($client, self::prompts(), new OfferAuthorizer());
    }

    /** A grant-band decision: the buyer asked 5% against a 10% cap. */
    private static function grantDecision(): \MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision
    {
        $snapshot = SnapshotAdapter::toPolicy(NegotiationFixture::snapshot());

        return (new QuoteBandDecider())->decide(
            $snapshot->withBuyerTargetNet(950.0),
            NegotiationFixture::settings()->policy->price,
        );
    }

    public function testAnInBandAskGetsAModelProposal(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"5% for you."}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)->propose(
            NegotiationFixture::settings(),
            SnapshotAdapter::toPolicy($snapshot),
            self::grantDecision(),
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNotNull($answer->offer);
        self::assertSame(5.0, $answer->offer->price->discountPercent);
        self::assertSame('5% for you.', $answer->modelMessage);
        self::assertSame(1, $spy->calls);
    }

    public function testTheModelIsToldItsAuthorityAndTheMerchantStrategy(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"ok"}']);
        $snapshot = NegotiationFixture::snapshot();

        self::proposer($client)->propose(
            NegotiationFixture::settings(strategy: 'concede in 1% steps'),
            SnapshotAdapter::toPolicy($snapshot),
            self::grantDecision(),
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertStringContainsString('concede in 1% steps', $spy->systemPrompts[0]);
        self::assertStringContainsString('10', $spy->userPrompts[0], 'The cap must be stated to the model.');
    }

    public function testAProposalOutsideAuthorityIsRejected(): void
    {
        // The model asked for 40% against a 10% cap. The rules, not the model,
        // decide what is permitted — this is the guardrail working.
        [$client] = ScriptedClient::spy(['{"action":"offer","discount_percent":40,"message":"40% off!"}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)->propose(
            NegotiationFixture::settings(),
            SnapshotAdapter::toPolicy($snapshot),
            self::grantDecision(),
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::ProposalRejected, $answer->escalation);
    }

    public function testTheModelMayDeclineAndItsReasonIsKept(): void
    {
        [$client] = ScriptedClient::spy(
            ['{"action":"escalate","escalation_reason":"buyer wants a term I cannot offer","message":""}'],
        );
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)->propose(
            NegotiationFixture::settings(),
            SnapshotAdapter::toPolicy($snapshot),
            self::grantDecision(),
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $answer->escalation);
        self::assertStringContainsString('term I cannot offer', $answer->escalationDetail);
    }

    public function testRulesOnlyModePricesFromTheBandWithNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy([]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)->propose(
            NegotiationFixture::settings(rulesOnly: true),
            SnapshotAdapter::toPolicy($snapshot),
            self::grantDecision(),
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNotNull($answer->offer);
        self::assertSame(0, $spy->calls, 'Rules-only must not let a model choose the number.');
        self::assertNull($answer->promptHash);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter OfferProposerTest`
Expected: FAIL — `OfferProposer` does not exist.

- [ ] **Step 3: Write ProposedAnswer**

Create `src/Negotiation/ProposedAnswer.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * Either an authorized offer to apply, or a reason to escalate. Never both,
 * never neither.
 */
final readonly class ProposedAnswer
{
    private function __construct(
        public ?ProposedOffer $offer,
        public ?QuoteEscalationReason $escalation,
        public string $escalationDetail,
        public string $modelMessage,
        public ?string $promptHash,
    ) {}

    public static function offer(ProposedOffer $offer, string $modelMessage, ?string $promptHash): self
    {
        return new self($offer, null, '', $modelMessage, $promptHash);
    }

    public static function escalate(QuoteEscalationReason $reason, string $detail, ?string $promptHash): self
    {
        return new self(null, $reason, $detail, '', $promptHash);
    }
}
```

- [ ] **Step 4: Write OfferProposer**

Create `src/Negotiation/OfferProposer.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;

/**
 * Model call 2, reached only when the deterministic band already said this ask
 * is inside the merchant's authority.
 *
 * The model chooses HOW MUCH within that authority and how to say it — never
 * WHETHER it may. Whatever it returns goes through OfferAuthorizer, which
 * rejects anything outside the bands no matter what the merchant's strategy
 * prompt asked for.
 */
final readonly class OfferProposer
{
    public function __construct(
        private ChatCompletionClient $client,
        private PromptComposer $prompts,
        private OfferAuthorizer $authorizer,
    ) {}

    /** @throws ModelUnavailable */
    public function propose(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        BuyerConversation $conversation,
    ): ProposedAnswer {
        $details = $decision->autoReply;

        if ($details === null) {
            return ProposedAnswer::escalate(QuoteEscalationReason::NeedsHumanReview, 'No priced band decision.', null);
        }

        if ($settings->rulesOnly) {
            return $this->authorize($settings, self::deterministicOffer($snapshot, $details), '', null);
        }

        $access = $settings->llm;

        if ($access === null) {
            throw new ModelUnavailable('No model access is configured for this sales channel.');
        }

        $prompt = $this->prompts->negotiate($settings);
        $response = NegotiateResponse::read($this->client->complete(
            $access,
            $prompt->text,
            self::userPrompt($settings, $snapshot, $decision, $conversation),
            json: true,
        ));

        if ($response->escalate) {
            return ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                $response->escalationReason ?? 'The agent declined to answer this ask.',
                $prompt->hash,
            );
        }

        return $this->authorize($settings, $response->toOffer($snapshot->totalNet), $response->message, $prompt->hash);
    }

    private function authorize(
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
        string $message,
        ?string $promptHash,
    ): ProposedAnswer {
        $authorization = $this->authorizer->authorize($offer, $settings->policy);

        if (!$authorization->approved) {
            return ProposedAnswer::escalate(
                QuoteEscalationReason::ProposalRejected,
                implode('; ', $authorization->violations),
                $promptHash,
            );
        }

        return ProposedAnswer::offer($offer, $message, $promptHash);
    }

    /** Rules-only: the band already priced this, so the band's number IS the offer. */
    private static function deterministicOffer(
        PolicySnapshot $snapshot,
        \MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails $details,
    ): ProposedOffer {
        return new ProposedOffer(
            orderTotalNet: $snapshot->totalNet,
            price: new OfferedPrice(
                discountPercent: $details->perLineAsks ? null : $details->discountPercent,
                linePricesNet: $details->perLineAsks ? $details->lineUnitPricesNet : null,
            ),
        );
    }

    private static function userPrompt(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        BuyerConversation $conversation,
    ): string {
        $limits = $settings->policy->price;
        $countered = $decision->autoReply?->counteredRequestPercent;

        return sprintf(
            "Quote total (net): %.2f %s\n\nYOUR AUTHORITY:\n- maximum discount you may grant: %.2f%%\n%s\n\n"
            . "Buyer comments:\n%s",
            $snapshot->totalNet,
            $snapshot->currencyIso,
            $limits->maxDiscountPercent,
            $countered === null
                ? ''
                : sprintf('- the buyer asked for %.2f%%, which is above your cap: counter, do not grant it', $countered),
            $conversation->buyerText(),
        );
    }
}
```

If `OfferProposer` trips class-scoped cyclomatic complexity, move `userPrompt()` and `deterministicOffer()` into a `NegotiationPromptFacts` helper class rather than raising a threshold.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter OfferProposerTest`
Expected: PASS, 5 tests. `testAProposalOutsideAuthorityIsRejected` is the important one — if it passes only because the model's 40% was silently clamped rather than rejected, read `OfferAuthorizer` before changing anything.

- [ ] **Step 6: Quality gate and commit**

```bash
composer run quality
git add src/Negotiation tests/Unit/Negotiation
git commit -m "feat: propose an offer within authority, or escalate"
```

---

### Task 9: Apply and verify

The only stage that writes prices. Everything after it can still fail, so this is where idempotency and the revision precondition earn their keep.

**Files:**
- Create: `src/Negotiation/OfferApplier.php`
- Test: `tests/Unit/Negotiation/OfferApplierTest.php`

**Interfaces:**
- Consumes: `QuoteGatewayInterface::{transition,updateLineItems,updateQuote,recalculate,fetchSnapshot}`, `OfferVerifier::verify()`, `SnapshotAdapter`.
- Produces: `OfferApplier::__construct(OfferVerifier $verifier, LoggerInterface $logger)` and `apply(QuoteGatewayInterface $gateway, BridgeSnapshot $snapshot, QuoteAgentSettings $settings, ProposedOffer $offer): AppliedOffer`. `AppliedOffer` readonly with `bool $verified`, `list<string> $violations`, `BridgeSnapshot $after`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/OfferApplierTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

final class OfferApplierTest extends TestCase
{
    private static function applier(): OfferApplier
    {
        return new OfferApplier(new OfferVerifier(), new NullLogger());
    }

    private static function quoteWideOffer(): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));
    }

    public function testAQuoteWideOfferWritesADiscountAndAnExpiry(): void
    {
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier()->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertContains('updateQuote', $gateway->calls);
        self::assertContains('recalculate', $gateway->calls);
        self::assertNotContains('updateLineItems', $gateway->calls, 'A quote-wide offer must not write line prices.');
    }

    public function testAPerLineOfferWritesAbsoluteUnitPrices(): void
    {
        // Absolute, not a delta — that is what makes a retry idempotent.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
        $offer = new ProposedOffer(
            orderTotalNet: 1000.0,
            price: new OfferedPrice(linePricesNet: [new QuoteLinePrice('line-1', 90.0)]),
        );

        self::applier()->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), $offer);

        self::assertContains('updateLineItems', $gateway->calls);
        self::assertSame(90.0, $gateway->lineItemChanges[0]->unitPriceNet);
    }

    public function testTheFirstWriteCarriesTheRevisionPrecondition(): void
    {
        // A buyer edit between our read and our write must lose, loudly.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
        $snapshot = NegotiationFixture::snapshot();

        self::applier()->apply($gateway, $snapshot, NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertSame($snapshot->revision, $gateway->firstExpectedRevision);
    }

    public function testTheProcessTransitionIsAttemptedAndAnIllegalOneIsSurvived(): void
    {
        // Idempotency: a retry finds the quote already in_review. The
        // transition is bookkeeping; the offer is the substance.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $gateway->transitionThrows = new IllegalTransitionException('in_review', 'in_review', ['sent']);

        $applied = self::applier()->apply(
            $gateway,
            NegotiationFixture::snapshot(state: 'in_review'),
            NegotiationFixture::settings(),
            self::quoteWideOffer(),
        );

        self::assertTrue($applied->verified, 'An illegal transition must not fail the pass.');
        self::assertContains('updateQuote', $gateway->calls);
    }

    public function testItVerifiesAgainstWhatTheDatabaseSaysNotWhatWeIntended(): void
    {
        // The re-read is a SECOND snapshot, deliberately different: the
        // verifier must see the database, not the offer we built.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(),
            NegotiationFixture::snapshot(totalNet: 950.0),
        ]);

        $applied = self::applier()->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertSame(950.0, $applied->after->totals->totalNet);
    }

    public function testAVerificationViolationIsReportedRatherThanRolledBack(): void
    {
        // 1000 -> 500 is a 50% cut against a 10% cap: the verifier must object,
        // and the changes must stay for a human to see.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(),
            NegotiationFixture::snapshot(totalNet: 500.0),
        ]);

        $applied = self::applier()->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertFalse($applied->verified);
        self::assertNotSame([], $applied->violations);
        self::assertNotContains('rollback', $gateway->calls);
    }
}
```

- [ ] **Step 2: Extend FakeQuoteGateway**

`tests/Unit/Servicing/FakeQuoteGateway.php` records `addComment` and `updateQuote` but not the rest. Add, keeping the existing style:

```php
    /** @var list<QuoteLineItemChange> */
    public array $lineItemChanges = [];

    public ?QuoteRevision $firstExpectedRevision = null;

    public ?\Throwable $transitionThrows = null;

    /** @var list<QuoteTransition> */
    public array $transitions = [];
```

and record in each method — `updateLineItems()` appends to `$lineItemChanges` and `'updateLineItems'` to `$calls`; `recalculate()` appends `'recalculate'`; `transition()` appends the transition and throws `$transitionThrows` once if set. Every write method captures `$expected` into `$firstExpectedRevision` when it is still null.

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter OfferApplierTest`
Expected: FAIL — `OfferApplier` does not exist.

- [ ] **Step 4: Write AppliedOffer and OfferApplier**

Create `src/Negotiation/AppliedOffer.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/** What the database says after we wrote, and whether it agrees with us. */
final readonly class AppliedOffer
{
    /** @param list<string> $violations */
    public function __construct(
        public bool $verified,
        public array $violations,
        public QuoteSnapshot $after,
    ) {}
}
```

Create `src/Negotiation/OfferApplier.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Writes the offer, then asks the database what actually happened.
 *
 * Every write is ABSOLUTE — a unit price, a discount percentage, an expiry
 * date, never a delta — so re-running the whole pass after a crash produces
 * the same quote rather than compounding a second discount onto the first.
 *
 * A verification failure escalates and LEAVES THE CHANGES IN PLACE. Rolling
 * back is itself a fallible write with no transaction around it, and a failed
 * rollback leaves a third state nobody intended. We report what the database
 * says, which is the same principle the verifier exists to enforce.
 */
final readonly class OfferApplier
{
    public function __construct(
        private OfferVerifier $verifier,
        private LoggerInterface $logger,
    ) {}

    public function apply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
    ): AppliedOffer {
        $quoteId = $snapshot->identity->quoteId;
        $limits = $settings->policy->price;

        $this->claim($gateway, $quoteId);

        $linePrices = $offer->price->linePricesNet;
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $limits->validityDays));

        if ($linePrices !== null && $linePrices !== []) {
            $gateway->updateLineItems($quoteId, array_map(
                static fn(QuoteLinePrice $p): QuoteLineItemChange => new QuoteLineItemChange(
                    lineItemId: $p->lineItemId,
                    unitPriceNet: $p->unitPriceNet,
                ),
                $linePrices,
            ), $snapshot->revision);

            $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: $expiresAt));
        } else {
            $gateway->updateQuote($quoteId, new QuoteUpdate(
                discount: new Discount(DiscountType::Percentage, $offer->price->discountPercent ?? 0.0),
                expiresAt: $expiresAt,
            ), $snapshot->revision);
        }

        $gateway->recalculate($quoteId);

        $after = $gateway->fetchSnapshot($quoteId);
        $violations = $this->verifier->verify(new VerifyOfferInput(
            reference: SnapshotAdapter::toPolicy($snapshot),
            final: SnapshotAdapter::toPolicy($after),
            limits: $limits,
            now: new \DateTimeImmutable(),
        ));

        return new AppliedOffer($violations === [], $violations, $after);
    }

    /**
     * `process` moves the quote to in_review. A retry finds it already there
     * and the machine refuses — which is the correct outcome, not a failure:
     * the transition is bookkeeping and the offer is the substance.
     */
    private function claim(QuoteGatewayInterface $gateway, string $quoteId): void
    {
        try {
            $gateway->transition($quoteId, QuoteTransition::Process);
        } catch (IllegalTransitionException $e) {
            $this->logger->info('The quote was already claimed; continuing with the offer.', [
                'quoteId' => $quoteId,
                'exception' => $e,
            ]);
        }
    }
}
```

`Discount` and `DiscountType` are **`Bridge\Data`**, not `Policy\Data` — there is no `Policy\Data\Discount`, and importing one is a fatal error. Verified: `Discount(DiscountType $type, float $value)` and `DiscountType::{Percentage,Absolute}`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter OfferApplierTest`
Expected: PASS, 6 tests.

`testAVerificationViolationIsReportedRatherThanRolledBack` depends on `OfferVerifier` actually objecting to a 50% cut against a 10% cap. If it returns no violations, read `TotalsOfferVerifier` to find what input it needs (it may require `allowedExtraDiscountNet`) and set the fixture up so the verifier genuinely fires — do not assert an empty violation list and call it passing.

- [ ] **Step 6: Quality gate and commit**

```bash
composer run quality
git add src/Negotiation tests/Unit
git commit -m "feat: apply an offer absolutely, then verify against the database"
```

---

### Task 10: The reply

Model call 3, plus the deterministic template that is both the rules-only path and the hallucination guard.

**Files:**
- Create: `src/Negotiation/ReplyTemplate.php`
- Create: `src/Negotiation/ReplyComposer.php`
- Test: `tests/Unit/Negotiation/ReplyComposerTest.php`

**Interfaces:**
- Consumes: `ChatCompletionClient`, `PromptComposer::reply()`, `BuyerConversation`, `QuoteGatewayInterface::{addComment,transition}`.
- Produces: `ReplyTemplate::compose(float $discountPercent, \DateTimeImmutable $validUntil): string`; `ReplyComposer::__construct(ChatCompletionClient $client, PromptComposer $prompts, LoggerInterface $logger)` and `reply(QuoteGatewayInterface $gateway, BridgeSnapshot $after, QuoteAgentSettings $settings, ProposedOffer $offer, BuyerConversation $conversation): ?string` returning the prompt hash used, or null when the template was used or the reply was skipped.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/ReplyComposerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReplyComposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
    }

    private static function offer(): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));
    }

    private static function composer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): ReplyComposer
    {
        return new ReplyComposer($client, self::prompts(), new NullLogger());
    }

    public function testItWritesTheModelsRewordingAndTransitions(): void
    {
        [$client] = ScriptedClient::spy(['We can offer 5% off, valid until 2026-09-11.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review');

        self::composer($client)->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(tone: 'formal'),
            self::offer(),
            SnapshotAdapter::conversation($after),
        );

        self::assertContains('addComment', $gateway->calls);
        self::assertStringContainsString('5%', $gateway->comments[0]);
        self::assertContains('transition', $gateway->calls);
    }

    public function testARewordingThatDropsTheDiscountFallsBackToTheTemplate(): void
    {
        // The reply prompt's one rule is "keep every fact exactly as given".
        // A reply that lost the number is a hallucination, and it is going to
        // a buyer, so the template wins.
        [$client] = ScriptedClient::spy(['Thanks for your interest! We will be in touch soon.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review');

        $hash = self::composer($client)->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(),
            self::offer(),
            SnapshotAdapter::conversation($after),
        );

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertStringContainsString('5', $gateway->comments[0]);
    }

    public function testRulesOnlyUsesTheTemplateWithNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy([]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review');

        self::composer($client)->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(rulesOnly: true),
            self::offer(),
            SnapshotAdapter::conversation($after),
        );

        self::assertSame(0, $spy->calls);
        self::assertContains('addComment', $gateway->calls);
    }

    public function testAnAlreadyAnsweredQuoteIsNotAnsweredTwice(): void
    {
        // Idempotency: the agent's reply is already newer than the buyer's ask,
        // so a retry must post nothing and pay for nothing.
        [$client, $spy] = ScriptedClient::spy(['would be a duplicate']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
        $after = NegotiationFixture::snapshot(state: 'replied', comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        self::composer($client)->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(),
            self::offer(),
            SnapshotAdapter::conversation($after),
        );

        self::assertSame(0, $spy->calls);
        self::assertNotContains('addComment', $gateway->calls);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter ReplyComposerTest`
Expected: FAIL — the classes do not exist.

- [ ] **Step 3: Write ReplyTemplate**

Create `src/Negotiation/ReplyTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The deterministic reply. Two jobs: it is the whole reply in rules-only mode,
 * and it is the fallback whenever the model's rewording drops a fact.
 *
 * It cannot hallucinate, which is why it is the safe side of that choice — a
 * plainer sentence reaching a buyer is strictly better than a fluent one with
 * the wrong number in it.
 */
final class ReplyTemplate
{
    private function __construct() {}

    public static function compose(float $discountPercent, \DateTimeImmutable $validUntil): string
    {
        return sprintf(
            'We can offer %s%% off this quote. The offer is valid until %s.',
            self::percent($discountPercent),
            $validUntil->format('Y-m-d'),
        );
    }

    /** The figure the guard looks for, formatted once so both sides agree. */
    public static function percent(float $discountPercent): string
    {
        return rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.');
    }
}
```

- [ ] **Step 4: Write ReplyComposer**

Create `src/Negotiation/ReplyComposer.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Model call 3, and the last write of a pass.
 *
 * The comment is posted BEFORE the `sent` transition and both happen before
 * the handler's fingerprint stamp, so a crash anywhere yields a clean retry.
 * The retry is safe because of the guard below: an agent comment already newer
 * than the buyer's newest ask means this pass has been answered, and a second
 * message to a buyer is the one thing a retry must never produce.
 */
final readonly class ReplyComposer
{
    public function __construct(
        private ChatCompletionClient $client,
        private PromptComposer $prompts,
        private LoggerInterface $logger,
    ) {}

    /** @return string|null the reply prompt's hash, or null when the template wrote it */
    public function reply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $after,
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
        BuyerConversation $conversation,
    ): ?string {
        if (!$conversation->hasNewBuyerAsk() && $conversation->agent !== []) {
            $this->logger->info('This quote already carries an agent reply newer than the buyer ask; not answering twice.', [
                'quoteId' => $after->identity->quoteId,
            ]);

            return null;
        }

        $discountPercent = $offer->price->discountPercent ?? 0.0;
        $validUntil = $after->lifecycle->expiresAt ?? new \DateTimeImmutable('+14 days');
        $template = ReplyTemplate::compose($discountPercent, $validUntil);

        [$text, $hash] = $this->reword($settings, $template, $discountPercent, $validUntil);

        $gateway->addComment($after->identity->quoteId, $text);
        $this->send($gateway, $after->identity->quoteId);

        return $hash;
    }

    /** @return array{0: string, 1: string|null} */
    private function reword(
        QuoteAgentSettings $settings,
        string $template,
        float $discountPercent,
        \DateTimeImmutable $validUntil,
    ): array {
        $access = $settings->llm;

        if ($settings->rulesOnly || $access === null) {
            return [$template, null];
        }

        $prompt = $this->prompts->reply($settings);

        try {
            $reworded = trim($this->client->complete($access, $prompt->text, $template, json: false));
        } catch (ModelUnavailable $e) {
            // The offer is already applied. A plainer sentence beats no
            // sentence, so the template ships and the pass still succeeds.
            $this->logger->warning('The reply could not be reworded; sending the template instead.', ['exception' => $e]);

            return [$template, null];
        }

        if (!self::keepsTheFacts($reworded, $discountPercent, $validUntil)) {
            $this->logger->warning('The reworded reply dropped a fact; sending the template instead.', [
                'reworded' => $reworded,
            ]);

            return [$template, null];
        }

        return [$reworded, $prompt->hash];
    }

    /** The prompt's own rule: keep the discount and the date exactly as given. */
    private static function keepsTheFacts(string $reworded, float $discountPercent, \DateTimeImmutable $validUntil): bool
    {
        return $reworded !== ''
            && str_contains($reworded, ReplyTemplate::percent($discountPercent))
            && str_contains($reworded, $validUntil->format('Y-m-d'));
    }

    private function send(QuoteGatewayInterface $gateway, string $quoteId): void
    {
        try {
            $gateway->transition($quoteId, QuoteTransition::Sent);
        } catch (IllegalTransitionException $e) {
            $this->logger->info('The quote could not be moved to replied; the comment stands.', [
                'quoteId' => $quoteId,
                'exception' => $e,
            ]);
        }
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter ReplyComposerTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Quality gate and commit**

```bash
composer run quality
git add src/Negotiation tests/Unit/Negotiation
git commit -m "feat: reply to the buyer, with the template as guard and fallback"
```

---

### Task 11: The pipeline

The orchestrator, and the band classification that gates everything. This is the class that makes an out-of-authority ask cost one model call instead of three.

**Files:**
- Create: `src/Negotiation/NegotiationPipeline.php`
- Create: `src/Negotiation/NegotiationOutcome.php`
- Test: `tests/Unit/Negotiation/NegotiationPipelineTest.php`

**Interfaces:**
- Consumes: every stage from Tasks 3 and 7-10, plus `NegotiationDecider`, `PriceBandClassifier`, `QuoteEscalator`.
- Produces: `NegotiationOutcome` enum with `Offered`, `Countered`, `Escalated`, `NothingToDo`; `NegotiationPipeline implements QuoteServicingPipelineInterface` whose `service()` returns that enum.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/NegotiationPipelineTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;

final class NegotiationPipelineTest extends TestCase
{
    public function testAnInBandAskIsOffered(): void
    {
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off, valid until 2026-09-11."}',
            'We can offer 5% off. Valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(3, $harness->spy->calls);
    }

    public function testAnOutOfAuthorityAskEscalatesAfterOneCall(): void
    {
        // The whole point of the gating: the band already knows 40% is out of
        // reach, so the negotiate and reply calls are never paid for.
        $harness = PipelineHarness::with(['{"additional_discount_percent": 40}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('40% off or no deal', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'An out-of-authority ask must not reach the model twice.');
        self::assertContains('addComment', $harness->gateway->calls, 'The escalation must still reach a human.');
    }

    public function testAnAskInTheCounterBandIsCountered(): void
    {
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 15}',
            '{"action":"offer","discount_percent":10,"message":"We can do 10%, valid until 2026-09-11."}',
            'Our best is 10%. Valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('15% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Countered, $outcome);
    }

    public function testNothingNewCostsNothingAndWritesNothing(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame(0, $harness->spy->calls);
        self::assertSame([], $harness->gateway->calls);
    }

    public function testAStructuralAskEscalatesRatherThanBeingSilentlyDropped(): void
    {
        // Nothing in the policy layer acts on a quantity change or a removal:
        // CommentLineTargets reads lineChanges only for target PRICES, and
        // addProducts is read nowhere at all. Without this guard the agent
        // would answer about price while the buyer's actual ask — more units —
        // vanished. Changing what is being sold is outside a price mandate,
        // and addProduct on a variant product still segfaults the worker (#3).
        $harness = PipelineHarness::with([
            '{"line_changes":[{"line_item_id":"line-1","quantity":20,"target_unit_price":null,"remove":false}]}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('make it 20 units', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A structural ask must not reach the negotiate call.');
    }

    public function testAPerLineTargetPriceIsNotStructuralAndStillNegotiates(): void
    {
        // The discriminator: a line change carrying only a target PRICE is a
        // price ask and squarely in the mandate.
        $harness = PipelineHarness::with([
            '{"line_changes":[{"line_item_id":"line-1","quantity":null,"target_unit_price":95,"remove":false}]}',
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":95}],"message":"95 each."}',
            '95 each, valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertNotSame(NegotiationOutcome::Escalated, $outcome);
    }

    public function testAModelFailureEscalatesRatherThanFallingBackToRules(): void
    {
        $harness = PipelineHarness::with(['this is not json']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertContains('addComment', $harness->gateway->calls);
    }

    public function testAVerificationFailureEscalatesAndLeavesTheChangesInPlace(): void
    {
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"ok"}',
        ], reReadTotalNet: 400.0);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertContains('updateQuote', $harness->gateway->calls, 'The applied changes must not be rolled back.');
    }
}
```

- [ ] **Step 2: Write the harness**

Create `tests/Unit/Negotiation/PipelineHarness.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Policy\PriceBandClassifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use Psr\Log\NullLogger;

/** A fully wired pipeline over a scripted model and a fake gateway. */
final class PipelineHarness
{
    private function __construct(
        public NegotiationPipeline $pipeline,
        public FakeQuoteGateway $gateway,
        public ScriptedClient $spy,
    ) {}

    /** @param list<string> $replies in call order: extract, negotiate, reply */
    public static function with(array $replies, float $reReadTotalNet = 950.0): self
    {
        [$client, $spy] = ScriptedClient::spy($replies);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
        $logger = new NullLogger();

        // Two snapshots: the pre-apply read and the post-apply re-read.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(state: 'in_review', totalNet: $reReadTotalNet),
            NegotiationFixture::snapshot(state: 'in_review', totalNet: $reReadTotalNet),
        ]);

        $pipeline = new NegotiationPipeline(
            new AskInterpreter($client, $prompts),
            new NegotiationDecider(),
            new PriceBandClassifier(),
            new OfferProposer($client, $prompts, new OfferAuthorizer()),
            new OfferApplier(new OfferVerifier(), $logger),
            new ReplyComposer($client, $prompts, $logger),
            new QuoteEscalator(),
            $logger,
        );

        return new self($pipeline, $gateway, $spy);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php vendor/bin/phpunit --filter NegotiationPipelineTest`
Expected: FAIL — `NegotiationPipeline` does not exist.

- [ ] **Step 4: Write the outcome enum**

Create `src/Negotiation/NegotiationOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * What one servicing pass did. The handler reads this to decide whether to
 * clear the escalation marker: an escalated quote must keep its marker, or it
 * re-escalates on every later buyer comment.
 */
enum NegotiationOutcome: string
{
    case Offered = 'offered';
    case Countered = 'countered';
    case Escalated = 'escalated';
    case NothingToDo = 'nothing_to_do';

    public function answeredTheBuyer(): bool
    {
        return $this === self::Offered || $this === self::Countered;
    }
}
```

- [ ] **Step 5: Write the pipeline**

Create `src/Negotiation/NegotiationPipeline.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\PriceBandClassifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use Psr\Log\LoggerInterface;

/**
 * snapshot → interpret → classify → propose → apply/verify → reply.
 *
 * The classification step is a gate, not a formality: an ask the bands put out
 * of authority escalates HERE, before the negotiate and reply calls are paid
 * for, and that holds even when the model is unreachable.
 *
 * Every failure escalates. There is no fall back to rules-only on error — a
 * shop whose negotiation quietly changes character when a provider has a bad
 * minute is the silent behaviour change this design exists to remove.
 */
final readonly class NegotiationPipeline implements QuoteServicingPipelineInterface
{
    public function __construct(
        private AskInterpreter $interpreter,
        private NegotiationDecider $decider,
        private PriceBandClassifier $classifier,
        private OfferProposer $proposer,
        private OfferApplier $applier,
        private ReplyComposer $reply,
        private QuoteEscalator $escalator,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationOutcome {
        try {
            return $this->negotiate($snapshot, $gateway, $settings);
        } catch (ModelUnavailable $e) {
            $this->logger->error('The model was unavailable, so this quote goes to a human.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);

            return $this->escalate($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable);
        }
    }

    /** @throws ModelUnavailable */
    private function negotiate(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationOutcome {
        $conversation = SnapshotAdapter::conversation($snapshot);
        $ask = $this->interpreter->interpret($settings, $snapshot, $conversation);

        if ($ask === null) {
            return NegotiationOutcome::NothingToDo;
        }

        if (self::isStructural($ask->interpretation)) {
            // Changing WHAT is being sold is outside a price-and-validity
            // mandate. Nothing downstream acts on these asks either —
            // CommentLineTargets reads lineChanges only for target prices —
            // so without this guard the buyer's real ask is silently dropped.
            $this->logger->info('The buyer asked to change the quote structurally; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalate($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview);
        }

        $policySnapshot = SnapshotAdapter::toPolicy($snapshot);
        $decision = $this->decider->decide(
            $policySnapshot,
            $settings->policy,
            new NegotiationProposal(price: $ask->interpretation),
        )->price;
        $band = $this->classifier->classify($decision);

        if ($band === Band::Escalate) {
            return $this->escalate($gateway, $snapshot, $decision->escalation?->reason
                ?? QuoteEscalationReason::NeedsHumanReview);
        }

        $answer = $this->proposer->propose($settings, $policySnapshot, $decision, $conversation);

        if ($answer->offer === null) {
            return $this->escalate($gateway, $snapshot, $answer->escalation ?? QuoteEscalationReason::NeedsHumanReview);
        }

        $applied = $this->applier->apply($gateway, $snapshot, $settings, $answer->offer);

        if (!$applied->verified) {
            $this->logger->error('The database disagreed with the offer we applied; escalating.', [
                'quoteId' => $snapshot->identity->quoteId,
                'violations' => $applied->violations,
            ]);

            return $this->escalate($gateway, $applied->after, QuoteEscalationReason::VerificationFailed);
        }

        $this->reply->reply($gateway, $applied->after, $settings, $answer->offer, $conversation);

        return $band === Band::Counter ? NegotiationOutcome::Countered : NegotiationOutcome::Offered;
    }

    /**
     * A line change carrying only a target PRICE is a price ask and in the
     * mandate. A quantity, a removal or an added product is not.
     */
    private static function isStructural(CommentInterpretation $interpretation): bool
    {
        if ($interpretation->structural->addProducts !== []) {
            return true;
        }

        foreach ($interpretation->structural->lineChanges as $change) {
            if ($change->quantity !== null || $change->remove === true) {
                return true;
            }
        }

        return false;
    }

    private function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteEscalationReason $reason,
    ): NegotiationOutcome {
        $this->escalator->escalate($gateway, $snapshot, $reason);

        return NegotiationOutcome::Escalated;
    }
}
```

**The interpretation must reach the decider.** `NegotiationDecider::decide()` takes a `NegotiationProposal` (the BUYER's ask) while `InterpretedAsk` carries a `CommentInterpretation`, so it is wrapped as `new NegotiationProposal(price: $ask->interpretation)` — verified: that constructor is `(?CommentInterpretation $price = null, ?NegotiationAsks $nonPrice = null)`. Passing `null` instead would mean the buyer's ask never reaches the bands, every quote would classify as a 0% request, and every test in this task would fail for that one reason. Add `use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit --filter NegotiationPipelineTest`
Expected: PASS, 8 tests. If `NegotiationPipeline` trips class-scoped cyclomatic complexity, move `negotiate()` into a `NegotiationRun` class holding the same collaborators — do not raise a threshold, and do not merge stages back together.

- [ ] **Step 7: Full suite, quality gate, commit**

```bash
php vendor/bin/phpunit
composer run quality
git add src/Negotiation tests/Unit/Negotiation
git commit -m "feat: the negotiation pipeline, gated on the deterministic band"
```

---

### Task 12: Turn it on

The interface change, the handler's marker fix, and the container wiring — the one commit that makes the agent negotiate.

**Files:**
- Modify: `src/Servicing/QuoteServicingPipelineInterface.php`
- Modify: `src/Servicing/ServiceQuoteHandler.php`
- Modify: `src/Resources/config/services.php`
- Modify: `tests/Unit/Servicing/ServicingHandlerFixture.php`
- Modify: `tests/Unit/Servicing/ServiceQuoteHandlerSettingsTest.php`
- Test: `tests/Unit/Negotiation/NamespacePurityTest.php`

**Interfaces:**
- Produces: `QuoteServicingPipelineInterface::service(...): NegotiationOutcome`. `ServiceQuoteHandler` clears `QuoteEscalator::MARKER_KEY` only when the outcome answered the buyer.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Servicing/ServiceQuoteHandlerSettingsTest.php`:

```php
    /** @throws \Throwable the handler's own declared surface */
    public function testAnEscalatedOutcomeKeepsTheEscalationMarker(): void
    {
        // #28's review found this: clearing the marker on every pass would
        // erase one the pipeline just wrote, and the quote would re-escalate
        // on every later buyer comment.
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::pipelineReturning(NegotiationOutcome::Escalated);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayNotHasKey(
            QuoteEscalator::MARKER_KEY,
            $stamp,
            'An escalated pass must not clear the marker it just wrote.',
        );
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testAnOfferedOutcomeClearsTheEscalationMarker(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::pipelineReturning(NegotiationOutcome::Offered);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayHasKey(QuoteEscalator::MARKER_KEY, $stamp);
        self::assertNull($stamp[QuoteEscalator::MARKER_KEY]);
    }
```

Create `tests/Unit/Negotiation/NamespacePurityTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * src/Negotiation/ must stay Shopware-free apart from the two exceptions the
 * bridge's own contract forces on it. The negotiation engine is the piece most
 * likely to be reused or tested outside a kernel, and an accidental
 * SystemConfigService import would end that quietly.
 */
final class NamespacePurityTest extends TestCase
{
    private const ALLOWED_SHOPWARE_IMPORTS = [
        // IllegalTransitionException is what QuoteGatewayInterface::transition
        // throws; catching it is the documented idempotency guard.
        'Shopware\\Core\\System\\StateMachine\\Exception\\IllegalTransitionException',
    ];

    public function testNothingInNegotiationImportsShopwareBeyondTheAllowedList(): void
    {
        $offenders = [];

        foreach (self::phpFiles(__DIR__ . '/../../../src/Negotiation') as $file) {
            preg_match_all('/^use ([^;]+);/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $import) {
                if (str_starts_with($import, 'Shopware\\') && !\in_array($import, self::ALLOWED_SHOPWARE_IMPORTS, true)) {
                    $offenders[] = basename($file) . ' imports ' . $import;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
```

- [ ] **Step 2: Widen the interface**

In `src/Servicing/QuoteServicingPipelineInterface.php`, change the return type and add to the docblock:

```php
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationOutcome;
```

```
 * The outcome comes back because the handler cannot infer it. #5 clears the
 * escalation marker on a successful pass — correct while nothing else could
 * escalate, and wrong the moment this pipeline can: clearing a marker the
 * pipeline just wrote makes the quote re-escalate on every later comment.
```

- [ ] **Step 3: Fix the handler's marker clear**

In `src/Servicing/ServiceQuoteHandler.php`, capture the outcome and make the clear conditional:

```php
        $outcome = $pipeline->service($snapshot, $gateway, $settings);
```

and in the success write, replace the unconditional `QuoteEscalator::MARKER_KEY => null` with a merged array:

```php
        $after = $gateway->fetchSnapshot($message->quoteId);

        $customFields = [
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::stamp(
                $snapshot,
                $after->lifecycle->stateTechnicalName,
            ),
            self::ATTEMPTS_KEY => null,
        ];

        if ($outcome->answeredTheBuyer()) {
            // Only a pass that actually answered clears the escalation marker.
            // An escalated pass must keep it, or the next buyer comment
            // escalates the same quote again.
            $customFields[QuoteEscalator::MARKER_KEY] = null;
        }

        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: $customFields));
```

The fingerprint is still stamped on every outcome: the ask *was* handled, even when the answer was to escalate.

- [ ] **Step 4: Update the test fixture**

In `tests/Unit/Servicing/ServicingHandlerFixture.php`, every anonymous pipeline must return an outcome. Change `countingPipeline()` to return `NegotiationOutcome::Offered` and add:

```php
    public static function pipelineReturning(NegotiationOutcome $outcome): QuoteServicingPipelineInterface
    {
        return new class($outcome) implements QuoteServicingPipelineInterface {
            public function __construct(private readonly NegotiationOutcome $outcome) {}

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): NegotiationOutcome {
                return $this->outcome;
            }
        };
    }
```

Every other anonymous `QuoteServicingPipelineInterface` in the suite — in `ServiceQuoteHandlerTest`, `ServiceQuoteHandlerSettingsTest`, `ServicingReentrancyTest`, `ServicingCrashBudgetTest`, `ServicingConfigGateTest` — needs `: NegotiationOutcome` and a `return NegotiationOutcome::Offered;`. Find them all with `grep -rn "implements QuoteServicingPipelineInterface" tests/`.

- [ ] **Step 5: Wire the container**

In `src/Resources/config/services.php`, inside the `CommercialAvailability::isAvailableByClass()` guard, after the servicing block:

```php
    // Negotiation (issue #18). The prompts are read here, at container compile,
    // and handed in as strings — src/Negotiation/ never touches the filesystem,
    // which is what keeps it Shopware-free and path-free.
    $promptDir = \dirname(__DIR__, 3) . '/config/agents/';
    $services->set(PromptComposer::class)->args([
        file_get_contents($promptDir . 'quote-extract-agent.prompt.md'),
        file_get_contents($promptDir . 'quote-negotiate-agent.prompt.md'),
        file_get_contents($promptDir . 'quote-reply-agent.prompt.md'),
    ]);

    $services->set(GuzzleClient::class);
    $services->set(ChatCompletionClient::class)->args([service(GuzzleClient::class), service('logger')]);

    $services->set(NegotiationDecider::class);
    $services->set(PriceBandClassifier::class);
    $services->set(OfferAuthorizer::class);
    $services->set(OfferVerifier::class);
    $services->set(AskInterpreter::class);
    $services->set(OfferProposer::class);
    $services->set(OfferApplier::class)->args([service(OfferVerifier::class), service('logger')]);
    $services->set(ReplyComposer::class)->args([
        service(ChatCompletionClient::class),
        service(PromptComposer::class),
        service('logger'),
    ]);

    $services->set(NegotiationPipeline::class)->args([
        service(AskInterpreter::class),
        service(NegotiationDecider::class),
        service(PriceBandClassifier::class),
        service(OfferProposer::class),
        service(OfferApplier::class),
        service(ReplyComposer::class),
        service(QuoteEscalator::class),
        service('logger'),
    ]);

    // The one line that turns the agent on.
    $services->alias(QuoteServicingPipelineInterface::class, NegotiationPipeline::class);
```

with `use GuzzleHttp\Client as GuzzleClient;` and the `MerchantQuoteAgentPlugin\Negotiation\*` and `MerchantQuoteAgentPlugin\Policy\*` imports. Verify the `$promptDir` path resolves — `services.php` lives at `src/Resources/config/`, so three levels up is the plugin root; if `file_get_contents` returns false the container will fail loudly at compile, which is the right time to find out.

- [ ] **Step 6: Run everything**

```bash
php vendor/bin/phpunit
composer run test:integration
composer run quality
```
Expected: all green. The integration suite now runs a REAL pipeline against the live shop for the first time — but its `setUp()` supplies a fake API key, so any test that triggers a real pass will fail at the model call. Where that happens, register `ServicingHandlerFixture::pipelineReturning(...)` in the test rather than the real pipeline, or assert the escalation the unreachable model produces. Do not add a real API key to the suite.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: register the negotiation pipeline and keep escalation markers"
```

---

### Task 13: Prove it against the live shop, and document it

**Files:**
- Create: `tests/Integration/NegotiationPipelineTest.php`
- Create: `tests/Integration/LiveModelSmokeTest.php`
- Modify: `README.md`

- [ ] **Step 1: Write the integration test**

Create `tests/Integration/NegotiationPipelineTest.php`. It resolves the real pipeline's collaborators from the container but substitutes a scripted `ChatCompletionClient`, so the writes, the verifier and the transitions are real and no API key is needed:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use Shopware\Core\Framework\Context;

/**
 * The pipeline against real quotes, real writes and the real verifier. Only
 * the model is scripted — everything else is the shop.
 */
final class NegotiationPipelineTest extends IntegrationTestCase
{
    public function testAnInBandAskIsAppliedToTheQuoteAndAnswered(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $before = $gateway->fetchSnapshot($quoteId);
        $commentsBefore = \count($before->content->comments);

        // A buyer ask has to exist for the pass to do anything.
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $pipeline = self::pipelineWith([
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ]);

        $outcome = $pipeline->service($gateway->fetchSnapshot($quoteId), $gateway, self::enabledSettings());

        self::assertSame(NegotiationOutcome::Offered, $outcome);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertGreaterThan($commentsBefore, \count($after->content->comments), 'The buyer was never answered.');
        self::assertFalse($after->revision->matches($before->revision), 'Nothing was written to the quote.');
    }

    public function testAnOutOfAuthorityAskEscalatesWithoutTouchingPrices(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'I need 40% off.');
        $before = $gateway->fetchSnapshot($quoteId);

        $pipeline = self::pipelineWith(['{"additional_discount_percent": 40}']);

        $outcome = $pipeline->service($gateway->fetchSnapshot($quoteId), $gateway, self::enabledSettings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(
            $before->totals->totalNet,
            $gateway->fetchSnapshot($quoteId)->totals->totalNet,
            'An escalated ask must not move the price.',
        );
    }

    public function testTheSamePassTwiceAnswersOnce(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $replies = [
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ];

        self::pipelineWith($replies)->service($gateway->fetchSnapshot($quoteId), $gateway, self::enabledSettings());
        $afterFirst = \count($gateway->fetchSnapshot($quoteId)->content->comments);

        self::pipelineWith($replies)->service($gateway->fetchSnapshot($quoteId), $gateway, self::enabledSettings());

        self::assertSame(
            $afterFirst,
            \count($gateway->fetchSnapshot($quoteId)->content->comments),
            'A re-run posted a second message to the buyer.',
        );
    }
}
```

Write the three helpers this needs — `self::writeBuyerComment()` (a `quote_comment.repository` create with a real `customerId`, as `ServicingTriggerTest` already does), `self::enabledSettings()` (a `QuoteAgentSettings` built directly, not read from config), and `self::pipelineWith(array $replies)` (resolve `NegotiationDecider`, `PriceBandClassifier`, `OfferAuthorizer`, `OfferVerifier` and `QuoteEscalator` from the container, construct the four model-facing stages around `ScriptedClient::returning($replies)`, and assemble a `NegotiationPipeline`). Put them at the bottom of the class, private and static.

- [ ] **Step 2: Write the opt-in live test**

Create `tests/Integration/LiveModelSmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use MerchantQuoteAgentPlugin\Negotiation\Response\ExtractResponse;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The only test that talks to a real provider, and the only thing that would
 * catch one changing its response shape. Opt-in: it must never gate CI or the
 * stress run, and it costs real money each time it runs.
 *
 *   QUOTE_AGENT_LIVE_KEY=sk-... QUOTE_AGENT_LIVE_MODEL=gpt-4o-mini \
 *     composer run test:integration -- --filter LiveModelSmokeTest
 */
final class LiveModelSmokeTest extends TestCase
{
    public function testARealProviderReturnsJsonOurReaderCanParse(): void
    {
        $key = getenv('QUOTE_AGENT_LIVE_KEY');
        $model = getenv('QUOTE_AGENT_LIVE_MODEL');

        if (!\is_string($key) || $key === '' || !\is_string($model) || $model === '') {
            self::markTestSkipped('Set QUOTE_AGENT_LIVE_KEY and QUOTE_AGENT_LIVE_MODEL to run this.');
        }

        $client = new ChatCompletionClient(new Client(), new NullLogger());
        $prompt = (string) file_get_contents(__DIR__ . '/../../config/agents/quote-extract-agent.prompt.md');

        $answer = $client->complete(
            new ModelAccess($key, getenv('QUOTE_AGENT_LIVE_BASE_URL') ?: 'https://api.openai.com/v1', $model),
            $prompt,
            "Line items:\nline-1 | Widget | 10 | 100.00\n\nBuyer comments:\nCould you do 5% off?",
            json: true,
        );

        // Asserting the SHAPE parses, never what the model decided — the
        // model's judgement is #21's question, not a unit test's.
        $interpretation = ExtractResponse::toInterpretation($answer);

        self::assertNotNull($interpretation->price);
    }
}
```

- [ ] **Step 3: Run everything**

```bash
php vendor/bin/phpunit
composer run test:integration
composer run quality
```
Expected: unit green; integration green with `LiveModelSmokeTest` reported as skipped; quality exit 0.

- [ ] **Step 4: Document it**

Add to `README.md`, after the "Configuring the agent" section:

```markdown
## How the agent negotiates

Each servicing pass runs six stages: read the quote, interpret the buyer's ask,
classify it against the merchant's bands, propose an offer, apply and verify it,
then reply.

**The bands decide what is permitted; the model decides how much within it.**
An ask above the counter-offer ceiling escalates to a human *before* any
proposal is requested — so an out-of-authority ask costs one model call rather
than three, and it escalates even when the model is unreachable. A proposal
that comes back outside authority is rejected by the policy layer regardless of
what the merchant's strategy prompt asked for.

**Up to three model calls per pass**, each with its own prompt under
`config/agents/`: extract (read the ask), negotiate (choose the offer), reply
(word it). A re-trigger with nothing new since the agent's last reply makes
none of them.

**Every failure escalates.** A model that cannot be reached, a proposal outside
authority, or a verifier that disagrees with what actually landed in the
database all send the quote to a human. The agent never falls back to deciding
deterministically when the model fails — that would quietly change how your
shop negotiates.

**A verification failure leaves the applied changes in place.** Rolling back is
a write that can itself fail, and a failed rollback leaves the quote in a third
state nobody intended. The escalation tells a human what the database actually
says.

Prices, discounts and expiry dates are written as absolute values, so a worker
that dies mid-pass and retries produces the same quote rather than stacking a
second discount on the first — and the buyer is never messaged twice.
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test: prove the negotiation pipeline against the live shop"
```

---

## Handover

State these in the pull request:

- `QuoteServicingPipelineInterface::service()` returns `NegotiationOutcome`. Anything implementing it must say what it did, because the handler cannot infer whether to clear the escalation marker.
- **#4's last Done-when is closed** — a quote can no longer be left in `in_review` by a race, because an interrupted pass is completed by its retry rather than abandoned.
- **#11 is now load-bearing.** Non-price *grants* escalate because `QuoteUpdate` cannot persist delivery, payment or bundle terms. Every such ask goes to a human until #11 decides.
- **#19 has its data.** Each pass emits one structured outcome event carrying the three prompt hashes; persisting it is #19's schema decision.
- **#29 can start.** Dry-run now has an offer to prepare and withhold.
- Rules-only mode requires an API key. The model reads; it never decides or writes.
