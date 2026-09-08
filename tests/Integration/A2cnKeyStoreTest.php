<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\System\SystemConfig\CachedSystemConfigLoader;
use Shopware\Core\System\SystemConfig\Store\MemoizedSystemConfigStore;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

final class A2cnKeyStoreTest extends IntegrationTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Same reasoning as PluginConfigTest: DatabaseTransactionBehaviour
        // rolls the database back between tests, but a set()/delete() in one
        // test primes MemoizedSystemConfigStore and CachedSystemConfigLoader
        // with post-write values that survive the rollback and leak into a
        // later test that only reads.
        $store = static::getContainer()->get(MemoizedSystemConfigStore::class);
        self::assertInstanceOf(MemoizedSystemConfigStore::class, $store);
        $store->reset();

        $cacheInvalidator = static::getContainer()->get(CacheInvalidator::class);
        self::assertInstanceOf(CacheInvalidator::class, $cacheInvalidator);
        $cacheInvalidator->invalidate([CachedSystemConfigLoader::CACHE_TAG], true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::config()->delete(A2cnKeyStore::CONFIG_KEY);
    }

    public function testItThrowsRatherThanInventingAKey(): void
    {
        self::config()->delete(A2cnKeyStore::CONFIG_KEY);

        $this->expectException(MissingSigningKey::class);

        self::store()->current();
    }

    public function testItGeneratesOnceAndKeepsTheSameKey(): void
    {
        $store = self::store();

        $first = $store->generateIfAbsent();
        $second = $store->generateIfAbsent();

        self::assertSame($first->kid, $second->kid);
        self::assertSame($first->privateKeyPem, $store->current()->privateKeyPem);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $first->publicKeyPem);
    }

    public function testItPublishesAP256PublicJwk(): void
    {
        $store = self::store();
        $key = $store->generateIfAbsent();

        $jwk = $store->publicJwk($key);

        self::assertSame('EC', $jwk['kty']);
        self::assertSame('P-256', $jwk['crv']);
        self::assertSame($key->kid, $jwk['kid']);
        self::assertArrayNotHasKey('d', $jwk);
    }

    private static function store(): A2cnKeyStore
    {
        return new A2cnKeyStore(self::config(), new DefaultSigningKeyManager());
    }

    private static function config(): SystemConfigService
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
    }
}
