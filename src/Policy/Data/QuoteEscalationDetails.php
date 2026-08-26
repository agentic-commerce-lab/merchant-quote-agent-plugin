<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class QuoteEscalationDetails
{
    /** @param list<string>|null $humanReviewRequests */
    public function __construct(
        public QuoteEscalationReason $reason,
        public ?float $requestedDiscountPercent,
        public ?array $humanReviewRequests = null,
    ) {}
}
