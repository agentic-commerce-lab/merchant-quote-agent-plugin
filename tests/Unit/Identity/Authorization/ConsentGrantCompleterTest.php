<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentGrantCompleter;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

#[CoversClass(ConsentGrantCompleter::class)]
final class ConsentGrantCompleterTest extends TestCase
{
    public function testItSendsOnlyTheClaimedRecordsFieldsAndReturnsTheRedirectTarget(): void
    {
        $identityLinking = $this->identityLinking(['redirect_to' => 'https://agent.example/callback?code=abc']);
        $completer = new ConsentGrantCompleter(new AgentAuthorizationContextFactory(), $identityLinking);

        $redirectTo = $completer->redirectTarget($this->claimed(), 'shop.example', 'ctx-token');

        self::assertSame('https://agent.example/callback?code=abc', $redirectTo);
        self::assertNotNull($identityLinking->received);
        self::assertSame('https://agent.example/.well-known/ucp', $identityLinking->received->clientId);
        self::assertSame('https://agent.example/callback', $identityLinking->received->redirectUri);
        self::assertSame('dev.ucp.shopping.order:read', $identityLinking->received->scope);
        self::assertSame('state-value', $identityLinking->received->state);
        self::assertSame('challenge-value', $identityLinking->received->codeChallenge);
        self::assertSame('S256', $identityLinking->received->codeChallengeMethod);
    }

    public function testItThrowsWhenNoRedirectTargetComesBack(): void
    {
        $completer = new ConsentGrantCompleter(new AgentAuthorizationContextFactory(), $this->identityLinking([]));

        $this->expectException(\RuntimeException::class);

        $completer->redirectTarget($this->claimed(), 'shop.example', 'ctx-token');
    }

    public function testItThrowsWhenTheRedirectTargetIsAnEmptyString(): void
    {
        $completer = new ConsentGrantCompleter(new AgentAuthorizationContextFactory(), $this->identityLinking([
            'redirect_to' => '',
        ]));

        $this->expectException(\RuntimeException::class);

        $completer->redirectTarget($this->claimed(), 'shop.example', 'ctx-token');
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

    /** @param array<string, mixed> $authorizeResult */
    private function identityLinking(array $authorizeResult): IdentityLinkingCapabilityInterface
    {
        return new class($authorizeResult) implements IdentityLinkingCapabilityInterface {
            public ?OAuthAuthorizationRequest $received = null;

            /** @param array<string, mixed> $authorizeResult */
            public function __construct(
                private readonly array $authorizeResult,
            ) {}

            public function describe(): CapabilityDescriptor
            {
                throw new \LogicException('Not needed by this test.');
            }

            public function getMetadata(RequestContext $context): OAuthMetadata
            {
                throw new \LogicException('Not needed by this test.');
            }

            public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
            {
                $this->received = $request;

                return $this->authorizeResult;
            }

            public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
            {
                throw new \LogicException('Not needed by this test.');
            }
        };
    }
}
