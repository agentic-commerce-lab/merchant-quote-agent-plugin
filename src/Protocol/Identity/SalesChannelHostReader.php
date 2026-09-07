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
    /**
     * The port each scheme is reached on without naming it. A configured
     * domain that spells its scheme's default port out is the same authority
     * as one that does not, and the publishing side — Symfony's
     * `Request::getHttpHost()` — omits it, so this side must omit it too.
     */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

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

        // Normalised to exactly what A2cnDiscoveryController publishes under,
        // which is Symfony's Request::getHttpHost(): lower case (RFC 952/2181
        // hostnames are case-insensitive), no root dot, and no port when it is
        // the scheme's default. Without this, a channel configured as
        // `https://shop.example:443/` signs acts as `did:web:shop.example%3A443`
        // while its own /.well-known/did.json publishes `did:web:shop.example`,
        // and a counterparty resolving the act's sender_verification_method
        // rejects the document (spec acceptance criteria 1 and 5).
        $host = rtrim(strtolower($host), '.');

        $port = parse_url($url, \PHP_URL_PORT);
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $default = \is_string($scheme) ? self::DEFAULT_PORTS[strtolower($scheme)] ?? null : null;

        return \is_int($port) && $port !== $default ? $host . ':' . $port : $host;
    }
}
