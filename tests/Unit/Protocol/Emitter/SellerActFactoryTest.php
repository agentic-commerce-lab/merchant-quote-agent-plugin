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
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame('2026-09-18T10:00:00Z', $act->expiresAt());
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

    /**
     * `ProtocolFixtures::at()` is `2026-09-04T10:00:00Z`. Every case here
     * answers the one question #112 turns on: does our own timestamp ever
     * land BEFORE the act it answers.
     *
     * @return iterable<string, array{0: ?string, 1: string}>
     */
    public static function clampCases(): iterable
    {
        yield 'an empty chain leaves our timestamp untouched' => [null, '2026-09-04T10:00:00Z'];
        yield 'a last act older than us leaves our timestamp untouched' => [
            '2026-09-04T09:00:00Z',
            '2026-09-04T10:00:00Z',
        ];
        yield 'a last act ahead of us (buyer clock skew) clamps forward to it, not before it' => [
            '2026-09-04T10:00:05Z',
            '2026-09-04T10:00:05Z',
        ];
        yield 'a last act with an unparseable timestamp leaves our timestamp untouched' => [
            'not-a-timestamp',
            '2026-09-04T10:00:00Z',
        ];
    }

    #[DataProvider('clampCases')]
    public function testItClampsItsTimestampForwardToTheChainsLastActNeverEarlier(
        ?string $lastActTimestamp,
        string $expectedTimestamp,
    ): void {
        $act = self::factory()
            ->build(
                ProtocolFixtures::snapshot(self::QUOTE_ID),
                self::chainWithLastActTimestamp($lastActTimestamp),
                self::identity(),
                ProtocolFixtures::at(),
            );

        self::assertSame($expectedTimestamp, $act->timestamp());
    }

    private static function chainWithLastActTimestamp(?string $timestamp): ActChain
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        if ($timestamp === null) {
            return ActChain::read([ActKey::SESSION_KEY => $session]);
        }

        $lastAct = ProtocolFixtures::buyerAct(1, $session);
        $lastAct['timestamp'] = $timestamp;

        return ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => $lastAct,
        ]);
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
