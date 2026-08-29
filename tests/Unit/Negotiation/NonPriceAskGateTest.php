<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The gate that sends a non-price ask to a human, at the one boundary that
 * decides whether a sub-ask was stated at all.
 *
 * Nothing carries shipping, payment terms or bundles past the interpreter, so
 * a misread here does not fail loudly — it answers the price half and drops
 * the rest in silence, which is the whole reason the gate exists.
 */
final class NonPriceAskGateTest extends TestCase
{
    public function testANonPriceAskOfZeroIsStillAnAsk(): void
    {
        // #31: the gate read the sub-asks with plain truthiness, so a literal
        // `0` was indistinguishable from "not asked". "Pay on delivery" is
        // exactly `requested_net_days: 0` — a real ask that used to be dropped
        // in silence while the price half was answered, which is the failure
        // this gate exists to stop. `false` must still mean no, which is why
        // the fix filters on `!== null && !== false` rather than on null.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent":5,"negotiation":{"delivery":{"free_shipping":false,'
                . '"expedited":false,"requested_lead_time_days":null},"payment":{"requested_term":null,'
                . '"requested_net_days":0,"requested_deposit_percent":null},"bundle":{"requested":false}}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off, and can we pay on delivery?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A non-price ask must not reach the negotiate call.');
    }

    public function testAllFalseSubAsksAreStillNotAnAsk(): void
    {
        // The other half of the same fix: the extract prompt says to null the
        // whole object when nothing was asked, but a model happily emits the
        // full shape with `false` in it. That is the model saying no, and an
        // in-band price ask must still be answered rather than escalated.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent":5,"negotiation":{"delivery":{"free_shipping":false,'
                . '"expedited":false,"requested_lead_time_days":null},"payment":{"requested_term":null,'
                . '"requested_net_days":null,"requested_deposit_percent":null},"bundle":{"requested":false}}}',
            '{"action":"offer","discount_percent":5,"message":"5% it is."}',
            'Five percent off.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Offered, $outcome);
    }
}
