<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;

/**
 * The last step of a granted consent: hand the claimed record to Agentic
 * Commerce's identity-linking capability and read back where the agent's
 * redirect_uri should be sent.
 *
 * Split out of AgentConsentController to keep the controller under the
 * class-level cyclomatic-complexity gate.
 */
final readonly class ConsentGrantCompleter
{
    public function __construct(
        private AgentAuthorizationContextFactory $contextFactory,
        private IdentityLinkingCapabilityInterface $identityLinking,
    ) {}

    public function redirectTarget(
        PendingAuthorization $claimed,
        string $host,
        #[\SensitiveParameter]
        string $customerContextToken,
    ): string {
        $result = $this->identityLinking->authorize(
            new OAuthAuthorizationRequest(
                $claimed->clientId,
                $claimed->redirectUri,
                $claimed->scope,
                $claimed->state,
                $claimed->codeChallenge,
                $claimed->codeChallengeMethod,
            ),
            $this->contextFactory->forConsent($claimed, $host, $customerContextToken),
        );

        $redirectTo = $result['redirect_to'] ?? null;

        if (!\is_string($redirectTo) || $redirectTo === '') {
            throw new \RuntimeException('The identity-linking capability returned no redirect target.');
        }

        return $redirectTo;
    }
}
