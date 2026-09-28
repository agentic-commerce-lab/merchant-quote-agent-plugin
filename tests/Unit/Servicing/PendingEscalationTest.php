<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\PendingEscalation;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

final class PendingEscalationTest extends TestCase
{
    public function testAQuoteNeverEscalatedAwaitsNobody(): void
    {
        self::assertFalse(PendingEscalation::awaitsAHuman(new QuoteLifecycle('change_requested')));
        self::assertFalse(PendingEscalation::awaitsAHuman(new QuoteLifecycle('change_requested', customFields: [
            QuoteEscalator::MARKER_KEY => null,
        ])));
    }

    public function testAnEscalationNoHumanHasAnsweredAwaitsOne(): void
    {
        self::assertTrue(PendingEscalation::awaitsAHuman(self::escalated()));
    }

    public function testAHumanSendingAfterTheEscalationAnswersIt(): void
    {
        self::assertFalse(PendingEscalation::awaitsAHuman(self::escalated(sentAt: '2026-09-23 10:00:00')));
    }

    public function testAHumanSendingBeforeTheEscalationDoesNotAnswerIt(): void
    {
        self::assertTrue(PendingEscalation::awaitsAHuman(self::escalated(sentAt: '2026-09-23 08:00:00')));
    }

    /**
     * Only a send is an answer. A merchant who moved the quote to `in_review`
     * is still working on it, possibly with half-edited prices, and the
     * acknowledgement's move to `replied` would publish those with an A2CN
     * approval receipt nobody gave.
     */
    public function testAHumanMovingTheQuoteAnywhereButRepliedDoesNotAnswerIt(): void
    {
        self::assertTrue(PendingEscalation::awaitsAHuman(self::escalated(
            sentAt: '2026-09-23 10:00:00',
            to: 'in_review',
        )));
    }

    /** A marker written before the time was recorded stays with the human until one sends the quote. */
    public function testAnUndatedEscalationAwaitsAHumanUntilOneSends(): void
    {
        self::assertTrue(PendingEscalation::awaitsAHuman(self::undated(to: null)));
        self::assertTrue(PendingEscalation::awaitsAHuman(self::undated(to: 'in_review')));
    }

    /**
     * An undated marker cannot be ordered against the send, so any send
     * releases it. Otherwise, now that an open escalation stands the agent
     * down, a legacy quote would stay silent for good.
     */
    public function testAnyHumanSendReleasesAnUndatedEscalation(): void
    {
        self::assertFalse(PendingEscalation::awaitsAHuman(self::undated(to: 'replied')));
    }

    private static function undated(?string $to): QuoteLifecycle
    {
        return new QuoteLifecycle(
            stateTechnicalName: 'change_requested',
            customFields: [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value],
            lastAdminTransitionAt: $to === null ? null : new \DateTimeImmutable('2026-09-23 10:00:00'),
            lastAdminTransitionTo: $to,
        );
    }

    private static function escalated(?string $sentAt = null, string $to = 'replied'): QuoteLifecycle
    {
        return new QuoteLifecycle(
            stateTechnicalName: 'change_requested',
            customFields: [
                QuoteEscalator::MARKER_KEY => QuoteEscalationReason::DiscountLimitExceeded->value,
                PendingEscalation::ESCALATED_AT_KEY => self::at('2026-09-23 09:00:00'),
            ],
            lastAdminTransitionAt: $sentAt === null ? null : new \DateTimeImmutable($sentAt),
            lastAdminTransitionTo: $sentAt === null ? null : $to,
        );
    }

    private static function at(string $moment): string
    {
        return (new \DateTimeImmutable($moment))->format('U.u');
    }
}
