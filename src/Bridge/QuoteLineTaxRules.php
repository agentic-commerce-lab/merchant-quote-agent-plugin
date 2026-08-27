<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Reads the tax rules a quote line already carries, so a reprice can echo them
 * instead of inventing a rate.
 *
 * Load-bearing since QuoteLineItemWriter writes `isCalculated => false`: the
 * rate now drives the net→gross conversion Shopware stores, so a wrong rate is
 * a wrong stored price rather than only a wrong VAT display. A 7% product
 * repriced against an assumed 19% would overcharge the buyer.
 */
final readonly class QuoteLineTaxRules
{
    /**
     * ponytail: only reached if a line's stored `price` carries no tax rules,
     * which QuoteLineItemDefinition's Required CalculatedPriceField forbids.
     *
     * @var list<array{taxRate: float, percentage: float}>
     */
    public const FALLBACK = [['taxRate' => 19.0, 'percentage' => 100.0]];

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
    public function __construct(
        private EntityRepository $lineItemRepository,
    ) {}

    /**
     * @param list<string> $lineItemIds
     *
     * @return array<string, list<array{taxRate: float, percentage: float}>>
     */
    public function forLines(array $lineItemIds, Context $context): array
    {
        if ($lineItemIds === []) {
            return [];
        }

        $rules = [];

        foreach ($this->lineItemRepository->search(new Criteria($lineItemIds), $context)->getEntities() as $lineItem) {
            $price = $lineItem->get('price');

            if (!$price instanceof CalculatedPrice) {
                continue;
            }

            $rules[(string) $lineItem->get('id')] = $this->asPayload($price);
        }

        return $rules;
    }

    /** @return list<array{taxRate: float, percentage: float}> */
    private function asPayload(CalculatedPrice $price): array
    {
        $rules = [];

        foreach ($price->getTaxRules() as $rule) {
            $rules[] = ['taxRate' => $rule->getTaxRate(), 'percentage' => $rule->getPercentage()];
        }

        return $rules === [] ? self::FALLBACK : $rules;
    }
}
