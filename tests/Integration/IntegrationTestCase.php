<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * Base for every bridge integration test. Each test runs in a transaction that
 * is rolled back, so tests may write freely to the live shop's database.
 */
abstract class IntegrationTestCase extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;
    use ShopServices;
}
