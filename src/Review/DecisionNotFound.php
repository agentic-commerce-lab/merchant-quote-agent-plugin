<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

final class DecisionNotFound extends \RuntimeException
{
    public static function forId(string $decisionId): self
    {
        return new self(sprintf('No agent decision %s exists.', $decisionId));
    }
}
