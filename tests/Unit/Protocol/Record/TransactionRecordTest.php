<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParty;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordSubject;
use MerchantQuoteAgentPlugin\Protocol\Record\TransactionRecord;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class TransactionRecordTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';

    public function testItRecordsTheAgreedTermsFromTheFinalOffer(): void
    {
        $record = self::build();

        self::assertSame('a2cn_transaction_record', $record['record_type']);
        self::assertSame('0.1', $record['record_version']);
        self::assertSame(self::SESSION, $record['session_id']);
        self::assertSame(760000, $record['agreed_terms']['total_value'] ?? null);
        self::assertSame('Q-1001', $record['subject']);
        self::assertSame('quote:Q-1001', $record['subject_reference']);
    }

    public function testItSummarizesTheNegotiation(): void
    {
        $record = self::build();

        self::assertSame(2, $record['negotiation_summary']['total_rounds']);
        self::assertSame(3, $record['negotiation_summary']['total_messages']);
        self::assertSame(ProtocolFixtures::SELLER, $record['final_offer']['sender_did']);
    }

    public function testTheRecordHashCoversTheRecordWithTheHashBlanked(): void
    {
        $record = self::build();
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertIsString($record['record_hash']);
        self::assertNotSame('', $record['record_hash']);

        // Recompute the way a third party would: blank the field, hash the rest.
        $blanked = $record;
        $blanked['record_hash'] = '';
        self::assertSame($hash->of($blanked), $record['record_hash']);
    }

    public function testItNamesTheOrderTheQuoteBecame(): void
    {
        $record = $this->record(orderReference: 'order:10014');

        self::assertSame('order:10014', $record['order_reference']);
    }

    public function testAnUnconvertedSessionCarriesNoOrderKeyAtAll(): void
    {
        // Absent, never null: an empty string is a claim, and there is
        // nothing to claim.
        self::assertArrayNotHasKey('order_reference', $this->record());
    }

    public function testTheRecordHashCoversTheOrderReference(): void
    {
        $with = $this->record(orderReference: 'order:10014');
        $without = $this->record();

        self::assertNotSame($with['record_hash'], $without['record_hash']);
    }

    public function testTheSubjectReferenceStillNamesOnlyTheQuote(): void
    {
        self::assertSame('quote:Q-1001', $this->record(orderReference: 'order:10014')['subject_reference']);
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        return self::record();
    }

    /** @return array<string, mixed> */
    private static function record(?string $orderReference = null): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $acts = [
            self::act(ProtocolFixtures::buyerAct(1, self::SESSION)),
            self::act(ProtocolFixtures::sellerAct(2, self::SESSION)),
            self::act(ProtocolFixtures::buyerAct(3, self::SESSION, 'acceptance')),
        ];

        return (new TransactionRecord($hash, new OfferChainHash($hash)))->build(
            new RecordParties(
                new RecordParty('', ProtocolFixtures::BUYER, 'buyer-agent', ProtocolFixtures::BUYER . '#key-1'),
                new RecordParty(
                    'Example Shop',
                    ProtocolFixtures::SELLER,
                    'merchant-quote-agent',
                    ProtocolFixtures::SELLER . '#key-1',
                ),
            ),
            $acts,
            $acts[2],
            new RecordSubject('goods_procurement', 'EUR', 'Q-1001', 'quote:Q-1001'),
            '2026-09-04T10:00:00+00:00',
            orderReference: $orderReference,
        );
    }

    /** @param array<string, mixed> $raw */
    private static function act(array $raw): Act
    {
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }
}
