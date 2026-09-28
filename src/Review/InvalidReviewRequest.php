<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * A review request the merchant can fix: answered 400 with `$reason`, which
 * the review card maps to its own copy, and the message, which is
 * merchant-facing copy for a reason the card does not know yet.
 */
final class InvalidReviewRequest extends \RuntimeException
{
    private function __construct(
        public readonly InvalidReviewReason $reason,
        string $message,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(InvalidReviewReason $reason, string $message, ?\Throwable $previous = null): self
    {
        return new self($reason, $message, $previous);
    }
}
