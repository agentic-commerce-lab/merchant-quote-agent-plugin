<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistoryEntry;
use MerchantQuoteAgentPlugin\Bridge\Data\History\ProductPurchase;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteHistoryEntry;
use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequest;
use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequestKind;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/** Resolves account-scoped reads, allowing product requests only for this quote's own products. */
final readonly class HistoryRequestResolver
{
    private const PREFIX = 'INTERNAL — ACCOUNT HISTORY YOU ASKED FOR (never quote or acknowledge it to the buyer): ';

    /** @param list<QuoteLineSnapshot> $lines */
    public function resolve(HistoryRequest $request, CustomerHistoryInterface $history, array $lines): string
    {
        return self::PREFIX . match ($request->kind) {
            HistoryRequestKind::QuoteHistory => $this->quotes($history->quotes()),
            HistoryRequestKind::Orders => $this->orders($history->orders()->recent),
            HistoryRequestKind::ProductPurchases => $this->productPurchases($request->productId, $history, $lines),
            null => 'no history was requested, so nothing was read',
        };
    }

    /** @param list<QuoteHistoryEntry> $quotes */
    private function quotes(array $quotes): string
    {
        return $this->block(array_map($this->quote(...), $quotes), 'this account has no quotes recorded');
    }

    private function quote(QuoteHistoryEntry $quote): string
    {
        $grant = $quote->grantedDiscountPercent === null
            ? 'no recorded pass reduction'
            : sprintf(
                'latest recorded pass reduced that pass’s opening total by %.2f%%',
                $quote->grantedDiscountPercent,
            );

        return sprintf(
            '- quote %s, %s, %s net, state %s, converted %s, %s',
            $quote->quoteNumber,
            $quote->createdAt?->format('Y-m-d') ?? 'unknown',
            HistoryMoney::of($quote->amountNet, $quote->currencyIso),
            $quote->state,
            $quote->converted ? 'yes' : 'no',
            $grant,
        );
    }

    /** @param list<OrderHistoryEntry> $orders */
    private function orders(array $orders): string
    {
        return "recent orders:\n"
        . $this->block(array_map($this->order(...), $orders), 'this account has no order history');
    }

    private function order(OrderHistoryEntry $order): string
    {
        $lines = [sprintf(
            '- order %s, %s, %s net, state %s',
            $order->orderNumber,
            $order->orderedAt?->format('Y-m-d') ?? 'unknown',
            HistoryMoney::of($order->amountNet, $order->currencyIso),
            $order->state,
        )];

        foreach ($order->lines as $line) {
            $lines[] = sprintf(
                '  - %s: quantity %d, unit net %s',
                $line->label,
                $line->quantity,
                HistoryMoney::of($line->unitPriceNet, $order->currencyIso),
            );
        }

        return implode("\n", $lines);
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private function productPurchases(?string $productId, CustomerHistoryInterface $history, array $lines): string
    {
        if ($productId === null || $productId === '') {
            return 'no product was specified, so nothing was read';
        }

        if (!$this->isOnQuote($productId, $lines)) {
            return 'the requested product is not on this quote; only products listed on this quote may be read';
        }

        $purchases = $history->productPurchases($productId);

        return $this->block(array_map($this->purchase(...), $purchases), 'this account has never bought that product');
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private function isOnQuote(string $productId, array $lines): bool
    {
        return \in_array(
            $productId,
            array_map(static fn(QuoteLineSnapshot $line): ?string => $line->identity->productId, $lines),
            strict: true,
        );
    }

    private function purchase(ProductPurchase $purchase): string
    {
        return sprintf(
            '- %s, quantity %d, unit net %s',
            $purchase->orderedAt?->format('Y-m-d') ?? 'unknown',
            $purchase->quantity,
            HistoryMoney::of($purchase->unitPriceNet, $purchase->currencyIso),
        );
    }

    /** @param list<string> $rows */
    private function block(array $rows, string $emptyMessage): string
    {
        return $rows === [] ? $emptyMessage : implode("\n", $rows);
    }
}
