<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The security boundary of the browser authorization flow, in one place.
 *
 * Consent later hands Agentic Commerce a RequestContext this plugin built, with
 * `signatureVerified: true`. That claim is only truthful if the agent really was
 * authenticated, so a record is written *only* from a request whose signature
 * the SDK confirmed — read off the flag, never inferred from having been routed
 * under `/ucp/`. Under `signaturePolicy: log` the SDK logs an unverified
 * signature and carries on, which is precisely why the flag has to be read.
 *
 * If this check is ever weakened, the browser hop becomes a way to launder an
 * unauthenticated agent into a verified context. `AgentAuthorizationRegistrarTest`
 * pins both directions; neither test should be deleted.
 *
 * The payload-field primitives live in {@see PayloadFields} to keep this
 * class within the project's maintainability gates — the same reason
 * src/Ucp/Quote/QuoteFieldAssertions.php exists.
 */
final readonly class AgentAuthorizationRegistrar
{
    public const TTL_SECONDS = 600;

    private const REQUIRED_CHALLENGE_METHOD = 'S256';

    public function __construct(
        private PendingAuthorizationStoreInterface $store,
        private PayloadFields $fields,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UnverifiedAgentException
     * @throws ValidationException
     * @throws \Doctrine\DBAL\Exception
     */
    public function register(array $payload, RequestContext $context, string $salesChannelId): string
    {
        $clientId = $this->fields->requiredString($payload, 'client_id');

        $this->assertVerifiedAgent($context, $clientId);

        $challengeMethod = $this->fields->requiredString($payload, 'code_challenge_method');

        if ($challengeMethod !== self::REQUIRED_CHALLENGE_METHOD) {
            throw new ValidationException('Only the S256 PKCE challenge method is supported.', [
                '$.code_challenge_method must be "S256"',
            ]);
        }

        return $this->store->store(
            new PendingAuthorization(
                $salesChannelId,
                $clientId,
                $context->platformProfile?->toArray() ?? [],
                $this->fields->requiredString($payload, 'redirect_uri'),
                $this->fields->optionalString($payload, 'scope'),
                $this->fields->requiredString($payload, 'state'),
                $this->fields->requiredString($payload, 'code_challenge'),
                $challengeMethod,
            ),
            self::TTL_SECONDS,
        );
    }

    /** @throws UnverifiedAgentException */
    private function assertVerifiedAgent(RequestContext $context, string $clientId): void
    {
        if (!$context->signatureVerified || $context->platformProfile === null) {
            throw UnverifiedAgentException::signatureNotVerified();
        }

        if ($context->platformProfileUri === null || !hash_equals($context->platformProfileUri, $clientId)) {
            throw UnverifiedAgentException::clientIdMismatch();
        }
    }
}
