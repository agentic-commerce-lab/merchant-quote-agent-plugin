<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationContextFactory;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentGrantCompleter;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaUrlPlaceholderHandlerInterface;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * Collaborators for AgentConsentControllerTest's grant() coverage — fakes,
 * not mocks, kept out of the test class to stay under mago's too-many-methods
 * ceiling (the same reason AgentAuthorizationRegistrarFixture exists).
 */
final class AgentConsentControllerFixture
{
    public const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private function __construct() {}

    public static function pending(): PendingAuthorization
    {
        return new PendingAuthorization(
            self::SALES_CHANNEL_ID,
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

    /**
     * A recording double. `find()` and `consume()` return independently
     * configurable results, so a race where `find()` still sees a record but
     * a concurrent `consume()` already claimed it is representable. Both
     * honour `$expectedHandle`: a handle that does not match sees a null
     * result regardless of the configured one, so a test that submits the
     * wrong handle is actually exercising "not found", not just a store
     * that always says no.
     */
    public static function store(
        ?PendingAuthorization $findResult,
        ?PendingAuthorization $consumeResult,
        string $expectedHandle = 'the-handle',
    ): object {
        return new class($findResult, $consumeResult, $expectedHandle) implements PendingAuthorizationStoreInterface {
            public int $consumeCalls = 0;

            /** @var list<string> */
            public array $consumedHandles = [];

            public function __construct(
                private readonly ?PendingAuthorization $findResult,
                private readonly ?PendingAuthorization $consumeResult,
                private readonly string $expectedHandle,
            ) {}

            public function store(PendingAuthorization $pending, int $ttlSeconds): string
            {
                return 'handle-value';
            }

            public function find(#[\SensitiveParameter] string $handle): ?PendingAuthorization
            {
                return $handle === $this->expectedHandle ? $this->findResult : null;
            }

            public function consume(#[\SensitiveParameter] string $handle): ?PendingAuthorization
            {
                $this->consumeCalls++;
                $this->consumedHandles[] = $handle;

                return $handle === $this->expectedHandle ? $this->consumeResult : null;
            }
        };
    }

    /** A completer wired to a real AgentAuthorizationContextFactory and the given AC stub. */
    public static function completer(IdentityLinkingCapabilityInterface $identityLinking): ConsentGrantCompleter
    {
        return new ConsentGrantCompleter(new AgentAuthorizationContextFactory(), $identityLinking);
    }

    /** @return IdentityLinkingCapabilityInterface&object{received: ?OAuthAuthorizationRequest} */
    public static function identityLinking(): object
    {
        return new class implements IdentityLinkingCapabilityInterface {
            public ?OAuthAuthorizationRequest $received = null;

            public function describe(): CapabilityDescriptor
            {
                throw new \LogicException('Not needed by this fixture.');
            }

            public function getMetadata(RequestContext $context): OAuthMetadata
            {
                throw new \LogicException('Not needed by this fixture.');
            }

            public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
            {
                $this->received = $request;

                return ['redirect_to' => 'https://agent.example/callback?code=granted'];
            }

            public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
            {
                throw new \LogicException('Not needed by this fixture.');
            }
        };
    }

    /**
     * A POST with a session already carrying `$handle`. `$token` defaults to
     * the correct one for `$handle`, so tests that are not specifically about
     * the token check do not have to think about it; pass a wrong one to
     * exercise the mismatch. `$extraBody` lets a test plant browser-supplied
     * values (e.g. a decoy `redirect_uri`) that grant() must ignore.
     *
     * @param array<string, mixed> $extraBody
     */
    public static function grantRequest(
        string $handle,
        bool $grant,
        #[\SensitiveParameter]
        ?string $token = null,
        array $extraBody = [],
    ): Request {
        $body = array_merge($extraBody, [
            'grant' => $grant ? '1' : '0',
            'token' => $token ?? hash('sha256', $handle),
        ]);
        $request = Request::create('/quote-agent/authorize', 'POST', $body);
        $session = new Session(new MockArraySessionStorage());
        $session->set(AgentConsentController::SESSION_KEY, $handle);
        $request->setSession($session);

        return $request;
    }

    /** A POST with an empty session — nothing stashed the way a real GET would have. */
    public static function emptySessionGrantRequest(bool $grant, string $requestUriInBody = ''): Request
    {
        $body = ['grant' => $grant ? '1' : '0'];

        if ($requestUriInBody !== '') {
            // A decoy: grant() must never read this — the handle comes only
            // from the session, which this request deliberately has none of.
            $body['request_uri'] = $requestUriInBody;
        }

        $request = Request::create('/quote-agent/authorize', 'POST', $body);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /** A GET, optionally carrying `?request_uri=` the way the agent's authorization_url does on the first hit. */
    public static function authorizeRequest(string $queryHandle = ''): Request
    {
        $query = $queryHandle === '' ? [] : ['request_uri' => $queryHandle];
        $request = Request::create('/quote-agent/authorize', 'GET', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * Stubs the three methods grant()/matchesChannel() actually call onto a
     * mock the test builds with `$this->createMock()` (a TestCase-only
     * method, so the mock itself can't be built in here). `$signedIn = false`
     * gives the guest case authorize()'s login redirect needs.
     */
    public static function customerContext(
        MockObject&SalesChannelContext $context,
        string $salesChannelId = self::SALES_CHANNEL_ID,
        bool $signedIn = true,
    ): SalesChannelContext {
        $context->method('getCustomer')->willReturn($signedIn ? new CustomerEntity() : null);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getToken')->willReturn('customer-context-token');

        return $context;
    }

    /**
     * A container that lets `renderStorefront()` (and, for the login
     * redirect, `redirectToRoute()`) actually run, so a test can observe a
     * real Response rather than only "it didn't render/redirect". `twig`
     * captures the parameters it was called with on `$lastParameters` (fetch
     * it back via `$container->get('twig')`) so a render-path test can assert
     * on them; the returned content is always empty — nothing here asserts on
     * markup.
     */
    public static function renderableContainer(
        Request $request,
        SalesChannelContext $context,
        object $systemConfig,
        object $templateFinder,
    ): Container {
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $context);
        $request->attributes->set(RequestTransformer::STOREFRONT_URL, 'https://shop.example');

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('event_dispatcher', new EventDispatcher());
        $container->set(SystemConfigService::class, $systemConfig);
        $container->set(TemplateFinder::class, $templateFinder);
        $container->set('router', new class {
            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = 1): string
            {
                return '/account/login';
            }
        });
        $container->set('twig', new class {
            /** @var array<string, mixed> */
            public array $lastParameters = [];

            /** @param array<string, mixed> $parameters */
            public function render(string $view, array $parameters = []): string
            {
                $this->lastParameters = $parameters;

                return '';
            }
        });
        // Post-processing renderStorefront() does after the twig render — both
        // are pass-throughs, nothing here asserts on markup.
        $container->set(SeoUrlPlaceholderHandlerInterface::class, new class implements
            SeoUrlPlaceholderHandlerInterface {
            /** @param array<mixed> $parameters */
            public function generate($name, array $parameters = []): string
            {
                return '';
            }

            public function replace(string $content, string $host, SalesChannelContext $context): string
            {
                return $content;
            }
        });
        $container->set(MediaUrlPlaceholderHandlerInterface::class, new class implements
            MediaUrlPlaceholderHandlerInterface {
            public function replace(string $content): string
            {
                return $content;
            }
        });

        return $container;
    }
}
