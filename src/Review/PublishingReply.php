<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/** Detects a merchant-authored comment after a Send began but before its audit write completed. */
final class PublishingReply
{
    private function __construct() {}

    public static function merchantCommentCount(QuoteSnapshot $snapshot): int
    {
        return \count(array_filter(
            $snapshot->content->comments,
            static fn(QuoteComment $comment): bool => $comment->createdById !== null,
        ));
    }

    public static function visible(QuoteDecisionRecord $record, QuoteSnapshot $live): bool
    {
        $before = $record->sentChanges['publishingMerchantCommentCount'] ?? null;

        return \is_int($before) && self::merchantCommentCount($live) > $before;
    }
}
