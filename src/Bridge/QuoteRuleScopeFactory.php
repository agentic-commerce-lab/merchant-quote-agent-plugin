<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
    ) {}

    /** @throws RuleScopeUnavailable */
    public function forQuote(string $quoteId, Context $context): CartRuleScope
    {
        $criteria = new Criteria([$quoteId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');

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
}
