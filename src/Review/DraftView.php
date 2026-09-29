<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * The review card's one read: live against drafted, side by side. `pricing`
 * says which kind of price the agent drafted — per-line or quote-wide — so the
 * card offers the matching inputs; null is a draft that changes no price (a
 * clarification or an acknowledgement), which has none.
 *
 * The discount keeps its type: QuoteTotalRounding drafts an ABSOLUTE amount
 * (Bridge\Data\Discount) that lands the gross total on a round figure, and a
 * percent-only read shows that draft as no discount at all.
 */
final class DraftView
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(PendingDraft $pending, QuoteSnapshot $draft, ?string $reply): array
    {
        $record = $pending->record;
        $live = $pending->live;

        return [
            'decisionId' => $record->id,
            'outcome' => $record->outcome,
            'stale' => $pending->stale,
            'previewEdited' => $pending->previewEdited(),
            'quoteState' => $live->lifecycle->stateTechnicalName,
            'currencyIso' => $live->identity->currencyIso,
            'maxDiscountPercent' => $record->maxDiscountPercent,
            'pricing' => self::pricing($pending),
            'discount' => ['live' => self::discount($live), 'draft' => self::discount($draft)],
            'lines' => self::lines($live, $draft),
            'totals' => ['live' => self::totals($live), 'draft' => self::totals($draft)],
            'expiresAt' => [
                'live' => $live->lifecycle->expiresAt?->format('Y-m-d'),
                'draft' => $draft->lifecycle->expiresAt?->format('Y-m-d'),
            ],
            'reply' => $reply ?? $record->replyToBuyer ?? '',
            'replyRedrafted' => $reply !== null,
        ];
    }

    private static function pricing(PendingDraft $pending): ?string
    {
        if ($pending->draft === null) {
            return null;
        }

        return \in_array('updateLineItems', $pending->record->writes ?? [], strict: true) ? 'lines' : 'discount';
    }

    /** @return ?array{type: string, value: float} */
    private static function discount(QuoteSnapshot $snapshot): ?array
    {
        $discount = $snapshot->totals->discount;

        return $discount === null ? null : ['type' => $discount->type->value, 'value' => $discount->value];
    }

    /** @return list<array{id: string, label: ?string, quantity: int, live: float, draft: float}> */
    private static function lines(QuoteSnapshot $live, QuoteSnapshot $draft): array
    {
        $drafted = [];

        foreach ($draft->content->lines as $line) {
            $drafted[$line->identity->lineItemId] = $line->unitPriceNet;
        }

        $lines = [];

        foreach ($live->content->lines as $line) {
            $lines[] = [
                'id' => $line->identity->lineItemId,
                'label' => $line->identity->label,
                'quantity' => $line->quantity,
                'live' => $line->unitPriceNet,
                'draft' => $drafted[$line->identity->lineItemId] ?? $line->unitPriceNet,
            ];
        }

        return $lines;
    }

    /** @return array{net: float, gross: ?float} */
    private static function totals(QuoteSnapshot $snapshot): array
    {
        return ['net' => $snapshot->totals->totalNet, 'gross' => $snapshot->totals->totalGross];
    }
}
