<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * System scope throughout, because every field carries
 * Protection(write: [Protection::SYSTEM_SCOPE]) — the same reason
 * TerminalOutcomeWriter uses one. The admin user's own permission is checked
 * by the route's `_acl`, before any of this runs.
 */
final readonly class DecisionReviewStore implements DecisionReviewStoreInterface
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $records */
    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function find(string $decisionId): ?QuoteDecisionRecord
    {
        if (!Uuid::isValid($decisionId)) {
            return null;
        }

        $record = $this->records
            ->search(new Criteria([$decisionId]), Context::createDefaultContext())
            ->getEntities()
            ->first();

        return $record instanceof QuoteDecisionRecord ? $record : null;
    }

    #[\Override]
    public function pendingOf(string $quoteId): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('quoteId', $quoteId),
            new EqualsFilter('reviewStatus', ReviewStatus::Pending->value),
        );

        $pending = [];

        foreach ($this->records->search($criteria, Context::createDefaultContext())->getEntities() as $record) {
            if (!$record instanceof QuoteDecisionRecord) {
                continue;
            }

            $pending[] = [
                'id' => $record->id,
                'versionId' => $record->draftVersionId,
                'clarified' => $record->outcome === NegotiationOutcome::Clarified->value,
            ];
        }

        return $pending;
    }

    #[\Override]
    public function supersede(array $decisionIds): void
    {
        if ($decisionIds === []) {
            return;
        }

        $this->records->update(array_map(static fn(string $id): array => [
            'id' => $id,
            'reviewStatus' => ReviewStatus::Superseded->value,
            'draftVersionId' => null,
            'sentChanges' => null,
        ], $decisionIds), Context::createDefaultContext());
    }

    #[\Override]
    public function markPreviewEdited(string $decisionId): void
    {
        // A pending marker uses this otherwise-empty review column without
        // claiming anything was sent. The export suppresses it until Send;
        // markSent replaces it with the actual prices and this flag.
        $this->write($decisionId, ['sentChanges' => ['editedByMerchant' => true]]);
    }

    #[\Override]
    public function markPublishing(string $decisionId, int $merchantCommentCount, bool $editedByMerchant): void
    {
        $this->write($decisionId, ['sentChanges' => [
            'editedByMerchant' => $editedByMerchant,
            'publishingMerchantCommentCount' => $merchantCommentCount,
        ]]);
    }

    #[\Override]
    public function markSent(string $decisionId, string $sentReply, ?array $sentChanges): void
    {
        $this->write($decisionId, [
            'reviewStatus' => ReviewStatus::Sent->value,
            'reviewedAt' => new \DateTimeImmutable(),
            'sentReply' => $sentReply,
            'sentChanges' => $sentChanges,
            'draftVersionId' => null,
        ]);
    }

    #[\Override]
    public function markRejected(string $decisionId): void
    {
        $this->write($decisionId, [
            'reviewStatus' => ReviewStatus::Rejected->value,
            'reviewedAt' => new \DateTimeImmutable(),
            'draftVersionId' => null,
            'sentChanges' => null,
        ]);
    }

    #[\Override]
    public function saveFeedback(string $decisionId, array $reasons, string $comment): void
    {
        $this->write($decisionId, [
            'feedbackReasons' => $reasons === [] ? null : $reasons,
            'feedbackComment' => $comment === '' ? null : $comment,
            'feedbackAt' => new \DateTimeImmutable(),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function write(string $decisionId, array $fields): void
    {
        $this->records->update([['id' => $decisionId, ...$fields]], Context::createDefaultContext());
    }
}
