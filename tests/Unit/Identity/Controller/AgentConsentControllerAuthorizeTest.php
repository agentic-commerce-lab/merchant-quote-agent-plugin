<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The GET half of the consent flow — split out of AgentConsentControllerTest
 * (which covers grant()) to stay under mago's too-many-methods ceiling.
 */
#[CoversClass(AgentConsentController::class)]
final class AgentConsentControllerAuthorizeTest extends TestCase
{
    public function testAuthorizeStashesTheQueryHandleAndRendersConsent(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
        $request = AgentConsentControllerFixture::authorizeRequest('the-handle');
        $context = AgentConsentControllerFixture::customerContext($this->createMock(SalesChannelContext::class));
        $container = AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        );
        $controller->setContainer($container);

        $response = $controller->authorize($request, $context);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame('the-handle', $request->getSession()->get(AgentConsentController::SESSION_KEY));
        $twig = $container->get('twig');
        self::assertFalse($twig->lastParameters['expired']);
        self::assertSame(hash('sha256', 'the-handle'), $twig->lastParameters['formToken']);
    }

    public function testAuthorizeRedirectsAnonymousVisitorToLoginWithoutRendering(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
        $request = AgentConsentControllerFixture::authorizeRequest('the-handle');
        $context = AgentConsentControllerFixture::customerContext(
            $this->createMock(SalesChannelContext::class),
            signedIn: false,
        );
        $container = AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        );
        $controller->setContainer($container);

        $response = $controller->authorize($request, $context);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $twig = $container->get('twig');
        self::assertSame([], $twig->lastParameters, 'an anonymous visitor must be sent to login, not shown the page');
    }

    /** The finding-2 binding: a channel mismatch on GET must refuse, not merely defer to submit time. */
    public function testAuthorizeRefusesOnChannelMismatch(): void
    {
        $pending = AgentConsentControllerFixture::pending();
        $store = AgentConsentControllerFixture::store($pending, $pending);
        $controller = AgentConsentControllerFixture::controller($store);
        $request = AgentConsentControllerFixture::authorizeRequest('the-handle');
        $context = AgentConsentControllerFixture::customerContext(
            $this->createMock(SalesChannelContext::class),
            '0191d3d0a0b071bd9c1a0d9d1a3f9fff',
        );
        $container = AgentConsentControllerFixture::renderableContainer(
            $request,
            $context,
            $this->createMock(SystemConfigService::class),
            $this->createMock(TemplateFinder::class),
        );
        $controller->setContainer($container);

        $response = $controller->authorize($request, $context);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        $twig = $container->get('twig');
        self::assertTrue($twig->lastParameters['expired']);
        self::assertNull(
            $request->getSession()->get(AgentConsentController::SESSION_KEY),
            'a refused handle must not linger in the session',
        );
    }
}
