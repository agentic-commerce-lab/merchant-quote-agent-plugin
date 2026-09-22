<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\InterpretationPayload;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Improvement\DecisionHarvest;
use MerchantQuoteAgentPlugin\Improvement\ImprovementWindow;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationAsks;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class DecisionHarvestTest extends TestCase
{
    public function testItMapsEveryHarvestedField(): void
    {
        $record = self::record(interpretedAsks: ['price' => ['additionalDiscountPercent' => 5.0]]);
        $harvest = new DecisionHarvest($this->repository([$record]));

        $decisions = $harvest->forWindow(self::window(), 'sc-1', Context::createDefaultContext());

        self::assertCount(1, $decisions);
        $decision = $decisions[0];
        self::assertSame($record->id, $decision->decisionId);
        self::assertSame($record->quoteId, $decision->quoteId);
        self::assertSame('grant', $decision->band);
        self::assertSame('offered', $decision->outcome);
        self::assertNull($decision->escalationReason);
        self::assertNull($decision->terminalState);
        self::assertSame(5.0, $decision->discountPercentGranted);
        self::assertSame(10.0, $decision->maxDiscountPercent);
        self::assertSame(['price' => ['additionalDiscountPercent' => 5.0]], $decision->interpretedAsks);
        self::assertSame('extract-hash', $decision->extractPromptHash);
    }

    public function testItFiltersOnTheHalfOpenWindowAndTheSalesChannel(): void
    {
        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$captured): EntitySearchResult {
                $captured = $criteria;

                return new EntitySearchResult('test', 0, new EntityCollection([]), null, $criteria, $context);
            });

        $window = self::window();
        (new DecisionHarvest($repository))->forWindow($window, 'sc-1', Context::createDefaultContext());

        self::assertInstanceOf(Criteria::class, $captured);

        $range = null;
        $salesChannel = null;

        foreach ($captured->getFilters() as $filter) {
            if ($filter instanceof RangeFilter) {
                $range = $filter;
            }

            if ($filter instanceof EqualsFilter && $filter->getField() === 'salesChannelId') {
                $salesChannel = $filter;
            }
        }

        self::assertNotNull($range);
        self::assertSame('createdAt', $range->getField());
        self::assertSame($window->from->format(\DateTimeInterface::ATOM), $range->getParameter(RangeFilter::GTE));
        self::assertSame($window->to->format(\DateTimeInterface::ATOM), $range->getParameter(RangeFilter::LT));
        self::assertNotNull($salesChannel);
        self::assertSame('sc-1', $salesChannel->getValue());
    }

    /**
     * The risk the design brief calls out by name: if HarvestedDecision were
     * built from AnonymizedDecision::asks()'s STRIPPED shape instead of the
     * raw stored interpretation, every decision would silently count as
     * skipped forever, because InterpretationHydrator::hydrate() refuses any
     * payload missing `clarificationQuestions` or `humanReviewRequests` --
     * exactly the two keys that export strips. This proves both halves: the
     * raw shape DecisionHarvest actually uses rehydrates to a usable
     * CommentInterpretation, and the stripped shape it deliberately does NOT
     * use would not.
     */
    public function testARealisticStoredInterpretedAsksPayloadRehydrates(): void
    {
        $interpretation = new CommentInterpretation(
            price: new PriceAsk(additionalDiscountPercent: 8.0, bestPriceRequested: false, targetTotal: null),
            structural: new StructuralAsks(lineChanges: [], addProducts: [], validityUntilIsoDate: null),
            clarificationQuestions: [],
            humanReviewRequests: [],
            negotiation: new NegotiationAsks(),
        );
        $stored = InterpretationPayload::of($interpretation);

        $record = self::record(interpretedAsks: $stored);
        $harvest = new DecisionHarvest($this->repository([$record]));
        $decisions = $harvest->forWindow(self::window(), 'sc-1', Context::createDefaultContext());

        $restored = InterpretationPayload::from($decisions[0]->interpretedAsks ?? []);

        self::assertNotNull($restored, 'the raw stored shape must rehydrate');
        self::assertEquals($interpretation, $restored);

        // The negative half: the export's stripped shape refuses to hydrate at
        // all, which is exactly why DecisionHarvest must never build
        // HarvestedDecision from it.
        $stripped = $stored;
        unset($stripped['clarificationQuestions'], $stripped['humanReviewRequests']);

        self::assertNull(
            InterpretationPayload::from($stripped),
            'the AnonymizedDecision::asks() stripped shape must NOT rehydrate -- proving the harvest must read raw',
        );
    }

    private static function window(): ImprovementWindow
    {
        return (
            ImprovementWindow::due(
                null,
                new \DateTimeImmutable('2026-09-21 00:00:00'),
                \MerchantQuoteAgentPlugin\Improvement\ImprovementCadence::Daily,
            ) ?? self::fail('expected a due window')
        );
    }

    /** @param array<string, mixed>|null $interpretedAsks */
    private static function record(?array $interpretedAsks): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $id = Uuid::randomHex();
        $record->setUniqueIdentifier($id);
        $record->id = $id;
        $record->quoteId = Uuid::randomHex();
        $record->band = 'grant';
        $record->outcome = 'offered';
        $record->escalationReason = null;
        $record->terminalState = null;
        $record->discountPercentGranted = 5.0;
        $record->maxDiscountPercent = 10.0;
        $record->interpretedAsks = $interpretedAsks;
        $record->extractPromptHash = 'extract-hash';

        return $record;
    }

    /** @param list<QuoteDecisionRecord> $records */
    private function repository(array $records): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn(Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    'merchant_quote_agent_decision',
                    \count($records),
                    new EntityCollection($records),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }
}
