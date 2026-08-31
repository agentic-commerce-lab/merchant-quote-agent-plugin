<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

/**
 * Read side of an OAuth access-token store.
 *
 * A resource server only ever resolves a presented token; it never issues or
 * revokes one. Everything above this port is free of any knowledge about where
 * tokens live, which is what makes {@see AcOAuthAccessTokenReader} the single
 * place that couples this plugin to Agentic Commerce's schema.
 */
interface AccessTokenSubjectReaderInterface
{
    /**
     * Null when the token is unknown, expired, revoked, or belongs to a
     * different sales channel.
     */
    public function find(#[\SensitiveParameter] string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo;
}
