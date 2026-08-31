# Buyer-Facing Quote Transport Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the buyer-facing half of `com.shopware.quote` — six `/ucp/quotes*` endpoints, the capability's operations, the buyer-side SwagCommercial gateway and the identity work behind them — out of the Agentic Commerce fork and into this plugin, so the capability this plugin advertises is actually reachable on a shop running Agentic Commerce unmodified from `main`.

**Architecture:** Three layers, one direction of dependency. `Identity` turns a presented bearer token into a customer's `SalesChannelContext` and knows nothing about quotes. `Bridge` gains a second port beside the existing merchant-side gateway: the buyer-side gateway calls SwagCommercial's Store API routes in that customer context. `Ucp` gains the transport (a controller under `/ucp/`, inheriting the SDK's `UCP-Agent` requirement, signature policy and error envelopes) plus the capability's six guard-then-delegate operations.

**Tech Stack:** PHP 8.3, Shopware 6.7 (`shopware/core` ~6.7.0), `ucp-php-sdk/core` + `ucp-php-sdk/symfony-bundle`, Doctrine DBAL 4.4, PHPUnit 11.5, Mago (format/lint/analyze), SwagCommercial 7.13.1 as a runtime-detected soft dependency.

**Spec:** `docs/superpowers/specs/2026-08-31-buyer-quote-transport-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- Gate thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines per file.
- Target PHP 8.3. No `echo`/`var_dump`/`print_r`/`dd` in application code; PSR-3 logger only.
- Throw `Throwable` subclasses only; preserve `$previous` when wrapping; no `return`/`throw` from `finally`.
- SwagCommercial is **never** a composer requirement. Class existence decides whether a service is built (`CommercialAvailability::isAvailableByClass()`), the license toggle decides whether it can serve (`CommercialAvailability::isLicensed()`), per ADR 0001.
- Every new composer dependency must be declared in `composer.json` before `composer run quality:depcheck` passes. This plan adds exactly two: `ucp-php-sdk/symfony-bundle` (same version constraint style as `ucp-php-sdk/core`: `>=0.0.5 <0.1.0`) and `symfony/http-kernel: ^7.4`.
- Route paths and names: paths come from constants, never string literals repeated across files. Route names are prefixed `frontend.merchant_quote_agent.` so a shop that also installs a fork build gets a visible collision rather than a silent override.
- Storefront route scope is written as the literal `'storefront'`, not `StorefrontRouteScope::ID` — `shopware/storefront` is not a dependency of this plugin. `src/Ucp/Quote/QuoteContractController.php` already documents why.
- Scope is **carried, never enforced**: `?string $requiredScope` exists on the authenticator and every caller passes `null`. Do not add scope enforcement in this plan.
- Bearer tokens only. There is no `sw-context-token` path anywhere in this plan.
- Unit tests never boot a kernel (`tests/Unit`, `phpunit.xml.dist`). Integration tests run inside the shop container (`composer run test:integration`, `phpunit.integration.xml.dist`) and each runs in a rolled-back transaction.

**Fork checkout used as the port source.** Every port step below assumes:

```bash
export FORK=/Users/sebastian/projects/agentic-commerce
```

Three refs matter: `feat/a2cn-act-carrier` (identity, gateway, DTOs, validator), `quote-management` (the capability), `test/mandate-on-sdk-compat` (the controller). Never copy the working tree; always `git show <ref>:<path>`.

---

### Task 1: Identity value objects and the access-token port

**Files:**
- Create: `src/Identity/AgentCustomerCredential.php`
- Create: `src/Identity/OAuthAccessTokenInfo.php`
- Create: `src/Identity/AccessTokenSubjectReaderInterface.php`
- Create: `src/Identity/AcOAuthAccessTokenReader.php`
- Test: `tests/Unit/Identity/AcOAuthAccessTokenReaderTest.php`
- Test: `tests/Unit/Identity/AgentCustomerCredentialTest.php`
- Modify: `composer.json`, `composer.lock`

**Interfaces:**
- Consumes: nothing.
- Produces: `AgentCustomerCredential::fromAccessToken(string $accessToken): self` and `::fromAuthorizationHeader(string $header): self` with `public readonly string $accessToken`; `OAuthAccessTokenInfo::__construct(string $salesChannelId, string $clientId, string $subject, list<string> $scopes)` plus `hasScope(string $scope): bool`; `AccessTokenSubjectReaderInterface::find(string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo`; `AcOAuthAccessTokenReader` implementing it over `Doctrine\DBAL\Connection`.

- [ ] **Step 1: Add the composer dependency this task needs**

```bash
composer require --no-update --no-interaction "symfony/http-kernel:^7.4"
composer update --lock --no-interaction
```

`symfony/http-kernel` is for `UnauthorizedHttpException`, thrown from this task onwards. It is already installed in the shop through Shopware itself, so nothing changes at runtime — declaring it is what makes `composer run quality:depcheck` pass.

Declare **only** this one. `ucp-php-sdk/symfony-bundle` is not used until Task 6, and a dependency nothing imports yet makes `quality:depcheck` fail as an unused package — silencing that with an `ignoreErrorsOnPackage` entry trades a real guard for a config entry somebody has to remember to delete. Task 6 declares it in the same commit that first imports it.

- [ ] **Step 2: Write the failing test for the credential**

Create `tests/Unit/Identity/AgentCustomerCredentialTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(AgentCustomerCredential::class)]
final class AgentCustomerCredentialTest extends TestCase
{
    public function testItCarriesTheBearerToken(): void
    {
        $credential = AgentCustomerCredential::fromAccessToken('ucp_at_abc');

        self::assertSame('ucp_at_abc', $credential->accessToken);
    }

    public function testItRejectsAnEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AgentCustomerCredential::fromAccessToken('');
    }

    public function testItReadsABearerAuthorizationHeader(): void
    {
        $credential = AgentCustomerCredential::fromAuthorizationHeader('Bearer ucp_at_abc');

        self::assertSame('ucp_at_abc', $credential->accessToken);
    }

    #[DataProvider('unusableHeaders')]
    public function testItRefusesAnythingThatIsNotABearerToken(string $header): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        AgentCustomerCredential::fromAuthorizationHeader($header);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableHeaders(): iterable
    {
        yield 'absent' => [''];
        yield 'basic' => ['Basic dXNlcjpwYXNz'];
        yield 'bearer without a token' => ['Bearer '];
        yield 'lowercase scheme' => ['bearer ucp_at_abc'];
    }
}
```

The header cases live here, on a class with no dependencies, rather than on the controller: `UcpResponseFactory` and `QuoteCapability` are both `final`, so a controller unit test would have to build the whole graph to reach two lines of string handling. Task 6's kernel-level integration tests cover the controller.

- [ ] **Step 3: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentCustomerCredential`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential" not found`.

- [ ] **Step 4: Write the credential**

Create `src/Identity/AgentCustomerCredential.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * How an agent proves it may act for a customer: an OAuth access token issued
 * by the shop's identity-linking capability.
 *
 * Bearer only, by design. The customer's consent is recorded server-side, the
 * token is scoped and revocable, and the published contract states that an
 * unscoped Shopware context token is not accepted — so there is no second
 * constructor and no second trust boundary to reason about.
 */
final class AgentCustomerCredential
{
    private function __construct(
        public readonly string $accessToken,
    ) {
    }

    public static function fromAccessToken(string $accessToken): self
    {
        if ('' === $accessToken) {
            throw new \InvalidArgumentException('An OAuth access token cannot be empty.');
        }

        return new self($accessToken);
    }

    /**
     * Reads the credential straight off the request's Authorization header.
     *
     * A missing or non-Bearer header is a 401 rather than a 400: the caller
     * has not authenticated, and `UnauthorizedHttpException` carries the
     * `WWW-Authenticate: Bearer` challenge with it. The scheme comparison is
     * case-sensitive on purpose — RFC 6750 spells it `Bearer`, and accepting
     * variants only hides a broken client.
     */
    public static function fromAuthorizationHeader(string $header): self
    {
        if (!str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedHttpException('Bearer', 'An identity-linking access token is required.');
        }

        $token = trim(substr($header, 7));

        if ('' === $token) {
            throw new UnauthorizedHttpException('Bearer', 'An identity-linking access token is required.');
        }

        return new self($token);
    }
}
```

- [ ] **Step 5: Run it to make sure it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentCustomerCredential`
Expected: PASS (7 tests — two token cases plus the five header cases).

- [ ] **Step 6: Port the token info value object**

```bash
git -C "$FORK" show feat/a2cn-act-carrier:src/Ucp/Identity/OAuthAccessTokenInfo.php \
  > src/Identity/OAuthAccessTokenInfo.php
```

Then apply exactly two edits to `src/Identity/OAuthAccessTokenInfo.php`:
1. `namespace Swag\AgenticCommerce\Ucp\Identity;` → `namespace MerchantQuoteAgentPlugin\Identity;`
2. Delete the ` * @internal` line and the blank comment line above it (this plugin does not use `@internal`; nothing else in `src/` does).

- [ ] **Step 7: Write the failing test for the reader**

Create `tests/Unit/Identity/AcOAuthAccessTokenReaderTest.php`. The reader is the only class that knows Agentic Commerce's schema, so the test pins the table, the hash and the rejection rules:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\AcOAuthAccessTokenReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcOAuthAccessTokenReader::class)]
final class AcOAuthAccessTokenReaderTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testItLooksTheTokenUpByRawSha256AndSalesChannel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static fn (string $sql): bool
                    => str_contains($sql, 'swag_agentic_commerce_ucp_oauth_access_token')
                    && str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token')
                    && str_contains($sql, 'LEFT JOIN')),
                self::callback(static fn (array $params): bool
                    => $params['tokenHash'] === hash('sha256', 'ucp_at_abc', true)),
            )
            ->willReturn([
                'sales_channel_id' => self::SALES_CHANNEL_ID,
                'client_id' => 'agent-client',
                'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                'scope' => 'dev.ucp.shopping.cart:manage com.shopware.quote:manage',
                'expires_at' => time() + 60,
                'revoked_at' => null,
            ]);

        $info = (new AcOAuthAccessTokenReader($connection))->find('ucp_at_abc', self::SALES_CHANNEL_ID);

        self::assertNotNull($info);
        self::assertSame('0191d3d0a0b071bd9c1a0d9d1a3f9f02', $info->subject);
        self::assertSame('agent-client', $info->clientId);
        self::assertTrue($info->hasScope('com.shopware.quote:manage'));
    }

    public function testItReturnsNullForAnUnknownToken(): void
    {
        self::assertNull($this->readerReturning(false)->find('ucp_at_abc', self::SALES_CHANNEL_ID));
    }

    public function testItReturnsNullForAnExpiredToken(): void
    {
        $reader = $this->readerReturning($this->row(['expires_at' => time() - 1]));

        self::assertNull($reader->find('ucp_at_abc', self::SALES_CHANNEL_ID));
    }

    public function testItReturnsNullWhenTheLinkWasRevoked(): void
    {
        $reader = $this->readerReturning($this->row(['revoked_at' => time() - 5]));

        self::assertNull($reader->find('ucp_at_abc', self::SALES_CHANNEL_ID));
    }

    /**
     * @param array<string, mixed>|false $result
     */
    private function readerReturning(array|false $result): AcOAuthAccessTokenReader
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($result);

        return new AcOAuthAccessTokenReader($connection);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'sales_channel_id' => self::SALES_CHANNEL_ID,
            'client_id' => 'agent-client',
            'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
            'scope' => 'dev.ucp.shopping.cart:manage',
            'expires_at' => time() + 60,
            'revoked_at' => null,
        ], $overrides);
    }
}
```

- [ ] **Step 8: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AcOAuthAccessTokenReader`
Expected: FAIL — reader class and `AccessTokenSubjectReaderInterface` do not exist.

- [ ] **Step 9: Write the port and its only implementation**

Create `src/Identity/AccessTokenSubjectReaderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

/**
 * Read side of an OAuth access-token store.
 *
 * A resource server only ever resolves a presented token; it never issues or
 * revokes one. Everything above this port is free of any knowledge about where
 * tokens live, which is what makes {@see AcOAuthAccessTokenReader} the single
 * place that couples this plugin to Agentic Commerce's schema.
 */
interface AccessTokenSubjectReaderInterface
{
    /**
     * Null when the token is unknown, expired, revoked, or belongs to a
     * different sales channel.
     */
    public function find(string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo;
}
```

Create `src/Identity/AcOAuthAccessTokenReader.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Resolves a bearer token against the Agentic Commerce plugin's OAuth tables.
 *
 * This is the whole coupling to that plugin's storage: the two table names, the
 * token hash (`hash('sha256', $token, true)`, matching its own
 * DoctrineDbalUcpOAuthStore) and the fact that revocation is recorded on the
 * refresh-token row rather than the access-token row. Issue #13 upstream adds a
 * read side to that store; the day it lands, this class becomes a delegate and
 * the rest of the plugin does not move.
 *
 * `AcOAuthAccessTokenReaderTest` pins all three assumptions, and the
 * integration suite issues a token through Agentic Commerce's own writer and
 * reads it back here — that pairing is what fails loudly if it renames
 * anything.
 */
final readonly class AcOAuthAccessTokenReader implements AccessTokenSubjectReaderInterface
{
    private const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';

    private const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function find(string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT LOWER(HEX(a.sales_channel_id)) AS sales_channel_id, a.client_id, a.subject, a.scope,'
                . ' a.expires_at, r.revoked_at'
                . ' FROM `%s` AS a'
                . ' LEFT JOIN `%s` AS r ON r.token_hash = a.refresh_token_hash'
                . ' WHERE a.token_hash = :tokenHash AND a.sales_channel_id = :salesChannelId',
                self::ACCESS_TOKEN_TABLE,
                self::REFRESH_TOKEN_TABLE,
            ),
            [
                'tokenHash' => hash('sha256', $accessToken, true),
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            ],
        );

        if ($row === false) {
            return null;
        }

        if ((int) $row['expires_at'] < time()) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            return null;
        }

        return new OAuthAccessTokenInfo(
            (string) $row['sales_channel_id'],
            (string) $row['client_id'],
            (string) $row['subject'],
            $this->scopeList((string) $row['scope']),
        );
    }

    /**
     * @return list<string>
     */
    private function scopeList(string $scope): array
    {
        return array_values(array_filter(
            explode(' ', trim($scope)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }
}
```

- [ ] **Step 10: Run the tests to make sure they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter "AcOAuthAccessTokenReader|AgentCustomerCredential"`
Expected: PASS (6 tests).

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock src/Identity tests/Unit/Identity
git commit -m "feat: read the identity-linking access token behind a port of ours

Issue #9. The port is what keeps the coupling to Agentic Commerce's OAuth
tables in one class: two table names, the raw sha256 token hash, and
revocation living on the refresh row. Upstream #13 turns it into a delegate."
```

---

### Task 2: Sales-channel resolution for an authenticated customer

**Files:**
- Create: `src/Bridge/SalesChannelResolution.php`
- Create: `src/Bridge/CustomerContextResolverInterface.php`
- Create: `src/Bridge/SalesChannelContextResolver.php`
- Test: `tests/Integration/SalesChannelContextResolverTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `SalesChannelResolution` with `public readonly string $salesChannelId, $languageId, $currencyId, ?string $domainId`; `CustomerContextResolverInterface::resolveSalesChannel(RequestContext $context): SalesChannelResolution` and `::resolveForCustomer(string $customerId, RequestContext $context): SalesChannelContext`, implemented by `SalesChannelContextResolver` (the resolver generates the fresh context token itself — callers never pass one). Note `Ucp\Sdk\Model\RequestContext`'s first constructor parameter is `$host` — it carries no base URI, so resolution is by host.

- [ ] **Step 1: Write the failing integration test**

The fork resolved the domain through an Agentic Commerce class we are not moving, so this is a rewrite and gets a test written against the real shop. Create `tests/Integration/SalesChannelContextResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use Ucp\Sdk\Model\RequestContext;

/**
 * The resolver is the seam between "a UCP request arrived" and "a Shopware
 * customer context exists", so it is only meaningful against a real shop: the
 * sales-channel domain table, the context service and the customer all have to
 * agree.
 */
final class SalesChannelContextResolverTest extends IntegrationTestCase
{
    public function testItResolvesTheSalesChannelFromTheRequestHost(): void
    {
        $domain = $this->anyStorefrontDomain();

        $resolution = $this->resolver()->resolveSalesChannel($this->requestContext($domain['url']));

        self::assertSame($domain['sales_channel_id'], $resolution->salesChannelId);
        self::assertSame($domain['language_id'], $resolution->languageId);
        self::assertSame($domain['currency_id'], $resolution->currencyId);
        self::assertSame($domain['id'], $resolution->domainId);
    }

    public function testItBuildsAContextForACustomerWithoutACustomerSession(): void
    {
        $domain = $this->anyStorefrontDomain();
        $customerId = $this->anyCustomerId($domain['sales_channel_id']);

        $context = $this->resolver()->resolveForCustomer($customerId, $this->requestContext($domain['url']));

        self::assertNotNull($context->getCustomer());
        self::assertSame($customerId, $context->getCustomer()?->getId());
        self::assertSame($domain['sales_channel_id'], $context->getSalesChannelId());
    }

    public function testItRejectsAHostNoSalesChannelServes(): void
    {
        $this->expectException(\Ucp\Sdk\Exception\ConfigurationException::class);

        $this->resolver()->resolveSalesChannel(new RequestContext('not-a-shop.invalid'));
    }

    private function resolver(): SalesChannelContextResolver
    {
        $resolver = static::getContainer()->get(SalesChannelContextResolver::class);
        self::assertInstanceOf(SalesChannelContextResolver::class, $resolver);

        return $resolver;
    }

    /** The SDK's RequestContext carries the request host, not a base URI. */
    private function requestContext(string $domainUrl): RequestContext
    {
        return new RequestContext((string) parse_url($domainUrl, \PHP_URL_HOST));
    }

    /**
     * @return array{id: string, url: string, sales_channel_id: string, language_id: string, currency_id: string}
     */
    private function anyStorefrontDomain(): array
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        $row = $connection->fetchAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, d.url, LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,'
            . ' LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(d.currency_id)) AS currency_id'
            . ' FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE d.url LIKE "http%"'
            . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
            . ' ORDER BY d.url LIMIT 1',
        );

        self::assertIsArray($row, 'the shop has no active sales-channel domain with an absolute URL whose channel has an active customer');

        /** @var array{id: string, url: string, sales_channel_id: string, language_id: string, currency_id: string} $row */
        return $row;
    }

    private function anyCustomerId(string $salesChannelId): string
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        $id = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM customer WHERE sales_channel_id = :scid AND active = 1 LIMIT 1',
            ['scid' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($salesChannelId)],
        );

        self::assertIsString($id, 'the shop has no active customer in this sales channel');

        return $id;
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `composer run test:integration -- --filter SalesChannelContextResolver`
Expected: FAIL — `SalesChannelContextResolver` class not found.

- [ ] **Step 3: Write the resolution value object**

Create `src/Bridge/SalesChannelResolution.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * Which sales channel, language, currency and domain a UCP request landed on.
 * Everything the Shopware context service needs, and nothing else.
 */
final readonly class SalesChannelResolution
{
    public function __construct(
        public string $salesChannelId,
        public string $languageId,
        public string $currencyId,
        public ?string $domainId,
    ) {
    }
}
```

- [ ] **Step 4: Write the port**

The resolver is `final readonly`, which PHPUnit cannot mock, and Task 3's authenticator has to be unit-testable without a kernel. So the two methods it needs are a port — the same shape the plugin already uses for `QuoteGatewayInterface` and `AccessTokenSubjectReaderInterface`. Create `src/Bridge/CustomerContextResolverInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Which shop a UCP request landed on, and the Shopware context of a customer
 * acting there.
 *
 * A port rather than a bare class so the resource-server side can be unit
 * tested without a booted kernel: everything behind it is Shopware
 * infrastructure.
 */
interface CustomerContextResolverInterface
{
    /** @throws ConfigurationException no active sales channel serves the request's host */
    public function resolveSalesChannel(RequestContext $context): SalesChannelResolution;

    /**
     * Materialises the customer's own context, so contract prices, customer
     * group and rules apply exactly as they would if the customer acted. The
     * caller must already have proven the authorization.
     *
     * @throws ConfigurationException
     */
    public function resolveForCustomer(string $customerId, RequestContext $context): SalesChannelContext;
}
```

- [ ] **Step 5: Write the resolver**

Create `src/Bridge/SalesChannelContextResolver.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Turns "a UCP request arrived on this host, for this customer" into a Shopware
 * sales-channel context.
 *
 * The domain lookup is a direct query rather than the DAL: this runs on every
 * buyer request, the answer is one row, and the criteria/repository route costs
 * more than it explains. Matching is on host with any port stripped from both
 * sides, and the shortest URL wins, which picks the bare domain over a
 * path-prefixed one. Two ceilings follow: a shop serving different sales
 * channels on the same host under different paths needs the path compared too,
 * and one serving them on the same host under different ports needs the port
 * kept. Agentic Commerce's own resolver does the path comparison; this is
 * deliberately the simpler thing until a shop needs it.
 *
 * `resolveForCustomer()` mints a fresh context token instead of accepting one:
 * an agent's authority comes from its access token, so it must never need to
 * hold — or be able to reuse — a customer session.
 */
final readonly class SalesChannelContextResolver implements CustomerContextResolverInterface
{
    public function __construct(
        private Connection $connection,
        private SalesChannelContextServiceInterface $contextService,
    ) {
    }

    #[Override]
    public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
    {
        $host = strtolower(trim($context->host));

        if ($host === '') {
            throw new ConfigurationException('The UCP request carries no host, so no sales channel can be resolved.');
        }

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,'
            . ' LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(d.currency_id)) AS currency_id'
            . ' FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(d.url, "://", -1), "/", 1), ":", 1)) = :host'
            . ' ORDER BY CHAR_LENGTH(d.url) ASC LIMIT 1',
            ['host' => strtolower(explode(':', $host)[0])],
        );

        if ($row === false) {
            throw new ConfigurationException(\sprintf('No active sales channel serves the host "%s".', $host));
        }

        return new SalesChannelResolution(
            (string) $row['sales_channel_id'],
            (string) $row['language_id'],
            (string) $row['currency_id'],
            (string) $row['id'],
        );
    }

    #[Override]
    public function resolveForCustomer(string $customerId, RequestContext $context): SalesChannelContext
    {
        $resolution = $this->resolveSalesChannel($context);

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $resolution->salesChannelId,
            Uuid::randomHex(),
            $resolution->languageId,
            $resolution->currencyId,
            $resolution->domainId,
            null,
            $customerId,
        ));
    }
}
```

- [ ] **Step 6: Register the service and its alias**

In `src/Resources/config/services.php`, directly after the `QuoteContractController` block, add:

```php
    // Buyer-side sales-channel resolution. Autowired: Connection and the
    // context service are both core services.
    //
    // ->public() only because nothing injects the resolver yet: Symfony's
    // RemoveUnusedDefinitionsPass drops an unconsumed private definition, and
    // the integration test cannot fetch what the container removed. Task 3's
    // authenticator becomes the consumer; the flag comes out with it.
    $services->set(SalesChannelContextResolver::class)->public();
    $services->alias(CustomerContextResolverInterface::class, SalesChannelContextResolver::class);
```

and add `use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;` plus `use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;` to the import block in alphabetical position (after `MerchantQuoteAgentPlugin\Bridge\QuoteWriters`).

- [ ] **Step 7: Run the integration test to make sure it passes**

Run: `composer run test:integration -- --filter SalesChannelContextResolver`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add src/Bridge/SalesChannelResolution.php src/Bridge/CustomerContextResolverInterface.php \
        src/Bridge/SalesChannelContextResolver.php \
        src/Resources/config/services.php tests/Integration/SalesChannelContextResolverTest.php
git commit -m "feat: resolve a customer's sales-channel context from a UCP request

Issue #9. Rewritten rather than moved: the fork resolved the domain through an
Agentic Commerce class this plugin does not take over. Mints its own context
token so an agent never holds a customer session."
```

---

### Task 3: The authenticator

**Files:**
- Create: `src/Identity/AgentCustomerAuthenticator.php`
- Test: `tests/Unit/Identity/AgentCustomerAuthenticatorTest.php`

**Interfaces:**
- Consumes: `AccessTokenSubjectReaderInterface::find()`, `AgentCustomerCredential::fromAccessToken()`, `OAuthAccessTokenInfo`, `CustomerContextResolverInterface::{resolveSalesChannel,resolveForCustomer}` (the interface, never the final class — PHPUnit cannot mock it).
- Produces: `AgentCustomerAuthenticator::authenticate(AgentCustomerCredential $credential, RequestContext $context, ?string $requiredScope = null): SalesChannelContext`. Throws `UnauthorizedHttpException` for a token problem, `ValidationException` when the token resolves to no customer.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Identity/AgentCustomerAuthenticatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\AccessTokenSubjectReaderInterface;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerAuthenticator;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential;
use MerchantQuoteAgentPlugin\Identity\OAuthAccessTokenInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

#[CoversClass(AgentCustomerAuthenticator::class)]
final class AgentCustomerAuthenticatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const CUSTOMER_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f02';

    public function testItResolvesTheTokenSubjectToTheCustomersContext(): void
    {
        $context = $this->customerContext(self::CUSTOMER_ID);
        $resolver = $this->resolver();
        $resolver->expects(self::once())
            ->method('resolveForCustomer')
            ->with(self::CUSTOMER_ID, self::isInstanceOf(RequestContext::class))
            ->willReturn($context);

        $authenticator = new AgentCustomerAuthenticator($resolver, $this->reader($this->tokenInfo()));

        self::assertSame($context, $authenticator->authenticate($this->credential(), $this->requestContext()));
    }

    public function testItRejectsAnUnknownExpiredOrRevokedToken(): void
    {
        $authenticator = new AgentCustomerAuthenticator($this->resolver(), $this->reader(null));

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Access token is invalid, expired, or revoked.');

        $authenticator->authenticate($this->credential(), $this->requestContext());
    }

    public function testItRejectsATokenIssuedForAnotherSalesChannel(): void
    {
        $foreign = new OAuthAccessTokenInfo('0191d3d0a0b071bd9c1a0d9d1a3f9fff', 'agent-client', self::CUSTOMER_ID, []);
        $authenticator = new AgentCustomerAuthenticator($this->resolver(), $this->reader($foreign));

        $this->expectException(UnauthorizedHttpException::class);

        $authenticator->authenticate($this->credential(), $this->requestContext());
    }

    public function testItRejectsATokenMissingARequiredScopeWhenOneIsAsked(): void
    {
        $authenticator = new AgentCustomerAuthenticator($this->resolver(), $this->reader($this->tokenInfo()));

        $this->expectException(UnauthorizedHttpException::class);

        $authenticator->authenticate($this->credential(), $this->requestContext(), 'com.shopware.quote:manage');
    }

    public function testItFailsWhenTheSubjectIsNoLongerACustomer(): void
    {
        $resolver = $this->resolver();
        $resolver->method('resolveForCustomer')->willReturn($this->customerContext(null));

        $authenticator = new AgentCustomerAuthenticator($resolver, $this->reader($this->tokenInfo()));

        $this->expectException(ValidationException::class);

        $authenticator->authenticate($this->credential(), $this->requestContext());
    }

    private function credential(): AgentCustomerCredential
    {
        return AgentCustomerCredential::fromAccessToken('ucp_at_abc');
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('shop.example');
    }

    private function tokenInfo(): OAuthAccessTokenInfo
    {
        return new OAuthAccessTokenInfo(self::SALES_CHANNEL_ID, 'agent-client', self::CUSTOMER_ID, ['dev.ucp.shopping.cart:manage']);
    }

    private function reader(?OAuthAccessTokenInfo $info): AccessTokenSubjectReaderInterface
    {
        $reader = $this->createMock(AccessTokenSubjectReaderInterface::class);
        $reader->method('find')->willReturn($info);

        return $reader;
    }

    private function resolver(): CustomerContextResolverInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver->method('resolveSalesChannel')->willReturn(
            new SalesChannelResolution(self::SALES_CHANNEL_ID, 'l', 'c', 'd'),
        );

        return $resolver;
    }

    private function customerContext(?string $customerId): SalesChannelContext
    {
        $customer = null;

        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentCustomerAuthenticator`
Expected: FAIL — `AgentCustomerAuthenticator` not found.

- [ ] **Step 3: Write the authenticator**

Create `src/Identity/AgentCustomerAuthenticator.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Resource-server side of identity linking: turns an agent's bearer token into
 * the customer's sales-channel context, or refuses.
 *
 * Token failures are `UnauthorizedHttpException` rather than the SDK's
 * OAuthException on purpose. The SDK's ExceptionListener maps OAuthException to
 * 400, but a rejected bearer token has to be 401 — and that listener honours
 * any HttpExceptionInterface with the exception's own status, so this way the
 * response is a real 401 inside a UCP error envelope.
 *
 * `$requiredScope` is wired but every caller passes null: Agentic Commerce
 * intersects requested scopes against a private constant that does not include
 * `com.shopware.quote:manage`, so no token can carry it yet. Authorization for
 * now is ownership — the token's subject names the customer, and the commercial
 * Store API routes filter by customer and sales channel themselves.
 */
final readonly class AgentCustomerAuthenticator
{
    public function __construct(
        private CustomerContextResolverInterface $contextResolver,
        private AccessTokenSubjectReaderInterface $accessTokenReader,
    ) {
    }

    public function authenticate(
        AgentCustomerCredential $credential,
        RequestContext $requestContext,
        ?string $requiredScope = null,
    ): SalesChannelContext {
        $resolution = $this->contextResolver->resolveSalesChannel($requestContext);
        $token = $this->accessTokenReader->find($credential->accessToken, $resolution->salesChannelId);

        if ($token === null || $token->salesChannelId !== $resolution->salesChannelId) {
            throw new UnauthorizedHttpException('Bearer', 'Access token is invalid, expired, or revoked.');
        }

        if ($requiredScope !== null && !$token->hasScope($requiredScope)) {
            throw new UnauthorizedHttpException(
                'Bearer',
                \sprintf('Access token is missing the required scope "%s".', $requiredScope),
            );
        }

        $context = $this->contextResolver->resolveForCustomer($token->subject, $requestContext);

        if ($context->getCustomer() === null) {
            throw new ValidationException(
                'The access token does not identify a customer.',
                ['$.headers.authorization must carry an identity-linking access token for an existing customer'],
            );
        }

        return $context;
    }
}
```

- [ ] **Step 4: Register the service, retire Task 2's scaffolding, and fix one stale sentence**

`src/Bridge/SalesChannelContextResolver.php`'s class comment opens with `Turns "a UCP request arrived on this base URI, for this customer"`, which contradicts the paragraph below it: resolution is by host, and the SDK's `RequestContext` carries no base URI at all. Replace that first sentence with:

```
 * Turns "a UCP request arrived on this host, for this customer" into a Shopware
 * sales-channel context.
```

Change nothing else in that file.

Task 2 marked `SalesChannelContextResolver` `->public()` with a comment saying this task removes it: the flag existed only because nothing injected the resolver, so Symfony's `RemoveUnusedDefinitionsPass` deleted the definition and the integration test could not fetch it. Your authenticator is that consumer. Drop `->public()` and its four-line comment, keep `$services->set(SalesChannelContextResolver::class);` and the alias, and prove the graph really consumes it by re-running Task 2's test at the end of this task:

```bash
composer run test:integration -- --filter SalesChannelContextResolver
```

Expected: still 3 passing. A "removed or inlined" error means nothing injects the resolver after all — report that rather than restoring the flag.

In `src/Resources/config/services.php`, after the `SalesChannelContextResolver::class` line:

```php
    // Identity: bearer token → customer context. The reader is the only class
    // that knows Agentic Commerce's OAuth schema (issue #13 retires it).
    $services->set(AcOAuthAccessTokenReader::class);
    $services->alias(AccessTokenSubjectReaderInterface::class, AcOAuthAccessTokenReader::class);
    $services->set(AgentCustomerAuthenticator::class);
```

Add the three matching `use` statements for `MerchantQuoteAgentPlugin\Identity\{AccessTokenSubjectReaderInterface, AcOAuthAccessTokenReader, AgentCustomerAuthenticator}`.

- [ ] **Step 5: Run the test to make sure it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter AgentCustomerAuthenticator`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Identity/AgentCustomerAuthenticator.php \
        src/Resources/config/services.php tests/Unit/Identity/AgentCustomerAuthenticatorTest.php
git commit -m "feat: authenticate a buyer agent by its identity-linking token

Issue #9. Token failures throw UnauthorizedHttpException so the SDK's listener
yields a real 401 in a UCP envelope; its OAuthException maps to 400. Scope is
carried but not enforced until Agentic Commerce can issue ours."
```

---

### Task 4: The buyer-side gateway in Bridge

**Files:**
- Create: `src/Bridge/BuyerQuoteGatewayInterface.php`
- Create: `src/Bridge/SwagCommercialBuyerQuoteGateway.php`
- Create: `src/Ucp/Quote/QuoteSnapshot.php`
- Create: `src/Ucp/Quote/QuoteList.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Integration/BuyerQuoteFlowTest.php`

**Interfaces:**
- Consumes: `SalesChannelContext` from Task 3's authenticator (the gateway never sees a credential), `CommercialAvailability::{isAvailableByClass,isLicensed}`.
- Produces: `BuyerQuoteGatewayInterface` with `isAvailable(): bool`, `requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot`, `getQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot`, `listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList`, `counterQuote(SalesChannelContext $context, string $quoteId, array $lineItems, ?string $comment): QuoteSnapshot`, `acceptQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot`, `declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot`. Plus `QuoteSnapshot::toArray()` and `QuoteList::toArray()`.

- [ ] **Step 1: Port the two response DTOs**

```bash
git -C "$FORK" show feat/a2cn-act-carrier:src/Ucp/Quote/QuoteSnapshot.php > src/Ucp/Quote/QuoteSnapshot.php
git -C "$FORK" show feat/a2cn-act-carrier:src/Ucp/Quote/QuoteList.php     > src/Ucp/Quote/QuoteList.php
```

Then edit both files:
1. `namespace Swag\AgenticCommerce\Ucp\Quote;` → `namespace MerchantQuoteAgentPlugin\Ucp\Quote;`
2. Delete the ` * @internal` line and the blank comment line above it.
3. In `QuoteSnapshot`: delete the `$a2cnActs` constructor parameter, its `@param` line, and the `if ([] !== $this->a2cnActs)` block in `toArray()`. A2CN is out of scope for this plan, and an unused parameter would be dead flexibility.

- [ ] **Step 2: Write the failing integration test for the buyer flow**

Create `tests/Integration/BuyerQuoteFlowTest.php`. This is the test that proves the gateway works against the real commercial backend; Task 6 adds the HTTP-level tests on top.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The buyer path end to end through SwagCommercial's Store API routes, in a
 * real customer's sales-channel context: request a quote, read it back, list
 * it. Runs in a rolled-back transaction like every integration test here, so
 * the quotes it creates do not accumulate.
 */
final class BuyerQuoteFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testItRequestsAQuoteAndReadsItBack(): void
    {
        $context = $this->buyerContext();
        $productId = QuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());

        $snapshot = $this->gateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 3, 'requested_unit_price' => 9.99]],
            'Three of these, please.',
        );

        self::assertNotSame('', $snapshot->id);
        self::assertSame('open', $snapshot->state);
        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(9.99, $snapshot->lineItems[0]['requested_unit_price']);

        $reread = $this->gateway()->getQuote($context, $snapshot->id);

        self::assertSame($snapshot->id, $reread->id);
        self::assertNotSame([], $reread->comments);
    }

    public function testItListsOnlyTheAuthenticatedCustomersQuotes(): void
    {
        $context = $this->buyerContext();
        $productId = QuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());

        $created = $this->gateway()->requestQuote($context, [['product_id' => $productId, 'quantity' => 1]], null);
        $list = $this->gateway()->listQuotes($context, 25, 1);

        $ids = array_map(static fn (QuoteSnapshot $quote): string => $quote->id, $list->quotes);

        self::assertContains($created->id, $ids);
        self::assertLessThanOrEqual(25, $list->limit);
    }

    public function testAnUnknownQuoteIdIsNotFound(): void
    {
        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->gateway()->getQuote($this->buyerContext(), \Shopware\Core\Framework\Uuid\Uuid::randomHex());
    }

    /**
     * The trust boundary: a real quote that belongs to somebody else must be
     * indistinguishable from one that does not exist, so a probing agent
     * cannot confirm existence.
     */
    public function testAnotherCustomersQuoteIsNotFound(): void
    {
        $context = $this->buyerContext();
        $foreignQuoteId = QuoteFixture::anyQuoteIdNotOwnedBy(
            static::getContainer(),
            $context->getCustomer()?->getId() ?? '',
        );

        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->gateway()->getQuote($context, $foreignQuoteId);
    }

    public function testACustomerWithoutTheQuoteFeatureIsToldWhichFlagIsMissing(): void
    {
        $context = $this->buyerContextWithoutQuoteFeature();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/QUOTE_MANAGEMENT/');

        $this->gateway()->requestQuote($context, [['product_id' => \Shopware\Core\Framework\Uuid\Uuid::randomHex(), 'quantity' => 1]], null);
    }

    private function gateway(): BuyerQuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        return $gateway;
    }

    private function buyerContext(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        return $this->contextFor(QuoteFixture::anyQuoteCapableCustomerId(static::getContainer()));
    }

    private function buyerContextWithoutQuoteFeature(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        return $this->contextFor(QuoteFixture::anyCustomerWithoutQuoteFeature(static::getContainer()));
    }

    private function contextFor(string $customerId): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        $resolver = static::getContainer()->get(SalesChannelContextResolver::class);
        self::assertInstanceOf(SalesChannelContextResolver::class, $resolver);

        return $resolver->resolveForCustomer(
            $customerId,
            new RequestContext(QuoteFixture::storefrontHost(static::getContainer())),
        );
    }
}
```

- [ ] **Step 3: Extend the fixture with the four helpers this test needs**

Add to `tests/Integration/QuoteFixture.php` (the class already exists and already holds this kind of shop lookup):

```php
    /**
     * The base URI of an active storefront domain — what a UCP request to this
     * shop would carry, and what SalesChannelContextResolver matches against.
     */
    public static function storefrontBaseUri(ContainerInterface $container): string
    {
        $url = self::connection($container)->fetchOne(
            'SELECT d.url FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE d.url LIKE "http%"'
            . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
            . ' ORDER BY d.url LIMIT 1',
        );

        if (!\is_string($url)) {
            throw new \RuntimeException('The shop has no active sales-channel domain with an absolute URL whose channel has an active customer.');
        }

        return rtrim($url, '/');
    }

    /**
     * The sales channel behind that domain. Every customer, token and quote the
     * integration tests touch must belong to this one channel, or an
     * access token issued for one channel is invisible to a request resolved
     * onto another.
     */
    public static function storefrontSalesChannelId(ContainerInterface $container): string
    {
        $id = self::connection($container)->fetchOne(
            'SELECT LOWER(HEX(d.sales_channel_id)) FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE d.url LIKE "http%"'
            . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
            . ' ORDER BY d.url LIMIT 1',
        );

        if (!\is_string($id)) {
            throw new \RuntimeException('The shop has no active sales-channel domain with an absolute URL whose channel has an active customer.');
        }

        return $id;
    }

    /** Just the host part, which is what the SDK's RequestContext carries. */
    public static function storefrontHost(ContainerInterface $container): string
    {
        $host = parse_url(self::storefrontBaseUri($container), \PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            throw new \RuntimeException('The shop\'s sales-channel domain has no host.');
        }

        return $host;
    }

    /**
     * A quote some other customer owns, for the ownership boundary tests.
     */
    public static function anyQuoteIdNotOwnedBy(ContainerInterface $container, string $customerId): string
    {
        $id = self::connection($container)->fetchOne(
            'SELECT LOWER(HEX(id)) FROM quote WHERE customer_id <> :customerId LIMIT 1',
            ['customerId' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($customerId)],
        );

        if (!\is_string($id)) {
            throw new \RuntimeException('The shop has no quote owned by another customer.');
        }

        return $id;
    }

    /**
     * A customer whose `customer_specific_features` map enables
     * QUOTE_MANAGEMENT. The gate is a JSON map, not a list: an array is
     * silently ignored, which is exactly the trap the prerequisite-matrix
     * issue exists to automate away.
     */
    public static function anyQuoteCapableCustomerId(ContainerInterface $container): string
    {
        $id = self::connection($container)->fetchOne(
            'SELECT LOWER(HEX(id)) FROM customer'
            . ' WHERE active = 1'
            . ' AND sales_channel_id = UNHEX(:salesChannelId)'
            . ' AND JSON_EXTRACT(customer_specific_features, "$.QUOTE_MANAGEMENT") = TRUE'
            . ' LIMIT 1',
            ['salesChannelId' => self::storefrontSalesChannelId($container)],
        );

        if (!\is_string($id)) {
            throw new \RuntimeException(
                'No customer in this shop has QUOTE_MANAGEMENT enabled. Set it with a PATCH against the Admin API:'
                . ' {"customerSpecificFeatures": {"QUOTE_MANAGEMENT": true}}.',
            );
        }

        return $id;
    }

    public static function anyCustomerWithoutQuoteFeature(ContainerInterface $container): string
    {
        $id = self::connection($container)->fetchOne(
            'SELECT LOWER(HEX(id)) FROM customer'
            . ' WHERE active = 1'
            . ' AND sales_channel_id = UNHEX(:salesChannelId)'
            . ' AND (customer_specific_features IS NULL'
            . ' OR JSON_EXTRACT(customer_specific_features, "$.QUOTE_MANAGEMENT") IS NULL)'
            . ' LIMIT 1',
            ['salesChannelId' => self::storefrontSalesChannelId($container)],
        );

        if (!\is_string($id)) {
            throw new \RuntimeException('Every customer in this shop has QUOTE_MANAGEMENT enabled.');
        }

        return $id;
    }

    /** A product an agent may put on a quote: active, and in a live version. */
    public static function anyPurchasableProductId(ContainerInterface $container, Context $context): string
    {
        $id = self::connection($container)->fetchOne(
            'SELECT LOWER(HEX(id)) FROM product'
            . ' WHERE active = 1 AND version_id = :version AND parent_id IS NULL'
            . ' AND child_count = 0 LIMIT 1',
            ['version' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION)],
        );

        if (!\is_string($id)) {
            throw new \RuntimeException('The shop has no simple active product to quote.');
        }

        return $id;
    }

    private static function connection(ContainerInterface $container): \Doctrine\DBAL\Connection
    {
        $connection = $container->get(\Doctrine\DBAL\Connection::class);

        if (!$connection instanceof \Doctrine\DBAL\Connection) {
            throw new \RuntimeException('The container has no DBAL connection.');
        }

        return $connection;
    }
```

- [ ] **Step 4: Run the test to make sure it fails**

Run: `composer run test:integration -- --filter BuyerQuoteFlow`
Expected: FAIL — `BuyerQuoteGatewayInterface` not found.

- [ ] **Step 5: Write the port interface**

Create `src/Bridge/BuyerQuoteGatewayInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;

/**
 * Buyer-facing B2B quote operations, in an already-authenticated customer's
 * sales-channel context.
 *
 * The context is a parameter rather than a credential on purpose:
 * authentication belongs to the transport, and this port's business is
 * Shopware. That is what keeps Bridge free of any dependency on Identity.
 *
 * The counterpart to {@see QuoteGatewayInterface}, which is the merchant side
 * of the same backend: that one writes an existing quote in an admin context
 * for the servicing loop, this one acts as the customer.
 */
interface BuyerQuoteGatewayInterface
{
    /** Commercial backend installed and quote management licensed. */
    public function isAvailable(): bool;

    /**
     * Two steps in Shopware: the line items become a draft quote, which is
     * then sent, moving it to `open`.
     *
     * @param list<array{product_id?: string, product_number?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     * @throws ValidationException
     */
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot;

    /** @throws ResourceNotFoundException an unknown id and a foreign one are indistinguishable, by contract */
    public function getQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot;

    /** The customer's own quotes, newest first. */
    public function listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList;

    /**
     * Counter-offer: new per-unit asks and/or a comment. Valid from `replied`.
     *
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     * @throws ResourceNotFoundException|ValidationException
     */
    public function counterQuote(SalesChannelContext $context, string $quoteId, array $lineItems, ?string $comment): QuoteSnapshot;

    /**
     * Accepting is ordering in Shopware's model; the snapshot carries the
     * resulting order reference.
     *
     * @throws ResourceNotFoundException|ValidationException
     */
    public function acceptQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot;

    /** @throws ResourceNotFoundException|ValidationException */
    public function declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot;
}
```

- [ ] **Step 6: Port the gateway implementation**

```bash
git -C "$FORK" show feat/a2cn-act-carrier:src/Ucp/Gateway/ShopwareQuoteGateway.php \
  > src/Bridge/SwagCommercialBuyerQuoteGateway.php
```

Apply exactly these edits, in order:

1. `namespace Swag\AgenticCommerce\Ucp\Gateway;` → `namespace MerchantQuoteAgentPlugin\Bridge;`
2. `final class ShopwareQuoteGateway implements QuoteGatewayInterface` → `final class SwagCommercialBuyerQuoteGateway implements BuyerQuoteGatewayInterface`
3. Imports: drop `Swag\AgenticCommerce\Ucp\Identity\AgentCustomerAuthenticator`, `...\AgentCustomerCredential`, `...\ShopwareIdentityLinkingAdapter`, `...\Quote\A2cnActField`, `...\Quote\QuoteBackendFeature`, `...\Quote\QuoteGatewayInterface`, `Ucp\Sdk\Model\RequestContext`. Add `MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability`, `MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList`, `MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot`, `Override`.
4. Constructor: drop the `AgentCustomerAuthenticator $authenticator` parameter. Keep every `?object` route parameter, `CartService`, `LineItemFactoryRegistry`, `$customerSpecificFeatureService` and `$quoteRepository` exactly as they are — they are the soft-dependency seam.
5. Delete the private `customerContext()` helper (lines ~251-274 in the source): the context now arrives as a parameter.
6. In all six public operations: replace the signature per `BuyerQuoteGatewayInterface`, delete the `?array $a2cnAct = null` parameter, and replace the opening `$context = $this->customerContext($credential, $requestContext);` with nothing — the parameter is already named `$context`.
7. Delete `appendA2cnAct()` and every call to it, and delete the `$a2cnActs` argument at every `new QuoteSnapshot(...)` call site.
8. Replace `QuoteBackendFeature::isAvailableByClass()` with `CommercialAvailability::isAvailableByClass()` and, in `isAvailable()`, the licence check with `CommercialAvailability::isLicensed()`.
9. Add `#[Override]` to each of the seven interface methods.
10. In `hasCustomerQuoteFeature()`, when the feature is absent throw
    `new ValidationException('Quote management is not enabled for this customer.', ['customer_specific_features must contain {"QUOTE_MANAGEMENT": true}'])`
    instead of returning false to a caller that turns it into a bare 403.

- [ ] **Step 7: Register the gateway behind the availability gate**

In `src/Resources/config/services.php`, next to the existing `QuoteGatewayFactory` wiring, add:

```php
    // Buyer-side counterpart of the merchant gateway. Every commercial route is
    // an ignore-on-invalid reference, so the container compiles on a shop
    // without SwagCommercial and the capability reports itself unsupported.
    if (CommercialAvailability::isAvailableByClass()) {
        $services->set(SwagCommercialBuyerQuoteGateway::class)
            ->arg('$quoteRequestRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\CartToQuote\\QuoteRequestRoute')->nullOnInvalid())
            ->arg('$quoteSendRequestRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteSendRequestRoute')->nullOnInvalid())
            ->arg('$quoteLineItemRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\LineItem\\QuoteLineItemRoute')->nullOnInvalid())
            ->arg('$quoteLoadRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteAccounting\\QuoteLoadRoute')->nullOnInvalid())
            ->arg('$quoteListingRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteAccounting\\QuoteListingRoute')->nullOnInvalid())
            ->arg('$quoteRequestChangeRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteRequestChangeRoute')->nullOnInvalid())
            ->arg('$quoteDeclineRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteDeclineRoute')->nullOnInvalid())
            ->arg('$quoteOrderRoute', service('Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteToOrder\\QuoteOrderRoute')->nullOnInvalid())
            ->arg('$customerSpecificFeatureService', service('Shopware\\Commercial\\B2B\\CustomerSpecificFeatures\\Domain\\CustomerSpecificFeature\\CustomerSpecificFeatureService')->nullOnInvalid())
            ->arg('$quoteRepository', service('quote.repository')->nullOnInvalid());

        $services->alias(BuyerQuoteGatewayInterface::class, SwagCommercialBuyerQuoteGateway::class);
    }
```

These nine ids are copied from the fork's own `ShopwareQuoteGateway` block, so they are the ones that worked. Confirm two of them against the live shop anyway — a SwagCommercial rename is exactly the failure this seam exists to localise:

```bash
docker exec -u www-data -w /var/www/html merchant-quote-shop \
  php bin/console debug:container --parameter-bag 2>/dev/null | head -1
docker exec -u www-data -w /var/www/html merchant-quote-shop \
  php bin/console debug:container Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\CartToQuote\\QuoteRequestRoute
```

- [ ] **Step 8: Run the integration test to make sure it passes**

Run: `composer run test:integration -- --filter BuyerQuoteFlow`
Expected: PASS (4 tests). A skip means the shop's licence toggle is off — fix the shop, do not weaken the test.

- [ ] **Step 9: Commit**

```bash
git add src/Bridge/BuyerQuoteGatewayInterface.php src/Bridge/SwagCommercialBuyerQuoteGateway.php \
        src/Ucp/Quote/QuoteSnapshot.php src/Ucp/Quote/QuoteList.php \
        src/Resources/config/services.php tests/Integration
git commit -m "feat: buyer-side quote gateway over the commercial Store API routes

Issue #9. Ported from the fork with one signature change: the context arrives
authenticated instead of a credential, so Bridge stays free of Identity. A2CN
act carrying is dropped with the rest of A2CN. A customer missing the
QUOTE_MANAGEMENT map entry now gets an error that names the flag."
```

---

### Task 5: The capability's operations and request validation

**Files:**
- Create: `src/Ucp/Quote/QuoteRequestValidator.php`
- Modify: `src/Ucp/Quote/QuoteCapability.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Ucp/Quote/QuoteCapabilityTest.php`
- Test: `tests/Unit/Ucp/Quote/QuoteRequestValidatorTest.php`

**Interfaces:**
- Consumes: `BuyerQuoteGatewayInterface` (all six operations), `QuoteSnapshot`, `QuoteList`.
- Produces: `QuoteCapability::{requestQuote,getQuote,listQuotes,counterQuote,acceptQuote,declineQuote}` each taking `(SalesChannelContext $context, …)` and throwing `UnsupportedCapabilityException` when no gateway is wired; `QuoteRequestValidator::lineItems(array $payload, bool $required): array` and `::comment(array $payload): ?string`.

- [ ] **Step 1: Port the validator and its test**

```bash
git -C "$FORK" show feat/a2cn-act-carrier:src/Ucp/Quote/QuoteRequestValidator.php \
  > src/Ucp/Quote/QuoteRequestValidator.php
git -C "$FORK" show feat/a2cn-act-carrier:tests/Unit/Ucp/Quote/QuoteRequestValidatorTest.php \
  > tests/Unit/Ucp/Quote/QuoteRequestValidatorTest.php
```

Edits to both: namespace `Swag\AgenticCommerce\...` → `MerchantQuoteAgentPlugin\...` (source: `MerchantQuoteAgentPlugin\Ucp\Quote`; test: `MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote`), drop `@internal`, and drop any A2CN assertions or `a2cn_act` payload keys.

- [ ] **Step 2: Run the ported test to make sure it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter QuoteRequestValidator`
Expected: PASS. If it fails on a missing class, an A2CN reference survived step 1 — remove it.

- [ ] **Step 3: Write the failing test for the capability's operations**

Port the fork's coverage and adapt it to the new signature:

```bash
git -C "$FORK" show quote-management:tests/Unit/Ucp/Quote/QuoteCapabilityTest.php \
  > tests/Unit/Ucp/Quote/QuoteCapabilityTest.php
```

Edits: namespace to `MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote`; imports to this plugin's `QuoteCapability`, `BuyerQuoteGatewayInterface`, `QuoteSnapshot`, `QuoteList`; every `AgentCustomerCredential` argument in a gateway expectation becomes a `SalesChannelContext` mock; delete tests that assert on `UcpCapabilityCatalog`, `UcpExtensionAvailability`, A2CN or the context-token path. Then add this test, which is new — it pins that the descriptor keeps coming from our own class:

```php
    public function testItStillDescribesItselfThroughTheSharedDescriptor(): void
    {
        $descriptor = (new QuoteCapability())->describe();

        self::assertSame(QuoteCapabilityDescriptor::NAME, $descriptor->name);
        self::assertSame(QuoteCapabilityDescriptor::SCHEMA_PATH, $descriptor->schema);
    }

    public function testAnUnwiredGatewayMakesEveryOperationUnsupported(): void
    {
        $capability = new QuoteCapability();

        $this->expectException(UnsupportedCapabilityException::class);

        $capability->getQuote($this->createMock(SalesChannelContext::class), 'any-id');
    }
```

- [ ] **Step 4: Run it to make sure it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter QuoteCapability`
Expected: FAIL — `QuoteCapability::getQuote()` does not exist.

- [ ] **Step 5: Grow the existing capability class**

`src/Ucp/Quote/QuoteCapability.php` today only implements `describe()`. Keep that method untouched and add the constructor plus the six operations:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use Override;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/**
 * Registers `com.shopware.quote` with the UCP SDK and carries its six
 * buyer-facing operations.
 *
 * Implementing CapabilityInterface is all the registration takes: the SDK
 * autoconfigures the `ucp_sdk.capability` tag onto every implementation and
 * collects them into its CapabilityRegistry. No decoration of the Agentic
 * Commerce plugin, and no changes to it.
 *
 * Guard-then-delegate: the backend is optional, so without SwagCommercial the
 * gateway is absent and every operation fails as unsupported (501) rather than
 * as a container error. The descriptor is still published — the profile
 * contributor does that unconditionally — because the contract documents are
 * served either way.
 */
final class QuoteCapability implements CapabilityInterface
{
    public function __construct(
        private readonly ?BuyerQuoteGatewayInterface $gateway = null,
    ) {
    }

    #[Override]
    public function describe(): CapabilityDescriptor
    {
        return QuoteCapabilityDescriptor::paths();
    }

    /**
     * @param list<array{product_id?: string, product_number?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     */
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot
    {
        return $this->gateway()->requestQuote($context, $lineItems, $comment);
    }

    public function getQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot
    {
        return $this->gateway()->getQuote($context, $quoteId);
    }

    public function listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList
    {
        return $this->gateway()->listQuotes($context, $limit, $page);
    }

    /**
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     */
    public function counterQuote(SalesChannelContext $context, string $quoteId, array $lineItems, ?string $comment): QuoteSnapshot
    {
        return $this->gateway()->counterQuote($context, $quoteId, $lineItems, $comment);
    }

    public function acceptQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot
    {
        return $this->gateway()->acceptQuote($context, $quoteId);
    }

    public function declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot
    {
        return $this->gateway()->declineQuote($context, $quoteId, $comment);
    }

    private function gateway(): BuyerQuoteGatewayInterface
    {
        if ($this->gateway === null || !$this->gateway->isAvailable()) {
            throw new UnsupportedCapabilityException('The quote capability requires a licensed commercial quote backend.');
        }

        return $this->gateway;
    }
}
```

- [ ] **Step 6: Wire the optional gateway into the capability**

In `src/Resources/config/services.php`, replace the bare `$services->set(QuoteCapability::class);` with:

```php
    $services->set(QuoteCapability::class)
        ->arg('$gateway', service(BuyerQuoteGatewayInterface::class)->ignoreOnInvalid());
```

and add `$services->set(QuoteRequestValidator::class);` beside it.

- [ ] **Step 7: Run the unit suite to make sure it passes**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: PASS, with the ported capability and validator tests included and no regressions in the existing 291.

- [ ] **Step 8: Commit**

```bash
git add src/Ucp/Quote/QuoteCapability.php src/Ucp/Quote/QuoteRequestValidator.php \
        src/Resources/config/services.php tests/Unit/Ucp/Quote
git commit -m "feat: the quote capability's six buyer operations

Issue #9. Guard-then-delegate over the buyer gateway: no gateway, or an
unlicensed one, and every operation is 501 rather than a container error. The
descriptor keeps coming from QuoteCapabilityDescriptor."
```

---

### Task 6: The transport

**Files:**
- Create: `src/Ucp/Quote/Controller/UcpQuoteController.php`
- Modify: `composer.json`, `composer.lock`
- Modify: `src/Resources/config/routes.php` (the import, and the comment Task 6 makes false)
- Modify: `src/Resources/config/services.php`
- Test: `tests/Integration/UcpQuoteEndpointTest.php`

**Interfaces:**
- Consumes: `QuoteCapability` (six operations), `QuoteRequestValidator::{lineItems,comment}`, `AgentCustomerAuthenticator::authenticate()`, `AgentCustomerCredential::fromAuthorizationHeader()`, `UcpResponseFactory::success()`.

There is no controller unit test: `UcpResponseFactory` and `QuoteCapability` are both `final`, so building the graph to reach the controller's own two decisions costs more than it proves. Those two decisions are covered where they have no dependencies — header parsing by `AgentCustomerCredentialTest` (Task 1), everything else by this task's kernel-level integration tests, which exercise the real listener chain.
- Produces: six routes named `frontend.merchant_quote_agent.quote.{request,list,get,counter,accept,decline}` on the paths in the published OpenAPI document.

- [ ] **Step 1: Add the SDK bundle dependency, installed this time**

```bash
composer require --no-interaction "ucp-php-sdk/symfony-bundle:>=0.0.5 <0.1.0"
```

Note the missing `--no-update`: `UcpResponseFactory` lives in this package, and Mago's analyzer needs it present in `vendor/` or it reports the class as unknown. The constraint matches the style of the existing `ucp-php-sdk/core` line. The shop already has this package through Agentic Commerce, so nothing changes at runtime. If the resolver wants to move unrelated packages, stop and report.

- [ ] **Step 2: Port the controller**

```bash
mkdir -p src/Ucp/Quote/Controller
git -C "$FORK" show test/mandate-on-sdk-compat:src/Ucp/Quote/Controller/UcpQuoteController.php \
  > src/Ucp/Quote/Controller/UcpQuoteController.php
```

Apply exactly these edits:

1. `namespace Swag\AgenticCommerce\Ucp\Quote\Controller;` → `namespace MerchantQuoteAgentPlugin\Ucp\Quote\Controller;`
2. Imports: drop `Shopware\Core\Framework\Log\Package`, `Shopware\Storefront\Framework\Routing\StorefrontRouteScope`, `Swag\AgenticCommerce\Ucp\Capability\QuoteCapability`, `...\Capability\UcpCapabilityCatalog`, `...\Http\SymfonyRequestContextFactory`, `...\Identity\AgentCustomerCredential`, `...\Quote\QuoteRequestValidator`. Add `MerchantQuoteAgentPlugin\Identity\{AgentCustomerAuthenticator, AgentCustomerCredential}`, `MerchantQuoteAgentPlugin\Ucp\Quote\{QuoteCapability, QuoteRequestValidator}`, `Shopware\Core\System\SalesChannel\SalesChannelContext`, `Ucp\Sdk\Exception\ConfigurationException`.
3. Delete the `#[Package('framework')]` attribute line.
4. Class-level route attribute becomes `#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]`.
5. Delete the two contract-document routes (`schema()`, `spec()`) and the `serveFile()` helper — `QuoteContractController` already owns them.
6. Constructor: replace `SymfonyRequestContextFactory $requestContextFactory` with `AgentCustomerAuthenticator $authenticator`; keep `QuoteCapability`, `UcpResponseFactory`, `QuoteRequestValidator`; drop `$quoteSchemaPath` and `$quoteSpecPath`.
7. Rename every route: `frontend.ucp.quote.X` → `frontend.merchant_quote_agent.quote.X`.
8. Replace the private `requestContext()` and `credential()` helpers, and add the authentication step, so each operation reads its context from the request attribute and its customer context from the authenticator. Header parsing itself belongs to the credential (Task 1), so this is only the plumbing:

```php
    private function requestContext(Request $request): RequestContext
    {
        $context = $request->attributes->get('ucp_request_context');

        if (!$context instanceof RequestContext) {
            throw new ConfigurationException(
                'No UCP request context on the request. The SDK listener only builds one below /ucp/, '
                . 'so this route is registered outside the prefix it depends on.',
            );
        }

        return $context;
    }

    private function customerContext(Request $request, RequestContext $context): SalesChannelContext
    {
        $credential = AgentCustomerCredential::fromAuthorizationHeader(
            (string) $request->headers->get('Authorization', ''),
        );

        return $this->authenticator->authenticate($credential, $context);
    }
```

   Each operation then reads, for example:

```php
    #[Route(path: '/ucp/quotes/{id}', name: 'frontend.merchant_quote_agent.quote.get', methods: ['GET'])]
    public function getQuote(string $id, Request $request): JsonResponse
    {
        $context = $this->requestContext($request);
        $snapshot = $this->quoteCapability->getQuote($this->customerContext($request, $context), $id);

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.get');
    }
```

   Note the order: `requestContext()` runs before `credential()`, so a request the SDK listener never saw is a 500 configuration error rather than a 401 — Step 1's second test pins exactly that.

9. Keep `DEFAULT_LIST_LIMIT = 25` and the `$request->query->getInt()` reads as they are.

- [ ] **Step 3: Register the controller and its routes**

In `src/Resources/config/services.php`, beside the contract controller:

```php
    if (CommercialAvailability::isAvailableByClass()) {
        $services->set(UcpQuoteController::class)->tag('controller.service_arguments');
    }
```

In `src/Resources/config/routes.php`, first correct the file's own comment. It currently reads "The only routes this plugin owns are the two capability documents; the quote transport itself belongs to the Agentic Commerce plugin (issue #9)" — this task is issue #9, so that is now false. Replace it with:

```php
// Imported by Bundle::configureRoutes(). Two kinds of route live here: the
// capability's contract documents, served unconditionally, and its runtime
// endpoints, which exist only where the commercial quote backend does.
```

Then add below the existing contract-controller import:

```php
    // The runtime endpoints only exist where the commercial backend does,
    // matching the service-graph gate in services.php — otherwise the routes
    // would resolve to a service the container never built.
    if (CommercialAvailability::isAvailableByClass()) {
        $routes->import(__DIR__ . '/../../Ucp/Quote/Controller/UcpQuoteController.php', 'attribute');
    }
```

with `use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;` at the top of that file.

- [ ] **Step 4: Write the failing integration test over the real kernel**

This is the test that proves what issue #9 inherited from #1: the SDK, not our code, rejects a request without `UCP-Agent`. Create `tests/Integration/UcpQuoteEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Identity\AccessTokenSubjectReaderInterface;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * The endpoints over the real kernel, so the SDK's listeners run: that is the
 * only way to prove the UCP-Agent requirement, the error envelopes and the
 * request context are inherited rather than re-implemented.
 *
 * Tokens are issued through Agentic Commerce's own OAuth store service, which
 * makes these tests the canary for the one schema coupling this plugin has: if
 * that plugin renames its table, its columns or its hash, the reader stops
 * finding tokens its writer just wrote.
 */
final class UcpQuoteEndpointTest extends IntegrationTestCase
{
    private const AC_OAUTH_STORE = 'Swag\\AgenticCommerce\\Ucp\\Identity\\DoctrineDbalUcpOAuthStore';

    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testTheSdkRejectsARequestWithoutAUcpAgentHeader(): void
    {
        $response = $this->send('GET', '/ucp/quotes', headers: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->issueToken()]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('UCP-Agent', (string) $response->getContent());
    }

    public function testAListRequestWithAValidTokenReturnsTheCustomersQuotes(): void
    {
        $response = $this->send('GET', '/ucp/quotes', headers: [
            'HTTP_UCP_AGENT' => 'test-agent/1.0',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->issueToken(),
        ]);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('quotes', $payload['data'] ?? $payload);
    }

    public function testAnUnknownTokenIsRejectedWithA401(): void
    {
        $response = $this->send('GET', '/ucp/quotes', headers: [
            'HTTP_UCP_AGENT' => 'test-agent/1.0',
            'HTTP_AUTHORIZATION' => 'Bearer ucp_at_not_a_real_token',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testOurReaderFindsATokenAgenticCommerceItselfIssued(): void
    {
        $customerId = QuoteFixture::anyQuoteCapableCustomerId(static::getContainer());
        $token = $this->issueToken($customerId);

        $reader = static::getContainer()->get(AccessTokenSubjectReaderInterface::class);
        self::assertInstanceOf(AccessTokenSubjectReaderInterface::class, $reader);

        $info = $reader->find($token, $this->salesChannelId());

        self::assertNotNull($info, 'the access-token schema or hash changed in Agentic Commerce');
        self::assertSame($customerId, $info->subject);
    }

    private function issueToken(?string $customerId = null): string
    {
        $store = static::getContainer()->get(self::AC_OAUTH_STORE);
        self::assertIsObject($store);
        self::assertTrue(method_exists($store, 'issueTokenSet'), 'Agentic Commerce OAuth store lost issueTokenSet()');

        $set = $store->issueTokenSet(
            $this->salesChannelId(),
            'integration-test-client',
            $customerId ?? QuoteFixture::anyQuoteCapableCustomerId(static::getContainer()),
            'dev.ucp.shopping.cart:manage',
        );

        self::assertIsObject($set);
        self::assertIsString($set->accessToken);

        return $set->accessToken;
    }

    private function salesChannelId(): string
    {
        return QuoteFixture::storefrontSalesChannelId(static::getContainer());
    }

    /**
     * @param array<string, string> $headers server-style header names (HTTP_*)
     */
    private function send(string $method, string $path, array $headers = []): \Symfony\Component\HttpFoundation\Response
    {
        $baseUri = QuoteFixture::storefrontBaseUri(static::getContainer());
        $request = Request::create($baseUri . $path, $method, server: $headers);

        return KernelLifecycleManager::getKernel()->handle($request);
    }
}
```

Task 4 already added `storefrontSalesChannelId()`; if it is somehow absent, add it now:

```php
    public static function storefrontSalesChannelId(ContainerInterface $container): string
    {
        $id = self::connection($container)->fetchOne(
            'SELECT LOWER(HEX(d.sales_channel_id)) FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE d.url LIKE "http%"'
            . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
            . ' ORDER BY d.url LIMIT 1',
        );

        if (!\is_string($id)) {
            throw new \RuntimeException('The shop has no active sales-channel domain with an absolute URL whose channel has an active customer.');
        }

        return $id;
    }
```

- [ ] **Step 5: Run it and fix what it finds**

Run: `composer run test:integration -- --filter UcpQuoteEndpoint`
Expected: PASS (4 tests). Two failures are worth expecting and diagnosing rather than working around:
- A 404 instead of 400/401 means the routes were not imported — check the `CommercialAvailability` gate in `routes.php` and run `debug:router | grep merchant_quote_agent`.
- A 500 mentioning "No UCP request context" means the route sits outside the `/ucp/` prefix the SDK listener matches.

- [ ] **Step 6: Verify the routes in the shop**

```bash
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/console cache:clear -n
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/console debug:router \
  | grep merchant_quote_agent
```

Expected: eight rows — the six new endpoints plus the two contract documents.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock src/Ucp/Quote/Controller/UcpQuoteController.php \
        src/Resources/config/routes.php src/Resources/config/services.php \
        tests/Integration/UcpQuoteEndpointTest.php tests/Integration/QuoteFixture.php
git commit -m "feat: serve the six buyer-facing quote endpoints

Issue #9, closing the acceptance criterion it inherited from #1: the routes sit
under /ucp/, so the SDK's listeners supply the UCP-Agent requirement, the
request context and the error envelopes, and the integration suite asserts the
rejection comes from the SDK rather than from us."
```

---

### Task 7: Close-out — documentation, gates, and the upstream asks

**Files:**
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-08-31-buyer-quote-transport-design.md`

- [ ] **Step 1: Check the two comments this work made stale**

`src/Ucp/Quote/QuoteCapability.php` used to say "The buyer-facing operations (request, get, list, counter, accept, decline) arrive with the gateway in issue #9. Until then this only declares that the capability exists." Task 5 replaces the whole class comment, so confirm that sentence is gone:

```bash
grep -rn "issue #9" src/ && echo "^ nothing above should promise future work this plan delivered"
```

`QuoteCapabilityDescriptor`'s comment ("The spec and schema documents are served by this plugin", and the two forms of the descriptor) stays true — leave it alone.

- [ ] **Step 2: Document the buyer path in the README**

Add a section after "Operating the servicing loop":

```markdown
## Buyer-facing quote endpoints

Buyer agents reach the quote capability at `/ucp/quotes` (see
`/.well-known/ucp/schemas/quote.openapi.json` for the contract). Every request
needs two headers: `UCP-Agent`, which the UCP SDK enforces for everything under
`/ucp/`, and `Authorization: Bearer <token>`, an identity-linking access token
issued by the Agentic Commerce plugin.

The token's subject is the trust boundary — nothing in a request body can
select a customer. The customer must additionally have quote management
enabled, as a **map**: `{"QUOTE_MANAGEMENT": true}` in
`customer_specific_features`. An array there is silently ignored, and a
customer without it gets a 422 naming the flag.

Scope is read but not enforced: Agentic Commerce cannot yet issue
`com.shopware.quote:manage` (its supported-scope list is a private constant),
so any valid token for the customer is accepted and authorization is by quote
ownership. Enforcement lands with the upstream scope change.
```

- [ ] **Step 3: Open the upstream ask the spec commits to**

```bash
gh issue create --title "5.3 Upstream PR: make Agentic Commerce's supported OAuth scope list extensible" \
  --body "$(cat <<'BODY'
`ShopwareIdentityLinkingAdapter::SUPPORTED_SCOPES` is a private constant
(`cart:manage`, `order:read`, `order:manage`) and requested scopes are
intersected against it, so no token can carry a vendor capability's scope —
`com.shopware.quote:manage` included. The fork widened the constant; a shop
plugin cannot.

Ask: let plugins contribute scopes (a tagged provider, or a container
parameter). Until then #9's transport accepts any valid customer token and
authorizes by quote ownership; see
`docs/superpowers/specs/2026-08-31-buyer-quote-transport-design.md`.

Sibling of #12 and #13.
BODY
)"
```

Then add the new issue number beside the "a new upstream ask" row in the spec's debt table.

- [ ] **Step 4: Run the whole gate**

```bash
composer run quality
vendor/bin/phpunit --testsuite unit
composer run test:integration
```

Expected: `quality` clean (`format:check`, `lint`, `typecheck`, `quality:filesize`, `quality:dupes`, `quality:depcheck`, `quality:security`); unit suite green; integration suite green. `quality:depcheck` is the one most likely to complain — it will name any class used from a package `composer.json` does not declare.

- [ ] **Step 5: Verify the whole path once by hand, against the shop**

```bash
scripts/sync-to-shop.sh
docker exec -u www-data -w /var/www/html merchant-quote-shop php bin/console cache:clear -n
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8095/ucp/quotes           # 400: UCP-Agent missing
curl -s -o /dev/null -w '%{http_code}\n' -H 'UCP-Agent: manual/1.0' \
  http://localhost:8095/ucp/quotes                                                  # 401: no bearer token
curl -s http://localhost:8095/.well-known/ucp | python3 -m json.tool | grep -A3 shopware.quote
```

Expected: 400, then 401, and the discovery document still advertising the capability whose endpoints now answer.

- [ ] **Step 6: Commit**

```bash
git add README.md docs/superpowers/specs/2026-08-31-buyer-quote-transport-design.md \
        src/Ucp/Quote/QuoteCapability.php src/Ucp/Quote/QuoteCapabilityDescriptor.php
git commit -m "docs: the buyer-facing quote path and the scope debt behind it

Issue #9. Records the two headers a buyer agent needs, the QUOTE_MANAGEMENT map
trap, and why scope is read but not enforced until the upstream scope list can
be extended."
```

---

## After the plan

The fork's `agentic-commerce-rfq-addition` branches are now the source of nothing this plugin needs, except the deferred pieces: `A2cnActField`, `A2cnMandateProfileContributor` and the browser consent page. Do not delete the fork until those have their own issues — issue #9's own text says removing the fork also resolves Linear ACL-175 and ACL-176 by removing their subject, and that only holds once nothing else is waiting there.

Follow-ups this plan deliberately leaves open, each already argued in the spec: the prerequisite matrix and `QUOTE_MANAGEMENT` automation (its own issue, blocking #21), upstream #12 and #13, the new scope-list ask from Task 7, and folding these REST routes into the SDK's operation dispatch once it supports vendor operations.
