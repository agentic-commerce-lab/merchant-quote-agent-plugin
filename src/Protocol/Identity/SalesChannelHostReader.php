<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The host (and, if non-standard, port) of a sales channel's primary domain —
 * the did:web authority for that channel.
 *
 * Split out of A2cnIdentityResolver, which would otherwise carry both the host
 * lookup and the organization-name lookup in one class and trip this repo's
 * per-class cyclomatic-complexity gate — the seam is which question is being
 * answered, exactly like ActTableStore/ViolationTableStore/ReceiptTableStore
 * split on which table.
 */
final readonly class SalesChannelHostReader
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * Null when the channel has no domain to be a did:web authority — a shop
     * reachable at no URL cannot publish a DID document either.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function hostFor(string $salesChannelId): ?string
    {
        if (!Uuid::isValid($salesChannelId)) {
            return null;
        }

        $url = $this->connection->fetchOne('SELECT `url` FROM `sales_channel_domain` WHERE `sales_channel_id` = :id ORDER BY `id` ASC LIMIT 1', [
            'id' => Uuid::fromHexToBytes($salesChannelId),
        ]);

        if (!\is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, \PHP_URL_PORT);

        return \is_int($port) ? $host . ':' . $port : $host;
    }
}
