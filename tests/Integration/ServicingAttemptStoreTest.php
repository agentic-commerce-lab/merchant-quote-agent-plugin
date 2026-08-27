<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\Attempt\DalServicingAttemptStore;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

final class ServicingAttemptStoreTest extends IntegrationTestCase
{
    public function testDeliveryAttemptsAreIncrementedAndResetAfterCompletion(): void
    {
        /** @var EntityRepository<ServicingAttemptCollection> $repository */
        $repository = static::getContainer()->get('merchant_quote_agent_servicing_attempt.repository');
        $store = new DalServicingAttemptStore($repository);
        $messageId = Uuid::randomHex();

        self::assertSame(1, $store->recordDelivery($messageId));
        self::assertSame(2, $store->recordDelivery($messageId));

        $store->completeDelivery($messageId);

        self::assertSame(1, $store->recordDelivery($messageId));
    }
}
