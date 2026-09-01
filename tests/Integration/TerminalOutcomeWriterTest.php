<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The writer against the real DAL. `terminal_state` and `terminal_at` are the
 * only two columns in the table that #19 reserved and never wrote, so nothing
 * has ever proved they exist under those names at the SQL level. These tests
 * are that proof: a wrong column name fails here as a DAL write error, not as
 * a silently dropped payload key.
 */
final class TerminalOutcomeWriterTest extends IntegrationTestCase
{
    public function testItStampsTheNewestRecordAndLeavesOlderOnesAlone(): void
    {
        $quoteId = Uuid::randomHex();
        $older = Uuid::randomHex();
        $newer = Uuid::randomHex();

        self::records()
            ->create([
                self::row($older, $quoteId, '2026-08-30 10:00:00.000'),
                self::row($newer, $quoteId, '2026-08-30 11:00:00.000'),
            ], Context::createDefaultContext());

        self::writer()->recordTerminalOutcome($quoteId, 'accepted', new \DateTimeImmutable('2026-08-31 09:30:00'));

        self::assertSame('accepted', self::terminalStateOf($newer), 'The newest record was not stamped.');
        self::assertNotNull(self::recordOf($newer)->terminalAt, 'terminalAt stayed null on the stamped record.');
        self::assertNull(self::terminalStateOf($older), 'An older record was stamped as well.');
    }

    public function testALaterOutcomeOverwritesAnEarlierOne(): void
    {
        // A quote can expire, have its expiration extended and then be
        // accepted, all against the same record: `reopen` is not a servicing
        // trigger, so no newer record is ever created. The last word is the
        // true one.
        $quoteId = Uuid::randomHex();
        $id = Uuid::randomHex();

        self::records()->create([self::row($id, $quoteId, '2026-08-30 10:00:00.000')], Context::createDefaultContext());

        self::writer()->recordTerminalOutcome($quoteId, 'expired', new \DateTimeImmutable('2026-08-31 09:00:00'));
        self::writer()->recordTerminalOutcome($quoteId, 'accepted', new \DateTimeImmutable('2026-08-31 10:00:00'));

        self::assertSame('accepted', self::terminalStateOf($id));
    }

    public function testAQuoteWithNoRecordIsASilentNoOp(): void
    {
        // A quote the agent never serviced. #33 is explicit that inventing a
        // record here would be wrong.
        self::writer()->recordTerminalOutcome(Uuid::randomHex(), 'declined', new \DateTimeImmutable());

        $this->addToAssertionCount(1);
    }

    public function testEachOfTheFiveTerminalStatesFitsTheColumn(): void
    {
        // terminal_state is VARCHAR(64) with maxLength: 64 on the entity. A
        // state name that did not fit would fail the write, not truncate.
        foreach (['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'] as $state) {
            $quoteId = Uuid::randomHex();
            $id = Uuid::randomHex();

            self::records()
                ->create([self::row($id, $quoteId, '2026-08-30 10:00:00.000')], Context::createDefaultContext());
            self::writer()->recordTerminalOutcome($quoteId, $state, new \DateTimeImmutable());

            self::assertSame($state, self::terminalStateOf($id));
        }
    }

    private static function writer(): TerminalOutcomeWriter
    {
        return new TerminalOutcomeWriter(self::records());
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    private static function recordOf(string $id): QuoteDecisionRecord
    {
        $record = self::records()
            ->search(new Criteria([$id]), Context::createDefaultContext())
            ->first();
        self::assertInstanceOf(QuoteDecisionRecord::class, $record);

        return $record;
    }

    private static function terminalStateOf(string $id): ?string
    {
        return self::recordOf($id)->terminalState;
    }

    /**
     * `createdAt` is set explicitly so the two fixture rows have a
     * deterministic order. CreatedAtFieldSerializer only defaults it to now
     * when the payload omits it.
     *
     * @return array<string, mixed>
     */
    private static function row(string $id, string $quoteId, string $createdAt): array
    {
        return [
            'id' => $id,
            'quoteId' => $quoteId,
            'outcome' => 'offered',
            'createdAt' => $createdAt,
        ];
    }
}
