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
            throw InvalidReviewRequest::because('These prices would raise the total above what the quote shows now.');
        }

        $granted = abs($after->totals->totalNet - $pending->live->totals->totalNet) > Epsilon::MONEY;
        [$percent, $disagreed] = ReductionForPass::of(
            SnapshotAdapter::anchored($pending->live)->totalNet,
            $after->totals->totalNet,
            $granted,
        );

        if ($disagreed) {
            throw InvalidReviewRequest::because('These prices would raise the total above what the quote shows now.');
        }

        return $percent;
    }
}
