<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeSubscriber;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
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

            // The spec's own justification for passing the timestamp as an
            // argument is "which is what makes it assertable in the unit
            // test" — so assert it. Seconds, not milliseconds: this must not
            // be flaky.
            $secondsAgo = time() - $writer->calls[0]['at']->getTimestamp();
            self::assertLessThan(5, abs($secondsAgo), 'The recorded timestamp is not close to now.');
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

    /**
     * The event fires twice per transition, leave then enter. Only entering is
     * news. `fromState: 'cancelled'` is load-bearing, not decoration: the LEAVE
     * side reports `previousState`, not `nextState` (see
     * StateMachineStateChangeEvent's constructor), so without an explicit
     * `fromState` this would default to `'draft'` and never reach the
     * TERMINAL_STATES check at all — the enter-side guard would go untested.
     * Each of the four non-`accepted` terminal states has an outgoing edge
     * (`reopen`, `admin_resend`, `admin_extend_expiration`, `admin_cancel`),
     * and the LEAVE side of those transitions reports the terminal state's own
     * name — e.g. reopening a cancelled quote fires a LEAVE event whose
     * `getStateName()` is `'cancelled'`. That is exactly what this test builds.
     */
    public function testTheLeaveSideRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();

        self::subscriber($writer)
            ->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
                'open',
                StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
                fromState: 'cancelled',
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
     * failed, and SwagCommercial's expiry task must not abort its batch. The
     * plan's Global Constraints require logging at error level, so a
     * collecting logger stands in for NullLogger here to assert that.
     */
    public function testAFailingWriterDoesNotBreakTheTransition(): void
    {
        $writer = new FakeTerminalOutcomeWriter();
        $writer->throws = new \RuntimeException('the database went away');
        $logger = self::collectingLogger();

        (new TerminalOutcomeSubscriber($writer, $logger))->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'accepted',
        ));

        self::assertCount(1, $writer->calls, 'The writer was never reached.');
        self::assertCount(1, $logger->records, 'Nothing was logged.');
        self::assertSame('error', $logger->records[0]['level']);
    }

    private static function subscriber(FakeTerminalOutcomeWriter $writer): TerminalOutcomeSubscriber
    {
        return new TerminalOutcomeSubscriber($writer, new NullLogger());
    }

    /** @return LoggerInterface&object{records: list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>} */
    private static function collectingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }
}
