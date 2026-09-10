<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Reads the amount, currency, count and date aggregations OrderHistoryReads::search() attaches to every
 * order search into an OrderStats. Split out of OrderHistoryReads so that
 * class stays under mago's per-class complexity budget.
 */
final readonly class OrderHistoryAggregation
{
    /** @param EntitySearchResult<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $result */
    public static function stateOf(EntitySearchResult $result): OrderStats
    {
        $count = $result->getAggregations()->get('orderCount');
        $sum = $result->getAggregations()->get('lifetimeNet');
        $max = $result->getAggregations()->get('lastOrderAt');
        $last = $max instanceof MaxResult ? $max->getMax() : null;
        $currencies = $result->getAggregations()->get('currencies');
        $total = OrderCurrencyTotal::of(
            $count instanceof CountResult ? $count : null,
            $sum instanceof SumResult ? $sum : null,
            $currencies instanceof TermsResult ? $currencies : null,
        );

        return new OrderStats(
            count: $count instanceof CountResult ? $count->getCount() : 0,
            lifetimeNet: $total->amount,
            lastOrderAt: \is_string($last) && $last !== '' ? new \DateTimeImmutable($last) : null,
            currencyIso: $total->currencyIso,
            unavailableReason: $total->unavailableReason,
        );
    }
}
