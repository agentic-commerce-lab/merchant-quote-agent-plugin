# Agent access controls: editable allowlists and a CLI-only allow-any-agent switch

**Status:** proposed design, 2026-09-01.

**The problem in one sentence:** a merchant cannot see or change which agents their shop trusts, and a developer cannot try a new agent out without editing shop configuration in several places.

**In scope:** an allowlist editor in this plugin's Administration page, a console-only per-sales-channel switch that admits whichever agent a request presents, and the two runtime hooks that make the switch effective. Agentic Commerce stays **untouched**.

**Out of scope, with reasons:**

| Left out | Why |
| --- | --- |
| Any change to the Agentic Commerce plugin | The premise of this design. An upstream PR existed (`agentic-commerce-rfq-addition#6`, CI green) and was deliberately closed in favour of hooking in from here; its branch `feat/ucp-allowlist-admin` remains on that remote if this turns out to be the wrong call. |
| A switch in this plugin's own settings form | Deliberate: a toggle that removes the merchant's say over which agents may transact does not belong on a settings screen a merchant can reach. It is console-only, which also means it must **not** be added to `config.xml`, since that file renders our plugin's config form. |
| Injecting fields into Agentic Commerce's sales-channel tab | Would couple us to its Vue component names and template blocks, which are internal and unversioned. We use its JSON API instead. |
| Changing `signaturePolicy` anywhere | It governs whether a fetched profile's signature must verify, which is a separate decision from who may reach the shop. Recorded below as a finding, not changed here. |

## Why

Trying an agent out today means naming its host in three sales-channel allowlists plus one installation-wide list, none of which is editable in any Administration screen. The consequence is not theoretical: on the dev shop those three lists held a single dead ngrok hostname, so **every** `/ucp/*` request was rejected before any profile fetch — including Agentic Commerce's own routes — and nothing in the Administration showed why.

The Agentic Commerce fork solved this with an `allowAnyAgent` switch (`46783f7`, July 2026) that never reached `main`, which is also why a config row carrying that key made the installed 1.2.0 throw on every read.

## What we verified before designing

Read out of the SDK sources, Agentic Commerce `main`, and this repository — not assumed.

- **The fetch is never skipped, and allowlisting is not an alternative to it.** `DefaultHttpRequestContextFactory::create()` runs `assertSafeProfileUri()` (the allowlist check) and then **unconditionally** `agentProfileFetcher->fetch($profileUri)`, taking the signing keys from the fetched profile to verify the request signature. So an allowlisted agent still has to publish a fetchable, signed profile: the allowlists are a pre-filter on which hosts the shop will make outbound requests to, i.e. an SSRF and abuse control, not an authenticity control.
- **Authenticity is governed by `signaturePolicy`, not by the allowlists.** Verification is enforced only under `strict`; under `log` an unverified signature is logged and the request proceeds. The dev shop is on `log`.
- **`profileFetchingDevelopmentMode` relaxes which URIs are acceptable** (plain http, local hosts) rather than whether a fetch happens. It is off on the dev shop. Separately, Agentic Commerce's `TestAgentProfileFetcherCompilerPass` swaps the fetcher for a static double in the test environment only.
- **The per-channel gates are reachable by decoration.** `main`'s `services.php:362` aliases `RuntimeConfigurationResolverInterface` to `ShopwareRuntimeConfigurationResolver`, and `DefaultHttpRequestContextFactory:41` takes its gate values from whatever that resolver returns. Decorating an interface another plugin aliases is a pattern Agentic Commerce itself uses (`AgentProfileFetcherInterface`).
- **The installation-wide gate can only be replaced, not decorated.** `Ucp\Sdk\Internal\Service\UrlSafetyValidator` is `final` and injected concretely into `HttpAgentProfileFetcher`; the SDK bundle defines it from `ucp_sdk.allowed_profile_hosts` (`UcpSdkExtension:266`). The fork's switch replaced that definition with a per-request factory for exactly this reason.
- **Agentic Commerce already exposes a config API.** `GET` and `PUT /api/_admin/ucp/sales-channels/{salesChannelId}/config`, ACL `ucp.viewer` / `ucp.editor`, with the `PUT` going through `UcpConfigService::saveConfig()` — which merges the posted payload over stored config, so a key present in the payload wins and absent keys keep their stored value.
- **Its config model rejects unknown keys.** `UcpConfig::CONFIG_KEYS` has eighteen entries and `IGNORED_LEGACY_KEYS` two; anything else throws in `fromJson()`. Our switch therefore cannot live in that row and must be stored by us.
- **This plugin's config reading is already sales-channel scoped.** `QuoteAgentSettingsSource::forSalesChannel(?string $salesChannelId)`.
- **`46783f7` contains two self-contained classes** that solve the runtime half — `AgentProfileHostValidatorFactory` (85 lines) and `UcpAgentHeader` (60 lines) — written by this team, portable rather than rewritten.

## Components

### 1. The allowlist editor (Administration)

Its own route and navigation entry in this plugin's Administration module, not a section on the existing admin page — that page's `index`/`detail` routes are audit views over decision records, gated by this plugin's own `merchant_quote_agent.viewer` privilege, and this editor is a different screen entirely. It lists sales channels from `GET /api/_admin/ucp/sales-channels`, loads a channel's config with `GET …/{id}/config`, and saves the three lists — `platformAllowlist`, `remoteProfileAllowlist`, `agentAllowlist` — with `PUT …/{id}/config`. One host per line; parsing splits on newlines and commas, trims, drops blanks and de-duplicates while keeping the typed order, and otherwise leaves entries alone so Agentic Commerce's own `hostList()` validation reports the offending host by path.

Because the `PUT` merges over stored config, the payload carries only the keys we edit, so console-set fields we do not show are preserved. The editor writes no other key.

Both the route and the `PUT` are gated by Agentic Commerce's own ACLs, not a privilege of ours: `ucp.viewer` to load the page at all, `ucp.editor` to save. A user without `ucp.editor` receives Agentic Commerce's `403`, surfaced verbatim rather than reported as success. The README states that both ACLs are needed.

### 2. The switch (console only)

`bin/console merchant-quote-agent:allow-any-agent` — the plugin's first console command, so it also introduces `src/Command/`. With no arguments it prints the flag's state for every sales channel, so "is this still on somewhere?" has an answer. With a sales-channel id and `--on` or `--off` it sets that channel's flag, and switching on prints a warning naming the channel.

State lives in `system_config` under `MerchantQuoteAgentPlugin.config.allowAnyAgent`, written directly through `SystemConfigService`, which accepts keys without a `config.xml` entry — and deliberately has none, so the flag never appears in our settings form. A small dedicated reader (`AgentAccessFlags`) reads it per channel, defaulting to off; it does not go into `QuoteAgentSettings`, which is about negotiation and should not grow a security flag.

### 3. The per-channel runtime hook

A decorator over `RuntimeConfigurationResolverInterface` (`AgentAdmittingRuntimeConfigurationResolver`). It calls the inner resolver, resolves the sales channel from the request host with this plugin's `SalesChannelContextResolver`, and — when the flag is on for that channel — returns a `RuntimeConfiguration` whose `allowedProfileHosts` and `allowedAgentDomains` also contain the profile host presented in the request's `UCP-Agent` header, parsed by the ported `UcpAgentHeader`. With the flag off, or with no usable header, it returns the inner result untouched.

### 4. The installation-wide runtime hook

Our own factory registered against the SDK's `UrlSafetyValidator` service id, ported from `46783f7`'s `AgentProfileHostValidatorFactory` and keeping that name. With the flag off, or with no request in scope, it builds exactly what the bundle would have built from `ucp_sdk.allowed_profile_hosts` and `profile_fetching_development_mode`. With the flag on, it adds the presented host to that list and nothing else.

Every other protection is untouched in both hooks: https only, ports 443/8443, no redirects, no private or link-local addresses, blocked metadata hosts, and an empty list still denying everything — the SDK has no wildcard. The flag adds a host; it never switches a check off.

## Known limitations

**On a long-running worker (FrankenPHP, RoadRunner) the installation-wide hook only widens for the first request that worker serves, then silently stops.** The SDK's `RequestContextListener` is a kernel event listener the container builds once per process. It holds `HttpRequestContextFactoryInterface` (`DefaultHttpRequestContextFactory`), which holds `AgentProfileFetcherInterface` (`HttpAgentProfileFetcher`), which holds the `UrlSafetyValidator` our factory builds — every one of those a shared service by the SDK bundle's own definitions (`UcpSdkExtension`, no `setShared(false)` anywhere in that chain). `HttpAgentProfileFetcher` therefore receives whichever validator our factory built for the first request resolved on that worker and keeps it for the container's lifetime, regardless of what later requests present. Shopware ships FrankenPHP as a supported runtime, so this is not a hypothetical; under php-fpm, one request per process, it is invisible.

The failure is silent in both directions: an operator can turn the switch on, test once, see it work, and have every later request refused; or test only after the worker has already served a request, conclude the switch is broken, and start widening the permanent allowlists instead — which is exactly what this feature exists to prevent.

Recorded so the fix is not re-derived and re-rejected: making `DefaultHttpRequestContextFactory` and `HttpAgentProfileFetcher` non-shared is about six lines and duplicates no constructor arguments, but buys nothing on its own while `RequestContextListener` above them is still shared — the listener is what the kernel actually holds. `HttpAgentProfileFetcher`'s cache is a `PlatformProfileCacheRepositoryInterface`, not in-memory state, so non-sharing it would have been safe if sharing were the whole problem, but it is not: Agentic Commerce's own decorator on `AgentProfileFetcherInterface` is a fourth shared holder in the same chain. The per-sales-channel hook (component 3) is unaffected — `AgentAdmittingRuntimeConfigurationResolver::resolve()` takes the request as an argument and reads its header fresh every call, caching nothing.

The console command's `--on` output warns about this directly, since that is the one place the person turning the switch on is guaranteed to read it. The README documents it under "Deciding which agents may transact".

## Risks and debt this takes on

| Risk | Mitigation |
| --- | --- |
| **Last definition wins on `UrlSafetyValidator`.** A third plugin redefining a `final` SDK-internal service means that if Agentic Commerce later ships its own switch, one of the two silently stops applying. | A behavioural integration test: with the flag on, the container-resolved validator accepts the presented host; with it off, it rejects. A clash fails that test loudly instead of degrading quietly. |
| **Three new couplings to Agentic Commerce** — its config API shape, its resolver alias, and its config-row key set — joining the two this plugin already carries (the OAuth token reader, #13, and the capability-filter workaround, #12). | Each is exercised by a test that fails if the other side moves: the editor by an integration test against the live API, the alias by the decorator test, the key set by the editor posting only keys we edit. |
| **A merchant can widen an SSRF-relevant list from the Administration.** | Validation is unchanged — Agentic Commerce normalizes and rejects malformed hosts, and an empty list denies everything. The switch, which is the blunt instrument, stays console-only. |
| **The dev shop runs `signaturePolicy: log`,** so a fetched profile's signature does not have to verify. | Out of scope here and recorded as a finding: it is the setting that actually governs agent authenticity, and worth a separate decision. |

## Testing

- **Unit:** the flag reader (per channel, default off); the decorator (flag on and off, absent header, malformed header, inner result untouched); `UcpAgentHeader` parsing.
- **Integration:** the container-resolved `UrlSafetyValidator` accepting the presented host with the flag on and rejecting it with the flag off — which doubles as the clash guard.
- **Administration:** none of the above two bullets' automated coverage extends to the editor. This plugin has no Administration test harness (issue #39), so there is no unit test for the editor's list-parsing helper and no integration test for its round-trip against the live shop's config API — that merge behaviour belongs to Agentic Commerce and is tested there. The editor's coverage is a manual pass only: add a host and confirm `bin/console ucp:config:show --sales-channel=<id>` reflects it. If the harness in issue #39 lands, the editor's `fromText` parsing and the 403 path are the first things worth covering.
- The suite must stay green at its current state: 354 unit tests, and the integration suites this plugin owns.
