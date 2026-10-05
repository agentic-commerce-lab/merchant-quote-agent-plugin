<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Improvement\ImprovementCadence;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettings;
use MerchantQuoteAgentPlugin\Improvement\JudgeAnswer;
use MerchantQuoteAgentPlugin\Improvement\JudgeCandidate;
use MerchantQuoteAgentPlugin\Improvement\RunStatus;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * M4: the judge's own model call is the night's biggest, and it must bill the
 * run row like every other call does -- under the model the judge ACTUALLY
 * used (`improvementLlmModel`, which can differ from the agent's own), not
 * the agent's model the run previously mislabelled it with. Split out of
 * ImprovementGeneratorTest to keep that class under the method-count gate --
 * same reasoning ImprovementGeneratorFixture's own docblock gives for the
 * fixture split.
 */
final class ImprovementRunnerBillingTest extends TestCase
{
    /**
     * The fixture's QuoteSnapshotReader always resolves zero subjects (its
     * repository is empty -- see ImprovementGeneratorFixture), so the one
     * scripted response below is the judge's call and nothing else touches
     * the model; the replay contributes 0 tokens of its own.
     */
    public function testTheJudgesCallBillsTheRunRowUnderTheImprovementModel(): void
    {
        $runs = new RunRepositorySpy();
        $settings = new ImprovementSettings(
            enabled: true,
            cadence: ImprovementCadence::Daily,
            sampleSize: 20,
            candidates: 2,
            llm: new ModelAccess('sk-improve', 'https://api.example.com/v1', 'gpt-5-improve-review'),
        );
        $generator = (new ImprovementGeneratorFixture())->generator(
            $settings,
            null,
            [self::decision()],
            $this->platformAnsweringTheJudge(promptTokens: 321, completionTokens: 45),
            $runs,
        );

        $generator->generate(new \DateTimeImmutable('2026-09-21 03:00:00'));

        self::assertCount(1, $runs->updated);
        self::assertSame(RunStatus::Completed->value, $runs->updated[0]['status']);
        self::assertSame(
            'gpt-5-improve-review',
            $runs->updated[0]['model'],
            'must record the model the judge actually called, not the agent\'s',
        );
        self::assertSame(321, $runs->updated[0]['promptTokens'], 'the judge\'s own call must bill the run row');
        self::assertSame(45, $runs->updated[0]['completionTokens']);
    }

    /** @return \Closure(DecisionRecorder): ModelPlatform */
    private function platformAnsweringTheJudge(int $promptTokens, int $completionTokens): \Closure
    {
        $content = json_encode(
            new JudgeAnswer([], [new JudgeCandidate('hold firm', 'led with the max')]),
            JSON_THROW_ON_ERROR,
        );
        $response = new MockResponse(
            json_encode([
                'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
            ], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        return static fn(DecisionRecorder $recorder): ModelPlatform => ScriptedClient::responding(
            [$response],
            $recorder,
        )[0];
    }

    /**
     * One decision, populated only enough for DayPicture and DecisionHarvest
     * to accept it AND group it: `strategyVersionId` must name
     * ImprovementGeneratorFixture::STRATEGY_VERSION_ID, the one version its
     * StrategyResolver double resolves, or DecisionHarvest drops this
     * decision from every group before the judge is ever reached.
     */
    private static function decision(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $id = Uuid::randomHex();
        $record->setUniqueIdentifier($id);
        $record->id = $id;
        $record->quoteId = Uuid::randomHex();
        $record->band = 'grant';
        $record->outcome = 'offered';
        $record->strategyVersionId = ImprovementGeneratorFixture::STRATEGY_VERSION_ID;

        return $record;
    }
}
