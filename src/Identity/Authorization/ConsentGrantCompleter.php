<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Psr\Log\LoggerInterface;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;

/**
 * The last step of a granted consent: hand the claimed record to Agentic
 * Commerce's identity-linking capability and read back where the agent's
 * redirect_uri should be sent.
 *
 * Split out of AgentConsentController to keep the controller under the
 * class-level cyclomatic-complexity gate.
 *
 * AC's own checks are the authoritative ones — this plugin mirrors only two of
 * them at registration time, for a readable error — so a refusal here is
 * expected traffic, not a bug: the capability may be disabled for the sales
 * channel, or AC may tighten a rule the registrar does not mirror. Every such
 * refusal is a `UcpException` (its exception hierarchy has a single root:
 * OAuthException, UnsupportedCapabilityException and ValidationException all
 * extend it), so all of them are caught and reported as `null`. The caller
 * renders the terminal error page. Without this, an AC refusal reached an
 * unhandled storefront controller as a 500 — and the handle was already
 * consumed by then, so the customer saw a stack trace and could not retry.
 *
 * The refusal is logged rather than swallowed: the customer cannot act on the
 * reason, but a merchant chasing "consent always fails" needs to see
 * "Identity linking capability is disabled for this sales channel." A missing
 * `redirect_to` is NOT a refusal and still throws — that is AC breaking its
 * own contract, not declining a request.
 */
final readonly class ConsentGrantCompleter
{
    public function __construct(
        private AgentAuthorizationContextFactory $contextFactory,
        private IdentityLinkingCapabilityInterface $identityLinking,
        private LoggerInterface $logger,
    ) {}

    /** @return string|null the agent's redirect target, or null when Agentic Commerce refused the grant */
    public function redirectTarget(
        PendingAuthorization $claimed,
        string $host,
        #[\SensitiveParameter]
        string $customerContextToken,
        RuntimeConfiguration $runtimeConfiguration,
    ): ?string {
        try {
            $result = $this->identityLinking->authorize(
                new OAuthAuthorizationRequest(
                    $claimed->clientId,
                    $claimed->redirectUri,
                    $claimed->scope,
                    $claimed->state,
                    $claimed->codeChallenge,
                    $claimed->codeChallengeMethod,
                ),
                $this->contextFactory->forConsent($claimed, $host, $customerContextToken, $runtimeConfiguration),
            );
        } catch (UcpException $refused) {
            $this->logger->warning('Agentic Commerce refused an identity-linking grant: {reason}', [
                'reason' => $refused->getMessage(),
                'client_id' => $claimed->clientId,
                'sales_channel_id' => $claimed->salesChannelId,
            ]);

            return null;
        }

        $redirectTo = $result['redirect_to'] ?? null;

        if (!\is_string($redirectTo) || $redirectTo === '') {
            throw new \RuntimeException('The identity-linking capability returned no redirect target.');
        }

        return $redirectTo;
    }
}
