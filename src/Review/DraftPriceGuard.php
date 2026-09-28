<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\ReductionForPass;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Epsilon;

/** The reply's reduction and Send must agree on whether edited prices are sendable. */
final class DraftPriceGuard
{
    private function __construct() {}

    /** @throws InvalidReviewRequest */
    public static function reduction(PendingDraft $pending, QuoteSnapshot $after): ?float
    {
        if ($after->totals->totalNet > ($pending->live->totals->totalNet + Epsilon::MONEY)) {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::PriceIncrease,
                'These prices would raise the total above what the quote shows now.',
            );
        }

        // Prices that leave the live total where it is are the merchant
        // holding: the reply says so (`ReplyTemplate::holds()`). The agent
        // escalates such a pass instead (`PostWriteOutcome`); a human choosing
        // to hold is the one who is allowed to.
        if (abs($after->totals->totalNet - $pending->live->totals->totalNet) <= Epsilon::MONEY) {
            return null;
        }

        [$percent, $disagreed] = ReductionForPass::of(
            SnapshotAdapter::anchored($pending->live)->totalNet,
            $after->totals->totalNet,
        );

        if ($disagreed) {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::PriceIncrease,
                'These prices would raise the total above what the quote shows now.',
            );
        }

        return $percent;
    }
}
