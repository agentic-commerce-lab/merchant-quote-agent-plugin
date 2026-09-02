<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

#[CoversClass(AgentAuthorizationContextFactory::class)]
final class AgentAuthorizationContextFactoryTest extends TestCase
{
    private function pending(): PendingAuthorization
    {
        return new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            // json_decode(json_encode(...)) is NOT ceremony — it reproduces what
            // the store actually hands back, and the factory depends on it.
            // PlatformProfile::toArray() renders empty maps as stdClass so they
            // serialise as `{}` rather than `[]`, but fromArray() requires
            // arrays and throws ValidationException: 'Platform profile section
            // "services" must be an object.' on the stdClass form. The real flow
            // survives because the row goes through json_encode on write and
            // json_decode(..., true) on read; a test that skips that round-trip
            // fails for a reason unrelated to the code under test.
            json_decode(json_encode((new PlatformProfile('2026-04-08', [], [], []))->toArray()), true),
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }

    private function forConsent(PendingAuthorization $pending): RequestContext
    {
        return (new AgentAuthorizationContextFactory())->forConsent(
            $pending,
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );
    }

    public function testItReplaysTheVerifiedAgentAndCarriesTheCustomerContextToken(): void
    {
        $context = $this->forConsent($this->pending());

        self::assertSame('shop.example', $context->host);
        self::assertSame('https://agent.example/.well-known/ucp', $context->platformProfileUri);
        self::assertNotNull($context->platformProfile);
        self::assertSame('2026-04-08', $context->platformProfile->version);
        self::assertTrue($context->signatureVerified);
        self::assertSame('ctx-token', $context->headers['sw-context-token']);
    }

    public function testItSatisfiesTheClientBindingItWasBuiltFrom(): void
    {
        $pending = $this->pending();

        $context = $this->forConsent($pending);

        // These are exactly the three things AC's assertClientId() checks.
        self::assertTrue($context->signatureVerified);
        self::assertNotNull($context->platformProfile);
        self::assertSame($pending->clientId, $context->platformProfileUri);
    }

    /**
     * assertClientId() is NOT the first thing AC runs — assertEnabled() is,
     * and it reads a different field entirely. This test exists because the
     * one above passed while the feature was dead: the factory left
     * `runtimeConfiguration` null, so
     * `UcpCapabilityCatalog::isEnabled(null, …)` returned false and
     * authorize() threw 'Identity linking capability is disabled for this
     * sales channel.' on every grant, before any of the three assertions
     * above were ever consulted.
     */
    public function testItCarriesTheRuntimeConfigurationAcChecksBeforeTheClientBinding(): void
    {
        $context = $this->forConsent($this->pending());

        self::assertNotNull($context->runtimeConfiguration);
        self::assertContains(
            IdentityLinkingCapabilityFixture::DESCRIPTOR,
            $context->runtimeConfiguration->enabledCapabilities,
        );
    }
}
