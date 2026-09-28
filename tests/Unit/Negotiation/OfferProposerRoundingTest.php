<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationContext;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ProposedAnswer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Rounding control, discount_percent mode, where the model's answer arrives (spec 2026-09-28). */
final class OfferProposerRoundingTest extends TestCase
{
    /** @return array{0: ProposedAnswer, 1: array<string, mixed>|null} the answer, and the pass's rounding meta */
    private static function propose(
        QuoteAgentSettings $settings,
        float $modelPercent,
        ?float $asked,
        ?float $requestedUnitPrice = null,
    ): array {
        [$client] = ScriptedClient::spy([
            sprintf('{"action":"offer","message":"ok","terms":{"discountPercent":%s}}', $modelPercent),
        ]);
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: $requestedUnitPrice);
        $recorder->begin($snapshot, NegotiationFixture::context());
        $policy = SnapshotAdapter::toPolicy($snapshot);

        $answer = (new OfferProposer(
            $client,
            new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}'),
            new OfferAuthorizer(),
            $recorder,
            new FakeCustomerHistoryFactory(),
        ))->propose(
            $settings,
            $policy,
            (new QuoteBandDecider())->decide($policy->withBuyerTargetNet(950.0), $settings->policy->price),
            new NegotiationContext(
                $snapshot->identity->customerId,
                $snapshot->identity->quoteId,
                SnapshotAdapter::conversation($snapshot),
            ),
            $asked,
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return [$answer, RoundingFixture::roundingMeta($writer)];
    }

    public function testTheModelsPercentageIsAuthorizedRoundedDown(): void
    {
        [$answer, $meta] = self::propose(RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5), 7.34, null);

        self::assertSame(7.0, $answer->offer?->price->discountPercent);
        self::assertSame(
            ['mode' => 'discount_percent', 'step' => 0.5, 'unrounded' => 7.34, 'rounded' => 7.0, 'skipped' => null],
            $meta,
        );
    }

    public function testAPerLineConversionInheritsTheRoundedRate(): void
    {
        // The buyer asked 90.00 on the 100.00 line (10%); the model answered
        // 7.34% quote-wide, and OfferLevelMirror prices the line at the
        // ROUNDED 7.0%.
        [$answer] = self::propose(RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5), 7.34, 10.0, 90.0);

        self::assertEquals([new QuoteLinePrice('line-1', 93.0)], $answer->offer?->price->linePricesNet);
    }

    public function testTheBuyersOwnPercentageIsAuthorizedAsAsked(): void
    {
        [$answer, $meta] = self::propose(RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5), 7.34, 7.34);

        self::assertSame(7.34, $answer->offer?->price->discountPercent);
        self::assertSame('buyer_figure', $meta['skipped'] ?? null);
    }

    public function testWithRoundingOffNothingIsRoundedOrTraced(): void
    {
        [$answer, $meta] = self::propose(NegotiationFixture::settings(), 7.34, null);

        self::assertSame(7.34, $answer->offer?->price->discountPercent);
        self::assertNull($meta);
    }

    /** @return iterable<string, array{0: float, 1: float}> */
    public static function asks(): iterable
    {
        yield 'the model offers exactly what the buyer asked' => [7.34, 7.34];
        yield 'the model offers less than the buyer asked' => [8.0, 7.0];
    }

    /** End to end: OfferRound must hand the buyer's ask to the proposer, or rule 2 never fires. */
    #[DataProvider('asks')]
    public function testThePipelineLeavesOnlyTheBuyersOwnFigureUnrounded(float $asked, float $written): void
    {
        $harness = PipelineHarness::with([
            sprintf('{"price":{"additionalDiscountPercent":%s}}', $asked),
            '{"action":"offer","message":"ok","terms":{"discountPercent":7.34}}',
            PipelineHarness::rewordedReply(),
        ]);

        $harness->pipeline->service(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment(sprintf('%s%% please', $asked), '2026-08-28 09:00:00'),
            ]),
            $harness->gateway,
            RoundingFixture::settings(RoundingMode::DiscountPercent, 0.5),
            NegotiationFixture::context(),
        );

        self::assertEquals(
            new Discount(DiscountType::Percentage, $written),
            RoundingFixture::writtenDiscount($harness->gateway),
        );
    }
}
