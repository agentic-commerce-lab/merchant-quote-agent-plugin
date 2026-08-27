<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\UnsupportedProductException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Refuses variant products before they reach SwagCommercial.
 *
 * QuoteManipulation::addProducts() segfaults on this shop for any product with a
 * parent (a variant) or with children (a variant parent) — reproduced per
 * product id, not xdebug, not a PHP stack overflow, not a memory limit. A
 * segfault gives PHP no chance to throw, so without this guard a servicing call
 * sees a dropped connection rather than an error. This turns it into a typed
 * exception at the boundary. Remove once the upstream crash is understood.
 *
 * An unknown product id is deliberately passed through: the inner adder's own
 * validateProducts() rejects it cleanly and tests pin that behaviour.
 */
final readonly class VariantRejectingProductAdder implements QuoteProductAdderInterface
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $productRepository */
    public function __construct(
        private QuoteProductAdderInterface $inner,
        private EntityRepository $productRepository,
    ) {}

    /** @throws UnsupportedProductException */
    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity, Context $context): void
    {
        $product = $this->productRepository->search(new Criteria([$productId]), $context)->first();

        if ($product !== null && ($product->get('parentId') !== null || (int) $product->get('childCount') > 0)) {
            throw UnsupportedProductException::variant($productId);
        }

        $this->inner->addProduct($quoteId, $productId, $quantity, $context);
    }
}
