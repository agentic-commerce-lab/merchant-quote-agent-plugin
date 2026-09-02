<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;

/**
 * Read-only projections of a PendingAuthorization for the consent page: the
 * agent's host, its requested scopes, and the OAuth denial redirect.
 *
 * Split out of AgentConsentController to keep the controller under the
 * class-level cyclomatic-complexity gate — none of this branches on request
 * state, so none of it needs to live in the controller.
 */
final class PendingAuthorizationPresenter
{
    public static function agentHost(PendingAuthorization $pending): string
    {
        $host = parse_url($pending->clientId, \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? $host : $pending->clientId;
    }

    /** @return list<string> */
    public static function scopeList(PendingAuthorization $pending): array
    {
        return array_values(array_filter(
            explode(' ', trim($pending->scope)),
            static fn(string $scope): bool => $scope !== '',
        ));
    }

    public static function denialUrl(PendingAuthorization $pending): string
    {
        $query = http_build_query(['error' => 'access_denied', 'state' => $pending->state]);
        $separator = str_contains($pending->redirectUri, '?') ? '&' : '?';

        return $pending->redirectUri . $separator . $query;
    }
}
