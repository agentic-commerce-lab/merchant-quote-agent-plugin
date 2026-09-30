<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #167: every escalation in the 2026-09-18 session was a buyer stating a
 * quote-level BUDGET that the extract prompt had nowhere to put before
 * price.targetTotal (#164) existed. This replays the four real clarification
 * questions from that session against what a model following the corrected
 * extract prompt (config/agents/quote-extract-agent.prompt.md) must now
 * produce.
 *
 * The model is scripted here, so this does not prove a live model complies —
 * QuoteExtractPromptTest pins the prompt wording that argues it should — but
 * it does pin that once the model places the ask in price.targetTotal,
 * AskInterpreter carries it through untouched, with no clarificationQuestions
 * riding along to re-trigger ClarificationRound. A separate file from
 * AskInterpreterTest so that suite stays under the method-count gate.
 */
final class QuoteLevelBudgetAskTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    private static function recorder(): DecisionRecorder
    {
        return new DecisionRecorder(new FakeDecisionWriter());
    }

    /** @return iterable<string, array{0: string, 1: float, 2: float}> */
    public static function realBudgetAsks(): iterable
    {
        yield 'a 2,500 budget for the whole quote' => [
            'We have a budget of 2,500 for the whole quote.',
            2153.43,
            2500.0,
        ];
        yield '3,500 across the items' => [
            'We have 3,500 to spend across the items.',
            3382.18,
            3500.0,
        ];
        yield 'a 9,000 EUR budget' => [
            'Our budget is 9,000 EUR.',
            8314.65,
            9000.0,
        ];
    }

    #[DataProvider('realBudgetAsks')]
    public function testARealBudgetAskPlacesInTargetTotalRatherThanAskingHowToSplitIt(
        string $buyerText,
        float $totalNet,
        float $targetTotal,
    ): void {
        [$client] = ScriptedClient::spy([sprintf('{"price":{"targetTotal":%s}}', $targetTotal)]);
        $snapshot = NegotiationFixture::snapshot(totalNet: $totalNet, comments: [
            NegotiationFixture::buyerComment($buyerText, '2026-09-18 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNotNull($result);
        self::assertSame($targetTotal, $result->interpretation->price->targetTotal);
        self::assertSame(
            [],
            $result->interpretation->clarificationQuestions,
            'A placed whole-quote budget must not also ask the buyer how to split it.',
        );
    }

    public function testABareNumberAmbiguousBetweenTotalAndUnitPriceLegitimatelyStillAsks(): void
    {
        // "60" on a 50-unit line (total 55.79) is genuinely
        // ambiguous between the whole-quote total and a per-unit price --
        // unlike the three budget asks above, #167 keeps this a legitimate
        // clarificationQuestions case.
        //
        // ponytail: the fixture's fixed 10-unit line stands in for the real
        // 50 units; the ambiguity under test is the bare number, not that
        // specific quantity.
        $question = 'Could you please clarify if 60 refers to the total price for the 50 units or a per-unit price?';
        [$client] = ScriptedClient::spy([sprintf('{"clarificationQuestions":["%s"]}', $question)]);
        $snapshot = NegotiationFixture::snapshot(totalNet: 55.79, comments: [
            NegotiationFixture::buyerComment('60', '2026-09-18 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNotNull($result);
        self::assertNull($result->interpretation->price->targetTotal);
        self::assertSame([$question], $result->interpretation->clarificationQuestions);
    }
}
