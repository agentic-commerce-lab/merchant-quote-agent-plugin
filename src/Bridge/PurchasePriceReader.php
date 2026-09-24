<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;

/**
 * Reads `product.purchasePrices` for the minimum-margin floor.
 *
 * Inheritance is enabled because the field is `Inherited`: a variant usually
 * carries no purchase price of its own. `PriceCollection::getCurrencyPrice()`
 * falls back to the default-currency price UNCONVERTED, so a fallback is
 * multiplied by the quote currency's factor here.
 */
final readonly class PurchasePriceReader implements PurchasePricesInterface
{
    /**
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $products
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $currencies
     */
    public function __construct(
        private EntityRepository $products,
        private EntityRepository $currencies,
    ) {}

    #[\Override]
    public function netUnitPrices(array $productIds, string $currencyIso): array
    {
        // Custom lines carry a referencedId that is no product id; a Criteria
        // built from one would throw rather than find nothing.
        $ids = array_values(array_unique(array_filter($productIds, Uuid::isValid(...))));
        $context = Context::createDefaultContext();
        $currency = $ids === [] ? null : $this->currency($currencyIso, $context);

        if ($currency === null) {
            return [];
        }

        $products = $context->enableInheritance(
            fn(Context $inheriting) => $this->products->search(new Criteria($ids), $inheriting)->getEntities(),
        );

        $prices = [];
        foreach ($products as $product) {
            $price = $product instanceof ProductEntity
                ? $product->getPurchasePrices()?->getCurrencyPrice($currency->getId())
                : null;

            if (!$product instanceof ProductEntity || $price === null) {
                continue;
            }

            $prices[$product->getId()] = $price->getCurrencyId() === $currency->getId()
                ? $price->getNet()
                : $price->getNet() * $currency->getFactor();
        }

        return $prices;
    }

    private function currency(string $iso, Context $context): ?CurrencyEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('isoCode', $iso))
            ->setLimit(1);
        $currency = $this->currencies->search($criteria, $context)->getEntities()->first();

        return $currency instanceof CurrencyEntity ? $currency : null;
    }
}
