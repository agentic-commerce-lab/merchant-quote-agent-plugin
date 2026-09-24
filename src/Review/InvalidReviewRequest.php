<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/** A review request the merchant can fix: answered 400 with the message, which is merchant-facing copy. */
final class InvalidReviewRequest extends \RuntimeException
{
    public static function because(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
