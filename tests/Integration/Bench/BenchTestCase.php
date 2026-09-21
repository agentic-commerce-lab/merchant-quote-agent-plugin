<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MerchantQuoteAgentPlugin\Tests\Integration\ShopServices;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * A live-shop test case whose writes COMMIT.
 *
 * Deliberately does not use DatabaseTransactionBehaviour, which every other
 * integration test here does use. The bench's whole output is the decision
 * records it leaves behind for the admin to group; rolling them back would
 * make the run pass and produce nothing.
 *
 * The cost of that decision is that a bench run is not self-cleaning. That is
 * accepted: the spec chose not to isolate bench data, because the target is a
 * test shop.
 */
abstract class BenchTestCase extends TestCase
{
    use KernelTestBehaviour;
    use ShopServices;

    /**
     * A second DBAL connection, built from the same parameters as the
     * container's, so a read through it cannot see anything still open in
     * the container connection's own transaction (there is none here, since
     * this base class skips DatabaseTransactionBehaviour — but tests use this
     * to prove a write is genuinely durable, not merely visible within the
     * same connection).
     */
    protected static function freshConnection(): Connection
    {
        return DriverManager::getConnection(self::connection(static::getContainer())->getParams());
    }
}
