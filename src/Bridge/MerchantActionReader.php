<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * When a human merchant last moved this quote through its state machine,
 * and (stateAt()) which state the quote was in at a given moment.
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

    /**
     * @return array{0: \DateTimeImmutable, 1: ?string}|null when, and into which state
     */
    public function lastTransition(string $quoteId, Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('toStateMachineState');
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

        if (!$createdAt instanceof \DateTimeInterface) {
            return null;
        }

        $to = $row?->get('toStateMachineState');
        $technicalName = $to instanceof Entity ? $to->get('technicalName') : null;

        return [
            \DateTimeImmutable::createFromInterface($createdAt),
            \is_string($technicalName) ? $technicalName : null,
        ];
    }

    /**
     * The state the quote was in at `$at`: the target of the newest history
     * row at or before it, by ANY author — the agent's and the buyer's
     * transitions move the state as much as a merchant's. Null when no row is
     * that old. QA-05: QuoteSnapshotReader asks it for the moment of the
     * newest merchant comment, for PendingEscalation.
     *
     * Not filtered on the version, for lastTransition()'s reason. `created_at`
     * is stored to the millisecond, so a row in the same millisecond as `$at`
     * counts as before it.
     */
    public function stateAt(string $quoteId, \DateTimeImmutable $at, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addAssociation('toStateMachineState');
        $criteria->addFilter(new EqualsFilter('entityName', 'quote'));
        $criteria->addFilter(new EqualsFilter('referencedId', $quoteId));
        // Stored as UTC to the millisecond (Defaults::STORAGE_DATE_TIME_FORMAT).
        // gmdate() rather than setTimezone(new DateTimeZone('UTC')), for
        // Protocol\ProtocolTimestamp::of()'s reason: it cannot throw.
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::LTE => gmdate('Y-m-d H:i:s', $at->getTimestamp()) . $at->format('.v'),
        ]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $row = $this->historyRepository->search($criteria, $context)->getEntities()->first();
        $to = $row instanceof Entity ? $row->get('toStateMachineState') : null;
        $technicalName = $to instanceof Entity ? $to->get('technicalName') : null;

        return \is_string($technicalName) ? $technicalName : null;
    }
}
