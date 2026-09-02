<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use MerchantQuoteAgentPlugin\Identity\Controller\PendingAuthorizationPresenter;
use MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization\IdentityLinkingCapabilityFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Ucp\Sdk\Exception\OAuthException;

/** grant()'s behavior; GET's lives in AgentConsentControllerAuthorizeTest and the page projections in PendingAuthorizationPresenterTest. */
#[CoversClass(AgentConsentController::class)]
final class AgentConsentControllerTest extends TestCase
{
    /** The single-use rule itself: granting must claim the record, not just look it up. */
    public function testGrantConsumesTheRecordAtGrantTime(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
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
        $identityLinking = IdentityLinkingCapabilityFixture::guarded();
        $controller = AgentConsentControllerFixture::controller($store, $identityLinking);
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
        $controller = AgentConsentControllerFixture::controller($store);
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: false);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));

        $response = $controller->grant($request, $context);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(PendingAuthorizationPresenter::denialUrl($pending), $response->getTargetUrl());
        self::assertSame(1, $store->consumeCalls);
    }

    /**
     * A stale link (expired, unknown, or already consumed) must render, never
     * redirect — it cannot become a redirector. Configuring the store FOR
     * 'the-handle' while submitting a different one is what makes this
     * "unknown handle", not just "a store that always says no".
     */
    public function testAnExpiredOrUnknownHandleRendersRatherThanRedirects(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
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

    /**
     * The docblock's promise: nothing but the stored record's own fields
     * reach AC. Planting DIFFERENT values for the same field names in the
     * POST body is what makes this a real pin — grant() never reads
     * redirect_uri/scope/state from the request at all, so the stored ones
     * winning is not a coincidence of the body being empty.
     */
    public function testOnlyStoredFieldsReachTheAuthorizationRequest(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $identityLinking = IdentityLinkingCapabilityFixture::guarded();
        $controller = AgentConsentControllerFixture::controller($store, $identityLinking);
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true, extraBody: [
            'redirect_uri' => 'https://evil.example/callback',
            'scope' => 'evil.scope',
            'state' => 'evil-state',
        ]);
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

    /** The fix for the CSRF finding must have a test that fails if it is undone. */
    public function testGrantIgnoresARequestUriSuppliedInThePostBody(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
        $request = AgentConsentControllerFixture::emptySessionGrantRequest(grant: true, requestUriInBody: 'the-handle');
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));
        $controller->setContainer(AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        ));

        $response = $controller->grant($request, $context);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(0, $store->consumeCalls, 'the body-supplied handle must never reach the store');
    }

    /**
     * Closes the swap the CSRF fix opened: a handle overwritten in the
     * session by another tab after this page was rendered must invalidate
     * the still-displayed page's Allow click, not silently grant the swap.
     */
    public function testGrantRefusesOnTokenMismatchWithoutConsuming(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
        $mismatchedToken = hash('sha256', 'a-different-handle');
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true, token: $mismatchedToken);
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

    /**
     * Critical, and the reason IdentityLinkingCapabilityFixture replicates
     * AC's guard: the context this controller builds must carry the sales
     * channel's RuntimeConfiguration. AC's authorize() reads it in its FIRST
     * statement and treats null as "capability disabled", so a context without
     * one is refused on every grant — the whole feature dead, behind an error
     * message that blames configuration. Asserted here, at the controller,
     * because this is where the Symfony request becomes that configuration.
     */
    public function testTheContextHandedToAgenticCommerceCarriesTheRuntimeConfiguration(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $identityLinking = IdentityLinkingCapabilityFixture::guarded();
        $controller = AgentConsentControllerFixture::controller($store, $identityLinking);
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));
        // Only so a regression fails on the assertion below rather than on an
        // uninitialised container: without a runtime configuration the double
        // refuses and grant() takes the render path.
        $controller->setContainer(AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        ));

        $controller->grant($request, $context);

        self::assertNotNull($identityLinking->receivedContext, 'AC must have been reached at all');
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
     * by the time they run, so a refusal must render the terminal page. An
     * unhandled UcpException here was a 500 out of a storefront controller,
     * with the customer's handle already burnt.
     */
    public function testAnAgenticCommerceRefusalRendersTheErrorPageRatherThan500(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller(
            $store,
            IdentityLinkingCapabilityFixture::guarded(
                refusal: new OAuthException('OAuth redirect URI must use the signed platform profile origin.'),
            ),
        );
        $request = AgentConsentControllerFixture::grantRequest('the-handle', grant: true);
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));
        $container = AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        );
        $controller->setContainer($container);

        $response = $controller->grant($request, $context);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertTrue($container->get('twig')->lastParameters['expired']);
    }
}
