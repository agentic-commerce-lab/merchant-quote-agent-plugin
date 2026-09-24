<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * The draft cannot be acted on right now; `$reason` is the machine code the
 * review card switches on (not_pending, stale, busy, gone, unavailable). Answered 409.
 */
final class DraftNotReviewable extends \RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 'This draft was already sent, rejected or replaced by a newer one.');
    }

    public static function stale(): self
    {
        return new self('stale', 'The buyer wrote again or changed the quote since this draft was prepared.');
    }

    public static function busy(): self
    {
        return new self('busy', 'The agent is working on this quote right now. Try again in a few seconds.');
    }

    /**
     * The row names a draft version whose rows are gone — after a Send that
     * failed past its merge, say. A versioned DAL read falls back to the live
     * row, so without this the review would silently show and send the live
     * quote as if it were the draft.
     */
    public static function gone(): self
    {
        return new self(
            'gone',
            'The prepared prices for this draft no longer exist. Reject it and handle the quote in SwagCommercial.',
        );
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 'Quotes cannot be edited because SwagCommercial is not licensed.');
    }
}
