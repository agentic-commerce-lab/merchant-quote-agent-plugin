<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\CappedAuthority;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the buyer holds, measured the way both verifier checks measure it
 * (design note 2026-09-28, review follow-ups).
 */
final class StandingConcessionTest extends TestCase
{
    private static function applier(): OfferApplier
    {
        return new OfferApplier(
            new OfferVerifier(),
            new NullLogger(),
            new DecisionRecorder(new FakeDecisionWriter()),
            new MarginFloorGuard(new FakePurchasePrices()),
        );
    }

    /**
     * @param array<string, float> $prices lineItemId => live unit price, one unit each, all baselined at 100
     */
    private static function quote(array $prices, float $totalNet): QuoteSnapshot
    {
        $snapshot = NegotiationFixture::snapshot();
        $lines = [];
        $baseline = [];
        foreach ($prices as $id => $price) {
            $lines[] = new QuoteLineSnapshot(new QuoteLineIdentity($id, $id), 1, $price, $price);
            $baseline[] = ['lineItemId' => $id, 'unitPriceNet' => 100.0, 'quantity' => 1];
        }

        return NegotiationFixture::withCustomFields(
            new QuoteSnapshot(
                identity: $snapshot->identity,
                revision: $snapshot->revision,
                totals: new QuoteTotals(totalNet: $totalNet, totalGross: $totalNet),
                lifecycle: $snapshot->lifecycle,
                content: new QuoteContent(lines: $lines, comments: []),
            ),
            [QuoteBaseline::KEY => ['totalNet' => 100.0 * \count($prices), 'lines' => $baseline]],
        );
    }

    /**
     * MarginFloorClamp makes round one uneven by construction: a at 95, b at
     * 80, 12.5% on the total but 20% on b. Capped at the total, round two's
     * "2% more" refused a hold on b (80 < 87.50) every round — the PM data's
     * line-floor path.
     */
    public function testAnUnevenRoundOneKeepsTheDeepestLineConcessionInTheCap(): void
    {
        $live = self::quote(['a' => 95.0, 'b' => 80.0], 175.0);
        $settings = CappedAuthority::forRound(
            NegotiationFixture::settings(maxDiscountPercent: 25.0),
            SnapshotAdapter::anchored($live),
            SnapshotAdapter::toPolicy($live),
            new InterpretedAsk(
                new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: 2.0)),
                'extract-hash',
            ),
        );

        self::assertEqualsWithDelta(20.0, $settings->policy->price->maxDiscountPercent, 1e-9);

        $applied = self::applier()
            ->apply(
                new FakeQuoteGateway([$live, $live]),
                $live,
                $settings,
                new ProposedOffer(orderTotalNet: 175.0, price: new OfferedPrice()),
            );

        self::assertTrue($applied->verified, implode('; ', $applied->violations));
    }

    /**
     * A total below its lines with no discount line to explain it: the
     * prediction's GoodsFactor would read 1 and a hold would write 0% over
     * whatever takes that money off. Refused before anything is written.
     */
    public function testATotalBelowItsLinesWithNoDiscountLineIsRefusedBeforeTheWrite(): void
    {
        $live = self::quote(['a' => 100.0], 86.0);
        $gateway = new FakeQuoteGateway([$live, $live]);

        $applied = self::applier()
            ->apply(
                $gateway,
                $live,
                NegotiationFixture::settings(maxDiscountPercent: 20.0),
                new ProposedOffer(orderTotalNet: 86.0, price: new OfferedPrice()),
            );

        self::assertFalse($applied->written);
        self::assertNotContains('updateQuote', $gateway->calls);
    }
}
