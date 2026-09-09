<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Tax-mode finding (spike deliverable, Task 5 Step 6, revised in fix round 1):
 * a reprice write round-trips through recalculate() unchanged, with no
 * divide/multiply by ~1.19, so the two-pass measure-and-rewrite correction the
 * old TypeScript implementation needed is not required here. But a stable
 * round trip is not the same as a net one: `price.unitPrice` is a GROSS field,
 * and the round trip was stable only because both ends were gross —
 * `isCalculated => true` made GrossPriceCalculator::getUnitPrice()
 * short-circuit and store the written number verbatim.
 *
 * The writer now sets `isCalculated => false`, so the gross calculator
 * converts net→gross on the way in, and the reader subtracts each line's
 * `calculatedTaxes` on the way out. `unitPriceNet` is therefore genuinely net
 * at both ends; `testARepricedLineIsStoredGrossedUp` is what proves the write
 * side, and `FetchSnapshotTest::testSummedLineNetsReconcileWithTheQuoteTotal`
 * the read side.
 */
final class UpdateLineItemsTest extends IntegrationTestCase
{
    /**
     * THE load-bearing test. A repriced line is only honoured if it also
     * carries customFields['quote_custom_offer_price'] === true; without it,
     * recalculate() re-prices from the catalog and silently discards the
     * priceDefinition we wrote.
     */
    public function testRepricedLineSurvivesRecalculate(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId);
        $line = $this->firstProductLine($before->content->lines);

        $target = round($line->unitPriceNet * 0.9, precision: 2);
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: $target),
        ]);

        $gateway->recalculate($quoteId);

        $sameLine = $this->lineIn($gateway->fetchSnapshot($quoteId), $line->identity->lineItemId);

        self::assertEqualsWithDelta(
            $target,
            $sameLine->unitPriceNet,
            0.01,
            'Price did not survive recalculate — the custom-price flag is missing or ineffective.',
        );
    }

    /**
     * Write-side proof that `unitPriceNet` goes in as a net price. With
     * `isCalculated => false` the gross calculator runs `calculateGross()` on
     * it, so what Shopware stores in `price.unitPrice` — a gross field — has
     * to be the written net grossed up by the line's own tax rate. If the
     * writer ever went back to storing gross, the stored value would equal the
     * written number instead, and this fails by the VAT rate.
     *
     * Requires a GROSS quote: `QuoteFixture::anyQuoteId()` picks whichever
     * editable quote sorts first, with no control over tax mode, and this
     * shop's quotes are not all gross. Skipped rather than weakened when the
     * picked quote is net — `LegacyGrossQuoteTest` proves the same gross-up on
     * a quote it creates specifically to be gross.
     */
    public function testARepricedLineIsStoredGrossedUp(): void
    {
        $context = Context::createDefaultContext();
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
        $quoteRepository = static::getContainer()->get('quote.repository');
        $quote = $quoteRepository
            ->search(new Criteria([$quoteId]), $context)
            ->getEntities()
            ->first();
        self::assertNotNull($quote, 'The quote under test disappeared.');

        $taxStatus = $quote->get('taxStatus');

        if ($taxStatus !== CartPrice::TAX_STATE_GROSS) {
            self::markTestSkipped(sprintf(
                'The fixture quote\'s taxStatus is "%s", not "gross" — this assertion only holds in gross '
                . 'mode. See LegacyGrossQuoteTest, which proves the same gross-up on a quote it creates '
                . 'specifically to be gross.',
                $taxStatus,
            ));
        }

        $line = $this->firstProductLine($gateway->fetchSnapshot($quoteId)->content->lines);
        $rules = $this->storedPrice($line->identity->lineItemId, $context)->getTaxRules();
        self::assertCount(1, $rules, 'This assertion assumes a single tax rule on the fixture line.');
        $rate = $rules->first()?->getTaxRate() ?? 0.0;
        self::assertGreaterThan(0.0, $rate, 'The fixture line is tax-free, so it cannot show a gross-up.');

        $target = round($line->unitPriceNet * 0.9, precision: 2);
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: $target),
        ]);
        $gateway->recalculate($quoteId);

        self::assertEqualsWithDelta(
            round($target * (1 + ($rate / 100)), precision: 2),
            $this->storedPrice($line->identity->lineItemId, $context)->getUnitPrice(),
            0.01,
            'Stored price.unitPrice is not the written net grossed up — the write path stored gross.',
        );
    }

    /**
     * The mirror, end to end against a real quote: a net ask goes in, lands in
     * the quote's own tax space (which is what both UIs render), and comes
     * back out net — hidden while the marker says the agent wrote it, visible
     * the moment it does not.
     *
     * The unit tests pin each half of the conversion against a hand-built 19%
     * gross fixture; this is the one that would catch the shop disagreeing
     * with that fixture, which is exactly what `requestedPrice`'s tax space
     * was wrong about before QuoteLineNet measured it.
     */
    public function testAMirroredRequestedPriceRoundTripsAndIsHiddenFromTheAgent(): void
    {
        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        if (!$capabilities->lineItemAsks) {
            self::markTestSkipped(
                'This shop has no quote_line_item.requestedPrice column '
                . '(CommercialCapabilities::$lineItemAsks is false); the mirror is a no-op there.',
            );
        }

        $context = Context::createDefaultContext();
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $line = $this->firstProductLine($gateway->fetchSnapshot($quoteId)->content->lines);
        $lineItemId = $line->identity->lineItemId;
        $ask = round($line->unitPriceNet * 0.8, precision: 2);

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: MirroredAsks::stamp([], [$lineItemId => $ask])));
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $lineItemId, requestedUnitPriceNet: $ask),
        ]);

        // Derived from the line's OWN tax, not asserted as "more than the net
        // ask": on a 0%-tax line the correct stored value IS the net ask, and
        // a `>=` assertion would pass even with the conversion deleted. This
        // form fails if the ratio is applied the wrong way round, or twice.
        $rate = $this->taxRateOf($lineItemId, $context);
        $stored = $this->storedLineItem($lineItemId, $context)->get('requestedPrice');
        self::assertIsNumeric($stored, 'The mirrored ask never reached the line.');
        self::assertEqualsWithDelta(
            round($ask * (1 + ($rate / 100)), precision: 2),
            (float) $stored,
            0.01,
            'The stored requested price is not the net ask in the quote\'s tax space.',
        );

        self::assertNull(
            $this->lineIn($gateway->fetchSnapshot($quoteId), $lineItemId)->requestedUnitPrice,
            'The agent read its own mirrored ask back as if the buyer had made it.',
        );

        // Marker cleared, nothing else touched: the same stored number now
        // reads as a buyer ask, and it reads back as the NET one that went in.
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [MirroredAsks::KEY => null]));

        self::assertEqualsWithDelta(
            $ask,
            $this->lineIn($gateway->fetchSnapshot($quoteId), $lineItemId)->requestedUnitPrice,
            0.01,
            'The stored ask did not convert back to the net number that was written.',
        );

        // Said out loud rather than passed quietly. Everything above holds on
        // a tax-free line, but the net<->tax-space conversion is an IDENTITY
        // there, so a green run on such a shop has not exercised it — which is
        // the one thing this test exists to add over the unit fixtures. Every
        // product line on `agenticquote` is 0% (11/11 on 2026-09-09), so this
        // is the normal outcome there, not an edge case.
        if ($rate === 0.0) {
            self::markTestIncomplete(
                'The mirror round trip and the marker are verified, but this quote line is '
                . 'tax-free, so the net->tax-space conversion ran as an identity and is NOT '
                . 'verified here. Needs a shop with a taxed quote line.',
            );
        }
    }

    /** The highest tax rate on a line's stored `price`, 0.0 on a tax-free line. */
    private function taxRateOf(string $lineItemId, Context $context): float
    {
        $rate = 0.0;

        foreach ($this->storedPrice($lineItemId, $context)->getTaxRules() as $rule) {
            $rate = max($rate, $rule->getTaxRate());
        }

        return $rate;
    }

    public function testQuantityChangeApplies(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $line = $this->firstProductLine($gateway->fetchSnapshot($quoteId)->content->lines);

        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, quantity: $line->quantity + 1),
        ]);

        $after = $this->lineIn($gateway->fetchSnapshot($quoteId), $line->identity->lineItemId);

        self::assertSame($line->quantity + 1, $after->quantity);
    }

    public function testRemovalSoftDeletesTheLine(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $lines = $gateway->fetchSnapshot($quoteId)->content->lines;
        $victim = $this->firstProductLine($lines)->identity->lineItemId;

        $gateway->updateLineItems($quoteId, [new QuoteLineItemChange(lineItemId: $victim, remove: true)]);

        foreach ($gateway->fetchSnapshot($quoteId)->content->lines as $line) {
            self::assertNotSame($victim, $line->identity->lineItemId, 'Removed line still reads back.');
        }
    }

    /** @throws \PHPUnit\Framework\AssertionFailedError when the line is gone */
    private function lineIn(QuoteSnapshot $snapshot, string $lineItemId): QuoteLineSnapshot
    {
        foreach ($snapshot->content->lines as $line) {
            if ($line->identity->lineItemId === $lineItemId) {
                return $line;
            }
        }

        self::fail('The line under test disappeared from the snapshot.');
    }

    /** Raw stored `price` of a line item, in Shopware's own gross space. */
    private function storedPrice(string $lineItemId, Context $context): CalculatedPrice
    {
        $price = $this->storedLineItem($lineItemId, $context)->get('price');
        self::assertInstanceOf(CalculatedPrice::class, $price);

        return $price;
    }

    private function storedLineItem(string $lineItemId, Context $context): Entity
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote_line_item.repository');
        $lineItem = $repository
            ->search(new Criteria([$lineItemId]), $context)
            ->getEntities()
            ->first();
        self::assertInstanceOf(Entity::class, $lineItem, 'The line item under test disappeared.');

        return $lineItem;
    }

    /**
     * Picks the first PRODUCT line, never a Shopware-generated
     * quote-discount line: discount lines have a null productId and a
     * negative price, and recalculate() regenerates them from scratch, so
     * repricing one would fail for a reason unrelated to the custom-price
     * flag under test.
     *
     * @param list<QuoteLineSnapshot> $lines
     */
    private function firstProductLine(array $lines): QuoteLineSnapshot
    {
        foreach ($lines as $line) {
            if ($line->identity->productId !== null) {
                return $line;
            }
        }

        self::fail('The fixture quote has no product line item (only generated discount lines).');
    }
}
