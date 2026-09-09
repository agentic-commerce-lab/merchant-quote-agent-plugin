<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineItemWriter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * `requested_price` is stored in the QUOTE's tax space, while everything above
 * the bridge is net — so the buyer's mirrored ask is converted on the way out
 * by the exact inverse of the ratio QuoteLineNet reads it back with. Writing
 * the net number verbatim into a gross quote would store an ask ~19% below the
 * one the buyer made, and the read path would then scale it down a second time.
 */
final class QuoteLineItemWriterTest extends TestCase
{
    public function testARequestedPriceIsWrittenInTheQuotesOwnTaxSpace(): void
    {
        $payload = $this->writeAndCapture([new QuoteLineItemChange(
            lineItemId: 'line-1',
            requestedUnitPriceNet: 80.0,
        )], taxStatus: 'gross');

        self::assertSame([['id' => 'line-1', 'requestedPrice' => 95.2]], $payload);
    }

    public function testARequestedPriceOnANetQuoteIsWrittenVerbatim(): void
    {
        $payload = $this->writeAndCapture([new QuoteLineItemChange(
            lineItemId: 'line-1',
            requestedUnitPriceNet: 80.0,
        )], taxStatus: 'net');

        self::assertSame([['id' => 'line-1', 'requestedPrice' => 80.0]], $payload);
    }

    public function testAnAskAndAResultingPriceTravelInOneRow(): void
    {
        $payload = $this->writeAndCapture([new QuoteLineItemChange(
            lineItemId: 'line-1',
            unitPriceNet: 90.0,
            requestedUnitPriceNet: 80.0,
        )], taxStatus: 'gross');

        self::assertCount(1, $payload);
        self::assertSame(95.2, $payload[0]['requestedPrice']);
        self::assertSame(90.0, $payload[0]['priceDefinition']['price']);
    }

    /**
     * A removal wins over everything else on the row, as it already does for a
     * reprice: an ask on a line that is going away is not worth displaying.
     */
    public function testARemovalDoesNotCarryARequestedPrice(): void
    {
        $payload = $this->writeAndCapture([new QuoteLineItemChange(
            lineItemId: 'line-1',
            requestedUnitPriceNet: 80.0,
            remove: true,
        )], taxStatus: 'gross');

        self::assertCount(1, $payload);
        self::assertArrayNotHasKey('requestedPrice', $payload[0]);
    }

    /**
     * Showing nothing beats showing a price nobody asked for: without the
     * line's own ratio the conversion cannot be made, and guessing 1.0 would
     * store a gross quote's ask ~19% low.
     */
    public function testALineWhoseRatioCannotBeReadIsLeftAlone(): void
    {
        $payload = $this->writeAndCapture([new QuoteLineItemChange(
            lineItemId: 'line-unknown',
            requestedUnitPriceNet: 80.0,
        )], taxStatus: 'gross');

        self::assertSame([], $payload);
    }

    /**
     * @param list<QuoteLineItemChange> $changes
     *
     * @return list<array<string, mixed>>
     */
    private function writeAndCapture(array $changes, string $taxStatus): array
    {
        $captured = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->searchResult($taxStatus));
        $repository
            ->method('update')
            ->willReturnCallback(static function (array $payload, Context $context) use (
                &$captured,
            ): EntityWrittenContainerEvent {
                $captured = $payload;

                return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
            });

        (new QuoteLineItemWriter($repository))->write($changes, Context::createDefaultContext());

        /** @var list<array<string, mixed>> $captured */
        return $captured;
    }

    /** A single 119.00 gross line at 19%, i.e. 100.00 net — netRatio 100/119. */
    private function searchResult(string $taxStatus): EntitySearchResult
    {
        $line = new ArrayEntity([
            'id' => 'line-1',
            'quantity' => 1,
            'totalPrice' => 119.0,
            'requestedPrice' => null,
            'price' => new CalculatedPrice(
                119.0,
                119.0,
                new CalculatedTaxCollection([new CalculatedTax(19.0, 19.0, 119.0)]),
                new TaxRuleCollection([new TaxRule(19.0)]),
            ),
            'quote' => new ArrayEntity(['id' => 'quote-1', 'taxStatus' => $taxStatus]),
        ]);

        return new EntitySearchResult(
            'quote_line_item',
            1,
            new EntityCollection([$line]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );
    }
}
