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

/**
 * @mago-expect lint:too-many-methods
 * Eleven cases plus four private helpers, one per property of a record two
 * parties must be able to derive identically: the agreed terms, the summary,
 * the record hash, the order reference and its absence, the subject, the
 * generation timestamp, determinism, what the offer chain covers, and the two
 * ways an acceptance can name the offer it accepted.
 */
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

    public function testGeneratedAtIsTheAcceptanceTimestampAndNotAClockReading(): void
    {
        // The record is a pure derivation over the chain, so the counterparty
        // must be able to build the same bytes from the same acts. A wall
        // clock read here would make record_hash differ on every request and
        // between the two parties, which is the one thing it may not do.
        self::assertSame('2026-09-04T09:00:00Z', self::build()['generated_at']);
    }

    public function testTheSameChainAlwaysHashesToTheSameRecord(): void
    {
        self::assertSame(self::build()['record_hash'], self::build()['record_hash']);
    }

    public function testTheOfferChainHashCoversTheOffersAndNotTheAcceptance(): void
    {
        // "Offer chain" is what it says: the acceptance names the offer it
        // accepts rather than joining the chain of positions.
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $acts = self::acts();

        self::assertSame((new OfferChainHash($hash))->of([$acts[0], $acts[1]]), self::build()['offer_chain_hash']);
    }

    public function testTheFinalAcceptanceNamesItsRoundSequenceAndTheOfferItAccepts(): void
    {
        $acceptance = self::build()['final_acceptance'];

        self::assertSame(1, $acceptance['round_number']);
        self::assertSame(3, $acceptance['sequence_number']);
        self::assertSame(self::SESSION . ':2', $acceptance['accepted_offer_id']);
    }

    public function testAnAcceptanceThatNamesItsOwnOfferIsBelieved(): void
    {
        // A2CN's acceptance envelope carries accepted_offer_id itself; when it
        // does, the record repeats what the buyer signed rather than what we
        // inferred from the chain.
        $acts = self::acts();
        $raw = ProtocolFixtures::buyerAct(3, self::SESSION, 'acceptance');
        $raw['accepted_offer_id'] = 'their-own-offer-id';
        $acts[2] = self::act($raw);

        self::assertSame('their-own-offer-id', self::recordFor($acts)['final_acceptance']['accepted_offer_id']);
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        return self::record();
    }

    /** @return list<Act> */
    private static function acts(): array
    {
        return [
            self::act(ProtocolFixtures::buyerAct(1, self::SESSION)),
            self::act(ProtocolFixtures::sellerAct(2, self::SESSION)),
            self::act(ProtocolFixtures::buyerAct(3, self::SESSION, 'acceptance')),
        ];
    }

    /** @return array<string, mixed> */
    private static function record(?string $orderReference = null): array
    {
        return self::recordFor(self::acts(), $orderReference);
    }

    /**
     * @param list<Act> $acts
     *
     * @return array<string, mixed>
     */
    private static function recordFor(array $acts, ?string $orderReference = null): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        return (new TransactionRecord($hash, new OfferChainHash($hash)))->build(
            new RecordParties(
                new RecordParty('', ProtocolFixtures::BUYER, 'buyer-agent', ProtocolFixtures::BUYER . '#key-1', ''),
                new RecordParty(
                    'Example Shop',
                    ProtocolFixtures::SELLER,
                    'merchant-quote-agent',
                    ProtocolFixtures::SELLER . '#key-1',
                    'declared',
                ),
            ),
            $acts,
            $acts[2],
            new RecordSubject('goods_procurement', 'EUR', 'Q-1001', 'quote:Q-1001'),
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
