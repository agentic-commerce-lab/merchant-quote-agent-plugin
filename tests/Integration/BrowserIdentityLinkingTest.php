<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PayloadFields;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\UnverifiedAgentException;
use MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization\AgentAuthorizationRegistrarFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization\IdentityLinkingCapabilityFixture;
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
        $id = static::getContainer()
            ->get('Doctrine\DBAL\Connection')
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
        $registrar = AgentAuthorizationRegistrarFixture::registrar($this->store());
        $connection = static::getContainer()->get('Doctrine\DBAL\Connection');
        self::assertNotNull($connection);
        $before = (int) $connection->fetchOne('SELECT COUNT(*) FROM merchant_quote_agent_pending_authorization');

        try {
            $this->registerUnverified($registrar);
            self::fail('An unverified agent must not be able to register an authorization request.');
        } catch (UnverifiedAgentException) {
            $after = (int) $connection->fetchOne('SELECT COUNT(*) FROM merchant_quote_agent_pending_authorization');
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
                'code_challenge' => AgentAuthorizationRegistrarFixture::CODE_CHALLENGE,
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

    /**
     * `PlatformProfile::toArray()` renders empty `services`/`capabilities`/
     * `payment_handlers` maps as `stdClass`, but `fromArray()` requires arrays
     * and throws `ValidationException` on the `stdClass` form. Production only
     * survives that mismatch because `DbalPendingAuthorizationStore::store()`
     * runs the profile through `json_encode()` and `find()` runs the row back
     * through `json_decode(..., true)`, which normalises `stdClass` to array.
     * Every other fixture in this file *simulates* that with an explicit
     * `json_decode(json_encode(...))` — this test feeds `toArray()`'s raw
     * output straight into `store()` with no round-trip of its own, so the
     * store is what has to do the normalising. If it ever stopped doing so,
     * `AgentAuthorizationContextFactory::forConsent()`'s call to
     * `PlatformProfile::fromArray()` would throw and this test would fail.
     */
    public function testAStoredProfileSurvivesTheRoundTripIntoAConsentContext(): void
    {
        $store = $this->store();
        $pending = new PendingAuthorization(
            $this->salesChannelId(),
            'https://agent.example/.well-known/ucp',
            (new PlatformProfile('2026-04-08', [], [], []))->toArray(),
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );

        $handle = $store->store($pending, 600);
        $found = $store->find($handle);
        self::assertNotNull($found);

        $context = (new AgentAuthorizationContextFactory())->forConsent(
            $found,
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );

        self::assertNotNull($context->platformProfile);
        self::assertSame('2026-04-08', $context->platformProfile->version);
    }
}
