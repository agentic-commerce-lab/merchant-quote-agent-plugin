<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The guards behind the admin page: that the trail cannot be edited through
 * the API, and that the figures the page shows are queries the database
 * actually answers.
 */
final class DecisionRecordGuardsTest extends IntegrationTestCase
{
    public function testAnAdminScopedWriteIsRejected(): void
    {
        $repository = self::records();
        $id = Uuid::randomHex();

        try {
            Context::createDefaultContext()->scope(Context::USER_SCOPE, static function (Context $userContext) use (
                $repository,
                $id,
            ): void {
                $repository->create([self::row($id, Uuid::randomHex(), 'offered', 5.0)], $userContext);
            });
            self::fail('The write was not rejected.');
        } catch (WriteException $e) {
            self::assertStringContainsString(
                'write-protected',
                $e->getMessage(),
                'Rejected for a reason other than write protection: ' . $e->getMessage(),
            );
        }
    }

    public function testTheSameWriteSucceedsInSystemScope(): void
    {
        // The other half of the finding: protection must stop the admin API
        // without stopping DecisionRecordWriter, which writes in system scope.
        $repository = self::records();
        $id = Uuid::randomHex();

        $repository->create([self::row($id, Uuid::randomHex(), 'offered', 5.0)], Context::createDefaultContext());

        $written = $repository->search(new Criteria([$id]), Context::createDefaultContext())->first();
        self::assertNotNull($written);
        self::assertSame('offered', $written->outcome, 'The row exists but did not round-trip its value.');
    }

    public function testTheValueHandledAggregationCountsEachQuoteOnce(): void
    {
        // A quote serviced twice has two rows. Summing totalNetBefore would
        // report its value twice, so the page takes the max per quoteId and
        // sums the buckets. This pins that shape. Quote A's two rows carry
        // DIFFERENT values (1000 and 1200) on purpose: with equal rows, max,
        // avg and min per bucket would all agree, and the test would stay
        // green even if MaxAggregation were swapped for a different one.
        $repository = self::records();
        $quoteA = Uuid::randomHex();
        $quoteB = Uuid::randomHex();

        $repository->create([
            self::row(Uuid::randomHex(), $quoteA, 'offered', 5.0, 1000.0),
            self::row(Uuid::randomHex(), $quoteA, 'countered', 7.0, 1200.0),
            self::row(Uuid::randomHex(), $quoteB, 'offered', 3.0, 500.0),
        ], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('quoteId', [$quoteA, $quoteB]));
        $criteria->addAggregation(
            new TermsAggregation('per-quote', 'quoteId', null, null, new MaxAggregation('value', 'totalNetBefore')),
        );

        $result = $repository->aggregate($criteria, Context::createDefaultContext())->get('per-quote');
        self::assertInstanceOf(TermsResult::class, $result);
        self::assertCount(2, $result->getBuckets(), 'One bucket per quote, not per pass.');

        $total = 0.0;
        foreach ($result->getBuckets() as $bucket) {
            $max = $bucket->getResult();
            self::assertInstanceOf(MaxResult::class, $max);
            $total += (float) $max->getMax();
        }

        // 1700 = max(1000,1200) + 500. A plain sum would give 2700; avg per
        // bucket then summed would give 1600; min per bucket would give 1500.
        // Only the max-per-quote shape lands on 1700.
        self::assertSame(
            1700.0,
            $total,
            'Expected max(1000, 1200) + 500 = 1700; a different aggregation shape was used.',
        );
    }

    public function testTheDiscountAverageExcludesEscalatedPasses(): void
    {
        // A verification-failed pass carries a real granted discount, because
        // the write happened and the database shows the reduction. Averaging
        // those in would mix discounts the agent stood behind with ones it
        // applied and then escalated over.
        $repository = self::records();
        $quoteId = Uuid::randomHex();

        $repository->create([
            self::row(Uuid::randomHex(), $quoteId, 'offered', 5.0),
            self::row(Uuid::randomHex(), $quoteId, 'countered', 7.0),
            self::row(Uuid::randomHex(), $quoteId, 'escalated', 40.0),
        ], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addFilter(new EqualsAnyFilter('outcome', ['offered', 'countered']));
        $criteria->addAggregation(new AvgAggregation('granted', 'discountPercentGranted'));

        $average = $repository->aggregate($criteria, Context::createDefaultContext())->get('granted');
        self::assertInstanceOf(AvgResult::class, $average);
        self::assertSame(6.0, $average->getAvg(), 'The 40% escalated row must not be averaged in.');
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /** @return array<string, mixed> */
    private static function row(
        string $id,
        string $quoteId,
        string $outcome,
        float $granted,
        float $totalNetBefore = 1000.0,
    ): array {
        return [
            'id' => $id,
            'quoteId' => $quoteId,
            'outcome' => $outcome,
            'discountPercentGranted' => $granted,
            'maxDiscountPercent' => 10.0,
            'totalNetBefore' => $totalNetBefore,
            'durationMs' => 100,
        ];
    }
}
