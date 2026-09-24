<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;

/**
 * Through the recorder, not QuoteTrace alone: the promise is about what a
 * recorded `quote_before` and `quote_after` hold, so both real recording sites
 * are what is under test.
 */
final class QuoteTraceTest extends TestCase
{
    public function testBothSnapshotsKeepTheQuoteButNeitherTheCompanyNameNorTheOrderId(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $snapshot = self::snapshotOfACompanyWithAnOrder();

        $recorder->begin($snapshot, NegotiationFixture::context());
        $recorder->recordApplied(new AppliedOffer(true, [], $snapshot, 1000.0), ['updateLineItems']);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $trace = $writer->drafts[0]->trace;
        self::assertSame(
            ['quote_before', 'quote_after'],
            array_map(static fn(TraceDraft $t): string => $t->kind->value, $trace),
        );

        foreach ($trace as $event) {
            $encoded = json_encode($event->content, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('Acme Schrauben GmbH', $encoded);
            self::assertStringNotContainsString('order-4711', $encoded);
            self::assertSame('q1', $event->content['identity']['quoteId'] ?? null);
            self::assertSame(['lineCount' => 1], $event->meta);
        }
    }

    private static function snapshotOfACompanyWithAnOrder(): QuoteSnapshot
    {
        $snapshot = NegotiationFixture::snapshot();

        return new QuoteSnapshot(
            identity: new QuoteIdentity(
                quoteId: $snapshot->identity->quoteId,
                quoteNumber: $snapshot->identity->quoteNumber,
                currencyIso: $snapshot->identity->currencyIso,
                salesChannelId: $snapshot->identity->salesChannelId,
                customerId: $snapshot->identity->customerId,
                companyName: 'Acme Schrauben GmbH',
                orderId: 'order-4711',
            ),
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: $snapshot->lifecycle,
            content: $snapshot->content,
        );
    }
}
