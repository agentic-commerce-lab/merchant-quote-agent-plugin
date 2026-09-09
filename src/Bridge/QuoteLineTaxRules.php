<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Reads the tax facts a quote line already carries, so a write can echo them
 * instead of inventing a rate: the rules a reprice repeats, and the net ratio a
 * mirrored requested price is converted back through.
 *
 * Load-bearing since QuoteLineItemWriter writes `isCalculated => false`: the
 * rate now drives the net→gross conversion Shopware stores, so a wrong rate is
 * a wrong stored price rather than only a wrong VAT display. A 7% product
 * repriced against an assumed 19% would overcharge the buyer.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10); the capability guard added to
 * requestedPriceRow() (no `requestedPrice` fragment on a backend without the
 * column) pushed this over it. That guard cannot move to QuoteLineItemWriter
 * instead — see requestedPriceRow()'s own docblock — because that class is
 * already at the same ceiling for the same reason.
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
        private CommercialCapabilities $capabilities,
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

    /**
     * Each line's own net ratio, read the way QuoteLineNet reads it so that a
     * write inverts exactly what the read applied — subtracting the line's
     * `calculatedTaxes` rather than dividing by a rate, which is what keeps a
     * mixed-rate line exact.
     *
     * A line missing from the result is a line the caller must not write a
     * requested price to: no ratio means no conversion, and assuming 1.0 would
     * store a gross quote's ask below the one the buyer made.
     *
     * @param list<string> $lineItemIds
     *
     * @return array<string, float>
     */
    public function netRatiosFor(array $lineItemIds, Context $context): array
    {
        if ($lineItemIds === []) {
            return [];
        }

        $criteria = new Criteria($lineItemIds);
        $criteria->addAssociation('quote');
        $ratios = [];

        foreach ($this->lineItemRepository->search($criteria, $context)->getEntities() as $lineItem) {
            $quote = $lineItem->get('quote');

            if (!$quote instanceof Entity) {
                continue;
            }

            $ratios[(string) $lineItem->get('id')] = QuoteLineNet::of(
                $lineItem,
                (string) $quote->get('taxStatus'),
                $this->capabilities,
            )->netRatio;
        }

        return $ratios;
    }

    /**
     * The buyer's mirrored ask as a row fragment, converted out of net and
     * into the quote's own tax space — the exact inverse of what QuoteLineNet
     * applied on the way in.
     *
     * No ratio, no fragment: see netRatiosFor(). The rest of the row still
     * stands, because a reprice must not be lost over a display-only field
     * that could not be converted. Here rather than on QuoteLineItemWriter,
     * which is at the gate's class-complexity ceiling — and this is where the
     * ratio that drives the conversion is read anyway.
     *
     * Instance rather than static so it can read `$this->capabilities`: a
     * released SwagCommercial has no `quote_line_item.requestedPrice` column
     * at all, and the DAL rejects an unknown field outright rather than
     * ignoring it, so this must return [] there — not a null-valued entry —
     * regardless of what the net/ratio inputs are.
     *
     * @return array<string, float>
     */
    public function requestedPriceRow(?float $net, ?float $netRatio): array
    {
        if (!$this->capabilities->lineItemAsks) {
            return [];
        }

        return $net === null || $netRatio === null ? [] : ['requestedPrice' => round($net / $netRatio, precision: 2)];
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
