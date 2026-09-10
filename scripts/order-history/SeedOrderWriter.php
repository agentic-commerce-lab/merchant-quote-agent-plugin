<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Scripts\OrderHistory;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/** Atomic, serialized order slots with recovery of an interrupted backdate. */
final readonly class SeedOrderWriter
{
    public function __construct(
        private Connection $connection,
        private SeedServices $services,
        private SeedData $data,
        private SeedQuoteWriter $quotes,
    ) {}

    /**
     * @param array{id: string, channel: string, host: string} $customer
     * @throws \Throwable DBAL transactions rethrow the original failure after rollback.
     */
    public function seedSlot(array $customer, ?string $productId, int $slot, \DateTimeImmutable $date): bool
    {
        // A customer row lock serializes concurrent runs, including their
        // absent-marker checks. Route and DAL writes share this connection.
        return $this->connection->transactional(
            /** @throws \Throwable */ function () use ($customer, $productId, $slot, $date): bool {
                $this->connection->fetchOne('SELECT id FROM customer WHERE id = UNHEX(:id) FOR UPDATE', [
                    'id' => $customer['id'],
                ]);
                $marker = SeedData::marker($customer['id'], $slot);
                if ($this->data->order($marker, $customer['id']) !== null) {
                    $this->recoverDate($marker, $customer['id']);
                    return false;
                }
                $context = $this->services->context($customer);
                $quote = $this->quotes->quote($context, $productId, $slot, $date);
                $this->quotes->ready($quote);
                $route = $this->services->route();
                $response = $route->order(
                    $context,
                    new RequestDataBag([OrderService::CUSTOMER_COMMENT_KEY => $marker]),
                    $quote['id'],
                );
                if (!is_object($response) || !method_exists($response, 'getOrder')) {
                    throw new \RuntimeException('The quote order route returned an invalid response.');
                }
                $order = $response->getOrder();
                if (!$order instanceof OrderEntity) {
                    throw new \RuntimeException('The quote order route did not return an order.');
                }
                $this->backdate($order->getId(), $quote['date'], $marker);
                return true;
            },
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    private function recoverDate(string $marker, string $customerId): void
    {
        $quote = $this->data->quote($marker, $customerId);
        $orderId = $this->data->order($marker, $customerId);
        if ($quote === null || $orderId === null) {
            throw new \RuntimeException(
                'A seeded order has lost its quote marker; refusing to duplicate or guess its date.',
            );
        }
        // Usually already equal. This recovers a route that committed an order
        // before a previous run reached the final backdate write.
        $stored = $this->connection->fetchOne('SELECT order_date_time FROM `order` WHERE id = UNHEX(:id) AND version_id = UNHEX(:live)', [
            'id' => $orderId,
            'live' => Defaults::LIVE_VERSION,
        ]);
        if ($stored !== $quote['date']) {
            $this->backdate($orderId, $quote['date'], $marker);
        }
    }

    private function backdate(string $orderId, string $date, string $marker): void
    {
        $this->services->repository('order.repository')->update([[
            'id' => $orderId,
            'versionId' => Defaults::LIVE_VERSION,
            'orderDateTime' => $date,
            'customerComment' => $marker,
            'customFields' => [SeedData::MARKER_FIELD => $marker],
        ]], SeedServices::systemContext());
    }
}
