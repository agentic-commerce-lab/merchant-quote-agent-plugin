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
 *
 * What counts is the state the quote was in WHEN the merchant commented, not
 * at the pass: a comment triggers no pass, and the buyer's counter that does
 * has already moved the quote out of `replied` by the time the pass reads it.
 */
final class PendingEscalationCommentTest extends TestCase
{
    public function testACommentWhileRepliedAnswersEvenAfterTheBuyerCountered(): void
    {
        // change_requested is trunk's request_change target, reopen 6.7.12's.
        foreach (['change_requested', 'reopen', 'replied'] as $now) {
            self::assertFalse(
                PendingEscalation::awaitsAHuman(self::commented('replied', '2026-09-23 10:00:00', now: $now)),
                sprintf('A comment written while replied did not release the escalation once "%s".', $now),
            );
        }
    }

    public function testACommentInAnyOtherStateDoesNotAnswer(): void
    {
        // Read `replied` at the pass, as if the quote had been sent since: only
        // the state at the comment counts.
        foreach (['in_review', 'change_requested', 'open'] as $state) {
            self::assertTrue(
                PendingEscalation::awaitsAHuman(self::commented($state, '2026-09-23 10:00:00', now: 'replied')),
                sprintf('A comment while the quote was "%s" released the escalation.', $state),
            );
        }

        self::assertTrue(
            PendingEscalation::awaitsAHuman(self::commented(null, '2026-09-23 10:00:00', now: 'replied')),
            'A comment with no history row at or before it released the escalation.',
        );
    }

    public function testAnAdminTransitionAfterTheCommentSupersedesIt(): void
    {
        self::assertTrue(
            PendingEscalation::awaitsAHuman(self::commented(
                'replied',
                '2026-09-23 10:00:00',
                now: 'in_review',
                adminTransition: ['2026-09-23 11:00:00', 'in_review'],
            )),
            'A merchant who withdrew the quote after commenting is still working on it.',
        );
    }

    public function testOnlyACommentNewerThanTheEscalationAnswersIt(): void
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
                adminTransition: ['2026-09-23 08:00:00', 'replied'],
            )),
            'The newer answer wins: a send from before the escalation must not mask a comment after it.',
        );
    }

    /** An undated marker cannot be ordered, so any comment while replied releases it, as any send does. */
    public function testAnUndatedMarkerIsReleasedOnlyByACommentWhileReplied(): void
    {
        self::assertFalse(PendingEscalation::awaitsAHuman(self::commented(
            'replied',
            '2026-09-23 10:00:00',
            now: 'change_requested',
            escalatedAt: null,
        )));
        self::assertTrue(PendingEscalation::awaitsAHuman(self::commented(
            'change_requested',
            '2026-09-23 10:00:00',
            now: 'change_requested',
            escalatedAt: null,
        )));
    }

    /**
     * Escalated at 09:00 by default, as PendingEscalationTest's own fixture;
     * a null `$escalatedAt` is a legacy marker written without its time.
     *
     * @param array{0: string, 1: string}|null $adminTransition when, and into which state
     */
    private static function commented(
        ?string $stateAtComment,
        string $commentedAt,
        string $now = 'change_requested',
        ?array $adminTransition = null,
        ?string $escalatedAt = '2026-09-23 09:00:00',
    ): QuoteLifecycle {
        $customFields = [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::DiscountLimitExceeded->value];

        if ($escalatedAt !== null) {
            $customFields[PendingEscalation::ESCALATED_AT_KEY] = (new \DateTimeImmutable($escalatedAt))->format('U.u');
        }

        return new QuoteLifecycle(
            stateTechnicalName: $now,
            customFields: $customFields,
            lastAdminTransitionAt: $adminTransition === null ? null : new \DateTimeImmutable($adminTransition[0]),
            lastAdminTransitionTo: $adminTransition[1] ?? null,
            lastAdminCommentAt: new \DateTimeImmutable($commentedAt),
            stateAtLastAdminComment: $stateAtComment,
        );
    }
}
