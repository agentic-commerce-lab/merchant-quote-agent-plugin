<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * `addProduct` is the second call that goes through a SwagCommercial service
 * rather than the DAL (QuoteManipulation::addProduct, @internal). It does far
 * more than insert a row: it restores a SalesChannelContext, converts the
 * quote to a cart, adds a cart line, re-runs the cart processor and writes the
 * whole recalculated cart back — so the assertions here check the quote
 * aggregate, not just the line count.
 *
 * `recalculate()` was implemented in Task 5, not here (see the ledger); its
 * price round trip is covered by UpdateLineItemsTest. What this file adds is
 * the one property that test cannot show: that recalculate() actually
 * recomputes the quote-level total from its lines.
 */
final class AddProductAndRecalculateTest extends IntegrationTestCase
{
    private const ADDED_QUANTITY = 2;

    public function testAddingAProductAddsOneLineForThatProduct(): void
    {
        $context = Context::createDefaultContext();
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $before = $gateway->fetchSnapshot($quoteId);
        $productId = $this->quotableProductAbsentFrom($before, $context);

        $gateway->addProduct($quoteId, $productId, self::ADDED_QUANTITY);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertCount(
            \count($before->content->lines) + 1,
            $after->content->lines,
            'addProduct did not add exactly one line.',
        );

        $added = null;
        foreach ($after->content->lines as $line) {
            if ($line->identity->productId !== $productId) {
                continue;
            }

            $added = $line;
        }

        self::assertNotNull($added, 'The added line does not reference the product that was added.');
        self::assertSame(self::ADDED_QUANTITY, $added->quantity, 'The added line ignored the requested quantity.');
    }

    /**
     * The assertion the brief's `assertGreaterThanOrEqual(0.0, $totals->totalNet)`
     * should have been: adding a positively-priced line must move the quote
     * aggregate, and the aggregate must still reconcile with the lines
     * afterwards. Both fail if QuoteManipulation writes the line but the quote
     * total is left stale, and the second also fails if the added line's price
     * lands in the wrong tax space (gross line against a net total).
     */
    public function testAddingAProductRaisesTheQuoteTotalAndStillReconciles(): void
    {
        $context = Context::createDefaultContext();
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $before = $gateway->fetchSnapshot($quoteId);
        $productId = $this->quotableProductAbsentFrom($before, $context);

        $gateway->addProduct($quoteId, $productId, self::ADDED_QUANTITY);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertGreaterThan(
            $before->totals->totalNet,
            $after->totals->totalNet,
            'The quote total did not grow after a product was added.',
        );
        self::assertEqualsWithDelta(
            $this->sumOfLineNets($after->content->lines),
            $after->totals->totalNet,
            0.01,
            'The quote total no longer reconciles with its lines after addProduct.',
        );
    }

    /**
     * QuoteManipulation validates product ids before touching the quote, so an
     * unknown id must be rejected without a partial write. Held as a class-name
     * string rather than an import: SwagCommercial is a runtime-only dependency
     * (ADR 0001) and its exception class is not loadable at analysis time.
     */
    public function testAddingAnUnknownProductIsRejectedWithoutTouchingTheQuote(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $gateway->fetchSnapshot($quoteId);

        $caught = null;
        try {
            $gateway->addProduct($quoteId, Uuid::randomHex(), 1);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Adding a product that does not exist was silently accepted.');
        self::assertSame(
            'Shopware\Commercial\B2B\QuoteManagement\Exception\QuoteException',
            $caught::class,
            'Expected SwagCommercial to reject the unknown product; got a different failure: ' . $caught->getMessage(),
        );

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertCount(\count($before->content->lines), $after->content->lines);
        self::assertSame($before->totals->totalNet, $after->totals->totalNet);
    }

    /**
     * `updateLineItems` writes line rows only — nothing recomputes
     * `quote.amountNet` — so a quantity change leaves the quote total stale.
     * That staleness is asserted first, because it is what makes the last two
     * assertions attributable to recalculate() rather than to the line write.
     */
    public function testRecalculateRecomputesAStaleQuoteTotal(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId);
        $line = $this->firstProductLine($before->content->lines);

        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, quantity: $line->quantity + 1),
        ]);

        self::assertSame(
            $before->totals->totalNet,
            $gateway->fetchSnapshot($quoteId)->totals->totalNet,
            'A line-only write already moved the quote total, so this test can no longer attribute '
            . 'the reconciliation below to recalculate().',
        );

        $gateway->recalculate($quoteId);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertGreaterThan(
            $before->totals->totalNet,
            $after->totals->totalNet,
            'recalculate() did not pick up the higher quantity.',
        );
        self::assertEqualsWithDelta(
            $this->sumOfLineNets($after->content->lines),
            $after->totals->totalNet,
            0.01,
            'The recalculated quote total does not reconcile with its lines.',
        );
    }

    /**
     * A product that is provably quotable in this shop — it already appears on
     * some quote line, so it is active and visible in the sales channel the
     * quotes belong to — but is not on the quote under test, so adding it
     * creates a line instead of merging into an existing one.
     *
     * `childCount = 0` restricts this to standalone products, excluding both
     * variants and variant parents. That is not cosmetic: in this shop,
     * `QuoteManipulation::addProduct` crashes the PHP process (SIGSEGV, exit
     * 139, no PHP error and no output) for any product in a variant
     * configuration — verified for the variants SWDEMO10005.1 / SWDEMO10007.1
     * and the parent SWDEMO10007, while the standalone SWDEMO10001 /
     * SWDEMO10002 / SWDEMO10006 succeed. It reproduces with xdebug disabled
     * and is not a PHP-detectable stack overflow, so it is a crash inside
     * Shopware's cart processing, not something this bridge causes or can
     * guard against.
     */
    private function quotableProductAbsentFrom(QuoteSnapshot $quote, Context $context): string
    {
        $present = [];
        foreach ($quote->content->lines as $line) {
            if ($line->identity->productId === null) {
                continue;
            }

            $present[] = $line->identity->productId;
        }

        $candidates = array_values(array_diff($this->quotedProductIds($context), $present));
        self::assertNotSame(
            [],
            $candidates,
            'Every product quoted in this shop is already on the fixture quote, so addProduct would '
            . 'merge into an existing line instead of adding one. Quote a further product first.',
        );

        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $products */
        $products = static::getContainer()->get('product.repository');
        $criteria = new Criteria($candidates);
        $criteria->addFilter(new EqualsFilter('childCount', 0));
        $criteria->addSorting(new FieldSorting('productNumber'));

        $productId = $products->searchIds($criteria, $context)->firstId();
        self::assertIsString(
            $productId,
            'No standalone product is quoted anywhere in this shop except on the fixture quote.',
        );

        return $productId;
    }

    /**
     * Product ids referenced by any quote line item, deterministically ordered.
     *
     * @return list<string>
     */
    private function quotedProductIds(Context $context): array
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote_line_item.repository');
        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('referencedId', null)]));
        $criteria->addSorting(new FieldSorting('referencedId'));

        $ids = [];
        foreach ($repository->search($criteria, $context)->getEntities() as $lineItem) {
            $productId = $lineItem->get('referencedId');

            if (\is_string($productId)) {
                $ids[] = $productId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private function sumOfLineNets(array $lines): float
    {
        $summed = 0.0;
        foreach ($lines as $line) {
            $summed += $line->totalNet;
        }

        return $summed;
    }

    /**
     * Never a Shopware-generated quote-discount line: those have a null
     * productId and a negative price, and recalculate() regenerates them.
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
