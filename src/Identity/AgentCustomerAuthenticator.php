<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
 * `$requiredScope` is wired but every caller passes null: Agentic Commerce
 * intersects requested scopes against a private constant that does not include
 * `com.shopware.quote:manage`, so no token can carry it yet. Authorization for
 * now is ownership — the token's subject names the customer, and the commercial
 * Store API routes filter by customer and sales channel themselves.
 */
final readonly class AgentCustomerAuthenticator
{
    public function __construct(
        private CustomerContextResolverInterface $contextResolver,
        private AccessTokenSubjectReaderInterface $accessTokenReader,
    ) {}

    public function authenticate(
        AgentCustomerCredential $credential,
        RequestContext $requestContext,
        ?string $requiredScope = null,
    ): SalesChannelContext {
        $resolution = $this->contextResolver->resolveSalesChannel($requestContext);
        $token = $this->accessTokenReader->find($credential->accessToken, $resolution->salesChannelId);

        if ($token === null || $token->salesChannelId !== $resolution->salesChannelId) {
            throw new UnauthorizedHttpException('Bearer', 'Access token is invalid, expired, or revoked.');
        }

        if ($requiredScope !== null && !$token->hasScope($requiredScope)) {
            throw new UnauthorizedHttpException('Bearer', \sprintf(
                'Access token is missing the required scope "%s".',
                $requiredScope,
            ));
        }

        $context = $this->contextResolver->resolveForCustomer($token->subject, $requestContext);

        if ($context->getCustomer() === null) {
            throw new ValidationException('The access token does not identify a customer.', [
                '$.headers.authorization must carry an identity-linking access token for an existing customer',
            ]);
        }

        return $context;
    }
}
