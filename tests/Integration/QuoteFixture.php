<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
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

    /**
     * Mirrors QuoteVersionResolver::SNAPSHOT_VERSION_ID — SwagCommercial's
     * fixed "what the counterparty last saw" DAL version lane.
     */
    private const SNAPSHOT_VERSION_ID = '019cfaaf020219939ba2eea26ba651ae';

    /** @throws \RuntimeException when the shop has no quote to work with */
    public static function anyQuoteId(ContainerInterface $container, Context $context): string
    {
        $id = self::editableQuoteCriteria($container, $context)->firstId();

        if ($id === null) {
            throw new \RuntimeException(
                'No editable quote (open/in_review/replied/change_requested/reopen) with at least one '
                . 'line item exists in the shop. Create one through the storefront or admin first — '
                . 'see the plan Task 4 Step 1 for why this is not generated.',
            );
        }

        return $id;
    }

    /**
     * An editable quote that also has a row in the snapshot lane, so tests
     * can exercise that lane instead of hedging with markTestSkipped: 29
     * live quotes satisfy both at once, so this is not a rare case.
     *
     * @throws \RuntimeException when no such quote exists
     */
    public static function quoteIdWithSnapshotLane(ContainerInterface $container, Context $context): string
    {
        $result = self::editableQuoteCriteria($container, $context);

        foreach ($result->getIds() as $id) {
            /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
            $repository = $container->get('quote.repository');
            $snapshotContext = $context->createWithVersionId(self::SNAPSHOT_VERSION_ID);

            if ($repository->searchIds(new Criteria([$id]), $snapshotContext)->firstId() !== null) {
                return $id;
            }
        }

        throw new \RuntimeException(
            'No editable quote with a row in the snapshot lane exists in the shop. Create one by '
            . 'sending an offer to a customer through the storefront or admin first.',
        );
    }

    /**
     * An editable quote that is currently in one specific state, so a
     * state-machine test can pick a quote whose state actually offers the
     * action under test. The alternative — take any quote and skip the test
     * when the transition is rejected — is a test that silently disables
     * itself, which this suite does not do.
     *
     * @throws \RuntimeException when the shop has no such quote
     */
    public static function quoteIdInState(ContainerInterface $container, Context $context, string $state): string
    {
        $id = self::editableQuoteCriteria($container, $context, [$state])->firstId();

        if ($id === null) {
            throw new \RuntimeException(sprintf(
                'No quote in state "%s" with at least one line item exists in the shop. Move a quote '
                . 'into that state through the admin first — see the plan Task 4 Step 1 for why this '
                . 'is not generated.',
                $state,
            ));
        }

        return $id;
    }

    /** @param list<string> $states */
    private static function editableQuoteCriteria(
        ContainerInterface $container,
        Context $context,
        array $states = self::EDITABLE_STATES,
    ): IdSearchResult {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = $container->get('quote.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('stateMachineState.technicalName', $states));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('lineItems.id', null),
        ]));
        $criteria->addSorting(new FieldSorting('quoteNumber'));

        return $repository->searchIds($criteria, $context);
    }
}
