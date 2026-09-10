<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * The verification half of a loaded order row, split out of OrderHistoryReads
 * so that class stays under mago's per-class complexity/method budget.
 * Aggregate reading lives in OrderHistoryAggregation, row-to-value-object
 * mapping lives in OrderHistoryEntryFactory — this file is only "does this row
 * belong to the customer this scope is bound to".
 */
final readonly class OrderHistoryRow
{
    /** @throws CrossCustomerRead */
    public static function verify(CustomerScope $scope, Entity $order): void
    {
        $customer = $order->get('orderCustomer');
        $seen = $customer instanceof Entity ? $customer->get('customerId') : null;

        $scope->verify(\is_string($seen) ? $seen : null, 'order ' . (string) $order->get('orderNumber'));
    }

    /**
     * Verifies a line's owning order belongs to this customer and hands it
     * back, so the caller can read its taxStatus. A line whose order cannot
     * be resolved carries no customer id either, so verify() throws rather
     * than this method defaulting to an unpriced 0.0.
     *
     * @throws CrossCustomerRead
     */
    public static function verifiedOrder(CustomerScope $scope, Entity $line, string $productId): Entity
    {
        $order = $line->get('order');
        $customer = $order instanceof Entity ? $order->get('orderCustomer') : null;
        $seen = $customer instanceof Entity ? $customer->get('customerId') : null;

        $what = 'an order line for product ' . $productId;
        $scope->verify(\is_string($seen) ? $seen : null, $what);

        if (!$order instanceof Entity) {
            throw CrossCustomerRead::of($what);
        }

        return $order;
    }
}
