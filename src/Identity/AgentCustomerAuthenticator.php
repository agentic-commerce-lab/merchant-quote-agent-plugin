<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Resource-server side of identity linking: turns an agent's bearer token into
 * the customer's sales-channel context, or refuses.
 *
 * Token failures are `UnauthorizedHttpException` rather than the SDK's
 * OAuthException on purpose. The SDK's ExceptionListener maps OAuthException to
 * 400, but a rejected bearer token has to be 401 — and that listener honours
 * any HttpExceptionInterface with the exception's own status, so this way the
 * response is a real 401 inside a UCP error envelope. The status is all it
 * honours, though: the listener builds its JsonResponse from
 * `$throwable->getStatusCode()` alone and never reads `$throwable->getHeaders()`,
 * so the `WWW-Authenticate: Bearer` challenge passed as this exception's first
 * constructor argument is dropped and never reaches the client. Worth raising
 * upstream with the other SDK fixes in #14.
 *
 * Scopes are checked only when `$enforceScopes` is on, which services.php sets
 * from whether Agentic Commerce can grant this plugin's scope at all (1.4.0+,
 * where an extension can register one). Before that release no token can carry
 * `com.shopware.quote:manage`, so enforcing it would lock every agent out.
 * Ownership is checked either way: the token's subject names the customer, and
 * the commercial Store API routes filter by customer and sales channel
 * themselves.
 *
 * A missing scope is a 403, not a 401: the token is valid, and RFC 6750 §3.1
 * answers `insufficient_scope` with 403 so the agent knows to re-link with the
 * named scopes rather than to refresh.
 */
final readonly class AgentCustomerAuthenticator
{
    public function __construct(
        private CustomerContextResolverInterface $contextResolver,
        private AccessTokenSubjectReaderInterface $accessTokenReader,
        private bool $enforceScopes = false,
    ) {}

    /**
     * @param list<string> $requiredScopes
     */
    public function authenticate(
        AgentCustomerCredential $credential,
        RequestContext $requestContext,
        array $requiredScopes = [],
    ): SalesChannelContext {
        $resolution = $this->contextResolver->resolveSalesChannel($requestContext);
        $token = $this->accessTokenReader->find($credential->accessToken, $resolution->salesChannelId);

        if ($token === null || $token->salesChannelId !== $resolution->salesChannelId) {
            throw new UnauthorizedHttpException('Bearer', 'Access token is invalid, expired, or revoked.');
        }

        $missing = $this->enforceScopes ? array_diff($requiredScopes, $token->scopes) : [];
        if ($missing !== []) {
            throw new AccessDeniedHttpException(
                'insufficient_scope: the access token lacks '
                . implode(' ', $missing)
                . '. Link the account again and request these scopes.',
            );
        }

        $context = $this->contextResolver->resolveForCustomer($token->subject, $requestContext, $resolution);

        if ($context->getCustomer() === null) {
            throw new ValidationException('The access token does not identify a customer.', [
                '$.headers.authorization must carry an identity-linking access token for an existing customer',
            ]);
        }

        return $context;
    }
}
