# Agent Access Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a merchant see and edit which agents their shop trusts, and let a developer admit a throwaway agent host with one console command — without changing the Agentic Commerce plugin.

**Architecture:** Four pieces. A per-sales-channel flag in `system_config`, written only by a console command. A decorator over the SDK's `RuntimeConfigurationResolverInterface` (which Agentic Commerce aliases) that adds the requesting agent's profile host to the per-channel allowlists while the flag is on. Our own factory registered against the SDK's `UrlSafetyValidator` service id, doing the same for the installation-wide list. And an Administration page that edits the three allowlists through Agentic Commerce's existing config API.

**Tech Stack:** PHP 8.3, Shopware 6.7 (`shopware/core` ~6.7.0), `ucp-php-sdk/core` + `ucp-php-sdk/symfony-bundle`, PHPUnit 11.5, Mago (format/lint/analyze), Shopware Administration (Vue 3 + TypeScript).

**Spec:** `docs/superpowers/specs/2026-09-01-agent-access-controls-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Mago analyze at full strictness — no `mixed`, no unsafe casts.
- Gate thresholds: cyclomatic complexity 10 **per class**, nesting depth 4, parameters 5, ~400 physical lines per `src/` file.
- **This repo fixes `too-many-methods` and `cyclomatic-complexity` by splitting, never by suppressing** — three existing precedents plus four during the last plan. No `@mago-expect` may appear in this plan's diff without a recorded ruling; flag it and ask.
- Mago wants `#[\SensitiveParameter]` on parameters carrying secrets and `@throws` docblocks on concrete classes (never on a port interface).
- The flag must **not** appear in `src/Resources/config/config.xml`. That file renders the plugin's settings form, and the switch is console-only by design.
- Agentic Commerce is never modified. Its config row is never written except through its own API.
- Every protection in the SDK stays: https only, ports 443/8443, no redirects, no private or link-local addresses, blocked metadata hosts, and an empty allowlist still denying everything. The flag adds a host; it never switches a check off.
- No new composer dependencies.
- Current test state, which must not regress: unit **354** passing; integration `BuyerQuoteFlow` 9, `UcpQuoteEndpoint` 4, `SalesChannelContextResolver` 3, `GatewayWiringTest` 4. The full integration suite has two pre-existing `PluginConfigTest` failures unrelated to this work (a float/int precision assertion, and one reading a persisted OpenRouter base URL out of the shared shop's `system_config`).
- Integration tests run inside the docker container `merchant-quote-shop` via `composer run test:integration`, which syncs this checkout into it. That container is shared with other sessions; re-run before believing a nonsensical failure, and **never change shop state** — report instead.

**Two corrections to the spec, already decided:** the editor gets its own route in the existing admin module (`merchant-quote-agent`), because that module's current pages are audit views over decision records; and the page is gated by Agentic Commerce's own `ucp.viewer` / `ucp.editor` privileges rather than a new one of ours, because the data is theirs and a parallel privilege would gate nothing.

---

### Task 1: The flag

**Files:**
- Create: `src/Identity/AgentAccessFlags.php`
- Test: `tests/Unit/Identity/AgentAccessFlagsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `AgentAccessFlags::allowAnyAgent(?string $salesChannelId): bool`, and the constant `AgentAccessFlags::ALLOW_ANY_AGENT_KEY = 'MerchantQuoteAgentPlugin.config.allowAnyAgent'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Identity/AgentAccessFlagsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(AgentAccessFlags::class)]
final class AgentAccessFlagsTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testItIsOffWhenNothingIsConfigured(): void
    {
        self::assertFalse($this->flags(null)->allowAnyAgent(self::SALES_CHANNEL_ID));
    }

    public function testItReadsTheFlagForTheGivenSalesChannel(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::once())
            ->method('get')
            ->with(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, self::SALES_CHANNEL_ID)
            ->willReturn(true);

        self::assertTrue((new AgentAccessFlags($config))->allowAnyAgent(self::SALES_CHANNEL_ID));
    }

    public function testItTreatsAnUnresolvedSalesChannelAsOff(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::never())->method('get');

        self::assertFalse((new AgentAccessFlags($config))->allowAnyAgent(null));
    }

    public function testItAcceptsOnlyABooleanTrue(): void
    {
        // system_config round-trips JSON, so a stale string must not read as on.
        self::assertFalse($this->flags('true')->allowAnyAgent(self::SALES_CHANNEL_ID));
        self::assertFalse($this->flags(1)->allowAnyAgent(self::SALES_CHANNEL_ID));
        self::assertTrue($this->flags(true)->allowAnyAgent(self::SALES_CHANNEL_ID));
    }

    private function flags(mixed $stored): AgentAccessFlags
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($stored);

        return new AgentAccessFlags($config);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentAccessFlags`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Identity\AgentAccessFlags" not found`.

- [ ] **Step 3: Write the reader**

Create `src/Identity/AgentAccessFlags.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Whether a sales channel currently admits any agent that presents a fetchable,
 * signed profile.
 *
 * Deliberately not part of {@see \MerchantQuoteAgentPlugin\Config\QuoteAgentSettings}:
 * that is the negotiation configuration a merchant edits, and this is a security
 * switch set only by `bin/console merchant-quote-agent:allow-any-agent`. It has
 * no `config.xml` entry for the same reason — that file would render it in the
 * plugin's settings form.
 *
 * A null sales channel means the request could not be attributed to one, which
 * is never a reason to widen anything.
 */
final readonly class AgentAccessFlags
{
    public const ALLOW_ANY_AGENT_KEY = 'MerchantQuoteAgentPlugin.config.allowAnyAgent';

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {
    }

    public function allowAnyAgent(?string $salesChannelId): bool
    {
        if ($salesChannelId === null) {
            return false;
        }

        return true === $this->systemConfig->get(self::ALLOW_ANY_AGENT_KEY, $salesChannelId);
    }
}
```

- [ ] **Step 4: Run the test to make sure it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentAccessFlags`
Expected: PASS (4 tests).

- [ ] **Step 5: Register the service**

In `src/Resources/config/services.php`, after the Identity block (`AgentCustomerAuthenticator`), add `$services->set(AgentAccessFlags::class);` and the matching `use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;` import in alphabetical position.

- [ ] **Step 6: Commit**

```bash
git add src/Identity/AgentAccessFlags.php tests/Unit/Identity/AgentAccessFlagsTest.php src/Resources/config/services.php
git commit -m "feat: read the per-sales-channel allow-any-agent flag

Console-set only, so it has no config.xml entry, and it is separate from
QuoteAgentSettings because that is negotiation configuration rather than a
security switch. Only a real boolean true reads as on."
```

---

### Task 2: The agent header parser

**Files:**
- Create: `src/Identity/UcpAgentHeader.php`
- Test: `tests/Unit/Identity/UcpAgentHeaderTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `UcpAgentHeader::NAME = 'UCP-Agent'`, `UcpAgentHeader::profileHost(?string $header): ?string`, `UcpAgentHeader::profileHostFromHeaders(array $headers): ?string`.

- [ ] **Step 1: Port the class and its test from the Agentic Commerce fork**

```bash
export FORK=/Users/sebastian/projects/agentic-commerce
git -C "$FORK" show 46783f7:src/Ucp/Profile/UcpAgentHeader.php > src/Identity/UcpAgentHeader.php
git -C "$FORK" show 46783f7:tests/Unit/Ucp/Profile/UcpAgentHeaderTest.php > tests/Unit/Identity/UcpAgentHeaderTest.php
```

Then edit both:

1. Namespace `Swag\AgenticCommerce\Ucp\Profile` → `MerchantQuoteAgentPlugin\Identity` (source) and `Swag\AgenticCommerce\Tests\Unit\Ucp\Profile` → `MerchantQuoteAgentPlugin\Tests\Unit\Identity` (test).
2. Remove the `use Shopware\Core\Framework\Log\Package;` import and the `#[Package('framework')]` attribute — this plugin does not use them.
3. Remove the ` * @internal` line and the blank comment line above it; nothing in this `src/` uses `@internal`.
4. In the class docblock, replace the sentence naming the fork's two callers with: `Two places here need the host *before* the SDK builds its request context, to decide whether a sales channel with the allow-any-agent flag should admit it: the runtime configuration resolver decorator and the profile-fetch validator factory.`

- [ ] **Step 2: Run the ported test**

Run: `vendor/bin/phpunit --testsuite unit --filter UcpAgentHeader`
Expected: PASS. A failure here means an edit in step 1 was wrong, not that the logic needs changing.

- [ ] **Step 3: Add the case the port does not cover**

The fork's test does not pin that a header carrying a profile URI with a port keeps the bare host, which both callers depend on when matching against allowlist entries. Append to the test class:

```php
    #[Test]
    public function testItDropsThePortFromTheProfileHost(): void
    {
        self::assertSame(
            'agent.example',
            UcpAgentHeader::profileHost('manual/1.0; profile="https://agent.example:8443/.well-known/ucp"'),
        );
    }
```

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit --testsuite unit --filter UcpAgentHeader`
Expected: PASS. If it fails, `parse_url`'s host already excludes the port and the assertion is simply confirming it — read the failure before changing the source.

- [ ] **Step 5: Commit**

```bash
git add src/Identity/UcpAgentHeader.php tests/Unit/Identity/UcpAgentHeaderTest.php
git commit -m "feat: read the profile host an agent announces in UCP-Agent

Ported from the Agentic Commerce fork's 46783f7, which already solved this.
Total by design: a caller uses it to widen an allowlist, so an unparseable
header must widen nothing."
```

---

### Task 3: Host-to-sales-channel lookup

**Files:**
- Modify: `src/Bridge/SalesChannelContextResolver.php`
- Test: `tests/Integration/SalesChannelContextResolverTest.php`

**Interfaces:**
- Consumes: `SalesChannelResolution` (existing).
- Produces: `CustomerContextResolverInterface::resolveByHost(?string $host): ?SalesChannelResolution` — null rather than throwing when no active sales channel serves the host. `resolveSalesChannel(RequestContext $context): SalesChannelResolution` keeps its throwing contract and delegates to it.

- [ ] **Step 1: Write the failing test**

Both new runtime hooks have a host and need a sales channel, without the SDK's `RequestContext` and without an exception when the host is unknown. Add to `tests/Integration/SalesChannelContextResolverTest.php`:

```php
    public function testItResolvesASalesChannelFromABareHost(): void
    {
        $domain = $this->anyStorefrontDomain();
        $host = (string) parse_url($domain['url'], \PHP_URL_HOST);

        $resolution = $this->resolver()->resolveByHost($host);

        self::assertNotNull($resolution);
        self::assertSame($domain['sales_channel_id'], $resolution->salesChannelId);
    }

    public function testItReturnsNullForAHostNoSalesChannelServes(): void
    {
        self::assertNull($this->resolver()->resolveByHost('not-a-shop.invalid'));
    }

    public function testItReturnsNullForAnEmptyHostRatherThanGuessing(): void
    {
        self::assertNull($this->resolver()->resolveByHost(null));
        self::assertNull($this->resolver()->resolveByHost(''));
    }
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `composer run test:integration -- --filter SalesChannelContextResolver`
Expected: FAIL — `resolveByHost()` does not exist.

- [ ] **Step 3: Add the method to the port and the resolver**

In `src/Bridge/CustomerContextResolverInterface.php`, add:

```php
    /**
     * The sales channel serving a bare host, or null when none does.
     *
     * Null rather than an exception because both runtime hooks use this to
     * decide whether to widen an allowlist, and an unattributable request is
     * never a reason to widen one.
     */
    public function resolveByHost(?string $host): ?SalesChannelResolution;
```

In `src/Bridge/SalesChannelContextResolver.php`, move the query out of `resolveSalesChannel()` into the new method and have the old one delegate, so there is one copy of the SQL:

```php
    #[Override]
    public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
    {
        $resolution = $this->resolveByHost($context->host);

        if ($resolution === null) {
            throw new ConfigurationException(\sprintf('No active sales channel serves the host "%s".', $context->host));
        }

        return $resolution;
    }

    #[Override]
    public function resolveByHost(?string $host): ?SalesChannelResolution
    {
        $normalized = strtolower(trim($host ?? ''));

        if ($normalized === '') {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,'
            . ' LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(d.currency_id)) AS currency_id'
            . ' FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(d.url, "://", -1), "/", 1), ":", 1)) = :host'
            . ' ORDER BY CHAR_LENGTH(d.url) ASC LIMIT 1',
            ['host' => explode(':', $normalized)[0]],
        );

        if ($row === false) {
            return null;
        }

        return new SalesChannelResolution(
            (string) $row['sales_channel_id'],
            (string) $row['language_id'],
            (string) $row['currency_id'],
            (string) $row['id'],
        );
    }
```

Keep the class docblock's paragraph about host matching and its two named ceilings unchanged — it still describes this query.

- [ ] **Step 4: Run the test**

Run: `composer run test:integration -- --filter SalesChannelContextResolver`
Expected: PASS (6 tests — the three existing plus the three new).

- [ ] **Step 5: Commit**

```bash
git add src/Bridge/CustomerContextResolverInterface.php src/Bridge/SalesChannelContextResolver.php tests/Integration/SalesChannelContextResolverTest.php
git commit -m "feat: resolve a sales channel from a bare host

Both new runtime hooks have a host and no RequestContext, and need null rather
than an exception when nothing serves it. resolveSalesChannel() now delegates,
so the domain query exists once."
```

---

### Task 4: The per-channel runtime hook

**Files:**
- Create: `src/Identity/AgentAdmittingRuntimeConfigurationResolver.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Identity/AgentAdmittingRuntimeConfigurationResolverTest.php`

**Interfaces:**
- Consumes: `AgentAccessFlags::allowAnyAgent(?string)`, `UcpAgentHeader::profileHostFromHeaders(array)`, `CustomerContextResolverInterface::resolveByHost(?string)`, and the SDK's `RuntimeConfigurationResolverInterface::resolve(HttpRequest): RuntimeConfiguration`.
- Produces: a decorator of `RuntimeConfigurationResolverInterface`; nothing else depends on it directly.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Identity/AgentAdmittingRuntimeConfigurationResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

#[CoversClass(AgentAdmittingRuntimeConfigurationResolver::class)]
final class AgentAdmittingRuntimeConfigurationResolverTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const AGENT_HEADER = 'manual/1.0; profile="https://agent.example/.well-known/ucp"';

    public function testItAdmitsThePresentedHostWhileTheFlagIsOn(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['chatgpt.com', 'agent.example'], $resolved->allowedProfileHosts);
        self::assertSame(['chatgpt.com', 'agent.example'], $resolved->allowedAgentDomains);
    }

    public function testItChangesNothingWhileTheFlagIsOff(): void
    {
        $resolved = $this->resolve(flagOn: false, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['chatgpt.com'], $resolved->allowedProfileHosts);
        self::assertSame(['chatgpt.com'], $resolved->allowedAgentDomains);
    }

    public function testItChangesNothingWithoutAUsableHeader(): void
    {
        self::assertSame(['chatgpt.com'], $this->resolve(flagOn: true, headers: [])->allowedProfileHosts);
        self::assertSame(
            ['chatgpt.com'],
            $this->resolve(flagOn: true, headers: ['UCP-Agent' => 'manual/1.0'])->allowedProfileHosts,
        );
    }

    public function testItDoesNotAdmitAHostTwice(): void
    {
        $resolved = $this->resolve(
            flagOn: true,
            headers: ['UCP-Agent' => 'manual/1.0; profile="https://chatgpt.com/.well-known/ucp"'],
        );

        self::assertSame(['chatgpt.com'], $resolved->allowedProfileHosts);
    }

    public function testItPreservesEveryOtherRuntimeSetting(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame('2026-04-08', $resolved->version);
        self::assertSame('https://shop.example', $resolved->baseUri);
        self::assertTrue($resolved->idempotencyRequired);
        self::assertSame(['catalog'], $resolved->enabledCapabilities);
        self::assertSame('tenant-1', $resolved->tenantIdentifier);
    }

    public function testItChangesNothingWhenTheHostMatchesNoSalesChannel(): void
    {
        $inner = $this->inner();
        $flags = new AgentAccessFlags($this->systemConfig(true));
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver->method('resolveByHost')->willReturn(null);

        $decorator = new AgentAdmittingRuntimeConfigurationResolver($inner, $flags, $resolver);

        self::assertSame(
            ['chatgpt.com'],
            $decorator->resolve($this->request(['UCP-Agent' => self::AGENT_HEADER]))->allowedProfileHosts,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function resolve(bool $flagOn, array $headers): RuntimeConfiguration
    {
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver->method('resolveByHost')->willReturn(
            new SalesChannelResolution(self::SALES_CHANNEL_ID, 'l', 'c', 'd'),
        );

        $decorator = new AgentAdmittingRuntimeConfigurationResolver(
            $this->inner(),
            new AgentAccessFlags($this->systemConfig($flagOn)),
            $resolver,
        );

        return $decorator->resolve($this->request($headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): HttpRequest
    {
        return new HttpRequest('GET', 'https://shop.example/ucp/quotes', $headers);
    }

    private function inner(): RuntimeConfigurationResolverInterface
    {
        $configuration = new RuntimeConfiguration(
            '2026-04-08',
            'https://shop.example',
            allowedProfileHosts: ['chatgpt.com'],
            allowedAgentDomains: ['chatgpt.com'],
            enabledCapabilities: ['catalog'],
            tenantIdentifier: 'tenant-1',
            idempotencyRequired: true,
        );

        $inner = $this->createMock(RuntimeConfigurationResolverInterface::class);
        $inner->method('resolve')->willReturn($configuration);

        return $inner;
    }

    private function systemConfig(bool $flagOn): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($flagOn);

        return $config;
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentAdmittingRuntimeConfigurationResolver`
Expected: FAIL — the decorator class does not exist.

- [ ] **Step 3: Write the decorator**

Create `src/Identity/AgentAdmittingRuntimeConfigurationResolver.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use Override;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/**
 * Admits the requesting agent's own profile host on sales channels whose
 * allow-any-agent flag is on.
 *
 * The SDK takes the profile-host and agent-domain gates from whatever this
 * interface returns (see DefaultHttpRequestContextFactory), and the Agentic
 * Commerce plugin aliases the interface to its own resolver, so decorating it
 * widens both gates without touching that plugin. What it does not touch is the
 * installation-wide list the SDK's UrlSafetyValidator enforces — that is
 * {@see AgentProfileHostValidatorFactory}.
 *
 * This widens an identity allowlist and nothing else: the agent still has to
 * publish a profile the shop can fetch, and the signature check on the request
 * still runs against the keys in it. Anything unattributable — no header, an
 * unparseable one, a host no sales channel serves — widens nothing.
 */
final readonly class AgentAdmittingRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
{
    public function __construct(
        private RuntimeConfigurationResolverInterface $inner,
        private AgentAccessFlags $flags,
        private CustomerContextResolverInterface $contextResolver,
    ) {
    }

    #[Override]
    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        $configuration = $this->inner->resolve($request);
        $agentHost = UcpAgentHeader::profileHostFromHeaders($request->headers);

        if ($agentHost === null) {
            return $configuration;
        }

        $salesChannelId = $this->contextResolver
            ->resolveByHost(parse_url($request->absoluteUri, \PHP_URL_HOST) ?: null)
            ?->salesChannelId;

        if (!$this->flags->allowAnyAgent($salesChannelId)) {
            return $configuration;
        }

        return new RuntimeConfiguration(
            $configuration->version,
            $configuration->baseUri,
            $configuration->signaturePolicy,
            $configuration->idempotencyRequired,
            self::withHost($configuration->allowedProfileHosts, $agentHost),
            self::withHost($configuration->allowedAgentDomains, $agentHost),
            $configuration->supportedVersions,
            $configuration->transports,
            $configuration->enabledCapabilities,
            $configuration->tenantIdentifier,
            $configuration->transportEndpoints,
            $configuration->profileFetchingDevelopmentMode,
        );
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private static function withHost(array $hosts, string $host): array
    {
        return \in_array($host, $hosts, true) ? $hosts : [...$hosts, $host];
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentAdmittingRuntimeConfigurationResolver`
Expected: PASS (6 tests).

- [ ] **Step 5: Register the decoration**

In `src/Resources/config/services.php`, in the Identity block:

```php
    // Widens the SDK's per-request profile-host and agent-domain gates on sales
    // channels whose allow-any-agent flag is on. Decorates the interface the
    // Agentic Commerce plugin aliases, the same seam that plugin uses for
    // AgentProfileFetcherInterface.
    $services->set(AgentAdmittingRuntimeConfigurationResolver::class)
        ->decorate(RuntimeConfigurationResolverInterface::class)
        ->arg('$inner', service('.inner'));
```

with `use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;` and `use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;` added to the imports.

- [ ] **Step 6: Prove the decoration is live in a real container**

Add `tests/Integration/AgentAccessWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/**
 * The runtime hooks only work while they are actually the services the container
 * hands out. Both are attached to definitions owned by other packages, so a
 * rename or a competing definition would otherwise degrade silently.
 */
final class AgentAccessWiringTest extends IntegrationTestCase
{
    public function testOurDecoratorIsWhatTheContainerResolvesForTheRuntimeConfiguration(): void
    {
        $resolver = static::getContainer()->get(RuntimeConfigurationResolverInterface::class);

        self::assertInstanceOf(
            AgentAdmittingRuntimeConfigurationResolver::class,
            $resolver,
            'the Agentic Commerce plugin no longer aliases this interface, or another decoration replaced ours',
        );
    }
}
```

- [ ] **Step 7: Run it**

Run: `composer run test:integration -- --filter AgentAccessWiring`
Expected: PASS (1 test).

- [ ] **Step 8: Commit**

```bash
git add src/Identity/AgentAdmittingRuntimeConfigurationResolver.php src/Resources/config/services.php tests/Unit/Identity/AgentAdmittingRuntimeConfigurationResolverTest.php tests/Integration/AgentAccessWiringTest.php
git commit -m "feat: admit the requesting agent on channels with the flag on

Decorates the resolver interface Agentic Commerce aliases, so the SDK's
per-request profile-host and agent-domain gates follow without touching that
plugin. Nothing unattributable widens anything, and the wiring test fails
loudly if the alias or the decoration stops being ours."
```

---

### Task 5: The installation-wide runtime hook

**Files:**
- Create: `src/Identity/AgentProfileHostValidatorFactory.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Integration/AgentAccessWiringTest.php`

**Interfaces:**
- Consumes: `AgentAccessFlags`, `UcpAgentHeader`, `CustomerContextResolverInterface::resolveByHost()`, the SDK bundle's `UcpSdkConfiguration` (for `allowedProfileHosts` and `profileFetchingDevelopmentMode`), and Symfony's `RequestStack`.
- Produces: `AgentProfileHostValidatorFactory::create(): UrlSafetyValidator`, registered as the factory for the SDK's `Ucp\Sdk\Internal\Service\UrlSafetyValidator` service id.

- [ ] **Step 1: Write the failing test**

The installation-wide list is what the SDK's profile fetcher consults, and it cannot be decorated — `UrlSafetyValidator` is `final` and injected concretely — so this is a definition replacement, and the test has to prove the container hands out ours. Add to `tests/Integration/AgentAccessWiringTest.php`:

```php
    public function testOurFactoryBuildsTheValidatorTheContainerResolves(): void
    {
        $validator = static::getContainer()->get(UrlSafetyValidator::class);

        self::assertInstanceOf(
            UrlSafetyValidator::class,
            $validator,
            'the SDK bundle no longer defines this service under this id',
        );

        // With no allow-any-agent channel in scope, our factory must reproduce
        // the bundle's own behaviour: a host nobody allowlisted stays rejected.
        $this->expectException(SignatureException::class);
        $validator->assertAllowed('https://agent.example/.well-known/ucp');
    }
```

with `use Ucp\Sdk\Exception\SignatureException;` and `use Ucp\Sdk\Internal\Service\UrlSafetyValidator;` imported. If `assertAllowed()` throws a different SDK exception in this version, read the class and assert that one — the point of the test is that an unlisted host is refused, not which type carries the refusal.

- [ ] **Step 2: Run it to make sure it fails**

Run: `composer run test:integration -- --filter AgentAccessWiring`
Expected: the new test errors, because `UrlSafetyValidator` is a private SDK-bundle service that nothing in this plugin references yet, so the test container has no entry for it. That failure is the point: after step 3 our factory's definition makes it resolvable.

- [ ] **Step 3: Port the factory**

```bash
export FORK=/Users/sebastian/projects/agentic-commerce
git -C "$FORK" show 46783f7:src/Ucp/Profile/AgentProfileHostValidatorFactory.php > src/Identity/AgentProfileHostValidatorFactory.php
```

Then edit it:

1. Namespace → `MerchantQuoteAgentPlugin\Identity`.
2. Drop the `Package` import and attribute, and the `@internal` line.
3. Replace the constructor's Agentic Commerce dependencies with ours: `UcpSdkConfiguration $sdkConfiguration` stays; `UcpConfigService $configService` becomes `AgentAccessFlags $flags`; `SalesChannelDomainResolver $domainResolver` becomes `CustomerContextResolverInterface $contextResolver`; `RequestStack $requestStack` stays.
4. Rewrite `allowedHosts()` and drop `salesChannelId()`:

```php
    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $configured = $this->sdkConfiguration->allowedProfileHosts;

        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return $configured;
        }

        $agentHost = UcpAgentHeader::profileHost($request->headers->get(UcpAgentHeader::NAME));
        if ($agentHost === null) {
            return $configured;
        }

        $salesChannelId = $this->contextResolver->resolveByHost($request->getHost())?->salesChannelId;
        if (!$this->flags->allowAnyAgent($salesChannelId)) {
            return $configured;
        }

        return array_values(array_unique([...$configured, $agentHost]));
    }
```

5. Rewrite the class docblock's last paragraph, which points at the fork's test, to point at ours: `The trade-off is a dependency on an @internal SDK class and on its service id, so AgentAccessWiringTest pins both: that the container resolves a validator built by this factory, and that a host nobody allowlisted is still refused.`

- [ ] **Step 4: Register the replacement**

In `src/Resources/config/services.php`:

```php
    // The SDK's profile-fetch validator, rebuilt per request so an
    // allow-any-agent channel can admit the host the request presents. This
    // REPLACES the SDK bundle's own definition of the service, because the class
    // is final and injected concretely, so it cannot be decorated. Whoever
    // defines this id last wins: AgentAccessWiringTest fails loudly if that
    // stops being us.
    $services->set(AgentProfileHostValidatorFactory::class);
    $services->set(UrlSafetyValidator::class)
        ->factory([service(AgentProfileHostValidatorFactory::class), 'create'])
        ->public();
```

with `use MerchantQuoteAgentPlugin\Identity\AgentProfileHostValidatorFactory;` and `use Ucp\Sdk\Internal\Service\UrlSafetyValidator;` imported. The `->public()` is deliberate and permanent here, unlike the scaffolding flags in the last plan: the wiring test fetches this service by id, and no consumer of ours references it.

- [ ] **Step 5: Run both tests**

Run: `composer run test:integration -- --filter AgentAccessWiring`
Expected: PASS (2 tests). Then `vendor/bin/phpunit --testsuite unit` — still green.

- [ ] **Step 6: Commit**

```bash
git add src/Identity/AgentProfileHostValidatorFactory.php src/Resources/config/services.php tests/Integration/AgentAccessWiringTest.php
git commit -m "feat: rebuild the SDK's profile-fetch validator per request

Ported from the fork's 46783f7. The installation-wide allowlist is a container
value the SDK bundle fixes at compile time, and the validator is final and
injected concretely, so this replaces its definition rather than decorating it.
Every other check it performs is untouched; only the identity allowlist widens."
```

---

### Task 6: The console command

**Files:**
- Create: `src/Command/AllowAnyAgentCommand.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Command/AllowAnyAgentCommandTest.php`

**Interfaces:**
- Consumes: `AgentAccessFlags::ALLOW_ANY_AGENT_KEY`, Shopware's `SystemConfigService`, and a sales-channel listing.
- Produces: `bin/console merchant-quote-agent:allow-any-agent [salesChannelId] [--on|--off]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Command/AllowAnyAgentCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Command\AllowAnyAgentCommand;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AllowAnyAgentCommand::class)]
final class AllowAnyAgentCommandTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testItListsEveryChannelsStateWithoutArguments(): void
    {
        $tester = new CommandTester($this->command(storedFlag: true));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('Storefront', $output);
        self::assertStringContainsString(self::SALES_CHANNEL_ID, $output);
        self::assertStringContainsString('on', $output);
    }

    public function testItTurnsTheFlagOnForOneChannelAndWarns(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::once())
            ->method('set')
            ->with(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, true, self::SALES_CHANNEL_ID);

        $tester = new CommandTester($this->command(storedFlag: false, systemConfig: $config));
        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID, '--on' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('any agent', $tester->getDisplay());
    }

    public function testItTurnsTheFlagOff(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::once())
            ->method('set')
            ->with(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, false, self::SALES_CHANNEL_ID);

        $tester = new CommandTester($this->command(storedFlag: true, systemConfig: $config));
        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID, '--off' => true]);

        $tester->assertCommandIsSuccessful();
    }

    public function testItRefusesAnUnknownSalesChannel(): void
    {
        $tester = new CommandTester($this->command(storedFlag: false));

        $tester->execute(['salesChannelId' => 'ffffffffffffffffffffffffffffffff', '--on' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unknown sales channel', $tester->getDisplay());
    }

    public function testItRefusesBothSwitchesAtOnce(): void
    {
        $tester = new CommandTester($this->command(storedFlag: false));

        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID, '--on' => true, '--off' => true]);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testItRefusesAChannelIdWithoutASwitch(): void
    {
        $tester = new CommandTester($this->command(storedFlag: false));

        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID]);

        self::assertSame(1, $tester->getStatusCode());
    }

    private function command(bool $storedFlag, ?SystemConfigService $systemConfig = null): AllowAnyAgentCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => self::SALES_CHANNEL_ID, 'name' => 'Storefront'],
        ]);

        $config = $systemConfig ?? $this->createMock(SystemConfigService::class);
        if ($systemConfig === null) {
            $config->method('get')->willReturn($storedFlag);
        }

        return new AllowAnyAgentCommand($connection, $config, new AgentAccessFlags($config));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AllowAnyAgentCommand`
Expected: FAIL — the command class does not exist.

- [ ] **Step 3: Write the command**

Create `src/Command/AllowAnyAgentCommand.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns the allow-any-agent switch on or off for one sales channel, and reports
 * where it is on.
 *
 * Console-only on purpose: while it is on, any agent that publishes a fetchable,
 * signed profile can transact on that channel, which is a decision for whoever
 * runs the shop rather than a setting to leave on a merchant's settings screen.
 * The allowlists themselves are editable in the Administration.
 */
#[AsCommand(
    name: 'merchant-quote-agent:allow-any-agent',
    description: 'Show, or set per sales channel, whether any agent presenting a signed profile is admitted.',
)]
final class AllowAnyAgentCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig,
        private readonly AgentAccessFlags $flags,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('salesChannelId', InputArgument::OPTIONAL, 'Sales channel to change; omit to list every channel.');
        $this->addOption('on', null, InputOption::VALUE_NONE, 'Admit any agent presenting a fetchable, signed profile.');
        $this->addOption('off', null, InputOption::VALUE_NONE, 'Restrict to the channel\'s allowlists again.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $salesChannelId = $input->getArgument('salesChannelId');
        $on = (bool) $input->getOption('on');
        $off = (bool) $input->getOption('off');

        if (!\is_string($salesChannelId) || $salesChannelId === '') {
            return $this->list($io, $on || $off);
        }

        if ($on === $off) {
            $io->error('Pass exactly one of --on or --off.');

            return Command::FAILURE;
        }

        $channels = $this->channels();
        if (!isset($channels[$salesChannelId])) {
            $io->error(\sprintf('Unknown sales channel "%s". Run without arguments to list them.', $salesChannelId));

            return Command::FAILURE;
        }

        $this->systemConfig->set(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, $on, $salesChannelId);

        if ($on) {
            $io->warning(\sprintf(
                'Sales channel "%s" now admits any agent that presents a fetchable, signed profile. Turn it off when you are done: --off',
                $channels[$salesChannelId],
            ));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Sales channel "%s" is restricted to its allowlists again.', $channels[$salesChannelId]));

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io, bool $switchWithoutChannel): int
    {
        if ($switchWithoutChannel) {
            $io->error('--on and --off need a sales channel id.');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($this->channels() as $id => $name) {
            $rows[] = [$name, $id, $this->flags->allowAnyAgent($id) ? 'on' : 'off'];
        }

        $io->table(['Sales channel', 'Id', 'Allow any agent'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function channels(): array
    {
        $channels = [];

        /** @var array{id: string, name: string} $row */
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sc.id)) AS id, COALESCE(t.name, sc.id) AS name'
            . ' FROM sales_channel sc'
            . ' LEFT JOIN sales_channel_translation t ON t.sales_channel_id = sc.id'
            . ' WHERE sc.active = 1 GROUP BY sc.id, t.name ORDER BY name',
        ) as $row) {
            $channels[(string) $row['id']] = (string) $row['name'];
        }

        return $channels;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit --testsuite unit --filter AllowAnyAgentCommand`
Expected: PASS (6 tests).

- [ ] **Step 5: Register the command**

In `src/Resources/config/services.php`, beside the Identity block:

```php
    $services->set(AllowAnyAgentCommand::class)->tag('console.command');
```

with `use MerchantQuoteAgentPlugin\Command\AllowAnyAgentCommand;` imported. `autoconfigure()` also picks up `#[AsCommand]`, but the explicit tag matches how this file registers everything else.

- [ ] **Step 6: Run it against the shop for real**

```bash
scripts/sync-to-shop.sh
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/console merchant-quote-agent:allow-any-agent
```

Expected: a table listing the shop's active sales channels with `off` for each. **Do not turn it on** — leave the shop as you found it, and paste the table into your report.

- [ ] **Step 7: Commit**

```bash
git add src/Command/AllowAnyAgentCommand.php src/Resources/config/services.php tests/Unit/Command/AllowAnyAgentCommandTest.php
git commit -m "feat: set the allow-any-agent switch from the console

Console-only by design, so there is no config.xml entry and no settings-form
field. Without arguments it reports where the switch is on, which is the
question that matters after a testing session."
```

---

### Task 7: The allowlist editor

**Files:**
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-access/index.ts`
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-access/merchant-quote-agent-access.html.twig`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/index.ts`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`, `snippet/de.json`

**Interfaces:**
- Consumes: Agentic Commerce's admin API — `GET /api/_admin/ucp/sales-channels`, `GET|PUT /api/_admin/ucp/sales-channels/{id}/config`.
- Produces: the route `merchant.quote.agent.access` and the component `merchant-quote-agent-access`.

**Note on testing:** this plugin has no Administration test harness — issue #39 tracks that gap — so this task's verification is the manual pass in step 5. Do not add a harness here; it is its own issue.

- [ ] **Step 1: Write the component**

Create `page/merchant-quote-agent-access/index.ts`:

```ts
import template from './merchant-quote-agent-access.html.twig';

/**
 * Edits the three UCP allowlists for one sales channel.
 *
 * The data belongs to the Agentic Commerce plugin, so this page drives that
 * plugin's own admin API rather than writing its table: the PUT goes through its
 * UcpConfigService, which validates every host and merges the payload over the
 * stored config. We therefore send only the three keys we edit, and anything set
 * by console survives untouched.
 *
 * Saving needs that plugin's `ucp.editor` privilege, which is separate from this
 * plugin's own. A 403 from it is shown as-is rather than reported as success.
 */
Shopware.Component.register('merchant-quote-agent-access', {
    template,

    inject: ['httpClient', 'syncService', 'acl'],

    data(): Record<string, unknown> {
        return {
            salesChannels: [],
            salesChannelId: null,
            lists: { platformAllowlist: '', remoteProfileAllowlist: '', agentAllowlist: '' },
            isLoading: false,
            isSaving: false,
            error: null,
        };
    },

    computed: {
        canEdit(): boolean {
            return this.acl.can('ucp.editor');
        },
    },

    created() {
        this.loadSalesChannels();
    },

    methods: {
        headers(): Record<string, string> {
            return this.syncService.getBasicHeaders();
        },

        async loadSalesChannels(): Promise<void> {
            this.isLoading = true;
            this.error = null;

            try {
                const { data } = await this.httpClient.get('_admin/ucp/sales-channels', { headers: this.headers() });
                this.salesChannels = data?.data ?? data ?? [];
                this.salesChannelId = this.salesChannels[0]?.id ?? null;

                if (this.salesChannelId) {
                    await this.loadConfig();
                }
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isLoading = false;
            }
        },

        async loadConfig(): Promise<void> {
            this.isLoading = true;
            this.error = null;

            try {
                const { data } = await this.httpClient.get(
                    `_admin/ucp/sales-channels/${this.salesChannelId}/config`,
                    { headers: this.headers() },
                );
                const config = data?.data ?? data ?? {};

                this.lists = {
                    platformAllowlist: this.toText(config.platformAllowlist),
                    remoteProfileAllowlist: this.toText(config.remoteProfileAllowlist),
                    agentAllowlist: this.toText(config.agentAllowlist),
                };
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isLoading = false;
            }
        },

        async save(): Promise<void> {
            this.isSaving = true;
            this.error = null;

            try {
                await this.httpClient.put(
                    `_admin/ucp/sales-channels/${this.salesChannelId}/config`,
                    {
                        platformAllowlist: this.fromText(this.lists.platformAllowlist),
                        remoteProfileAllowlist: this.fromText(this.lists.remoteProfileAllowlist),
                        agentAllowlist: this.fromText(this.lists.agentAllowlist),
                    },
                    { headers: this.headers() },
                );
                await this.loadConfig();
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isSaving = false;
            }
        },

        toText(values: unknown): string {
            return Array.isArray(values) ? values.join('\n') : '';
        },

        /**
         * Splits on newlines and commas so a pasted list works, trims, drops
         * blanks and de-duplicates while keeping the typed order. Entries are
         * otherwise left alone: the Agentic Commerce plugin normalizes them and
         * names the offending host by path if one is malformed.
         */
        fromText(text: string): string[] {
            const entries = String(text ?? '')
                .split(/[\n,]/)
                .map((entry) => entry.trim())
                .filter((entry) => entry !== '');

            return entries.filter((entry, index) => entries.indexOf(entry) === index);
        },

        messageFor(error: unknown): string {
            const response = (error as { response?: { status?: number; data?: { errors?: { detail?: string }[] } } })?.response;
            const detail = response?.data?.errors?.[0]?.detail;

            if (response?.status === 403) {
                return detail ?? 'This user lacks the Agentic Commerce ucp.editor privilege.';
            }

            return detail ?? 'Request failed.';
        },
    },
});
```

- [ ] **Step 2: Write the template**

Create `page/merchant-quote-agent-access/merchant-quote-agent-access.html.twig`:

```twig
{% block merchant_quote_agent_access %}
<sw-page class="merchant-quote-agent-access">
    {% block merchant_quote_agent_access_content %}
    <template #content>
        <sw-card-view>
            <mt-card
                :title="$tc('merchant-quote-agent.access.cardTitle')"
                :is-loading="isLoading"
            >
                <p class="merchant-quote-agent-access__hint">
                    {{ $tc('merchant-quote-agent.access.hint') }}
                </p>

                <mt-select
                    :label="$tc('merchant-quote-agent.access.salesChannelLabel')"
                    :options="salesChannels.map((channel) => ({ value: channel.id, label: channel.name }))"
                    :model-value="salesChannelId"
                    :disabled="isLoading"
                    @update:model-value="(value) => { salesChannelId = value; loadConfig(); }"
                />

                <mt-banner v-if="error" variant="critical">
                    {{ error }}
                </mt-banner>

                <mt-textarea
                    :label="$tc('merchant-quote-agent.access.platformAllowlistLabel')"
                    :help-text="$tc('merchant-quote-agent.access.platformAllowlistHelp')"
                    :disabled="!canEdit || isLoading"
                    :model-value="lists.platformAllowlist"
                    @update:model-value="(value) => { lists.platformAllowlist = value; }"
                />

                <mt-textarea
                    :label="$tc('merchant-quote-agent.access.remoteProfileAllowlistLabel')"
                    :help-text="$tc('merchant-quote-agent.access.remoteProfileAllowlistHelp')"
                    :disabled="!canEdit || isLoading"
                    :model-value="lists.remoteProfileAllowlist"
                    @update:model-value="(value) => { lists.remoteProfileAllowlist = value; }"
                />

                <mt-textarea
                    :label="$tc('merchant-quote-agent.access.agentAllowlistLabel')"
                    :help-text="$tc('merchant-quote-agent.access.agentAllowlistHelp')"
                    :disabled="!canEdit || isLoading"
                    :model-value="lists.agentAllowlist"
                    @update:model-value="(value) => { lists.agentAllowlist = value; }"
                />

                <mt-button
                    variant="primary"
                    :disabled="!canEdit || isSaving || !salesChannelId"
                    @click="save"
                >
                    {{ $tc('merchant-quote-agent.access.save') }}
                </mt-button>
            </mt-card>
        </sw-card-view>
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

- [ ] **Step 3: Register the route and navigation**

In `module/merchant-quote-agent/index.ts`, add `import './page/merchant-quote-agent-access';` beside the other two page imports, then add to `routes`:

```ts
        access: {
            component: 'merchant-quote-agent-access',
            path: 'access',
            meta: {
                parentPath: 'merchant.quote.agent.index',
                // The Agentic Commerce plugin's privileges, deliberately: this
                // page reads and writes that plugin's config through its API, so
                // its ACL is the one that actually gates the data.
                privilege: 'ucp.viewer',
            },
        },
```

and a second navigation entry after the existing one:

```ts
        {
            id: 'merchant-quote-agent-access',
            label: 'merchant-quote-agent.access.mainMenuItem',
            path: 'merchant.quote.agent.access',
            parent: 'sw-order',
            position: 31,
            privilege: 'ucp.viewer',
        },
```

- [ ] **Step 4: Add the snippets**

In `snippet/en.json`, under `merchant-quote-agent`, add an `access` object:

```json
        "access": {
            "mainMenuItem": "Agent access",
            "cardTitle": "Agent access",
            "hint": "One host per line. An entry also covers its subdomains; an empty list is not a deny-all (see the README), so example.com admits agent.example.com. Saving needs the Agentic Commerce ucp.editor privilege.",
            "salesChannelLabel": "Sales channel",
            "platformAllowlistLabel": "Agent platforms",
            "platformAllowlistHelp": "Hosts allowed to act as an agent platform when identity linking is used.",
            "remoteProfileAllowlistLabel": "Profile hosts",
            "remoteProfileAllowlistHelp": "Hosts whose agent profile this shop may fetch. Profiles are fetched over HTTPS only.",
            "agentAllowlistLabel": "Agent domains",
            "agentAllowlistHelp": "Domains whose agents may transact with this sales channel.",
            "save": "Save allowlists"
        }
```

and the German equivalent in `snippet/de.json`:

```json
        "access": {
            "mainMenuItem": "Agenten-Zugriff",
            "cardTitle": "Agenten-Zugriff",
            "hint": "Ein Host pro Zeile. Ein Eintrag gilt auch für Subdomains; eine leere Liste verbietet nicht alles (siehe README), example.com lässt also agent.example.com zu. Zum Speichern wird die Agentic-Commerce-Berechtigung ucp.editor benötigt.",
            "salesChannelLabel": "Verkaufskanal",
            "platformAllowlistLabel": "Agenten-Plattformen",
            "platformAllowlistHelp": "Hosts, die beim Identity Linking als Agenten-Plattform auftreten dürfen.",
            "remoteProfileAllowlistLabel": "Profil-Hosts",
            "remoteProfileAllowlistHelp": "Hosts, deren Agenten-Profil dieser Shop abrufen darf. Profile werden ausschließlich über HTTPS abgerufen.",
            "agentAllowlistLabel": "Agenten-Domains",
            "agentAllowlistHelp": "Domains, deren Agenten mit diesem Verkaufskanal handeln dürfen.",
            "save": "Allowlists speichern"
        }
```

Then verify both files still parse and carry the same keys:

```bash
python3 -c "
import json
en = json.load(open('src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json'))
de = json.load(open('src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json'))
a, b = en['merchant-quote-agent']['access'], de['merchant-quote-agent']['access']
assert a.keys() == b.keys(), (a.keys() ^ b.keys())
print('snippets valid, %d keys each' % len(a))
"
```

- [ ] **Step 5: Verify it by hand in the shop**

```bash
scripts/sync-to-shop.sh
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/console cache:clear -n
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/build-administration.sh
```

Then in the Administration: open the new **Agent access** entry, pick the storefront sales channel, add `agent.example` to Agent domains, save, and confirm with:

```bash
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/console ucp:config:show --sales-channel=<id>
```

Expected: `agentAllowlist` contains `agent.example`, and `signaturePolicy`, `enabledCapabilities` and the other console-managed keys are unchanged — that is the merge behaviour the design relies on. **Then remove the entry again** so the shop is left as you found it, and report both outputs.

If `bin/build-administration.sh` is unavailable or fails in this container, say so in your report rather than skipping the manual check silently.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent
git commit -m "feat: edit the UCP allowlists from our own admin page

Drives the Agentic Commerce plugin's own config API rather than its table, so
its validation and its merge semantics apply and console-set keys survive. Gated
by that plugin's ucp.viewer/ucp.editor privileges, because the data is theirs;
a 403 is shown as-is."
```

---

### Task 8: Close-out

**Files:**
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-09-01-agent-access-controls-design.md`

- [ ] **Step 1: Document both surfaces in the README**

Add after the "Buyer-facing quote endpoints" section:

```markdown
## Deciding which agents may transact

The three UCP allowlists — agent platforms, profile hosts, agent domains — are
edited per sales channel under **Agent access** in this plugin's admin module.
They are Agentic Commerce's data; this page drives that plugin's own config API,
so a save needs its `ucp.editor` privilege in addition to this plugin's, and its
validation reports a malformed host rather than silently dropping it. An empty
list allows nothing, and an entry also covers its subdomains.

For a throwaway agent host that changes between sessions, the switch is console
only:

    bin/console merchant-quote-agent:allow-any-agent                     # where is it on?
    bin/console merchant-quote-agent:allow-any-agent <salesChannelId> --on
    bin/console merchant-quote-agent:allow-any-agent <salesChannelId> --off

While it is on, that sales channel admits whichever agent a request presents —
in the per-channel gates and in the installation-wide profile-fetch list. It
widens an identity allowlist and nothing else: the agent must still publish a
profile the shop can fetch, every other safety check still runs (https only,
ports 443/8443, no redirects, no private or link-local addresses, blocked
metadata hosts), and whether the fetched profile's signature must verify is
governed by the channel's `signaturePolicy`, not by this switch.
```

- [ ] **Step 2: Correct the spec's two inaccuracies**

Three corrections, all places where the spec promised something this plan does not deliver:

1. The editor is not "a per-sales-channel section on this plugin's existing admin page" — those pages are audit views over decision records. It has its own route and navigation entry in the same module.
2. It is gated by Agentic Commerce's `ucp.viewer` / `ucp.editor`, not a privilege of ours, because that plugin's API is what enforces access to its data.
3. The Testing section promises unit coverage of "the list-parsing helper for the editor" and an integration test for "the editor's round-trip against the live shop's config API". Neither exists: this plugin has no Administration test harness (issue #39), and the merge behaviour that round-trip would assert belongs to Agentic Commerce and is tested there. Say so plainly, and note that the editor's coverage is the manual pass in this plan's Task 7.

- [ ] **Step 3: Run the whole gate**

```bash
composer run quality
vendor/bin/phpunit --testsuite unit
composer run test:integration
```

Expected: `quality` clean; unit green (354 plus this plan's additions); integration green except the two pre-existing `PluginConfigTest` failures. Report the numbers rather than "all green".

- [ ] **Step 4: Commit**

```bash
git add README.md docs/superpowers/specs/2026-09-01-agent-access-controls-design.md
git commit -m "docs: how to decide which agents may transact

Records both surfaces and what the console switch does and does not widen -
including that signaturePolicy, not this switch, governs whether a fetched
profile's signature must verify."
```

---

## After the plan

Two things this deliberately leaves open:

- **`signaturePolicy` is still `log` on the dev shop**, so a fetched profile's signature does not have to verify there. That is the setting that actually governs agent authenticity, and it deserves its own decision rather than riding along here.
- **The Administration has no test harness** (issue #39), so Task 7 is covered by a manual pass only. If that harness lands, the editor's `fromText` parsing and the 403 path are the first things worth covering.
