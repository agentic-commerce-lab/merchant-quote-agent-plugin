<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class ChainMirrorTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    /**
     * The wire deliberately preserves two acts at one sequence — that is the
     * entire reason the customFields key carries a role suffix
     * (`a2cn_act_0002_b` vs `a2cn_act_0002_s`), so a concurrent append keeps
     * both instead of one clobbering the other. The mirror has to preserve
     * both too: keyed on (session, sequence) alone the buyer's act landed and
     * OUR OWN seller act never reached our own mirror, so the records
     * endpoints served an incomplete chain and offer_chain_hash computed from
     * the mirror disagreed with the same hash computed from the wire, forever.
     * DuplicateSequenceCheck runs at gate 5, long after the mirror at gate 2,
     * so it cannot save the row.
     */
    public function testBothActsAtOneSequenceReachTheMirror(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $store = new InMemoryActStore();

        (new ChainMirror($store))->mirror(self::QUOTE_ID, ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ActKey::for(2, ActRole::Buyer) => ProtocolFixtures::buyerAct(2, $session),
            ActKey::for(2, ActRole::Seller) => ProtocolFixtures::sellerAct(2, $session),
        ]));

        $mirrored = $store->listBySession($session);
        self::assertCount(3, $mirrored, 'the seller act at sequence 2 must not clobber the buyer act');
        self::assertSame(
            [ProtocolFixtures::BUYER, ProtocolFixtures::BUYER, ProtocolFixtures::SELLER],
            array_map(static fn(Act $act): string => $act->senderDid(), $mirrored),
            'the mirror must read back in the wire chain order: sequence, then role',
        );
    }

    /**
     * Re-mirroring is free: the emitter mirrors the WHOLE known chain on every
     * observation, so the triple (session, sequence, role) has to be
     * idempotent, not merely unique.
     */
    public function testReMirroringTheSameChainAddsNothing(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $store = new InMemoryActStore();
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ActKey::for(1, ActRole::Seller) => ProtocolFixtures::sellerAct(1, $session),
        ]);

        $mirror = new ChainMirror($store);
        $mirror->mirror(self::QUOTE_ID, $chain);
        $mirror->mirror(self::QUOTE_ID, $chain);

        self::assertCount(2, $store->listBySession($session));
        self::assertSame(self::QUOTE_ID, $store->quoteIdForSession($session));
    }

    /**
     * The role is read off the wire key rather than off the act's sender DID
     * because the mirror runs BEFORE identity resolution (see
     * SellerActEmitter::run(): a buyer act on a quote we cannot yet identify
     * ourselves for must still reach our copy), so no seller DID is available
     * to compare against.
     */
    public function testAKeyWeDidNotWriteOurselvesIsTheCounterpartys(): void
    {
        self::assertSame(ActRole::Seller, ActRole::fromKey(ActKey::for(3, ActRole::Seller)));
        self::assertSame(ActRole::Buyer, ActRole::fromKey(ActKey::for(3, ActRole::Buyer)));
        self::assertSame(ActRole::Buyer, ActRole::fromKey('a2cn_act_0003_x'));
    }
}
