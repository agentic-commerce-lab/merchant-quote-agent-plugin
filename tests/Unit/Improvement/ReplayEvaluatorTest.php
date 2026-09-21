<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Improvement\ReplayEvaluator;
use MerchantQuoteAgentPlugin\Improvement\TallyingDecisionWriter;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
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
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReplayEvaluatorTest extends TestCase
{
    public function testAnEscalatingBandNeverReachesTheModel(): void
    {
        // The band gate is deterministic and runs before the model call in
        // production too. A replay that paid for a model call on an ask the
        // bands already refused would bill the merchant for an arm that
        // cannot differ between prompts.
        $recorder = new DecisionRecorder(new TallyingDecisionWriter());
        [$platform, $spy] = ScriptedClient::spy([], $recorder);
        $evaluator = $this->evaluator($platform, $recorder);

        $arm = $evaluator->replay($this->settings(maxDiscountPercent: 0.0), $this->snapshot(), $this->ask(10.0));

        self::assertTrue($arm->escalated);
        self::assertNull($arm->grantedPercent);
        self::assertSame(0, $spy->calls);
    }

    public function testItMeasuresTheGrantedPercentAgainstTheBaseline(): void
    {
        $recorder = new DecisionRecorder(new TallyingDecisionWriter());
        $evaluator = $this->evaluator($this->platformOffering(900.0, $recorder), $recorder);

        $arm = $evaluator->replay(
            $this->settings(maxDiscountPercent: 20.0),
            $this->snapshot(totalNet: 1000.0),
            $this->ask(10.0),
        );

        self::assertFalse($arm->escalated);
        self::assertSame(10.0, $arm->grantedPercent);
    }

    /**
     * The buyer itemised the ask (a requested price on the line), so
     * OfferLevelMirror converts the model's quote-wide reply into a per-line
     * offer -- see OfferProposerTest::testAQuoteWideAnswerToAPerLineAskComesBackAsALinePrice()
     * for the same conversion in the live path. That offer's own
     * `discountPercent` is then null, and the only trustworthy "after" total
     * for a per-line concession is a database re-read a replay may never
     * perform (ReplayEvaluator::grantedPercent()'s docblock) -- so this must
     * come back as an offer with nothing measured, never as an escalation and
     * never as a guessed number.
     */
    public function testAPerLineOfferIsOfferedButNotMeasured(): void
    {
        $recorder = new DecisionRecorder(new TallyingDecisionWriter());
        $evaluator = $this->evaluator($this->platformOffering(900.0, $recorder), $recorder);

        $arm = $evaluator->replay(
            $this->settings(maxDiscountPercent: 20.0),
            $this->snapshot(totalNet: 1000.0, requestedUnitPrice: 95.0),
            $this->ask(10.0),
        );

        self::assertFalse($arm->escalated);
        self::assertFalse($arm->modelRefused);
        self::assertFalse($arm->failed);
        self::assertNull($arm->grantedPercent);
    }

    public function testItWritesNoDecisionRecord(): void
    {
        $writer = new TallyingDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $evaluator = $this->evaluator($this->platformOffering(900.0, $recorder), $recorder);

        $evaluator->replay($this->settings(maxDiscountPercent: 20.0), $this->snapshot(), $this->ask(10.0));

        // TallyingDecisionWriter is the only writer in play, and it cannot
        // persist -- so the only thing left to check is that the model call
        // the replay paid for actually reached it. A writer stuck at 0 would
        // mean either no model call happened or the recorder wiring silently
        // dropped it, either of which is a bug this test exists to catch.
        self::assertGreaterThan(0, $writer->promptTokens);
    }

    /**
     * counterOfferMaxPercent pinned at 0.0 too: QuoteBandDecider has a
     * separate counter band above maxDiscountPercent (#5), so a 0% cap alone
     * still auto-replies at the merchant's own counterOfferMaxPercent default
     * (20%) instead of escalating. Both must be closed for the band gate test
     * to actually exercise Band::Escalate.
     */
    private function settings(float $maxDiscountPercent): QuoteAgentSettings
    {
        return NegotiationFixture::settings(maxDiscountPercent: $maxDiscountPercent, counterOfferMaxPercent: 0.0);
    }

    private function snapshot(float $totalNet = 1000.0, ?float $requestedUnitPrice = null): QuoteSnapshot
    {
        return NegotiationFixture::snapshot(totalNet: $totalNet, requestedUnitPrice: $requestedUnitPrice);
    }

    private function ask(float $percent): InterpretedAsk
    {
        return new InterpretedAsk(
            new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: $percent)),
            'extract-hash',
        );
    }

    /**
     * A model that always answers with a quote-wide discount landing the
     * total at $totalNet, off the default 1000.00 -- and, unlike
     * NegotiationFixture::modelReply(), reports token usage, which is what
     * testItWritesNoDecisionRecord needs to see land on the writer.
     *
     * Takes $recorder rather than building its own: ModelPlatform records a
     * model call's tokens through its OWN DecisionRecorder dependency,
     * separate from OfferProposer's. ScriptedClient::responding() defaults to
     * a throwaway recorder when none is given, which is exactly right for
     * tests that don't care where the tokens went -- but wrong here, where
     * the whole point is watching them land on this evaluator's writer.
     */
    private function platformOffering(float $totalNet, DecisionRecorder $recorder): ModelPlatform
    {
        $discountPercent = ((1000.0 - $totalNet) / 1000.0) * 100;
        $content = sprintf('{"action":"offer","message":"ok","terms":{"discountPercent":%s}}', $discountPercent);

        [$platform] = ScriptedClient::responding([
            new MockResponse(
                (string) json_encode([
                    'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 7],
                ], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
        ], $recorder);

        return $platform;
    }

    private function evaluator(ModelPlatform $platform, DecisionRecorder $recorder): ReplayEvaluator
    {
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
