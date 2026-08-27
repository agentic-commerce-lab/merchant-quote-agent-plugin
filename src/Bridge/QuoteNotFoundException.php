<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

final class QuoteNotFoundException extends \RuntimeException
{
    public static function forId(string $quoteId): self
    {
        return new self(sprintf('Quote "%s" was not found.', $quoteId));
    }
}
