<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use GuzzleHttp\Client;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use MerchantQuoteAgentPlugin\Negotiation\Response\ExtractResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The only test that talks to a real provider, and the only thing that would
 * catch one changing its response shape. Opt-in: it must never gate CI or the
 * stress run, and it costs real money each time it runs.
 *
 *   QUOTE_AGENT_LIVE_KEY=sk-... QUOTE_AGENT_LIVE_MODEL=gpt-4o-mini \
 *     composer run test:integration -- --filter LiveModelSmokeTest
 */
final class LiveModelSmokeTest extends TestCase
{
    public function testARealProviderReturnsJsonOurReaderCanParse(): void
    {
        $key = getenv('QUOTE_AGENT_LIVE_KEY');
        $model = getenv('QUOTE_AGENT_LIVE_MODEL');

        if (!\is_string($key) || $key === '' || !\is_string($model) || $model === '') {
            self::markTestSkipped('Set QUOTE_AGENT_LIVE_KEY and QUOTE_AGENT_LIVE_MODEL to run this.');
        }

        $client = new ChatCompletionClient(new Client(), new NullLogger());
        $prompt = (string) file_get_contents(__DIR__ . '/../../config/agents/quote-extract-agent.prompt.md');

        $answer = $client->complete(
            new ModelAccess($key, getenv('QUOTE_AGENT_LIVE_BASE_URL') ?: 'https://api.openai.com/v1', $model),
            $prompt,
            "Line items:\nline-1 | Widget | 10 | 100.00\n\nBuyer comments:\nCould you do 5% off?",
            json: true,
        );

        // Asserting the SHAPE parses, never what the model decided — the
        // model's judgement is #21's question, not a unit test's.
        $interpretation = ExtractResponse::toInterpretation($answer);

        self::assertNotNull($interpretation->price);
    }
}
