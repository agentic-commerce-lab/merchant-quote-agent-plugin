<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Improvement\DayPicture;
use MerchantQuoteAgentPlugin\Improvement\ImprovementJudge;
use MerchantQuoteAgentPlugin\Improvement\JudgeAnswer;
use MerchantQuoteAgentPlugin\Improvement\JudgeCandidate;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImprovementJudgeTest extends TestCase
{
    public function testItReturnsNullWhenTheModelSendsNoCandidates(): void
    {
        $judge = new ImprovementJudge($this->platformReturning(new JudgeAnswer([], [])));

        self::assertNull($judge->assess($this->llm(), $this->picture(), 'concede slowly', 2));
    }

    public function testItDropsACandidateWithAnEmptyPrompt(): void
    {
        $answer = new JudgeAnswer([], [
            new JudgeCandidate('', 'no reason'),
            new JudgeCandidate('hold firm', 'led with the max'),
        ]);
        $judge = new ImprovementJudge($this->platformReturning($answer));

        $assessed = $judge->assess($this->llm(), $this->picture(), 'concede slowly', 2);

        self::assertNotNull($assessed);
        self::assertCount(1, $assessed->candidates);
        self::assertSame('hold firm', $assessed->candidates[0]->prompt);
    }

    public function testAModelOutageIsNullRatherThanAThrow(): void
    {
        $judge = new ImprovementJudge($this->platformThrowing());

        self::assertNull($judge->assess($this->llm(), $this->picture(), 'concede slowly', 2));
    }

    private function llm(): ModelAccess
    {
        return new ModelAccess('sk-test', 'https://api.example.test/v1', 'gpt-4o-mini');
    }

    private function picture(): DayPicture
    {
        return DayPicture::of([]);
    }

    /** A ModelPlatform whose one HTTP call answers with $answer's own shape. */
    private function platformReturning(JudgeAnswer $answer): ModelPlatform
    {
        $encoded = json_encode($answer, JSON_THROW_ON_ERROR);

        return ScriptedClient::returning([$encoded]);
    }

    /**
     * Two 503s: ModelPlatform retries exactly once (see ModelPlatformRetryTest),
     * so this is what actually exhausts the retry and throws ModelUnavailable
     * out of ModelPlatform::object() -- the same path a real outage takes,
     * rather than a double that pretends to throw.
     */
    private function platformThrowing(): ModelPlatform
    {
        [$platform] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
        ]);

        return $platform;
    }
}
