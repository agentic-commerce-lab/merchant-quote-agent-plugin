<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\EscalationResolutionWriter;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The writer against the real DAL. `resolved_at` and `resolved_state` are new
 * columns nothing else writes, so these tests are the proof they exist under
 * those names at the SQL level: a wrong column name fails here as a DAL write
 * error rather than as a silently dropped payload key.
 */
final class EscalationResolutionWriterTest extends IntegrationTestCase
{
    public function testItStampsTheNewestEscalatedRecord(): void
    {
        $quoteId = Uuid::randomHex();
        $older = Uuid::randomHex();
        $newer = Uuid::randomHex();

        self::records()
            ->create([
                self::row($older, $quoteId, '2026-08-30 10:00:00.000', 'escalated'),
                self::row($newer, $quoteId, '2026-08-30 11:00:00.000', 'escalated'),
            ], Context::createDefaultContext());

        self::writer()->recordEscalationResolution($quoteId, 'sent', new \DateTimeImmutable('2026-08-30 15:00:00'));

        self::assertSame('sent', self::recordOf($newer)->resolvedState, 'The newest record was not stamped.');
        self::assertNotNull(self::recordOf($newer)->resolvedAt, 'resolvedAt stayed null on the stamped record.');
        self::assertNull(self::recordOf($older)->resolvedState, 'An older record was stamped as well.');
    }

    public function testItLeavesANonEscalatedNewestRecordAlone(): void
    {
        // The subscriber guards this too, but the writer is the thing that
        // touches the row, so it must not depend on its caller for
        // correctness: a pass that answered the buyer has no escalation to
        // resolve.
        $quoteId = Uuid::randomHex();
        $id = Uuid::randomHex();

        self::records()
            ->create([
                self::row($id, $quoteId, '2026-08-30 10:00:00.000', 'offered'),
            ], Context::createDefaultContext());

        self::writer()->recordEscalationResolution($quoteId, 'sent', new \DateTimeImmutable('2026-08-30 15:00:00'));

        self::assertNull(self::recordOf($id)->resolvedState);
    }

    public function testAnAlreadyResolvedRecordIsNotRestamped(): void
    {
        // First resolution wins: the measure is "how long the deal desk took
        // to answer", so a later transition on the same quote must not reset
        // the clock.
        //
        // The assertion below compares resolvedAt across the two calls rather
        // than to an absolute string: the DAL stores DATETIME(3) as UTC while
        // `new \DateTimeImmutable(...)` uses PHP's default timezone, so an
        // absolute-string assertion would fail on a non-UTC container for a
        // reason unrelated to the behaviour under test. What matters here is
        // that the second call did not move the timestamp the first call set.
        $quoteId = Uuid::randomHex();
        $id = Uuid::randomHex();

        self::records()
            ->create([
                self::row($id, $quoteId, '2026-08-30 10:00:00.000', 'escalated'),
            ], Context::createDefaultContext());

        $writer = self::writer();
        $writer->recordEscalationResolution($quoteId, 'sent', new \DateTimeImmutable('2026-08-30 12:00:00'));
        $firstResolvedAt = self::recordOf($id)->resolvedAt;

        $writer->recordEscalationResolution($quoteId, 'declined', new \DateTimeImmutable('2026-08-30 18:00:00'));
        $secondResolvedAt = self::recordOf($id)->resolvedAt;

        self::assertSame('sent', self::recordOf($id)->resolvedState, 'The second transition overwrote the first.');
        self::assertEquals($firstResolvedAt, $secondResolvedAt, 'resolvedAt was moved by the second transition.');
    }

    public function testAQuoteWithNoRecordsIsANoop(): void
    {
        // writer() resolves records() below, which already asserts, so this
        // uses addToAssertionCount() rather than expectNotToPerformAssertions()
        // — mirrors TerminalOutcomeWriterTest::testAQuoteWithNoRecordIsASilentNoOp.
        self::writer()->recordEscalationResolution(Uuid::randomHex(), 'sent', new \DateTimeImmutable());

        $this->addToAssertionCount(1);
    }

    /** @return array<string, mixed> */
    private static function row(string $id, string $quoteId, string $createdAt, string $outcome): array
    {
        return [
            'id' => $id,
            'quoteId' => $quoteId,
            'outcome' => $outcome,
            'createdAt' => $createdAt,
        ];
    }

    private static function writer(): EscalationResolutionWriter
    {
        return new EscalationResolutionWriter(self::records());
    }

    /** @return EntityRepository<covariant QuoteDecisionRecord> */
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
}
