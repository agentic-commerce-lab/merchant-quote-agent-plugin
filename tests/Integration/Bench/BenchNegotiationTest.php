<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Tests\Bench\BuyerMove;
use MerchantQuoteAgentPlugin\Tests\Bench\BuyerMoveKind;
use MerchantQuoteAgentPlugin\Tests\Bench\Scenario;
use MerchantQuoteAgentPlugin\Tests\Bench\SyntheticBuyer;
use MerchantQuoteAgentPlugin\Tests\Integration\PipelineFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;

/**
 * The negotiation loop against a real quote on a real shop: a real gateway, a
 * real authorizer and verifier, real decision records. Only the model
 * (scripted, so this test is free and needs no API key) and the buyer
 * (one of the two tiny SyntheticBuyer stand-ins below) are substituted.
 */
final class BenchNegotiationTest extends BenchTestCase
{
    use PipelineFixture;

    public function testARefusingBuyerStopsAtTheRoundCapRatherThanRunningOn(): void
    {
        // There is no plugin-side round cap on main — #142's PR was closed
        // unmerged. If this loop does not bound itself, nothing does.
        $scenario = Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 3,
        ]);

        $bench = new BenchNegotiation(
            static::getContainer(),
            self::gateway(),
            self::buyerGateway(),
            ScriptedClient::returning([
                '{"price":{"additionalDiscountPercent":5}}',
                '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
                self::reworded(...),
                '{"price":{"additionalDiscountPercent":8}}',
                '{"action":"offer","message":"8% off.","terms":{"discountPercent":8}}',
                self::reworded(...),
                '{"price":{"additionalDiscountPercent":10}}',
                '{"action":"offer","message":"10% off.","terms":{"discountPercent":10}}',
                self::reworded(...),
            ]),
        );

        $result = $bench->run($scenario, new AlwaysCountersBuyer(), self::benchSettings(), 'test-run');

        self::assertSame(3, $result->rounds);
    }

    public function testAnAcceptingBuyerEndsTheNegotiationEarly(): void
    {
        $scenario = Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 8,
        ]);

        $bench = new BenchNegotiation(
            static::getContainer(),
            self::gateway(),
            self::buyerGateway(),
            ScriptedClient::returning([
                '{"price":{"additionalDiscountPercent":5}}',
                '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
                self::reworded(...),
            ]),
        );

        $result = $bench->run($scenario, new AcceptsImmediatelyBuyer(), self::benchSettings(), 'test-run');

        self::assertSame(1, $result->rounds);
        self::assertSame(BuyerMoveKind::Accept, $result->terminal);
    }

    public function testEachRoundLeavesADecisionRecordBehind(): void
    {
        $scenario = Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 2,
        ]);

        $bench = new BenchNegotiation(
            static::getContainer(),
            self::gateway(),
            self::buyerGateway(),
            ScriptedClient::returning([
                '{"price":{"additionalDiscountPercent":5}}',
                '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
                self::reworded(...),
                '{"price":{"additionalDiscountPercent":8}}',
                '{"action":"offer","message":"8% off.","terms":{"discountPercent":8}}',
                self::reworded(...),
            ]),
        );

        $result = $bench->run($scenario, new AlwaysCountersBuyer(), self::benchSettings(), 'test-run');

        self::assertSame(
            $result->rounds,
            (int) self::connection(static::getContainer())
                ->fetchOne('SELECT COUNT(*) FROM merchant_quote_agent_decision WHERE quote_id = UNHEX(:quote)', [
                    'quote' => $result->quoteId,
                ]),
        );
    }

    /**
     * Built directly rather than reused from PipelineFixture::enabledSettings():
     * this suite runs several growing scripted asks (5%, 8%, 10%) in one
     * negotiation and needs a ceiling comfortably above all of them, or the
     * round-cap test would escalate on round three instead of exercising the
     * cap it exists to prove.
     */
    private static function benchSettings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: 20.0,
                counterOfferMaxPercent: 20.0,
                validityDays: 14,
            )),
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: null,
        );
    }
}

/** Never satisfied by any offer; the bench's own round cap is the only thing that can stop it. */
final class AlwaysCountersBuyer implements SyntheticBuyer
{
    public function respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply, int $round): BuyerMove
    {
        return BuyerMove::counter('Still not enough, can you do better?');
    }
}

/** Takes whatever the very first pass offers. */
final class AcceptsImmediatelyBuyer implements SyntheticBuyer
{
    public function respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply, int $round): BuyerMove
    {
        return BuyerMove::accept();
    }
}
