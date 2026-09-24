<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
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

    public function testOnlyThePluginsOwnCustomFieldsAreKept(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $snapshot = self::snapshotOfACompanyWithAnOrder();
        $snapshot = new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $snapshot->lifecycle->stateTechnicalName,
                expiresAt: $snapshot->lifecycle->expiresAt,
                customFields: [
                    'merchant_quote_agent_baseline' => ['lines' => []],
                    'a2cn_session' => 'sess-1',
                    ActKey::for(1, ActRole::Buyer) => ['id' => 'act-1'],
                    // A merchant's own field: anything can be in it.
                    'crm_contact_email' => 'anna@acme.example',
                    'merchant_quote_agent_contact_email' => 'anna@acme.example',
                    'merchantQuoteAgentContactEmail' => 'anna@acme.example',
                    'a2cn_contact_email' => 'anna@acme.example',
                    'a2cn_act_0001_b_extra' => 'anna@acme.example',
                ],
            ),
            content: $snapshot->content,
        );

        $recorder->begin($snapshot, NegotiationFixture::context());
        $recorder->recordApplied(new AppliedOffer(true, [], $snapshot, 1000.0), ['updateLineItems']);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        foreach ($writer->drafts[0]->trace as $event) {
            $encoded = json_encode($event->content, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('crm_contact_email', $encoded);
            self::assertStringNotContainsString('anna@acme.example', $encoded);
            self::assertSame(
                [
                    'merchant_quote_agent_baseline' => ['lines' => []],
                    'a2cn_session' => 'sess-1',
                    ActKey::for(1, ActRole::Buyer) => ['id' => 'act-1'],
                ],
                $event->content['lifecycle']['customFields'] ?? null,
            );
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
