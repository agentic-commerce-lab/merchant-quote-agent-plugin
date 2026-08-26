<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

final class HarnessSmokeTest extends IntegrationTestCase
{
    public function testCommercialQuoteServicesAreResolvable(): void
    {
        self::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation');
        self::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter');
        self::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Recalculation\QuoteCalculator');
        self::commercialService(
            'Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer',
        );
    }

    public function testServiceLevelLicenseToggleIsActive(): void
    {
        /** @var callable(string): (string|bool|int) $get */
        $get = ['Shopware\Commercial\Licensing\License', 'get'];

        self::assertNotFalse(
            $get('QUOTE_MANAGEMENT-6302947'),
            'The service-level toggle is what every commercial quote service checks on entry.',
        );
    }
}
