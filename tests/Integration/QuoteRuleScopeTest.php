<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Cart\Rule\GoodsPriceRule;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The plan's whole reading of SwagCommercial, checked against a real shop.
 *
 * Every unit test in this codebase doubles `QuoteRuleScopeFactory` and
 * `CartRuleScope` -- StrategyAssignmentResolverTest's double returns a scope
 * wrapping an empty `new Cart('token')`, which is enough to prove the ladder's
 * own branching but proves nothing about whether a real quote actually
 * converts. This file is the only place that claim is checked: that
 * `SalesChannelContextRestorer::restoreByQuote()` and
 * `QuoteToCartConverter::convertToCart()` compose into a `CartRuleScope` a
 * real Shopware rule condition treats as a working cart, not as the "no
 * scope" case core's own conditions answer `false` to.
 *
 * `QuoteFixture::anyQuoteId()` is reused rather than a purpose-built fixture:
 * AddProductAndRecalculateTest already proves every quote it can return
 * converts through this exact restore-then-convert path (its own docblock:
 * `addProduct` "restores a SalesChannelContext, converts the quote to a
 * cart"), which is also the proof that its customer carries an active
 * shipping address -- QuoteToCartConverter refuses otherwise (see
 * RuleScopeUnavailable's docblock). Inventing a second fixture here would
 * only re-test that precondition instead of trusting the one test file that
 * already depends on it holding.
 *
 * One thing that reuse does NOT inherit: AddProductAndRecalculateTest's own
 * docblock documents a SIGSEGV in SwagCommercial's `QuoteManipulation::addProduct`
 * for a quote whose line references a variant product, and
 * `QuoteFixture::anyQuoteId()` applies no filter that would exclude such a
 * quote from the ones it can return. Every test here reaches SwagCommercial
 * through a different call (`QuoteToCartConverter::convertToCart()`, not
 * `QuoteManipulation::addProduct()`), so whether that crash reproduces on
 * this path is genuinely unknown -- not ruled out, and not expected either.
 * Worth knowing before staring at a run that died mid-suite with no PHPUnit
 * failure to read: a SIGSEGV kills the whole process, not just a test.
 */
final class QuoteRuleScopeTest extends IntegrationTestCase
{
    /**
     * The weaker, structural half of this file's proof: the cart the factory
     * built is not just *a* cart, it is *this quote's* cart, line for line.
     * Read independently through the gateway rather than through the scope
     * itself, so a factory that quietly built the wrong quote's cart (or an
     * empty one) cannot pass by comparing itself to itself.
     */
    public function testAQuoteBuildsACartRuleScopeCarryingItsOwnLineItems(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $quote = static::gateway()->fetchSnapshot($quoteId);
        $scope = $this->scopeFor($quoteId, $context);

        $sawProductLine = false;

        foreach ($quote->content->lines as $line) {
            $productId = $line->identity->productId;

            if ($productId === null) {
                // A generated quote-discount line, not something the cart
                // conversion is expected to reproduce as a purchasable line.
                continue;
            }

            $sawProductLine = true;
            $cartLine = null;

            foreach ($scope->getCart()->getLineItems() as $candidate) {
                if ($candidate->getReferencedId() !== $productId) {
                    continue;
                }

                $cartLine = $candidate;

                break;
            }

            self::assertNotNull(
                $cartLine,
                'The cart QuoteRuleScopeFactory built has no line item for product '
                . $productId
                . ', which the quote itself carries.',
            );
            self::assertSame(
                $line->quantity,
                $cartLine->getQuantity(),
                'The cart line for product ' . $productId . ' does not carry the quantity the quote line itself has.',
            );
        }

        self::assertTrue($sawProductLine, 'The fixture quote has no product line item to check the cart against.');
    }

    /**
     * Guards the delivery half of the same claim `testAQuoteBuildsACartRuleScopeCarryingItsOwnLineItems()`
     * makes for line items. Before `deliveries.shippingMethod` and
     * `deliveries.positions.quoteLineItem` were loaded, neither association
     * autoloaded, `QuoteDeliveryTransformer::transformToDeliveries()` drops
     * any delivery whose `getShippingMethod()` or `getPositions()` is null,
     * and every delivery on a real quote fell into that gap -- so the cart
     * always had zero deliveries and every delivery-derived condition
     * (`CartShippingCostRule`, `CartDeliveryTaxRule`, ...) matched or missed
     * however "no deliveries" happens to read, silently, with no exception to
     * point at the cause. This only proves the collection is non-empty, not
     * any particular condition's outcome -- the fixture's shipping cost is
     * not asserted anywhere else in this file, so pinning a `CartShippingCostRule`
     * threshold here would be a guess this test cannot verify against a real
     * shop.
     */
    public function testAQuoteBuildsACartRuleScopeCarryingItsOwnDeliveries(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $scope = $this->scopeFor($quoteId, $context);

        self::assertGreaterThan(
            0,
            $scope->getCart()->getDeliveries()->count(),
            'The cart QuoteRuleScopeFactory built has no deliveries at all, so every delivery-derived '
            . 'rule condition would evaluate against an empty collection instead of the quote\'s real one.',
        );
    }

    /**
     * The load-bearing assertion for the whole plan: a real core condition,
     * `GoodsPriceRule`, evaluated against the scope this factory built.
     *
     * Asserting only `instanceof CartRuleScope` would also pass against a
     * scope wrapping an empty cart -- `GoodsPriceRule::match()` opens with a
     * scope-type guard, not a content guard, so the wrong kind of scope is
     * the only failure that assertion could ever catch. Every core cart
     * condition instead answers `false` to a scope it cannot read (see
     * `QuoteRuleScopeFactory`'s own docblock), so the only way to tell "this
     * scope works" from "this scope is silently empty" is to make a
     * condition that is known to be true and confirm it actually reads that
     * way -- which is why both the true and the false side are asserted
     * below, not just one of them.
     *
     * The threshold is computed from the cart's own goods total at runtime,
     * the same computation `GoodsPriceRule::match()` itself performs
     * (`filterGoodsFlat()` then `getTotalPriceAmount()`), rather than an
     * assumed gross figure -- every product line on this shop is quoted at 0%
     * tax, so net and gross coincide here, but only because this reads
     * whatever the fixture actually produced, not because either value was
     * assumed ahead of time.
     */
    public function testAGoodsPriceRuleMatchesAgainstTheScopesRealGoodsTotal(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $scope = $this->scopeFor($quoteId, $context);

        $goodsTotal = self::goodsTotal($scope);
        self::assertGreaterThan(
            0.0,
            $goodsTotal,
            'The fixture quote has no priced goods, so no threshold here could distinguish a working '
            . 'scope from an empty one.',
        );

        $margin = self::margin($goodsTotal);

        $matching = new GoodsPriceRule(Rule::OPERATOR_GTE, $goodsTotal - $margin);
        self::assertTrue(
            $matching->match($scope),
            'A GoodsPriceRule set below the quote\'s real goods total did not match. The scope this '
            . 'factory built is answering every cart condition the way an empty-cart scope would, which '
            . 'is exactly the failure this test exists to catch.',
        );

        $nonMatching = new GoodsPriceRule(Rule::OPERATOR_GTE, $goodsTotal + $margin);
        self::assertFalse(
            $nonMatching->match($scope),
            'A GoodsPriceRule set above the quote\'s real goods total matched anyway.',
        );
    }

    /**
     * The end-to-end proof for the rule rung of the assignment ladder. The
     * unit test's fake repository (CriteriaFilter, see
     * StrategyAssignmentResolverTest) applies the `priority DESC` sorting in
     * PHP -- it is the fake's claim about what MySQL does, not a check of it.
     * This seeds two rules that both genuinely match the same real quote and
     * asserts the higher-priority one is the one `StrategyAssignmentResolver`
     * actually returns, which only a real `ORDER BY` can prove.
     *
     * Both rules use the same condition (a `GoodsPriceRule` below the quote's
     * real total) so that priority is the only thing separating them: if the
     * sort were missing, reversed, or applied to the wrong column, whichever
     * rule the database happens to return first would win instead, and this
     * assertion would fail for one of the two runs in expectation rather
     * than deterministically -- either way it stops being a coincidence.
     */
    public function testTheHigherPriorityMatchingRuleWinsAgainstARealDatabase(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $scope = $this->scopeFor($quoteId, $context);

        $goodsTotal = self::goodsTotal($scope);
        self::assertGreaterThan(
            0.0,
            $goodsTotal,
            'The fixture quote has no priced goods to build a matching rule condition from.',
        );

        $matchingAmount = $goodsTotal - self::margin($goodsTotal);
        $lowPriorityRuleId = Uuid::randomHex();
        $highPriorityRuleId = Uuid::randomHex();

        // This shop is shared with other sessions (see the plan's "Not in
        // this plan" note: assignment rows are already created here through
        // the admin API), so the winning priority is pushed far above
        // anything a merchant would configure by hand rather than picked as
        // "higher than the other one" -- a real pre-existing rule assignment
        // sharing this scope must not accidentally outrank it and turn this
        // into a false failure.
        static::repository(static::getContainer(), 'rule.repository')
            ->create([
                self::goodsPriceRuleRow($lowPriorityRuleId, 'QuoteRuleScopeTest low priority', 1, $matchingAmount),
                self::goodsPriceRuleRow(
                    $highPriorityRuleId,
                    'QuoteRuleScopeTest high priority',
                    1_000_000,
                    $matchingAmount,
                ),
            ], $context);

        static::repository(static::getContainer(), 'merchant_quote_agent_strategy_assignment.repository')
            ->create([
                self::ruleAssignmentRow($lowPriorityRuleId, BuiltInStrategies::MARGIN_DEFENDER),
                self::ruleAssignmentRow($highPriorityRuleId, BuiltInStrategies::FAST_CLOSE),
            ], $context);

        // Proven independently of assign(), against each rule's own hydrated
        // payload, and before the resolver ever runs: if hydration silently
        // failed for one of the two rows, or its condition simply did not
        // apply to this particular quote, `assign()` would still return the
        // OTHER rule's strategy and the assertion below would fail with a
        // message blaming `priority DESC` for a fixture problem that has
        // nothing to do with sort order. These two calls turn that
        // misdiagnosis into an accurate one, pointing at the rule id that
        // actually failed to match.
        self::assertRuleMatches($lowPriorityRuleId, $scope, $context);
        self::assertRuleMatches($highPriorityRuleId, $scope, $context);

        $resolver = static::getContainer()->get(StrategyAssignmentResolver::class);
        self::assertInstanceOf(StrategyAssignmentResolver::class, $resolver);

        $assigned = $resolver->assign($quoteId, Uuid::randomHex(), Uuid::randomHex(), $context);

        self::assertNotNull($assigned, 'assign() found no rule, although both seeded rules were just proven to match.');
        self::assertSame(StrategyAssignmentSource::Rule, $assigned->source);

        $strategies = static::getContainer()->get(StrategyResolver::class);
        self::assertInstanceOf(StrategyResolver::class, $strategies);

        self::assertSame(
            $strategies->resolve(BuiltInStrategies::FAST_CLOSE, $context)->versionId,
            $assigned->strategy->versionId,
            'The lower-priority rule\'s strategy won, so `priority DESC` is not what the resolver ran '
            . 'against the real database.',
        );
    }

    /**
     * Reads a just-seeded rule back through the same repository
     * StrategyAssignmentResolver uses and confirms its hydrated payload
     * matches the scope on its own -- independent of the ladder, so a
     * failure here can never be mistaken for a sort-order bug.
     */
    private static function assertRuleMatches(string $ruleId, CartRuleScope $scope, Context $context): void
    {
        $rule = static::repository(static::getContainer(), 'rule.repository')
            ->search(new Criteria([$ruleId]), $context)
            ->first();

        self::assertInstanceOf(RuleEntity::class, $rule, 'Rule ' . $ruleId . ' was not found after being seeded.');

        $payload = $rule->getPayload();

        self::assertInstanceOf(
            Rule::class,
            $payload,
            'Rule '
            . $ruleId
            . ' has no matchable payload after being seeded. This is a fixture problem '
            . '(hydration did not build a payload), not a priority-sort problem.',
        );
        self::assertTrue(
            $payload->match($scope),
            'Rule '
            . $ruleId
            . ' does not match the scope even though it was seeded with a GoodsPriceRule '
            . 'condition below the quote\'s own goods total. This is a fixture problem, not a priority-sort '
            . 'problem.',
        );
    }

    private function scopeFor(string $quoteId, Context $context): CartRuleScope
    {
        $factory = static::getContainer()->get(QuoteRuleScopeFactory::class);
        self::assertInstanceOf(QuoteRuleScopeFactory::class, $factory);

        try {
            return $factory->forQuote($quoteId, $context);
        } catch (RuleScopeUnavailable $e) {
            self::fail('QuoteRuleScopeFactory could not build a scope for the fixture quote: ' . $e->getMessage());
        }
    }

    /** The same computation GoodsPriceRule::match() itself performs, read from the cart the factory built. */
    private static function goodsTotal(CartRuleScope $scope): float
    {
        return (new LineItemCollection($scope->getCart()->getLineItems()->filterGoodsFlat()))
            ->getPrices()
            ->getTotalPriceAmount();
    }

    /**
     * A margin wide enough that float rounding in the cart calculation can
     * never put the threshold and the total on the wrong side of each other,
     * scaled to the total itself so it works whether the fixture's goods
     * total is a few euros or a few thousand.
     */
    private static function margin(float $goodsTotal): float
    {
        return max(1.0, $goodsTotal * 0.1);
    }

    /** @return array<string, mixed> */
    private static function goodsPriceRuleRow(string $id, string $name, int $priority, float $amount): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'priority' => $priority,
            'conditions' => [[
                'type' => GoodsPriceRule::RULE_NAME,
                'value' => ['operator' => Rule::OPERATOR_GTE, 'amount' => $amount],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function ruleAssignmentRow(string $ruleId, string $strategyId): array
    {
        return [
            'id' => Uuid::randomHex(),
            'kind' => StrategyAssignmentSource::Rule->value,
            'ruleId' => $ruleId,
            'strategyId' => $strategyId,
        ];
    }
}
