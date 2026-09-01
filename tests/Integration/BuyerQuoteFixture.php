<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shop lookups for the buyer-side gateway's integration tests: a storefront
 * domain to resolve a `SalesChannelContext` against, and customers/quotes/
 * products scoped to it.
 *
 * Separate from QuoteFixture (the merchant-side quote lookups) for the same
 * reason ServicingSettingsFixture is separate from ServicingHandlerFixture:
 * it keeps each class under mago's too-many-methods ceiling, and nothing in
 * the merchant-side suite needs a storefront domain or a customer.
 */
final class BuyerQuoteFixture
{
    private function __construct() {}

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
     * A quote some other customer owns, in the live version, on the same
     * storefront sales channel — otherwise a not-found could just as well be
     * proving a version or channel mismatch instead of the ownership
     * boundary the trust-boundary tests actually want.
     */
    public static function anyQuoteIdNotOwnedBy(ContainerInterface $container, string $customerId): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM quote'
            . ' WHERE customer_id <> :customerId'
            . ' AND version_id = :version'
            . ' AND sales_channel_id = UNHEX(:salesChannelId)'
            . ' LIMIT 1', [
                'customerId' => Uuid::fromHexToBytes($customerId),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'salesChannelId' => self::storefrontSalesChannelId($container),
            ]);

        if (!\is_string($id)) {
            throw new \RuntimeException(
                'The shop has no quote owned by another customer on this storefront sales channel.',
            );
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

    /**
     * A customer for whom the flag is either absent or explicitly `false` —
     * `JSON_EXTRACT` returns `FALSE`, not `NULL`, for `{"QUOTE_MANAGEMENT":
     * false}`, so both cases need their own comparison.
     */
    public static function anyCustomerWithoutQuoteFeature(ContainerInterface $container): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(c.id)) FROM customer c'
            . ' LEFT JOIN customer_specific_features csf ON csf.customer_id = c.id'
            . ' WHERE c.active = 1'
            . ' AND c.sales_channel_id = UNHEX(:salesChannelId)'
            . ' AND (csf.features IS NULL'
            . ' OR JSON_EXTRACT(csf.features, "$.QUOTE_MANAGEMENT") IS NULL'
            . ' OR JSON_EXTRACT(csf.features, "$.QUOTE_MANAGEMENT") = FALSE)'
            . ' LIMIT 1', ['salesChannelId' => self::storefrontSalesChannelId($container)]);

        if (!\is_string($id)) {
            throw new \RuntimeException('Every customer in this shop has QUOTE_MANAGEMENT enabled.');
        }

        return $id;
    }

    /** A product an agent may put on a quote: active, and in a live version. */
    public static function anyPurchasableProductId(ContainerInterface $container): string
    {
        $id = self::connection($container)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM product'
            . ' WHERE active = 1 AND version_id = :version AND parent_id IS NULL'
            . ' AND child_count = 0 LIMIT 1', [
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
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
