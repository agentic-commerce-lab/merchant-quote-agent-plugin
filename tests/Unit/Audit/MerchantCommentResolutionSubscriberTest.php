<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\MerchantCommentResolutionSubscriber;
use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * QA-05: a merchant's answer in the quote's thread resolves the Needs-review
 * item. The payload shapes are the ones SwagCommercial writes: the admin's
 * QuoteActionController passes `createdById` with both buyer columns null,
 * the storefront's QuoteCommentRoute a `customerId` (plus `employeeId` for a
 * B2B employee), and the agent nothing at all (#3, pinned by AddCommentTest).
 */
final class MerchantCommentResolutionSubscriberTest extends TestCase
{
    private const MERCHANT = ['createdById' => 'user-1', 'customerId' => null, 'employeeId' => null];

    public function testItSubscribesToTheQuoteCommentWrittenEvent(): void
    {
        self::assertSame(
            ['quote_comment.written' => 'onQuoteCommentWritten'],
            MerchantCommentResolutionSubscriber::getSubscribedEvents(),
        );
    }

    public function testAMerchantCommentResolvesTheEscalationOnlyOnARepliedQuote(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
            self::comment(self::MERCHANT),
        ]));

        self::assertCount(1, $writer->calls);
        self::assertSame('q1', $writer->calls[0]['quoteId']);
        self::assertSame('commented', $writer->calls[0]['state']);

        // Seconds, not milliseconds: this must not be flaky.
        $secondsAgo = time() - $writer->calls[0]['at']->getTimestamp();
        self::assertLessThan(5, abs($secondsAgo), 'The recorded timestamp is not close to now.');

        // Only a comment on a `replied` quote answers (the condition
        // PendingEscalation releases on). In any other state the merchant may
        // be mid-edit and the agent stays stood down, so Needs review keeps
        // the quote until the send, which EscalationResolutionSubscriber
        // stamps.
        foreach (['in_review', 'change_requested', 'open', null] as $state) {
            $writer = new FakeEscalationResolutionWriter();

            $this->subscriber($writer, state: $state)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
                self::comment(self::MERCHANT),
            ]));

            self::assertSame(
                [],
                $writer->calls,
                \sprintf('A comment in "%s" resolved the escalation.', $state ?? 'no state'),
            );
        }

        // Nor one whose state cannot be read: the read sits inside the same
        // guard as the write, so the comment itself never fails.
        $writer = new FakeEscalationResolutionWriter();
        $history = $this->createMock(EntityRepository::class);
        $history->method('search')->willThrowException(new \RuntimeException('the DAL is unwell'));

        (new MerchantCommentResolutionSubscriber(
            $writer,
            new MerchantActionReader($history),
            new NullLogger(),
        ))->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([self::comment(self::MERCHANT)]));

        self::assertSame([], $writer->calls);
    }

    /** The buyer asking again is not the deal desk answering. */
    public function testABuyerCommentResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
            self::comment(['createdById' => null, 'customerId' => 'customer-1', 'employeeId' => null]),
            self::comment(['createdById' => null, 'customerId' => 'customer-1', 'employeeId' => 'employee-1']),
        ]));

        self::assertSame([], $writer->calls);
    }

    /**
     * The agent's own escalation notice is written BEFORE DecisionRecorder
     * inserts the escalated row, so the newest row is still the previous
     * pass's — exactly the row the writer would stamp if that pass escalated
     * too. Each guard is pinned on its own: an author-less comment never
     * counts whatever its Context, and a write under AgentContext::STATE never
     * counts whatever its payload says.
     */
    public function testAnAgentCommentResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();
        $agentComment = self::comment(['createdById' => null, 'customerId' => null, 'employeeId' => null]);

        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([$agentComment]));
        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent(
            [$agentComment],
            AgentContext::create(),
        ));
        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent(
            [self::comment(self::MERCHANT)],
            AgentContext::create(),
        ));

        self::assertSame([], $writer->calls);
    }

    /** SwagCommercial mirrors every live comment into the snapshot lane; only the live write counts. */
    public function testACommentOnTheSnapshotVersionResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent(
            [self::comment(self::MERCHANT)],
            QuoteTriggerEventFixture::snapshotContext(),
        ));

        self::assertSame([], $writer->calls);
    }

    public function testAnEditedCommentResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        $this->subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
            self::comment(self::MERCHANT, EntityWriteResult::OPERATION_UPDATE),
        ]));

        self::assertSame([], $writer->calls);
    }

    /** A merchant's comment must never fail because an audit write did. */
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

        $this->subscriber($writer, $logger)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
            self::comment(self::MERCHANT),
        ]));

        self::assertCount(1, $logger->messages, 'The failure was not logged.');
    }

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

        $this->subscriber($writer, $logger)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
            self::comment(self::MERCHANT),
        ]));

        self::assertCount(1, $writer->calls);
    }

    /** @param array<string, ?string> $authors */
    private static function comment(
        array $authors,
        string $operation = EntityWriteResult::OPERATION_INSERT,
    ): EntityWriteResult {
        return new EntityWriteResult(
            'c1',
            ['quoteId' => 'q1', 'comment' => 'We can do 5% on this one.', ...$authors],
            'quote_comment',
            $operation,
        );
    }

    private function subscriber(
        FakeEscalationResolutionWriter $writer,
        ?LoggerInterface $logger = null,
        ?string $state = 'replied',
    ): MerchantCommentResolutionSubscriber {
        $rows = $state === null
            ? []
            : [new ArrayEntity([
                'id' => 'h1',
                'toStateMachineState' => new ArrayEntity(['id' => 's1', 'technicalName' => $state]),
            ])];
        $history = $this->createMock(EntityRepository::class);
        $history
            ->method('search')
            ->willReturn(
                new EntitySearchResult(
                    'state_machine_history',
                    \count($rows),
                    new EntityCollection($rows),
                    null,
                    new Criteria(),
                    Context::createDefaultContext(),
                ),
            );

        return new MerchantCommentResolutionSubscriber(
            $writer,
            new MerchantActionReader($history),
            $logger ?? new NullLogger(),
        );
    }
}
