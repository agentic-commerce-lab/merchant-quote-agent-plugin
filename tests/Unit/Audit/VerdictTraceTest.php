<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\VerdictTrace;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use PHPUnit\Framework\TestCase;

final class VerdictTraceTest extends TestCase
{
    public function testAGrantCarriesItsFiguresInMetaAndTheWholeDecisionInContent(): void
    {
        $decision = new NegotiationDecision(
            Band::Grant,
            QuoteDecision::autoReply(new QuoteAutoReplyDetails(5.0, false, [], 14, 7.5)),
        );

        [$meta, $content] = VerdictTrace::of($decision);

        self::assertSame(TraceKind::PolicyVerdict->metaKeys(), array_keys($meta));
        self::assertSame('grant', $meta['overall']);
        self::assertSame(5.0, $meta['discountPercent']);
        self::assertSame(14, $meta['validityDays']);
        self::assertSame(7.5, $meta['counteredRequestPercent']);
        self::assertNull($meta['escalationReason']);
        self::assertSame('grant', $content['overall']);
    }

    public function testTheModelsHumanReviewSentencesStayInContent(): void
    {
        // QuoteEscalationDetails::$humanReviewRequests are sentences the
        // extract model wrote out of the buyer's message: free text, so never
        // meta, which leaves in every export.
        $decision = new NegotiationDecision(
            Band::Escalate,
            QuoteDecision::escalate(
                new QuoteEscalationDetails(
                    QuoteEscalationReason::NeedsHumanReview,
                    30.0,
                    ['Anna wants to talk to a person.'],
                ),
            ),
            ['price: needs_human_review'],
        );

        [$meta, $content] = VerdictTrace::of($decision);

        self::assertSame(QuoteEscalationReason::NeedsHumanReview->value, $meta['escalationReason']);
        self::assertSame(30.0, $meta['requestedDiscountPercent']);
        self::assertSame(['price: needs_human_review'], $meta['escalationReasons']);
        self::assertStringNotContainsString('Anna', json_encode($meta, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Anna', json_encode($content, JSON_THROW_ON_ERROR));
    }
}
