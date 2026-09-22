<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * One window's worth of decisions, read whole -- not limited to the replay
 * sample size. DayPicture::of() is built from every decision in the window,
 * because an aggregate over 20 of 300 decisions is not the period's picture;
 * ImprovementRunner is what slices the replay sample off the front of the
 * list this returns.
 *
 * The range is half-open, `$window->from` inclusive and `$window->to`
 * exclusive, the same convention ImprovementWindow and the decision export
 * already use, so two consecutive runs can never read one decision twice.
 */
final readonly class DecisionHarvest
{
    public function __construct(
        private EntityRepository $decisions,
    ) {}

    /** @return list<HarvestedDecision> */
    public function forWindow(ImprovementWindow $window, ?string $salesChannelId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $window->from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $window->to->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $decisions = [];

        foreach ($this->decisions->search($criteria, $context)->getEntities() as $record) {
            if ($record instanceof QuoteDecisionRecord) {
                $decisions[] = self::harvest($record);
            }
        }

        return $decisions;
    }

    private static function harvest(QuoteDecisionRecord $record): HarvestedDecision
    {
        return new HarvestedDecision(
            $record->id,
            $record->quoteId,
            new DecisionClassification(
                $record->band,
                $record->outcome,
                $record->escalationReason,
                $record->terminalState,
            ),
            new DecisionDiscount($record->discountPercentGranted, $record->maxDiscountPercent),
            new DecisionExtraction($record->interpretedAsks, $record->extractPromptHash),
        );
    }
}
