<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\EscalationResolutionSubscriber;
use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;

final class EscalationResolutionSubscriberTest extends TestCase
{
    public function testItSubscribesToTheCoreStateChangeEvent(): void
    {
        self::assertSame(
            ['state_machine.quote.state_changed' => 'onQuoteStateChanged'],
            EscalationResolutionSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * ANY state a quote can be moved into resolves an open escalation — the
     * deal desk sending a revised offer, declining it, the buyer withdrawing
     * it. The writer decides whether there is an escalation to resolve; the
     * subscriber's job is only to report that something happened.
     */
    public function testEveryEnteredStateIsReported(): void
    {
        foreach (['sent', 'declined', 'accepted', 'in_review', 'withdrawn'] as $state) {
            $writer = new FakeEscalationResolutionWriter();
            self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));

            self::assertCount(1, $writer->calls, sprintf('Entering "%s" reported nothing.', $state));
            self::assertSame('q1', $writer->calls[0]['quoteId']);
            self::assertSame($state, $writer->calls[0]['state']);

            // Seconds, not milliseconds: this must not be flaky.
            $secondsAgo = time() - $writer->calls[0]['at']->getTimestamp();
            self::assertLessThan(5, abs($secondsAgo), 'The recorded timestamp is not close to now.');
        }
    }

    /** The event fires twice per transition, leave then enter. Only entering is news. */
    public function testTheLeaveSideReportsNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
                'sent',
                StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
                fromState: 'in_review',
            ));

        self::assertSame([], $writer->calls);
    }

    /** A snapshot-lane transition is a mirror, never a merchant's decision. */
    public function testATransitionOnTheSnapshotVersionReportsNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
                'sent',
                context: QuoteTriggerEventFixture::snapshotContext(),
            ));

        self::assertSame([], $writer->calls);
    }

    /**
     * The agent drives `process` (OfferApplier) and `sent` (ReplyComposer)
     * mid-pass, BEFORE DecisionRecorder::finish() inserts the current pass's
     * own audit row. At that moment the newest row on the quote still belongs
     * to the PREVIOUS pass — and if that pass escalated, it is exactly the
     * row EscalationResolutionWriter's guards would match: `outcome ===
     * 'escalated'` and `resolvedAt === null`. Without this guard, the agent's
     * own next pass would record itself as the human resolution. This test
     * would fail without the AgentContext::STATE check.
     */
    public function testATransitionUnderAgentContextReportsNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('sent', context: AgentContext::create()));

        self::assertSame([], $writer->calls);
    }

    /**
     * A merchant clicking "send offer" must never see a 500 because an audit
     * write failed.
     */
    public function testAThrowingWriterIsSwallowedAndLogged(): void
    {
        $writer = new FakeEscalationResolutionWriter();
        $writer->throws = new \RuntimeException('the DAL is unwell');

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        self::subscriber($writer, $logger)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('sent'));

        self::assertCount(1, $logger->messages, 'The failure was not logged.');
    }

    /**
     * A logger that itself misbehaves must not fail the quote's transition
     * either: a failing DAL write is exactly the situation most likely to come
     * with a failing log handler.
     */
    public function testAThrowingLoggerIsSwallowed(): void
    {
        $writer = new FakeEscalationResolutionWriter();
        $writer->throws = new \RuntimeException('the DAL is unwell');

        $logger = new class extends AbstractLogger {
            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('the logger is unwell too');
            }
        };

        self::subscriber($writer, $logger)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('sent'));

        $this->expectNotToPerformAssertions();
    }

    private static function subscriber(
        FakeEscalationResolutionWriter $writer,
        ?LoggerInterface $logger = null,
    ): EscalationResolutionSubscriber {
        return new EscalationResolutionSubscriber($writer, $logger ?? new NullLogger());
    }
}
