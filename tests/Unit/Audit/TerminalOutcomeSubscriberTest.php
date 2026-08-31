<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeSubscriber;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;

final class TerminalOutcomeSubscriberTest extends TestCase
{
    public function testItSubscribesToTheCoreStateChangeEvent(): void
    {
        self::assertSame(
            ['state_machine.quote.state_changed' => 'onQuoteStateChanged'],
            TerminalOutcomeSubscriber::getSubscribedEvents(),
        );
    }

    public function testEachTerminalStateIsRecorded(): void
    {
        foreach (['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'] as $state) {
            $writer = new FakeTerminalOutcomeWriter();
            self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));

            self::assertCount(1, $writer->calls, sprintf('Entering "%s" recorded nothing.', $state));
            self::assertSame('q1', $writer->calls[0]['quoteId']);
            self::assertSame($state, $writer->calls[0]['state']);
        }
    }

    /**
     * Only `accepted` is graph-terminal; the other four have a `reopen` or
     * `admin_resend` edge out. "Terminal" here is a business label, so the
     * non-terminal states have to be excluded by name.
     */
    public function testANonTerminalStateRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();
        $subscriber = self::subscriber($writer);

        foreach (['draft', 'open', 'in_review', 'replied', 'change_requested', 'reopen'] as $state) {
            $subscriber->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));
        }

        self::assertSame([], $writer->calls);
    }

    /** The event fires twice per transition, leave then enter. Only entering is news. */
    public function testTheLeaveSideRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();

        self::subscriber($writer)
            ->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
                'accepted',
                StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
            ));

        self::assertSame([], $writer->calls);
    }

    /** A snapshot-lane transition is not a merchant's decision about a quote. */
    public function testATransitionOnTheSnapshotVersionRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();

        self::subscriber($writer)
            ->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
                'accepted',
                StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
                QuoteTriggerEventFixture::snapshotContext(),
            ));

        self::assertSame([], $writer->calls);
    }

    /**
     * A merchant clicking accept must never see a 500 because an audit write
     * failed, and SwagCommercial's expiry task must not abort its batch.
     */
    public function testAFailingWriterDoesNotBreakTheTransition(): void
    {
        $writer = new FakeTerminalOutcomeWriter();
        $writer->throws = new \RuntimeException('the database went away');

        self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('accepted'));

        self::assertCount(1, $writer->calls, 'The writer was never reached.');
    }

    private static function subscriber(FakeTerminalOutcomeWriter $writer): TerminalOutcomeSubscriber
    {
        return new TerminalOutcomeSubscriber($writer, new NullLogger());
    }
}
