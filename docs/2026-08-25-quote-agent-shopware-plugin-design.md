# Quote Agent as a Shopware Plugin — design spec

*2026-08-25 — design spec. Status: proposed. Scope: move the merchant quote agent from a*
*Shopware App with an app-server we host to a Shopware plugin the merchant installs, so no*
*quote data and no shop credentials leave the merchant's infrastructure. Retires the*
*TypeScript quote path; keeps the sales-agent harness for the interactive/UCP surface.*

*Historical record. What was built is documented in [`end-to-end.md`](end-to-end.md);*
*this is the reasoning that got there, kept as written.*

Related documents — `agent-led-negotiation-gaps.md` (the gap list this design
deletes or inherits), `FINDINGS.md` (platform capability findings),
`aws-deployment.md` (the deployment being retired) and
`2026-08-10-harness-extension-system-design.md` — all live in the **retired
TypeScript agent repository**, not here. They are named rather than linked
because this repository never carried them.

## Why

The current shape is a Shopware App with an external app-server on our AWS account
(`src/shopware-app-server.ts`, single ECS Fargate task). On registration each shop hands us its
Admin API key and secret key; we store them per `shopId` and use them for every write. Three
consequences, all of which a B2B merchant is entitled to object to:

1. **Credential blast radius.** We hold long-lived credentials that can reprice quotes and touch
   orders in their shop.
2. **Data leaves the shop.** Every RFQ, line price, margin and buyer identity flows through our
   infrastructure and is persisted in our store.
3. **Operational dependency.** If our service is down, their RFQs go unanswered and they cannot
   fix it. Webhook deliveries are lost, not queued.

Decision (2026-08-25): hosted-by-us remains acceptable **only for shops we own** — the demo shop,
A2CN test rounds, "try our test shop". A pilot merchant's production shop requires that the merchant
runs it. Shopware SaaS support is explicitly out of scope for now, which is what makes a plugin
viable at all (plugins cannot be installed on SaaS; apps are the only extension path there).

## Decision

A third plugin, on top of the two the merchant already needs:

```
SwagCommercial      B2B: quotes, RFQ, approval, organization structure
Agentic Commerce    UCP: discovery, catalog, cart, checkout, agent surface
  └─ ours           quote capability · negotiation automation · A2CN mandate · admin
```

Ours depends on Agentic Commerce **hard**, not optionally: it is the Agentic Commerce plugin — not
the SDK bundle — that imports the UCP SDK's routes into Shopware
(`src/Resources/config/routes.php`, importing from the Composer install path of
`ucp-php-sdk/symfony-bundle`). Without it there is no UCP routing to extend.

The Agentic Commerce plugin is used **unmodified from `main`**. The RFQ/UCP/A2CN work currently on
its fork branches (`quote-management`, `quote-management-clean-split`, `feat/a2cn-act-carrier`) moves
into our plugin rather than living as a patched copy of theirs. This retires the fork and dissolves
ACL-175 / ACL-176.

The TypeScript quote path is **retired**, not kept as a second deployment mode. One implementation of
the negotiation policy, no drift. Our own demo shop installs the plugin like any merchant.

## What we verified about the integration seam

Checked against `shopware/agentic-commerce` at `main` (b2f81c5) and the vendored UCP PHP SDK.

**The SDK has real extension points. The plugin has none.**

The SDK autoconfigures a capability tag and collects it into a registry:

```php
// UcpSdkExtension.php:125
$container->registerForAutoconfiguration(CapabilityInterface::class)->addTag('ucp_sdk.capability');
// UcpSdkExtension.php:294
$container->setDefinition(CapabilityRegistry::class, new Definition(CapabilityRegistry::class, [
    new TaggedIteratorArgument('ucp_sdk.capability'),
]));
```

`CapabilityInterface` is a single method, `describe(): CapabilityDescriptor`. Any plugin that
registers such a service is in the registry. Alongside it the SDK exposes
`ucp_sdk.profile_contributor`, `ucp_sdk.payment_handler`, `ucp_sdk.adapter.*`,
`ucp_sdk.checkout_response_augmenter`, `ucp_sdk.order_webhook_enricher` and others, all by
autoconfiguration.

The **standard** capabilities are wired as one-slot aliases —
`alias(CatalogCapabilityInterface::class, CatalogCapability::class)` — so a second implementation of
a standard capability is not possible. This does not affect us: quote is a **vendor** capability
(`com.shopware.quote`, reverse-domain, allowed by UCP without upstream approval) and has no SDK
interface to contend for.

**The SDK's request handling is scoped by path prefix, not route ownership:**

```php
// RequestContextListener.php:114
return str_starts_with($path, '/ucp/');
```

So endpoints our plugin serves under `/ucp/…` receive the SDK's request context — `UCP-Agent`
requirement, agent profile fetching, request signature verification — on routes the SDK never
declared. `ExceptionListener` gives them UCP-shaped error envelopes on the same basis.

**The Agentic Commerce plugin, by contrast, offers nothing.** 176 of its 180 PHP files are
`@internal`. The class that owns the capability descriptor list is the worst case for extension:

```php
final class UcpCapabilityCatalog {
    private static function definitions(): array { /* hardcoded */ }
```

`final`, `private`, `static` — not decoratable, not subclassable. On `main` it contains no quote
capability at all.

**Known catch: discovery strips foreign capabilities.** The plugin's profile contributor runs at
default priority and whitelist-intersects:

```php
// CapabilityFilteringProfileContributor.php:48
$capabilities = array_intersect_key($profile->capabilities, array_flip($enabledDescriptors));
```

`$enabledDescriptors` derives from the hardcoded catalog, so our descriptor reaches the SDK registry,
enters the profile, and is then deleted before publication. **Workaround, available today:** register
our own `ProfileContributorInterface` at a negative priority so it runs after the filter and re-adds
the descriptor. This is the pattern the fork already uses — `A2cnMandateProfileContributor` at
`priority => -256`, explicitly commented as running after the SDK capability filter so its addition
survives. Consequence to accept: our capability does not appear in the plugin's admin capability
toggles, so enabling and disabling it is our own configuration.

**The genuine gap: agent-to-customer resolution.** The SDK's `RequestContext` carries host, headers,
agent platform profile, negotiated capabilities, signature verification, idempotency key and
`oauthClientId` — but no customer and no subject. Identity stops at the agent. The mapping lives in
the plugin, in `DoctrineDbalUcpOAuthStore` (`final`, `@internal`):

```php
public function issueTokenSet(string $salesChannelId, string $clientId, string $subject, string $scope): OAuthTokenSet
```

There is no SDK contract for reading `subject` back. On `main` nothing needs one:
`ShopwareCartAdapter` builds its context with `ContextTokenGenerator`, an anonymous guest context —
the B2C shape already recorded in `FINDINGS.md` (retired repository) §2.8. The abstraction we want
(`AccessTokenReaderInterface`, `AgentCustomerCredential`) exists **only on the fork branch**, because
the quote work is the first thing that needed an authenticated B2B buyer.

## Module decomposition

Six modules. Exactly one of them knows what Shopware is.

**Policy** — port of `src/policy` (1,365 lines): bands, per-dimension
deciders, `authorizeOffer`, `verifyOffer`. Pure functions, no Shopware dependency, unit-tested in
isolation. The part that must never be wrong, and the part that ports most mechanically.

**Negotiation** — the LLM. One interface, `propose(snapshot, caps): Proposal`, with an HTTP
implementation and a fallback that escalates. On the async path this is a single
`POST /chat/completions` with a system prompt read from a markdown file — see
`quote-negotiate-agent.ts:66`. No agent loop, no
tool calling, no LangGraph: the deployed quote path never touches the deep-agents runtime, which
appears in `bootstrap.ts` as a type import for the *interactive* surface only. In PHP this is Guzzle
and roughly eighty lines. The three prompt files under `config/agents/` are copied verbatim.

**Servicing** — orchestration: snapshot → interpret ask → propose → authorize → apply → verify →
reply or escalate. Also Shopware-free; takes the bridge as a dependency.

**Shopware bridge** — the only version-fragile module, behind one interface. Builds the quote
snapshot and performs the writes: reprice lines, recalculate, set expiration, add comment,
transition state. Everything that is an Admin API call today becomes a service call here. Confining
it to one seam is what keeps SwagCommercial's version drift out of the negotiation logic.

**Admin** — bands configuration, escalation surfacing, monitoring. See below.

**Protocol** — the `com.shopware.quote` vendor capability (descriptor + re-adding profile
contributor), the quote endpoints under `/ucp/…`, the served OpenAPI contract and prose spec, and
the signed A2CN seller mandate — now published from the merchant's own domain, which is where
partners should read it from.

What disappears rather than ports: `src/commerce` (2,584 lines of HTTP
client for reaching a shop from outside) and `src/app/shopware-app`
(482 lines of app registration, HMAC signed-query verification, iframe admin module). Both exist
only because we are outside the shop.

### Sizing

| Area | TS lines | Fate |
| --- | --- | --- |
| `src/policy` | 1,365 | port |
| quote slice of `src/harness` | ~1,840 | port |
| quote/negotiation contracts | ~290 | port as DTOs |
| `src/commerce` | 2,584 | delete |
| `src/app/shopware-app` | 482 | delete |
| prompts (`config/agents/*.prompt.md`) | — | copy verbatim |

Roughly 3,500 lines of TypeScript become PHP, most of it straight-line deterministic logic.

## Execution model

**Trigger.** No webhook. Subscribe in-process to SwagCommercial's quote state transitions
(`state_enter.quote.state.*`, `quote.requested`) plus quote-comment writes, and normalize both into
one internal "this quote needs servicing" message. State transitions are more precise than
entity-written events, which fire on every field touch and today have to be filtered on the
receiving end of an HTTP call.

This deletes gap #12 outright: there is no webhook delivery to verify on a given
Shopware/SwagCommercial build, and no Flow Builder fallback to document.

**Execution.** Never in the triggering request. Dispatch an async message carrying quote id,
sales-channel id and a revision marker; handle it in a worker with `#[AsMessageHandler]`. The
Agentic Commerce plugin already establishes this pattern
(`CleanupExpiredOAuthTokensTaskHandler`), so this is house style. Messenger supplies retry and a
dead-letter path that the current webhook path does not have.

**Re-entrancy.** Gap #1 exists because the agent's own `addComment` re-fires a webhook that is
indistinguishable from a buyer comment. In-plugin we author that comment, so the subscriber
recognises and skips it — an author check, not a tagging protocol across an HTTP boundary.
Deduplication becomes a real lock, one per quote id, using `symfony/lock` (already a required
dependency, `~6.4`). That replaces the in-process `inFlight` map in `quote-webhook.ts`, which is only correct today
because exactly one instance runs — so this also removes the constraint behind gap #14.

**Optimistic concurrency.** The message carries the quote's version marker; the handler re-reads and
aborts if it moved. Quote versioning belongs to SwagCommercial, and re-read-then-abort is cheaper
than contending with it.

**Failure posture — unchanged, deliberately.** Every failure escalates rather than guesses: model
unavailable, proposal failing `authorizeOffer`, or `verifyOffer` disagreeing with what actually
landed in the database. The last one is the important one and it ports as-is: we verify what the
database says, not what we intended to write.

**State we own,** all of it now in the merchant's own database: negotiation policy per sales channel,
the pre-negotiation reference snapshot needed to anchor per-line prices across rounds (gap #7), and
the audit trail. Retention and offboarding stop being our concern.

## Admin surfaces

Write as little UI as possible; inside the shop most of it already exists.

**Bands configuration → plugin `config.xml`.** Shopware renders it per sales channel with no
frontend code from us. The volume-tier list is the one thing `config.xml` cannot express as a
repeatable field; the current form already solves this with a textarea of `minQty:discountPercent`
lines, which ports as-is. This deletes the iframe admin module entirely — 237 lines of hand-rolled
HTML, the HMAC query verification, and the `sw-app-loaded` timing workaround.

**Escalation → quote custom fields plus the native Quotes list.** Write the escalation reason and a
waiting-since timestamp onto the quote; the merchant filters and sorts the list they already work
in. No new UI, and the escalation appears where the person answering it already is. A dedicated
inbox module is a later addition if the pilot shows the list is too thin — a merchant should tell us
that rather than us guessing now.

**Model configuration → the same `config.xml`.** The merchant supplies their own LLM credentials, so
the plugin needs an API key field and a base-URL field, per sales channel like the bands. The base
URL defaults to `https://api.openai.com/v1` and is what lets a merchant point at Azure, their own
gateway, or a self-hosted model — the mitigation for the residual network call is now a visible
setting rather than an environment variable nobody sees. Two consequences to be explicit about: the
key belongs to the merchant, so per-tenant model cost stops being ours; and `config.xml` stores it in
`system_config`, obscured in the UI by a password-type field but not encrypted at rest, which is the
same posture as every other secret a Shopware plugin holds and should be stated in the install
documentation rather than implied.

An empty API key must be a **loud** state, not a silent fallback. Today an empty `OPENAI_API_KEY`
silently drops the app to the deterministic path (gap #13), which looks like it is working while
behaving differently. In the plugin, no key means the agent is off and says so in the admin.

**Monitoring is the one thing we must build.** No generic admin view produces share-of-RFQs-handled.
The smallest honest version: the audit trail as a DAL entity, and one admin module page showing
counts (received / auto-answered / escalated / expired unanswered), average granted discount against
the configured band, and the per-quote decision trail. One page, not a dashboard.

## Upstream asks

Two small, defensible PRs against `shopware/agentic-commerce`. Both are worth doing on their own
merits, and both are platform findings of the kind this project exists to produce.

1. **Stop whitelist-filtering foreign capability descriptors** in
   `CapabilityFilteringProfileContributor` — filter only descriptors the plugin owns, and pass others
   through. One line of intent. Until it lands, the negative-priority contributor covers us.
2. **Expose a read side for the OAuth subject** — cherry-pick `AccessTokenReaderInterface` and
   `AgentCustomerCredential` from `quote-management-clean-split` onto `main`. Additive, no behaviour
   change, and a precondition for *any* authenticated B2B UCP capability, not a favour to us.

Interim bridge if PR 2 slips: query the plugin's OAuth token table directly with DBAL. This works
today and couples us to their migration schema rather than to a PHP interface — no deprecation path,
silent breakage. Acceptable only as a marked, dated bridge.

Rejected alternative: our plugin registering its own identity-linking adapter. The seam genuinely
exists — `IdentityLinkingCapability` takes `tagged_iterator('ucp_sdk.adapter.identity_linking')`,
commented "Capabilities that collect tagged adapters via iteration" — but it means a second consent
flow and a second token store in front of the merchant. Duplication for no gain when PR 2 is small.

## Retirement and parity

**Port order:** policy, bridge, servicing, trigger and queue, admin. Each layer depends only on the
one below, so the policy is finished and proven before anything touches a real quote.

**The existing test suite is the specification.** The TS repo has 7,562 lines of tests; the policy
portion encodes every band decision, boundary case and epsilon. Port those as the acceptance gate:
same fixtures in, same decisions out. Where a ported test disagrees, one of the two implementations
is wrong and we learn it during the port rather than on a merchant's quote. This is also the
cheapest place to fix gap #7, since a fixed pre-negotiation reference price is easy to express as a
fixture.

Test layout follows the plugin's own suites: unit for policy and servicing, integration for the
bridge against a real Shopware, functional for the endpoints.

**Cutover on a second shop, not the existing one.** A new test shop takes the plugin; the current demo
shop keeps the App and the TypeScript app-server, untouched, until the plugin is ready. That gives us
a live A/B rather than a migration: run the same A2CN scenarios against both and compare decisions,
offers and escalations directly, instead of comparing the plugin against recorded behaviour and
hoping the recording was faithful. It also means the demo shop stays demonstrable throughout — no
window where the thing we show partners is half-migrated.

Only once the new shop matches on the full scenario set do we delete the TS quote path:
`shopware-app-server.ts`, the app manifest and its render tooling, the shop store, the Admin API
client. The sales-agent harness stays for the interactive and UCP B2C surfaces, which are a different
product and were never part of this deployment.

Two things this requires and gets us: the two shops must run comparable Shopware/SwagCommercial
versions, or a behaviour difference tells us nothing; and the new shop should be the
production-grade one from ACL-186, so the free-shipping path (gap #3, untestable where shipping
computes to zero) finally becomes exercisable.

**Stays in TypeScript:** the interactive sales-agent surface, and the buyer-side test driver — which
we actively want, since it is how we will test the plugin.

## Consequences for the existing M5 issue list

These issues no longer exist. The workspace hit Linear's 250-active-issue cap — Done and Canceled
still count toward it, only archived issues do not, and Linear offers no manual archive — so
ACL-178 through ACL-188 were consolidated into the initiative document
[Quote Agent — working TODO list](https://linear.app/agentic-commerce-lab/document/quote-agent-working-todo-list-b70cda7c2ebf)
and deleted on 2026-08-25. That document is now the work list and the record; the table below is
kept as the mapping from the old issue numbers, which appear throughout this spec.

| Issue | Effect |
| --- | --- |
| ACL-177 delivery model | Decided by this spec; closes with the decision recorded |
| ACL-178 webhook re-entrancy | Shrinks to an author check plus a per-quote lock |
| ACL-179 per-line compounding | Survives; becomes a fixture in the ported policy tests |
| ACL-180 escalation inbox | Shrinks to quote custom fields plus the native list |
| ACL-181 monitoring | Survives; one admin page over a DAL entity |
| ACL-182 rollout controls | Survives; kill switch and scope move into plugin config |
| ACL-183 webhook delivery | Deleted — no webhook |
| ACL-184 SQLite on EFS | Deleted — state lives in the merchant's database |
| ACL-185 non-price terms | Survives unchanged; still a product decision |
| ACL-186 pilot environment | Reshaped: the install runbook work is deleted, and the new production-grade shop becomes the plugin's parity target |
| ACL-187 three installs to one | Reshaped: retire the fork, two upstream PRs |
| ACL-188 value ceiling currency | Survives; carried into the ported policy |

## Risks

**The bridge cannot be fully designed on paper.** SwagCommercial's quote services are mostly not
`@internal`, but their exact shape, transaction behaviour and interaction with quote versioning need
code written against them. This is the part of the estimate least trustworthy.

**Upstream PRs move at Shopware's pace.** Neither blocks starting: the capability filter has a
working workaround, and the identity reader has a DBAL bridge. Both must be opened early so the
internal-dependency list shrinks over time instead of growing.

**Internal-API coupling can creep.** Every use of an `@internal` class from the Agentic Commerce
plugin must be marked at the call site and listed in one place, so the list stays visible and
shrinkable rather than becoming ambient.

**Two implementations during the port.** The window between "policy ported" and "TS path deleted" has
two sources of truth. Parity tests are the mitigation; the cutover date is the real one.

## Non-goals

- Shopware SaaS support. Plugins cannot be installed there; revisit only if SaaS becomes a
  requirement, which would mean keeping an app-shaped deployment alongside.
- Rewriting the sales-agent harness. Only the quote path moves.
- Removing the LLM network call. Prompts containing quote lines, prices and buyer identity still go
  to a model provider. The mitigation is configuration, not architecture: the merchant's own API key
  and an OpenAI-compatible base URL, both as admin fields (see Admin surfaces), so they can point at
  Azure, their own gateway, or a self-hosted model. If "no data leaves our infrastructure" has to
  include the model provider, a self-hosted model becomes a requirement rather than an option, and
  this section changes.
- Distributing through the Shopware Store. Out of scope until a pilot merchant runs it successfully.
