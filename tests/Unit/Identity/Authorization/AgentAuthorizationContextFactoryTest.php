<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Profile\PlatformProfile;

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

    public function testItReplaysTheVerifiedAgentAndCarriesTheCustomerContextToken(): void
    {
        $context = (new AgentAuthorizationContextFactory())->forConsent($this->pending(), 'shop.example', 'ctx-token');

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

        $context = (new AgentAuthorizationContextFactory())->forConsent($pending, 'shop.example', 'ctx-token');

        // These are exactly the three things AC's assertClientId() checks.
        self::assertTrue($context->signatureVerified);
        self::assertNotNull($context->platformProfile);
        self::assertSame($pending->clientId, $context->platformProfileUri);
    }
}
