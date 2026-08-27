<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds an existing quote in the shop to exercise, rather than constructing
 * one: SwagCommercial's creation path needs a customer, a sales channel
 * context and a cart, and reproducing that here would test the fixture.
 *
 * Restricted to states SwagCommercial treats as editable (its
 * QuoteSnapshotVersionResolver::NON_EDITABLE_STATES excludes accepted,
 * expired, cancelled) and to quotes with at least one live line item: later
 * plan tasks reprice lines, remove lines and transition state against
 * whatever this returns, and an accepted/cancelled quote would make those
 * fail unpredictably depending on row order. Ordered deterministically so
 * reruns exercise the same quote.
 */
final class QuoteFixture
{
    private const EDITABLE_STATES = ['open', 'in_review', 'replied', 'change_requested', 'reopen'];

    /** @throws \RuntimeException when the shop has no quote to work with */
    public static function anyQuoteId(ContainerInterface $container, Context $context): string
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = $container->get('quote.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('stateMachineState.technicalName', self::EDITABLE_STATES));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('lineItems.id', null),
        ]));
        $criteria->addSorting(new FieldSorting('quoteNumber'));

        $id = $repository->searchIds($criteria, $context)->firstId();

        if ($id === null) {
            throw new \RuntimeException(
                'No editable quote (open/in_review/replied/change_requested/reopen) with at least one '
                . 'line item exists in the shop. Create one through the storefront or admin first — '
                . 'see the plan Task 4 Step 1 for why this is not generated.',
            );
        }

        return $id;
    }
}
