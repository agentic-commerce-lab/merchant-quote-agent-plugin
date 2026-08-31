<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use Ucp\Sdk\Model\RequestContext;

/**
 * The resolver is the seam between "a UCP request arrived" and "a Shopware
 * customer context exists", so it is only meaningful against a real shop: the
 * sales-channel domain table, the context service and the customer all have to
 * agree.
 */
final class SalesChannelContextResolverTest extends IntegrationTestCase
{
    public function testItResolvesTheSalesChannelFromTheRequestHost(): void
    {
        $domain = $this->anyStorefrontDomain();

        $resolution = $this->resolver()->resolveSalesChannel($this->requestContext($domain['url']));

        self::assertSame($domain['sales_channel_id'], $resolution->salesChannelId);
        self::assertSame($domain['language_id'], $resolution->languageId);
        self::assertSame($domain['currency_id'], $resolution->currencyId);
        self::assertSame($domain['id'], $resolution->domainId);
    }

    public function testItBuildsAContextForACustomerWithoutACustomerSession(): void
    {
        $domain = $this->anyStorefrontDomain();
        $customerId = $this->anyCustomerId($domain['sales_channel_id']);

        $context = $this->resolver()->resolveForCustomer($customerId, $this->requestContext($domain['url']));

        self::assertNotNull($context->getCustomer());
        self::assertSame($customerId, $context->getCustomer()?->getId());
        self::assertSame($domain['sales_channel_id'], $context->getSalesChannelId());
    }

    public function testItRejectsAHostNoSalesChannelServes(): void
    {
        $this->expectException(\Ucp\Sdk\Exception\ConfigurationException::class);

        $this->resolver()->resolveSalesChannel(new RequestContext('not-a-shop.invalid'));
    }

    private function resolver(): SalesChannelContextResolver
    {
        $resolver = static::getContainer()->get(SalesChannelContextResolver::class);
        self::assertInstanceOf(SalesChannelContextResolver::class, $resolver);

        return $resolver;
    }

    /** The SDK's RequestContext carries the request host, not a base URI. */
    private function requestContext(string $domainUrl): RequestContext
    {
        return new RequestContext((string) parse_url($domainUrl, \PHP_URL_HOST));
    }

    /**
     * @return array{id: string, url: string, sales_channel_id: string, language_id: string, currency_id: string}
     */
    private function anyStorefrontDomain(): array
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        $row = $connection->fetchAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, d.url, LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,'
            . ' LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(d.currency_id)) AS currency_id'
            . ' FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE d.url LIKE "http%"'
            . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
            . ' ORDER BY d.url LIMIT 1',
        );

        self::assertIsArray(
            $row,
            'the shop has no active sales-channel domain with an absolute URL whose channel has an active customer',
        );

        /** @var array{id: string, url: string, sales_channel_id: string, language_id: string, currency_id: string} $row */
        return $row;
    }

    private function anyCustomerId(string $salesChannelId): string
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        $id = $connection->fetchOne('SELECT LOWER(HEX(id)) FROM customer WHERE sales_channel_id = :scid AND active = 1 LIMIT 1', [
            'scid' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($salesChannelId),
        ]);

        self::assertIsString($id, 'the shop has no active customer in this sales channel');

        return $id;
    }
}
