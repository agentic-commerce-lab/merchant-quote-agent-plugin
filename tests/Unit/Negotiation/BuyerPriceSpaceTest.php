<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;

/**
 * The seam between the space the buyer types in and the net space everything
 * behind the bridge works in — driven through AskInterpreter, because the
 * conversion only means anything as a pair: the table the model reads and the
 * target it hands back have to be the same money.
 */
final class BuyerPriceSpaceTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    private static function recorder(): DecisionRecorder
    {
        return new DecisionRecorder(new FakeDecisionWriter());
    }

    public function testTheLineTableSpeaksTheBuyersOwnTaxSpace(): void
    {
        // sw-ag.dev quote 1037: the buyer reads 823.86 on their quote and the
        // model was shown 692.32, the net behind it. Every number the buyer
        // types is in the space they were shown, so that is the space the
        // table has to be in.
        [$client, $spy] = ScriptedClient::spy(['{}']);
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('90 per unit and we have a deal', '2026-08-28 09:00:00'),
        ]);

        (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertStringContainsString('line-1 | Widget | 10 | 100.00 | none', $spy->userPrompts[0]);
    }

    public function testAGrossTargetPriceComesBackNet(): void
    {
        // The other half of the same seam: what the model reads in the buyer's
        // space must reach the policy layer in net, or a counter is filed one
        // tax factor above what was asked for — quote 1037 stored 885.65 for a
        // 744.24 ask, and the agent then "granted" 0.21% on a 14% ask.
        [$client] = ScriptedClient::spy([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
        ]);
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('90 per unit and we have a deal', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertSame(72.0, $result?->interpretation->structural->lineChanges[0]->targetUnitPrice);
    }

    public function testAGrossQuoteLevelBudgetComesBackNet(): void
    {
        // A budget covers whatever the quote covers, so it converts on the
        // quote's own blended ratio (800 net behind 1000 gross), not on a
        // line's: 900 gross is a 720 net target, a 10% ask on an 800 net
        // quote — not the 12.5% the raw figure implies.
        [$client] = ScriptedClient::spy(['{"price":{"targetTotal":900.0}}']);
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('max cost should be 900', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertSame(720.0, $result?->interpretation->price->targetTotal);
    }

    public function testTheModelIsShownTheQuoteTotalItHasToPlaceANumberAgainst(): void
    {
        // Which level "2500" belongs to is a comparison, and the total is half
        // of it. In the buyer's space like the table, or the two halves would
        // be different money.
        [$client, $spy] = ScriptedClient::spy(['{}']);
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('max cost should be 900', '2026-08-28 09:00:00'),
        ]);

        (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertStringContainsString('Quote total: 1000.00', $spy->userPrompts[0]);
    }

    public function testANetQuoteLeavesTheTargetAlone(): void
    {
        [$client] = ScriptedClient::spy([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
        ]);
        $snapshot = NegotiationFixture::snapshot([
            NegotiationFixture::buyerComment('90 per unit please', '2026-08-28 09:00:00'),
        ]);

        $result = (new AskInterpreter($client, self::prompts(), self::recorder()))->interpret(
            NegotiationFixture::settings(),
            $snapshot,
            SnapshotAdapter::conversation($snapshot),
        );

        self::assertSame(90.0, $result?->interpretation->structural->lineChanges[0]->targetUnitPrice);
    }
}
