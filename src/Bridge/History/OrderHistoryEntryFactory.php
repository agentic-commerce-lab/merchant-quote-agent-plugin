<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistoryEntry;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderLineEntry;
use MerchantQuoteAgentPlugin\Bridge\OrderLineNet;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Maps one verified order Entity (with its lineItems association loaded) to
 * the read model. Split out of OrderHistoryReads so that class stays under
 * mago's per-class complexity budget.
 */
final readonly class OrderHistoryEntryFactory
{
    public static function of(Entity $order): OrderHistoryEntry
    {
        $state = $order->get('stateMachineState');
        $orderedAt = $order->get('orderDateTime');

        return new OrderHistoryEntry(
            orderNumber: (string) $order->get('orderNumber'),
            orderedAt: $orderedAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($orderedAt)
                : null,
            amountNet: (float) $order->get('amountNet'),
            state: $state instanceof Entity ? (string) $state->get('technicalName') : '',
            lines: self::lines($order),
        );
    }

    /** @return list<OrderLineEntry> */
    private static function lines(Entity $order): array
    {
        $lines = $order->get('lineItems');
        $taxStatus = (string) $order->get('taxStatus');

        if (!is_iterable($lines)) {
            return [];
        }

        $mapped = [];

        foreach ($lines as $line) {
            if (!$line instanceof Entity) {
                continue;
            }

            $mapped[] = new OrderLineEntry(
                label: (string) $line->get('label'),
                quantity: (int) $line->get('quantity'),
                unitPriceNet: OrderLineNet::of($line, $taxStatus),
            );
        }

        return $mapped;
    }
}
