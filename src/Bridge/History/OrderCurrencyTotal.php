<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;

/** A sum of original order amounts is meaningful only in one fully known currency. */
final readonly class OrderCurrencyTotal
{
    public function __construct(
        public ?float $amount = null,
        public ?string $currencyIso = null,
        public ?string $unavailableReason = 'order currencies are mixed or unknown',
    ) {}

    public static function of(?CountResult $count, ?SumResult $sum, ?TermsResult $currencies): self
    {
        if ($count === null) {
            return new self();
        }
        if ($count->getCount() === 0) {
            return new self(0.0, null, null);
        }
        $buckets = $currencies?->getBuckets() ?? [];
        if ($sum === null || \count($buckets) !== 1) {
            return new self();
        }
        $bucket = $buckets[0];
        $iso = $bucket->getKey();
        if ($iso === null || trim($iso) === '' || $bucket->getCount() !== $count->getCount()) {
            return new self();
        }
        return new self((float) $sum->getSum(), $iso, null);
    }
}
