<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnRecordsController;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Protocol\Http\RecordPartiesResolver;
use MerchantQuoteAgentPlugin\Protocol\Http\RecordResponder;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditLog;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Protocol\Record\TransactionRecord;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class A2cnRecordsControllerTest extends TestCase
{
    private const QUOTE_ID = RecordsControllerFixtures::QUOTE_ID;

    public function testItServesTheMirroredActs(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $store = RecordsControllerFixtures::storeWith([1 => 'offer', 2 => 'counteroffer']);

        $response = self::controller($store, RecordsControllerFixtures::live())->acts($session);

        self::assertSame(200, $response->getStatusCode());
        $body = RecordsControllerFixtures::decode($response->getContent());
        self::assertSame($session, $body['session_id']);
        self::assertCount(2, $body['acts']);
        // Symfony's ResponseHeaderBag always appends ", private" to a
        // Cache-Control value that does not itself carry public/private/
        // s-maxage (see ResponseHeaderBag::computeCacheControlValue()) — a
        // documented, deliberate default, and a strict superset of "no-store"
        // here, never a weaker one. Asserting containment rather than exact
        // equality is what survives that framework behaviour instead of
        // fighting it.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testAnUnknownSessionIsNotFound(): void
    {
        $response = self::controller(new InMemoryActStore(), RecordsControllerFixtures::live())->acts('not-a-session');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testALiveSessionHasNoRecordYet(): void
    {
        $store = RecordsControllerFixtures::storeWith([1 => 'offer', 2 => 'counteroffer']);

        $response = self::controller($store, RecordsControllerFixtures::live())
            ->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('session_live', RecordsControllerFixtures::decode($response->getContent())['status']);
    }

    public function testATerminalSessionYieldsTheAuditLog(): void
    {
        $store = RecordsControllerFixtures::storeWith([1 => 'offer', 2 => 'counteroffer']);

        $response = self::controller($store, RecordsControllerFixtures::terminal())
            ->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('a2cn_audit_log', RecordsControllerFixtures::decode($response->getContent())['log_type']);
    }

    public function testAnAcceptedSessionYieldsTheTransactionRecord(): void
    {
        $store = RecordsControllerFixtures::storeWith([1 => 'offer', 2 => 'counteroffer', 3 => 'acceptance']);
        $acceptance = $store->listBySession(SessionId::forQuote(self::QUOTE_ID))[2];

        $response = self::controller($store, RecordsControllerFixtures::accepted($acceptance))
            ->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'a2cn_transaction_record',
            RecordsControllerFixtures::decode($response->getContent())['record_type'],
        );
    }

    public function testAnAcceptanceWithoutAnOfferIsReportedAsAViolation(): void
    {
        $store = RecordsControllerFixtures::storeWith([1 => 'acceptance']);
        $acceptance = $store->listBySession(SessionId::forQuote(self::QUOTE_ID))[0];

        $response = self::controller($store, RecordsControllerFixtures::accepted($acceptance))
            ->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'accepted_without_offer',
            RecordsControllerFixtures::decode($response->getContent())['reason'],
        );
    }

    public function testAnUnreachableQuoteIsABadGatewayNotAServerError(): void
    {
        $store = RecordsControllerFixtures::storeWith([1 => 'offer']);

        $response = self::controller($store, RecordsControllerFixtures::throwingReader())
            ->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(
            'quote_state_unavailable',
            RecordsControllerFixtures::decode($response->getContent())['status'],
        );
    }

    /**
     * The reader returns null for "the quote genuinely does not exist" —
     * distinct from a gateway failure (tested above), which it throws
     * instead. Both collapse to 404/502 respectively at the controller.
     */
    public function testARecordForAQuoteThatNoLongerExistsIsNotFound(): void
    {
        $store = RecordsControllerFixtures::storeWith([1 => 'offer']);

        $response = self::controller($store, null)->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRouteAttributesDeclareBothCanonicalAndLegacyPaths(): void
    {
        $class = new \ReflectionClass(A2cnRecordsController::class);

        /** @var list<\ReflectionAttribute<Route>> $actsRoutes */
        $actsRoutes = $class->getMethod('acts')->getAttributes(Route::class);
        self::assertCount(2, $actsRoutes);
        $actsPaths = array_map(static fn(\ReflectionAttribute $a): string => $a->newInstance()->getPath(), $actsRoutes);
        $actsNames = array_map(
            static fn(\ReflectionAttribute $a): ?string => $a->newInstance()->getName(),
            $actsRoutes,
        );
        $actsMethods = array_map(
            static fn(\ReflectionAttribute $a): array => $a->newInstance()->getMethods(),
            $actsRoutes,
        );
        self::assertContains('/a2cn/sessions/{sessionId}/messages', $actsPaths);
        self::assertContains('/a2cn/sessions/{sessionId}/acts', $actsPaths);
        self::assertContains('frontend.merchant_quote_agent.a2cn.messages.get', $actsNames);
        self::assertContains('frontend.merchant_quote_agent.a2cn.acts', $actsNames);
        self::assertSame([['GET'], ['GET']], $actsMethods);

        /** @var list<\ReflectionAttribute<Route>> $recordRoutes */
        $recordRoutes = $class->getMethod('record')->getAttributes(Route::class);
        self::assertCount(2, $recordRoutes);
        $recordPaths = array_map(
            static fn(\ReflectionAttribute $a): string => $a->newInstance()->getPath(),
            $recordRoutes,
        );
        $recordNames = array_map(
            static fn(\ReflectionAttribute $a): ?string => $a->newInstance()->getName(),
            $recordRoutes,
        );
        $recordMethods = array_map(
            static fn(\ReflectionAttribute $a): array => $a->newInstance()->getMethods(),
            $recordRoutes,
        );
        self::assertContains('/a2cn/sessions/{sessionId}/record', $recordPaths);
        self::assertContains('/a2cn/records/{sessionId}', $recordPaths);
        self::assertContains('frontend.merchant_quote_agent.a2cn.record.canonical', $recordNames);
        self::assertContains('frontend.merchant_quote_agent.a2cn.record', $recordNames);
        self::assertSame([['GET'], ['GET']], $recordMethods);
    }

    private static function controller(
        InMemoryActStore $store,
        QuoteTerminalState|QuoteTerminalStateReader|null $state,
    ): A2cnRecordsController {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $chainHash = new OfferChainHash($hash);

        $reader = $state instanceof QuoteTerminalStateReader
            ? $state
            : new class($state) extends QuoteTerminalStateReader {
                public function __construct(
                    private readonly ?QuoteTerminalState $state,
                ) {}

                public function for(string $quoteId, \DateTimeImmutable $now): ?QuoteTerminalState
                {
                    return $this->state;
                }
            };

        $responder = new RecordResponder(
            $store,
            new TransactionRecord($hash, $chainHash),
            new AuditLog($chainHash),
            new RecordPartiesResolver(TestActSigner::identities()),
        );

        return new A2cnRecordsController($store, $reader, $responder);
    }
}
