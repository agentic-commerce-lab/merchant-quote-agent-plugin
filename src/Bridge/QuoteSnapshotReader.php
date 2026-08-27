<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Reads a quote through the generic DAL and maps it to the bridge's own read
 * model. No SwagCommercial class is named: `quote.repository` is resolved by
 * string id, and fields are read via Entity::get().
 *
 * Field names verified against a live shop (36 quotes) during Task 4: all as
 * SwagCommercial's QuoteDefinition declares them, no corrections needed.
 */
final readonly class QuoteSnapshotReader
{
    private QuoteLineMapper $lineMapper;
    private QuoteCommentMapper $commentMapper;

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
    public function __construct(
        private EntityRepository $quoteRepository,
        private QuoteVersionResolver $versionResolver,
    ) {
        $this->lineMapper = new QuoteLineMapper();
        $this->commentMapper = new QuoteCommentMapper();
    }

    /** @throws QuoteNotFoundException */
    public function read(string $quoteId, QuoteVersion $version, Context $context): QuoteSnapshot
    {
        $versionedContext = $this->versionResolver->contextFor($context, $version);

        $criteria = new Criteria([$quoteId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('comments');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('currency');

        $quote = $this->quoteRepository->search($criteria, $versionedContext)->getEntities()->first();

        if (!$quote instanceof Entity) {
            throw QuoteNotFoundException::forId($quoteId);
        }

        return new QuoteSnapshot(
            identity: $this->readIdentity($quote, $quoteId),
            revision: $this->readRevision($quote, $versionedContext),
            totals: new QuoteTotals(totalNet: (float) $quote->get('amountNet')),
            lifecycle: $this->readLifecycle($quote),
            content: new QuoteContent(
                lines: $this->lineMapper->map($quote),
                comments: $this->commentMapper->map($quote),
            ),
        );
    }

    private function readIdentity(Entity $quote, string $quoteId): QuoteIdentity
    {
        $currency = $quote->get('currency');
        $iso = $currency instanceof Entity ? (string) $currency->get('isoCode') : '';

        return new QuoteIdentity(
            quoteId: $quoteId,
            quoteNumber: (string) $quote->get('quoteNumber'),
            currencyIso: $iso,
        );
    }

    private function readRevision(Entity $quote, Context $context): QuoteRevision
    {
        $updatedAt = $quote->get('updatedAt') ?? $quote->get('createdAt');

        return new QuoteRevision(
            versionId: $context->getVersionId(),
            updatedAt: $updatedAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($updatedAt)
                : new \DateTimeImmutable('@0'),
        );
    }

    private function readLifecycle(Entity $quote): QuoteLifecycle
    {
        $state = $quote->get('stateMachineState');
        $expiresAt = $quote->get('expirationDate');
        $customFields = $quote->get('customFields');

        /** @var array<string, mixed> $normalizedCustomFields */
        $normalizedCustomFields = \is_array($customFields) ? $customFields : [];

        return new QuoteLifecycle(
            stateTechnicalName: $state instanceof Entity ? (string) $state->get('technicalName') : '',
            expiresAt: $expiresAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($expiresAt)
                : null,
            customFields: $normalizedCustomFields,
        );
    }
}
