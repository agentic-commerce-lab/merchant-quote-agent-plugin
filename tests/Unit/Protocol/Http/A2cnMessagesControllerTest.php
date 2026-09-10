<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnBearerJwt;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnMessagesController;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteStateUnavailable;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActAppender;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActConformance;
use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class A2cnMessagesControllerTest extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password
     * Synthetic token value used only to match the test double bearer check.
     */
    public const TOKEN = 'valid-test-token';
    public const QUOTE_ID = '11111111111111111111111111111111';

    public ?string $locatorQuoteId = self::QUOTE_ID;
    public ?QuoteTerminalState $quoteTerminalState = null;
    public bool $throwOnQuoteState = false;
    /** @var array<string, mixed>|null */
    public ?array $customFields = [];
    public bool $hasIdentity = true;
    public ?QuoteServicingLock $locks = null;
    public ?LockInterface $heldLock = null;
    public ?RecordingQuoteGateway $gateway = null;

    protected function setUp(): void
    {
        $this->locatorQuoteId = self::QUOTE_ID;
        $this->quoteTerminalState = new QuoteTerminalState(
            state: 'replied',
            expired: false,
            quoteNumber: 'Q-1001',
            salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
            acceptance: null,
        );
        $this->throwOnQuoteState = false;
        $this->customFields = [];
        $this->hasIdentity = true;
        $this->locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'in-memory');
        $this->gateway = new RecordingQuoteGateway();
    }

    public function testItAcceptsAVerifiedActAndEchoesWhatItStored(): void
    {
        $act = ProtocolFixtures::buyerAct(1, $this->session());

        $response = $this->controller()->messages($this->session(), Request::create(
            '/a2cn/sessions/' . $this->session() . '/messages',
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
            content: (string) json_encode($act),
        ));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/a2cn+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = json_decode((string) $response->getContent(), associative: true);
        self::assertIsArray($body);
        self::assertSame($this->session(), $body['session_id']);
        self::assertSame($act['message_id'], $body['accepted']['message_id']);
        self::assertSame(1, $body['accepted']['sequence_number']);
    }

    public function testItReturns200OnReplayedAct(): void
    {
        $actRaw = ProtocolFixtures::buyerAct(1, $this->session());
        $this->customFields = [
            ActKey::SESSION_KEY => $this->session(),
            ActKey::for(1, ActRole::Buyer) => $actRaw,
        ];

        $response = $this->controller()->messages($this->session(), Request::create(
            '/a2cn/sessions/' . $this->session() . '/messages',
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
            content: (string) json_encode($actRaw),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/a2cn+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = json_decode((string) $response->getContent(), associative: true);
        self::assertIsArray($body);
        self::assertSame($this->session(), $body['session_id']);
        self::assertSame($actRaw['message_id'], $body['accepted']['message_id']);
        self::assertSame(1, $body['accepted']['sequence_number']);
        self::assertSame([], $this->gateway?->updates);
    }

    /** @param \Closure(self): Request $requestFactory */
    #[DataProvider('refusalCases')]
    public function testItRefusesInvalidRequests(
        int $expectedStatus,
        string $expectedCode,
        \Closure $requestFactory,
    ): void {
        $request = $requestFactory($this);
        $response = $this->controller()->messages($this->session(), $request);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame('application/a2cn+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = json_decode((string) $response->getContent(), associative: true);
        self::assertIsArray($body);
        self::assertSame($expectedCode, $body['status']);
    }

    /** @return iterable<string, array{0: int, 1: string, 2: \Closure(self): Request}> */
    public static function refusalCases(): iterable
    {
        yield 'no Authorization header' => [
            401,
            'invalid_jwt',
            static fn(self $test): Request => Request::create(
                '/a2cn/sessions/' . $test->session() . '/messages',
                'POST',
                content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
            ),
        ];

        yield 'token verifies, body is not JSON' => [
            400,
            'invalid_act',
            static fn(self $test): Request => Request::create(
                '/a2cn/sessions/' . $test->session() . '/messages',
                'POST',
                server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                content: 'not a json body',
            ),
        ];

        yield 'body is JSON but Act::fromArray() returns null' => [
            400,
            'invalid_act',
            static fn(self $test): Request => Request::create(
                '/a2cn/sessions/' . $test->session() . '/messages',
                'POST',
                server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                content: (string) json_encode(['not_an_act' => true]),
            ),
        ];

        yield 'session resolves to no quote' => [
            404,
            'not_found',
            static function (self $test): Request {
                $test->locatorQuoteId = null;

                return Request::create(
                    '/a2cn/sessions/' . $test->session() . '/messages',
                    'POST',
                    server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                    content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
                );
            },
        ];

        yield 'QuoteStateUnavailable from the reader' => [
            502,
            'quote_state_unavailable',
            static function (self $test): Request {
                $test->throwOnQuoteState = true;

                return Request::create(
                    '/a2cn/sessions/' . $test->session() . '/messages',
                    'POST',
                    server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                    content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
                );
            },
        ];

        yield 'session resolves to quote that no longer exists' => [
            404,
            'not_found',
            static function (self $test): Request {
                $test->quoteTerminalState = null;

                return Request::create(
                    '/a2cn/sessions/' . $test->session() . '/messages',
                    'POST',
                    server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                    content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
                );
            },
        ];

        yield 'forSalesChannel() returns null' => [
            503,
            'signing_key_missing',
            static function (self $test): Request {
                $test->hasIdentity = false;

                return Request::create(
                    '/a2cn/sessions/' . $test->session() . '/messages',
                    'POST',
                    server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                    content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
                );
            },
        ];

        yield 'the lock is already held' => [
            409,
            'session_busy',
            static function (self $test): Request {
                \assert($test->locks !== null);
                $test->heldLock = $test->locks->for(self::QUOTE_ID);
                self::assertTrue($test->heldLock->acquire());

                return Request::create(
                    '/a2cn/sessions/' . $test->session() . '/messages',
                    'POST',
                    server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                    content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
                );
            },
        ];

        yield 'the appender returns a refusal' => [
            409,
            'session_closed',
            static function (self $test): Request {
                $test->quoteTerminalState = new QuoteTerminalState(
                    state: 'declined',
                    expired: false,
                    quoteNumber: 'Q-1001',
                    salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
                    acceptance: null,
                );

                return Request::create(
                    '/a2cn/sessions/' . $test->session() . '/messages',
                    'POST',
                    server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
                    content: (string) json_encode(ProtocolFixtures::buyerAct(1, $test->session())),
                );
            },
        ];
    }

    public function session(): string
    {
        return SessionId::forQuote(self::QUOTE_ID);
    }

    private function controller(): A2cnMessagesController
    {
        $tokens = new class extends A2cnBearerJwt {
            public function __construct() {}

            public function issuerOf(string $authorizationHeader, string $audience, \DateTimeImmutable $now): ?string
            {
                return $authorizationHeader === 'Bearer ' . A2cnMessagesControllerTest::TOKEN
                    ? ProtocolFixtures::BUYER
                    : null;
            }
        };

        $locator = new class($this->locatorQuoteId) extends SessionQuoteLocator {
            public function __construct(
                private readonly ?string $quoteId,
            ) {}

            public function quoteIdFor(string $sessionId): ?string
            {
                return $this->quoteId;
            }
        };

        $state = $this->quoteTerminalState;
        $customFields = $this->customFields;
        $throwOnState = $this->throwOnQuoteState;

        $quotes = new class extends QuoteTerminalStateReader {
            public ?QuoteTerminalState $state = null;
            /** @var array<string, mixed>|null */
            public ?array $customFields = [];
            public bool $throwOnState = false;

            public function __construct() {}

            public function for(string $quoteId, \DateTimeImmutable $now): ?QuoteTerminalState
            {
                if ($this->throwOnState) {
                    throw new QuoteStateUnavailable('boom');
                }

                return $this->state;
            }

            public function customFieldsFor(string $quoteId): ?array
            {
                return $this->customFields;
            }
        };
        $quotes->state = $state;
        $quotes->customFields = $customFields;
        $quotes->throwOnState = $throwOnState;

        $identities = new class($this->hasIdentity) extends A2cnIdentityResolver {
            public function __construct(
                private readonly bool $hasIdentity,
            ) {}

            public function forSalesChannel(string $salesChannelId): ?A2cnIdentity
            {
                return $this->hasIdentity ? A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop') : null;
            }

            public function forHost(string $host, ?string $salesChannelId = null): A2cnIdentity
            {
                return A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
            }
        };

        $verifier = new class extends ActVerifier {
            public function __construct() {}

            public function reasonItDoesNotVerify(Act $act): ?string
            {
                return null;
            }
        };

        $appender = new InboundActAppender(
            conformance: new InboundActConformance($verifier),
            mirror: new ChainMirror(new InMemoryActStore()),
            logger: new NullLogger(),
            gateway: $this->gateway,
        );

        \assert($this->locks !== null);

        return new A2cnMessagesController(
            tokens: $tokens,
            locator: $locator,
            quotes: $quotes,
            identities: $identities,
            locks: $this->locks,
            appender: $appender,
        );
    }
}
