<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The gate for the one ask that arrives without a comment: a per-line target
 * the buyer typed into the storefront, which SwagCommercial keeps in
 * `quote_line_item.requested_price`.
 */
final class StructuredAskGateTest extends TestCase
{
    public function testAStructuredPriceAskIsAnsweredWithoutAComment(): void
    {
        // The storefront's per-line "Requested price" field is an ask on its
        // own: SwagCommercial writes it to `quote_line_item.requested_price`
        // and a buyer who uses it need not also type a comment. The pipeline
        // used to read the conversation as the only source of an ask and
        // return NothingToDo here, so a quote whose line said 98 against a
        // quoted 100 was recorded as "no action needed" and never answered.
        //
        // No extract call: there is no comment to interpret, and the ask is
        // already structured. Two calls, not three.
        $harness = PipelineHarness::with(
            [
                '{"action":"offer","message":"2% off.","terms":{"discountPercent":2}}',
                'We can do 2%.',
            ],
            reReadTotalNet: 980.0,
        );
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 98.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(2, $harness->spy->calls, 'A structured ask needs no extraction call.');
    }

    public function testAStructuredPriceThatIsNotBelowTheQuotedOneIsNotAnAsk(): void
    {
        // `requested_price` is sticky: it stays on the line after the agent has
        // answered it, and QuoteAutoReplyPricer only ever takes it as
        // `min(requested, quoted)`. Treating its mere presence as an ask would
        // make every later trigger on an answered quote look like new work.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 100.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame(0, $harness->spy->calls);
        self::assertSame([], $harness->gateway->calls);
    }
}
