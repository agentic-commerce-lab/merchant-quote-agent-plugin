<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentRequestGuard;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;

#[CoversClass(AgentConsentController::class)]
final class AgentConsentControllerTest extends TestCase
{
    public function testItNamesTheAgentByHostRatherThanTheFullProfileUri(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp?run=1788265660',
            [],
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read dev.ucp.shopping.cart:manage',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertSame('agent.example', AgentConsentController::agentHost($pending));
        self::assertSame(
            ['dev.ucp.shopping.order:read', 'dev.ucp.shopping.cart:manage'],
            AgentConsentController::scopeList($pending),
        );
    }

    public function testAnEmptyScopeListsNothingRatherThanOneBlankEntry(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            '',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertSame([], AgentConsentController::scopeList($pending));
    }

    public function testDenialRedirectsToTheStoredRedirectUriWithTheOriginalState(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            '',
            'state value/with?chars',
            'challenge-value',
            'S256',
        );

        $url = AgentConsentController::denialUrl($pending);

        self::assertStringStartsWith('https://agent.example/callback?', $url);
        self::assertStringContainsString('error=access_denied', $url);
        self::assertStringContainsString('state=' . urlencode('state value/with?chars'), $url);
    }

    public function testDenialAppendsToARedirectUriThatAlreadyHasAQuery(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback?existing=1',
            '',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertStringContainsString('?existing=1&', AgentConsentController::denialUrl($pending));
    }

    /** The single-use rule itself: granting must claim the record, not just look it up. */
    public function testGrantConsumesTheRecordAtGrantTime(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = new AgentConsentController(
            $store,
            new ConsentRequestGuard($store),
            AgentConsentControllerFixture::completer(AgentConsentControllerFixture::identityLinking()),
        );
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));

        $response = $controller->grant($request, $context);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(1, $store->consumeCalls);
        self::assertSame(['the-handle'], $store->consumedHandles);
    }

    /**
     * A concurrent submission that already claimed the record must not reach
     * AC's authorize() a second time — that would be minting a second code
     * from one registration.
     */
    public function testANullConsumeAbortsBeforeReachingAuthorize(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, null);
        $identityLinking = AgentConsentControllerFixture::identityLinking();
        $controller = new AgentConsentController(
            $store,
            new ConsentRequestGuard($store),
            AgentConsentControllerFixture::completer($identityLinking),
        );
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));
        $controller->setContainer(AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        ));

        $response = $controller->grant($request, $context);

        self::assertNull($identityLinking->received, 'authorize() must not be reached when consume() races to null');
        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    /** Deny is a real OAuth denial, and — like grant — single-use: it must consume, not just redirect. */
    public function testDenialConsumesAndRedirectsWithoutRendering(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = new AgentConsentController(
            $store,
            new ConsentRequestGuard($store),
            AgentConsentControllerFixture::completer(AgentConsentControllerFixture::identityLinking()),
        );
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: false);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));

        $response = $controller->grant($request, $context);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(AgentConsentController::denialUrl($pending), $response->getTargetUrl());
        self::assertSame(1, $store->consumeCalls);
    }

    /** A stale link (expired, unknown, or already consumed) must render, never redirect — it cannot become a redirector. */
    public function testAnExpiredOrUnknownHandleRendersRatherThanRedirects(): void
    {
        $store = AgentConsentControllerFixture::store(null, null);
        $controller = new AgentConsentController(
            $store,
            new ConsentRequestGuard($store),
            AgentConsentControllerFixture::completer(AgentConsentControllerFixture::identityLinking()),
        );
        $request = AgentConsentControllerFixture::grantRequest('unknown-handle', grant: true);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));
        $controller->setContainer(AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        ));

        $response = $controller->grant($request, $context);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(0, $store->consumeCalls);
    }

    /** The docblock's promise: nothing but the stored record's own fields reach AC. */
    public function testOnlyStoredFieldsReachTheAuthorizationRequest(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $identityLinking = AgentConsentControllerFixture::identityLinking();
        $controller = new AgentConsentController(
            $store,
            new ConsentRequestGuard($store),
            AgentConsentControllerFixture::completer($identityLinking),
        );
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));

        $controller->grant($request, $context);

        self::assertNotNull($identityLinking->received);
        self::assertSame($pending->clientId, $identityLinking->received->clientId);
        self::assertSame($pending->redirectUri, $identityLinking->received->redirectUri);
        self::assertSame($pending->scope, $identityLinking->received->scope);
        self::assertSame($pending->state, $identityLinking->received->state);
        self::assertSame($pending->codeChallenge, $identityLinking->received->codeChallenge);
        self::assertSame($pending->codeChallengeMethod, $identityLinking->received->codeChallengeMethod);
    }
}
