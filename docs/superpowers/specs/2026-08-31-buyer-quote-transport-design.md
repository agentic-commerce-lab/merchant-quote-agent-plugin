# Buyer-facing quote transport: retiring the Agentic Commerce fork (issue #9)

**Status:** proposed design, 2026-08-31. Delivers the code move from issue #9 and
the acceptance criterion #9 inherited from #1 (a `/ucp/…` request without a valid
`UCP-Agent` is rejected by the SDK, not by our code).

**In scope:** the buyer-facing UCP quote transport, its application layer, the
buyer-side SwagCommercial gateway, and the identity work needed to turn a
presented bearer token into a customer.

**Out of scope, each with a reason:**

| Left out | Why |
| --- | --- |
| `A2cnActField`, `A2cnMandateProfileContributor` | Deferred with A2A per the PM scope note of 2026-08-27. Both are clean new files and both fit patterns this plugin already has, so they cherry-pick later without rework. |
| Browser consent page (`OAuthConsentController`, Twig, snippets), PKCE hardening, client binding | Identity linking is Agentic Commerce's capability, not ours. Upstream AC already issues tokens; a nicer consent flow belongs in an AC PR. |
| Prerequisite matrix, `QUOTE_MANAGEMENT` gate scripting | Its own issue, linked from #9 and #21. It is documentation plus a setup script, and it blocks #21, not this work. |
| `UcpCapabilityCatalog` / `CapabilityFilteringProfileContributor` changes | Upstream modifications, tracked as #12. The negative-priority contributor already in this plugin is the standing workaround. |
| Admin sales-channel capability toggle (JS) | Upstream modification to AC's admin extension. Our capability is advertised by our own contributor and needs no AC UI. |

## Why

The stack we ship against is SwagCommercial + Agentic Commerce **unmodified from
`main`** + this plugin. The buyer-facing half of `com.shopware.quote` currently
lives on three unreleased branches of `agentic-commerce-rfq-addition`, which
means it ships nowhere: verified on 2026-08-31, tags `v1.0.0`, `1.1.1` and
`1.2.0-alpha1` of that repository contain **zero** files matching "quote".

The consequence is visible on the dev shop today. `com.shopware.quote` is
advertised in `/.well-known/ucp` — our profile contributor adds it
unconditionally — while `/ucp/quotes` returns the storefront's 404 page, because
no route by that name exists in the installed build. We closed half of that gap
by moving the contract documents into this plugin; this spec closes the other
half, the endpoints the contract describes.

## What we verified before designing

Read out of the fork, the SDK sources, `merchant-quote-shop` (Shopware
`dev-trunk`, SwagCommercial 7.13.1, Agentic Commerce 1.2.0) or this repository —
not assumed.

- **The move is additive, not a de-duplication.** This plugin's
  `SwagCommercialQuoteGateway` is merchant-side: it works in an admin `Context`
  (`AgentContext::create()`) through `QuoteSnapshotReader` / `QuoteWriters` and
  has no notion of a credential. The fork's `ShopwareQuoteGateway` is buyer-side:
  every operation calls SwagCommercial's **Store API routes** in the resolved
  customer's `SalesChannelContext`, so contract prices, customer-specific
  features and commercial's own ownership rules apply exactly as they would if
  the customer acted. Neither can be expressed in terms of the other.
- **Upstream AC already has a working OAuth flow.** `ShopwareIdentityLinkingAdapter`
  on AC `main` implements authorize (PKCE S256 required, a logged-in customer
  context token required) and token exchange, and persists access tokens. What it
  lacks is (a) our scope and (b) any read side.
- **AC's supported scopes are a private constant.** `SUPPORTED_SCOPES` on `main`
  is `cart:manage`, `order:read`, `order:manage`; requested scopes are
  intersected against it. The fork simply widened the constant, which is exactly
  the kind of change this issue forbids. So no upstream-issued token can carry
  `com.shopware.quote:manage` today.
- **AC's access-token table is readable and its hash is knowable.**
  `swag_agentic_commerce_ucp_oauth_access_token` holds `token_hash`,
  `refresh_token_hash`, `sales_channel_id` (binary), `client_id`, `subject`,
  `scope`, `expires_at`, `created_at`; the hash is `hash('sha256', $token, true)`.
  Revocation lives on the refresh-token row, which the fork's reader reaches by
  `LEFT JOIN … ON r.token_hash = a.refresh_token_hash`.
- **`ucp-php-sdk/core` has no bearer-token seam at all.** Its `Exception/` and
  `Model/Identity/` trees carry OAuth *models* only. Token verification is
  entirely the shop plugin's business.
- **The SDK's symfony bundle already builds our request context.**
  `RequestContextListener::onKernelRequest()` scopes on
  `str_starts_with($path, '/ucp/')` (excluding `/ucp/mcp`, `/ucp/a2a`,
  `/ucp/embedded`) and sets the built `RequestContext` on the request as the
  `ucp_request_context` attribute. So the controller reads an attribute; we need
  neither AC's internal `SymfonyRequestContextFactory` nor one of our own.
- **The SDK's exception mapping is not what a resource server needs, but it has
  an escape hatch.** `ExceptionListener` maps `ValidationException` → 422,
  `IdempotencyConflictException` → 409, `SignatureException` → 401,
  `OAuthException` → 400, `NegotiationException` → 400,
  `UnsupportedCapabilityException` → 501, `ResourceNotFoundException` → 404,
  `ConfigurationException` → 500 — and any `HttpExceptionInterface` with **that
  exception's own status**. A rejected bearer token must be 401, so the transport
  throws Symfony's `UnauthorizedHttpException` and still gets a UCP-shaped
  envelope.
- **The transport and the application layer sit on different fork branches.**
  `QuoteCapability`, `QuoteRequestValidator`, `QuoteSnapshot`, `QuoteList` and
  `ShopwareQuoteGateway` are on `quote-management` / `feat/a2cn-act-carrier`;
  `UcpQuoteController` (213 lines) exists only on `test/mandate-on-sdk-compat`,
  after the refactor that folded the contract routes into it.
- **`Resources/config/routes.php` is already ours.** Added with the contract
  documents; `Bundle::configureRoutes()` imports `Resources/config/routes*` by
  glob, so the transport is one more `import()` line.

## Module boundaries and the file map

| Lands here | From | Kind |
| --- | --- | --- |
| `src/Ucp/Quote/QuoteCapability.php` | fork `Ucp/Capability/QuoteCapability.php` | **merge** into the existing class — today it only `describe()`s; it gains the six guard-then-delegate methods |
| `src/Ucp/Quote/Controller/UcpQuoteController.php` | `test/mandate-on-sdk-compat` | pick, minus the contract routes (already shipped) and minus the request-context factory |
| `src/Ucp/Quote/{QuoteRequestValidator,QuoteSnapshot,QuoteList}.php` | fork `Ucp/Quote/*` | pick, namespace rename only |
| `src/Bridge/BuyerQuoteGatewayInterface.php`, `src/Bridge/SwagCommercialBuyerQuoteGateway.php` | fork `Ucp/Gateway/ShopwareQuoteGateway.php` (423 lines) | pick, **relocated into `Bridge`**; A2CN act parameters dropped from every signature |
| `src/Bridge/SalesChannelContextResolver.php` | fork's version is a modified upstream file | **rewrite**, ~90 lines |
| `src/Identity/{AgentCustomerCredential,AgentCustomerAuthenticator,OAuthAccessTokenInfo}.php`, `src/Identity/AccessTokenSubjectReaderInterface.php` | fork `Ucp/Identity/*`, consent classes excluded | pick, minus the context-token path |
| `src/Identity/AcOAuthAccessTokenReader.php` | new | the one class that knows AC's schema |
| — | fork `Ucp/Quote/QuoteBackendFeature.php` | **not moved**: `Bridge/Commercial/CommercialAvailability` already owns that question |

Boundaries this sets:

- **`Bridge`** remains the only module that touches SwagCommercial, now with two
  ports: the existing merchant-side one (admin context, driven by the servicing
  loop) and the new buyer-side one (customer sales-channel context, driven by the
  transport). Both gate on `CommercialAvailability`, so a shop without the
  commercial backend keeps compiling and the capability degrades to unsupported.
- **`Identity`** is new, top-level, and deliberately tiny: turn a presented
  bearer token into "this customer, in this sales channel", or nothing. It knows
  nothing about quotes and must stay that way — a second capability would reuse
  it unchanged.
- **`Ucp`** keeps its present job (descriptor, profile contributor, contract
  documents) and gains the transport plus the capability's operations. It depends
  on `Bridge` and `Identity`; neither depends back.
- `composer.json` gains `ucp-php-sdk/symfony-bundle` (the response factory) and
  `symfony/http-kernel` (`UnauthorizedHttpException`). Both are already installed
  in the shop via AC; declaring them is what `quality:depcheck` demands.

## Identity: from bearer token to customer

Four collaborators, one direction of flow:

1. **`AgentCustomerCredential`** — value object over the presented credential.
   Bearer only: `fromAccessToken()` is the sole named constructor. The fork kept
   an `sw-context-token` fallback for embedded checkout; the contract we publish
   says an unscoped Shopware context token is not accepted, so the second path
   and the branching it forced are dropped rather than moved.
2. **`AccessTokenSubjectReaderInterface`** — our port.
   `find(string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo`,
   returning null for unknown, expired, revoked, or wrong-sales-channel tokens.
   Nothing above this line knows where tokens are stored.
3. **`AcOAuthAccessTokenReader`** — the only implementation, and the only place
   in the plugin that knows AC's schema: one `SELECT` against
   `swag_agentic_commerce_ucp_oauth_access_token`, `LEFT JOIN`ed to the
   refresh-token row for `revoked_at`, keyed by `hash('sha256', $token, true)`
   and the sales-channel id. When issue #13 lands upstream, this class becomes a
   one-line delegate to AC's own `AccessTokenReaderInterface` and the port stays.
4. **`AgentCustomerAuthenticator`** — resolves the token's `subject` to a
   customer and materialises the customer's `SalesChannelContext` on a fresh
   context token via `SalesChannelContextResolver::resolveForCustomer()`, so
   contract prices and rules apply as they would for the customer.

Scope is **carried but not enforced** for V1: `OAuthAccessTokenInfo` keeps the
token's scope list, and the authenticator's `?string $requiredScope` parameter
exists and is passed `null` by every caller. It cannot be enforced until AC can
issue `com.shopware.quote:manage`, which is a fourth upstream ask, sibling to
#12 and #13. Authorization for V1 is therefore ownership-based, which is where
the real boundary sits anyway: the token's subject names the customer, and
SwagCommercial's Store API routes filter by customer and sales channel
themselves, so a foreign quote id is not found rather than forbidden.

## Bridge: the buyer-side port

`BuyerQuoteGatewayInterface` mirrors the six buyer operations —
`requestQuote`, `getQuote`, `listQuotes`, `counterQuote`, `acceptQuote`,
`declineQuote` — plus `isAvailable()`. Each takes the resolved
`SalesChannelContext` rather than a credential: authentication happens in the
transport, and the gateway's job is Shopware, not identity. This is the one
signature change from the fork's version, and it is what keeps `Bridge` free of
any `Identity` dependency.

`SwagCommercialBuyerQuoteGateway` keeps the fork's structure: SwagCommercial's Store API
routes typed as `?object` and wired `nullOnInvalid()`, `CartService` +
`LineItemFactoryRegistry` for the two-step request flow (line items become a
draft quote, then `customer_send` moves it to `open`), the `quote` repository for
listing, and `customerSpecificFeatureService` for the `QUOTE_MANAGEMENT` gate.
`MAX_LIST_LIMIT = 50` stays: an agent must not be able to ask for the whole
table.

The `QUOTE_MANAGEMENT` gate deserves its own error. A customer without
`{"QUOTE_MANAGEMENT": true}` in `customer_specific_features` gets a 403 out of
SwagCommercial that says nothing useful; the gateway catches it and rethrows as a
`ValidationException` naming the feature flag, so the agent — and whoever reads
the log — sees the actual cause. This is the trap the prerequisite-matrix issue
exists to automate away.

## Transport

Six routes on `UcpQuoteController`, exactly the paths the published OpenAPI
document describes:

| Method | Path | Route name |
| --- | --- | --- |
| POST | `/ucp/quotes` | `frontend.merchant_quote_agent.quote.request` |
| GET | `/ucp/quotes` | `…quote.list` |
| GET | `/ucp/quotes/{id}` | `…quote.get` |
| POST | `/ucp/quotes/{id}/counter` | `…quote.counter` |
| POST | `/ucp/quotes/{id}/accept` | `…quote.accept` |
| POST | `/ucp/quotes/{id}/decline` | `…quote.decline` |

Route names carry our own prefix rather than the fork's `frontend.ucp.quote.*`,
so a shop that also installs a fork build gets a visible collision rather than a
silent override. Storefront route scope, written as the `'storefront'` literal
for the reason the contract controller already documents. The import in
`routes.php` is gated on `CommercialAvailability`, mirroring the service-graph
gate, so a shop without SwagCommercial has no dead routes.

Per request: read `ucp_request_context` off the request (absent means the
request never passed the SDK listener — a configuration error, so
`ConfigurationException` → 500), extract the bearer credential, authenticate,
delegate to `QuoteCapability`, and return `UcpResponseFactory::success()` with
the operation name. Everything the `/ucp/` prefix already gives us — the
`UCP-Agent` requirement, agent profile fetching, signature policy, idempotency
keys, UCP error envelopes — is inherited, not re-implemented. That inheritance
is the acceptance criterion this issue took over from #1.

Error mapping, all of it by throwing:

| Cause | Thrown | Status |
| --- | --- | --- |
| No/malformed `Authorization` header, unknown, expired or revoked token | `UnauthorizedHttpException('Bearer')` | 401 |
| Malformed body, unknown line item, `QUOTE_MANAGEMENT` not enabled | `ValidationException` | 422 |
| Unknown or foreign quote id | `ResourceNotFoundException` | 404 |
| Commercial backend absent or unlicensed | `UnsupportedCapabilityException` | 501 |
| Missing request context | `ConfigurationException` | 500 |

Unknown and foreign quote ids deliberately share a response: the contract
already promises not to confirm existence.

## Testing

- **Unit.** The fork's `QuoteCapabilityTest` (474 lines on the a2cn branch) and
  `QuoteRequestValidatorTest` come along with the code they cover. New unit tests
  for `AgentCustomerAuthenticator` (unknown / expired / revoked / wrong sales
  channel / no customer) against an in-memory reader, and for the controller's
  credential extraction and error mapping.
- **Integration, against `merchant-quote-shop`.** The suite already boots a real
  shop with AC 1.2.0, so the whole path is testable end to end: issue a token
  through AC's own authorize + token endpoints for a seeded customer, then drive
  request → get → counter → accept over HTTP. One test per buyer operation, plus:
  - `/ucp/quotes` without `UCP-Agent` is rejected **by the SDK** — assert the
    response shape is the SDK's envelope, not ours (the #1 criterion).
  - A token belonging to customer A cannot read customer B's quote (404).
  - A customer without `QUOTE_MANAGEMENT` gets the 422 that names the flag.
  - `AcOAuthAccessTokenReader` finds a token AC itself issued. This is the
    canary for the schema coupling: it fails the day AC renames the table, the
    columns or the hash.
- **Route registration.** `debug:router` in the shop must list all six routes,
  and the discovery document's advertised capability must stay consistent with
  what is routed.

## Debt this deliberately takes on

| Debt | Retired by |
| --- | --- |
| `AcOAuthAccessTokenReader` reads another plugin's table and replicates its hash | #13 upstream: the class becomes a delegate |
| Scope carried but never enforced | #46 upstream: make AC's supported-scope list extensible |
| Our profile contributor re-adds the descriptor the AC filter strips | #12 upstream |
| Route paths duplicated between our controller and any fork build | the fork going away, which is this issue |
| The buyer path uses `/ucp/quotes` REST routes instead of the SDK's operation dispatch | an SDK operation registry for vendor capabilities; the fork's own comment says the same |
