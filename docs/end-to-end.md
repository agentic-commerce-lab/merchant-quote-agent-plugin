# The quote agent, end to end

How a B2B quote gets negotiated by this plugin, from the moment a buyer asks
for one to the moment a merchant reads the result on the dashboard. This is the
operator's and integrator's reference; the design record lives in
`docs/superpowers/specs/`, and the one architectural decision that shapes
everything else is [ADR 0001](adr/0001-runtime-plugin-dependencies.md).

Writing for the person who runs the shop rather than the code? Send them to
[`for-merchants.md`](for-merchants.md), which covers the same ground with no
class names in it.

---

## TL;DR

A buyer asks for a discount on a quote. Shopware fires an event, the plugin
queues a message, a worker picks it up, and one **servicing pass** runs:

1. **Trigger** — the quote entered `open` / `change_requested` / `request_change`,
   or a comment was inserted on it. Anything the agent itself did is ignored.
2. **Claim** — a per-quote lock, a crash budget, and a fingerprint that skips a
   re-trigger with nothing new on it.
3. **Read** — one snapshot of the quote through the SwagCommercial bridge.
4. **Interpret** — model call 1 turns the buyer's free text into a structured ask.
5. **Classify** — merchant-configured bands decide *grant*, *counter*, or
   *escalate*. This is plain PHP; no model is consulted.
6. **Propose** — model call 2 chooses the numbers, but only inside the band.
7. **Apply and verify** — the offer is written, then re-read and checked against
   the pre-pricing reference. A disagreement escalates and leaves the write in place.
8. **Reply** — model call 3 words the message; a template is used if it drifts.
   The quote moves to `replied`.
9. **Record** — one row in `merchant_quote_agent_decision`, and, if the buyer's
   agent opened an A2CN session, one signed counteroffer act.

**The bands decide what is permitted; the model only decides where inside it to
land.** Every failure — unreachable model, out-of-authority proposal, a verifier
that disagrees with the database — escalates to a human. Nothing ever falls back
to negotiating deterministically, and no number the shop commits to is chosen by
a model without a policy check after it.

**The agent ships switched off.** `enabled` defaults to false and
`maxDiscountPercent` defaults to `0`, so a fresh install answers nothing and an
enabled-but-unbanded channel escalates everything.

---

## 1. What has to be in place

| Requirement | Why |
| --- | --- |
| Shopware core **6.7.1** or newer | The support floor, and what `composer.json` requires. `CoreFloorCompatibilityTest` checks that nothing in `src/` uses a core class or DAL attribute argument newer than this, because an unknown DAL attribute argument is an `Error` during the container build — it takes the whole shop down, not just this plugin. |
| **SwagCommercial 6.7.1.2** or newer, with B2B quote management licensed (`QUOTE_MANAGEMENT-6302947`) | Owns the quote entities, the state machine and the Store API quote routes. 6.7.1.2 is the floor because `quote_comment.employee_id` appears there and `QuoteCommentMapper` reads it unguarded; `ReleaseCapabilityMatrixTest` pins that. Note that SwagCommercial's own version numbers look like core's but are not — its **6.7.12** is far newer than core's **6.7.1.2**. |
| A running `messenger:consume` worker | Nothing is serviced until a worker consumes the queue. |
| An LLM API key, base URL and model name | The merchant's own. See [Configuration](#6-configuration). |
| **SwagAgenticCommerce** — **optional** | Imports the UCP SDK's routes into Shopware. Needed only for the agent-facing half: the `/ucp/quotes` endpoints, identity linking, the Agent access page and the whole A2CN evidence layer. Everything a hand-made quote goes through works without it. See [Without Agentic Commerce](#11-without-agentic-commerce). |

Neither plugin is a Composer dependency; both are detected at runtime. That is
ADR 0001, and it is why a shop with neither installs this plugin happily.

**Both probes read `kernel.bundles`, for the same reason.** SwagCommercial is
gated on whether `QuoteManagement` — the bundle that owns the quote entities,
since SwagCommercial registers each feature as its own bundle — is in
`kernel.bundles` (`Bridge\Commercial\CommercialAvailability::isRegistered()`).
Agentic Commerce is gated on whether its `UcpSdkBundle` is in `kernel.bundles`
(`Ucp\UcpAvailability::isRegistered()`). Neither is gated on `class_exists()`
alone, because that is wrong for both: both plugins normally arrive via
`composer require` into `vendor/`, so Composer's autoloader keeps the
namespace loadable after a deactivation. A class-existence gate therefore went
on registering services against a bundle that was no longer there, and the
deactivation itself died in `DecoratorServicePass`. The bundle list is derived
from what the container is being built from, so it has no such lag.
`CommercialAvailability` keeps a class check as its second stage, against the
`@internal` classes this bridge is written to — a listed bundle without them
is a SwagCommercial this bridge was not written for.

One consequence worth knowing: `Resources/config/routes.php` receives a
`RoutingConfigurator` and no container, so it cannot ask. The gated route
imports live in `MerchantQuoteAgentPlugin::configureRoutes()` instead, which
holds the booted container — the same one those routes resolve services
against, so the router and the service graph cannot disagree.

The plugin also **probes what the installed SwagCommercial can do** rather than
reading its version number, because SwagCommercial ships schema in patch
releases. Four capabilities are probed off the DAL at container build
(`CommercialCapabilitiesFactory`):

| Capability | Field probed | Absent on released SwagCommercial (through 6.7.12) |
| --- | --- | --- |
| `lineItemAsks` | `quote_line_item.requestedPrice` | Yes — buyer asks are read from comments instead |
| `softDeleteLines` | `quote_line_item.deletedAt` | Yes |
| `lineScopedComments` | `quote_comment.quoteLineItemId` | Yes |
| `draftBeforeSend` | the send-request route class | Yes |

Merchant-side concessions — per-line offer prices and the quote-level discount —
work on every supported version.

---

## 2. How a quote reaches the agent

### The buyer's two doors

**Through the storefront.** A logged-in B2B customer with quote management
enabled requests a quote from their cart. SwagCommercial moves it `draft → open`
via the `customer_send` transition. This door needs nothing but SwagCommercial,
and everything from [§3](#3-claiming-the-pass) onwards is identical whichever
door the quote came through.

**Through a buyer agent, over UCP.** Six endpoints under `/ucp/quotes`, described
by `/.well-known/ucp/schemas/quote.openapi.json`. **This door exists only with
the Agentic Commerce plugin installed**, and so does the rest of this section.

| Method and path | Operation |
| --- | --- |
| `POST /ucp/quotes` | request a quote |
| `GET /ucp/quotes` | list the customer's quotes |
| `GET /ucp/quotes/{id}` | read one |
| `POST /ucp/quotes/{id}/counter` | counter it |
| `POST /ucp/quotes/{id}/accept` | accept it |
| `POST /ucp/quotes/{id}/decline` | decline it |

Every request needs two headers:

- `UCP-Agent`, which the UCP SDK enforces for everything under `/ucp/`. Its
  `profile="<uri>"` must be **https** on a host the shop allowlists. A bad
  profile URI is rejected **before the token is read**, and it surfaces as a
  **422, not a 401** — so an agent seeing 422 on a request that already carries a
  bearer token should suspect its profile URI, not its token.
- `Authorization: Bearer <token>`, an identity-linking access token issued by
  the Agentic Commerce plugin.

**The token's subject is the trust boundary.** Nothing in a request body can
select a customer. The customer must additionally carry the quote-management
feature flag, which SwagCommercial reads as a JSON **map** in
`customer_specific_features` — `{"QUOTE_MANAGEMENT": true}`. An array there is
silently ignored, and a customer without the flag gets a 422 naming it.

Scope is read but not enforced: Agentic Commerce cannot yet issue
`com.shopware.quote:manage`, so any valid token for the customer is accepted and
authorization is by quote ownership.

### Getting the buyer a token

`AgentAuthorizationRequestController` and `AgentConsentController` implement the
browser hop:

1. The agent POSTs to `/ucp/quote-agent/authorization-requests` (a POST with a
   JSON body and no query string, because the SDK verifies `@target-uri` against
   a URI Symfony re-sorts and re-encodes — a signed GET with parameters fails
   verification unless the client reproduces that normalisation exactly).
2. It gets back a one-time handle, a TTL, and an `authorization_url` on the
   sales channel's own domain.
3. The human opens that URL, logs in, and consents. The plugin asks Agentic
   Commerce to grant, and the agent collects its token.

> **None of this works until the sales channel's Identity Linking capability is
> on, and Agentic Commerce's own admin UI has no control for it.** A buyer who
> completes consent on a channel where it is off is told *"This authorization
> link has expired or has already been used"* — which has nothing to do with
> expiry. `ConsentGrantCompleter` burns the handle before it asks Agentic
> Commerce to grant, and turns every refusal into that one terminal page, so
> retrying always reports "already used". The real reason appears only in the
> shop log, as a warning naming `client_id` and `sales_channel_id`. This
> plugin's **Agent access** admin page carries the checkbox that turns it on.

### The trigger

`QuoteServicingTrigger` subscribes to two **core** events — never
SwagCommercial's own, whose classes are `@internal`:

| Event | Fires a pass when |
| --- | --- |
| `state_machine.quote.state_changed` | the quote **enters** `open`, `change_requested`, or `reopen` **via the `request_change` transition** |
| `quote_comment.written` | a comment row is **inserted** (one message per quote, not per row) |

The `reopen` case needs its own transition-name check because a buyer's
`request_change` and a merchant's `reopen` cannot be told apart by state name
alone. `quote.requested` needs no subscription: a buyer's request runs through
`customer_send` into `open`, which the state subscription already covers.

Two things are deliberately ignored: any context that is not
`Defaults::LIVE_VERSION`, and any context carrying `AgentContext::STATE` — which
is how the agent's own mid-pass writes avoid re-triggering it.

The trigger only ever **queues** a `ServiceQuoteMessage`. Nothing is serviced
until a worker consumes it.

---

## 3. Claiming the pass

`ServiceQuoteHandler` is the message handler. In order:

1. **No gateway?** SwagCommercial is absent or unlicensed. The message is parked
   in `failed` as unrecoverable — re-license the shop and
   `messenger:failed:retry` it.
2. **Lock.** A per-quote lock. If another worker holds it the delivery is
   *refused with a 5-second retry*, not parked — so a buyer comment landing
   mid-pass is serviced rather than dropped.
3. **Snapshot.** One read through the bridge.
4. **Preflight** (`ServicingPreflight`):
   - Quote in `accepted`, `declined`, `expired` or `cancelled`? SwagCommercial
     will not edit those. Logged and acked; nothing to service.
   - Configuration unusable? Escalate with `not_configured` and stop.
   - Agent switched off for the channel? Log at debug and stop.
5. **Fingerprint.** `ServicingFingerprint` compares the quote's current shape
   against the stamp left by the last pass. A re-trigger with nothing new since
   the agent's own reply makes **no model calls at all**.
6. **Crash budget.** The `merchant_quote_agent_attempts` custom field is
   incremented before the pass and cleared after it. Four failures *without a
   thrown exception* — a segfaulted worker, not a caught error — park the quote
   permanently. See [Recovering a parked quote](#8-operating-it).

The pass then runs, and afterwards the handler stamps the new fingerprint,
clears the attempt counter, and releases the escalation and clarification
markers if the pass actually answered the buyer.

---

## 4. The servicing pass

`NegotiationPipeline::service()` wraps everything in the audit recorder, so a
row is written whether the pass succeeds, escalates, or throws.

### 4.1 Interpret — model call 1

`AskInterpreter` sends the quote's line items (id, label, quantity, unit price,
requested price) plus the buyer's latest comment under a JSON schema generated
from the response DTOs. It extracts **only what the buyer explicitly asked**:

- `price.additionalDiscountPercent` — an explicit extra percentage.
- `price.bestPriceRequested` — "your best price", with no number named. A
  volume/bulk/tiered ask ("better price if we take 10?") is this field too: it
  asks for a price the merchant's own cap can answer.
- `structural.lineChanges` — quantity changes, per-unit target prices, removals.
- `structural.addProducts`, `structural.validityUntilIsoDate`.
- `negotiation.delivery` / `.payment` — non-price asks.
- `clarificationQuestions` — asks ambiguous in *reference* ("10% off" on a
  five-line quote) or in *intent* ("What about this?").
- `humanReviewRequests` — an ask that was understood and only the merchant can
  answer.

Earlier `[merchant]` comments in the thread are the agent's own previous
replies, used as context and never as buyer asks.

If there is no ask at all and no open structured target price — one below the
line price that the last pass has not already answered — a pass that read
a buyer comment ends as `acknowledged`: it posts `ReplyTemplate::acknowledges()`
— the buyer-facing total and expiry as the quote holds them, no model call, no
price write — and moves the quote to `replied` (`sent`, or `admin_resend` from
the renegotiation states). With no comment read it ends as `nothing_to_do`
instead (an escalated quote no merchant has sent since never gets this far;
it is `handed_over`, section 4.7) — first finishing a stranded
`in_review → replied` transition, but only when the agent's own comment is the
newest one on the quote.

### 4.2 The gate — what the agent refuses to answer itself

`AskGate` runs before any pricing, and each branch escalates to a human:

| Ask | Why a human takes it |
| --- | --- |
| **Structural** (add/remove products, change quantities) | Changing *what* is being sold is outside a price-and-validity mandate, and nothing downstream would act on it — so without the gate the buyer's real ask is silently dropped. |
| **Non-price** (free shipping, payment terms) | The quote gateway cannot write a delivery or payment term at all. Answering the price half and dropping the rest silently is worse than saying a human takes it. The asks are still *extracted* — that is what makes this escalation possible instead of a silent drop. A *volume* ask is not one of these: it names no term, only a price, so it is negotiated. |
| **Ambiguous**, first time | Not escalated: the questions are posted to the buyer **verbatim**, once, and the pass ends as `clarified` without spending the negotiate call. |
| **Ambiguous**, after already asking | A human takes it. The marker clears as soon as a pass answers with an offer, so a genuinely new ambiguity later is asked about rather than escalated silently. |

An ambiguous comment is deliberately **not** a `humanReviewRequests`
escalation: if the ask cannot be named, nothing is yet known that puts it
outside the merchant's own pricing policy. Asking costs one comment; escalating
spends a human.

One exception: a comment that merely points at a per-line requested price ("the
prices I asked for") is *not* ambiguous, because the line and the number are
both on record. The appliers read `requestedUnitPrice` directly.

### 4.3 Classify — no model involved

`NegotiationDecider` → `QuoteDecider` → `QuoteBandDecider` produce one of three
bands:

| Band | When |
| --- | --- |
| `grant` | The asked discount is at or below `maxDiscountPercent`. |
| `counter` | Above the maximum but at or below `counterOfferMaxPercent` — answered with a deterministic counter at the merchant's own cap. |
| `escalate` | Above the counter ceiling, or no counter band configured. |

**Minimum-margin floor.** With `minMarginPercent` set, `OfferApplier` never
LOWERS a line below `purchase price × (1 + minMarginPercent/100)`; a line
already below its floor (a loss leader, say) is left where it is. It does not
escalate: a deeper offer is raised to the floor, per line, and a quote-wide
percentage that would undercut any floor is written as line prices. Whenever
the floor re-prices an offer, quote-wide or per-line, the quote-level discount
is reset to 0% and folded into the line prices. A repeated ask the floor leaves
nothing more to give on moves no price, and escalates as
`no_further_concession`: a price ask is never answered with 0%. A post-write
check escalates as `verification_failed` if the database still lands a line
below its floor. The purchase price and the floor never reach the model or the
buyer; the buyer's reply reports the reduction the database actually shows.

Two other checks escalate here:

- **Value ceiling.** Above `maxQuoteValueNet` for the quote's currency →
  `quote_value_limit_exceeded`. A currency the merchant left blank →
  `currency_mismatch`, because an unknown ceiling is not an unlimited one.
- **Human review requested** by the extract step → `needs_human_review`.

**This gate is why an out-of-authority ask costs one model call rather than
three, and why it escalates even when the model is unreachable.**

### 4.4 Propose — model call 2

`OfferProposer` sends the quote, the buyer's asks, and *the authority* — the caps
the model must not exceed. The model leads: it decides what to offer, at the
level the buyer asked at (`terms.linePricesNet` for a per-line ask,
`terms.discountPercent` for a quote-wide one, never both).

It may also ask for **account history** instead of proposing, at most twice per
response cycle:

| Request | Returns |
| --- | --- |
| `quote_history` | this account's recent quotes: dates, values, states, whether each became an order, and each one's latest recorded per-pass price reduction |
| `orders` | lifetime order figures and recent orders with line items |
| `product_purchases` | what this account paid for **one product that is on this quote** — any other id is refused |

History is bound to this quote's account; the model cannot supply a customer
identifier, and a cross-customer read raises `CrossCustomerRead` and escalates.
The prompt is explicit that history is internal (never quoted back to the
buyer), that it **does not raise the cap**, and that a discount in the history
was granted on a *different* quote and is not already in these prices.

A third history request sends the quote to a human rather than getting the buyer
a reply.

### 4.5 Apply and verify

`OfferApplier` writes the offer, then re-reads the quote and runs
`OfferVerifier` — totals, per-line prices, expiry — against the **pre-pricing
reference snapshot**, not against what the model claimed. `OfferAuthorizer` has
already bounds-checked the proposal; the verifier is the authoritative gate on
what actually landed in the database.

Two escalations live here:

- **The proposal was outside authority** → `proposal_rejected`, with the reason
  string recorded so the pass can be explained afterwards.
- **The database disagrees with the offer** → `verification_failed`.
  **The applied changes are deliberately left in place.** Rolling back is a
  write that can itself fail, and a failed rollback leaves the quote in a third
  state nobody intended. The escalation tells a human what the database
  actually says.

A **second round of per-line negotiation** goes to a human: the reference prices
a per-line offer is bounded against are captured fresh each pass, so a second
per-line concession would be measured against the first one's already-reduced
prices and compound past the cap. Quote-wide rounds are unaffected.

Prices, discounts and expiry dates are written as **absolute values**, so a
worker that dies mid-pass and retries produces the same quote rather than
stacking a second discount on the first.

### 4.6 Reply — model call 3

`ReplyComposer` asks the model to reword a template that already contains every
fact: the reduction, the new total, the changes, the validity date.
`RewordingGuard::unsafeBecause()` compares the result; **if a figure moved, a
new one appeared, the reply ran past five sentences, or it named a concession
the merchant never authorised, the plain template is sent instead** — this is
the only path by which model free text reaches a buyer, so it is a positive
list, not a spot check. If the call fails outright, the template is
sent — the offer is already applied and verified, so the alternative is leaving
the buyer with a changed quote and no message.

The merchant's `negotiationStrategy` text supplies the tone here and the posture
in the negotiate prompt. It **cannot move a cap**: it lands in a delimited
section below the base instructions, and the authorizer rejects anything outside
authority regardless of what it asked for.

The quote then transitions to `replied`. If that transition fails, the failure
is appended to the record's `violations` — the pass authorized, verified and
told the buyer, and still did not finish.

### 4.7 Standing down for a human

Before the extract call, `NegotiationPipeline::negotiate()` asks one thing:
has a human merchant acted on this quote more recently than the buyer's
newest input? If so, the pipeline itself writes nothing and calls no model,
and ends as `handed_over` — though `ServiceQuoteHandler::claimAttempt()` has
already committed the attempt counter and the baseline before the pipeline
ever runs, and the fingerprint is stamped after it, same as any other
outcome.

"Acted" means either of two things, whichever is newer. **A comment** — an
administration note, kept in `BuyerConversation`'s third bucket (`merchant`),
which `SnapshotAdapter::conversation()` fills from the comment's own author
columns and which reaches neither prompt. **A transition** — read by
`Bridge\MerchantActionReader` off the newest `state_machine_history` row for
the quote whose `user_id` is not null. Core fills that column only when the
context source is an `AdminApiSource` (`StateMachineRegistry.php:154` at core
tag `v6.7.1.0`), so the buyer's storefront transitions
(`SalesChannelApiSource`) and the agent's own (`SystemSource`) both write null
by construction — an administration user is the only party this column can
name. The query is deliberately not filtered by `referenced_version_id`:
SwagCommercial edits a quote inside a version lane, and a human acting there
is still a human acting. The result lands on
`QuoteLifecycle::$lastAdminTransitionAt`.

The buyer's side of the comparison is the newer of their newest comment and
their newest per-line ask. A per-line ask can arrive with no comment at all —
the storefront writes straight into `quote_line_item.requested_price` — so
`MerchantHandover::freshAskAt()` reads it line by line: it parses the ask
tokens the last pass stamped into a set, then, for each line still carrying a
requested price, composes that line's own `id:price` token
(`ServicingFingerprint::askToken()`, the one place the token is formatted,
shared with `asksOf()`'s whole-quote marker) and skips the line whenever that
token is already in the stamped set. Only a line whose own token differs from
what was stamped contributes its `updatedAt`. That has to be a per-line
comparison rather than a whole-quote one: our own price writes move
`updatedAt` on every line we concede on, including a line the buyer never
touched, so gating on whether anything anywhere had changed would read that
unrelated concession as a fresh buyer ask on the line it landed on.
`Negotiation\MerchantHandover::tookOver()` runs this whole comparison as a
static predicate over the snapshot.

There is no persistent handover flag and no merchant-operated switch: a later
buyer comment, or a later per-line ask, makes the comparison read `false`
again on the very next pass, and the agent answers as normal.

The one exception is an open escalation. While
`Servicing\PendingEscalation::awaitsAHuman()` holds — the escalation marker is
set and no merchant has moved the quote to `replied` since it was written —
`tookOver()` returns `true` however new the buyer's ask, so a second ask on an
escalated quote does not run the pipeline and escalate it again. A merchant's
send releases it. A legacy marker written before its time was recorded is
released by any send, since it cannot be ordered against one. After an
escalation only a merchant SEND re-enables the agent; reopening or declining
the quote does not, and buyer chat asks made during the escalation are
consumed (the fingerprint is stamped) and not mirrored to `requested_price`.

The outcome, `NegotiationOutcome::HandedOver`, is returned before the extract
call and before the stranded-reply branch that follows it. It does not answer
the buyer, so `answeredTheBuyer()` is false and neither the escalation marker
nor the clarification marker is released — the fingerprint is stamped all the
same, because the trigger was handled.

### 4.8 Escalation

`QuoteEscalator` posts one fixed, customer-facing comment — *"A member of our
team will review this quote personally and get back to you."* — stamps the
reason into a custom field, and notifies the merchant through two channels: a
`QuoteAgentEscalatedEvent` business event for Flow Builder, and an
administration notification. **It never transitions the quote**; the deal desk's
own state change is the only observable sign of a human acting.

The marker makes escalation idempotent for a given reason. The thirteen reasons:

`discount_limit_exceeded`, `quote_value_limit_exceeded`, `needs_human_review`,
`currency_mismatch`, `not_configured`, `model_unavailable`, `model_declined`,
`proposal_rejected`,
`verification_failed`, `structural_change_requested`,
`non_price_term_requested`, `unplaceable_ask`, `no_further_concession`.

---

## 5. What the pass leaves behind

### The decision record

One row per pass in `merchant_quote_agent_decision`, written through system
scope so nobody can PATCH it afterwards. It carries the trigger and attempt, the
revision it read, the band and the outcome, the discount granted against the cap
in force, totals before and after, the model, host, token counts and latency,
the three prompt hashes, the interpreted asks, the raw proposal, the violations,
the writes, the error chain, and `replyToBuyer` — **the agent's message to the
buyer**. The newest buyer comment a pass read is stored in `buyer_ask`; logs
never carry it.

Four columns are not written by the pass: `terminalState` / `terminalAt`, stamped
by `TerminalOutcomeSubscriber` when the quote reaches a state that ends a
negotiation, and `resolvedAt` / `resolvedState`, stamped by
`EscalationResolutionSubscriber` when a human acts after an escalation.

Because the core state-change event carries no author, a transition by *anyone*
closes an escalation — the deal desk sending a revised offer, or the buyer
withdrawing. `resolvedState` is stored precisely so that stays inspectable. The
agent's own mid-pass transitions carry `AgentContext::STATE` and are skipped,
without which an escalated quote's next pass would stamp itself as the human
resolution.

### The A2CN act, when there is a session

**This whole layer needs the Agentic Commerce plugin** and is not registered
without it — see [Without Agentic Commerce](#11-without-agentic-commerce) for
why it cannot stand alone.

Nothing here is gated by a toggle: the presence of `a2cn_session` on the quote —
written by whichever buyer agent opened the negotiation — is the gate. On every
quote entering `replied`, `SellerActEmitter` mirrors the whole chain, then
counter-signs a `counteroffer` act **only if** the terms differ from our own last
signed act. A shop nobody negotiates with over A2CN emits nothing.

Published on the sales channel's own domain:

| Path | Body |
| --- | --- |
| `/.well-known/a2cn-agent` | discovery document, twelve fields |
| `/.well-known/did.json` | did:web document with the public half of this installation's signing key |
| `/.well-known/a2cn-seller-mandate` | the negotiation bands, published declaratively and signed |
| `/a2cn/sessions/{sessionId}/acts` | our mirror of the act chain |
| `/a2cn/records/{sessionId}` | the transaction record once an acceptance act exists, else the audit log once the session ended, else `409` |

`{sessionId}` is a UUIDv5 derived from the Shopware quote id — unguessable, and
the capability the records endpoints are authorized against.

Verifying an act needs nothing beyond what is published: take `publicKeyJwk` off
the DID document's one verification method, convert it to PEM, and verify the
act's compact ES256 JWS. The verified payload must equal the act's own
`protocol_act_hash`, itself `base64url(SHA-256(JCS(signed view)))` — so a
signature that merely verifies over *some* object is not enough.

The ES256/P-256 signing key is generated on `activate()` and on `update()`, never
on install (during install the plugin is inactive, so its own services are not
in the container yet). Both call `generateIfAbsent()`, so an upgrade never
rotates a key the already-signed acts depend on. It lives in `system_config`
under `MerchantQuoteAgentPlugin.a2cn.signingKeyJwk`, deliberately outside the
`config.*` prefix the admin renders — a private key must never appear in a
settings form. Evidence is **fail-open throughout**: it never stops commerce.

---

## 6. Configuration

**Settings → Extensions → Merchant Quote Agent**, per sales channel. A channel
inherits the global value until overridden, so a pilot channel can be raised
first.

| Field | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | While off the agent queues nothing and writes nothing. |
| `llmApiKey` | — | Yours. Stored in `system_config`, obscured in the form but **not encrypted at rest**. Overridden by `MQA_LLM_API_KEY` in the environment, which keeps it out of the database. |
| `llmBaseUrl` | `https://api.openai.com/v1` | Point at Azure, your own gateway, or a self-hosted model. |
| `llmModel` | — | Required. No default, because guessing one picks a price and quality point for you. |
| `negotiationStrategyId` | — | Which strategy this sales channel negotiates with. Holds the strategy's id, not its text; the prompt comes from that strategy's newest version. Can never move a cap. |
| `maxDiscountPercent` | `0` | `0` means every price ask escalates. |
| `counterOfferMaxPercent` | — | Blank means no counter band. |
| `minMarginPercent` | — | Markup on each product's purchase price that no offer may go below (`purchase × (1 + m/100)`, rounded up to the cent). Clamps the offer to that floor rather than escalating. Products without a purchase price have no floor. Blank means off; `0` means never below cost. The purchase price never reaches the model or the buyer. |
| `roundingMode` | `off` | `off`, `discount_percent` (the model's quote-wide percentage is floored to the step before authorization, so the checks, the per-line conversion and the reply all see it; a per-line answer is left unrounded) or `quote_total` (a quote-wide write becomes an absolute discount that lands the buyer-facing total, shipping included, on the next multiple of the step). Never the buyer's own figure, never below a standing concession, never to nothing (a cent or less counts as nothing); `quote_total` additionally skips a net quote with tax on top (`tax_on_top`), which also catches a gross quote whose goods are all 0 % VAT but whose shipping is taxed (conservatively written unrounded), while `discount_percent` still rounds there. Each skip is recorded on the `rounding` trace event. The percentage stated in the reply is measured on the whole buyer-facing total including shipping, so under `discount_percent` a quote with shipping can read e.g. 6.97 % in the reply while its discount line shows 7 %; `quote_total` makes the total round, and the stated percentage then follows from it. |
| `roundingStep` | — | Percentage points in `discount_percent`, currency units of the buyer-facing total in `quote_total`. Blank or `0` means off whatever the mode. |
| `maxQuoteValueNet` | — | Per currency, net. A currency left blank escalates. Blank everywhere means no ceiling. |
| `validityDays` | `14` | How long an auto-offer stays valid. At least 1 — blank or `0` takes the channel out of service rather than sending an offer stamped as already expired. A shop updating from a release that defaulted this to `0` has that `0` rewritten to `14`; a value the merchant set is left alone. |
| `escalationSlaHours` | — | Dashboard benchmark only. Changes nothing the agent does. |

One field is deliberately **not** on that page. The **organization name
published in the A2CN seller mandate** lives on the **Agent access** page,
because it is only ever read when the shop publishes that mandate and the whole
A2CN layer is off without the Agentic Commerce plugin. It is the same
`system_config` key as before
(`MerchantQuoteAgentPlugin.config.a2cnOrganizationName`), so an existing value
still applies — but it is now per sales channel only, where the plugin config
page could also set a global default. A global value still shows on every
channel, and saving copies it onto that channel. Editing it needs
`system_config:update`, not that page's `ucp.editor`.

Left empty it publishes the shop name from **Settings → Shop → Basic
information**, then the sales channel's name, then `Merchant`. The field's
placeholder shows whichever applies, so leaving it empty is a visible choice
rather than a guess.

Three things worth stating plainly:

- **An empty API key is never a quiet fall back to deterministic decisions.** An
  enabled channel with no key or no model name is a misconfiguration: the quote
  escalates and the log names the wrong fields.
- **There is no mode that negotiates without a model.** Reading a buyer's
  free-text ask is itself a model call.
- **Invalid configuration is refused whole.** A cap above 100, or a wrong-typed
  value, takes the whole sales channel out of service rather than applying the
  half that happened to be valid.

**Configuring from the CLI needs `--json`.** `bin/console system:config:set <key>
<value>` stores the raw *string*: `...validityDays 30` stores `"30"`, which is
refused, and `...enabled true` stores `"true"`, which reads as switched off —
silently. Always `bin/console system:config:set --json <key> <value>`.

### Deciding which agents may transact

Three UCP allowlists — agent platforms, profile hosts, agent domains — are edited
per sales channel under **Agent access** in this plugin's admin module. They are
Agentic Commerce's data: the page reads and writes that plugin's config API, so
it needs `ucp.viewer` to load and `ucp.editor` to save. An entry covers its
subdomains.

> **An empty list is not a deny-all.** Agentic Commerce substitutes rather than
> passes through: emptying *Profile hosts* falls back to *Agent platforms*, and
> emptying that too falls back to the sales channel's own domain host. Clearing
> all three is what denies every remote agent.

The allowlists are an **SSRF and abuse control, not an authenticity check**. They
gate which hosts the shop will fetch a profile from; whether a fetched profile's
signature must verify is governed by the channel's `signaturePolicy`.

For a throwaway agent host there is a console-only switch:

```bash
bin/console merchant-quote-agent:allow-any-agent                     # where is it on?
bin/console merchant-quote-agent:allow-any-agent <salesChannelId> --on
bin/console merchant-quote-agent:allow-any-agent <salesChannelId> --off
```

It widens an identity allowlist and nothing else — https only, ports 443/8443,
no redirects, no private or link-local addresses, blocked metadata hosts all
still apply. It is deliberately absent from `config.xml`: widening which agents
are even checked is not a decision for a settings form.

Two traps around it:

- **A `system_config` row written without a sales channel applies to every
  channel.** The console command only ever writes per-channel; an inherited row
  shows up as every channel reporting `on`.
- **On FrankenPHP or RoadRunner the switch is effectively inoperative.** The
  SDK's `RequestContextListener` is built once per process and holds the
  validator our factory builds, so the installation-wide list freezes at
  whatever the worker's *first request of any kind* produced — almost never the
  agent's. Restarting the worker re-runs the same lottery. The per-sales-channel
  gate is still evaluated per request, so an agent on an unflagged channel is
  still refused. Under php-fpm none of this is visible.

---

## 7. Reading the dashboard

The module's list page opens filtered to *Needs review* and leads with four
figures, each scoped to the period in the smart bar. **A figure with nothing to
measure reports absent (`–`, "no baseline", "n/a") rather than a confident
zero.**

- **Auto-execution rate** — the share of the period's serviced quotes the agent
  never escalated. The denominator is every quote serviced, not only the
  concluded ones: restricting it would drop stuck escalations out of the count
  and make a shop with ten of them report 100%. The raw `n of m escalated` count
  sits beside it, with a trend against the previous period.
- **Escalation resolution time** — mean time from escalation to the deal desk's
  resolving transition. **Only covers escalations resolved after the
  `resolved_at` migration shipped**; earlier ones report as `n still open`
  rather than vanishing from the average. Set `escalationSlaHours` to turn it
  into "n of m within the SLA".
- **Price retention** — discount granted on the agent's deals against discount
  granted on deals it never touched, matched to the same net-value range.
  **This is not gross margin**: it is the original price against the price sold,
  and neither this plugin nor a typical B2B catalog carries a cost-of-goods
  figure to net against. On SwagCommercial 7.12 the baseline sees quote-level
  discounts only, which *understates* the baseline and so makes the agent look
  worse than it is — the safe direction to be wrong in.
- **Deal cycle time** — mean time from RFQ submission to a confirmed order, same
  agent-vs-baseline comparison. Reads `requestedAt` where it exists and falls
  back to `createdAt` where it does not.

---

## 8. Operating it

### Run a worker

```bash
php bin/console messenger:consume async -vv
```

Without one, quotes queue silently and forever.

> **The admin worker looks like a substitute and is not.** It runs only while
> someone has the administration open, and `ConsumeMessagesController` hands it a
> bare event dispatcher carrying neither retry listener. Symfony's `Worker`
> therefore rejects any message whose handler threw, and the doctrine transport
> deletes the row: no retry, nothing in `failed`, nothing to show, and the quote
> untouched.

### Locking, and why two replies happen

Locks default to `flock`, and "one host" is not the ceiling it sounds like.
Symfony's `FlockStore` keys its lock file on `sys_get_temp_dir()`, so exclusion
holds only between processes sharing a `/tmp` — and a web server under systemd
`PrivateTmp=yes` does not share one with a CLI worker. The admin worker and
`messenger:consume` then take *different* files for the same quote.
`QuoteServicingLock` logs a startup warning whenever the DSN is host-local.

Measured, not inferred: with both workers live, one buyer comment fires both
triggers, both passes claim the quote a second apart, and the buyer gets two
replies to one ask. The discount survives it — offers are absolute, so the
second pass re-applies the same percentage rather than stacking — but "the buyer
is never messaged twice" does not. An escalation doubles the same way.

Two ways out, and the first is the one you want anyway: switch the admin worker
off (`shopware.admin_worker.enable_admin_worker: false`) once a real worker
runs, or point `LOCK_DSN` at a shared store such as Redis — required regardless
before running workers on more than one node.

### What ends up in `failed`

| Cause | What to do |
| --- | --- |
| SwagCommercial licence off | Re-license, then `messenger:failed:retry`. |
| Quote reached a terminal state | Nothing — logged and acked, there is nothing to service. |
| Crash budget exhausted | See below. |
| Another worker holds the lock | *Not* parked — refused with a 5-second retry. |

### Recovering a parked quote

Four failures without a thrown exception park a quote permanently; every future
trigger, including a genuine buyer comment, is skipped. The counter is a
deliberately unregistered custom field, so it will not show in the admin. Clear
it:

```http
PATCH /api/quote/{id}
{ "customFields": { "merchant_quote_agent_attempts": null } }
```

---

## 9. Installing into a shop

CI packages an installable zip on every merge to main. It carries the compiled
administration bundle but **no `vendor/`** — shopware-cli skips dependency
bundling for Shopware >= 6.5, because the shop resolves a plugin's Composer
requirements itself.

The shop needs **Composer >= 2.10.0**: Shopware's 6.7 project template writes
`config.audit.ignore` as keyed objects, a form 2.9.0 rejects on every command
that reads the project file.

```bash
unzip MerchantQuoteAgentPlugin.zip -d /path/to/shop/custom/plugins/
cd /path/to/shop
composer require shopware/merchant-quote-agent-plugin
rm -f config/packages/ai_generic_platform.yaml
bin/console plugin:refresh
bin/console plugin:install --activate MerchantQuoteAgentPlugin
bin/console cache:clear
```

**The `rm` is not cosmetic.** Composer's Flex plugin applies a recipe for
`symfony/ai-generic-platform` that writes a file declaring an `ai:` config root
belonging to `symfony/ai-bundle`, which this plugin does not register. Left in
place it fails the container build with *There is no extension able to load the
configuration for "ai"* — taking down every console command and the storefront,
not just this plugin. Flex records the recipe as applied, so deleting it once is
enough. Nothing in the plugin can prevent it: Flex reads `extra.symfony.*` only
from the root package.

**The `composer require` is the step that is easy to skip and expensive to
diagnose.** `plugin:install` does refuse without it, but a plugin forced past
that check activates cleanly and then throws `Class "CuyZ\Valinor\MapperBuilder"
not found` on every servicing pass, naming neither the plugin nor the step that
was skipped.

Installing from the administration needs no shell for that step:
`executeComposerCommands()` is overridden, so Shopware runs the `composer
require` itself, with `--no-scripts` — which also means it cannot write the Flex
file above. Keep the `rm` in the runbook, just not in the install path. Composer
then runs inside a web request, so `composer.json`, `composer.lock` and
`vendor/` must be writable by the web user, and PHP's `memory_limit` and
`max_execution_time` have to survive a dependency resolution. Core skips the
whole mechanism in cluster setups, where the build owns the lock file.

Each zip is versioned `1.0.<run number>+<commit sha>`. The `+<sha>` is semver
build metadata: Composer keeps the full string in the shop's lock file while
Shopware records `1.0.<run>` and orders builds by run number. Map a run number
back to a commit with `gh run list --workflow "Plugin Zip"`.

### Agentic Commerce 1.2 and 1.3

Both AC versions declare `ucp-php-sdk/symfony-bundle` from the same `<0.1.0`
range, but 1.2 floors it at 0.0.5 and 1.3 at 0.0.6. This plugin floors it at
0.0.6 — a version both declarations accept, so Composer resolves either pairing
without complaint. Only one of them runs.

**Composer-satisfiable is not the same as bootable, and AC 1.2 is the case
where they differ.** AC 1.2 ships
`src/Resources/config/packages/ucp_sdk.yaml` with `version: '2026-04-08'`, and
SDK 0.0.6 turned that node into a validated one accepting only `2026-08-25`.
So on a shop running the **public 1.2 release**, 0.0.6 resolves and then the
container build dies:

```
Invalid configuration for path "ucp_sdk.version": Unsupported UCP protocol
version "2026-04-08". This SDK release serves 2026-08-25.
```

That is a compile-time failure, so it takes down every console command and the
storefront, not just a plugin. Reproduced on `merchant-quote-shop`: with SDK
0.0.6 resolved, `plugin:install --activate SwagAgenticCommerce` (AC 1.2.0 from
the GitHub release) failed in `MergeExtensionConfigurationPass`. AC 1.3 does
not ship that file at all and sets `2026-08-25` in its `services.php`, which is
why the same SDK is fine there.

The practical consequence: **this plugin's 0.0.6 floor pairs it with AC 1.3.**
A shop on the public 1.2 release has to move to 1.3, or hold the whole stack at
SDK 0.0.5 and not install this plugin's current version.

A shop still holding 0.0.5 refuses the AC 1.3 upload with *Required
plugin/package "ucp-php-sdk/symfony-bundle >=0.0.6 <0.1.0" does not match
installed version == 0.0.5.0* — Shopware validates an uploaded plugin's
requirements against the **root** `vendor/`, not against the `vendor/` the
upload carries, so AC bundling 0.0.6 itself does not satisfy the check. Lift
the root instead:

```bash
composer update ucp-php-sdk/core ucp-php-sdk/symfony-bundle
bin/console plugin:refresh && bin/console plugin:install --activate SwagAgenticCommerce
```

Name the packages, and do not reach for `-W` when Composer suggests it. On a
shop tracking `shopware/core: dev-trunk` — `merchant-quote-shop` is one — `-W`
also moves core, and a core that has drifted past the installed SwagCommercial
fails the container build on an unrelated service (`subscription.cart.restorer`
wanting a `$cartRuleLoader` argument core no longer has). Recovering means
restoring `composer.lock` and running `composer install`.

0.0.6 is additive over 0.0.5 across everything this plugin touches: every
changed constructor gained its new parameter last and with a default.

**Shops that once ran the pre-release AC fork need one more step.** That build
stored an `allowAnyAgent` key in `swag_agentic_commerce_ucp_config.config_json`,
and upstream AC rejects unknown keys — every read *and* every write of that
sales channel's UCP config throws, which 500s `/.well-known/ucp/profile` and
400s every `/ucp/*` route with *Invalid UCP config at $.allowAnyAgent*. No admin
or console path can repair it, because `saveConfig()` merges over the stored row
it cannot read. Drop the key directly:

```sql
UPDATE swag_agentic_commerce_ucp_config
   SET config_json = JSON_REMOVE(config_json, '$.allowAnyAgent')
 WHERE JSON_CONTAINS_PATH(config_json, 'one', '$.allowAnyAgent');
```

This plugin's own allow-any-agent switch is unrelated and lives in
`system_config`; set it with `bin/console merchant-quote-agent:allow-any-agent`
(§6), never by editing AC's row.

**That fork build left a second value, and the validator reports one at a
time**, so the 400 comes back naming a different path once `allowAnyAgent` is
gone: `"quote"` inside `enabledCapabilities`, refused as *unsupported
capability "quote"*. Upstream AC has no such capability name and needs none —
this plugin publishes `com.shopware.quote` from its own profile contributor,
independently of that list. Drop just that element and leave
`identity_linking`, which upstream does support and the buyer-token flow needs:

```sql
UPDATE swag_agentic_commerce_ucp_config
   SET config_json = JSON_REMOVE(
         config_json,
         JSON_UNQUOTE(JSON_SEARCH(config_json, 'one', 'quote', NULL, '$.enabledCapabilities[*]')))
 WHERE JSON_SEARCH(config_json, 'one', 'quote', NULL, '$.enabledCapabilities[*]') IS NOT NULL;
```

Clear the cache after each statement, and check with `bin/console ucp:channels`
— it reads the same config, so it fails until the row is clean.

### Uninstalling

With *keep user data* off, uninstall drops the three A2CN evidence tables and
deletes the signing key from `system_config` — the two are independent, so a
merchant who asked to wipe data does not keep a live private key just because a
table drop failed.

---

## 10. Where the code is

| Area | Namespace | Owns |
| --- | --- | --- |
| Bridge | `src/Bridge` | Everything that talks to SwagCommercial, behind narrow typed interfaces (ADR 0001). Capability probing lives in `Bridge\Commercial`. |
| Servicing | `src/Servicing` | Trigger, queue, lock, crash budget, preflight, escalation. |
| Negotiation | `src/Negotiation` | The pass itself and the three model calls. Framework-free: prompts arrive as strings from the container. |
| Policy | `src/Policy` | Bands, authorization, verification. Pure functions over its own DTOs; no Shopware, no model. |
| Audit | `src/Audit` | The decision record and its two outcome subscribers. |
| Protocol | `src/Protocol` | A2CN evidence: acts, did:web, signing, records. |
| Identity | `src/Identity` | Bearer tokens, the consent hop, agent allowlisting. |
| Ucp | `src/Ucp` | The buyer-facing capability, its transport and its contract documents. `UcpAvailability` is the Agentic Commerce gate; `AgentFacingRoutes` lists what it turns off. |
| Config | `src/Config` | Raw `system_config` values to validated settings. |

Two boundaries are enforced rather than merely intended. `NamespacePurityTest`
fails if anything in `src/Negotiation` imports Shopware beyond one allowed
exception (`IllegalTransitionException`, which the applier and the reply have to
catch); `src/Policy` imports none at all. And the policy layer keeps its own
snapshot DTOs, converted at the edge by `SnapshotAdapter::toPolicy()`, so a
change to the bridge's shape cannot reach the deciders unnoticed.

---

## 11. Without Agentic Commerce

The plugin installs and runs on a shop that never had SwagAgenticCommerce, or
that has it deactivated. Nothing configures this: what is registered decides.
`Ucp\UcpAvailability::isRegistered()` asks whether `UcpSdkBundle` is in
`kernel.bundles` — see [§1](#1-what-has-to-be-in-place) for why that, and not
`class_exists()`.

**Still there** — everything a hand-made quote goes through:

- the trigger, the queue, the lock and the crash budget
- the full negotiation pass and all three model calls
- the policy bands, authorization and verification
- escalation, its business event and its administration notification
- the decision record, the dashboard and the detail page
- the quote contract documents under `/.well-known/ucp/schemas/`, which
  *describe* the capability rather than serve it

**Gone:**

- the `/ucp/quotes` endpoints, and the two profile contributors that advertise
  them
- identity linking: the authorization and consent routes, and the readers behind
  them, which read Agentic Commerce's own OAuth tables
- the `merchant-quote-agent:allow-any-agent` and `merchant-quote-agent:grants`
  console commands
- the **Agent access** page in Settings
- the entire A2CN evidence layer, including the three `.well-known` documents

### Why A2CN goes with the surface rather than standing alone

It cannot start without a buyer agent. `SellerActEmitter` reads the chain from
the quote's `a2cn_session` custom field; nothing in this plugin ever writes that
field, and the counterparty that does can only reach the shop over UCP. With no
session it returns `inert()` forever. What would be left is three discovery
documents advertising an `endpoint` and a `records_url` to any agent that
crawled them, on a shop where no agent can negotiate at all — so they are not
published either.

Installing SwagAgenticCommerce later needs nothing from this plugin but a cache
clear: the container is rebuilt and the surface appears.

---

## 12. The shopping assistant

A third door onto the same servicing loop, gated on a **second, independent**
optional plugin: the shopping-assistant-starter-kit
(`swag/assistant-starter-kit`). `Assistant\AssistantAvailability::isRegistered()`
gates it exactly the way `Ucp\UcpAvailability` gates Agentic Commerce — on
whether `SwagAssistantStarterKit` is in `kernel.bundles`, for the same reason
[§1](#1-what-has-to-be-in-place) gives at length. Without it, nothing below is
registered, and a quote requested by hand in the storefront or over UCP is
serviced exactly as before.

With it, this plugin contributes two tools to the storefront chat assistant:

| Tool | Does | Returns |
| --- | --- | --- |
| `request_quote` | Turns the shopper's cart into an ordinary hand-made storefront quote — the same door SwagCommercial's own "request a quote" action uses, so everything from [§3](#3-claiming-the-pass) onward is unaware it came from a chat message. Takes a short merchant-facing message — not a transcript of what the shopper typed at the assistant — and, optionally, two parallel lists of product ids and per-unit target prices. | `quote_number`, `state`, and a note instructing the model what it may and may not say |
| `quote_status` | Looks up what happened to a quote the shopper already has, scoped to their own account — a number belonging to someone else, or a number nobody has, both come back `not_found`. | `state`, `total`, `valid_until`, and a note instructing the model how to state them |

**`request_quote` waits on its own merchant toggle, `assistantQuoteRequests`,
default off; `quote_status` does not.** Requesting a quote acts *in the
buyer's name* — it writes to their account without them clicking anything —
so a merchant opts in before a shopper can create one by chatting rather than
filling in a form. Reading what already happened to a quote the shopper
themselves asked for is not a new act on their behalf either way, so it stays
available whenever the tools are registered at all. The two switch
independently because they are wired from two separate factories
(`RequestQuoteToolFactory`, `QuoteStatusToolFactory`): a merchant can let a
shopper ask about an existing quote in chat while keeping the quote-through-chat
door itself shut.

**Neither tool ever states a price, for two different reasons.** `quote_status`
states a total, but only one the shop already committed to: the figure is
formatted server-side and its note tells the model to repeat it verbatim,
never to recalculate, round, or convert it, and never to state a discount
percentage next to it. `request_quote` states no figure at all, because none
exists yet to state — the merchant's negotiation agent replies minutes later,
asynchronously, and at the moment the tool returns nobody has looked at the
quote. The only figure the tool ever has on hand is the buyer's own ask,
echoed back — and a model handed that figure and no instruction otherwise
narrates an outcome anyway, turning an echo into "I got you 12% off." That is
not a guess about model behaviour, it is why the
starter kit's own `EscalateTool` needed the identical prohibition spelled out,
after a live model claimed a handover had already happened in six runs out of
six with no instruction that it should. `RequestQuoteTool`'s note tells the
model plainly not to say a discount was granted, approved, applied or secured,
not to predict what the shop will offer, and not to state any price or
percentage for this quote — the only honest thing to say is that the request
is with the shop and a reply is coming.

**The provenance stamp.** A shopper can hand `request_quote` a target price two
ways: their own words, or a figure the assistant itself proposed that the
shopper then agreed to. Both reach the policy engine as the identical number,
so without a record the decision log would read "the buyer asked for X%" about
a figure a model wrote. `AssistantAskStamp` records which one happened, in the
quote's own `merchantQuoteAgentAssistantAsk` custom field
(`buyer_stated` or `assistant_proposed`) — a deliberately unregistered custom
field, so it will not show in the admin; read it back through the API or the
database. **`Negotiation\CappedAuthority` deliberately does not read it.** An
assistant-proposed figure caps exactly like a typed one today; narrowing the
cap for a model-authored ask is a policy decision for its own spec, not a side
effect of recording provenance. The stamp exists only to make the distinction
visible, so that decision can be made on evidence instead of a guess.

**This path leaves no A2CN evidence trail.** The whole layer in
[§5](#the-a2cn-act-when-there-is-a-session) exists to mirror a signed act chain
between this shop and a counterparty *agent* — a buyer's own agent negotiating
over UCP, the door [§2](#2-how-a-quote-reaches-the-agent) describes. A shopper
chatting with the storefront assistant has no counterparty: the assistant runs
inside the buyer's own browser session, acting on the buyer's own behalf, the
same as if they had filled in the quote form themselves. There is no mandate,
no session id, no act for `SellerActEmitter` to counter-sign — a quote
requested this way never carries an `a2cn_session` custom field, so nothing
here overlaps [§11](#11-without-agentic-commerce): that section is a shop
without the Agentic Commerce plugin at all, while this is a shop that has it,
talking with a party the protocol was never asked to cover.
