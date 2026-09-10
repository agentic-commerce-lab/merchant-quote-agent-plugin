<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;

/**
 * Who the buyer is, written out for the negotiate prompt. Sibling of
 * AuthorityBrief, and the same rule applies: this tunes POSTURE and cannot move
 * a cap. OfferAuthorizer rejects an out-of-band offer no matter what loyalty the
 * model read here.
 *
 * "Customer" is the company: one id covers every employee and every
 * organization unit (see CustomerHistoryInterface).
 *
 * Marked INTERNAL in the text itself, not only in the prompt file, because the
 * negotiate call writes the buyer-facing `message` in the same response — the
 * marker has to travel with the data it governs. The decision-table figures are
 * the part that must never surface: recorded proposal activity and accepted
 * quote counts are private negotiating context, not an offer acceptance rate.
 *
 * An unavailable summary renders NOTHING. An empty section invites the model to
 * speculate about why it is empty; the reason goes to the audit record instead.
 */
final class CustomerBrief
{
    private const HEADING =
        'INTERNAL — THIS ACCOUNT\'S HISTORY (informs your posture; never quote, '
            . 'summarise or acknowledge any of it to the buyer):';

    private function __construct() {}

    public static function of(CustomerSummary $summary): string
    {
        if (!$summary->available) {
            return '';
        }

        $lines = [...self::quoteLines($summary), ...self::orderLines($summary)];

        if ($lines === []) {
            return self::HEADING . "\n- no quotes and no orders recorded on this account";
        }

        return self::HEADING . "\n" . implode("\n", $lines);
    }

    /** @return list<string> */
    private static function quoteLines(CustomerSummary $summary): array
    {
        $quotes = $summary->quotes;

        if ($quotes->seen === 0) {
            return [];
        }

        $lines = [sprintf(
            '- %d recent quotes on this account: %d became orders, %d ended without a deal',
            $quotes->seen,
            $quotes->converted,
            $quotes->lost,
        )];

        if ($quotes->offersMade > 0) {
            $lines[] = sprintf(
                '- %d authorized proposal passes across this account; %d accepted quotes had an authorized proposal',
                $quotes->offersMade,
                $quotes->offersAccepted,
            );
        }

        if ($quotes->lastGrantedDiscountPercent !== null) {
            $lines[] = sprintf(
                '- the latest recorded pass reduced that pass’s opening total by %.2f%%',
                $quotes->lastGrantedDiscountPercent,
            );
        }

        return $lines;
    }

    /** @return list<string> */
    private static function orderLines(CustomerSummary $summary): array
    {
        $orders = $summary->orders;

        if ($orders->count === 0) {
            return [];
        }

        $lifetime = $orders->lifetimeNet === null
            ? 'lifetime net unavailable: ' . ($orders->unavailableReason ?? 'unknown')
            : 'lifetime ' . HistoryMoney::of($orders->lifetimeNet, $orders->currencyIso) . ' net';

        return [sprintf(
            '- %d orders, %s, last on %s',
            $orders->count,
            $lifetime,
            $orders->lastOrderAt?->format('Y-m-d') ?? 'an unknown date',
        )];
    }
}
