<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;

/**
 * Regression coverage: a buyer driving the complete UCP flow without sending
 * any A2CN acts reaches the expected quote states without leaking any
 * a2cn_act_* keys onto the quote's customFields.
 */
final class BuyerQuoteNoA2cnTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testCompleteBuyerFlowWithoutA2cnActsLeavesNoA2cnActKeys(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
        $merchantGateway = static::gateway();

        $snapshot = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1]],
            null,
        );
        self::assertSame('open', $snapshot->state);
        self::assertNotNull($snapshot->totalNet);
        $merchantGateway->updateQuote($snapshot->id, new QuoteUpdate(expiresAt: new \DateTimeImmutable('+14 days')));
        $merchantGateway->transition($snapshot->id, QuoteTransition::Sent);
        self::assertSame('replied', $this->buyerGateway()->getQuote($context, $snapshot->id)->state);

        $countered = $this->buyerGateway()->counterQuote($context, $snapshot->id, [], 'Can we do 5% off?');
        self::assertSame('change_requested', $countered->state);
        self::assertSame($snapshot->totalNet, $countered->totalNet);

        $merchantGateway->transition($snapshot->id, QuoteTransition::Process);
        $merchantGateway->transition($snapshot->id, QuoteTransition::Sent);

        $accepted = $this->buyerGateway()->acceptQuote($context, $snapshot->id);
        self::assertSame('accepted', $accepted->state);
        self::assertSame($snapshot->totalNet, $accepted->totalNet);

        $quote = $merchantGateway->fetchSnapshot($snapshot->id);
        foreach (array_keys($quote->lifecycle->customFields) as $key) {
            self::assertStringStartsNotWith(
                'a2cn_act_',
                $key,
                'Plain UCP flow leaked an A2CN act key onto customFields',
            );
        }
    }
}
