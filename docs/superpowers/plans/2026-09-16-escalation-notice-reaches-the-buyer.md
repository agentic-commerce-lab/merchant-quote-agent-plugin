# Escalation Notice Reaches the Buyer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `NotConfigured` escalation tells the buyer, instead of being silenced by the very misconfiguration that triggered it.

**Architecture:** `notifyBuyerOnEscalation` stops being read through `QuoteAgentSettingsFactory` (which throws before it constructs anything when the LLM credentials are missing) and is read as a single raw config key through a new one-method interface, `BuyerNotificationPreference`, implemented by `QuoteAgentSettingsReader`. `QuoteEscalator` depends on that interface instead of `QuoteAgentSettingsSource`, and `shouldNotifyBuyer()` loses its try/catch because nothing in it can throw any more.

**Tech Stack:** PHP 8.3, Shopware 6.7 plugin, PHPUnit 11, mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-09-16-escalation-notice-reaches-the-buyer-design.md`

## Global Constraints

- PHP 8.3, `declare(strict_types=1)` in every file.
- `#[\Override]` on every interface implementation — the codebase uses it everywhere and mago lints for it.
- Mago's 5-constructor-parameter cap applies; nothing here adds a parameter.
- `composer run quality` runs `mago fmt --check`, `mago lint`, `mago analyze`, a file-length check, `jscpd`, and `composer-dependency-analyser`. Run it before the final commit.
- Commit messages end with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Use `git commit --no-gpg-sign`.
- **Buyer-facing copy is unchanged.** `QuoteEscalator::BUYER_MESSAGE` is a fixed constant and no reason value, field name or problem list may reach it.
- The unset / non-boolean value of `notifyBuyerOnEscalation` means **notify**. The rule is `!== false`, matching `QuoteAgentSettingsFactory` (`RawConfigValue::bool($raw, 'notifyBuyerOnEscalation') !== false`) and `config.xml`'s `<defaultValue>true</defaultValue>`. Only an explicit boolean `false` means silence.
- Integration tests run against the shared `merchant-quote-shop` container via `composer run test:integration`. Other agents use it concurrently: never mutate it outside a test transaction, and re-run once before concluding a failure is real.

---

### Task 1: `BuyerNotificationPreference`, readable while the configuration is invalid

**Files:**
- Create: `src/Config/BuyerNotificationPreference.php`
- Modify: `src/Config/QuoteAgentSettingsReader.php` (class declaration, plus one new method after `forSalesChannel()`)
- Test: `tests/Unit/Config/QuoteAgentSettingsReaderTest.php`

**Interfaces:**
- Consumes: `QuoteAgentSettingsReader::DOMAIN` (`'MerchantQuoteAgentPlugin.config.'`), already defined.
- Produces: `MerchantQuoteAgentPlugin\Config\BuyerNotificationPreference` with the single method `notifyBuyerOnEscalation(?string $salesChannelId): bool`, implemented by `QuoteAgentSettingsReader`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Config/QuoteAgentSettingsReaderTest.php`. The existing
private `reader(array $overrides = [], ...)` helper builds the reader against a
mocked `SystemConfigService`; reuse it exactly as the other tests do.

```php
    public function testBuyerNotificationDefaultsToTellingTheBuyer(): void
    {
        self::assertTrue($this->reader()->notifyBuyerOnEscalation(null));
    }

    public function testBuyerNotificationIsSilentOnlyWhenExplicitlyFalse(): void
    {
        self::assertFalse($this->reader(['notifyBuyerOnEscalation' => false])->notifyBuyerOnEscalation(null));
        self::assertTrue($this->reader(['notifyBuyerOnEscalation' => true])->notifyBuyerOnEscalation(null));
    }

    /**
     * The regression this whole change exists for (#140). A NotConfigured
     * escalation is raised FROM forSalesChannel() throwing, and then asks
     * whether to tell the buyer. That second question must still have an
     * answer.
     */
    public function testBuyerNotificationAnswersWhileTheConfigurationIsUnusable(): void
    {
        $reader = $this->reader(['llmApiKey' => '', 'llmModel' => null]);

        try {
            $reader->forSalesChannel(null);
            self::fail('The fixture configuration should be unusable.');
        } catch (InvalidQuoteAgentConfiguration) {
            // expected
        }

        self::assertTrue($reader->notifyBuyerOnEscalation(null));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter QuoteAgentSettingsReaderTest`
Expected: FAIL — `Call to undefined method ... ::notifyBuyerOnEscalation()`.

- [ ] **Step 3: Create the interface**

`src/Config/BuyerNotificationPreference.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Whether an escalation may post its notice into the buyer's quote
 * conversation — and nothing else.
 *
 * Separate from QuoteAgentSettingsSource because it must be answerable when
 * that one cannot answer. A NotConfigured escalation is raised from
 * `forSalesChannel()` throwing InvalidQuoteAgentConfiguration; asking the same
 * source whether to tell the buyer throws again, and #140 is what that cost:
 * every misconfigured shop escalated in total silence, because the second
 * throw was read as "the merchant asked for silence".
 *
 * This toggle depends on none of what the factory validates: not the LLM
 * credentials, not the policy numbers. So it is read raw, one key, no
 * validation, and it cannot fail.
 */
interface BuyerNotificationPreference
{
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool;
}
```

- [ ] **Step 4: Implement it on the reader**

In `src/Config/QuoteAgentSettingsReader.php`, change the class declaration:

```php
final readonly class QuoteAgentSettingsReader implements QuoteAgentSettingsSource, BuyerNotificationPreference
```

and add this method immediately after `forSalesChannel()`:

```php
    /**
     * Read raw and never validated, which is the whole point: see
     * BuyerNotificationPreference. `!== false` rather than `=== true` so an
     * unset key means notify, matching config.xml's defaultValue and the
     * identical rule in QuoteAgentSettingsFactory — only an explicit false
     * silences the notice.
     */
    #[\Override]
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool
    {
        return $this->config->get(self::DOMAIN . 'notifyBuyerOnEscalation', $salesChannelId) !== false;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `composer run test -- --filter QuoteAgentSettingsReaderTest`
Expected: PASS, all tests in the class.

- [ ] **Step 6: Commit**

```bash
git add src/Config/BuyerNotificationPreference.php src/Config/QuoteAgentSettingsReader.php tests/Unit/Config/QuoteAgentSettingsReaderTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
feat(config): read the buyer-notice toggle without validating the rest

notifyBuyerOnEscalation depends on none of what the settings factory
validates, so routing it through a factory that throws on a missing API
key made it unreadable in the one state that needs it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `QuoteEscalator` asks the preference, not the settings

**Files:**
- Create: `tests/Unit/Servicing/FakeBuyerNotification.php`
- Modify: `src/Servicing/QuoteEscalator.php:60-131` (constructor, `escalate()`'s ordering docblock, `shouldNotifyBuyer()`)
- Modify: `src/Resources/config/services.php:736-739`
- Test: `tests/Unit/Servicing/QuoteEscalatorTest.php`

**Interfaces:**
- Consumes: `BuyerNotificationPreference::notifyBuyerOnEscalation(?string): bool` from Task 1.
- Produces:
  - `QuoteEscalator::__construct(?EscalationNotifierInterface $notifier = null, ?BuyerNotificationPreference $buyerNotification = null)` — the second parameter is renamed from `$settingsSource` and retyped; every named-argument call site becomes `new QuoteEscalator(buyerNotification: ...)`.
  - `MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeBuyerNotification` with a public mutable `bool $notify` and the interface method, used by Tasks 3 and 4.

- [ ] **Step 1: Write the test double**

`tests/Unit/Servicing/FakeBuyerNotification.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\BuyerNotificationPreference;

/** The merchant's buyer-notice toggle, flippable mid-test. */
final class FakeBuyerNotification implements BuyerNotificationPreference
{
    public function __construct(
        public bool $notify = true,
    ) {}

    #[\Override]
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool
    {
        return $this->notify;
    }
}
```

- [ ] **Step 2: Write the failing tests**

In `tests/Unit/Servicing/QuoteEscalatorTest.php`, **delete**
`testItFallsBackToSilentWhenSettingsSourceThrows` entirely — it pins the
defect — and **replace** `testItResolvesBuyerNotificationFromSettingsSource`
(the whole method, including its anonymous `QuoteAgentSettingsSource` class)
with these two:

```php
    public function testItResolvesBuyerNotificationFromTheMerchantsToggle(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $preference = new FakeBuyerNotification(notify: false);
        $escalator = new QuoteEscalator(buyerNotification: $preference);

        $escalator->escalate($gateway, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['updateQuote'], $gateway->calls);

        $gateway2 = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $preference->notify = true;
        $escalator->escalate($gateway2, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['addComment', 'updateQuote'], $gateway2->calls);
    }

    /**
     * #140. A NotConfigured escalation exists BECAUSE the configuration is
     * unusable, so the toggle must be readable without it. The preference is
     * read raw and cannot throw; this pins that the escalator no longer
     * swallows an unreadable configuration into silence.
     */
    public function testAMisconfiguredShopStillTellsTheBuyer(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator(buyerNotification: new FakeBuyerNotification()))->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }
```

Also rename `testItDefaultsToSilentWhenNoSettingsSourceProvided` to
`testItDefaultsToSilentWhenNoPreferenceIsWired` — its body is unchanged, it
constructs `new QuoteEscalator()` with no arguments.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `composer run test -- --filter QuoteEscalatorTest`
Expected: FAIL — `Unknown named parameter $buyerNotification`.

- [ ] **Step 4: Retype the collaborator**

In `src/Servicing/QuoteEscalator.php`:

Replace the import `use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;` with
`use MerchantQuoteAgentPlugin\Config\BuyerNotificationPreference;`, and delete
`use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;` (nothing
catches it here any more).

Constructor:

```php
    /**
     * The notifier and the buyer-notice preference are optional so existing
     * construction sites and tests keep working; with no preference wired the
     * escalation is silent toward the buyer.
     */
    public function __construct(
        private readonly ?EscalationNotifierInterface $notifier = null,
        private readonly ?BuyerNotificationPreference $buyerNotification = null,
    ) {}
```

`shouldNotifyBuyer()` in full — the try/catch goes, because a raw single-key
read cannot fail:

```php
    private function shouldNotifyBuyer(?string $salesChannelId): bool
    {
        return $this->buyerNotification?->notifyBuyerOnEscalation($salesChannelId) ?? false;
    }
```

- [ ] **Step 5: Record why the comment still precedes the marker**

Still in `src/Servicing/QuoteEscalator.php`, replace the comment block that
currently begins `// After the buyer is told (if enabled) and the marker is
stamped, never before:` with:

```php
        // After the buyer is told (if enabled) and the marker is stamped, never before: the
        // marker's early return above is what makes this once per quote per
        // reason, and a notifier that throws must not cost the buyer their
        // comment. Guarded even though the contract forbids throwing — an
        // implementation that forgets must not break escalation.
        //
        // The comment/marker order above is deliberate too, and #140 asked it
        // to be reversed. It stays. If updateQuote throws after addComment
        // succeeded, the next pass comments again — the buyer hears it twice.
        // Reversed, an addComment that throws after the marker was stamped is
        // suppressed by that marker on every later pass, and the buyer is
        // never told at all, silently, forever. For a notice whose whole
        // purpose is that the buyer is not left in silence, failing toward
        // "said twice" is the right way round.
```

- [ ] **Step 6: Rewire the container**

In `src/Resources/config/services.php`, change the `QuoteEscalator` definition
(leave the `ServicingPreflight` definition below it exactly as it is):

```php
    $services->set(QuoteEscalator::class)->args([
        service(EscalationNotifierInterface::class),
        service(BuyerNotificationPreference::class),
    ]);
```

Add the alias next to the existing `QuoteAgentSettingsSource` one, right after
`$services->alias(QuoteAgentSettingsSource::class, QuoteAgentSettingsReader::class);`:

```php
    $services->alias(BuyerNotificationPreference::class, QuoteAgentSettingsReader::class);
```

Add `use MerchantQuoteAgentPlugin\Config\BuyerNotificationPreference;` to the
file's imports, in alphabetical order among the other `Config` imports.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `composer run test -- --filter QuoteEscalatorTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Servicing/QuoteEscalator.php src/Resources/config/services.php tests/Unit/Servicing/QuoteEscalatorTest.php tests/Unit/Servicing/FakeBuyerNotification.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
fix(servicing): tell the buyer when the shop is misconfigured (#140)

shouldNotifyBuyer() asked the settings source the toggle's value and
read InvalidQuoteAgentConfiguration as "the merchant wants silence" —
but a NotConfigured escalation is raised from that same exception, so
the notice it exists to send could never be sent. It now asks the raw
preference, which has an answer in every state.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The remaining construction sites, and the preflight test that lied

**Files:**
- Modify: `tests/Unit/Servicing/ServicingSettingsFixture.php:41-60`
- Modify: `tests/Unit/Servicing/ServicingPreflightTest.php:39-90`
- Modify: `tests/Unit/Negotiation/PipelineHarness.php:32,108-117,152`
- Modify: `tests/Unit/Negotiation/ClarificationRoundTest.php:51-52`
- Modify: `tests/Unit/Negotiation/NegotiationPipelineTest.php:76-78`

**Interfaces:**
- Consumes: `FakeBuyerNotification` and the retyped `QuoteEscalator` constructor from Task 2.
- Produces: `ServicingSettingsFixture::preflight(\Closure $outcome, ?QuoteEscalator $escalator = null): ServicingPreflight` — signature unchanged; only the escalator it builds by default changes. `PipelineHarness::$buyerNotification` (public, `FakeBuyerNotification`), replacing `PipelineHarness::$settingsSource`.

- [ ] **Step 1: Fix the fixture's default escalator**

In `tests/Unit/Servicing/ServicingSettingsFixture.php`, the default escalator is
currently `new QuoteEscalator(settingsSource: $source)`, where `$source` is the
same closure-backed source the preflight gets. Replace that one expression with:

```php
            $escalator ?? new QuoteEscalator(buyerNotification: new FakeBuyerNotification(notify: false)),
```

and add `use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeBuyerNotification;`
— or no import at all, since the fixture already lives in that namespace.

- [ ] **Step 2: Make the preflight test exercise the production wiring**

In `tests/Unit/Servicing/ServicingPreflightTest.php`:

Rename `testAMisconfiguredChannelEscalatesTheQuoteQuietlyByDefaultAndReturnsNull`
to `testAMisconfiguredChannelWithBuyerNotificationOffEscalatesSilentlyAndReturnsNull`
and change only its final assertion message, from
`'Misconfigured channel escalates quietly by default.'` to
`'A merchant who turned the buyer notice off gets no comment.'`. Its body is
otherwise unchanged: the fixture now wires a `notify: false` preference, which
is what that name claims.

Replace the whole of
`testAMisconfiguredChannelWithBuyerNotificationEnabledWritesCommentAndMarksQuote`
with this. The old version handed the preflight a source that throws and the
escalator a *different*, non-throwing source returning valid settings — a
wiring `services.php` cannot produce, so it asserted nothing about production.

```php
    /**
     * #140. In services.php the preflight's settings source and the
     * escalator's collaborator are both the same QuoteAgentSettingsReader, and
     * this path is reached BECAUSE forSalesChannel() threw. The old version of
     * this test gave the escalator a second, non-throwing settings source and
     * so asserted a wiring that cannot exist. Two collaborators, two
     * questions, and the second one answerable while the first throws.
     */
    public function testAMisconfiguredChannelWithBuyerNotificationOnCommentsAndMarksTheQuote(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $escalator = new QuoteEscalator(buyerNotification: new FakeBuyerNotification());

        $result = ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        }, $escalator)->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
        self::assertStringNotContainsString('API key', implode("\n", $gateway->comments));
    }
```

The file's remaining imports are already present (`InvalidQuoteAgentConfiguration`,
`QuoteAgentSettings`, `QuoteEscalator`); the long inline
`\MerchantQuoteAgentPlugin\Config\...` and `\MerchantQuoteAgentPlugin\Policy\Data\...`
references disappear with the old body.

- [ ] **Step 3: Retype the pipeline harness**

In `tests/Unit/Negotiation/PipelineHarness.php`:

- Line 32: replace `public ?QuoteAgentSettingsSource $settingsSource = null;` with
  `public ?FakeBuyerNotification $buyerNotification = null;`
- Lines 108-117: delete the anonymous `QuoteAgentSettingsSource` class and its
  `$settingsSource` variable; replace with

```php
        $buyerNotification = new FakeBuyerNotification(notify: false);
        $escalator = new QuoteEscalator(buyerNotification: $buyerNotification);
```

- Line 152: replace `$harness->settingsSource = $settingsSource;` with
  `$harness->buyerNotification = $buyerNotification;`
- Imports: drop `QuoteAgentSettingsSource`; add
  `use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeBuyerNotification;`
  next to the existing `FakeQuoteGateway` import. Drop
  `use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;` only if nothing else
  in the file still uses it — check before deleting.

- [ ] **Step 4: Update the two harness consumers**

In `tests/Unit/Negotiation/ClarificationRoundTest.php`, replace lines 51-52:

```php
        if ($harness->buyerNotification !== null) {
            $harness->buyerNotification->notify = true;
        }
```

In `tests/Unit/Negotiation/NegotiationPipelineTest.php`, replace lines 77-78 the
same way:

```php
        if ($harness->buyerNotification !== null) {
            $harness->buyerNotification->notify = true;
        }
```

`NegotiationPipelineTest:76` builds `$settings = NegotiationFixture::settings(notifyBuyerOnEscalation: true);`
for its own use — leave that line alone; only the two lines that reached through
`$harness->settingsSource` change.

- [ ] **Step 5: Run the whole unit suite**

Run: `composer run test`
Expected: PASS, no errors, no risky tests.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit
git commit --no-gpg-sign -m "$(cat <<'EOF'
test(servicing): stop the preflight test asserting an impossible wiring

testAMisconfiguredChannelWithBuyerNotificationEnabled... handed the
preflight a settings source that throws and the escalator a second one
that does not, so it passed whether or not a misconfigured shop tells
the buyer — the #81/#87 shape. Both now get the collaborator
services.php actually gives them.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: The integration gate goes green

**Files:**
- Modify: `tests/Integration/ServicingConfigGateTest.php:53-79`

**Interfaces:**
- Consumes: everything from Tasks 1-3, wired through the real container
  (`IntegrationTestCase::preflight()` resolves `ServicingPreflight` from
  `services.php`, so the escalator it holds is the container's).
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Make the test set the toggle it depends on**

In `testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly`, add a third
`$config->set(...)` next to the two that are already there:

```php
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');
        // Named rather than left to the shop's stored value: this test asserts
        // the buyer IS told, and a merchant who turned the notice off on this
        // shop would otherwise make it red for a reason that is not a bug.
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'notifyBuyerOnEscalation', true);
```

Nothing else in the test changes. The two handler invocations and the
`$before + 1` assertion stay exactly as they are — with a first comment now
actually written, the second invocation is what proves the marker guard
suppresses it, which is what the test's name has always claimed.

- [ ] **Step 2: Run the integration test to verify it passes**

Run: `composer run test:integration -- --filter ServicingConfigGateTest`
Expected: `OK` — 2 tests. If it fails, re-run once before investigating: other
agents share the container.

- [ ] **Step 3: Run the full gates**

```bash
composer run test
composer run quality
composer run test:integration
```

Expected: all three green. `composer run quality` includes `mago fmt --check`;
if it reports formatting, run `composer run format` and re-run.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/ServicingConfigGateTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
test(integration): name the buyer-notice toggle the gate test relies on

The test asserts the buyer is told once; with the toggle left to the
shop's stored value it was red on a correctly-configured shop and could
not have caught a marker-guard regression either, having never written a
first comment for a second one to duplicate.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage:**
- Decision 1 (read the toggle without validating) → Tasks 1 and 2.
- Decision 2 (comment before marker stays, argument recorded) → Task 2 Step 5.
- Decision 3 (marker stays inside `escalate()`) → no task; nothing is changed, which is the decision.
- Decision 4 (integration test sets its toggle) → Task 4.
- Decision 5 (the two lying unit tests) → Task 2 Step 2 (`QuoteEscalatorTest`) and Task 3 Step 2 (`ServicingPreflightTest`).
- Testing 1-4 → Task 2, Task 1, Task 3, Task 4 respectively.

**Type consistency:** `notifyBuyerOnEscalation(?string $salesChannelId): bool` is
spelled identically in the interface (Task 1 Step 3), the reader (Task 1 Step 4),
the fake (Task 2 Step 1) and every call. The constructor parameter is
`$buyerNotification` in the definition (Task 2 Step 4) and in all five named-argument
call sites (Tasks 2 and 3). `FakeBuyerNotification::$notify` is the only mutable
property and is written in Tasks 3 Step 4 exactly as declared in Task 2 Step 1.

**Placeholders:** none.
