<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

/**
 * An authorization request an agent registered and a human has not yet answered.
 *
 * `agentProfile` is the profile the SDK fetched and verified at registration,
 * kept as its array form so consent can replay it into a RequestContext without
 * a second outbound fetch. Nothing here is quote-specific: this type moves to
 * Agentic Commerce unchanged when the flow goes upstream.
 *
 * @mago-expect lint:excessive-parameter-list
 * All eight fields are exactly what the OAuth request and the stored row
 * carry (see PendingAuthorizationStoreInterface); splitting them into a
 * sub-object would just move the same eight fields one level down.
 */
final readonly class PendingAuthorization
{
    /** @param array<string, mixed> $agentProfile */
    public function __construct(
        public string $salesChannelId,
        public string $clientId,
        public array $agentProfile,
        public string $redirectUri,
        public string $scope,
        public string $state,
        public string $codeChallenge,
        public string $codeChallengeMethod,
    ) {}
}
