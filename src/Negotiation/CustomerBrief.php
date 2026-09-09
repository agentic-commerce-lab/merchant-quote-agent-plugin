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
 * the part that must never surface: telling a buyer they accept three offers in
 * eight hands them the playbook.
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
            return self::HEADING . "\n- first contact: no earlier quotes and no orders on this account";
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
            '- %d earlier quotes on this account: %d became orders, %d ended without a deal',
            $quotes->seen,
            $quotes->converted,
            $quotes->lost,
        )];

        if ($quotes->offersMade > 0) {
            $lines[] = sprintf(
                '- you have made %d offers to this account; %d were on quotes that closed',
                $quotes->offersMade,
                $quotes->offersAccepted,
            );
        }

        if ($quotes->lastGrantedDiscountPercent !== null) {
            $lines[] = sprintf(
                '- the discount actually granted last time was %.2f%%',
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

        return [sprintf(
            '- %d orders, lifetime %.2f net, last on %s',
            $orders->count,
            $orders->lifetimeNet,
            $orders->lastOrderAt?->format('Y-m-d') ?? 'an unknown date',
        )];
    }
}
