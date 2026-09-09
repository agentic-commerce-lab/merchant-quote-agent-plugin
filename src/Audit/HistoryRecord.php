<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequest;

/** Maps the account brief and exact rendered history reads onto the active pass. */
final class HistoryRecord
{
    private function __construct() {}

    public static function summaryTo(?DecisionDraft $draft, CustomerSummary $summary): void
    {
        if ($draft === null) {
            return;
        }

        $draft->historyReads = [
            'available' => $summary->available,
            'reason' => $summary->unavailableReason,
            'quotesSeen' => $summary->quotes->seen,
            'quotesConverted' => $summary->quotes->converted,
            'quotesLost' => $summary->quotes->lost,
            'offersMade' => $summary->quotes->offersMade,
            'offersAccepted' => $summary->quotes->offersAccepted,
            'lastGrantedDiscountPercent' => $summary->quotes->lastGrantedDiscountPercent,
            'orderCount' => $summary->orders->count,
            'lifetimeNet' => $summary->orders->lifetimeNet,
            'lastOrderAt' => $summary->orders->lastOrderAt?->format(\DateTimeInterface::ATOM),
            'rounds' => $draft->historyReads['rounds'] ?? [],
        ];
    }

    public static function roundTo(?DecisionDraft $draft, HistoryRequest $request, string $result): void
    {
        if ($draft === null) {
            return;
        }

        $rounds = $draft->historyReads['rounds'] ?? [];
        $rounds = is_array($rounds) ? $rounds : [];
        $rounds[] = ['kind' => $request->kind?->value, 'productId' => $request->productId, 'result' => $result];
        $draft->historyReads['rounds'] = $rounds;
    }
}
