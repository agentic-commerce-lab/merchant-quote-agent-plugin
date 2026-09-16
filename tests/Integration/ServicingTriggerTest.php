<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
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
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($quoteId): void {
            static::gateway()->transition($quoteId, QuoteTransition::Process);
        });

        self::assertSame([], $bus->messages);
    }

    /**
     * The positive half of the trigger, against a real core event: a buyer
     * (not the agent — Context::createDefaultContext(), never the gateway,
     * which stamps AgentContext::STATE) driving a real transition through
     * StateMachineRegistry produces exactly one message. Without this,
     * "the core event name, the live-version filter and the state whitelist
     * agree" was proven only by hand-built events, never end to end.
     *
     * Drives `customer_send` (draft -> open) with a raw core Transition
     * rather than `request_change` (replied -> change_requested) through
     * QuoteStateTransitioner: the seed has no `replied` quote with a line
     * item, and `customer_send` is the more central case regardless — it is
     * the buyer's actual quote-request action, the exact transition
     * QuoteServicingTrigger's own docblock names as why `quote.requested`
     * needs no separate subscription. It is deliberately not a
     * QuoteTransition case (that enum is scoped to the agent's own actions),
     * so this goes straight to the core registry rather than through our
     * gateway. `change_requested` stays unproven end to end as a result —
     * a known coverage gap, not one to close by seeding data.
     */
    public function testABuyerDrivenTransitionIntoATriggerStateQueuesTheQuoteOnce(): void
    {
        try {
            $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'draft');
        } catch (\RuntimeException $e) {
            self::markTestSkipped(
                $e->getMessage()
                . ' Note: on a released SwagCommercial, QuoteRequestRoute creates a quote '
                . 'directly in "open" — there is no draft step — so this shop may legitimately never have '
                . 'a "draft" quote at all.',
            );
        }

        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($registry, $quoteId): void {
            $registry->transition(
                new Transition('quote', $quoteId, 'customer_send', 'stateId'),
                Context::createDefaultContext(),
            );
        });

        self::assertCount(
            1,
            $bus->messages,
            'A real buyer transition into open queued nothing: '
            . 'the core event name, the live-version filter and the state whitelist are unproven end to end.',
        );
        self::assertSame('state_entered', $bus->messages[0]->reason ?? null);
    }

    /**
     * The comment counterpart of the transition test above: a real buyer
     * comment, written through the repository under a plain default context
     * (never the gateway's author-less addComment()), queues exactly one
     * message.
     */
    public function testABuyerCommentQueuesTheQuoteOnce(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $comments = static::getContainer()->get('quote_comment.repository');
        self::assertInstanceOf(EntityRepository::class, $comments);
        $customerId = $this->anyCustomerId();
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($comments, $quoteId, $customerId): void {
            $comments->create([[
                'quoteId' => $quoteId,
                'comment' => 'ServicingTriggerTest buyer follow-up',
                'customerId' => $customerId,
            ]], Context::createDefaultContext());
        });

        self::assertCount(
            1,
            $bus->messages,
            'A real buyer comment queued nothing: the core event name and '
            . 'the version/state-stamp filters are unproven end to end.',
        );
        self::assertSame('comment_written', $bus->messages[0]->reason ?? null);
    }

    /**
     * #55: a merchant typing an internal note in the administration is not a
     * buyer ask. SwagCommercial's QuoteActionController writes exactly this
     * row — createdById from the AdminApiSource, customerId and employeeId
     * hard-coded null — so this reproduces the persisted shape rather than
     * the transport.
     *
     * Three things this proves, and none of them is redundant. The empty bus
     * is the pass that never gets queued. The unchanged fingerprint is the
     * backstop that would still stop the pass even if the trigger could not
     * tell who wrote the comment. And the three authorship assertions below
     * are what measure SwagCommercial's actual columns on a real row — a
     * merchant's comment carries `createdById` and neither buyer column —
     * which is the premise the whole three-way split (isAuthored(),
     * isBuyerAuthored(), this trigger's own payload check) rests on; nothing
     * else in the suite pins that the mapper carries `created_by_id` through
     * to `QuoteComment::createdById` on a live-shop row.
     */
    public function testAMerchantAdminCommentQueuesNothingAndChangesNoFingerprint(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $comments = static::getContainer()->get('quote_comment.repository');
        self::assertInstanceOf(EntityRepository::class, $comments);
        $userId = $this->anyAdminUserId();
        $bus = self::collectingBus();
        $text = 'ServicingTriggerTest merchant note ' . Uuid::randomHex();

        $before = ServicingFingerprint::of(static::gateway()->fetchSnapshot($quoteId));

        $this->withTrigger($bus, static function () use ($comments, $quoteId, $userId, $text): void {
            $comments->create([[
                'quoteId' => $quoteId,
                'comment' => $text,
                'createdById' => $userId,
            ]], Context::createDefaultContext());
        });

        self::assertSame([], $bus->messages, 'A merchant admin comment queued a servicing pass.');
        self::assertSame(
            $before,
            ServicingFingerprint::of(static::gateway()->fetchSnapshot($quoteId)),
            'A merchant admin comment moved the servicing fingerprint, which buys a pass on the next trigger.',
        );

        $ours = $this->commentWithText(static::gateway()->fetchSnapshot($quoteId)->content->comments, $text);

        self::assertSame($userId, $ours->createdById, 'createdById on the row we just wrote is not the admin user.');
        self::assertTrue($ours->isAuthored(), 'A comment with createdById set is not read as authored.');
        self::assertFalse($ours->isBuyerAuthored(), 'A merchant comment is read as the buyer\'s.');
    }

    /** @param list<QuoteComment> $comments */
    private function commentWithText(array $comments, string $text): QuoteComment
    {
        foreach ($comments as $comment) {
            if ($comment->comment === $text) {
                return $comment;
            }
        }

        self::fail('The comment just written is not in the quote read back.');
    }

    private function anyAdminUserId(): string
    {
        $repository = static::getContainer()->get('user.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = $repository->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($id, 'The shop has no admin user to attribute a merchant comment to.');

        return $id;
    }

    private function anyCustomerId(): string
    {
        $repository = static::getContainer()->get('customer.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = $repository->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($id, 'The shop has no customer to attribute a buyer comment to.');

        return $id;
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
