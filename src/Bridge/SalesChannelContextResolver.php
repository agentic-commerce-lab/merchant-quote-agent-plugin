<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Turns "a UCP request arrived on this base URI, for this customer" into a
 * Shopware sales-channel context.
 *
 * The domain lookup is a direct query rather than the DAL: this runs on every
 * buyer request, the answer is one row, and the criteria/repository route costs
 * more than it explains. Matching is on host with any port stripped from both
 * sides, and the shortest URL wins, which picks the bare domain over a
 * path-prefixed one. Two ceilings follow: a shop serving different sales
 * channels on the same host under different paths needs the path compared too,
 * and one serving them on the same host under different ports needs the port
 * kept. Agentic Commerce's own resolver does the path comparison; this is
 * deliberately the simpler thing until a shop needs it.
 *
 * `resolveForCustomer()` mints a fresh context token instead of accepting one:
 * an agent's authority comes from its access token, so it must never need to
 * hold — or be able to reuse — a customer session.
 */
final readonly class SalesChannelContextResolver implements CustomerContextResolverInterface
{
    public function __construct(
        private Connection $connection,
        private SalesChannelContextServiceInterface $contextService,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
    {
        $host = strtolower(trim($context->host));

        if ($host === '') {
            throw new ConfigurationException('The UCP request carries no host, so no sales channel can be resolved.');
        }

        $row = $this->connection->fetchAssociative('SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,'
        . ' LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(d.currency_id)) AS currency_id'
        . ' FROM sales_channel_domain d'
        . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
        . ' WHERE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(d.url, "://", -1), "/", 1), ":", 1)) = :host'
        . ' ORDER BY CHAR_LENGTH(d.url) ASC LIMIT 1', ['host' => strtolower(explode(':', $host)[0])]);

        if ($row === false) {
            throw new ConfigurationException(\sprintf('No active sales channel serves the host "%s".', $host));
        }

        return new SalesChannelResolution(
            (string) $row['sales_channel_id'],
            (string) $row['language_id'],
            (string) $row['currency_id'],
            (string) $row['id'],
        );
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function resolveForCustomer(string $customerId, RequestContext $context): SalesChannelContext
    {
        $resolution = $this->resolveSalesChannel($context);

        return $this->contextService->get(
            new SalesChannelContextServiceParameters(
                $resolution->salesChannelId,
                Uuid::randomHex(),
                $resolution->languageId,
                $resolution->currencyId,
                $resolution->domainId,
                null,
                $customerId,
            ),
        );
    }
}
