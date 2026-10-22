<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Improvement\ReplayEvaluator;
use MerchantQuoteAgentPlugin\Improvement\TallyingDecisionWriter;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\FakeCustomerHistoryFactory;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;

/**
 * Pins the mechanism ReplayHarness::controlArms() relies on to give each
 * decision its OWN control arm (per the per-strategy design brief):
 * QuoteAgentSettings::withStrategyPrompt($subject->controlPrompt), applied
 * per decision rather than once per run, must actually change what
 * PromptComposer::negotiate() sends the model -- not just what the settings
 * object holds in memory.
 *
 * ReplayHarness itself cannot be driven directly in a unit test: its own
 * ReplaySubjectResolver dependency is final and needs a real QuoteSnapshotReader,
 * which this codebase deliberately never fakes at the unit level (see
 * QuoteSnapshotReader's own class docblock -- it is exercised only by the
 * integration suite against a real shop). ReplayEvaluator is the layer one
 * step below that boundary, fed a QuoteSnapshot DTO directly the same way
 * ReplayEvaluatorTest already does, so this test proves the SAME
 * withStrategyPrompt()-then-replay() call ReplayHarness::controlArms() makes,
 * for two decisions whose recorded strategy prompts differ.
 */
final class ReplayControlPromptTest extends TestCase
{
    public function testEachDecisionsControlArmSendsItsOwnRecordedPrompt(): void
    {
        $recorder = new DecisionRecorder(new TallyingDecisionWriter());
        $offer = '{"action":"offer","message":"ok","terms":{"discountPercent":5}}';
        [$platform, $spy] = ScriptedClient::spy([$offer, $offer], $recorder);
        $evaluator = $this->evaluator($platform, $recorder);

        $control = NegotiationFixture::settings(maxDiscountPercent: 20.0);

        // Two decisions from two different strategies: the split arm's
        // decision must be controlled against the split's own prompt, the
        // config arm's against the config strategy's own prompt -- never a
        // single prompt shared across both, which was the bug (#191) this
        // fixes.
        $evaluator->replay($control->withStrategyPrompt('the SPLIT arm strategy'), $this->snapshot(), $this->ask());
        $evaluator->replay($control->withStrategyPrompt('the CONFIG arm strategy'), $this->snapshot(), $this->ask());

        self::assertCount(2, $spy->systemPrompts);
        self::assertStringContainsString('the SPLIT arm strategy', $spy->systemPrompts[0]);
        self::assertStringNotContainsString('the CONFIG arm strategy', $spy->systemPrompts[0]);
        self::assertStringContainsString('the CONFIG arm strategy', $spy->systemPrompts[1]);
        self::assertStringNotContainsString('the SPLIT arm strategy', $spy->systemPrompts[1]);
    }

    private function snapshot(): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot
    {
        return NegotiationFixture::snapshot(totalNet: 1000.0);
    }

    private function ask(): InterpretedAsk
    {
        return new InterpretedAsk(
            new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: 10.0)),
            'extract-hash',
        );
    }

    private function evaluator(
        \MerchantQuoteAgentPlugin\Negotiation\ModelPlatform $platform,
        DecisionRecorder $recorder,
    ): ReplayEvaluator {
        $proposer = new OfferProposer(
            $platform,
            new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}'),
            new OfferAuthorizer(),
            $recorder,
            new FakeCustomerHistoryFactory(),
        );

        return new ReplayEvaluator(new NegotiationDecider(), $proposer, $recorder);
    }
}
