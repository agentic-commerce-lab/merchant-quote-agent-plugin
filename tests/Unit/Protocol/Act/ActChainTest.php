<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use PHPUnit\Framework\TestCase;

final class ActChainTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';
    private const SELLER = 'did:web:shop.example';

    public function testItReadsActsInLexicalKeyOrderRegardlessOfArrayOrder(): void
    {
        $chain = ActChain::read([
            ActKey::for(2, ActRole::Seller) => self::act(2, self::SELLER, 'counteroffer'),
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'offer'),
            'merchant_quote_agent_serviced' => 'unrelated',
        ]);

        self::assertSame([1, 2], array_map(static fn(Act $act): int => $act->sequenceNumber(), $chain->acts()));
        self::assertSame(self::SESSION, $chain->sessionId());
    }

    public function testItIsEmptyWithoutASession(): void
    {
        $chain = ActChain::read(['merchant_quote_agent_serviced' => 'x']);

        self::assertTrue($chain->isEmpty());
        self::assertFalse($chain->hasSession());
        self::assertNull($chain->sessionId());
        self::assertSame(1, $chain->nextSequence());
        self::assertSame(1, $chain->nextRound());
    }

    public function testItSkipsUnparseableActsWithoutLosingTheRest(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => ['message_type' => 'offer'],
            ActKey::for(2, ActRole::Seller) => self::act(2, self::SELLER, 'counteroffer'),
        ]);

        self::assertCount(1, $chain->acts());
        self::assertSame(2, $chain->acts()[0]->sequenceNumber());
    }

    public function testItCountsTheNextSequenceAndRound(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'session_init'),
            ActKey::for(2, ActRole::Buyer) => self::act(2, 'did:web:buyer.example', 'offer'),
        ]);

        self::assertSame(3, $chain->nextSequence());
        // session_init does not consume a round; the buyer's offer is round 1,
        // so our counteroffer is round 2.
        self::assertSame(2, $chain->nextRound());
        self::assertTrue($chain->hasOffer());
    }

    public function testItFindsOurLastActAndTheirFirst(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'offer'),
            ActKey::for(2, ActRole::Seller) => self::act(2, self::SELLER, 'counteroffer'),
            ActKey::for(3, ActRole::Buyer) => self::act(3, 'did:web:buyer.example', 'counteroffer'),
        ]);

        self::assertSame(2, $chain->lastSellerAct(self::SELLER)?->sequenceNumber());
        self::assertSame(1, $chain->firstForeignAct(self::SELLER)?->sequenceNumber());
        self::assertCount(2, $chain->buyerActs(self::SELLER));
        self::assertSame(3, $chain->last()?->sequenceNumber());
    }

    public function testItReportsDuplicateSequences(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'offer'),
            ActKey::for(1, ActRole::Seller) => self::act(1, self::SELLER, 'counteroffer'),
        ]);

        self::assertSame([1], $chain->duplicateSequences());
        // Both acts survive the collision — role-suffixed keys cannot overwrite.
        self::assertCount(2, $chain->acts());
    }

    public function testItStopsAtTheLengthCap(): void
    {
        $fields = [ActKey::SESSION_KEY => self::SESSION];
        for ($sequence = 1; $sequence <= (ActChain::MAX_ACTS + 5); ++$sequence) {
            $fields[ActKey::for($sequence, ActRole::Buyer)] = self::act($sequence, 'did:web:buyer.example', 'offer');
        }

        $chain = ActChain::read($fields);

        self::assertCount(ActChain::MAX_ACTS, $chain->acts());
        self::assertTrue($chain->exceedsLengthCap());
    }

    /** @return array<string, mixed> */
    private static function act(int $sequence, string $did, string $type): array
    {
        return [
            'message_type' => $type,
            'message_id' => self::SESSION . ':' . $sequence,
            'session_id' => self::SESSION,
            'round_number' => $type === 'session_init' ? null : 1,
            'sequence_number' => $sequence,
            'sender_did' => $did,
            'sender_verification_method' => $did . '#key-1',
            'timestamp' => '2026-09-04T09:00:00Z',
            'protocol_act_hash' => 'hash-' . $sequence,
            'protocol_act_signature' => 'signature-' . $sequence,
        ];
    }
}
