<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistory;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use MerchantQuoteAgentPlugin\Bridge\Data\History\ProductPurchase;
use MerchantQuoteAgentPlugin\Bridge\OrderLineNet;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * The company's order record, read through the generic DAL. No SwagCommercial
 * class is named (ADR 0001): `order.repository` and `order_line_item.repository`
 * arrive by string id and fields come off Entity::get().
 *
 * The customer is reached through core's `orderCustomer` one-to-one rather than
 * a column on the order, which makes the scope field a PATH:
 * `orderCustomer.customerId` from an order, `order.orderCustomer.customerId`
 * from a line.
 *
 * `orderCustomer` is associated on both reads for the VERIFICATION, not for
 * anything rendered: without it the loaded row carries no customer id to check
 * against, and the filter would be the only thing between us and another
 * company's orders. See OrderHistoryRow::verify().
 *
 * Orders are versioned like quotes, and CustomerScope::context() forces the
 * live version for that reason.
 *
 * Verification lives in OrderHistoryRow, aggregate reading in
 * OrderHistoryAggregation, row-to-value-object mapping in
 * OrderHistoryEntryFactory, and a line's net unit price in OrderLineNet: this
 * class only decides which search to run and assembles the results, keeping
 * it under mago's per-class complexity/method budget.
 */
final readonly class OrderHistoryReads
{
    private const RECENT_ORDERS = 10;

    private const RECENT_PURCHASES = 10;

    /**
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $orders
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $orderLines
     */
    public function __construct(
        private EntityRepository $orders,
        private EntityRepository $orderLines,
    ) {}

    /** @throws CrossCustomerRead */
    public function history(CustomerScope $scope): OrderHistory
    {
        $result = $this->search($scope, withLines: true);

        $recent = [];

        foreach ($result->getEntities() as $order) {
            if (!$order instanceof Entity) {
                continue;
            }

            OrderHistoryRow::verify($scope, $order);
            $recent[] = OrderHistoryEntryFactory::of($order);
        }

        return new OrderHistory(OrderHistoryAggregation::stateOf($result), $recent);
    }

    /** @throws CrossCustomerRead */
    public function stats(CustomerScope $scope): OrderStats
    {
        // Limit 1 rather than 0: the aggregations cover the whole filtered set
        // either way, and one row is what lets verify() run at all.
        return OrderHistoryAggregation::stateOf($this->search($scope, withLines: false, limit: 1));
    }

    /**
     * @return list<ProductPurchase>
     *
     * @throws CrossCustomerRead
     */
    public function purchasesOf(CustomerScope $scope, string $productId): array
    {
        $criteria = $scope->criteria('order.orderCustomer.customerId');
        $criteria->addFilter(new EqualsFilter('productId', $productId));
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addSorting(new FieldSorting('order.orderDateTime', FieldSorting::DESCENDING));
        $criteria->setLimit(self::RECENT_PURCHASES);

        $purchases = [];

        foreach ($this->orderLines->search($criteria, $scope->context())->getEntities() as $line) {
            if (!$line instanceof Entity) {
                continue;
            }

            $order = OrderHistoryRow::verifiedOrder($scope, $line, $productId);
            $orderedAt = $order->get('orderDateTime');

            $purchases[] = new ProductPurchase(
                orderedAt: $orderedAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($orderedAt)
                    : null,
                quantity: (int) $line->get('quantity'),
                unitPriceNet: OrderLineNet::of($line, (string) $order->get('taxStatus')),
            );
        }

        return $purchases;
    }

    /** @return EntitySearchResult<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> */
    private function search(CustomerScope $scope, bool $withLines, int $limit = self::RECENT_ORDERS): EntitySearchResult
    {
        $criteria = $scope->criteria('orderCustomer.customerId');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('stateMachineState');
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::DESCENDING));
        $criteria->setLimit($limit);

        if ($withLines) {
            $criteria->addAssociation('lineItems');
        }

        // Aggregations cover every order the filter matched, not just the page.
        $criteria->addAggregation(new CountAggregation('orderCount', 'id'));
        $criteria->addAggregation(new SumAggregation('lifetimeNet', 'amountNet'));
        $criteria->addAggregation(new MaxAggregation('lastOrderAt', 'orderDateTime'));

        return $this->orders->search($criteria, $scope->context());
    }
}
