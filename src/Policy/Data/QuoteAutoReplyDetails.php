<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class QuoteAutoReplyDetails
{
    /** @param list<QuoteLinePrice> $lineUnitPricesNet */
    public function __construct(
        public float $discountPercent,
        public bool $perLineAsks,
        public array $lineUnitPricesNet,
        public int $validityDays,
        public ?float $counteredRequestPercent = null,
    ) {}
}
