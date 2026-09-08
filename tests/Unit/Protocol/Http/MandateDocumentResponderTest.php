<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Http\MandateDocumentResponder;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

/**
 * The one behaviour A2cnDiscoveryControllerTest cannot isolate: whether
 * `respond()` answers 503 when MandateSigner::sign() itself discovers the
 * missing key, independently of whatever identity resolution already did.
 * `A2cnDiscoveryController::resolveIdentity()`'s own guard would hide this in
 * a controller-level test — its identity double never touches a real key
 * store — so this test drives the responder directly: a resolved identity
 * (built by hand, the way resolveIdentity() would have handed it in) plus a
 * key store whose `current()` throws.
 */
final class MandateDocumentResponderTest extends TestCase
{
    public function testAMissingKeyDuringSigningYields503NotAnUncaughtException(): void
    {
        $keys = new class extends A2cnKeyStore {
            public function __construct() {}

            public function current(): A2cnSigningKey
            {
                throw new MissingSigningKey('no key configured');
            }
        };

        $responder = new MandateDocumentResponder(
            A2cnDiscoveryControllerFixtures::settingsWithAPolicy(),
            new SellerMandateFactory(),
            new MandateSigner(new ProtocolHash(new DefaultJsonCanonicalization()), $keys),
        );

        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
        $response = $responder->respond($identity, null, new \DateTimeImmutable());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            'signing_key_missing',
            A2cnDiscoveryControllerFixtures::decode($response->getContent())['status'],
        );
    }
}
