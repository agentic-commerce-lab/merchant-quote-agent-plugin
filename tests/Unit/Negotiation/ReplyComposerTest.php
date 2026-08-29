<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReplyComposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
    }

    private static function composer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): ReplyComposer
    {
        return new ReplyComposer($client, self::prompts(), new NullLogger());
    }

    private static function after(float $totalNet = 950.0): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot
    {
        return NegotiationFixture::snapshot(state: 'in_review', totalNet: $totalNet);
    }

    public function testItWritesTheModelsRewordingAndTransitions(): void
    {
        $reworded = 'We can bring this quote down by 5% to 950.00 EUR, valid until 2026-09-11.';
        [$client] = ScriptedClient::spy([$reworded]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(tone: 'formal'),
                5.0,
                SnapshotAdapter::conversation($after),
            );

        self::assertContains('addComment', $gateway->calls);
        self::assertSame($reworded, $gateway->comments[0]);
        self::assertContains('transition', $gateway->calls);
    }

    public function testARewordingThatDropsTheDiscountFallsBackToTheTemplate(): void
    {
        // The reply prompt's one rule is "keep every fact exactly as given".
        // A reply that lost the number is a hallucination, and it is going to
        // a buyer, so the template wins.
        [$client] = ScriptedClient::spy(['Thanks for your interest! We will be in touch soon.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertStringContainsString('down by 5% to 950.00 EUR', $gateway->comments[0]);
    }

    public function testARewordingThatDropsTheNewTotalFallsBackToTheTemplate(): void
    {
        // The total is a fact the buyer acts on, so the guard covers it too —
        // otherwise the sentence could keep the percentage and invent a total.
        [$client] = ScriptedClient::spy(['We can bring this quote down by 5%, valid until 2026-09-11.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash);
        self::assertStringContainsString('950.00 EUR', $gateway->comments[0]);
    }

    public function testAPerLineConcessionIsAnnouncedAsTheReductionTheDatabaseShows(): void
    {
        // The bug this replaces: a per-line offer carries no `discountPercent`
        // at all, so the reply used to tell the buyer "0% off this quote" on a
        // quote whose line prices had just been cut by 15%.
        [$client, $spy] = ScriptedClient::spy([]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after(totalNet: 850.0);

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(rulesOnly: true),
                ReplyTemplate::reduction(1000.0, 850.0),
                SnapshotAdapter::conversation($after),
            );

        self::assertSame(0, $spy->calls);
        self::assertSame(
            'We can bring this quote down by 15% to 850.00 EUR. The offer is valid until 2026-09-11.',
            $gateway->comments[0],
        );
    }

    public function testRulesOnlyUsesTheTemplateWithNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy([]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(rulesOnly: true),
                5.0,
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
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertSame(0, $spy->calls);
        self::assertNotContains('addComment', $gateway->calls);
    }
}
