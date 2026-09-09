<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

final class QuoteNegotiatePromptTest extends TestCase
{
    public function testTheShippedPromptStatesTheHistoryDisclosureAuthorityAndRequestRules(): void
    {
        $prompt = file_get_contents(__DIR__ . '/../../../config/agents/quote-negotiate-agent.prompt.md');
        self::assertIsString($prompt);

        self::assertStringContainsString('INTERNAL', $prompt);
        self::assertStringContainsString('Never quote it, summarise it, confirm it or allude to it in', $prompt);
        self::assertStringContainsString('`message`', $prompt);
        self::assertStringContainsString('a colleague can go through their records with them', $prompt);
        self::assertStringContainsString('History does not raise your cap', $prompt);
        self::assertStringContainsString('The offer authorizer enforces these caps', $prompt);
        self::assertStringContainsString('"kind": "quote_history"', $prompt);
        self::assertStringContainsString('"kind": "orders"', $prompt);
        self::assertStringContainsString('"kind": "product_purchases", "productId": "<id>"', $prompt);
        self::assertStringContainsString('The id must be one of the `productId` values shown on THIS quote', $prompt);
        self::assertStringContainsString('a request is answered before your terms or escalation are read', $prompt);
        self::assertStringContainsString('an offer or escalation in the same response is discarded', $prompt);
        self::assertStringContainsString('at most twice', $prompt);
        self::assertStringContainsString('a third request sends the quote to a human', $prompt);
        self::assertStringContainsString('"historyRequest": {"kind": null, "productId": null}', $prompt);
    }
}
