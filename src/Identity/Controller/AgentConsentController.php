<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentGrantCompleter;
use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentRequestGuard;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\RequestRuntimeConfigurationReader;
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
 * The handle lives ONLY in the session, never in the request body or a
 * rendered form field, and never in the query string past the first hit.
 * Shopware's storefront forms carry no CSRF protection (removed in 6.5), so a
 * hidden `request_uri` field would let a third-party page induce a signed-in
 * victim into POSTing an attacker-registered handle. An attacker cannot plant
 * a value in a victim's session, so there is nothing for such a forgery to
 * act on. The same session entry also carries the handle across the login
 * redirect, sidestepping Shopware's login/guest-login pages disagreeing on
 * whether `redirectParameters` is an array or a JSON string.
 *
 * The session being the only source is not by itself enough: it can still be
 * overwritten mid-flow by a top-level GET to `?request_uri=` in another tab
 * (`SameSite=lax` permits that), swapping which request a still-displayed
 * page's Allow click would grant. The `formToken` rendered into the page and
 * re-checked in `grant()` (see `authorize()`) closes that: it binds the page
 * to the handle the session held at RENDER time, not at submit time.
 *
 * This is the plugin's only controller that renders a themed storefront page
 * rather than JSON, so — unlike UcpQuoteController, QuoteContractController
 * and AgentAuthorizationRequestController — it deliberately extends
 * StorefrontController instead of standing alone as a plain final class.
 *
 * The sales-channel binding both hops need (see ConsentRequestGuard) lives
 * there rather than here, the Agentic Commerce exchange lives in
 * ConsentGrantCompleter, and the pure presentation logic lives in
 * PendingAuthorizationPresenter — all split out to keep this class under the
 * class-level cyclomatic-complexity gate.
 *
 * Symfony's AbstractController declares `$container` as a typed property with
 * no default and sets it via setContainer(), called by the service
 * registration in services.php — not by this class's constructor. mago.toml
 * carries the analyzer ignore for this (an inline `@mago-expect` cannot
 * suppress it reliably: the diagnostic's primary span is in vendor code, and
 * pragma matching against a foreign-file span was observed to be
 * nondeterministic). The same pattern is accepted for Shopware's own Plugin
 * base class in MerchantQuoteAgentPlugin.php, via a regular @mago-expect
 * there since that diagnostic's span IS in-file.
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
        private readonly RequestRuntimeConfigurationReader $runtimeConfiguration,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Route(
        path: '/quote-agent/authorize',
        name: 'frontend.merchant_quote_agent.authorize',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: ['GET'],
    )]
    public function authorize(Request $request, SalesChannelContext $context): Response
    {
        $handle = $this->handle($request);
        $pending = $handle === '' ? null : $this->store->find($handle);

        if ($pending === null) {
            return $this->expiredResponse($request);
        }

        if ($context->getCustomer() === null) {
            // Hand over to the shop's own login page; the handle is already
            // in the session. Channel binding waits until there is a
            // customer to bind against.
            return $this->redirectToRoute('frontend.account.login.page', [
                'redirectTo' => 'frontend.merchant_quote_agent.authorize',
            ]);
        }

        if (!$this->guard->matchesChannel($pending, $context)) {
            return $this->expiredResponse($request);
        }

        return $this->renderStorefront(self::TEMPLATE, [
            'expired' => false,
            'agentHost' => PendingAuthorizationPresenter::agentHost($pending),
            'scopes' => PendingAuthorizationPresenter::scopeList($pending),
            'expiresInMinutes' => (int) (AgentAuthorizationRegistrar::TTL_SECONDS / 60),
            // Binds the page the customer read to the request their Allow
            // click submits. The handle itself is 256 bits of random_bytes(),
            // so this token cannot be reversed to it and is not a secret — it
            // only has to prove the form was rendered from the same handle
            // the session currently holds. grant() checks the token against
            // the SESSION's current handle, not the token's own origin: a
            // handle swapped into the session by another tab (SameSite=lax
            // permits a top-level GET to do that) makes this page's token
            // stop matching, so an unwitting Allow can no longer grant a
            // different request than the one displayed.
            'formToken' => $this->guard->formToken($handle),
        ]);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    #[Route(path: '/quote-agent/authorize', name: 'frontend.merchant_quote_agent.authorize.grant', methods: ['POST'])]
    public function grant(Request $request, SalesChannelContext $context): Response
    {
        $handle = $this->sessionHandle($request);
        $pending = $this->guard->verifiedPending($handle, $context, $request->request->getString('token'));

        if ($pending === null) {
            return $this->expiredResponse($request);
        }

        // Single-use either way: a denial that left the record live could
        // still be granted later, contradicting the page the customer just read.
        $claimed = $this->store->consume($handle);

        if ($claimed === null) {
            return $this->expiredResponse($request);
        }

        $request->getSession()->remove(self::SESSION_KEY);

        if (!$request->request->getBoolean('grant')) {
            return new RedirectResponse(PendingAuthorizationPresenter::denialUrl($claimed));
        }

        $redirectTo = $this->completer->redirectTarget(
            $claimed,
            $request->getHost(),
            $context->getToken(),
            $this->runtimeConfiguration->forRequest($request),
        );

        // Agentic Commerce refused (see ConsentGrantCompleter). Its checks are
        // the authoritative ones, and the handle is already spent, so the only
        // honest outcome left is the terminal page — never a 500 out of a
        // storefront controller.
        return $redirectTo === null ? $this->expiredResponse($request) : new RedirectResponse($redirectTo);
    }

    /** Clears the stale handle before rendering — every "expired" outcome is terminal. */
    private function expiredResponse(Request $request): Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->renderStorefront(self::TEMPLATE, ['expired' => true]);
    }

    /**
     * The first hit from the agent's authorization_url; stashed for every hop
     * after. An empty string means "no handle" throughout this class.
     */
    private function handle(Request $request): string
    {
        // getString(), as grant() already uses for the form token: a
        // non-scalar `?request_uri[]=` is a malformed request, answered with
        // a 400 rather than silently read as "no handle".
        $fromQuery = $request->query->getString('request_uri');

        if ($fromQuery !== '') {
            $request->getSession()->set(self::SESSION_KEY, $fromQuery);

            return $fromQuery;
        }

        // Returning from the shop's login page, or a later GET on this page.
        return $this->sessionHandle($request);
    }

    /**
     * The session is the ONLY source for the POST handle — never the request
     * body, so a forged cross-site submission has nothing to supply.
     */
    private function sessionHandle(Request $request): string
    {
        $stashed = $request->getSession()->get(self::SESSION_KEY);

        return \is_string($stashed) ? $stashed : '';
    }
}
