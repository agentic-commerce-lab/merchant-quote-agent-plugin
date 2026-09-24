<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStore;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\DraftingQuoteGateway;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\DraftRejecter;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Real DAL draft, review and send; each test is rolled back by IntegrationTestCase. */
final class DraftModeFlowTest extends IntegrationTestCase
{
    public function testDraftIsInvisibleUntilAdminSendsIt(): void
    {
        [$quoteId, $decisionId] = $this->draftAQuote();
        $before = static::gateway()->fetchSnapshot($quoteId);
        self::assertNotContains('We can offer 10%.', array_map(
            static fn($comment): string => $comment->comment,
            $before->content->comments,
        ));

        $userId = $this->anyAdminUserId();
        $merchant = new Context(new AdminApiSource($userId));
        $this->pendingDrafts()->with($decisionId, fn(PendingDraft $pending) => $this->sender()->send(
            $pending,
            'We can offer 10%.',
            new DraftEdits(),
            $merchant,
        ));

        $after = static::gateway()->fetchSnapshot($quoteId);
        self::assertSame('replied', $after->lifecycle->stateTechnicalName);
        self::assertLessThan($before->totals->totalNet, $after->totals->totalNet);
        $posted = array_values(array_filter(
            $after->content->comments,
            static fn($comment): bool => $comment->comment === 'We can offer 10%.',
        ));
        self::assertCount(1, $posted);
        self::assertSame($userId, $posted[0]->createdById);
        self::assertSame('sent', $this->store()->find($decisionId)?->reviewStatus);
        self::assertNull($this->store()->find($decisionId)?->draftVersionId);
    }

    public function testRejectDiscardsTheVersionAndPreservesLivePrices(): void
    {
        [$quoteId, $decisionId] = $this->draftAQuote();
        $before = static::gateway()->fetchSnapshot($quoteId);
        $versionId = $this->store()->find($decisionId)?->draftVersionId;
        self::assertNotNull($versionId);

        $this->pendingDrafts()->withAnyDraft($decisionId, fn(PendingDraft $pending) => (new DraftRejecter(
            $this->versions(),
            static::gateway(),
            $this->store(),
        ))->reject($pending));

        self::assertFalse($this->versions()->exists($versionId));
        self::assertSame($before->totals->totalNet, static::gateway()->fetchSnapshot($quoteId)->totals->totalNet);
        self::assertSame('rejected', $this->store()->find($decisionId)?->reviewStatus);
    }

    public function testANewBuyerCommentMakesTheDraftStale(): void
    {
        [$quoteId, $decisionId] = $this->draftAQuote();
        $this->addBuyerComment($quoteId);

        $this->expectException(DraftNotReviewable::class);

        $this->pendingDrafts()->with($decisionId, fn(PendingDraft $pending) => $this->sender()->send(
            $pending,
            'We can offer 10%.',
            new DraftEdits(),
            new Context(new AdminApiSource($this->anyAdminUserId())),
        ));
    }

    private function addBuyerComment(string $quoteId): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $customerId = $connection->fetchOne('SELECT customer_id FROM quote WHERE id = UNHEX(:quoteId) AND version_id = UNHEX(:versionId)', [
            'quoteId' => $quoteId,
            'versionId' => \Shopware\Core\Defaults::LIVE_VERSION,
        ]);
        self::assertIsString($customerId);

        $connection->insert('quote_comment', [
            'id' => Uuid::randomBytes(),
            'version_id' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION),
            'quote_id' => Uuid::fromHexToBytes($quoteId),
            'quote_version_id' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION),
            'comment' => 'Can you offer more?',
            'customer_id' => $customerId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** @return array{string, string} */
    private function draftAQuote(): array
    {
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $snapshot = static::gateway()->fetchSnapshot($quoteId);
        $recorder = new DecisionRecorder(new DecisionRecordWriter(static::getContainer()->get(
            'merchant_quote_agent_decision.repository',
        )));
        $recorder->begin($snapshot, new PassContext(ServicingTriggerReason::CommentWritten, 0));

        $drafting = new DraftingQuoteGateway(static::gateway(), $this->versions(), $recorder, $snapshot);
        $drafting->transition($quoteId, QuoteTransition::Process);
        $drafting->updateQuote(
            $quoteId,
            new QuoteUpdate(
                discount: new Discount(DiscountType::Percentage, 10.0),
                expiresAt: new \DateTimeImmutable('+14 days'),
            ),
        );
        $drafting->recalculate($quoteId);
        $drafting->addComment($quoteId, 'We can offer 10%.');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $decisionId = static::getContainer()
            ->get(Connection::class)
            ->fetchOne(
                'SELECT LOWER(HEX(id)) FROM merchant_quote_agent_decision WHERE quote_id = UNHEX(:quoteId) ORDER BY created_at DESC LIMIT 1',
                ['quoteId' => $quoteId],
            );
        self::assertIsString($decisionId);

        return [$quoteId, $decisionId];
    }

    private function anyAdminUserId(): string
    {
        $id = static::getContainer()->get(Connection::class)->fetchOne('SELECT LOWER(HEX(id)) FROM `user` LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function versions(): QuoteDraftVersions
    {
        return new QuoteDraftVersions(
            static::getContainer()->get('quote.repository'),
            static::getContainer()->get('version.repository'),
            static::gatewayFactory(),
        );
    }

    private function store(): DecisionReviewStore
    {
        return new DecisionReviewStore(static::getContainer()->get('merchant_quote_agent_decision.repository'));
    }

    private function pendingDrafts(): PendingDrafts
    {
        return new PendingDrafts(
            $this->store(),
            $this->versions(),
            static::gateway(),
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock'),
        );
    }

    private function sender(): DraftSender
    {
        return new DraftSender($this->versions(), static::gatewayFactory(), $this->store());
    }
}
