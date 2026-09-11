<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\Check\BuyerSignatureCheck;
use MerchantQuoteAgentPlugin\Protocol\Check\BuyerTermsCheck;
use MerchantQuoteAgentPlugin\Protocol\Check\ChainLengthCheck;
use MerchantQuoteAgentPlugin\Protocol\Check\DuplicateSequenceCheck;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Check\SessionIdCheck;
use MerchantQuoteAgentPlugin\Protocol\Check\TimestampFormatCheck;
use MerchantQuoteAgentPlugin\Protocol\Check\TimestampMonotonicityCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteHandler;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteMessage;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActFactory;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnBearerJwt;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnMessagesController;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActAppender;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActConformance;
use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;

/**
 * End-to-end integration coverage for inbound A2CN acts:
 * - Quote request over UCP stamps the derived session id
 * - Inbound act posted over /a2cn/sessions/{id}/messages writes to chain
 * - Replay of identical act is accepted with 200 and no new writes
 * - Out-of-order sequence conflict returns 409
 * - Foreign buyer DID on pinned chain returns 403
 * - Servicing into replied emits seller act with monotonic timestamp
 */
final class A2cnInboundActTest extends IntegrationTestCase
{
    private const BUYER_1_DID = 'did:web:buyer.example';
    private const BUYER_1_KID = 'did:web:buyer.example#key-1';
    private const BUYER_2_DID = 'did:web:other-buyer.example';
    private const BUYER_2_KID = 'did:web:other-buyer.example#key-1';

    /** @var array{private: string, public: string, did: string, kid: string} */
    private array $buyer1;

    /** @var array{private: string, public: string, did: string, kid: string} */
    private array $buyer2;

    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }

        $keyStore = static::getContainer()->get(A2cnKeyStore::class);
        self::assertInstanceOf(A2cnKeyStore::class, $keyStore);
        $keyStore->generateIfAbsent();

        $fetcher = static::getContainer()->get(AgentProfileFetcherInterface::class);
        if (method_exists($fetcher, 'setProfile')) {
            $fetcher->setProfile(new PlatformProfile(
                version: '2026-04-08',
                services: [],
                capabilities: [],
                paymentHandlers: [],
            ));
        }

        $this->buyer1 = ['did' => self::BUYER_1_DID, 'kid' => self::BUYER_1_KID] + self::ecKeyPair();
        $this->buyer2 = ['did' => self::BUYER_2_DID, 'kid' => self::BUYER_2_KID] + self::ecKeyPair();

        $this->installDidWebResolverDouble();
    }

    public function testInboundActRoundTrip(): void
    {
        $baseUri = BuyerQuoteFixture::storefrontBaseUri(static::getContainer());
        $salesChannelId = BuyerQuoteFixture::storefrontSalesChannelId(static::getContainer());
        $customerId = BuyerQuoteFixture::anyQuoteCapableCustomerId(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        // 1. Request quote over UCP route
        $ucpToken = $this->issueToken($customerId);
        $quoteResponse = KernelLifecycleManager::getKernel()->handle(Request::create(
            $baseUri . '/ucp/quotes',
            'POST',
            server: [
                'HTTP_UCP_AGENT' => 'profile="' . $baseUri . '/.well-known/ucp"',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $ucpToken,
                'HTTP_IDEMPOTENCY_KEY' => 'idem-' . bin2hex(random_bytes(8)),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: (string) json_encode([
                'line_items' => [['product_id' => $productId, 'quantity' => 1]],
            ]),
        ));
        self::assertSame(201, $quoteResponse->getStatusCode(), (string) $quoteResponse->getContent());
        $quotePayload = json_decode((string) $quoteResponse->getContent(), associative: true);
        self::assertIsArray($quotePayload);
        $quoteId = $quotePayload['id'];
        self::assertIsString($quoteId);
        $sessionId = $quotePayload['a2cn_session_id'] ?? null;
        self::assertIsString($sessionId);

        $identities = static::getContainer()->get(A2cnIdentityResolver::class);
        self::assertInstanceOf(A2cnIdentityResolver::class, $identities);
        $sellerIdentity = $identities->forSalesChannel($salesChannelId);
        self::assertNotNull($sellerIdentity);
        $sellerDid = $sellerIdentity->did;

        $snapshot = static::gateway()->fetchSnapshot($quoteId);

        // 2. Post sequence 1 act from Buyer 1
        $act1 = $this->signedBuyerAct($sessionId, $snapshot, 1, $this->buyer1, '2026-09-10T12:00:00Z');
        $jwt1 = $this->bearerJwt($this->buyer1, $sellerDid);

        $res1 = $this->postMessage($baseUri, $sessionId, $jwt1, $act1);
        self::assertSame(201, $res1->getStatusCode());
        self::assertSame('application/a2cn+json', $res1->headers->get('Content-Type'));
        $body1 = json_decode((string) $res1->getContent(), associative: true);
        self::assertIsArray($body1);
        self::assertSame($sessionId, $body1['session_id']);
        self::assertSame($act1['message_id'], $body1['accepted']['message_id']);
        self::assertSame(1, $body1['accepted']['sequence_number']);

        $after1 = static::gateway()->fetchSnapshot($quoteId);
        self::assertArrayHasKey(ActKey::for(1, ActRole::Buyer), $after1->lifecycle->customFields);

        // 3. Replay of identical act returns 200 with no changes
        $resReplay = $this->postMessage($baseUri, $sessionId, $jwt1, $act1);
        self::assertSame(200, $resReplay->getStatusCode());
        $afterReplay = static::gateway()->fetchSnapshot($quoteId);
        self::assertSame($after1->lifecycle->customFields, $afterReplay->lifecycle->customFields);

        // 4. Sequence 5 conflict returns 409 sequence_conflict
        $act5 = $this->signedBuyerAct($sessionId, $snapshot, 5, $this->buyer1, '2026-09-10T12:05:00Z');
        $res5 = $this->postMessage($baseUri, $sessionId, $jwt1, $act5);
        self::assertSame(409, $res5->getStatusCode());
        $body5 = json_decode((string) $res5->getContent(), associative: true);
        self::assertIsArray($body5);
        self::assertSame('sequence_conflict', $body5['status']);

        // 5. Foreign buyer DID on pinned session returns 403 sender_did_not_party
        $actForeign = $this->signedBuyerAct($sessionId, $snapshot, 2, $this->buyer2, '2026-09-10T12:02:00Z');
        $jwtForeign = $this->bearerJwt($this->buyer2, $sellerDid);
        $resForeign = $this->postMessage($baseUri, $sessionId, $jwtForeign, $actForeign);
        self::assertSame(403, $resForeign->getStatusCode());
        $bodyForeign = json_decode((string) $resForeign->getContent(), associative: true);
        self::assertIsArray($bodyForeign);
        self::assertSame('sender_did_not_party', $bodyForeign['status']);

        // 6. Servicing into replied emits seller act at sequence 2 with monotonic timestamp
        static::gateway()->updateQuote($quoteId, new QuoteUpdate(expiresAt: new \DateTimeImmutable('+14 days')));
        static::gateway()->transition($quoteId, QuoteTransition::Sent);

        $handler = static::getContainer()->get(ObserveQuoteHandler::class);
        self::assertInstanceOf(ObserveQuoteHandler::class, $handler);
        $handler(new ObserveQuoteMessage($quoteId));

        $afterReplied = static::gateway()->fetchSnapshot($quoteId);
        $sellerAct = $afterReplied->lifecycle->customFields[ActKey::for(2, ActRole::Seller)] ?? null;
        self::assertIsArray($sellerAct);
        self::assertSame(2, $sellerAct['sequence_number']);
        self::assertGreaterThanOrEqual($act1['timestamp'], $sellerAct['timestamp']);
    }

    /** @param array<string, mixed> $act */
    private function postMessage(string $baseUri, string $sessionId, string $jwt, array $act): Response
    {
        return KernelLifecycleManager::getKernel()->handle(Request::create(
            $baseUri . '/a2cn/sessions/' . $sessionId . '/messages',
            'POST',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: (string) json_encode($act),
        ));
    }

    private function installDidWebResolverDouble(): void
    {
        $b1Pem = $this->buyer1['public'];
        $b2Pem = $this->buyer2['public'];

        $resolver = new class($b1Pem, $b2Pem) extends DidWebResolver {
            public function __construct(
                private readonly string $b1,
                private readonly string $b2,
            ) {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return match ($verificationMethod) {
                    'did:web:buyer.example#key-1' => $this->b1,
                    'did:web:other-buyer.example#key-1' => $this->b2,
                    default => null,
                };
            }
        };

        $container = static::getContainer();
        $container->set(DidWebResolver::class, $resolver);

        $protocolHash = $container->get(ProtocolHash::class);
        self::assertInstanceOf(ProtocolHash::class, $protocolHash);
        $verifier = new ActVerifier($resolver, $protocolHash);
        $container->set(ActVerifier::class, $verifier);

        $bearer = new A2cnBearerJwt($resolver);
        $container->set(A2cnBearerJwt::class, $bearer);

        $conformance = new InboundActConformance($verifier);
        $container->set(InboundActConformance::class, $conformance);

        $appender = new InboundActAppender(
            $conformance,
            $container->get(ChainMirror::class),
            $container->get('logger'),
            static::gateway(),
        );
        $container->set(InboundActAppender::class, $appender);

        $controller = new A2cnMessagesController(
            $bearer,
            $container->get(SessionQuoteLocator::class),
            $container->get(QuoteTerminalStateReader::class),
            $container->get(A2cnIdentityResolver::class),
            $container->get(QuoteServicingLock::class),
            $appender,
        );
        $container->set(A2cnMessagesController::class, $controller);

        $inspector = new EvidenceInspector([
            $container->get(SessionIdCheck::class),
            $container->get(DuplicateSequenceCheck::class),
            $container->get(ChainLengthCheck::class),
            $container->get(TimestampFormatCheck::class),
            $container->get(TimestampMonotonicityCheck::class),
            $container->get(BuyerTermsCheck::class),
            new BuyerSignatureCheck($verifier),
        ]);
        $container->set(EvidenceInspector::class, $inspector);

        $emitter = new SellerActEmitter(
            $container->get(SellerActFactory::class),
            $inspector,
            $container->get(ChainMirror::class),
            $container->get('logger'),
            static::gateway(),
        );
        $container->set(SellerActEmitter::class, $emitter);

        $container->set(
            ObserveQuoteHandler::class,
            new ObserveQuoteHandler(
                $container->get(QuoteServicingLock::class),
                $container->get('logger'),
                static::gateway(),
                $emitter,
            ),
        );
    }

    private function issueToken(?string $customerId = null): string
    {
        $store = static::getContainer()->get('Swag\\AgenticCommerce\\Ucp\\Identity\\DoctrineDbalUcpOAuthStore');
        self::assertIsObject($store);
        self::assertTrue(method_exists($store, 'issueTokenSet'), 'Agentic Commerce OAuth store lost issueTokenSet()');

        $set = $store->issueTokenSet(
            BuyerQuoteFixture::storefrontSalesChannelId(static::getContainer()),
            'integration-test-client',
            $customerId ?? BuyerQuoteFixture::anyQuoteCapableCustomerId(static::getContainer()),
            'dev.ucp.shopping.cart:manage',
        );

        self::assertIsObject($set);
        self::assertIsString($set->accessToken);

        return $set->accessToken;
    }

    /** @param array{private: string, did: string, kid: string} $buyer */
    private function bearerJwt(array $buyer, string $audienceDid): string
    {
        $header = Base64Url::encode((string) json_encode([
            'alg' => 'ES256',
            'typ' => 'JWT',
            'kid' => $buyer['kid'],
        ]));
        $claims = Base64Url::encode((string) json_encode([
            'iss' => $buyer['did'],
            'aud' => $audienceDid,
            'exp' => (new \DateTimeImmutable('+1 hour'))->getTimestamp(),
            'jti' => 'jwt-' . bin2hex(random_bytes(8)),
        ]));

        $signingInput = $header . '.' . $claims;
        $der = '';
        openssl_sign($signingInput, $der, $buyer['private'], \OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . Base64Url::encode(Es256Signature::toRaw($der));
    }

    /**
     * @param array{private: string, did: string, kid: string} $buyer
     * @return array<string, mixed>
     */
    private function signedBuyerAct(
        string $sessionId,
        QuoteSnapshot $snapshot,
        int $sequenceNumber,
        array $buyer,
        string $timestamp,
    ): array {
        $lineItems = array_map(static fn(QuoteLineSnapshot $line): array => [
            'id' => $line->identity->lineItemId,
            'description' => $line->identity->label ?? $line->identity->lineItemId,
            'quantity' => $line->quantity,
            'unit' => 'piece',
            'unit_price' => 1,
            'total' => max($line->quantity, 1),
        ], $snapshot->content->lines);

        $wire = [
            'message_type' => 'offer',
            'message_id' => $sessionId . ':' . $sequenceNumber,
            'session_id' => $sessionId,
            'round_number' => 1,
            'sequence_number' => $sequenceNumber,
            'sender_did' => $buyer['did'],
            'sender_agent_id' => 'buyer-agent',
            'sender_verification_method' => $buyer['kid'],
            'timestamp' => $timestamp,
            'terms' => [
                'total_value' => array_sum(array_column($lineItems, 'total')),
                'currency' => $snapshot->identity->currencyIso,
                'line_items' => array_values($lineItems),
                'custom_terms' => ['tax_status' => 'net', 'quote_number' => $snapshot->identity->quoteNumber],
            ],
        ];

        $unsigned = Act::fromArray($wire + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        self::assertNotNull($unsigned);
        $protocolHash = static::getContainer()->get(ProtocolHash::class);
        self::assertInstanceOf(ProtocolHash::class, $protocolHash);
        $digest = $protocolHash->of(SignedView::of($unsigned));

        return $wire
        + [
            'protocol_act_hash' => $digest,
            'protocol_act_signature' => CompactJws::sign($digest, $buyer['private']),
        ];
    }

    /** @return array{private: string, public: string} */
    private static function ecKeyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($resource);
        $private = '';
        self::assertTrue(openssl_pkey_export($resource, $private));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $private, 'public' => $details['key']];
    }
}
