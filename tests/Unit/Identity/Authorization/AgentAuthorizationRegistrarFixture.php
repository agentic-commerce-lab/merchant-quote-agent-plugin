<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PayloadFields;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\RedirectUriRule;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * Builders for AgentAuthorizationRegistrarTest that need no TestCase — fakes,
 * not mocks. Separate from the test class so that class stays under mago's
 * too-many-methods ceiling.
 */
final class AgentAuthorizationRegistrarFixture
{
    private const CLIENT_ID = 'https://agent.example/.well-known/ucp?run=1';

    /**
     * A real S256 challenge: base64url(sha256('verifier')), 43 characters.
     * PayloadFields checks the shape, so a placeholder like 'challenge-value'
     * is no longer a payload the registrar accepts.
     */
    public const CODE_CHALLENGE = 'iMnq5o6zALKXGivsnlom_0F5_WYda32GHkxlV7mq7hQ';

    /**
     * The local-development shape: AC's assertClientId() still requires an
     * https client_id, but assertRedirectUri() exempts an `http://localhost`
     * redirect when the client is on localhost too.
     */
    public const LOCALHOST_CLIENT_ID = 'https://localhost/.well-known/ucp';

    private function __construct() {}

    /**
     * A recording double. The captured records are read off the object itself:
     * a promoted constructor property cannot be by-reference (fatal error), so
     * the double owns the array rather than aliasing the test's.
     */
    public static function store()
    {
        // Deliberately no return type: the anonymous class's own shape has to
        // flow to the caller so `$store->stored` type-checks.
        return new class implements PendingAuthorizationStoreInterface {
            /** @var list<PendingAuthorization> */
            public array $stored = [];

            public function store(PendingAuthorization $pending, int $ttlSeconds): string
            {
                $this->stored[] = $pending;

                return 'handle-value';
            }

            public function find(string $handle): ?PendingAuthorization
            {
                return null;
            }

            public function consume(string $handle): ?PendingAuthorization
            {
                return null;
            }
        };
    }

    /** The real registrar over its real field collaborators; only the store is doubled. */
    public static function registrar(PendingAuthorizationStoreInterface $store): AgentAuthorizationRegistrar
    {
        $fields = new PayloadFields();

        return new AgentAuthorizationRegistrar($store, $fields, new RedirectUriRule($fields));
    }

    public static function verifiedContext(): RequestContext
    {
        return new RequestContext('shop.example', [], self::CLIENT_ID, self::profile(), [], true);
    }

    public static function unverifiedContext(): RequestContext
    {
        return new RequestContext('shop.example', [], self::CLIENT_ID, self::profile(), [], false);
    }

    public static function contextWithoutProfile(): RequestContext
    {
        return new RequestContext('shop.example', [], self::CLIENT_ID, null, [], true);
    }

    public static function contextWithProfileUri(string $uri): RequestContext
    {
        return new RequestContext('shop.example', [], $uri, self::profile(), [], true);
    }

    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'https://agent.example/callback',
            'scope' => 'dev.ucp.shopping.order:read',
            'state' => 'state-value',
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
        ];
    }

    private static function profile(): PlatformProfile
    {
        return new PlatformProfile('2026-04-08', [], [], []);
    }
}
