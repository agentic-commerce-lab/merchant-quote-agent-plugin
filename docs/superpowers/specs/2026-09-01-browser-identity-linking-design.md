# Browser identity linking: storefront login, consent, and a redirect back to the agent

**Status:** proposed design, 2026-09-01.

**The problem in one sentence:** an agent cannot obtain an OAuth token for a customer, because the shop has no page a human can visit to grant one.

**In scope:** a signed authorization-request endpoint for the agent, a storefront route that authenticates the customer through the shop's own login and asks for consent, the redirect back to the agent with an authorization code, and a console command to list and revoke grants. Agentic Commerce stays **untouched**.

**Out of scope, with reasons:**

| Left out | Why |
| --- | --- |
| Any change to Agentic Commerce | Decided: build here, prove it, then lift it upstream. The identity-linking capability belongs in AC, not in a quote plugin — the seams below exist so the move is a port rather than a rewrite. A prior upstream attempt on the neighbouring allowlist topic (`agentic-commerce-rfq-addition#6`) was closed on the same reasoning. |
| Changing the token endpoint | It already works. `ShopwareIdentityLinkingAdapter::issueToken()` validates client id, redirect URI, sales channel and PKCE against the stored authorization, and the agent's token POST is signed, so `assertClientId()` passes there without help. |
| Reimplementing the client/redirect binding rules | `authorize()` runs `assertClientId()` and `assertRedirectUri()` itself against the context we hand it. AC stays the single source of truth; we only mirror the checks at request time for a readable error. |
| Widening AC's OAuth scope catalogue | Decided. Quote authorization stays ownership-by-token-subject, as `AgentCustomerAuthenticator`'s docblock already documents. Filed upstream as **#46**; closing it is that issue's job, not this one's. |
| A storefront "connected agents" page | Decided. Revocation must be *possible*, not self-service, until a merchant asks. A console command covers the operational need at a fraction of the surface. |
| Changing `signaturePolicy` | Not ours to move. But this design **reads** it, and the reason is the security boundary below. |

## Why

An agent that wants to negotiate for a customer needs an access token issued by the shop. Today it cannot get one, and not for the reason it first appears.

`GET /ucp/v1/oauth/authorize` returns a JSON envelope rather than a redirect — `OAuthController.php:39-46` calls `responseFactory->success()` on the adapter's array `{client_id, code, state, subject, redirect_to}`. It computes `redirect_to` and never uses it. That looks like the whole problem, and it is not.

The wall is `OAuthClientBindingValidator::assertClientId()`, which requires all of: `client_id` is an `https` URI; `$context->platformProfileUri` and `$context->platformProfile` are both non-null; the request signature verified; and `$context->platformProfileUri === $clientId` exactly. A browser sends neither a `UCP-Agent` header nor an RFC 9421 signature, so **no browser request can ever satisfy this**. Adding a `302` to the existing endpoint would not help.

`authorize()` additionally requires a logged-in customer's `sw-context-token` (`contextToken()`, ~line 226) and throws when the resolved context carries no customer. Nothing in the shop renders a consent screen: neither released AC 1.2.0 nor the unreleased fork at `~/projects/agentic-commerce` has a storefront controller for it — both ship only `Storefront/Robots/ReferringSalesChannelRobotsSubscriber.php`.

The insight this design turns on: **the browser hop does not need to authenticate the agent, because the agent can be authenticated one step earlier.** Split the flow in two — a signed machine request that registers the intent, and an unsigned browser visit that carries only an opaque handle to it.

## What we verified before designing

Read out of the SDK sources, Agentic Commerce as installed on the dev shop, and this repository — not assumed. Confirmed live against `agenticquote-shoelscher.eu-core-1.shopdev.de`.

- **The capability is injectable.** `SwagAgenticCommerce/src/Resources/config/services.php:251` aliases `IdentityLinkingCapabilityInterface` to `IdentityLinkingCapability`. Depending on an interface another plugin aliases is an established pattern here — the neighbouring access-controls design relies on the same property of `RuntimeConfigurationResolverInterface`.
- **A request context can be constructed, not only received.** `Ucp\Sdk\Model\RequestContext` is a `final` class whose every field is a public readonly constructor parameter, including `headers`, `platformProfileUri`, `platformProfile`, `signatureVerified` and `runtimeConfiguration`. There is no factory to go through and no private state.
- **The token exchange needs nothing from us.** `issueToken()`'s authorization-code branch consumes the stored authorization and compares `clientId`, `redirectUri` and `salesChannelId` with `hash_equals`, then verifies PKCE. All four come from what `authorize()` stored, so a code minted through AC's own `authorize()` is accepted unchanged.
- **The routes under `/ucp/` already receive a verified context.** This plugin's `UcpQuoteController` reads `$request->attributes->get('ucp_request_context')`, built by the SDK's `RequestContextListener` for everything under the prefix. `DefaultHttpRequestContextFactory::create()` runs the allowlist check and then **unconditionally** fetches the agent's profile, taking the signing keys from it to verify the request signature.
- **Verification is conditional on policy, and that is the danger.** The signature is *enforced* only under `signaturePolicy: strict`; under `log` an unverified signature is logged and the request proceeds. The shared dev shop is on `strict` (read twice via the admin API); the local `merchant-quote-shop` is on `log`. So "the request reached a `/ucp/` route" is **not** proof that the signature verified.
- **A signed GET with a query string fails unless the client signs Symfony's normalised URI.** `Rfc9421RequestSignatureService::signatureBase()` builds `"@target-uri"` from `$request->absoluteUri`, which the bundle fills from Symfony's `$request->getUri()`; Symfony's `normalizeQueryString()` runs `HeaderUtils::parseQuery` → `ksort` → `http_build_query(..., PHP_QUERY_RFC3986)`. Mismatched parameter order or percent-encoding yields a bare "Request signature verification failed". This is why the machine-side endpoint below is a **POST with a JSON body and no query string**.
- **Two independent host allowlists gate every external agent profile, and empty denies.** Per sales channel via `UcpConfig::toRuntimeConfiguration()` (`remoteProfileAllowlist ?: platformAllowlist ?: [shop host]`, same for `agentAllowlist`), checked in `DefaultHttpRequestContextFactory::assertSafeProfileUri()`; and installation-wide via `ucp_sdk.allowed_profile_hosts`, read by the `final` `UrlSafetyValidator`, which AC never sets so it defaults to `[]`. Both are suffix-matched. **This design changes neither** — the concurrent access-controls work owns them, and its factory adds to the configured list rather than replacing it.
- **`identity_linking` is off by default and has no admin checkbox.** `UcpCapabilityCatalog::defaultConfigKeys()` omits it, and the administration lists it in `NOT_READY_CAPABILITIES` in `ucp-capabilities.js` while the sales-channel component imports only `READY_CAPABILITIES` — so the "Not yet available" group that file's own comment describes renders nowhere. It must be enabled through `PUT /api/_admin/ucp/sales-channels/{id}/config` (note `PUT`, not `POST`).
- **No quote scope can exist in a token.** `scopes_supported` is `[dev.ucp.shopping.cart:manage, dev.ucp.shopping.order:read, dev.ucp.shopping.order:manage]`; AC intersects requested scopes against its own private catalogue. `AgentCustomerAuthenticator`'s `$requiredScope` argument is wired and every caller passes `null` for exactly this reason.

## The security boundary

Everything else in this document is plumbing. This is the design.

The browser hop carries no signature, and at consent time we hand AC a `RequestContext` we built ourselves, with `signatureVerified: true`. That claim is truthful **only** if the agent really was verified earlier. So:

> A pending authorization record is written **only** from a request where `$context->signatureVerified === true`, `$context->platformProfileUri !== null`, and `$context->platformProfileUri === $clientId`.

We check `signatureVerified` **ourselves**. We do not infer it from having been routed under `/ucp/`. Under `signaturePolicy: log` the SDK proceeds on an unverified signature, so without our own check a `log`-policy shop would let an unverified agent register a request that the browser hop later launders into a context stamped verified. The local dev shop runs `log` today, which makes this a live hazard rather than a theoretical one.

Supporting rules, none of them sufficient alone:

- The handle is 32 bytes from a CSPRNG, single-use, and expires in 10 minutes. It is looked up by hash, never logged, and never appears in a template.
- Consuming a record is a conditional write (`consumed_at IS NULL`), so two concurrent submissions cannot both mint a code.
- `code_challenge_method` must be `S256`. A missing or `plain` challenge is refused at registration rather than at consent.
- The stored `redirect_uri` is the only redirect target. The browser cannot supply, extend, or override it.
- An expired or consumed handle renders an error page and never redirects, so a stale link cannot be turned into a redirector.
- **The sales channel is bound across both hops.** The record stores the sales channel resolved at registration, and consent refuses unless the channel resolved for the browser request is the same one. Without this, a customer signed in on one sales channel could grant a record registered against another: `authorize()` compares the customer's channel against the one it resolves from the context *we* built, so it would compare the browser's channel with itself and find no fault. Consequently `authorization_url` is built on the registering channel's own domain, via AC's `SalesChannelDomainResolver`, rather than on whatever host the storefront happens to answer on.

## Components

### 1. Registering the intent — `POST /ucp/quote-agent/authorization-requests`

Under `/ucp/`, so the SDK listener has already required `UCP-Agent`, fetched the agent's profile and attempted verification. A JSON body, deliberately no query string (see the normalisation finding above).

Body: `client_id`, `redirect_uri`, `scope`, `code_challenge`, `code_challenge_method`, `state`. The boundary rule above is applied first. Then a fail-fast mirror of AC's client and redirect checks — for a readable error only; the authoritative run happens inside `authorize()` at step 3.

Persists one row and returns `{request_uri, expires_in, authorization_url}`, where `authorization_url` is the storefront URL to put in front of the human.

### 2. Authenticating the human — `GET /quote-agent/authorize?request_uri=…`

Storefront scope. Loads the record; expired, consumed or unknown renders an error page and stops.

With no logged-in customer it redirects to Shopware's **own** login — `/account/login` with `redirectTo` and `redirectParameters` naming this route and the handle — so the customer sees the real themed shop login, with the shop's own registration and password reset. Nothing about authentication is reimplemented here.

With a customer present it renders a consent page: a Twig template extending the storefront layout, naming the agent's profile **host** (not the full URI, which is long and carries a cache-busting query), the requested scopes in plain words, what the grant permits, and when it expires.

### 3. Recording the grant — `POST /quote-agent/authorize`

**not** CSRF-protected by the framework: Shopware removed CSRF tokens in 6.5, so nothing in `shopware/core` or `shopware/storefront` guards this POST. A cross-site submission is stopped only by `cookie_samesite: lax` (`framework.yaml:22`), which is a shop-overridable default — a shop setting `none` reopens silent CSRF, and nothing in code asserts that dependency.

The form carries `hash('sha256', $handle)` and the handler requires it to match the hash of the session's *current* handle. That is **not** a CSRF defence and must not be read as one: the token mixes in no secret and no session material, so an agent that registered a handle can compute the token for it. What it does is bind the rendered page to the request its Allow authorises — without it, a top-level navigation carrying `?request_uri=` in another tab rewrites the session while the page still names the original agent, so informed consent for one agent authorises another.

Re-loads and re-validates the record, then builds the `RequestContext`: `host` from the request; `headers` carrying the logged-in customer's `sw-context-token`; `platformProfileUri` and `platformProfile` replayed from the record; `signatureVerified: true`; `runtimeConfiguration` from AC's resolver.

Calls `IdentityLinkingCapabilityInterface::authorize()`. AC re-checks the client binding, resolves the customer, mints a real authorization code through its own store, and returns `redirect_to` already carrying `code`, `state` and `iss`. We mark the record consumed and `302` there.

Denial redirects to the stored `redirect_uri` with `error=access_denied` and the original `state`, per OAuth.

### 4. Revoking — `bin/console merchant-quote-agent:agent-grants`

With no arguments, lists grants per customer: client id, scope, issued and expiry. With a customer and a client id, revokes by setting `revoked_at` on AC's refresh-token row, which `AcOAuthAccessTokenReader` already honours on every token read.

This is a fifth direct read of AC's OAuth tables. It goes behind the same interface as the existing one so #13's upstream fix retires both together.

### 5. Storage

One table, `merchant_quote_agent_pending_authorization`, one migration: handle hash (primary), sales channel id, client id, agent profile JSON, redirect URI, scope, code challenge and method, state, `created_at`, `expires_at`, `consumed_at`. Rows are short-lived; consumed and expired rows are deleted opportunistically on write rather than by a scheduled task.

## Seams for upstreaming

The exit is a port, not a rewrite:

- `PendingAuthorizationStoreInterface` with a DBAL implementation; the interface mentions nothing about quotes.
- An `AgentAuthorizationRequest` DTO and an `AgentAuthorizationContextFactory` that owns building the synthesized `RequestContext` — the one place the boundary rule is enforced, so it can be reviewed in isolation and moved intact.
- Controllers speak `dev.ucp.common.identity_linking` vocabulary only.

The route prefix is the single vendor-specific thing. Upstream, step 1 becomes `/ucp/v1/oauth/par` and AC's existing `/ucp/v1/oauth/authorize` grows the browser branch, at which point `assertClientId()` can accept a verified pending record instead of demanding a signature on the browser hop.

## Testing

Unit tests own the rules; the integration suite owns the wiring.

- The boundary rule, both directions: a context with `signatureVerified: false` is refused; one with a mismatched `platformProfileUri` is refused. **This is the test that must never be deleted.**
- Handle lifecycle: expiry, single use, and a concurrent double-consume yielding exactly one code.
- Sales-channel binding: a record registered against one channel cannot be consented to from another.
- `S256` enforcement, and `plain`/absent challenges refused at registration.
- Context construction: the synthesized context carries the replayed profile and the customer's context token.
- Integration, on a booted shop: register → consent → code → token → an authenticated quote call, reusing `tests/Integration/IntegrationTestCase.php` and AC's `TestAgentProfileFetcherCompilerPass` double for the profile fetch.

## Consequence for the test client

`scripts/ucp-quote-agent.py` currently serves its own sign-in page on its tunnel because no shop page exists — consent rendered by the client, which is backwards. Once this lands it goes back to opening a URL and waiting on `/callback`, losing roughly eighty lines, and the credentials never leave the shop's own login form.

## Risks and debt this takes on

| Risk | Mitigation |
| --- | --- |
| **A synthesized `signatureVerified: true` is a forgeable claim if the boundary rule is ever weakened.** A future edit that trusts "we are under `/ucp/`" instead of reading the flag reintroduces it silently, and on a `log`-policy shop it is exploitable. | The rule lives in one factory, not spread across controllers, with a named test per direction. The spec states the `log` hazard explicitly so a reviewer knows why the check looks redundant. |
| **A fourth coupling to Agentic Commerce internals**, joining the capability filter (#12), the OAuth token reader (#13), and the concurrent access-controls work. | Each is exercised by a test that fails if the other side moves. The seams above mean this one retires by being moved upstream rather than by being deleted. |
| **A generic identity-linking flow living in a quote plugin.** Wrong home, accepted deliberately. | The seams, and this paragraph, so the next reader knows it was a decision and not an accident. |
| **`identity_linking` disabled or the profile host un-allowlisted turns every attempt into an opaque failure** — three separate gates with similar-sounding errors. | Step 1 fails with a message naming which gate refused and how to change it. The allowlist gates themselves belong to the concurrent access-controls work. |
| **AC could ship its own browser flow**, leaving two implementations of the same route shape. | The prefix is vendor-namespaced, so the routes cannot collide. If AC ships one, ours is deleted rather than reconciled. |
