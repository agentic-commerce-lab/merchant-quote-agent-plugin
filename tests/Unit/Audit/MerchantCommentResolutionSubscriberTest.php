<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\MerchantCommentResolutionSubscriber;
use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;

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

    public function testAMerchantCommentResolvesTheEscalation(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
                self::comment(self::MERCHANT),
            ]));

        self::assertCount(1, $writer->calls);
        self::assertSame('q1', $writer->calls[0]['quoteId']);
        self::assertSame('commented', $writer->calls[0]['state']);

        // Seconds, not milliseconds: this must not be flaky.
        $secondsAgo = time() - $writer->calls[0]['at']->getTimestamp();
        self::assertLessThan(5, abs($secondsAgo), 'The recorded timestamp is not close to now.');
    }

    /** The buyer asking again is not the deal desk answering. */
    public function testABuyerCommentResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
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

        self::subscriber($writer)->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([$agentComment]));
        self::subscriber($writer)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([$agentComment], AgentContext::create()));
        self::subscriber($writer)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent(
                [self::comment(self::MERCHANT)],
                AgentContext::create(),
            ));

        self::assertSame([], $writer->calls);
    }

    /** SwagCommercial mirrors every live comment into the snapshot lane; only the live write counts. */
    public function testACommentOnTheSnapshotVersionResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent(
                [self::comment(self::MERCHANT)],
                QuoteTriggerEventFixture::snapshotContext(),
            ));

        self::assertSame([], $writer->calls);
    }

    public function testAnEditedCommentResolvesNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
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

        self::subscriber($writer, $logger)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
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

        self::subscriber($writer, $logger)
            ->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([
                self::comment(self::MERCHANT),
            ]));

        $this->expectNotToPerformAssertions();
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

    private static function subscriber(
        FakeEscalationResolutionWriter $writer,
        ?LoggerInterface $logger = null,
    ): MerchantCommentResolutionSubscriber {
        return new MerchantCommentResolutionSubscriber($writer, $logger ?? new NullLogger());
    }
}
