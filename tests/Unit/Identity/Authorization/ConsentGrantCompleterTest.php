<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentGrantCompleter;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Profile\PlatformProfile;

#[CoversClass(ConsentGrantCompleter::class)]
final class ConsentGrantCompleterTest extends TestCase
{
    public function testItSendsOnlyTheClaimedRecordsFieldsAndReturnsTheRedirectTarget(): void
    {
        $identityLinking = IdentityLinkingCapabilityFixture::guarded([
            'redirect_to' => 'https://agent.example/callback?code=abc',
        ]);
        $completer = $this->completer($identityLinking);

        $redirectTo = $completer->redirectTarget(
            $this->claimed(),
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );

        self::assertSame('https://agent.example/callback?code=abc', $redirectTo);
        self::assertNotNull($identityLinking->received);
        self::assertSame('https://agent.example/.well-known/ucp', $identityLinking->received->clientId);
        self::assertSame('https://agent.example/callback', $identityLinking->received->redirectUri);
        self::assertSame('dev.ucp.shopping.order:read', $identityLinking->received->scope);
        self::assertSame('state-value', $identityLinking->received->state);
        self::assertSame('challenge-value', $identityLinking->received->codeChallenge);
        self::assertSame('S256', $identityLinking->received->codeChallengeMethod);
    }

    /**
     * The context reaching AC must carry the runtime configuration, because
     * AC reads it before anything else. Asserted directly rather than only
     * through the double's guard, so the reason a regression fails is legible.
     */
    public function testTheContextItBuildsCarriesTheRuntimeConfigurationAcChecksFirst(): void
    {
        $identityLinking = IdentityLinkingCapabilityFixture::guarded();

        $this->completer($identityLinking)->redirectTarget(
            $this->claimed(),
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );

        self::assertNotNull($identityLinking->receivedContext);
        self::assertNotNull(
            $identityLinking->receivedContext->runtimeConfiguration,
            'a null runtimeConfiguration makes AC refuse every grant',
        );
        self::assertContains(
            IdentityLinkingCapabilityFixture::DESCRIPTOR,
            $identityLinking->receivedContext->runtimeConfiguration->enabledCapabilities,
        );
    }

    /**
     * AC's checks are the authoritative ones and the handle is already spent
     * by the time this runs, so a refusal must come back as null for the
     * caller to render — not escape a storefront controller as a 500.
     */
    public function testAnAgenticCommerceRefusalComesBackAsNull(): void
    {
        $identityLinking = IdentityLinkingCapabilityFixture::guarded(
            refusal: new OAuthException('OAuth redirect URI must use the signed platform profile origin.'),
        );

        $redirectTo = $this->completer($identityLinking)->redirectTarget(
            $this->claimed(),
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );

        self::assertNull($redirectTo);
    }

    /** A channel with the capability switched off is a refusal too, not a crash. */
    public function testACapabilityDisabledForTheChannelComesBackAsNull(): void
    {
        $redirectTo = $this->completer(IdentityLinkingCapabilityFixture::guarded())->redirectTarget(
            $this->claimed(),
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::capabilityDisabledConfiguration(),
        );

        self::assertNull($redirectTo);
    }

    public function testItThrowsWhenNoRedirectTargetComesBack(): void
    {
        $completer = $this->completer(IdentityLinkingCapabilityFixture::guarded([]));

        $this->expectException(\RuntimeException::class);

        $completer->redirectTarget(
            $this->claimed(),
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );
    }

    public function testItThrowsWhenTheRedirectTargetIsAnEmptyString(): void
    {
        $completer = $this->completer(IdentityLinkingCapabilityFixture::guarded(['redirect_to' => '']));

        $this->expectException(\RuntimeException::class);

        $completer->redirectTarget(
            $this->claimed(),
            'shop.example',
            'ctx-token',
            IdentityLinkingCapabilityFixture::runtimeConfiguration(),
        );
    }

    private function completer(IdentityLinkingCapabilityInterface $identityLinking): ConsentGrantCompleter
    {
        return new ConsentGrantCompleter(new AgentAuthorizationContextFactory(), $identityLinking, new NullLogger());
    }

    private function claimed(): PendingAuthorization
    {
        return new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            // See AgentAuthorizationContextFactoryTest for why this round-trip
            // is required rather than a raw toArray().
            json_decode(json_encode((new PlatformProfile('2026-04-08', [], [], []))->toArray()), true),
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }
}
