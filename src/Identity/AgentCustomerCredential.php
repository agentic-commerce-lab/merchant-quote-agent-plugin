<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * How an agent proves it may act for a customer: an OAuth access token issued
 * by the shop's identity-linking capability.
 *
 * Bearer only, by design. The customer's consent is recorded server-side, the
 * token is scoped and revocable, and the published contract states that an
 * unscoped Shopware context token is not accepted — so there is no second
 * constructor and no second trust boundary to reason about.
 */
final class AgentCustomerCredential
{
    private function __construct(
        #[\SensitiveParameter]
        public readonly string $accessToken,
    ) {}

    public static function fromAccessToken(#[\SensitiveParameter] string $accessToken): self
    {
        if ('' === $accessToken) {
            throw new \InvalidArgumentException('An OAuth access token cannot be empty.');
        }

        return new self($accessToken);
    }

    /**
     * Reads the credential straight off the request's Authorization header.
     *
     * A missing or non-Bearer header is a 401 rather than a 400: the caller
     * has not authenticated, and `UnauthorizedHttpException` carries the
     * `WWW-Authenticate: Bearer` challenge with it. The scheme comparison is
     * case-sensitive on purpose — RFC 6750 spells it `Bearer`, and accepting
     * variants only hides a broken client.
     */
    public static function fromAuthorizationHeader(string $header): self
    {
        if (!str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedHttpException('Bearer', 'An identity-linking access token is required.');
        }

        $token = trim(substr($header, 7));

        if ('' === $token) {
            throw new UnauthorizedHttpException('Bearer', 'An identity-linking access token is required.');
        }

        return new self($token);
    }
}
