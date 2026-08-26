<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fix for issue #2(a): `referenceLines` is the pre-negotiation snapshot every
 * proposed line price is bounded against — replacing the TS `quoteLines`
 * field, which was documented as the quote's CURRENT lines and let per-line
 * discounts compound round over round. The caller (Servicing, issue #4) is
 * responsible for capturing this snapshot once and reusing it across rounds.
 */
final readonly class OfferedPrice
{
    /**
     * @param list<QuoteLinePrice>|null $linePricesNet
     * @param list<QuoteLineSnapshot>|null $referenceLines
     */
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public ?float $discountPercent = null,
        public ?array $linePricesNet = null,
        public ?array $referenceLines = null,
    ) {}
}
