<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Improvement\DayPicture;
use MerchantQuoteAgentPlugin\Improvement\ImprovementJudge;
use MerchantQuoteAgentPlugin\Improvement\JudgeAnswer;
use MerchantQuoteAgentPlugin\Improvement\JudgeCandidate;
use MerchantQuoteAgentPlugin\Improvement\TallyingDecisionWriter;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImprovementJudgeTest extends TestCase
{
    public function testItReturnsNullWhenTheModelSendsNoCandidates(): void
    {
        $judge = new ImprovementJudge(...$this->platformReturning(new JudgeAnswer([], [])));

        self::assertNull($judge->assess($this->llm(), $this->picture(), 'concede slowly', 2));
    }

    public function testItDropsACandidateWithAnEmptyPrompt(): void
    {
        $answer = new JudgeAnswer([], [
            new JudgeCandidate('', 'no reason'),
            new JudgeCandidate('hold firm', 'led with the max'),
        ]);
        $judge = new ImprovementJudge(...$this->platformReturning($answer));

        $assessed = $judge->assess($this->llm(), $this->picture(), 'concede slowly', 2);

        self::assertNotNull($assessed);
        self::assertCount(1, $assessed->candidates);
        self::assertSame('hold firm', $assessed->candidates[0]->prompt);
    }

    public function testAModelOutageIsNullRatherThanAThrow(): void
    {
        $judge = new ImprovementJudge(...$this->platformThrowing());

        self::assertNull($judge->assess($this->llm(), $this->picture(), 'concede slowly', 2));
    }

    /**
     * M4: the judge's call must bill the SAME recorder/writer pair
     * ReplayHarness reports through (see ImprovementJudge's own docblock),
     * not silently drop its tokens the way the shared, no-open-draft
     * ModelPlatform::class did before this fix.
     */
    public function testItBillsItsCallToTheSharedTally(): void
    {
        $tally = new TallyingDecisionWriter();
        $recorder = new DecisionRecorder($tally);
        $content = json_encode(
            new JudgeAnswer([], [new JudgeCandidate('hold firm', 'led with the max')]),
            JSON_THROW_ON_ERROR,
        );
        $response = new MockResponse(
            json_encode([
                'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 30],
            ], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
        [$platform] = ScriptedClient::responding([$response], $recorder);
        $judge = new ImprovementJudge($platform, $recorder);

        $judge->assess($this->llm(), $this->picture(), 'concede slowly', 2);

        self::assertSame(200, $tally->promptTokens);
        self::assertSame(30, $tally->completionTokens);
    }

    private function llm(): ModelAccess
    {
        return new ModelAccess('sk-test', 'https://api.example.test/v1', 'gpt-4o-mini');
    }

    private function picture(): DayPicture
    {
        return DayPicture::of([]);
    }

    /**
     * A ModelPlatform whose one HTTP call answers with $answer's own shape,
     * paired with the DecisionRecorder it was built with -- the two must be
     * the same instance ImprovementJudge is given, or begin()/finish() bracket
     * a draft ModelPlatform::send() never writes into (see ImprovementJudge's
     * own docblock).
     *
     * @return array{0: ModelPlatform, 1: DecisionRecorder}
     */
    private function platformReturning(JudgeAnswer $answer): array
    {
        $encoded = json_encode($answer, JSON_THROW_ON_ERROR);
        $recorder = new DecisionRecorder(new TallyingDecisionWriter());

        return [ScriptedClient::spy([$encoded], $recorder)[0], $recorder];
    }

    /**
     * Two 503s: ModelPlatform retries exactly once (see ModelPlatformRetryTest),
     * so this is what actually exhausts the retry and throws ModelUnavailable
     * out of ModelPlatform::object() -- the same path a real outage takes,
     * rather than a double that pretends to throw.
     *
     * @return array{0: ModelPlatform, 1: DecisionRecorder}
     */
    private function platformThrowing(): array
    {
        $recorder = new DecisionRecorder(new TallyingDecisionWriter());
        [$platform] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
        ], $recorder);

        return [$platform, $recorder];
    }
}
