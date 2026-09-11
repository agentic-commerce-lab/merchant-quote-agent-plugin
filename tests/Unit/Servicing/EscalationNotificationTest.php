<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotifierInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

/**
 * The merchant's half of an escalation. QuoteEscalator's copy is customer-
 * facing by design, so before this the merchant got a log line and nothing
 * else — these tests pin the notification onto the same once-per-quote-per-
 * reason marker the buyer comment already uses.
 */
final class EscalationNotificationTest extends TestCase
{
    private static function notifier(bool $throwing = false): EscalationNotifierInterface
    {
        return new class($throwing) implements EscalationNotifierInterface {
            /** @var list<EscalationNotice> */
            public array $notices = [];

            public function __construct(
                private readonly bool $throwing,
            ) {}

            #[\Override]
            public function notify(EscalationNotice $notice): void
            {
                $this->notices[] = $notice;

                if ($this->throwing) {
                    throw new \RuntimeException('the mail transport is down');
                }
            }
        };
    }

    public function testTheMerchantIsNotifiedWithTheReasonTheBuyerNeverSees(): void
    {
        $notifier = self::notifier();
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator($notifier))->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::ProposalRejected,
            notifyBuyer: true,
        );

        self::assertCount(1, $notifier->notices);
        $notice = $notifier->notices[0];
        self::assertSame('q1', $notice->quoteId);
        self::assertSame('10001', $notice->quoteNumber);
        self::assertSame('sc1', $notice->salesChannelId);
        self::assertSame(QuoteEscalationReason::ProposalRejected, $notice->reason);

        // The buyer's own comment still carries nothing internal.
        self::assertCount(1, $gateway->comments);
        self::assertStringNotContainsString('proposal', strtolower($gateway->comments[0]));
    }

    public function testTheMerchantIsNotifiedEvenWhenBuyerNotificationIsDisabled(): void
    {
        $notifier = self::notifier();
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator($notifier))->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::ProposalRejected,
            notifyBuyer: false,
        );

        self::assertCount(1, $notifier->notices);
        self::assertCount(0, $gateway->comments);
        self::assertSame(['updateQuote'], $gateway->calls);
    }

    /**
     * The marker gates the notification exactly as it gates the comment, so a
     * talkative buyer cannot turn one escalation into a stream of notices.
     */
    public function testAnAlreadyEscalatedQuoteNotifiesNobodyAgain(): void
    {
        $notifier = self::notifier();
        $snapshot = QuoteSnapshotFixture::snapshot(customFields: [
            QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value,
        ]);
        $gateway = new FakeQuoteGateway([$snapshot]);

        (new QuoteEscalator($notifier))->escalate($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview);

        self::assertSame([], $notifier->notices);
        self::assertSame([], $gateway->comments);
    }

    /** A different reason is a different escalation, and worth telling. */
    public function testADifferentReasonNotifiesAgain(): void
    {
        $notifier = self::notifier();
        $snapshot = QuoteSnapshotFixture::snapshot(customFields: [
            QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value,
        ]);

        (new QuoteEscalator($notifier))->escalate(
            new FakeQuoteGateway([$snapshot]),
            $snapshot,
            QuoteEscalationReason::ProposalRejected,
        );

        self::assertCount(1, $notifier->notices);
    }

    /**
     * The buyer being told is worth more than the merchant being pinged: a
     * broken notification channel must not lose the comment or fail the pass,
     * which would leave Messenger retrying an escalation that already happened.
     */
    public function testANotifierThatThrowsDoesNotBreakTheEscalation(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator(self::notifier(throwing: true)))->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NeedsHumanReview,
            notifyBuyer: true,
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
            'The marker was not stamped, so this quote will escalate again.',
        );
    }

    /** A shop with no notifier configured still escalates, as it did before. */
    public function testEscalationWorksWithNoNotifierAtAll(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NeedsHumanReview,
            notifyBuyer: true,
        );

        self::assertCount(1, $gateway->comments);
    }
}
