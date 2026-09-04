<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class SellerActFactoryTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItBuildsACounterofferAtTheNextSequenceAndRound(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);

        $act = self::factory()
            ->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertSame('counteroffer', $act->messageType());
        self::assertSame(2, $act->sequenceNumber());
        self::assertSame(2, $act->roundNumber());
        self::assertSame($session, $act->sessionId());
        self::assertSame($session . ':2', $act->messageId());
        self::assertSame($session . ':1', $act->raw()['in_reply_to'] ?? null);
        self::assertSame('did:web:shop.example', $act->senderDid());
        self::assertSame('merchant-quote-agent', $act->senderAgentId());
    }

    public function testItCarriesTheQuoteExpiryAndTheDerivedTerms(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);

        $act = self::factory()
            ->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertSame('2026-09-18T10:00:00+00:00', $act->expiresAt());
        self::assertSame(760000, $act->terms()['total_value'] ?? null);
    }

    public function testTheProofCoversExactlyTheSignedView(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        $act = self::factory()
            ->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertSame($hash->of(SignedView::of($act)), $act->hash());
        self::assertSame($act->hash(), CompactJws::verify($act->signature(), TestActSigner::publicKeyPem()));
    }

    public function testItProducesAnActItsOwnReaderAccepts(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);

        $act = self::factory()
            ->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertNotNull(Act::fromArray($act->raw()));
        self::assertArrayNotHasKey('protocol_version', $act->raw(), 'protocol_version is signed but never on the wire');
    }

    public function testItComparesTermsByTheirSignedBytes(): void
    {
        $factory = self::factory();
        $terms = $factory->terms(ProtocolFixtures::snapshot(self::QUOTE_ID));

        self::assertTrue($factory->termsUnchanged($terms, $terms));
        self::assertFalse($factory->termsUnchanged(ProtocolFixtures::terms(quantity: 5), $terms));
    }

    private static function factory(): \MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActFactory
    {
        return TestActSigner::factory();
    }

    private static function identity(): A2cnIdentity
    {
        return A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
    }
}
