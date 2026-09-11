<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActAppender;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActConformance;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRefusal;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRequest;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class InboundActAppenderTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    /** @var ?array{private: string, public: string} */
    private static ?array $keyPair = null;

    public function testItWritesTheActUnderTheBuyerKeyAtTheNextSequence(): void
    {
        $gateway = new RecordingQuoteGateway();
        $appender = $this->appender($gateway);

        $result = $appender->append($this->request(sequence: 2, chainActs: [1]));

        self::assertInstanceOf(Act::class, $result);
        self::assertCount(1, $gateway->updates);
        self::assertArrayHasKey(ActKey::for(2, ActRole::Buyer), (array) $gateway->updates[0]->customFields);
        self::assertSame(
            SessionId::forQuote(self::QUOTE_ID),
            ((array) $gateway->updates[0]->customFields)[ActKey::SESSION_KEY],
        );
    }

    public function testAReplayedActWritesNothingAndIsStillAccepted(): void
    {
        $gateway = new RecordingQuoteGateway();
        $request = $this->request(sequence: 1, chainActs: [1]);
        // The act is already on the chain under the same message_id.
        $result = $this->appender($gateway)->append($request);

        self::assertInstanceOf(Act::class, $result);
        self::assertSame($request->act->messageId(), $result->messageId());
        self::assertSame([], $gateway->updates);
    }

    public function testARefusalFromAnyGateStopsTheWrite(): void
    {
        $gateway = new RecordingQuoteGateway();

        // 1. Envelope refusal (sender mismatch)
        $result = $this->appender($gateway)->append($this->request(
            sequence: 2,
            chainActs: [1],
            issuerDid: 'did:web:someone.else',
        ));
        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame(401, $result->status);
        self::assertSame('sender_did_mismatch', $result->code);
        self::assertSame([], $gateway->updates);

        // 2. Eligibility refusal (quote closed)
        $quote = new QuoteTerminalState(
            state: 'accepted',
            expired: false,
            quoteNumber: 'Q-1001',
            salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
            acceptance: null,
        );
        $result = $this->appender($gateway)->append($this->request(sequence: 2, chainActs: [1], quote: $quote));
        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame(409, $result->status);
        self::assertSame('session_closed', $result->code);
        self::assertSame([], $gateway->updates);

        // 3. Conformance refusal (sequence conflict)
        $result = $this->appender($gateway)->append($this->request(sequence: 5, chainActs: [1]));
        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame(409, $result->status);
        self::assertSame('sequence_conflict', $result->code);
        self::assertSame([], $gateway->updates);

        // 4. Backend unavailable (null gateway)
        $result = $this->appender(gateway: null)->append($this->request(sequence: 2, chainActs: [1]));
        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame(503, $result->status);
        self::assertSame('quote_backend_unavailable', $result->code);
    }

    public function testItMirrorsWhatItWrote(): void
    {
        $store = new InMemoryActStore();
        $this->appender(new RecordingQuoteGateway(), $store)->append($this->request(sequence: 2, chainActs: [1]));

        self::assertCount(1, $store->listBySession(SessionId::forQuote(self::QUOTE_ID)));
    }

    public function testTheWriteHappensBeforeTheMirror(): void
    {
        // A mirror row for an act the buyer's chain never received would be
        // our own copy claiming evidence the wire does not carry.
        $store = new InMemoryActStore();
        $result = $this->appender(
            new RecordingQuoteGateway(failOnUpdate: true),
            $store,
        )->append($this->request(sequence: 2, chainActs: [1]));

        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame(502, $result->status);
        self::assertSame([], $store->listBySession(SessionId::forQuote(self::QUOTE_ID)));
    }

    private function appender(
        ?QuoteGatewayInterface $gateway = null,
        ?InMemoryActStore $store = null,
    ): InboundActAppender {
        $verifier = new ActVerifier(
            ProtocolFixtures::resolvingTo(self::keyPair()['public']),
            new ProtocolHash(new DefaultJsonCanonicalization()),
        );

        return new InboundActAppender(
            conformance: new InboundActConformance($verifier),
            mirror: new ChainMirror($store ?? new InMemoryActStore()),
            logger: new NullLogger(),
            gateway: $gateway,
        );
    }

    /** @param list<int> $chainActs */
    private function request(
        int $sequence = 2,
        array $chainActs = [1],
        string $issuerDid = ProtocolFixtures::BUYER,
        ?QuoteTerminalState $quote = null,
    ): InboundActRequest {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = $this->signedBuyerAct($sequence, $session);

        $customFields = [ActKey::SESSION_KEY => $session];
        foreach ($chainActs as $seq) {
            $chainAct = $seq === $sequence ? $act : $this->signedBuyerAct($seq, $session);
            $customFields[ActKey::for($seq, ActRole::Buyer)] = $chainAct->raw();
        }

        return new InboundActRequest(
            act: $act,
            chain: ActChain::read($customFields),
            quoteId: self::QUOTE_ID,
            sessionId: $session,
            quote: $quote ?? new QuoteTerminalState(
                state: 'replied',
                expired: false,
                quoteNumber: 'Q-1001',
                salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
                acceptance: null,
            ),
            sellerDid: ProtocolFixtures::SELLER,
            issuerDid: $issuerDid,
        );
    }

    private function signedBuyerAct(int $sequence, string $session): Act
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $raw = ProtocolFixtures::buyerAct($sequence, $session);
        $raw['timestamp'] = \sprintf('2026-09-04T09:%02d:00Z', $sequence);
        unset($raw['protocol_act_hash'], $raw['protocol_act_signature']);
        $parsed = Act::fromArray($raw + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        self::assertNotNull($parsed);
        $digest = $hash->of(SignedView::of($parsed));

        $raw['protocol_act_hash'] = $digest;
        $raw['protocol_act_signature'] = CompactJws::sign($digest, self::keyPair()['private']);

        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }

    /** @return array{private: string, public: string} */
    private static function keyPair(): array
    {
        return self::$keyPair ??= ProtocolFixtures::keyPair();
    }
}
