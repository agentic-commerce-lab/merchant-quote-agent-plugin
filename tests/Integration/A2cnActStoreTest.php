<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Protocol\Store\DbalActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;

/**
 * The mirror against a real MySQL: the idempotent primary key and the JSON
 * round trip are properties of the schema, not of PHP.
 */
final class A2cnActStoreTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::requireUcpSurface();
    }

    private const QUOTE_ID = '0189d1c8f4f27c3ea0d4a5b6c7d8e9f0';

    public function testAppendingTheSameActTwiceIsANoOp(): void
    {
        $store = new DbalActStore(self::connection(self::getContainer()));
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = Act::fromArray(ProtocolFixtures::sellerAct(1, $session));
        self::assertNotNull($act);

        $store->append(new ActRecord($session, self::QUOTE_ID, 1, ActRole::Seller, $act));
        $store->append(new ActRecord($session, self::QUOTE_ID, 1, ActRole::Seller, $act));

        self::assertCount(1, $store->listBySession($session));
        self::assertSame(self::QUOTE_ID, $store->quoteIdForSession($session));
    }

    /**
     * The wire keeps two acts at one sequence on purpose — that is what the
     * role suffix on the customFields key is for — so the mirror must keep
     * both too. Keyed on (session, sequence) alone the buyer's act landed and
     * our own seller act was silently swallowed by ON DUPLICATE KEY UPDATE,
     * leaving the records endpoints serving an incomplete chain and
     * offer_chain_hash from the mirror permanently disagreeing with the same
     * hash from the wire.
     */
    public function testTwoActsAtOneSequenceWithDifferentRolesBothSurvive(): void
    {
        $store = new DbalActStore(self::connection(self::getContainer()));
        $session = SessionId::forQuote(self::QUOTE_ID);

        foreach ([ActRole::Seller, ActRole::Buyer] as $role) {
            $raw = $role === ActRole::Seller
                ? ProtocolFixtures::sellerAct(1, $session)
                : ProtocolFixtures::buyerAct(1, $session);
            $act = Act::fromArray($raw);
            self::assertNotNull($act);
            $store->append(new ActRecord($session, self::QUOTE_ID, 1, $role, $act));
        }

        $mirrored = $store->listBySession($session);
        self::assertCount(2, $mirrored);
        // Wire order: `a2cn_act_0001_b` sorts before `a2cn_act_0001_s`, so the
        // buyer's act comes back first however the two were appended.
        self::assertSame(
            [ProtocolFixtures::BUYER, ProtocolFixtures::SELLER],
            array_map(static fn(Act $act): string => $act->senderDid(), $mirrored),
        );
    }

    public function testItReadsActsBackInSequenceOrder(): void
    {
        $store = new DbalActStore(self::connection(self::getContainer()));
        $session = SessionId::forQuote(self::QUOTE_ID);
        foreach ([2, 1] as $sequence) {
            $act = Act::fromArray(ProtocolFixtures::sellerAct($sequence, $session));
            self::assertNotNull($act);
            $store->append(new ActRecord($session, self::QUOTE_ID, $sequence, ActRole::Seller, $act));
        }

        self::assertSame(
            [1, 2],
            array_map(static fn(Act $act): int => $act->sequenceNumber(), $store->listBySession($session)),
        );
    }

    public function testItStoresViolationsAndReceipts(): void
    {
        $store = new DbalActStore(self::connection(self::getContainer()));
        $session = SessionId::forQuote(self::QUOTE_ID);

        $store->appendViolation(
            $session,
            new ProtocolViolation('2026-09-04T10:00:00+00:00', 'duplicate_sequence', null, 'sequence 1 twice'),
        );
        $store->appendReceipt(
            $session,
            new ApprovalReceipt($session . ':hash', 'hash', 'reason', '2026-09-04T10:00:00+00:00'),
        );
        $store->appendReceipt(
            $session,
            new ApprovalReceipt($session . ':hash', 'hash', 'reason', '2026-09-04T10:00:00+00:00'),
        );

        self::assertCount(1, $store->listViolations($session));
        self::assertSame('duplicate_sequence', $store->listViolations($session)[0]->violationType);
        self::assertCount(1, $store->listReceipts($session));
    }

    public function testAnUnknownSessionIsEmptyRatherThanAnError(): void
    {
        $store = new DbalActStore(self::connection(self::getContainer()));

        self::assertSame([], $store->listBySession(SessionId::forQuote('ffffffffffffffffffffffffffffffff')));
        self::assertNull($store->quoteIdForSession('not-a-session'));
    }
}
