# Browser Identity Linking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a human a page on the shop where they sign in and grant an agent an OAuth token, so the agent can act for them over UCP.

**Architecture:** Split the OAuth authorization request in two. A signed machine POST under `/ucp/` registers the intent — the SDK has already fetched the agent's profile and verified the signature there — and persists it under an opaque single-use handle. An unsigned storefront visit then authenticates the customer through Shopware's own login, asks for consent, and replays the stored verified facts into a constructed `RequestContext` so Agentic Commerce's own `authorize()` mints a real authorization code. AC's token endpoint is untouched.

**Tech Stack:** PHP 8.3, Shopware 6.7, Doctrine DBAL, Symfony DI/Routing, Twig (storefront), PHPUnit 11, `ucp-php-sdk/core`.

**Spec:** `docs/superpowers/specs/2026-09-01-browser-identity-linking-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file. Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- Thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines/file.
- Target PHP 8.3 (`mago.toml` `php-version`, `composer.json` `config.platform.php`).
- Never `echo`/`var_dump`/`print_r`/`dd` in application code — Mago `no-debug-symbols` blocks them.
- Throw `Throwable` subclasses only; preserve the original via `$previous` when wrapping.
- Validate boundary data at runtime; define DTOs once and reuse them.
- Reuse the existing constant or typed config before adding a repeated literal.
- Checks: `composer run format:check && composer run lint`, `composer run typecheck`, `composer run quality:depcheck`. CI is the authority.
- **Agentic Commerce is never modified.** Every interaction is through its aliased interfaces or its documented admin API.
- **The boundary rule** (spec, "The security boundary"): a pending record is written only where `$context->signatureVerified === true`, `$context->platformProfileUri !== null`, and `platformProfileUri === client_id`. Enforced in exactly one class. Never inferred from the `/ucp/` prefix.

---

### Task 0: Prepare the worktree

**Files:**
- Modify: none

- [ ] **Step 1: Install dependencies**

The worktree was created from `origin/main` with no `vendor/`, so hooks and tests cannot run.

```bash
cd ~/.config/superpowers/worktrees/merchant-quote-agent-plugin/feat-browser-identity-linking
composer install
```

- [ ] **Step 2: Confirm the baseline is green**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: PASS. If it fails, stop — the branch is not a clean base.

---

### Task 1: Pending authorization storage

**Files:**
- Create: `src/Migration/Migration1788300000CreatePendingAuthorization.php`
- Create: `src/Identity/Authorization/PendingAuthorization.php`
- Create: `src/Identity/Authorization/PendingAuthorizationStoreInterface.php`
- Create: `src/Identity/Authorization/DbalPendingAuthorizationStore.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Identity/Authorization/DbalPendingAuthorizationStoreTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `PendingAuthorization` readonly DTO with public: `string $salesChannelId`, `string $clientId`, `array $agentProfile`, `string $redirectUri`, `string $scope`, `string $state`, `string $codeChallenge`, `string $codeChallengeMethod`.
  - `PendingAuthorizationStoreInterface::store(PendingAuthorization $pending, int $ttlSeconds): string` returning the plaintext handle.
  - `PendingAuthorizationStoreInterface::find(string $handle): ?PendingAuthorization`
  - `PendingAuthorizationStoreInterface::consume(string $handle): ?PendingAuthorization` — atomic; returns null if unknown, expired or already consumed.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Identity/Authorization/DbalPendingAuthorizationStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\Authorization\DbalPendingAuthorizationStore;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbalPendingAuthorizationStore::class)]
#[CoversClass(PendingAuthorization::class)]
final class DbalPendingAuthorizationStoreTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private function pending(): PendingAuthorization
    {
        return new PendingAuthorization(
            self::SALES_CHANNEL_ID,
            'https://agent.example/.well-known/ucp',
            ['ucp' => ['version' => '2026-04-08']],
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }

    public function testItStoresOnlyTheHandleHashAndReturnsThePlaintextHandle(): void
    {
        $captured = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        $store = new DbalPendingAuthorizationStore($connection);
        $handle = $store->store($this->pending(), 600);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $handle);

        $insert = array_values(array_filter(
            $captured,
            static fn(array $call): bool => str_contains($call['sql'], 'INSERT INTO'),
        ));
        self::assertCount(1, $insert);
        self::assertSame(hash('sha256', $handle, true), $insert[0]['params']['handleHash']);
        self::assertStringNotContainsString($handle, $insert[0]['sql']);
        foreach ($insert[0]['params'] as $value) {
            self::assertNotSame($handle, $value, 'the plaintext handle must never be persisted');
        }
    }

    public function testItFindsByHandleHashAndRehydratesTheProfile(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(
                    static fn(string $sql): bool => str_contains($sql, 'merchant_quote_agent_pending_authorization')
                        && str_contains($sql, 'consumed_at IS NULL')
                        && str_contains($sql, 'expires_at'),
                ),
                self::callback(
                    static fn(array $params): bool => $params['handleHash'] === hash('sha256', 'plain-handle', true),
                ),
            )
            ->willReturn([
                'sales_channel_id' => self::SALES_CHANNEL_ID,
                'client_id' => 'https://agent.example/.well-known/ucp',
                'agent_profile' => '{"ucp":{"version":"2026-04-08"}}',
                'redirect_uri' => 'https://agent.example/callback',
                'scope' => 'dev.ucp.shopping.order:read',
                'state' => 'state-value',
                'code_challenge' => 'challenge-value',
                'code_challenge_method' => 'S256',
            ]);

        $found = (new DbalPendingAuthorizationStore($connection))->find('plain-handle');

        self::assertNotNull($found);
        self::assertSame(self::SALES_CHANNEL_ID, $found->salesChannelId);
        self::assertSame(['ucp' => ['version' => '2026-04-08']], $found->agentProfile);
        self::assertSame('S256', $found->codeChallengeMethod);
    }

    public function testFindReturnsNullWhenNoRowMatches(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new DbalPendingAuthorizationStore($connection))->find('missing'));
    }

    public function testConsumeReturnsNullWhenTheConditionalUpdateAffectsNoRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'sales_channel_id' => self::SALES_CHANNEL_ID,
            'client_id' => 'https://agent.example/.well-known/ucp',
            'agent_profile' => '{}',
            'redirect_uri' => 'https://agent.example/callback',
            'scope' => '',
            'state' => 'state-value',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
        ]);
        // The conditional UPDATE is what makes consumption single-use: a second
        // caller updates zero rows and must get nothing back.
        $connection->method('executeStatement')->willReturn(0);

        self::assertNull((new DbalPendingAuthorizationStore($connection))->consume('plain-handle'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/DbalPendingAuthorizationStoreTest.php`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Identity\Authorization\DbalPendingAuthorizationStore" not found`.

- [ ] **Step 3: Write the DTO**

`src/Identity/Authorization/PendingAuthorization.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

/**
 * An authorization request an agent registered and a human has not yet answered.
 *
 * `agentProfile` is the profile the SDK fetched and verified at registration,
 * kept as its array form so consent can replay it into a RequestContext without
 * a second outbound fetch. Nothing here is quote-specific: this type moves to
 * Agentic Commerce unchanged when the flow goes upstream.
 */
final readonly class PendingAuthorization
{
    /** @param array<string, mixed> $agentProfile */
    public function __construct(
        public string $salesChannelId,
        public string $clientId,
        public array $agentProfile,
        public string $redirectUri,
        public string $scope,
        public string $state,
        public string $codeChallenge,
        public string $codeChallengeMethod,
    ) {}
}
```

- [ ] **Step 4: Write the interface**

`src/Identity/Authorization/PendingAuthorizationStoreInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

/**
 * Short-lived storage for authorization requests awaiting a human answer.
 *
 * The handle the caller receives is a bearer secret: only its hash is stored,
 * and `consume()` is single-use so one registration can mint at most one code.
 */
interface PendingAuthorizationStoreInterface
{
    /** @return string the plaintext handle; only its hash is persisted */
    public function store(PendingAuthorization $pending, int $ttlSeconds): string;

    public function find(string $handle): ?PendingAuthorization;

    /** Atomically marks the record used. Null when unknown, expired or already consumed. */
    public function consume(string $handle): ?PendingAuthorization;
}
```

- [ ] **Step 5: Write the DBAL store**

`src/Identity/Authorization/DbalPendingAuthorizationStore.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Handles are hashed with raw sha256 before they touch the database, matching
 * how Agentic Commerce stores its own OAuth tokens, so a database read never
 * yields a usable handle.
 *
 * `consume()` is a conditional UPDATE rather than a read-then-write: two
 * concurrent consent submissions must not both mint an authorization code, and
 * the affected-row count is the only thing that settles that without a lock.
 */
final readonly class DbalPendingAuthorizationStore implements PendingAuthorizationStoreInterface
{
    private const TABLE = 'merchant_quote_agent_pending_authorization';

    private const HANDLE_BYTES = 32;

    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function store(PendingAuthorization $pending, int $ttlSeconds): string
    {
        $this->deleteSpent();

        $handle = self::handle();

        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO `%s` (`handle_hash`, `sales_channel_id`, `client_id`, `agent_profile`,'
                . ' `redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method`,'
                . ' `created_at`, `expires_at`)'
                . ' VALUES (:handleHash, :salesChannelId, :clientId, :agentProfile, :redirectUri,'
                . ' :scope, :state, :codeChallenge, :codeChallengeMethod, NOW(3), :expiresAt)',
                self::TABLE,
            ),
            [
                'handleHash' => hash('sha256', $handle, true),
                'salesChannelId' => Uuid::fromHexToBytes($pending->salesChannelId),
                'clientId' => $pending->clientId,
                'agentProfile' => json_encode($pending->agentProfile, \JSON_THROW_ON_ERROR),
                'redirectUri' => $pending->redirectUri,
                'scope' => $pending->scope,
                'state' => $pending->state,
                'codeChallenge' => $pending->codeChallenge,
                'codeChallengeMethod' => $pending->codeChallengeMethod,
                'expiresAt' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            ],
        );

        return $handle;
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function find(string $handle): ?PendingAuthorization
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT LOWER(HEX(`sales_channel_id`)) AS sales_channel_id, `client_id`, `agent_profile`,'
                . ' `redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method`'
                . ' FROM `%s`'
                . ' WHERE `handle_hash` = :handleHash AND `consumed_at` IS NULL AND `expires_at` > NOW(3)',
                self::TABLE,
            ),
            ['handleHash' => hash('sha256', $handle, true)],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function consume(string $handle): ?PendingAuthorization
    {
        $pending = $this->find($handle);

        if ($pending === null) {
            return null;
        }

        $claimed = $this->connection->executeStatement(
            \sprintf(
                'UPDATE `%s` SET `consumed_at` = NOW(3)'
                . ' WHERE `handle_hash` = :handleHash AND `consumed_at` IS NULL AND `expires_at` > NOW(3)',
                self::TABLE,
            ),
            ['handleHash' => hash('sha256', $handle, true)],
        );

        return $claimed === 1 ? $pending : null;
    }

    /**
     * Rows live ten minutes, so spent ones are cleared on the next write rather
     * than by a scheduled task.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function deleteSpent(): void
    {
        $this->connection->executeStatement(
            \sprintf(
                'DELETE FROM `%s` WHERE `expires_at` <= NOW(3) OR `consumed_at` IS NOT NULL',
                self::TABLE,
            ),
        );
    }

    private static function handle(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::HANDLE_BYTES)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws \JsonException
     */
    private static function hydrate(array $row): PendingAuthorization
    {
        /** @var array<string, mixed> $profile */
        $profile = json_decode((string) $row['agent_profile'], true, 512, \JSON_THROW_ON_ERROR);

        return new PendingAuthorization(
            (string) $row['sales_channel_id'],
            (string) $row['client_id'],
            $profile,
            (string) $row['redirect_uri'],
            (string) $row['scope'],
            (string) $row['state'],
            (string) $row['code_challenge'],
            (string) $row['code_challenge_method'],
        );
    }
}
```

- [ ] **Step 6: Write the migration**

`src/Migration/Migration1788300000CreatePendingAuthorization.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Authorization requests awaiting a human answer. Hand-written like the
 * decision table, and must stay in step with DbalPendingAuthorizationStore.
 *
 * The primary key is the handle's sha256, so the plaintext handle exists only
 * in the agent's memory and in the URL the human opens.
 */
class Migration1788300000CreatePendingAuthorization extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1788300000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_pending_authorization` (
                    `handle_hash`           BINARY(32)   NOT NULL,
                    `sales_channel_id`      BINARY(16)   NOT NULL,
                    `client_id`             VARCHAR(2048) NOT NULL,
                    `agent_profile`         JSON         NOT NULL,
                    `redirect_uri`          VARCHAR(2048) NOT NULL,
                    `scope`                 VARCHAR(1024) NOT NULL,
                    `state`                 VARCHAR(255) NOT NULL,
                    `code_challenge`        VARCHAR(255) NOT NULL,
                    `code_challenge_method` VARCHAR(16)  NOT NULL,
                    `created_at`            DATETIME(3)  NOT NULL,
                    `expires_at`            DATETIME(3)  NOT NULL,
                    `consumed_at`           DATETIME(3)  NULL,
                    PRIMARY KEY (`handle_hash`),
                    KEY `idx.pending_authorization.expires_at` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is dropped with the plugin.
    }
}
```

- [ ] **Step 7: Register the store**

In `src/Resources/config/services.php`, add the import and the binding alongside the existing `Identity` registrations:

```php
use MerchantQuoteAgentPlugin\Identity\Authorization\DbalPendingAuthorizationStore;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
```

```php
    $services->set(DbalPendingAuthorizationStore::class);
    $services->alias(PendingAuthorizationStoreInterface::class, DbalPendingAuthorizationStore::class);
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/DbalPendingAuthorizationStoreTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 9: Lint and typecheck**

```bash
composer run format:check && composer run lint && composer run typecheck
```

Expected: no findings for the new files.

- [ ] **Step 10: Commit**

```bash
git add src/Identity/Authorization src/Migration/Migration1788300000CreatePendingAuthorization.php \
        src/Resources/config/services.php tests/Unit/Identity/Authorization
git commit -m "feat: store authorization requests awaiting a human answer

Handles are hashed before they are persisted and consumption is a
conditional update, so one registration mints at most one code even
under concurrent submissions."
```

---

### Task 2: The boundary rule

**Files:**
- Create: `src/Identity/Authorization/UnverifiedAgentException.php`
- Create: `src/Identity/Authorization/AgentAuthorizationRegistrar.php`
- Test: `tests/Unit/Identity/Authorization/AgentAuthorizationRegistrarTest.php`

**Interfaces:**
- Consumes: `PendingAuthorization`, `PendingAuthorizationStoreInterface` (Task 1).
- Produces: `AgentAuthorizationRegistrar::register(array $payload, RequestContext $context, string $salesChannelId): string` returning the handle; throws `UnverifiedAgentException` or `Ucp\Sdk\Exception\ValidationException`.

This is the one class the spec's security boundary lives in. It gets its own task so a reviewer can gate it alone.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Identity/Authorization/AgentAuthorizationRegistrarTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\UnverifiedAgentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

#[CoversClass(AgentAuthorizationRegistrar::class)]
#[CoversClass(UnverifiedAgentException::class)]
final class AgentAuthorizationRegistrarTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const CLIENT_ID = 'https://agent.example/.well-known/ucp?run=1';

    /**
     * A recording double. The captured records are read off the object itself:
     * a promoted constructor property cannot be by-reference (fatal error), so
     * the double owns the array rather than aliasing the test's.
     */
    private function store()
    {
        // Deliberately no return type: the anonymous class's own shape has to
        // flow to the caller so `$store->stored` type-checks.
        return new class implements PendingAuthorizationStoreInterface {
            /** @var list<PendingAuthorization> */
            public array $stored = [];

            public function store(PendingAuthorization $pending, int $ttlSeconds): string
            {
                $this->stored[] = $pending;

                return 'handle-value';
            }

            public function find(string $handle): ?PendingAuthorization
            {
                return null;
            }

            public function consume(string $handle): ?PendingAuthorization
            {
                return null;
            }
        };
    }

    private function profile(): PlatformProfile
    {
        return new PlatformProfile('2026-04-08', [], [], []);
    }

    private function context(
        bool $signatureVerified = true,
        ?string $profileUri = self::CLIENT_ID,
        bool $withProfile = true,
    ): RequestContext {
        return new RequestContext(
            'shop.example',
            [],
            $profileUri,
            $withProfile ? $this->profile() : null,
            [],
            $signatureVerified,
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'https://agent.example/callback',
            'scope' => 'dev.ucp.shopping.order:read',
            'state' => 'state-value',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
        ];
    }

    public function testItRegistersAVerifiedRequest(): void
    {
        $store = $this->store();
        $registrar = new AgentAuthorizationRegistrar($store);

        $handle = $registrar->register($this->payload(), $this->context(), self::SALES_CHANNEL_ID);

        self::assertSame('handle-value', $handle);
        self::assertCount(1, $store->stored);
        self::assertSame(self::CLIENT_ID, $store->stored[0]->clientId);
        self::assertSame(self::SALES_CHANNEL_ID, $store->stored[0]->salesChannelId);
        self::assertSame('2026-04-08', $store->stored[0]->agentProfile['ucp']['version']);
    }

    /**
     * The reason this check cannot be dropped: under signaturePolicy "log" the
     * SDK proceeds on an unverified signature, so reaching a /ucp/ route proves
     * nothing. Without this, consent would stamp signatureVerified: true on an
     * agent nobody authenticated.
     */
    public function testItRefusesWhenTheRequestSignatureDidNotVerify(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store());

        $this->expectException(UnverifiedAgentException::class);

        $registrar->register($this->payload(), $this->context(signatureVerified: false), self::SALES_CHANNEL_ID);
    }

    public function testItRefusesWhenTheClientIdIsNotTheVerifiedProfileUri(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store());

        $this->expectException(UnverifiedAgentException::class);

        $registrar->register(
            $this->payload(),
            $this->context(profileUri: 'https://other.example/.well-known/ucp'),
            self::SALES_CHANNEL_ID,
        );
    }

    public function testItRefusesWhenNoProfileWasFetched(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store());

        $this->expectException(UnverifiedAgentException::class);

        $registrar->register($this->payload(), $this->context(withProfile: false), self::SALES_CHANNEL_ID);
    }

    public function testItRefusesAnythingButS256(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store());
        $payload = $this->payload();
        $payload['code_challenge_method'] = 'plain';

        $this->expectException(ValidationException::class);

        $registrar->register($payload, $this->context(), self::SALES_CHANNEL_ID);
    }

    public function testItRefusesAMissingCodeChallenge(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store());
        $payload = $this->payload();
        unset($payload['code_challenge']);

        $this->expectException(ValidationException::class);

        $registrar->register($payload, $this->context(), self::SALES_CHANNEL_ID);
    }

    public function testItRefusesAMissingState(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store());
        $payload = $this->payload();
        unset($payload['state']);

        $this->expectException(ValidationException::class);

        $registrar->register($payload, $this->context(), self::SALES_CHANNEL_ID);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/AgentAuthorizationRegistrarTest.php`
Expected: FAIL — `AgentAuthorizationRegistrar` not found.

- [ ] **Step 3: Write the exception**

`src/Identity/Authorization/UnverifiedAgentException.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * The agent registering an authorization request was not authenticated.
 *
 * A 401 rather than the SDK's OAuthException, which its ExceptionListener maps
 * to 400: the caller has not proven who it is. The listener honours any
 * HttpExceptionInterface's own status, so this surfaces as a real 401 inside a
 * UCP error envelope — the same reasoning as AgentCustomerAuthenticator.
 */
final class UnverifiedAgentException extends UnauthorizedHttpException
{
    public static function signatureNotVerified(): self
    {
        return new self('UCP-Agent', 'The request signature did not verify, so no authorization request was recorded.');
    }

    public static function clientIdMismatch(): self
    {
        return new self('UCP-Agent', 'client_id must be the signed platform profile URI of the calling agent.');
    }
}
```

- [ ] **Step 4: Write the registrar**

`src/Identity/Authorization/AgentAuthorizationRegistrar.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The security boundary of the browser authorization flow, in one place.
 *
 * Consent later hands Agentic Commerce a RequestContext this plugin built, with
 * `signatureVerified: true`. That claim is only truthful if the agent really was
 * authenticated, so a record is written *only* from a request whose signature
 * the SDK confirmed — read off the flag, never inferred from having been routed
 * under `/ucp/`. Under `signaturePolicy: log` the SDK logs an unverified
 * signature and carries on, which is precisely why the flag has to be read.
 *
 * If this check is ever weakened, the browser hop becomes a way to launder an
 * unauthenticated agent into a verified context. `AgentAuthorizationRegistrarTest`
 * pins both directions; neither test should be deleted.
 */
final readonly class AgentAuthorizationRegistrar
{
    public const TTL_SECONDS = 600;

    private const REQUIRED_CHALLENGE_METHOD = 'S256';

    public function __construct(
        private PendingAuthorizationStoreInterface $store,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UnverifiedAgentException
     * @throws ValidationException
     * @throws \Doctrine\DBAL\Exception
     */
    public function register(array $payload, RequestContext $context, string $salesChannelId): string
    {
        $clientId = self::requiredString($payload, 'client_id');

        $this->assertVerifiedAgent($context, $clientId);

        $challengeMethod = self::requiredString($payload, 'code_challenge_method');

        if ($challengeMethod !== self::REQUIRED_CHALLENGE_METHOD) {
            throw new ValidationException('Only the S256 PKCE challenge method is supported.', [
                '$.code_challenge_method must be "S256"',
            ]);
        }

        return $this->store->store(
            new PendingAuthorization(
                $salesChannelId,
                $clientId,
                $context->platformProfile?->toArray() ?? [],
                self::requiredString($payload, 'redirect_uri'),
                self::optionalString($payload, 'scope'),
                self::requiredString($payload, 'state'),
                self::requiredString($payload, 'code_challenge'),
                $challengeMethod,
            ),
            self::TTL_SECONDS,
        );
    }

    /** @throws UnverifiedAgentException */
    private function assertVerifiedAgent(RequestContext $context, string $clientId): void
    {
        if (!$context->signatureVerified || $context->platformProfile === null) {
            throw UnverifiedAgentException::signatureNotVerified();
        }

        if ($context->platformProfileUri === null || !hash_equals($context->platformProfileUri, $clientId)) {
            throw UnverifiedAgentException::clientIdMismatch();
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    private static function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new ValidationException(
                \sprintf('"%s" is required.', $key),
                [\sprintf('$.%s must be a non-empty string', $key)],
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    private static function optionalString(array $payload, string $key): string
    {
        if (!\array_key_exists($key, $payload)) {
            return '';
        }

        $value = $payload[$key];

        if (!\is_string($value)) {
            throw new ValidationException(
                \sprintf('"%s" must be a string.', $key),
                [\sprintf('$.%s must be a string', $key)],
            );
        }

        return $value;
    }
}
```

- [ ] **Step 5: Register the registrar**

In `src/Resources/config/services.php`:

```php
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
```

```php
    $services->set(AgentAuthorizationRegistrar::class);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/AgentAuthorizationRegistrarTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 7: Commit**

```bash
git add src/Identity/Authorization src/Resources/config/services.php tests/Unit/Identity/Authorization
git commit -m "feat: refuse authorization requests from unverified agents

Reads context->signatureVerified rather than trusting the /ucp/ prefix:
under signaturePolicy log the SDK proceeds on an unverified signature,
which would otherwise let the browser hop launder an unauthenticated
agent into a context stamped verified."
```

---

### Task 3: The registration endpoint

**Files:**
- Create: `src/Identity/Controller/AgentAuthorizationRequestController.php`
- Create: `src/Identity/Authorization/SalesChannelDomainUrlReader.php`
- Modify: `src/Resources/config/routes.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Identity/Controller/AgentAuthorizationRequestControllerTest.php`

**Interfaces:**
- Consumes: `AgentAuthorizationRegistrar::register()` (Task 2), `SalesChannelContextResolver::resolveSalesChannel()` returning `SalesChannelResolution` with `?string $domainId` (existing).
- Produces:
  - `SalesChannelDomainUrlReader::urlFor(?string $domainId): ?string`
  - Route `frontend.merchant_quote_agent.authorization_request` at `POST /ucp/quote-agent/authorization-requests`, responding `201` with `{request_uri, expires_in, authorization_url}`.

A POST with a JSON body and no query string, deliberately: the SDK verifies `@target-uri` against Symfony's normalised URI, so a signed GET with query parameters fails unless the client reproduces `ksort` + RFC3986 encoding exactly.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Identity/Controller/AgentAuthorizationRequestControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Controller\AgentAuthorizationRequestController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\ValidationException;

#[CoversClass(AgentAuthorizationRequestController::class)]
final class AgentAuthorizationRequestControllerTest extends TestCase
{
    public function testItRejectsABodyThatIsNotAJsonObject(): void
    {
        $controller = AgentAuthorizationRequestControllerFixture::build();
        $request = Request::create('/ucp/quote-agent/authorization-requests', 'POST', content: '[]');
        $request->attributes->set('ucp_request_context', AgentAuthorizationRequestControllerFixture::context());

        $this->expectException(ValidationException::class);

        $controller->register($request);
    }

    public function testItRejectsMalformedJson(): void
    {
        $controller = AgentAuthorizationRequestControllerFixture::build();
        $request = Request::create('/ucp/quote-agent/authorization-requests', 'POST', content: '{oops');
        $request->attributes->set('ucp_request_context', AgentAuthorizationRequestControllerFixture::context());

        $this->expectException(ValidationException::class);

        $controller->register($request);
    }

    public function testItFailsLoudlyWithoutAUcpRequestContext(): void
    {
        $controller = AgentAuthorizationRequestControllerFixture::build();
        $request = Request::create('/ucp/quote-agent/authorization-requests', 'POST', content: '{}');

        $this->expectException(ConfigurationException::class);

        $controller->register($request);
    }
}
```

`tests/Unit/Identity/Controller/AgentAuthorizationRequestControllerFixture.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\SalesChannelDomainUrlReader;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentAuthorizationRequestController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * Collaborators for the controller test. A fixture class rather than inline
 * anonymous classes so the three test methods read as one shape.
 */
final class AgentAuthorizationRequestControllerFixture
{
    public const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public static function context(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            [],
            'https://agent.example/.well-known/ucp',
            new PlatformProfile('2026-04-08', [], [], []),
            [],
            true,
        );
    }

    public static function build(): AgentAuthorizationRequestController
    {
        $store = new class implements PendingAuthorizationStoreInterface {
            public function store(PendingAuthorization $pending, int $ttlSeconds): string
            {
                return 'handle-value';
            }

            public function find(string $handle): ?PendingAuthorization
            {
                return null;
            }

            public function consume(string $handle): ?PendingAuthorization
            {
                return null;
            }
        };

        $resolver = new class implements CustomerContextResolverInterface {
            public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
            {
                return new SalesChannelResolution(
                    AgentAuthorizationRequestControllerFixture::SALES_CHANNEL_ID,
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0a',
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0b',
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0c',
                );
            }

            public function resolveForCustomer(
                string $customerId,
                RequestContext $context,
                ?SalesChannelResolution $resolution = null,
            ): SalesChannelContext {
                throw new \LogicException('not used in this test');
            }
        };

        $domains = new class extends SalesChannelDomainUrlReader {
            public function __construct() {}

            public function urlFor(?string $domainId): ?string
            {
                return 'https://shop.example';
            }
        };

        return new AgentAuthorizationRequestController(
            new AgentAuthorizationRegistrar($store),
            $resolver,
            $domains,
        );
    }
}
```

> Note for the implementer: `CustomerContextResolverInterface`'s exact method signatures are in `src/Bridge/CustomerContextResolverInterface.php`. Read it and match the fixture to it before running the test — if `resolveForCustomer` differs, fix the fixture, not the interface.

> **Also required (plan amendment):** have `build()` return the store double alongside the controller, or expose it from the fixture, and add `self::assertSame([], $store->stored)` inside each of the three refusal tests. Use try/catch with a trailing `self::fail(...)` rather than `expectException()`, which returns control on throw so trailing assertions never run. Rationale: every refusal test in this plan originally asserted only the exception class, which would pass an implementation that persisted first and then threw. The invariant is that nothing is recorded unless the agent is verified, so the tests must pin the write, not just the throw.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Identity/Controller/AgentAuthorizationRequestControllerTest.php`
Expected: FAIL — controller not found.

- [ ] **Step 3: Write the domain URL reader**

`src/Identity/Authorization/SalesChannelDomainUrlReader.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The absolute URL of one sales-channel domain.
 *
 * The consent URL must live on the domain the agent registered against, not on
 * whatever host the storefront happens to answer on: the sales channel is bound
 * across both hops, and a customer signed in on another channel must not be
 * able to answer this record. A single-column lookup, so a direct query rather
 * than the DAL — the same reasoning as SalesChannelContextResolver.
 *
 * Not `final`: the controller test substitutes it.
 */
class SalesChannelDomainUrlReader
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function urlFor(?string $domainId): ?string
    {
        if ($domainId === null || $domainId === '') {
            return null;
        }

        $url = $this->connection->fetchOne(
            'SELECT `url` FROM `sales_channel_domain` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($domainId)],
        );

        return \is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }
}
```

- [ ] **Step 4: Write the controller**

`src/Identity/Controller/AgentAuthorizationRequestController.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Controller;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\SalesChannelDomainUrlReader;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Where an agent registers an authorization request before sending a human to
 * the shop.
 *
 * A POST with a JSON body and deliberately no query string: the SDK verifies
 * `@target-uri` against Symfony's Request::getUri(), which re-sorts and
 * re-encodes the query, so a signed GET with parameters fails verification
 * unless the client reproduces that normalisation exactly. A body avoids the
 * trap for every client.
 *
 * Lives under `/ucp/` because that is where the SDK's RequestContextListener
 * builds a context — which is what makes the agent's identity known here and
 * unknown on the browser hop that follows.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class AgentAuthorizationRequestController
{
    public function __construct(
        private readonly AgentAuthorizationRegistrar $registrar,
        private readonly CustomerContextResolverInterface $contextResolver,
        private readonly SalesChannelDomainUrlReader $domains,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(
        path: '/ucp/quote-agent/authorization-requests',
        name: 'frontend.merchant_quote_agent.authorization_request',
        methods: ['POST'],
    )]
    public function register(Request $request): JsonResponse
    {
        $context = $this->requestContext($request);
        $resolution = $this->contextResolver->resolveSalesChannel($context);

        $handle = $this->registrar->register(
            $this->payload($request),
            $context,
            $resolution->salesChannelId,
        );

        $base = $this->domains->urlFor($resolution->domainId);

        if ($base === null) {
            throw new ConfigurationException(
                'The sales channel has no domain URL, so no consent page can be addressed.',
            );
        }

        return new JsonResponse(
            [
                'request_uri' => $handle,
                'expires_in' => AgentAuthorizationRegistrar::TTL_SECONDS,
                'authorization_url' => $base . '/quote-agent/authorize?request_uri=' . urlencode($handle),
            ],
            Response::HTTP_CREATED,
        );
    }

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

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function payload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Request body must be valid JSON.', ['$ must be a JSON object']);
        }

        if (!\is_array($payload) || array_is_list($payload)) {
            throw new ValidationException('Request body must be a JSON object.', ['$ must be a JSON object']);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
```

- [ ] **Step 5: Register the route and services**

In `src/Resources/config/routes.php`, import the new controller unconditionally — it does not depend on the commercial backend:

```php
    $routes->import(__DIR__ . '/../../Identity/Controller/AgentAuthorizationRequestController.php', 'attribute');
```

In `src/Resources/config/services.php`:

```php
use MerchantQuoteAgentPlugin\Identity\Authorization\SalesChannelDomainUrlReader;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentAuthorizationRequestController;
```

```php
    $services->set(SalesChannelDomainUrlReader::class);
    $services->set(AgentAuthorizationRequestController::class)->tag('controller.service_arguments');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Identity`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Identity src/Resources/config tests/Unit/Identity
git commit -m "feat: endpoint where an agent registers an authorization request

A POST with a JSON body and no query string: the SDK verifies
@target-uri against Symfony's normalised URI, so a signed GET with
parameters fails unless the client reproduces ksort plus RFC3986
encoding exactly."
```

---

### Task 4: Replaying the verified agent into a context

**Files:**
- Create: `src/Identity/Authorization/AgentAuthorizationContextFactory.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Identity/Authorization/AgentAuthorizationContextFactoryTest.php`

**Interfaces:**
- Consumes: `PendingAuthorization` (Task 1).
- Produces: `AgentAuthorizationContextFactory::forConsent(PendingAuthorization $pending, string $host, string $customerContextToken): RequestContext`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Identity/Authorization/AgentAuthorizationContextFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Profile\PlatformProfile;

#[CoversClass(AgentAuthorizationContextFactory::class)]
final class AgentAuthorizationContextFactoryTest extends TestCase
{
    private function pending(): PendingAuthorization
    {
        return new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            // json_decode(json_encode(...)) is NOT ceremony — it reproduces what
            // the store actually hands back, and the factory depends on it.
            // PlatformProfile::toArray() renders empty maps as stdClass so they
            // serialise as `{}` rather than `[]`, but fromArray() requires
            // arrays and throws ValidationException: 'Platform profile section
            // "services" must be an object.' on the stdClass form. The real flow
            // survives because the row goes through json_encode on write and
            // json_decode(..., true) on read; a test that skips that round-trip
            // fails for a reason unrelated to the code under test.
            json_decode(json_encode((new PlatformProfile('2026-04-08', [], [], []))->toArray()), true),
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }

    public function testItReplaysTheVerifiedAgentAndCarriesTheCustomerContextToken(): void
    {
        $context = (new AgentAuthorizationContextFactory())
            ->forConsent($this->pending(), 'shop.example', 'ctx-token');

        self::assertSame('shop.example', $context->host);
        self::assertSame('https://agent.example/.well-known/ucp', $context->platformProfileUri);
        self::assertNotNull($context->platformProfile);
        self::assertSame('2026-04-08', $context->platformProfile->version);
        self::assertTrue($context->signatureVerified);
        self::assertSame('ctx-token', $context->headers['sw-context-token']);
    }

    public function testItSatisfiesTheClientBindingItWasBuiltFrom(): void
    {
        $pending = $this->pending();

        $context = (new AgentAuthorizationContextFactory())->forConsent($pending, 'shop.example', 'ctx-token');

        // These are exactly the three things AC's assertClientId() checks.
        self::assertTrue($context->signatureVerified);
        self::assertNotNull($context->platformProfile);
        self::assertSame($pending->clientId, $context->platformProfileUri);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/AgentAuthorizationContextFactoryTest.php`
Expected: FAIL — factory not found.

- [ ] **Step 3: Write the factory**

`src/Identity/Authorization/AgentAuthorizationContextFactory.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Shopware\Core\PlatformRequest;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * Builds the RequestContext that consent hands to Agentic Commerce.
 *
 * `signatureVerified: true` is asserted here, and it is truthful only because
 * AgentAuthorizationRegistrar refused to store the record unless the SDK had
 * verified the agent's signature. The two classes are one mechanism: read them
 * together before changing either.
 *
 * The context carries the logged-in customer's context token, which is what
 * lets AC's adapter resolve a customer and mint a code against it.
 */
final readonly class AgentAuthorizationContextFactory
{
    public function forConsent(
        PendingAuthorization $pending,
        string $host,
        #[\SensitiveParameter] string $customerContextToken,
    ): RequestContext {
        return new RequestContext(
            $host,
            [strtolower(PlatformRequest::HEADER_CONTEXT_TOKEN) => $customerContextToken],
            $pending->clientId,
            PlatformProfile::fromArray($pending->agentProfile),
            [],
            true,
        );
    }
}
```

- [ ] **Step 4: Register it**

```php
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
```

```php
    $services->set(AgentAuthorizationContextFactory::class);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/AgentAuthorizationContextFactoryTest.php`
Expected: PASS, 2 tests.

`PlatformProfile::fromArray()` rejects a profile that has not been through JSON: `toArray()` emits `stdClass` for empty `services`/`capabilities`/`payment_handlers` maps, and `fromArray()` demands arrays, throwing `ValidationException: Platform profile section "services" must be an object.` The fixtures above JSON round-trip for exactly this reason. Do NOT loosen the factory to accept `stdClass` — the store is the only producer of this value in production and it always round-trips through JSON. Instead note the constraint in the factory's docblock, so a future caller who passes a freshly-`toArray()`d profile straight to `forConsent()` learns why it throws.

- [ ] **Step 6: Commit**

```bash
git add src/Identity/Authorization src/Resources/config/services.php tests/Unit/Identity/Authorization
git commit -m "feat: replay a verified agent profile into a request context

Paired with AgentAuthorizationRegistrar: the verified flag asserted
here is only truthful because the registrar refused to store a record
from an unverified request."
```

---

### Task 5: The consent page

**Files:**
- Modify: `composer.json` + `composer.lock` — see the dependency note below
- Create: `src/Identity/Controller/AgentConsentController.php`
- Create: `src/Resources/views/storefront/page/quote-agent/consent.html.twig`
- Create: `src/Resources/snippet/en_GB/messages.en-GB.json` (if absent — check first)
- Modify: `src/Resources/config/routes.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Identity/Controller/AgentConsentControllerTest.php`

**Interfaces:**
- Consumes: `PendingAuthorizationStoreInterface::find()`/`consume()` (Task 1), `AgentAuthorizationContextFactory::forConsent()` (Task 4), AC's `Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface`.
- Produces:
  - Route `frontend.merchant_quote_agent.authorize` at `GET /quote-agent/authorize`
  - Route `frontend.merchant_quote_agent.authorize.grant` at `POST /quote-agent/authorize`
  - Session key `merchant_quote_agent.pending_authorization`

> **Dependency note (plan amendment, verified before dispatch).** This is the
> plugin's first controller that renders HTML, and `StorefrontController` lives
> in `shopware/storefront`, which the plugin did NOT require — only
> `shopware/core`. Verified: the class was absent from vendor, so this task's
> controller (and the static-method test that loads it) would have fatalled at
> class load. `shopware/storefront:~6.7.0` has therefore been added to
> `composer.json` `require` and installed in the worktree; it resolved cleanly
> to v6.7.13.1, matching the shop's own Shopware version, pulling only
> `scssphp/scssphp` and `meyfa/php-svg`, with no advisories and the suite still
> green.
>
> Two consequences the implementer must handle:
> 1. `composer.json` and `composer.lock` are already modified but NOT committed.
>    Commit them TOGETHER with the controller. `composer run quality:depcheck`
>    currently FAILS with `shopware/storefront` reported unused, and only passes
>    once the controller actually extends `StorefrontController` — so a commit of
>    the dependency alone would leave the branch with a red gate.
> 2. The plugin's other three controllers (`UcpQuoteController`,
>    `QuoteContractController`, `AgentAuthorizationRequestController`) are plain
>    `final class` declarations that return JSON and extend nothing. Extending
>    `StorefrontController` here is a deliberate departure, justified because
>    this is the only controller rendering a themed page; say so in the
>    docblock so the inconsistency reads as a decision.
>
> Confirmed signature: `renderStorefront(string $view, array $parameters)`.
>
> Accepted limitation: a headless-only installation without the storefront
> bundle cannot serve this consent page. That is inherent — the page is a
> storefront page — and such an installation has no login UI to send a human to
> either.

**Why the handle goes in the session:** Shopware's login page and guest-login page disagree on whether `redirectParameters` is an array or a JSON string (`AuthController.php:106` passes `json_encode([])` as the default, `:134` passes `[]`). Stashing the handle in the session and redirecting to a parameterless `redirectTo` sidesteps that entirely, and keeps the handle out of the post-login URL and referrer.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Identity/Controller/AgentConsentControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentConsentController::class)]
final class AgentConsentControllerTest extends TestCase
{
    public function testItNamesTheAgentByHostRatherThanTheFullProfileUri(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp?run=1788265660',
            [],
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read dev.ucp.shopping.cart:manage',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertSame('agent.example', AgentConsentController::agentHost($pending));
        self::assertSame(
            ['dev.ucp.shopping.order:read', 'dev.ucp.shopping.cart:manage'],
            AgentConsentController::scopeList($pending),
        );
    }

    public function testAnEmptyScopeListsNothingRatherThanOneBlankEntry(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            '',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertSame([], AgentConsentController::scopeList($pending));
    }

    public function testDenialRedirectsToTheStoredRedirectUriWithTheOriginalState(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            '',
            'state value/with?chars',
            'challenge-value',
            'S256',
        );

        $url = AgentConsentController::denialUrl($pending);

        self::assertStringStartsWith('https://agent.example/callback?', $url);
        self::assertStringContainsString('error=access_denied', $url);
        self::assertStringContainsString('state=' . urlencode('state value/with?chars'), $url);
    }

    public function testDenialAppendsToARedirectUriThatAlreadyHasAQuery(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback?existing=1',
            '',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertStringContainsString('?existing=1&', AgentConsentController::denialUrl($pending));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Identity/Controller/AgentConsentControllerTest.php`
Expected: FAIL — controller not found.

- [ ] **Step 3: Write the controller**

`src/Identity/Controller/AgentConsentController.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;

/**
 * The human half of identity linking: sign in, see who is asking, decide.
 *
 * The browser sends no signature and none is needed — the agent was
 * authenticated when it registered the request, and
 * AgentAuthorizationContextFactory replays that. What this controller must not
 * do is trust anything the browser supplies beyond the handle: the redirect
 * target, scope and PKCE challenge all come from the stored record.
 *
 * The handle is stashed in the session before the login redirect rather than
 * passed through Shopware's `redirectParameters`, which the login page and the
 * guest-login page type differently (array vs. JSON string). It also keeps the
 * handle out of the post-login URL.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class AgentConsentController extends StorefrontController
{
    public const SESSION_KEY = 'merchant_quote_agent.pending_authorization';

    public function __construct(
        private readonly PendingAuthorizationStoreInterface $store,
        private readonly AgentAuthorizationContextFactory $contextFactory,
        private readonly IdentityLinkingCapabilityInterface $identityLinking,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Route(
        path: '/quote-agent/authorize',
        name: 'frontend.merchant_quote_agent.authorize',
        methods: ['GET'],
    )]
    public function authorize(Request $request, SalesChannelContext $context): Response
    {
        $handle = $this->handle($request);
        $pending = $handle === null ? null : $this->store->find($handle);

        if ($pending === null) {
            return $this->renderStorefront(
                '@MerchantQuoteAgentPlugin/storefront/page/quote-agent/consent.html.twig',
                ['expired' => true],
            );
        }

        if ($context->getCustomer() === null) {
            // Stash and hand over to the shop's own login page.
            $request->getSession()->set(self::SESSION_KEY, $handle);

            return $this->redirectToRoute('frontend.account.login.page', [
                'redirectTo' => 'frontend.merchant_quote_agent.authorize',
            ]);
        }

        return $this->renderStorefront(
            '@MerchantQuoteAgentPlugin/storefront/page/quote-agent/consent.html.twig',
            [
                'expired' => false,
                'agentHost' => self::agentHost($pending),
                'scopes' => self::scopeList($pending),
                'handle' => $handle,
                'expiresInMinutes' => intdiv(AgentAuthorizationRegistrar::TTL_SECONDS, 60),
            ],
        );
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Route(
        path: '/quote-agent/authorize',
        name: 'frontend.merchant_quote_agent.authorize.grant',
        methods: ['POST'],
    )]
    public function grant(Request $request, SalesChannelContext $context): Response
    {
        $handle = (string) $request->request->get('request_uri', '');
        $pending = $handle === '' ? null : $this->store->find($handle);
        $customer = $context->getCustomer();

        if ($pending === null || $customer === null) {
            return $this->renderStorefront(
                '@MerchantQuoteAgentPlugin/storefront/page/quote-agent/consent.html.twig',
                ['expired' => true],
            );
        }

        // The sales channel is bound across both hops. Without this a customer
        // signed in on another channel could answer this record, and AC would
        // not catch it: it compares the customer's channel against the one it
        // resolves from the context we build, i.e. against itself.
        if (!hash_equals($pending->salesChannelId, $context->getSalesChannelId())) {
            return $this->renderStorefront(
                '@MerchantQuoteAgentPlugin/storefront/page/quote-agent/consent.html.twig',
                ['expired' => true],
            );
        }

        if (!$request->request->getBoolean('grant')) {
            return new RedirectResponse(self::denialUrl($pending));
        }

        $claimed = $this->store->consume($handle);

        if ($claimed === null) {
            return $this->renderStorefront(
                '@MerchantQuoteAgentPlugin/storefront/page/quote-agent/consent.html.twig',
                ['expired' => true],
            );
        }

        $result = $this->identityLinking->authorize(
            new OAuthAuthorizationRequest(
                $claimed->clientId,
                $claimed->redirectUri,
                $claimed->scope,
                $claimed->state,
                $claimed->codeChallenge,
                $claimed->codeChallengeMethod,
            ),
            $this->contextFactory->forConsent(
                $claimed,
                (string) $request->getHost(),
                $context->getToken(),
            ),
        );

        $redirectTo = $result['redirect_to'] ?? null;

        if (!\is_string($redirectTo) || $redirectTo === '') {
            throw new \RuntimeException('The identity-linking capability returned no redirect target.');
        }

        return new RedirectResponse($redirectTo);
    }

    public static function agentHost(PendingAuthorization $pending): string
    {
        $host = parse_url($pending->clientId, \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? $host : $pending->clientId;
    }

    /** @return list<string> */
    public static function scopeList(PendingAuthorization $pending): array
    {
        return array_values(array_filter(
            explode(' ', trim($pending->scope)),
            static fn(string $scope): bool => $scope !== '',
        ));
    }

    public static function denialUrl(PendingAuthorization $pending): string
    {
        $query = http_build_query(['error' => 'access_denied', 'state' => $pending->state]);
        $separator = str_contains($pending->redirectUri, '?') ? '&' : '?';

        return $pending->redirectUri . $separator . $query;
    }

    private function handle(Request $request): ?string
    {
        $fromQuery = $request->query->get('request_uri');

        if (\is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }

        // Returning from the shop's login page.
        $session = $request->getSession();
        $stashed = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        return \is_string($stashed) && $stashed !== '' ? $stashed : null;
    }
}
```

- [ ] **Step 4: Write the template**

`src/Resources/views/storefront/page/quote-agent/consent.html.twig`:

```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_main_inner %}
    <div class="container">
        {% if expired %}
            <div class="alert alert-warning" role="alert">
                {{ "merchantQuoteAgent.consent.expired"|trans }}
            </div>
        {% else %}
            <h1>{{ "merchantQuoteAgent.consent.headline"|trans }}</h1>

            <p>{{ "merchantQuoteAgent.consent.intro"|trans({'%host%': agentHost}) }}</p>

            {% if scopes is not empty %}
                <ul>
                    {% for scope in scopes %}
                        <li><code>{{ scope }}</code></li>
                    {% endfor %}
                </ul>
            {% endif %}

            <p>{{ "merchantQuoteAgent.consent.effect"|trans }}</p>

            <p class="text-muted">
                {{ "merchantQuoteAgent.consent.expiry"|trans({'%minutes%': expiresInMinutes}) }}
            </p>

            <form method="post" action="{{ path('frontend.merchant_quote_agent.authorize.grant') }}">
                <input type="hidden" name="request_uri" value="{{ handle }}">
                <button type="submit" name="grant" value="1" class="btn btn-primary">
                    {{ "merchantQuoteAgent.consent.allow"|trans }}
                </button>
                <button type="submit" name="grant" value="0" class="btn btn-link">
                    {{ "merchantQuoteAgent.consent.deny"|trans }}
                </button>
            </form>
        {% endif %}
    </div>
{% endblock %}
```

- [ ] **Step 5: Add the snippets**

Create `src/Resources/snippet/en_GB/messages.en-GB.json` if it does not exist — check with `ls src/Resources/snippet` first and merge into the existing file if one is there:

```json
{
    "merchantQuoteAgent": {
        "consent": {
            "headline": "Authorize this agent",
            "intro": "The agent at %host% is asking to act for you at this shop.",
            "effect": "If you allow this, the agent can request and negotiate quotes on your behalf. You can have the merchant revoke it at any time.",
            "allow": "Allow",
            "deny": "Deny",
            "expiry": "This request can only be answered once, and expires %minutes% minutes after the agent asked.",
            "expired": "This authorization link has expired or has already been used. Ask the agent for a new one."
        }
    }
}
```

- [ ] **Step 6: Register the route and services**

In `src/Resources/config/routes.php`:

```php
    $routes->import(__DIR__ . '/../../Identity/Controller/AgentConsentController.php', 'attribute');
```

In `src/Resources/config/services.php`:

```php
use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
```

```php
    $services->set(AgentConsentController::class)
        ->public()
        ->tag('controller.service_arguments')
        ->call('setContainer', [service('service_container')]);
```

> `StorefrontController` extends Symfony's `AbstractController`, which needs the container injected and the service public. If the container refuses the explicit `setContainer` call (some Shopware versions wire it through the `controller.service_arguments` tag alone), drop the `->call(...)` line and keep `->public()->tag('controller.service_arguments')`. Confirm which is right by comparing against a core storefront controller's registration:
>
> ```bash
> grep -rn "AccountProfileController" vendor/shopware/storefront/DependencyInjection/*.xml vendor/shopware/storefront/Resources/config/*.xml | head
> ```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Identity/Controller/AgentConsentControllerTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Verify the container still boots**

```bash
vendor/bin/phpunit --testsuite unit
composer run lint && composer run typecheck
```

- [ ] **Step 9: Commit**

```bash
git add src/Identity/Controller src/Resources tests/Unit/Identity/Controller
git commit -m "feat: storefront consent page for agent identity linking

Signs the customer in through the shop's own login, then binds the
sales channel across both hops - AC cannot catch a cross-channel grant
because it compares the customer's channel against the one it resolves
from the context we build."
```

---

### Task 6: Listing and revoking grants

**Files:**
- Modify: `composer.json` — declare `symfony/console` (see the dependency note below)
- Create: `src/Identity/Authorization/AgentGrantReaderInterface.php`
- Create: `src/Identity/Authorization/AcAgentGrantReader.php`
- Create: `src/Command/AgentGrantsCommand.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Identity/Authorization/AcAgentGrantReaderTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `AgentGrantReaderInterface::grantsFor(?string $customerId): list<array{customer_id: string, client_id: string, scope: string, expires_at: int, revoked: bool}>`
  - `AgentGrantReaderInterface::revoke(string $customerId, string $clientId): int` returning rows revoked.

This is a fifth direct read of AC's OAuth tables. It goes behind an interface so #13's upstream fix retires it together with `AcOAuthAccessTokenReader`.

> **Dependency note (plan amendment, verified before dispatch).** This is the
> plugin's first console command, and `symfony/console` is NOT declared in
> `composer.json` — the classes resolve only transitively. This plugin declares
> every Symfony component it uses directly (there are eight), and
> `composer run quality:depcheck` reports shadow dependencies, so using
> `Symfony\Component\Console\*` without declaring it would trip that gate.
>
> Add `"symfony/console": "^7.4"` to `require`, matching the constraint the other
> Symfony components use. Verified: it resolves as a declaration-only change —
> 0 installs, 1 lock update — because the package is already present
> transitively. No `composer install` needed, and no new code arrives in vendor.
> Commit `composer.json` (and `composer.lock`) together with the command, and
> confirm `composer run quality:depcheck` passes before finishing.

> **Verified assumption:** AC stores `subject` as a plain string, not binary.
> Its own `DoctrineDbalUcpOAuthStore` writes `'subject' => $subject` and reads
> `(string) $row['subject']`, with no `HEX()` wrapping — unlike
> `sales_channel_id`, which it reads as `LOWER(HEX(sales_channel_id))`. So do
> NOT wrap the customer id in `Uuid::fromHexToBytes()` when querying by subject.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Identity/Authorization/AcAgentGrantReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\Authorization\AcAgentGrantReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcAgentGrantReader::class)]
final class AcAgentGrantReaderTest extends TestCase
{
    public function testItReadsAgenticCommerceOwnTablesAndReportsRevocation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::callback(
                static fn(string $sql): bool => str_contains($sql, 'swag_agentic_commerce_ucp_oauth_access_token')
                    && str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token'),
            ))
            ->willReturn([[
                'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                'client_id' => 'https://agent.example/.well-known/ucp',
                'scope' => 'dev.ucp.shopping.order:read',
                'expires_at' => 1788300000,
                'revoked_at' => '2026-09-01 10:00:00',
            ]]);

        $grants = (new AcAgentGrantReader($connection))->grantsFor(null);

        self::assertCount(1, $grants);
        self::assertTrue($grants[0]['revoked']);
        self::assertSame('https://agent.example/.well-known/ucp', $grants[0]['client_id']);
    }

    public function testRevokeStampsTheRefreshTokenRowAndReturnsTheCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(
                    static fn(string $sql): bool => str_contains($sql, 'UPDATE')
                        && str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token')
                        && str_contains($sql, 'revoked_at'),
                ),
            )
            ->willReturn(2);

        $revoked = (new AcAgentGrantReader($connection))->revoke(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
            'https://agent.example/.well-known/ucp',
        );

        self::assertSame(2, $revoked);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/AcAgentGrantReaderTest.php`
Expected: FAIL — reader not found.

- [ ] **Step 3: Write the interface**

`src/Identity/Authorization/AgentGrantReaderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

/**
 * Reading and revoking the grants customers have given agents.
 *
 * An interface because the implementation reads Agentic Commerce's OAuth tables
 * directly, exactly as AcOAuthAccessTokenReader does. Issue #13 adds a read side
 * to that store upstream; when it lands both implementations become delegates
 * and nothing else moves.
 */
interface AgentGrantReaderInterface
{
    /**
     * @return list<array{customer_id: string, client_id: string, scope: string, expires_at: int, revoked: bool}>
     */
    public function grantsFor(?string $customerId): array;

    /** @return int the number of refresh-token rows marked revoked */
    public function revoke(string $customerId, string $clientId): int;
}
```

- [ ] **Step 4: Write the implementation**

`src/Identity/Authorization/AcAgentGrantReader.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The coupling to Agentic Commerce's OAuth storage, second instance.
 *
 * Revocation is recorded on the refresh-token row rather than the access-token
 * row — the same asymmetry AcOAuthAccessTokenReader documents and relies on, so
 * stamping it there is what actually makes an access token stop resolving.
 */
final readonly class AcAgentGrantReader implements AgentGrantReaderInterface
{
    private const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';

    private const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function grantsFor(?string $customerId): array
    {
        $sql = \sprintf(
            'SELECT a.subject, a.client_id, a.scope, a.expires_at, r.revoked_at'
            . ' FROM `%s` AS a'
            . ' LEFT JOIN `%s` AS r ON r.token_hash = a.refresh_token_hash',
            self::ACCESS_TOKEN_TABLE,
            self::REFRESH_TOKEN_TABLE,
        );
        $params = [];

        if ($customerId !== null) {
            $sql .= ' WHERE a.subject = :subject';
            $params['subject'] = $customerId;
        }

        $rows = $this->connection->fetchAllAssociative($sql . ' ORDER BY a.expires_at DESC', $params);

        return array_map(
            static fn(array $row): array => [
                'customer_id' => (string) $row['subject'],
                'client_id' => (string) $row['client_id'],
                'scope' => (string) $row['scope'],
                'expires_at' => (int) $row['expires_at'],
                'revoked' => $row['revoked_at'] !== null,
            ],
            $rows,
        );
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function revoke(string $customerId, string $clientId): int
    {
        return $this->connection->executeStatement(
            \sprintf(
                'UPDATE `%s` AS r'
                . ' INNER JOIN `%s` AS a ON a.refresh_token_hash = r.token_hash'
                . ' SET r.revoked_at = NOW(3)'
                . ' WHERE a.subject = :subject AND a.client_id = :clientId AND r.revoked_at IS NULL',
                self::REFRESH_TOKEN_TABLE,
                self::ACCESS_TOKEN_TABLE,
            ),
            ['subject' => $customerId, 'clientId' => $clientId],
        );
    }
}
```

> The `subject` column stores the customer id. `AcOAuthAccessTokenReader` reads it as a plain string and passes it to `resolveForCustomer()`, so it is hex, not binary — do not wrap it in `Uuid::fromHexToBytes()`. If the integration test in Task 7 disagrees, trust the database and fix this.

- [ ] **Step 5: Write the command**

`src/Command/AgentGrantsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentGrantReaderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operational off-switch for agent grants.
 *
 * Console-only by decision: revocation has to be possible, not self-service,
 * until a merchant asks for a storefront page. Echoing is allowed here — Mago's
 * no-debug-symbols rule exempts commands, and a console command that printed
 * nothing would be useless.
 */
#[AsCommand(name: 'merchant-quote-agent:agent-grants', description: 'List or revoke agent identity-linking grants')]
final class AgentGrantsCommand extends Command
{
    public function __construct(
        private readonly AgentGrantReaderInterface $grants,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('customerId', InputArgument::OPTIONAL, 'Filter by customer id (hex)');
        $this->addOption('revoke', null, InputOption::VALUE_REQUIRED, 'Revoke this client id for the given customer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $customerId = $input->getArgument('customerId');
        $customerId = \is_string($customerId) && $customerId !== '' ? $customerId : null;
        $revoke = $input->getOption('revoke');

        if (\is_string($revoke) && $revoke !== '') {
            if ($customerId === null) {
                $io->error('Revoking needs a customer id: merchant-quote-agent:agent-grants <customerId> --revoke=<clientId>');

                return Command::INVALID;
            }

            $count = $this->grants->revoke($customerId, $revoke);
            $io->success(\sprintf('Revoked %d grant(s) for %s.', $count, $revoke));

            return Command::SUCCESS;
        }

        $rows = $this->grants->grantsFor($customerId);

        if ($rows === []) {
            $io->writeln('No grants.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Customer', 'Agent (client id)', 'Scope', 'Expires', 'Revoked'],
            array_map(
                static fn(array $row): array => [
                    $row['customer_id'],
                    $row['client_id'],
                    $row['scope'] === '' ? '-' : $row['scope'],
                    date('Y-m-d H:i:s', $row['expires_at']),
                    $row['revoked'] ? 'yes' : 'no',
                ],
                $rows,
            ),
        );

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 6: Register both**

```php
use MerchantQuoteAgentPlugin\Command\AgentGrantsCommand;
use MerchantQuoteAgentPlugin\Identity\Authorization\AcAgentGrantReader;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentGrantReaderInterface;
```

```php
    $services->set(AcAgentGrantReader::class);
    $services->alias(AgentGrantReaderInterface::class, AcAgentGrantReader::class);
    $services->set(AgentGrantsCommand::class)->tag('console.command');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Identity/Authorization/AcAgentGrantReaderTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 8: Commit**

```bash
git add src/Identity/Authorization src/Command src/Resources/config/services.php tests/Unit
git commit -m "feat: list and revoke agent grants from the console

Revocation is stamped on Agentic Commerce's refresh-token row, the
asymmetry AcOAuthAccessTokenReader already relies on, so an access
token really does stop resolving."
```

---

### Task 7: End-to-end integration test

**Files:**
- Create: `tests/Integration/BrowserIdentityLinkingTest.php`
- Test: itself

**Interfaces:**
- Consumes: everything from Tasks 1-6.
- Produces: nothing.

- [ ] **Step 1: Read the existing integration setup**

```bash
sed -n '1,120p' tests/Integration/IntegrationTestCase.php
sed -n '1,80p' tests/Integration/UcpQuoteEndpointTest.php
cat phpunit.integration.xml.dist
```

Note how the suite boots the kernel and how `UcpQuoteEndpointTest` issues requests; mirror it rather than inventing a second style.

- [ ] **Step 2: Write the test**

`tests/Integration/BrowserIdentityLinkingTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\UnverifiedAgentException;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * The store and the boundary rule against a real database.
 *
 * The full HTTP chain (signed POST, storefront login, consent, token exchange)
 * is exercised by scripts/ucp-quote-agent.py against a live shop; what belongs
 * here is the part that can regress silently.
 */
final class BrowserIdentityLinkingTest extends IntegrationTestCase
{
    private function store(): PendingAuthorizationStoreInterface
    {
        $store = static::getContainer()->get(PendingAuthorizationStoreInterface::class);
        self::assertInstanceOf(PendingAuthorizationStoreInterface::class, $store);

        return $store;
    }

    private function pending(string $salesChannelId): PendingAuthorization
    {
        return new PendingAuthorization(
            $salesChannelId,
            'https://agent.example/.well-known/ucp',
            // json_decode(json_encode(...)) is NOT ceremony — it reproduces what
            // the store actually hands back, and the factory depends on it.
            // PlatformProfile::toArray() renders empty maps as stdClass so they
            // serialise as `{}` rather than `[]`, but fromArray() requires
            // arrays and throws ValidationException: 'Platform profile section
            // "services" must be an object.' on the stdClass form. The real flow
            // survives because the row goes through json_encode on write and
            // json_decode(..., true) on read; a test that skips that round-trip
            // fails for a reason unrelated to the code under test.
            json_decode(json_encode((new PlatformProfile('2026-04-08', [], [], []))->toArray()), true),
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }

    private function salesChannelId(): string
    {
        $id = static::getContainer()->get('Doctrine\DBAL\Connection')
            ?->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel WHERE active = 1 LIMIT 1');
        self::assertIsString($id, 'the test shop has no active sales channel');

        return $id;
    }

    public function testAHandleRoundTripsAndCanOnlyBeConsumedOnce(): void
    {
        $store = $this->store();
        $handle = $store->store($this->pending($this->salesChannelId()), 600);

        $found = $store->find($handle);
        self::assertNotNull($found);
        self::assertSame('https://agent.example/callback', $found->redirectUri);

        self::assertNotNull($store->consume($handle), 'the first consume must succeed');
        self::assertNull($store->consume($handle), 'the second consume must yield nothing');
        self::assertNull($store->find($handle), 'a consumed handle must no longer be findable');
    }

    public function testAnExpiredHandleIsNeitherFoundNorConsumed(): void
    {
        $store = $this->store();
        $handle = $store->store($this->pending($this->salesChannelId()), -1);

        self::assertNull($store->find($handle));
        self::assertNull($store->consume($handle));
    }

    /**
     * The refusal is the lesser half of this test. The valuable assertion is
     * that the table is UNCHANGED: this is the only place the write-side of
     * the boundary rule is exercised against a real database rather than a
     * double, so it is the only place a persist-then-throw ordering bug would
     * actually be caught. `expectException()` cannot be used here — it returns
     * control the moment the exception is thrown, so any assertion written
     * after the call never runs. Hence try/catch with an explicit fail().
     */
    public function testTheRegistrarRefusesAnUnverifiedAgentAndPersistsNothing(): void
    {
        $registrar = new AgentAuthorizationRegistrar($this->store(), new PayloadFields());
        $connection = static::getContainer()->get('Doctrine\DBAL\Connection');
        self::assertNotNull($connection);
        $before = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM merchant_quote_agent_pending_authorization',
        );

        try {
            $this->registerUnverified($registrar);
            self::fail('An unverified agent must not be able to register an authorization request.');
        } catch (UnverifiedAgentException) {
            $after = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM merchant_quote_agent_pending_authorization',
            );
            self::assertSame($before, $after, 'no row may be written when the agent is unverified');
        }
    }

    private function registerUnverified(AgentAuthorizationRegistrar $registrar): void
    {
        $registrar->register(
            [
                'client_id' => 'https://agent.example/.well-known/ucp',
                'redirect_uri' => 'https://agent.example/callback',
                'scope' => '',
                'state' => 'state-value',
                'code_challenge' => 'challenge-value',
                'code_challenge_method' => 'S256',
            ],
            new RequestContext(
                'shop.example',
                [],
                'https://agent.example/.well-known/ucp',
                new PlatformProfile('2026-04-08', [], [], []),
                [],
                false,
            ),
            $this->salesChannelId(),
        );
    }

    public function testTheConsentContextSatisfiesTheClientBinding(): void
    {
        $pending = $this->pending($this->salesChannelId());

        $context = (new AgentAuthorizationContextFactory())
            ->forConsent($pending, 'shop.example', 'ctx-token');

        self::assertTrue($context->signatureVerified);
        self::assertSame($pending->clientId, $context->platformProfileUri);
        self::assertNotNull($context->platformProfile);
    }
}
```

- [ ] **Step 3: Run the integration suite**

```bash
./scripts/test-integration.sh
```

Read `scripts/test-integration.sh` first — it wraps `phpunit.integration.xml.dist` and expects a shop. If it needs a running shop that is not up, note it and run:

```bash
vendor/bin/phpunit -c phpunit.integration.xml.dist --filter BrowserIdentityLinkingTest
```

Expected: PASS, 4 tests.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/BrowserIdentityLinkingTest.php
git commit -m "test: handle lifecycle and the boundary rule against a real database"
```

---

### Task 8: Point the test client at the real flow

**Files:**
- Modify: `scripts/ucp-quote-agent.py` (from the primary checkout — copy it in first; it is untracked there)

**Interfaces:**
- Consumes: the endpoints from Tasks 3 and 5.
- Produces: nothing.

- [ ] **Step 1: Bring the script into this branch, FIRST, and commit it**

Do this before any editing. The copy in the primary checkout is currently the
only copy and it is untracked, so this `cp` is the moment it first gets version
control — an interrupted Task 8 must not leave it unprotected.

```bash
cp ~/projects/merchant-quote-agent-plugin/scripts/ucp-quote-agent.py scripts/
python3 scripts/ucp-quote-agent.py --selftest
```

Expected: `selftest ok`.

The `.gitignore` rules that belong with this script are ALREADY on this branch
(committed separately, ahead of this task) — verify with
`grep ucp-agent-key .gitignore` and do not duplicate them:

```
scripts/.ucp-agent-key.pem
scripts/__pycache__/
```

Why that matters, and why it is not hygiene: the script generates an ephemeral
EC **private key** at `scripts/.ucp-agent-key.pem` and removes it in a `finally`
block, but a killed run — Ctrl-C, a crash, a sleeping machine, all of which have
happened during this plan — leaves it on disk. Without the ignore rule it then
appears untracked in `git status`, which is exactly how a key gets swept into a
`git add -A`. The path is derived from the script's own location
(`os.path.dirname(os.path.abspath(__file__))`), so if you ever relocate the
script, the ignore rule has to move with it.

- [ ] **Step 2: Replace the local sign-in page with the shop's flow**

Delete `LOGIN_PAGE`, the `/login` and `/logged-in` handlers, `seed_context_token()`, the `context_token` argument of `call()`, and the `--access-key` plumbing (argument, prompt, and both `run()`/`login()` parameters). Restore a `/callback` GET handler on the tunnel:

```python
        elif parsed.path == "/callback":
            STATE["callback"] = dict(urllib.parse.parse_qsl(parsed.query))
            SIGNED_IN.set()
            self._send(b"<h1>Authorized.</h1><p>Back to the terminal.</p>", "text/html")
```

Then replace `login()` entirely:

```python
def login(shop: str, meta: dict, agent: str, redirect: str) -> str:
    """Register the request, send the human to the shop, exchange the code.

    The consent page is the shop's own now: it signs the customer in through
    the storefront login and renders the grant. This agent never sees a
    credential, and no longer needs a Store API access key to get one.
    """
    verifier = b64u(secrets.token_bytes(32))
    challenge = b64u(hashlib.sha256(verifier.encode()).digest())
    expected_state = b64u(secrets.token_bytes(16))

    registered = call(
        "POST",
        f"{shop}/ucp/quote-agent/authorization-requests",
        {
            "client_id": agent,
            "redirect_uri": redirect,
            "scope": " ".join(meta.get("scopes_supported", [])),
            "state": expected_state,
            "code_challenge": challenge,
            "code_challenge_method": "S256",
        },
        agent=agent,
        label="authorization-request",
        quiet=True,
    )
    if "__error__" in registered:
        sys.exit("[fatal] the shop refused to register the authorization request - see above.")

    url = registered.get("authorization_url")
    if not url:
        sys.exit(f"[fatal] no authorization_url in the response: {registered}")

    print(f"\n[login] opening the shop's own sign-in and consent page:\n        {url}\n")
    webbrowser.open(url)

    print("[login] waiting for consent to come back over the tunnel (10 min)...")
    if not SIGNED_IN.wait(600):
        sys.exit("[fatal] no consent callback within 10 minutes")

    callback = STATE["callback"]
    if callback.get("error"):
        sys.exit(f"[fatal] consent was refused: {callback['error']}")
    if callback.get("state") != expected_state:
        sys.exit(f"[fatal] OAuth state mismatch - discarding. got {callback.get('state')!r}")
    code = callback.get("code")
    if not code:
        sys.exit(f"[fatal] no authorization code in the callback: {callback}")

    granted = call(
        "POST",
        meta["token_endpoint"],
        form={
            "grant_type": "authorization_code",
            "code": code,
            "redirect_uri": redirect,
            "client_id": agent,
            "code_verifier": verifier,
        },
        agent=agent,
        label="oauth.token",
        quiet=True,
    )
    token = granted.get("access_token")
    if not token:
        sys.exit(f"[fatal] no access_token in the token response: {granted}")
    print(f"[login] token acquired (scope: {granted.get('scope') or '(none)'}) - memory only")
    return token
```

Update the one call site in `run()` from `login(shop, oauth, agent, redirect, tunnel, access_key)` to `login(shop, oauth, agent, redirect)`, and drop `access_key` from `run()`'s signature and from the `run(...)` invocation at the bottom of the file.

- [ ] **Step 3: Verify against the live shop**

```bash
python3 scripts/ucp-quote-agent.py --selftest
python3 scripts/ucp-quote-agent.py --shop https://agenticquote-shoelscher.eu-core-1.shopdev.de
```

Expected: the browser opens the shop's own login page; after signing in, a consent page naming the agent's ngrok host; allowing it redirects to the tunnel and the script proceeds to file the RFQ.

- [ ] **Step 4: Commit**

```bash
git add scripts/ucp-quote-agent.py
git commit -m "test: drive the shop's own consent flow from the test client

Consent moves from a page the client served to the shop's own login,
which is where it belonged; the client no longer needs a Store API
access key."
```

---

## Notes for the executor

- **Never weaken `AgentAuthorizationRegistrar::assertVerifiedAgent()`** or delete either of its refusal tests. The spec explains why it looks redundant and why it is not.
- **Enabling the flow on a shop** needs `identity_linking` in the sales channel's `enabledCapabilities` (`PUT /api/_admin/ucp/sales-channels/{id}/config` — `PUT`, not `POST`), and the agent's profile host allowlisted in *both* the per-channel lists and `ucp_sdk.allowed_profile_hosts`. The concurrent `feat/agent-access-controls` work owns the allowlist half; do not duplicate it here.
- **`composer run quality:depcheck`** will flag any new dependency. There should be none.
- If `IdentityLinkingCapabilityInterface` cannot be injected because AC's alias is private in this Shopware version, add `$services->alias(...)->public()` **in our own** `services.php` pointing at AC's concrete class id — do not edit AC.
