<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Scripts\OrderHistory;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Development-only orchestration of the real Commercial quote-to-order flow. */
final readonly class OrderHistorySeeder
{
    private SeedServices $services;
    private SeedData $data;
    private SeedSelection $selection;
    private SeedOrderWriter $orders;

    public function __construct(ContainerInterface $container)
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Install the plugin with its dev/test order-history service locator first.');
        }
        $this->services = new SeedServices($container);
        $this->data = new SeedData($connection);
        $this->selection = new SeedSelection($connection);
        $this->orders = new SeedOrderWriter(
            $connection,
            $this->services,
            $this->data,
            new SeedQuoteWriter($this->services, $this->data),
        );
    }

    /** @throws \Throwable Per-slot transactions rethrow their original failure. */
    public function run(int $perCustomer, ?string $productId): void
    {
        fwrite(STDOUT, 'Database: ' . $this->data->databaseName() . "\n");
        $customers = $this->selection->customers();
        $newSlots = $this->newSlots($customers, $perCustomer);
        if ($newSlots > 0) {
            $productId = $this->selection->product($customers, $newSlots, $productId);
        }
        if (!$this->services->buyer()->isAvailable()) {
            throw new \RuntimeException('Commercial quote management is unavailable or unlicensed.');
        }
        fwrite(STDOUT, sprintf(
            "Customers: %d; product: %s; target slots/customer: %d\n",
            count($customers),
            $productId ?? 'existing seed quotes/orders',
            $perCustomer,
        ));
        foreach ($customers as $index => $customer) {
            $created = 0;
            for ($slot = 1; $slot <= $perCustomer; ++$slot) {
                $date = SeedData::date($slot, $index);
                $created += (int) $this->orders->seedSlot($customer, $productId, $slot, $date);
            }
            $this->data->report($customer['id'], $created, $perCustomer - $created);
        }
    }

    /**
     * @param list<array{id: string, channel: string, host: string}> $customers
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    private function newSlots(array $customers, int $perCustomer): int
    {
        $missing = 0;
        foreach ($customers as $customer) {
            for ($slot = 1; $slot <= $perCustomer; ++$slot) {
                $marker = SeedData::marker($customer['id'], $slot);
                if (
                    $this->data->order($marker, $customer['id']) === null
                    && $this->data->quote($marker, $customer['id']) === null
                ) {
                    ++$missing;
                }
            }
        }
        return $missing;
    }
}
