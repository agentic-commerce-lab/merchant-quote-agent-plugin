<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PayloadFields;
use MerchantQuoteAgentPlugin\Identity\Authorization\UnverifiedAgentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

#[CoversClass(AgentAuthorizationRegistrar::class)]
#[CoversClass(UnverifiedAgentException::class)]
final class AgentAuthorizationRegistrarTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const CLIENT_ID = 'https://agent.example/.well-known/ucp?run=1';

    public function testItRegistersAVerifiedRequest(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());

        $handle = $registrar->register(
            AgentAuthorizationRegistrarFixture::payload(),
            AgentAuthorizationRegistrarFixture::verifiedContext(),
            self::SALES_CHANNEL_ID,
        );

        self::assertSame('handle-value', $handle);
        self::assertCount(1, $store->stored);
        self::assertSame(self::CLIENT_ID, $store->stored[0]->clientId);
        self::assertSame(self::SALES_CHANNEL_ID, $store->stored[0]->salesChannelId);
        self::assertSame('2026-04-08', $store->stored[0]->agentProfile['ucp']['version']);
    }

    /**
     * The reason this check cannot be dropped: under signaturePolicy "log" the
     * SDK proceeds on an unverified signature, so reaching a /ucp/ route proves
     * nothing. Without this, consent would stamp signatureVerified: true on an
     * agent nobody authenticated.
     */
    public function testItRefusesWhenTheRequestSignatureDidNotVerify(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());

        try {
            $registrar->register(
                AgentAuthorizationRegistrarFixture::payload(),
                AgentAuthorizationRegistrarFixture::unverifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('An unverified agent must not be able to register an authorization request.');
        } catch (UnverifiedAgentException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when the agent is unverified');
        }
    }

    public function testItRefusesWhenTheClientIdIsNotTheVerifiedProfileUri(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());

        try {
            $registrar->register(
                AgentAuthorizationRegistrarFixture::payload(),
                AgentAuthorizationRegistrarFixture::contextWithProfileUri('https://other.example/.well-known/ucp'),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A client_id not matching the verified profile URI must not be able to register.');
        } catch (UnverifiedAgentException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when client_id does not match');
        }
    }

    public function testItRefusesWhenNoProfileWasFetched(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());

        try {
            $registrar->register(
                AgentAuthorizationRegistrarFixture::payload(),
                AgentAuthorizationRegistrarFixture::contextWithoutProfile(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A request with no verified profile must not be able to register.');
        } catch (UnverifiedAgentException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when no profile was fetched');
        }
    }

    public function testItRefusesAnythingButS256(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['code_challenge_method'] = 'plain';

        try {
            $registrar->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A non-S256 code_challenge_method must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when the challenge method is not S256');
        }
    }

    public function testItRefusesAMissingCodeChallenge(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());
        $payload = AgentAuthorizationRegistrarFixture::payload();
        unset($payload['code_challenge']);

        try {
            $registrar->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A missing code_challenge must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when code_challenge is missing');
        }
    }

    public function testItRefusesAMissingState(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());
        $payload = AgentAuthorizationRegistrarFixture::payload();
        unset($payload['state']);

        try {
            $registrar->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A missing state must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when state is missing');
        }
    }

    public function testItRefusesAWhitespaceOnlyState(): void
    {
        $registrar = new AgentAuthorizationRegistrar(AgentAuthorizationRegistrarFixture::store(), new PayloadFields());
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['state'] = '   ';

        $this->expectException(ValidationException::class);

        $registrar->register($payload, AgentAuthorizationRegistrarFixture::verifiedContext(), self::SALES_CHANNEL_ID);
    }

    /**
     * Denial never reaches AC's own redirect_uri check (grant does, inside
     * authorize()), so a non-http(s) scheme stored here would make the shop a
     * redirector — e.g. to `javascript:` — for anything a verified agent asked.
     */
    public function testItRefusesARedirectUriThatIsNotHttpOrHttps(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['redirect_uri'] = 'javascript:alert(1)';

        try {
            $registrar->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A non-http(s) redirect_uri must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when redirect_uri has no http(s) scheme');
        }
    }

    /**
     * A fragment is never sent to a server, so denialUrl()'s appended query
     * string would land inside it (or, if the fragment itself contains a `?`,
     * corrupt the separator) — the agent never receives the denial, and the
     * shop reports success against a URL nobody receives.
     */
    public function testItRefusesARedirectUriCarryingAFragment(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $registrar = new AgentAuthorizationRegistrar($store, new PayloadFields());
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['redirect_uri'] = 'https://agent.example/callback#fragment';

        try {
            $registrar->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail('A redirect_uri carrying a fragment must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when redirect_uri carries a fragment');
        }
    }
}
