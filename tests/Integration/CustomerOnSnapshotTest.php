<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The quote's customer is the COMPANY account: SwagCommercial's b2b_employee
 * carries no customer row of its own, only business_partner_customer_id. This
 * pins that the snapshot carries it, because every history read is scoped by it
 * and an empty id silently means "no history".
 */
final class CustomerOnSnapshotTest extends IntegrationTestCase
{
    public function testTheSnapshotCarriesTheQuotesCustomer(): void
    {
        $context = AgentContext::create();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $snapshot = self::gateway()->fetchSnapshot($quoteId);

        self::assertNotSame('', $snapshot->identity->customerId, 'customer_id is Required on QuoteDefinition.');
        self::assertTrue(Uuid::isValid($snapshot->identity->customerId));
    }
}
