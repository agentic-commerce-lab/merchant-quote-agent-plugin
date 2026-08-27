<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

final class QuoteRevisionMismatch extends \RuntimeException
{
    public static function forId(string $quoteId): self
    {
        return new self(sprintf('Quote "%s" changed since it was read; write refused.', $quoteId));
    }
}
