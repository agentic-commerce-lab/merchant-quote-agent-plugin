<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
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
    private const QUOTE_ID = '0189d1c8f4f27c3ea0d4a5b6c7d8e9f0';

    public function testAppendingTheSameActTwiceIsANoOp(): void
    {
        $store = new DbalActStore(self::connection());
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = Act::fromArray(ProtocolFixtures::sellerAct(1, $session));
        self::assertNotNull($act);

        $store->append(new ActRecord($session, self::QUOTE_ID, 1, $act));
        $store->append(new ActRecord($session, self::QUOTE_ID, 1, $act));

        self::assertCount(1, $store->listBySession($session));
        self::assertSame(self::QUOTE_ID, $store->quoteIdForSession($session));
    }

    public function testItReadsActsBackInSequenceOrder(): void
    {
        $store = new DbalActStore(self::connection());
        $session = SessionId::forQuote(self::QUOTE_ID);
        foreach ([2, 1] as $sequence) {
            $act = Act::fromArray(ProtocolFixtures::sellerAct($sequence, $session));
            self::assertNotNull($act);
            $store->append(new ActRecord($session, self::QUOTE_ID, $sequence, $act));
        }

        self::assertSame(
            [1, 2],
            array_map(static fn(Act $act): int => $act->sequenceNumber(), $store->listBySession($session)),
        );
    }

    public function testItStoresViolationsAndReceipts(): void
    {
        $store = new DbalActStore(self::connection());
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
        $store = new DbalActStore(self::connection());

        self::assertSame([], $store->listBySession(SessionId::forQuote('ffffffffffffffffffffffffffffffff')));
        self::assertNull($store->quoteIdForSession('not-a-session'));
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
