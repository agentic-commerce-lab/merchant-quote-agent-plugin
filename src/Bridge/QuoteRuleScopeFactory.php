<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * A quote, as something Shopware's rule builder can be evaluated against.
 *
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer::restoreByQuote()
 * and Shopware\Commercial\B2B\QuoteManagement\Domain\QuoteToCart\QuoteToCartConverter::convertToCart()
 * -- neither is @internal, and QuoteCalculator::recalculate() composes the two
 * exactly this way. QuoteRecalculator already depends on the first.
 *
 * Every RuleScope in core requires a SalesChannelContext, and the cart-shaped
 * conditions additionally require a Cart. Building a CartRuleScope rather than
 * a CheckoutRuleScope is what makes 106 of core's 117 conditions evaluable
 * here: core's conditions open with a scope guard that RETURNS FALSE rather
 * than throwing, so the cheaper scope would leave a merchant's cart condition
 * silently never matching. The 11 flow-only conditions stay in that trap and
 * are documented in the administration instead.
 *
 * The extra quote read is unavoidable: restoreByQuote() loads the quote
 * internally but does not hand it back, and convertToCart() throws unless
 * lineItems, transactions and deliveries are all loaded.
 *
 * QuoteToCartConverter::convertToCart() trusts the Criteria it is handed: it
 * does not itself filter out soft-deleted lines. On a shop where
 * {@see CommercialCapabilities::$softDeleteLines} is true, an unfiltered
 * `lineItems` association therefore hands it lines the buyer or the agent
 * already removed, and every line-item-scope condition (GoodsPriceRule and
 * the other 39) keeps matching against a cart that no longer exists. See
 * {@see excludeSoftDeletedLineItems()}.
 *
 * Deliberately not `final`, like StrategyResolver and unlike almost everything
 * else here: StrategyAssignmentResolverTest doubles it, and PHPUnit cannot
 * double a final class. An interface is not the alternative -- there is one
 * implementation, and a class is just as good a seam.
 */
readonly class QuoteRuleScopeFactory
{
    public function __construct(
        private object $contextRestorer,
        private object $quoteToCartConverter,
        private EntityRepository $quotes,
        private CommercialCapabilities $capabilities,
    ) {}

    /** @throws RuleScopeUnavailable */
    public function forQuote(string $quoteId, Context $context): CartRuleScope
    {
        $criteria = new Criteria([$quoteId]);
        $criteria->addAssociation('lineItems');
        $this->excludeSoftDeletedLineItems($criteria);
        $criteria->addAssociation('transactions');
        // QuoteDeliveryTransformer::transformToDeliveries() (called from inside
        // convertToCart() below) drops any delivery whose shippingMethod or
        // positions is null -- and neither association autoloads. A bare
        // `deliveries` association therefore loads deliveries with both
        // relations empty, every one gets silently dropped, and the resulting
        // cart has none. CartShippingCostRule, CartDeliveryTaxRule and every
        // other delivery-derived condition then evaluate against zero with no
        // error: exactly the "condition silently returns false" trap this
        // factory exists to avoid. Both SwagCommercial quote readers
        // (SalesChannelContextRestorer::getQuote(), QuoteCalculator::fetchQuote())
        // load these same two full paths instead of the bare association, and
        // QuoteRecalculator::restoreByQuote() -- proven to resolve on a
        // released 6.7.12.x shop too -- relies on them, so they are safe here.
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.positions.quoteLineItem');

        $quote = $this->quotes->search($criteria, $context)->first();

        if ($quote === null) {
            throw new RuleScopeUnavailable('Quote ' . $quoteId . ' was not found, so it has no rule scope.');
        }

        try {
            /** @mago-expect analysis:ambiguous-object-method-access */
            $salesChannelContext = $this->contextRestorer->restoreByQuote($quoteId, $context);
            \assert($salesChannelContext instanceof SalesChannelContext);

            /** @mago-expect analysis:ambiguous-object-method-access */
            $cart = $this->quoteToCartConverter->convertToCart($quote, $salesChannelContext);
            \assert($cart instanceof Cart);
        } catch (\Throwable $e) {
            throw new RuleScopeUnavailable(
                'Quote ' . $quoteId . ' could not be converted to a cart: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return new CartRuleScope($cart, $salesChannelContext);
    }

    /**
     * Mirrors {@see SwagCommercialBuyerQuoteGateway::excludeSoftDeletedLineItems()}
     * exactly, for the same reason: `quote_line_item.deleted_at` is a
     * trunk-only column. Released SwagCommercial (6.7.1.2-6.7.12.x) never
     * added it, and the DAL rejects a Criteria that names an unmapped field
     * with `UnmappedFieldException` rather than silently ignoring it -- so
     * filtering unconditionally would take down rule evaluation on every
     * released shop. Gated on `$capabilities->softDeleteLines` instead: where
     * the column does not exist, removal is a hard delete, so the
     * `lineItems` association can never contain a soft-deleted row and there
     * is nothing for the filter to exclude. Kept as its own method rather
     * than shared with the gateway's: the two build unrelated Criteria for
     * unrelated read paths, and the only thing in common is this one filter.
     */
    private function excludeSoftDeletedLineItems(Criteria $criteria): void
    {
        if (!$this->capabilities->softDeleteLines) {
            return;
        }

        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
    }
}
