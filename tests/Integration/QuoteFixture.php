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
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 * A fixture is a bag of independent, single-purpose shop lookups, one per
 * integration test's need — not a class with behaviour to design down.
 * Splitting it by table would just relocate the same count of one-liners.
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

    /**
     * The base URI of an active storefront domain — what a UCP request to this
     * shop would carry, and what SalesChannelContextResolver matches against.
     */
    public static function storefrontBaseUri(ContainerInterface $container): string
    {
        $url = self::connection($container)
            ->fetchOne(
                'SELECT d.url FROM sales_channel_domain d'
                . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
                . ' WHERE d.url LIKE "http%"'
                . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
                . ' ORDER BY d.url LIMIT 1',
            );

        if (!\is_string($url)) {
            throw new \RuntimeException(
                'The shop has no active sales-channel domain with an absolute URL whose channel has an active customer.',
            );
        }

        return rtrim($url, '/');
    }

    /**
     * The sales channel behind that domain. Every customer, token and quote the
     * integration tests touch must belong to this one channel, or an
     * access token issued for one channel is invisible to a request resolved
     * onto another.
     */
    public static function storefrontSalesChannelId(ContainerInterface $container): string
    {
        $id = self::connection($container)
            ->fetchOne(
                'SELECT LOWER(HEX(d.sales_channel_id)) FROM sales_channel_domain d'
                . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
                . ' WHERE d.url LIKE "http%"'
                . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
                . ' ORDER BY d.url LIMIT 1',
            );

        if (!\is_string($id)) {
            throw new \RuntimeException(
                'The shop has no active sales-channel domain with an absolute URL whose channel has an active customer.',
            );
        }

        return $id;
    }

    /** Just the host part, which is what the SDK's RequestContext carries. */
    public static function storefrontHost(ContainerInterface $container): string
    {
        $host = parse_url(self::storefrontBaseUri($container), \PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            throw new \RuntimeException('The shop\'s sales-channel domain has no host.');
        }

        return $host;
    }

    /**
     * A quote some other customer owns, for the ownership boundary tests.
     */
    public static function anyQuoteIdNotOwnedBy(ContainerInterface $container, string $customerId): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM quote WHERE customer_id <> :customerId LIMIT 1', [
                'customerId' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($customerId),
            ]);

        if (!\is_string($id)) {
            throw new \RuntimeException('The shop has no quote owned by another customer.');
        }

        return $id;
    }

    /**
     * A customer whose `customer_specific_features` row enables
     * QUOTE_MANAGEMENT. That table, not a column on `customer`, is where
     * SwagCommercial's CustomerSpecificFeatureService actually reads the flag
     * from (`customer_id` -> `features`, a JSON map, not a list — an array
     * would be silently ignored).
     */
    public static function anyQuoteCapableCustomerId(ContainerInterface $container): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(c.id)) FROM customer c'
            . ' INNER JOIN customer_specific_features csf ON csf.customer_id = c.id'
            . ' WHERE c.active = 1'
            . ' AND c.sales_channel_id = UNHEX(:salesChannelId)'
            . ' AND JSON_EXTRACT(csf.features, "$.QUOTE_MANAGEMENT") = TRUE'
            . ' LIMIT 1', ['salesChannelId' => self::storefrontSalesChannelId($container)]);

        if (!\is_string($id)) {
            throw new \RuntimeException(
                'No customer in this shop has QUOTE_MANAGEMENT enabled. Set it with a PATCH against the Admin API:'
                . ' {"customerSpecificFeatures": {"QUOTE_MANAGEMENT": true}}.',
            );
        }

        return $id;
    }

    public static function anyCustomerWithoutQuoteFeature(ContainerInterface $container): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(c.id)) FROM customer c'
            . ' LEFT JOIN customer_specific_features csf ON csf.customer_id = c.id'
            . ' WHERE c.active = 1'
            . ' AND c.sales_channel_id = UNHEX(:salesChannelId)'
            . ' AND (csf.features IS NULL'
            . ' OR JSON_EXTRACT(csf.features, "$.QUOTE_MANAGEMENT") IS NULL)'
            . ' LIMIT 1', ['salesChannelId' => self::storefrontSalesChannelId($container)]);

        if (!\is_string($id)) {
            throw new \RuntimeException('Every customer in this shop has QUOTE_MANAGEMENT enabled.');
        }

        return $id;
    }

    /** A product an agent may put on a quote: active, and in a live version. */
    public static function anyPurchasableProductId(ContainerInterface $container, Context $context): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM product'
            . ' WHERE active = 1 AND version_id = :version AND parent_id IS NULL'
            . ' AND child_count = 0 LIMIT 1', [
                'version' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION),
            ]);

        if (!\is_string($id)) {
            throw new \RuntimeException('The shop has no simple active product to quote.');
        }

        return $id;
    }

    private static function connection(ContainerInterface $container): \Doctrine\DBAL\Connection
    {
        $connection = $container->get(\Doctrine\DBAL\Connection::class);

        if (!$connection instanceof \Doctrine\DBAL\Connection) {
            throw new \RuntimeException('The container has no DBAL connection.');
        }

        return $connection;
    }
}
