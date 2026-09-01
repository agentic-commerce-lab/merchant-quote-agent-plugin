<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The buyer path end to end through SwagCommercial's Store API routes, in a
 * real customer's sales-channel context: request a quote, read it back, list
 * it. Runs in a rolled-back transaction like every integration test here, so
 * the quotes it creates do not accumulate.
 */
final class BuyerQuoteFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testItRequestsAQuoteAndReadsItBack(): void
    {
        $context = $this->buyerContext();
        $productId = QuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());

        $snapshot = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 3, 'requested_unit_price' => 9.99]],
            'Three of these, please.',
        );

        self::assertNotSame('', $snapshot->id);
        self::assertSame('open', $snapshot->state);
        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(9.99, $snapshot->lineItems[0]['requested_unit_price']);

        $reread = $this->buyerGateway()->getQuote($context, $snapshot->id);

        self::assertSame($snapshot->id, $reread->id);
        self::assertNotSame([], $reread->comments);
    }

    public function testItListsOnlyTheAuthenticatedCustomersQuotes(): void
    {
        $context = $this->buyerContext();
        $productId = QuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());

        $created = $this->buyerGateway()->requestQuote($context, [['product_id' => $productId, 'quantity' => 1]], null);
        $list = $this->buyerGateway()->listQuotes($context, 25, 1);

        $ids = array_map(static fn(QuoteSnapshot $quote): string => $quote->id, $list->quotes);

        self::assertContains($created->id, $ids);
        self::assertLessThanOrEqual(25, $list->limit);
    }

    public function testAnUnknownQuoteIdIsNotFound(): void
    {
        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->buyerGateway()->getQuote($this->buyerContext(), \Shopware\Core\Framework\Uuid\Uuid::randomHex());
    }

    /**
     * The trust boundary: a real quote that belongs to somebody else must be
     * indistinguishable from one that does not exist, so a probing agent
     * cannot confirm existence.
     */
    public function testAnotherCustomersQuoteIsNotFound(): void
    {
        $context = $this->buyerContext();
        $foreignQuoteId = QuoteFixture::anyQuoteIdNotOwnedBy(
            static::getContainer(),
            $context->getCustomer()?->getId() ?? '',
        );

        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->buyerGateway()->getQuote($context, $foreignQuoteId);
    }

    public function testACustomerWithoutTheQuoteFeatureIsToldWhichFlagIsMissing(): void
    {
        $context = $this->buyerContextWithoutQuoteFeature();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/QUOTE_MANAGEMENT/');

        $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => \Shopware\Core\Framework\Uuid\Uuid::randomHex(), 'quantity' => 1]],
            null,
        );
    }

    private function buyerGateway(): BuyerQuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        return $gateway;
    }

    private function buyerContext(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        return $this->contextFor(QuoteFixture::anyQuoteCapableCustomerId(static::getContainer()));
    }

    private function buyerContextWithoutQuoteFeature(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        return $this->contextFor(QuoteFixture::anyCustomerWithoutQuoteFeature(static::getContainer()));
    }

    private function contextFor(string $customerId): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        $resolver = static::getContainer()->get(SalesChannelContextResolver::class);
        self::assertInstanceOf(SalesChannelContextResolver::class, $resolver);

        return $resolver->resolveForCustomer(
            $customerId,
            new RequestContext(QuoteFixture::storefrontHost(static::getContainer())),
        );
    }
}
