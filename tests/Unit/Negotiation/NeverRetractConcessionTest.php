<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\CappedAuthority;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A later round never takes back what the buyer already holds (design note
 * 2026-09-28). PM testing: 67 passes raised the quote total and
 * verification_failed was 77 of 104 escalations, because round two was capped
 * at the buyer's new, smaller figure and a quote-wide write replaced or
 * stacked on the discount round one had granted.
 */
final class NeverRetractConcessionTest extends TestCase
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

    /** Round one cut line-1 from 100.00 to 86.00 per line: 14% off the baseline. */
    private static function cutPerLine(): QuoteSnapshot
    {
        return NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 860.0),
            NegotiationFixture::baselineOf(1000.0, 100.0),
        );
    }

    /** Round one granted 14% quote-wide: line-1 still 100.00, Shopware's discount line takes 140.00 off. */
    private static function cutQuoteWide(): QuoteSnapshot
    {
        $snapshot = NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(), NegotiationFixture::baselineOf(
            1000.0,
            100.0,
        ));

        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: new QuoteTotals(
                totalNet: 860.0,
                discount: new Discount(DiscountType::Percentage, 14.0),
                totalGross: 860.0,
            ),
            lifecycle: $snapshot->lifecycle,
            content: new QuoteContent(lines: [
                ...$snapshot->content->lines,
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('discount', 'Quote discount'),
                    quantity: 1,
                    unitPriceNet: -140.0,
                    totalNet: -140.0,
                ),
            ], comments: $snapshot->content->comments),
        );
    }

    /** The round's settings for a round-two "5% off" on cutPerLine(), as NegotiationPipeline derives them. */
    private static function capped(float $maxDiscountPercent): QuoteAgentSettings
    {
        $live = self::cutPerLine();

        return CappedAuthority::forRound(
            NegotiationFixture::settings(maxDiscountPercent: $maxDiscountPercent),
            SnapshotAdapter::anchored($live),
            $live->totals->totalNet,
            new InterpretedAsk(
                new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: 5.0)),
                'extract-hash',
            ),
        );
    }

    public function testASmallerAskInALaterRoundNeverCapsTheRoundBelowTheStandingConcession(): void
    {
        self::assertEqualsWithDelta(
            14.0,
            self::capped(20.0)->policy->price->maxDiscountPercent,
            1e-9,
            'Capped at the buyer\'s 5%, the round must take back 9% the buyer already holds.',
        );
        self::assertSame(
            10.0,
            self::capped(10.0)->policy->price->maxDiscountPercent,
            'The standing concession must never lift the cap above the merchant\'s maximum.',
        );
    }

    /**
     * (a) Round two, "5% off" after a 14% grant: the model obeys the buyer's
     * figure against the baseline. That is a hold, not a 5% cut on top of the
     * 14% and not a retraction to 5%.
     */
    public function testARoundTwoSmallerAskAfterAFourteenPercentGrantRaisesNoLine(): void
    {
        $live = self::cutPerLine();
        $settings = self::capped(20.0);
        $gateway = new FakeQuoteGateway([$live, $live]);

        $applied = self::applier()
            ->apply(
                $gateway,
                $live,
                $settings,
                new ProposedOffer(orderTotalNet: 950.0, price: new OfferedPrice(discountPercent: 5.0)),
            );

        self::assertTrue($applied->verified, implode('; ', $applied->violations));
        self::assertSame([], $gateway->lineItemChanges);
        self::assertEqualsWithDelta(
            0.0,
            self::writtenDiscount($gateway),
            1e-6,
            '5% on the already-cut 86.00 stacks to 18.3% off the baseline.',
        );
    }

    /**
     * (b) A hold, or a smaller figure, on a quote that already carries a 14%
     * quote discount must keep 14% rather than overwrite it.
     */
    #[DataProvider('holds')]
    public function testAQuoteWideHoldKeepsTheBuyersPrice(?float $percent): void
    {
        $live = self::cutQuoteWide();
        $gateway = new FakeQuoteGateway([$live, $live]);

        $applied = self::applier()
            ->apply(
                $gateway,
                $live,
                NegotiationFixture::settings(maxDiscountPercent: 20.0),
                new ProposedOffer(orderTotalNet: 860.0, price: new OfferedPrice(discountPercent: $percent)),
            );

        self::assertTrue($applied->verified, implode('; ', $applied->violations));
        self::assertEqualsWithDelta(14.0, self::writtenDiscount($gateway), 1e-6);
    }

    /** @return iterable<string, array{?float}> */
    public static function holds(): iterable
    {
        yield 'no percentage' => [null];
        yield 'a smaller percentage' => [5.0];
    }

    /**
     * (c) A per-line offer priced from the baseline (95.00) raises a line the
     * buyer already has at 86.00. Caught BEFORE the write: nothing lands on the
     * quote and the pass escalates as a rejected proposal, not as a
     * verification failure of a price already sitting on the quote.
     */
    public function testAnOfferThatWouldRaiseALineIsRejectedBeforeAnythingIsWritten(): void
    {
        $live = self::cutPerLine();
        $gateway = new FakeQuoteGateway([$live]);
        $reply = '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}';

        $recorder = new DecisionRecorder(new FakeDecisionWriter());
        [$client] = ScriptedClient::spy([$reply, 'unused']);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder, new FakeCustomerHistoryFactory()),
            self::applier(),
            new ReplyComposer($client, $prompts, new NullLogger(), $recorder),
            new QuoteEscalator(),
            new NullLogger(),
        );
        $settings = NegotiationFixture::settings(maxDiscountPercent: 20.0);
        $grant = new NegotiationDecision(Band::Grant, (new QuoteBandDecider())->decide(
            SnapshotAdapter::toPolicy(NegotiationFixture::snapshot())->withBuyerTargetNet(950.0),
            $settings->policy->price,
        ));

        $pass = $round->play($gateway, $live, $settings, $grant, null);

        self::assertSame(QuoteEscalationReason::ProposalRejected, $pass->escalationReason);
        self::assertSame([], $gateway->lineItemChanges, 'The raised line price was written.');
        foreach ($gateway->quoteUpdates as $update) {
            self::assertNull($update->discount, 'A discount was written for a rejected offer.');
            self::assertNull($update->expiresAt, 'The offer\'s expiry was written for a rejected offer.');
        }
    }

    private static function writtenDiscount(FakeQuoteGateway $gateway): ?float
    {
        $discount = null;
        foreach ($gateway->quoteUpdates as $update) {
            $discount = $update->discount->value ?? $discount;
        }

        return $discount;
    }
}
