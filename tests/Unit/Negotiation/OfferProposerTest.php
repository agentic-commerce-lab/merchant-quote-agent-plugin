<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use PHPUnit\Framework\TestCase;

final class OfferProposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    private static function proposer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): OfferProposer
    {
        return new OfferProposer($client, self::prompts(), new OfferAuthorizer());
    }

    /** A grant-band decision: the buyer asked 5% against a 10% cap. */
    private static function grantDecision(): \MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision
    {
        $snapshot = SnapshotAdapter::toPolicy(NegotiationFixture::snapshot());

        return (new QuoteBandDecider())->decide(
            $snapshot->withBuyerTargetNet(950.0),
            NegotiationFixture::settings()->policy->price,
        );
    }

    public function testAnInBandAskGetsAModelProposal(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"5% for you."}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNotNull($answer->offer);
        self::assertSame(5.0, $answer->offer->price->discountPercent);
        self::assertSame('5% for you.', $answer->modelMessage);
        self::assertSame(1, $spy->calls);
    }

    public function testTheModelIsToldItsAuthorityAndTheMerchantStrategy(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"ok"}']);
        $snapshot = NegotiationFixture::snapshot();

        self::proposer($client)
            ->propose(
                NegotiationFixture::settings(strategy: 'concede in 1% steps'),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertStringContainsString('concede in 1% steps', $spy->systemPrompts[0]);
        self::assertStringContainsString('10', $spy->userPrompts[0], 'The cap must be stated to the model.');
    }

    public function testAProposalOutsideAuthorityIsRejected(): void
    {
        // The model asked for 40% against a 10% cap. The rules, not the model,
        // decide what is permitted — this is the guardrail working.
        [$client] = ScriptedClient::spy(['{"action":"offer","discount_percent":40,"message":"40% off!"}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::ProposalRejected, $answer->escalation);
    }

    public function testTheModelMayDeclineAndItsReasonIsKept(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"escalate","escalation_reason":"buyer wants a term I cannot offer","message":""}',
        ]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $answer->escalation);
        self::assertStringContainsString('term I cannot offer', $answer->escalationDetail);
    }

    public function testRulesOnlyModePricesFromTheBandWithNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy([]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(rulesOnly: true),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNotNull($answer->offer);
        self::assertSame(0, $spy->calls, 'Rules-only must not let a model choose the number.');
        self::assertNull($answer->promptHash);
    }
}
