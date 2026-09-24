<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
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
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionStatus;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActFactory;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Security\PublicSigningKey;

/**
 * The proof unit tests cannot give: a seller act emitted by the REAL
 * `SellerActEmitter` wiring — real signing key, real terms factory, real
 * identity resolver, real `DbalActStore` mirror over a real MySQL, real
 * `QuoteGatewayInterface` writes against a real quote — verifies against the
 * DID document this installation actually serves.
 *
 * One substitution, no more: the `DidWebResolver` behind `BuyerSignatureCheck`'s
 * `ActVerifier` is replaced with a double that returns a locally generated
 * buyer key's public PEM directly, exactly the way `BuyerSignatureCheckTest`
 * already does at the unit level. There is no real, network-reachable
 * `did:web` host for a buyer that exists only inside this test, so this is
 * the one seam that cannot be exercised over a real network fetch without
 * inventing a second live counterparty shop. Every other collaborator is
 * either fetched straight from
 * the real container (Shopware's test container makes every service
 * resolvable, private or not — see `IntegrationTestCase::commercialService()`)
 * or is one of this suite's own stateless checks constructed fresh.
 *
 * The quote fixture is picked with an explicit "no existing a2cn_session"
 * filter: Task 17 already wired `SellerActEmitter` behind a live trigger, so a
 * "replied" quote in this shop could already carry a real negotiation's act
 * chain, and this test must not graft its own sequence-1 buyer act onto one.
 */
final class A2cnEmissionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::requireUcpSurface();
    }

    public function testAnEmittedActLandsWithoutDisturbingSiblingKeys(): void
    {
        $gateway = static::gateway();
        $arranged = $this->arrangeRepliedQuoteWithBuyerAct();
        $quoteId = $arranged['quoteId'];

        $snapshot = $gateway->fetchSnapshot($quoteId);
        $emitter = self::emitter($arranged['buyerPublicKeyPem']);

        $outcome = $emitter->observe($snapshot, new \DateTimeImmutable());

        self::assertSame(EmissionStatus::Emitted, $outcome->status);
        self::assertNotNull($outcome->act);
        self::assertSame(2, $outcome->act->sequenceNumber());

        $after = $gateway->fetchSnapshot($quoteId);
        $fields = $after->lifecycle->customFields;

        self::assertArrayHasKey(ActKey::for(2, ActRole::Seller), $fields, 'the seller act never landed on the quote');

        $mirroredBuyer = Act::fromArray($fields[ActKey::for(1, ActRole::Buyer)] ?? []);
        self::assertNotNull($mirroredBuyer, 'the buyer act did not survive the seller emission');
        self::assertSame($arranged['buyerAct']['protocol_act_hash'], $mirroredBuyer->hash());

        self::assertSame(
            $arranged['fingerprint'],
            $fields[ServicingFingerprint::MARKER_KEY] ?? null,
            'the servicing fingerprint was disturbed by the seller emission',
        );

        $baseline = QuoteBaseline::read($after);
        self::assertNotNull($baseline, 'the negotiation baseline was disturbed by the seller emission');
        self::assertEqualsWithDelta($arranged['snapshotTotalNet'], $baseline->totalNet, 0.01);
        self::assertSame(
            array_map(static fn(QuoteLineSnapshot $line): string => $line->identity->lineItemId, $arranged['lines']),
            array_map(static fn($line) => $line->lineItemId(), $baseline->lines),
        );

        $store = static::getContainer()->get(ActStoreInterface::class);
        self::assertInstanceOf(ActStoreInterface::class, $store);
        self::assertCount(2, $store->listBySession($arranged['sessionId']), 'the mirror does not hold both acts');

        // A second observation over the now-unchanged snapshot must write nothing.
        $second = $emitter->observe($after, new \DateTimeImmutable());
        self::assertSame(EmissionStatus::Unchanged, $second->status);

        $afterAgain = $gateway->fetchSnapshot($quoteId);
        self::assertSame($after->lifecycle->customFields, $afterAgain->lifecycle->customFields);
    }

    public function testTheEmittedActVerifiesAgainstThePublishedDidDocument(): void
    {
        $gateway = static::gateway();
        $arranged = $this->arrangeRepliedQuoteWithBuyerAct();
        $quoteId = $arranged['quoteId'];

        $snapshot = $gateway->fetchSnapshot($quoteId);
        $emitter = self::emitter($arranged['buyerPublicKeyPem']);

        $outcome = $emitter->observe($snapshot, new \DateTimeImmutable());
        self::assertSame(EmissionStatus::Emitted, $outcome->status);
        $act = $outcome->act;
        self::assertNotNull($act);

        // Fetched IN PROCESS through the real kernel, on the domain
        // SalesChannelHostReader itself resolved for this quote's sales
        // channel — not just "a" storefront domain: a sales channel can carry
        // more than one active domain, and BuyerQuoteFixture's own domain pick
        // (ordered by URL) is not guaranteed to be the same row
        // A2cnIdentityResolver used (ordered by domain id) to build this DID.
        $baseUri = self::domainUrlFor($snapshot->identity->salesChannelId);
        $response = KernelLifecycleManager::getKernel()->handle(Request::create($baseUri . '/.well-known/did.json'));
        self::assertSame(200, $response->getStatusCode());

        $document = json_decode((string) $response->getContent(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        // The document must describe the SAME DID the act is signed under —
        // A2cnDiscoveryController now resolves identity through
        // $request->getHttpHost() (keeps a non-default port, omits the
        // default one), matching SalesChannelHostReader's own convention,
        // which is what built this act's identity in the first place. A
        // conformant counterparty resolves the act's
        // sender_verification_method to this exact document and rejects it
        // outright if the `id` names a different DID, even when the key
        // material underneath happens to be the same.
        self::assertSame($act->senderDid(), $document['id'] ?? null);

        $jwk = $document['verificationMethod'][0]['publicKeyJwk'] ?? null;
        self::assertIsArray($jwk, 'the DID document carries no publicKeyJwk');

        $publicKey = PublicSigningKey::fromJwk($jwk);
        self::assertNotNull($publicKey->publicKeyPem);

        $expectedHash = self::protocolHash()->of(SignedView::of($act));
        self::assertSame($act->hash(), $expectedHash, 'the act carries a hash that does not cover its own signed view');

        $verifiedPayload = CompactJws::verify($act->signature(), $publicKey->publicKeyPem);
        self::assertSame(
            $expectedHash,
            $verifiedPayload,
            'the act did not verify against the key the shop itself publishes',
        );
    }

    /**
     * Arranges a "replied" quote on the storefront sales channel that carries
     * no A2CN session yet, opens one, and writes one signed buyer act plus two
     * unrelated plugin keys through the gateway — everything a real emission
     * has to leave untouched.
     *
     * @return array{
     *     quoteId: string,
     *     sessionId: string,
     *     buyerAct: array<string, mixed>,
     *     buyerPublicKeyPem: string,
     *     fingerprint: string,
     *     snapshotTotalNet: float,
     *     lines: list<QuoteLineSnapshot>,
     * }
     */
    private function arrangeRepliedQuoteWithBuyerAct(): array
    {
        $gateway = static::gateway();
        $quoteId = self::repliedQuoteIdWithoutA2cnSession();
        $sessionId = SessionId::forQuote($quoteId);
        $before = $gateway->fetchSnapshot($quoteId);

        ['private' => $buyerPrivate, 'public' => $buyerPublic] = self::buyerKeyPair();
        $buyerAct = self::signedBuyerAct($sessionId, $buyerPrivate, $before, self::protocolHash());
        $fingerprint = ServicingFingerprint::of($before);
        $baseline = QuoteBaseline::stamp($before);

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ActKey::SESSION_KEY => $sessionId,
            ActKey::for(1, ActRole::Buyer) => $buyerAct,
            ServicingFingerprint::MARKER_KEY => $fingerprint,
        ] + $baseline));

        return [
            'quoteId' => $quoteId,
            'sessionId' => $sessionId,
            'buyerAct' => $buyerAct,
            'buyerPublicKeyPem' => $buyerPublic,
            'fingerprint' => $fingerprint,
            'snapshotTotalNet' => $before->totals->totalNet,
            'lines' => $before->content->lines,
        ];
    }

    /**
     * The real `SellerActEmitter` graph, with the one substitution this class's
     * docblock explains: `BuyerSignatureCheck` resolves through a double
     * instead of a real did:web fetch, because no real buyer host exists for
     * this test to fetch from.
     */
    private static function emitter(string $buyerPublicKeyPem): SellerActEmitter
    {
        $container = static::getContainer();

        $resolver = new class($buyerPublicKeyPem) extends DidWebResolver {
            public function __construct(
                private readonly string $pem,
            ) {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return $this->pem;
            }
        };

        $inspector = new EvidenceInspector([
            new SessionIdCheck(),
            new DuplicateSequenceCheck(),
            new ChainLengthCheck(),
            new BuyerTermsCheck(),
            new BuyerSignatureCheck(new ActVerifier($resolver, self::protocolHash())),
        ]);

        $keyStore = $container->get(A2cnKeyStore::class);
        self::assertInstanceOf(A2cnKeyStore::class, $keyStore);
        // A no-op when this installation already has one, which a live,
        // activated shop does (Task 14); only generates when truly absent.
        $keyStore->generateIfAbsent();

        $factory = $container->get(SellerActFactory::class);
        self::assertInstanceOf(SellerActFactory::class, $factory);

        $mirror = $container->get(ChainMirror::class);
        self::assertInstanceOf(ChainMirror::class, $mirror);

        $logger = $container->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);

        return new SellerActEmitter(
            $factory,
            $inspector,
            $mirror,
            $container->get(\MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActJournal::class),
            static::gateway(),
        );
    }

    private static function protocolHash(): ProtocolHash
    {
        $hash = static::getContainer()->get(ProtocolHash::class);
        self::assertInstanceOf(ProtocolHash::class, $hash);

        return $hash;
    }

    /**
     * A locally signed `offer`, structurally matching the real quote's own
     * line items (id and quantity) so `BuyerTermsCheck` has nothing to reject.
     * Prices are not compared by that check, so they are filler.
     *
     * @return array<string, mixed>
     */
    private static function signedBuyerAct(
        string $sessionId,
        string $privateKeyPem,
        QuoteSnapshot $snapshot,
        ProtocolHash $hash,
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
            'message_id' => $sessionId . ':1',
            'session_id' => $sessionId,
            'round_number' => 1,
            'sequence_number' => 1,
            'sender_did' => 'did:web:buyer.example',
            'sender_agent_id' => 'buyer-agent',
            'sender_verification_method' => 'did:web:buyer.example#key-1',
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'terms' => [
                'total_value' => array_sum(array_column($lineItems, 'total')),
                'currency' => $snapshot->identity->currencyIso,
                'line_items' => array_values($lineItems),
                'custom_terms' => ['tax_status' => 'net', 'quote_number' => $snapshot->identity->quoteNumber],
            ],
        ];

        $unsigned = Act::fromArray($wire + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        self::assertNotNull($unsigned, 'the fixture buyer act could not be read back by this plugin');
        $digest = $hash->of(SignedView::of($unsigned));

        return $wire
        + [
            'protocol_act_hash' => $digest,
            'protocol_act_signature' => CompactJws::sign($digest, $privateKeyPem),
        ];
    }

    /** @return array{private: string, public: string} */
    private static function buyerKeyPair(): array
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

    /**
     * A "replied" quote, with a line item, on the storefront sales channel
     * (whose domain is what `A2cnIdentityResolver` will resolve and what
     * `/.well-known/did.json` is served from), that carries no A2CN session
     * yet — so this test opens its own rather than possibly grafting onto a
     * chain a live trigger already started.
     */
    private static function repliedQuoteIdWithoutA2cnSession(): string
    {
        $container = static::getContainer();
        $gateway = static::gateway();
        $salesChannelId = BuyerQuoteFixture::storefrontSalesChannelId($container);

        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = $container->get('quote.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', 'replied'));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('lineItems.id', null),
        ]));
        $criteria->addSorting(new FieldSorting('quoteNumber'));

        foreach ($repository->searchIds($criteria, Context::createDefaultContext())->getIds() as $id) {
            \assert(\is_string($id));
            if (($gateway->fetchSnapshot($id)->lifecycle->customFields[ActKey::SESSION_KEY] ?? null) === null) {
                return $id;
            }
        }

        throw new \RuntimeException(
            'No "replied" quote with a line item and no existing A2CN session exists on the storefront '
            . 'sales channel. Create one through the storefront or admin first.',
        );
    }

    /**
     * The exact domain row `SalesChannelHostReader::hostFor()` resolves for
     * this sales channel — same table, same filter, same `ORDER BY id ASC
     * LIMIT 1` — so the kernel fetch below lands on the domain the emitted
     * act's DID actually names, even when the channel carries more than one
     * active domain.
     */
    private static function domainUrlFor(string $salesChannelId): string
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $url = $connection->fetchOne('SELECT `url` FROM `sales_channel_domain` WHERE `sales_channel_id` = :id ORDER BY `id` ASC LIMIT 1', [
            'id' => Uuid::fromHexToBytes($salesChannelId),
        ]);
        self::assertIsString($url, 'the quote\'s sales channel has no domain to resolve a did:web authority from');

        return rtrim($url, '/');
    }
}
