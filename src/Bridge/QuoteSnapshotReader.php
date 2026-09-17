<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
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
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10); mapping the DAL entity's
 * associations and nullable fields (currency, customer, state, customFields)
 * into typed DTOs takes one null/type check per mapped field.
 */
final readonly class QuoteSnapshotReader
{
    private QuoteLineMapper $lineMapper;
    private QuoteCommentMapper $commentMapper;
    private QuoteDiscountMapper $discountMapper;

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
    public function __construct(
        private EntityRepository $quoteRepository,
        private QuoteVersionResolver $versionResolver,
        CommercialCapabilities $capabilities,
        private MerchantActionReader $merchantActions,
    ) {
        $this->lineMapper = new QuoteLineMapper($capabilities);
        $this->commentMapper = new QuoteCommentMapper($capabilities);
        $this->discountMapper = new QuoteDiscountMapper();
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
        $criteria->addAssociation('customer');

        $quote = $this->quoteRepository->search($criteria, $versionedContext)->getEntities()->first();

        if (!$quote instanceof Entity) {
            throw QuoteNotFoundException::forId($quoteId);
        }

        return new QuoteSnapshot(
            identity: $this->readIdentity($quote, $quoteId),
            revision: $this->readRevision($quote, $versionedContext),
            totals: new QuoteTotals(
                totalNet: (float) $quote->get('amountNet'),
                discount: $this->discountMapper->map($quote->get('discount')),
                totalGross: (float) $quote->get('amountTotal'),
            ),
            lifecycle: $this->readLifecycle($quote, $this->merchantActions->lastTransitionAt(
                $quoteId,
                $versionedContext,
            )),
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
        $salesChannelId = $quote->get('salesChannelId');
        $customerId = $quote->get('customerId');
        $customer = $quote->get('customer');
        $company = $customer instanceof Entity ? $customer->get('company') : null;
        $orderId = $quote->get('orderId');

        return new QuoteIdentity(
            quoteId: $quoteId,
            quoteNumber: (string) $quote->get('quoteNumber'),
            currencyIso: $iso,
            salesChannelId: \is_string($salesChannelId) ? $salesChannelId : '',
            customerId: \is_string($customerId) ? $customerId : '',
            companyName: \is_string($company) ? $company : '',
            orderId: \is_string($orderId) ? $orderId : null,
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

    private function readLifecycle(Entity $quote, ?\DateTimeImmutable $lastAdminTransitionAt): QuoteLifecycle
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
            lastAdminTransitionAt: $lastAdminTransitionAt,
        );
    }
}
