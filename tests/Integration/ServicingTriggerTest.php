<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The trigger against real Shopware events, with a collecting bus in place of
 * the real one — the same hand-built-collaborator approach IntegrationTestCase
 * uses for the gateway. What is real here is the events: SwagCommercial's own
 * comment write and the core state machine, not a constructed event object.
 */
final class ServicingTriggerTest extends IntegrationTestCase
{
    /**
     * Issue #4's "the agent's own comment does not re-trigger servicing", on
     * the real write path. This is the test that fails if the Context stamp
     * ever stops surviving QuoteCommenter's scope().
     */
    public function testAnAgentCommentDoesNotQueueTheQuote(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($quoteId): void {
            static::gateway()->addComment($quoteId, 'ServicingTriggerTest agent reply');
        });

        self::assertSame([], $bus->messages, 'The agent re-triggered itself by writing a comment.');
    }

    /**
     * `in_review` is where the agent's own `process` transition lands, so it
     * must not queue anything — otherwise claiming a quote queues it again.
     */
    public function testTheAgentsOwnProcessTransitionDoesNotQueueTheQuote(): void
    {
        $quoteId = QuoteFixture::quoteInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($quoteId): void {
            static::gateway()->transition($quoteId, QuoteTransition::Process);
        });

        self::assertSame([], $bus->messages);
    }

    private function withTrigger(object $bus, callable $write): void
    {
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, $dispatcher);

        $trigger = new QuoteServicingTrigger($bus);
        $dispatcher->addSubscriber($trigger);

        try {
            $write();
        } finally {
            $dispatcher->removeSubscriber($trigger);
        }
    }

    /** @return MessageBusInterface&object{messages: list<ServiceQuoteMessage>} */
    private static function collectingBus(): object
    {
        return new class implements MessageBusInterface {
            /** @var list<ServiceQuoteMessage> */
            public array $messages = [];

            /**
             * @param object|Envelope $message
             * @param array<array-key, \Symfony\Component\Messenger\Stamp\StampInterface> $stamps
             */
            #[\Override]
            public function dispatch($message, array $stamps = []): Envelope
            {
                // Assert::, not self:: — inside an anonymous class self:: is the
                // anonymous class, which has no assertion methods.
                \PHPUnit\Framework\Assert::assertInstanceOf(ServiceQuoteMessage::class, $message);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
