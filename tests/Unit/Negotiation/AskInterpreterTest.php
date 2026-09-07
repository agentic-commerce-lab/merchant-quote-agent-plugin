<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;

final class AskInterpreterTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    private static function recorder(): DecisionRecorder
    {
        return new DecisionRecorder(new FakeDecisionWriter());
    }

    public function testItInterpretsANewBuyerAsk(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"price":{"additionalDiscountPercent":8}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNotNull($result);
        self::assertSame(8.0, $result->interpretation->price->additionalDiscountPercent);
        self::assertSame(1, $spy->calls);
        self::assertSame('EXTRACT BASE', $spy->systemPrompts[0]);
        self::assertStringContainsString('8% please', $spy->userPrompts[0]);
    }

    public function testTheUserPromptCarriesTheLinesSoTheModelCanNameThem(): void
    {
        // The extract prompt's contract: "You will get the quote's line items
        // (id | label | quantity | unit price) and the buyer comments."
        [$client, $spy] = ScriptedClient::spy(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('cheaper on the widgets', '2026-08-28 09:00:00'),
        ]);

        (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertStringContainsString('line-1', $spy->userPrompts[0]);
        self::assertStringContainsString('Widget', $spy->userPrompts[0]);
    }

    public function testNoNewAskCostsNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"price":{"additionalDiscountPercent":8}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertNull($result);
        self::assertSame(0, $spy->calls, 'A re-trigger with nothing new must not cost a model call.');
    }

    public function testTheHashIsTheExtractPromptsHash(): void
    {
        [$client] = ScriptedClient::spy(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('hi', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertSame(self::prompts()->extract()->hash, $result?->promptHash);
    }

    public function testAnUnusableResponsePropagates(): void
    {
        [$client] = ScriptedClient::spy(['not json at all']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('hi', '2026-08-28 09:00:00'),
        ]);

        $this->expectException(ModelUnavailable::class);

        (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );
    }
}
