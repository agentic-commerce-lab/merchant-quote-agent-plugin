<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\PendingEscalation;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

/**
 * QA-05: a merchant comment as an answer to an escalation. Its own class
 * beside PendingEscalationTest, which is at the method-count limit.
 *
 * SwagCommercial's send from `replied` saves the quote and posts the message
 * without a transition, so there the merchant's comment IS their send — and
 * the terms on a `replied` quote are the ones the buyer was sent, so the
 * receipt invariant in PendingEscalation's docblock holds. Anywhere else a
 * comment may be a note written over half-edited prices nobody sent.
 */
final class PendingEscalationCommentTest extends TestCase
{
    public function testAMerchantCommentAfterTheEscalationAnswersItOnlyWhileReplied(): void
    {
        self::assertFalse(PendingEscalation::awaitsAHuman(self::commented('replied', '2026-09-23 10:00:00')));

        foreach (['in_review', 'change_requested', 'reopen', 'open'] as $state) {
            self::assertTrue(
                PendingEscalation::awaitsAHuman(self::commented($state, '2026-09-23 10:00:00')),
                sprintf('A comment while the quote is "%s" released the escalation.', $state),
            );
        }
    }

    public function testOnlyAMerchantCommentNewerThanTheEscalationAnswersIt(): void
    {
        self::assertTrue(PendingEscalation::awaitsAHuman(self::commented('replied', '2026-09-23 08:00:00')));
        self::assertTrue(
            PendingEscalation::awaitsAHuman(self::commented('replied', '2026-09-23 09:00:00')),
            'A tie stays with the human.',
        );
        self::assertFalse(
            PendingEscalation::awaitsAHuman(self::commented(
                'replied',
                '2026-09-23 10:00:00',
                sentAt: '2026-09-23 08:00:00',
            )),
            'The newer answer wins: a send from before the escalation must not mask a comment after it.',
        );
    }

    /** Escalated at 09:00, as PendingEscalationTest's own fixture. */
    private static function commented(string $state, string $commentedAt, ?string $sentAt = null): QuoteLifecycle
    {
        return new QuoteLifecycle(
            stateTechnicalName: $state,
            customFields: [
                QuoteEscalator::MARKER_KEY => QuoteEscalationReason::DiscountLimitExceeded->value,
                PendingEscalation::ESCALATED_AT_KEY => (new \DateTimeImmutable('2026-09-23 09:00:00'))->format('U.u'),
            ],
            lastAdminTransitionAt: $sentAt === null ? null : new \DateTimeImmutable($sentAt),
            lastAdminTransitionTo: $sentAt === null ? null : 'replied',
            lastAdminCommentAt: new \DateTimeImmutable($commentedAt),
        );
    }
}
