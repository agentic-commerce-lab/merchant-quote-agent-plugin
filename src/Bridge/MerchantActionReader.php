<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * When a human merchant last moved this quote through its state machine.
 *
 * Core writes one `state_machine_history` row per transition and fills
 * `user_id` from the context source:
 *
 *     'userId' => $context->getSource() instanceof AdminApiSource
 *         ? $context->getSource()->getUserId() : null,
 *     -- StateMachineRegistry.php:154, v6.7.1.0
 *
 * So `user_id IS NOT NULL` is exactly "an administration user did this". The
 * buyer's storefront transitions carry a SalesChannelApiSource and the agent's
 * own carry a SystemSource; both write null by construction. This is the same
 * three-way authorship split Bridge\Data\QuoteComment documents for comments,
 * and it is the author EscalationResolutionSubscriber correctly says the core
 * state-change EVENT does not carry — true of the event, not of the row core
 * writes beside it.
 *
 * An integration acting through the admin API sets `integration_id` and leaves
 * `user_id` null, so an ERP sync is not read as a person. That is intended: the
 * rule this feeds is about a human having looked.
 *
 * A core entity rather than a SwagCommercial one, but it lives here anyway:
 * ADR 0001 puts every DAL read in the bridge, and src/Negotiation — which owns
 * the decision this feeds — may not import Shopware at all.
 */
final readonly class MerchantActionReader
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $historyRepository */
    public function __construct(
        private EntityRepository $historyRepository,
    ) {}

    public function lastTransitionAt(string $quoteId, Context $context): ?\DateTimeImmutable
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entityName', 'quote'));
        $criteria->addFilter(new EqualsFilter('referencedId', $quoteId));
        // Deliberately NOT filtered on referencedVersionId: SwagCommercial
        // edits quotes in a version lane, and a human acting there is still a
        // human acting. The floor's index is (referenced_id,
        // referenced_version_id), whose leading column still serves this.
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('userId', null)]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $row = $this->historyRepository->search($criteria, $context)->getEntities()->first();
        $createdAt = $row instanceof Entity ? $row->get('createdAt') : null;

        return $createdAt instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($createdAt) : null;
    }
}
