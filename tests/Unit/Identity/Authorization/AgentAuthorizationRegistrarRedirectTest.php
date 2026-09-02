<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\RedirectUriRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

/**
 * What may be stored as a `redirect_uri` — split out of
 * AgentAuthorizationRegistrarTest (which owns the boundary rule) to stay
 * under mago's too-many-methods ceiling; the PKCE challenge's shape lives in
 * AgentAuthorizationRegistrarChallengeTest for the same reason.
 *
 * Every test here asserts the WRITE, not just the throw. That is the whole
 * value: the grant path is protected by AC re-running its own
 * `assertRedirectUri()` inside `authorize()`, but DENIAL never reaches AC —
 * `PendingAuthorizationPresenter::denialUrl()` sends the browser to the stored
 * URI raw. So a `redirect_uri` that gets persisted is a place the shop will
 * 302 a signed-in customer, and a verified agent is the one choosing it.
 * Registration is the only gate on that path, which is why these mirror AC's
 * rules rather than a looser subset.
 */
#[CoversClass(AgentAuthorizationRegistrar::class)]
#[CoversClass(RedirectUriRule::class)]
final class AgentAuthorizationRegistrarRedirectTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    /**
     * @param array<string, mixed> $payload
     * @param non-empty-string     $because
     */
    private function assertRefused(array $payload, string $because): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();

        try {
            AgentAuthorizationRegistrarFixture::registrar($store)->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail($because);
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted: ' . $because);
        }
    }

    /** @return array<string, mixed> */
    private function payloadWithRedirectUri(string $redirectUri): array
    {
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['redirect_uri'] = $redirectUri;

        return $payload;
    }

    /** A scheme with no host at all — `javascript:` being the one that turns a 302 into script execution. */
    public function testItRefusesARedirectUriWithNoHost(): void
    {
        $this->assertRefused(
            $this->payloadWithRedirectUri('javascript:alert(1)'),
            'a redirect_uri with no host must not be able to register',
        );
    }

    /**
     * A fragment is never sent to a server, so denialUrl()'s appended query
     * string would land inside it (or, if the fragment itself contains a `?`,
     * corrupt the separator) — the agent never receives the denial, and the
     * shop reports success against a URL nobody receives.
     */
    public function testItRefusesARedirectUriCarryingAFragment(): void
    {
        $this->assertRefused(
            $this->payloadWithRedirectUri('https://agent.example/callback#fragment'),
            'a redirect_uri carrying a fragment must not be able to register',
        );
    }

    /**
     * A CRLF in the stored URI reaches the `Location` header untouched:
     * `requiredString()`'s `trim()` strips edges only, `denialUrl()`
     * concatenates raw, and `RedirectResponse` does not validate. PHP's
     * `header()` has refused `\r`/`\n` since 5.1.2, so the realistic outcome
     * was a broken denial rather than a split response — but denial is the one
     * path Agentic Commerce never re-validates, so this rule must stand on its
     * own rather than on a guard in the SAPI beneath it.
     *
     * Note the double-quoted string: the assertion is about real CR and LF
     * bytes, not the two-character sequence `\r\n`, which the pattern would
     * reject for the unrelated reason of being ordinary text.
     */
    public function testItRefusesARedirectUriCarryingACrlf(): void
    {
        $this->assertRefused(
            $this->payloadWithRedirectUri("https://agent.example/cb\r\nSet-Cookie: a=b"),
            'a redirect_uri carrying a CRLF must not be able to register',
        );
    }

    /**
     * AC's `assertRedirectUri()` requires https except for localhost. Plain
     * http on a real host would put the authorization code — and the customer
     * — on the wire in clear.
     */
    public function testItRefusesPlainHttpOnANonLocalhostHost(): void
    {
        $this->assertRefused(
            $this->payloadWithRedirectUri('http://agent.example/callback'),
            'a plain-http redirect_uri on a real host must not be able to register',
        );
    }

    /**
     * The open redirect this closes: AC additionally requires the redirect to
     * share the `client_id` origin, and denial never reaches AC. Without this
     * mirror, a verified agent could register `https://any-host/anything` and
     * have the shop 302 a signed-in customer's browser there on Deny.
     */
    public function testItRefusesARedirectUriOnADifferentOriginThanTheClientId(): void
    {
        $this->assertRefused(
            $this->payloadWithRedirectUri('https://evil.example/callback'),
            'a redirect_uri on another origin than client_id must not be able to register',
        );
    }

    /** Same host, different port is a different origin — AC compares scheme, host AND port. */
    public function testItRefusesARedirectUriOnADifferentPortThanTheClientId(): void
    {
        $this->assertRefused(
            $this->payloadWithRedirectUri('https://agent.example:8443/callback'),
            'a redirect_uri on another port than client_id must not be able to register',
        );
    }

    /** A path and query on the client_id's own origin is the ordinary case and must still register. */
    public function testItAcceptsARedirectUriOnTheClientIdOrigin(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();

        AgentAuthorizationRegistrarFixture::registrar($store)->register(
            $this->payloadWithRedirectUri('https://agent.example/callback?existing=1'),
            AgentAuthorizationRegistrarFixture::verifiedContext(),
            self::SALES_CHANNEL_ID,
        );

        self::assertCount(1, $store->stored);
        self::assertSame('https://agent.example/callback?existing=1', $store->stored[0]->redirectUri);
    }

    /**
     * The exemption AC grants, kept rather than tightened: local development
     * runs the agent on `http://localhost`, and refusing it here would make
     * the flow untestable without a tunnel while AC itself allowed it.
     */
    public function testItAcceptsHttpLocalhostWhenTheClientIdIsAlsoLocalhost(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['client_id'] = AgentAuthorizationRegistrarFixture::LOCALHOST_CLIENT_ID;
        $payload['redirect_uri'] = 'http://localhost:8765/callback';

        AgentAuthorizationRegistrarFixture::registrar($store)->register(
            $payload,
            AgentAuthorizationRegistrarFixture::contextWithProfileUri(AgentAuthorizationRegistrarFixture::LOCALHOST_CLIENT_ID),
            self::SALES_CHANNEL_ID,
        );

        self::assertCount(1, $store->stored);
        self::assertSame('http://localhost:8765/callback', $store->stored[0]->redirectUri);
    }
}
