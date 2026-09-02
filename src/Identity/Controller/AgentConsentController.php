<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentGrantCompleter;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentRequestGuard;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The human half of identity linking: sign in, see who is asking, decide.
 *
 * The browser sends no signature and none is needed — the agent was
 * authenticated when it registered the request, and
 * AgentAuthorizationContextFactory replays that. What this controller must not
 * do is trust anything the browser supplies beyond the handle: the redirect
 * target, scope and PKCE challenge all come from the stored record.
 *
 * The handle is stashed in the session before the login redirect rather than
 * passed through Shopware's `redirectParameters`, which the login page and the
 * guest-login page type differently (array vs. JSON string). It also keeps the
 * handle out of the post-login URL.
 *
 * This is the plugin's only controller that renders a themed storefront page
 * rather than JSON, so — unlike UcpQuoteController, QuoteContractController
 * and AgentAuthorizationRequestController — it deliberately extends
 * StorefrontController instead of standing alone as a plain final class.
 *
 * The branchy parts of grant() (find the record, require a customer, bind the
 * sales channel) live in ConsentRequestGuard, the Agentic Commerce exchange
 * lives in ConsentGrantCompleter, and the pure presentation logic lives in
 * PendingAuthorizationPresenter — all split out to keep this class under the
 * class-level cyclomatic-complexity gate.
 *
 * @mago-expect analysis:uninitialized-property
 * Symfony's AbstractController declares `$container` as a typed property with
 * no default and sets it via setContainer(), called by the service
 * registration in services.php — not by this class's constructor. The same
 * pattern is already accepted in MerchantQuoteAgentPlugin.php for Shopware's
 * own Plugin base class.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class AgentConsentController extends StorefrontController
{
    public const SESSION_KEY = 'merchant_quote_agent.pending_authorization';

    private const TEMPLATE = '@MerchantQuoteAgentPlugin/storefront/page/quote-agent/consent.html.twig';

    public function __construct(
        private readonly PendingAuthorizationStoreInterface $store,
        private readonly ConsentRequestGuard $guard,
        private readonly ConsentGrantCompleter $completer,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Route(path: '/quote-agent/authorize', name: 'frontend.merchant_quote_agent.authorize', methods: ['GET'])]
    public function authorize(Request $request, SalesChannelContext $context): Response
    {
        $handle = $this->handle($request);
        $pending = $handle === null ? null : $this->store->find($handle);

        if ($pending === null) {
            return $this->expiredResponse();
        }

        if ($context->getCustomer() === null) {
            // Stash and hand over to the shop's own login page.
            $request->getSession()->set(self::SESSION_KEY, $handle);

            return $this->redirectToRoute('frontend.account.login.page', [
                'redirectTo' => 'frontend.merchant_quote_agent.authorize',
            ]);
        }

        return $this->renderStorefront(self::TEMPLATE, [
            'expired' => false,
            'agentHost' => PendingAuthorizationPresenter::agentHost($pending),
            'scopes' => PendingAuthorizationPresenter::scopeList($pending),
            'handle' => $handle,
            'expiresInMinutes' => (int) (AgentAuthorizationRegistrar::TTL_SECONDS / 60),
        ]);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Route(path: '/quote-agent/authorize', name: 'frontend.merchant_quote_agent.authorize.grant', methods: ['POST'])]
    public function grant(Request $request, SalesChannelContext $context): Response
    {
        $handle = (string) $request->request->get('request_uri', '');
        $pending = $this->guard->verifiedPending($handle, $context);

        if ($pending === null) {
            return $this->expiredResponse();
        }

        if (!$request->request->getBoolean('grant')) {
            return new RedirectResponse(PendingAuthorizationPresenter::denialUrl($pending));
        }

        $claimed = $this->store->consume($handle);

        if ($claimed === null) {
            return $this->expiredResponse();
        }

        $redirectTo = $this->completer->redirectTarget($claimed, $request->getHost(), $context->getToken());

        return new RedirectResponse($redirectTo);
    }

    private function expiredResponse(): Response
    {
        return $this->renderStorefront(self::TEMPLATE, ['expired' => true]);
    }

    private function handle(Request $request): ?string
    {
        $fromQuery = $request->query->get('request_uri');

        if (\is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }

        // Returning from the shop's login page.
        $session = $request->getSession();
        $stashed = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        return \is_string($stashed) && $stashed !== '' ? $stashed : null;
    }

    public static function agentHost(PendingAuthorization $pending): string
    {
        return PendingAuthorizationPresenter::agentHost($pending);
    }

    /** @return list<string> */
    public static function scopeList(PendingAuthorization $pending): array
    {
        return PendingAuthorizationPresenter::scopeList($pending);
    }

    public static function denialUrl(PendingAuthorization $pending): string
    {
        return PendingAuthorizationPresenter::denialUrl($pending);
    }
}
