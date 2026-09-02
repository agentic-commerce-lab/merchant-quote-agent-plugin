<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The absolute URL of one sales-channel domain.
 *
 * The consent URL must live on the domain the agent registered against, not on
 * whatever host the storefront happens to answer on: the sales channel is bound
 * across both hops, and a customer signed in on another channel must not be
 * able to answer this record. A single-column lookup, so a direct query rather
 * than the DAL — the same reasoning as SalesChannelContextResolver.
 *
 * Not `final`: the controller test substitutes it.
 */
class SalesChannelDomainUrlReader
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function urlFor(?string $domainId): ?string
    {
        if ($domainId === null || $domainId === '') {
            return null;
        }

        $url = $this->connection->fetchOne('SELECT `url` FROM `sales_channel_domain` WHERE `id` = :id', [
            'id' => Uuid::fromHexToBytes($domainId),
        ]);

        return \is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }
}
