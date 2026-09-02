<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * The agent registering an authorization request was not authenticated.
 *
 * A 401 rather than the SDK's OAuthException, which its ExceptionListener maps
 * to 400: the caller has not proven who it is. The listener honours any
 * HttpExceptionInterface's own status, so this surfaces as a real 401 inside a
 * UCP error envelope — the same reasoning as AgentCustomerAuthenticator.
 */
final class UnverifiedAgentException extends UnauthorizedHttpException
{
    public static function signatureNotVerified(): self
    {
        return new self('UCP-Agent', 'The request signature did not verify, so no authorization request was recorded.');
    }

    public static function clientIdMismatch(): self
    {
        return new self('UCP-Agent', 'client_id must be the signed platform profile URI of the calling agent.');
    }
}
