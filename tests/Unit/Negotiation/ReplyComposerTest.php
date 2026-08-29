<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReplyComposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
    }

    private static function offer(): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));
    }

    private static function composer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): ReplyComposer
    {
        return new ReplyComposer($client, self::prompts(), new NullLogger());
    }

    public function testItWritesTheModelsRewordingAndTransitions(): void
    {
        [$client] = ScriptedClient::spy(['We can offer 5% off, valid until 2026-09-11.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review');

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(tone: 'formal'),
                self::offer(),
                SnapshotAdapter::conversation($after),
            );

        self::assertContains('addComment', $gateway->calls);
        self::assertStringContainsString('5%', $gateway->comments[0]);
        self::assertContains('transition', $gateway->calls);
    }

    public function testARewordingThatDropsTheDiscountFallsBackToTheTemplate(): void
    {
        // The reply prompt's one rule is "keep every fact exactly as given".
        // A reply that lost the number is a hallucination, and it is going to
        // a buyer, so the template wins.
        [$client] = ScriptedClient::spy(['Thanks for your interest! We will be in touch soon.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review');

        $hash = self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(),
                self::offer(),
                SnapshotAdapter::conversation($after),
            );

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertStringContainsString('5', $gateway->comments[0]);
    }

    public function testRulesOnlyUsesTheTemplateWithNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy([]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review');

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(rulesOnly: true),
                self::offer(),
                SnapshotAdapter::conversation($after),
            );

        self::assertSame(0, $spy->calls);
        self::assertContains('addComment', $gateway->calls);
    }

    public function testAnAlreadyAnsweredQuoteIsNotAnsweredTwice(): void
    {
        // Idempotency: the agent's reply is already newer than the buyer's ask,
        // so a retry must post nothing and pay for nothing.
        [$client, $spy] = ScriptedClient::spy(['would be a duplicate']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
        $after = NegotiationFixture::snapshot(state: 'replied', comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(),
                self::offer(),
                SnapshotAdapter::conversation($after),
            );

        self::assertSame(0, $spy->calls);
        self::assertNotContains('addComment', $gateway->calls);
    }
}
