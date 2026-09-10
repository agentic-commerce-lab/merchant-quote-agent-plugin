# Silent Escalation Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a toggle in plugin configuration (`notifyBuyerOnEscalation`, boolean, default false) allowing merchants to escalate quotes without sending an automated comment to the buyer.

**Architecture:** The toggle is stored in Shopware's `system_config` under `config.xml` (Escalation card) and read via `QuoteAgentSettingsReader` into `QuoteAgentSettings`. `QuoteEscalator` injects `QuoteAgentSettingsSource` to resolve the toggle per sales channel and suppresses the buyer comment (`$gateway->addComment`) when disabled, while retaining the escalation marker update and internal notifier.

**Tech Stack:** PHP 8.3, Shopware 6.7, Symfony DI & Validator, PHPUnit, Mago.

---

### Task 1: Add `notifyBuyerOnEscalation` Configuration Field to `config.xml`

**Files:**
- Modify: `src/Resources/config/config.xml`
- Test: `tests/Integration/ServicingConfigGateTest.php`

- [ ] **Step 1: Write the failing integration test in `tests/Integration/ServicingConfigGateTest.php`**

Verify that `notifyBuyerOnEscalation` exists in `config.xml` schema and can be read through `SystemConfigService`.

```php
    public function testNotifyBuyerOnEscalationDefaultsToFalse(): void
    {
        $config = $this->getContainer()->get(SystemConfigService::class);
        $value = $config->get('MerchantQuoteAgentPlugin.config.notifyBuyerOnEscalation');

        self::assertFalse($value);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/ServicingConfigGateTest.php --filter testNotifyBuyerOnEscalationDefaultsToFalse`
Expected: FAIL because key does not exist in `config.xml`.

- [ ] **Step 3: Add the Escalation card to `src/Resources/config/config.xml`**

Add the new card before or after `Negotiation policies`:

```xml
    <card>
        <title>Escalation</title>
        <input-field type="bool">
            <name>notifyBuyerOnEscalation</name>
            <label>Notify buyer when escalated to a human</label>
            <defaultValue>false</defaultValue>
            <helpText>When enabled, posts a storefront comment informing the buyer that their quote has been passed to a team member for manual review. When disabled, escalates silently without sending an automated comment to the buyer.</helpText>
        </input-field>
    </card>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/ServicingConfigGateTest.php --filter testNotifyBuyerOnEscalationDefaultsToFalse`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Resources/config/config.xml tests/Integration/ServicingConfigGateTest.php
git commit --no-gpg-sign -m "feat(config): add notifyBuyerOnEscalation toggle to config.xml"
```

---

### Task 2: Update `QuoteAgentSettings`, `QuoteAgentSettingsReader`, and `QuoteAgentSettingsFactory`

**Files:**
- Modify: `src/Config/QuoteAgentSettings.php`
- Modify: `src/Config/QuoteAgentSettingsReader.php`
- Modify: `src/Config/QuoteAgentSettingsFactory.php`
- Test: `tests/Unit/Config/QuoteAgentSettingsTest.php`
- Test: `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`

- [ ] **Step 1: Write failing unit tests for `QuoteAgentSettings` and `QuoteAgentSettingsFactory`**

In `tests/Unit/Config/QuoteAgentSettingsTest.php`:
```php
    public function testSettingsCarryNotifyBuyerOnEscalationFlag(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0));
        $llm = new ModelAccess('sk-test', 'https://api.openai.com/v1', 'gpt-4o-mini');

        $settings = new QuoteAgentSettings($policy, $llm, 'concede slowly', notifyBuyerOnEscalation: true);

        self::assertTrue($settings->notifyBuyerOnEscalation);

        $cloned = $settings->withPolicy($policy);
        self::assertTrue($cloned->notifyBuyerOnEscalation);
    }
```

In `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`:
```php
    public function testFactoryParsesNotifyBuyerOnEscalation(): void
    {
        $settingsDefault = self::build();
        self::assertNotNull($settingsDefault);
        self::assertFalse($settingsDefault->notifyBuyerOnEscalation);

        $settingsEnabled = self::build(['notifyBuyerOnEscalation' => true]);
        self::assertNotNull($settingsEnabled);
        self::assertTrue($settingsEnabled->notifyBuyerOnEscalation);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Config/QuoteAgentSettingsTest.php tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`
Expected: FAIL (argument mismatch or undefined property).

- [ ] **Step 3: Implement changes in `QuoteAgentSettings`, `QuoteAgentSettingsReader`, and `QuoteAgentSettingsFactory`**

In `src/Config/QuoteAgentSettings.php`:
```php
final readonly class QuoteAgentSettings
{
    public function __construct(
        public NegotiationPolicy $policy,
        public ModelAccess $llm,
        public ?string $strategyPrompt,
        public bool $notifyBuyerOnEscalation = false,
    ) {}

    public function withPolicy(NegotiationPolicy $policy): self
    {
        return new self($policy, $this->llm, $this->strategyPrompt, $this->notifyBuyerOnEscalation);
    }
}
```

In `src/Config/QuoteAgentSettingsReader.php`:
Add `'notifyBuyerOnEscalation'` to `private const KEYS`.

In `src/Config/QuoteAgentSettingsFactory.php`:
Pass `notifyBuyerOnEscalation: RawConfigValue::bool($raw, 'notifyBuyerOnEscalation') === true` into `new QuoteAgentSettings(...)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Config/QuoteAgentSettingsTest.php tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Config/ tests/Unit/Config/
git commit --no-gpg-sign -m "feat(config): support notifyBuyerOnEscalation in QuoteAgentSettings"
```

---

### Task 3: Update `QuoteEscalator` and Service Wiring

**Files:**
- Modify: `src/Servicing/QuoteEscalator.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Servicing/QuoteEscalatorTest.php`

- [ ] **Step 1: Write failing unit tests in `tests/Unit/Servicing/QuoteEscalatorTest.php`**

```php
    public function testItDoesNotWriteCommentWhenBuyerNotificationIsDisabled(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NeedsHumanReview,
            notifyBuyer: false,
        );

        self::assertSame(['updateQuote'], $gateway->calls);
        self::assertEmpty($gateway->comments);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    public function testItWritesCommentWhenBuyerNotificationIsEnabled(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NeedsHumanReview,
            notifyBuyer: true,
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertNotEmpty($gateway->comments);
    }

    public function testItResolvesBuyerNotificationFromSettingsSource(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settingsSource = new class implements QuoteAgentSettingsSource {
            public bool $enabled = false;
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return new QuoteAgentSettings(
                    new NegotiationPolicy(),
                    new ModelAccess('key', 'https://example.com', 'model'),
                    null,
                    notifyBuyerOnEscalation: $this->enabled,
                );
            }
        };

        $escalator = new QuoteEscalator(settingsSource: $settingsSource);

        $settingsSource->enabled = false;
        $escalator->escalate($gateway, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['updateQuote'], $gateway->calls);

        $gateway2 = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settingsSource->enabled = true;
        $escalator->escalate($gateway2, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['addComment', 'updateQuote'], $gateway2->calls);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Servicing/QuoteEscalatorTest.php`
Expected: FAIL

- [ ] **Step 3: Update `QuoteEscalator` and `services.php`**

In `src/Servicing/QuoteEscalator.php`:
- Add `private readonly ?QuoteAgentSettingsSource $settingsSource = null` to `__construct`.
- Update `escalate(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, QuoteEscalationReason $reason, ?bool $notifyBuyer = null): void`.
- Resolve `$shouldNotify = $notifyBuyer ?? $this->shouldNotifyBuyer($snapshot->identity->salesChannelId);`.
- Only call `$gateway->addComment($quoteId, self::BUYER_MESSAGE)` if `$shouldNotify` is `true`.
- Add private helper `shouldNotifyBuyer(?string $salesChannelId): bool` that safely handles `InvalidQuoteAgentConfiguration` and null settings.

In `src/Resources/config/services.php`:
- Update `QuoteEscalator` service definition to pass `service(QuoteAgentSettingsSource::class)` as the second argument.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Servicing/QuoteEscalatorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Servicing/QuoteEscalator.php src/Resources/config/services.php tests/Unit/Servicing/QuoteEscalatorTest.php
git commit --no-gpg-sign -m "feat(servicing): support silent escalation in QuoteEscalator"
```

---

### Task 4: Full Quality Gate Verification

**Files:**
- All touched files

- [ ] **Step 1: Run format and lint checks**

Run: `composer run format:check && composer run lint`
Expected: PASS (exit code 0).

- [ ] **Step 2: Run type check**

Run: `composer run typecheck`
Expected: PASS (exit code 0).

- [ ] **Step 3: Run full PHPUnit test suite**

Run: `composer test`
Expected: All tests PASS.

- [ ] **Step 4: Run dependency and boundary checks**

Run: `composer run quality:depcheck`
Expected: PASS.
