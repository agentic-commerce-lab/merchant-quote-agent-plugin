<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\UnsupportedProductException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * The guard in front of QuoteManipulation::addProducts(). These tests are
 * load-bearing by construction: if the guard did not fire, the call would reach
 * SwagCommercial and the PHP process would exit 139 — a failed run, not a pass.
 */
final class AddVariantProductTest extends IntegrationTestCase
{
    public function testAVariantIsRefusedBeforeItReachesSwagCommercial(): void
    {
        $this->assertRefusedAndQuoteUntouched($this->firstProductMatching(
            new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('parentId', null)]),
        ));
    }

    public function testAVariantParentIsRefusedBeforeItReachesSwagCommercial(): void
    {
        $this->assertRefusedAndQuoteUntouched($this->firstProductMatching(new RangeFilter('childCount', [
            RangeFilter::GT => 0,
        ])));
    }

    private function assertRefusedAndQuoteUntouched(string $productId): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $gateway->fetchSnapshot($quoteId);

        $caught = null;
        try {
            $gateway->addProduct($quoteId, $productId, 1);
        } catch (UnsupportedProductException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'A variant product reached addProduct without being refused.');
        self::assertStringContainsString($productId, $caught->getMessage());
        self::assertCount(
            count($before->content->lines),
            $gateway->fetchSnapshot($quoteId)->content->lines,
            'The refused addProduct still changed the quote.',
        );
    }

    private function firstProductMatching(EqualsFilter|NotFilter|RangeFilter $filter): string
    {
        $criteria = (new Criteria())
            ->addFilter($filter)
            ->addSorting(new FieldSorting('productNumber'))
            ->setLimit(1);
        $id = static::getContainer()
            ->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext())
            ->firstId();

        self::assertIsString(
            $id,
            'The shop has no product matching the filter, so this guard cannot be exercised here.',
        );

        return $id;
    }
}
