<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ActSigner;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActFactory;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Terms\TermsFactory;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

/**
 * A real ES256 key pair, generated once per process, wired into a real
 * ActSigner. The signing path is what these tests are about, so it is not
 * mocked — only the key's origin is.
 */
final class TestActSigner
{
    private static ?A2cnSigningKey $key = null;

    private function __construct() {}

    public static function key(): A2cnSigningKey
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($resource === false) {
            throw new \RuntimeException('Unable to generate a test key.');
        }
        $private = '';
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        if (!\is_array($details) || !\is_string($details['key'])) {
            throw new \RuntimeException('Unable to export the test key.');
        }

        return self::$key = new A2cnSigningKey('key-1', $private, $details['key'], '2026-09-01T00:00:00+00:00');
    }

    public static function publicKeyPem(): string
    {
        return self::key()->publicKeyPem;
    }

    public static function signer(): ActSigner
    {
        return new ActSigner(self::keyStore(), new ProtocolHash(new DefaultJsonCanonicalization()));
    }

    public static function factory(): SellerActFactory
    {
        return new SellerActFactory(
            self::signer(),
            new TermsFactory(new ProtocolHash(new DefaultJsonCanonicalization())),
            self::identities(),
        );
    }

    public static function keyStore(): A2cnKeyStore
    {
        return new class(self::key()) extends A2cnKeyStore {
            public function __construct(
                private readonly A2cnSigningKey $key,
            ) {}

            public function current(): A2cnSigningKey
            {
                return $this->key;
            }
        };
    }

    public static function identities(): A2cnIdentityResolver
    {
        return new class extends A2cnIdentityResolver {
            public function __construct() {}

            public function forSalesChannel(string $salesChannelId): ?A2cnIdentity
            {
                return A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
            }
        };
    }
}
