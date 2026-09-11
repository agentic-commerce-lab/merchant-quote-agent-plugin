<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Pins the one platform assumption in A2CN session lookup: Shopware's DAL
 * supports filtering quotes by a JSON path inside `customFields`
 * (`customFields.a2cn_session`), which is how SessionQuoteLocator resolves
 * the quote for the first inbound act before any mirror row exists.
 */
final class A2cnSessionLookupTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testSessionLookupFindsQuoteByCustomField(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $snapshot = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1]],
            null,
        );
        $quoteId = $snapshot->id;
        $sessionId = SessionId::forQuote($quoteId);

        static::gateway()
            ->updateQuote($quoteId, new QuoteUpdate(customFields: [
                ActKey::SESSION_KEY => $sessionId,
            ]));

        $locator = static::getContainer()->get(SessionQuoteLocator::class);
        self::assertInstanceOf(SessionQuoteLocator::class, $locator);

        self::assertSame($quoteId, $locator->quoteIdFor($sessionId));
        self::assertNull($locator->quoteIdFor(SessionId::forQuote(Uuid::randomHex())));
    }
}
