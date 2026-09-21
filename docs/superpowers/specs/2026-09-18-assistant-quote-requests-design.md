# Assistant-requested quotes

A buyer talks to the shopping assistant, fills a cart, and asks for a better
price. Today that conversation ends with "please request a quote yourself". This
design lets the assistant request the quote, in the buyer's own name, from the
buyer's own session — and then the servicing loop that already exists replies to
it, unchanged.

Compatibility with
[shopping-assistant-starter-kit](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit)
is **optional**, in the same sense Agentic Commerce is: install the starter kit
and the assistant gains two tools; leave it out and nothing in this plugin
changes or fails to boot.

## 1. What the starter kit is, and what it is not

It is a Shopware 6.7 plugin (`swag/assistant-starter-kit`, namespace
`Swag\AssistantStarterKit\`) running Symfony AI Agent in-process. It reaches
Shopware through the DAL and the SalesChannel services.

It speaks **no** UCP, **no** MCP and **no** Agentic Commerce. That single fact
sets the whole shape of this design: there is no agent identity to verify, no
mandate to check, no counterparty to exchange signed acts with. The assistant
runs inside the buyer's logged-in storefront session, so a quote it creates is
an ordinary hand-made storefront quote. The A2CN evidence layer is not involved
and must not be.

Tools are contributed by any plugin through two service tags,
`swag_assistant.tool_factory` and `swag_assistant.grounded_tool_factory`,
collected with `tagged_iterator`. Nothing has to be registered with them.

## 2. Architecture

```
storefront chat turn (buyer's own session)
  └─ AssistantAgentFactory collects swag_assistant.tool_factory
       └─ RequestQuoteToolFactory  (ours — a normal DI service)
            ├─ returns null if: no SalesChannelContext, no logged-in customer,
            │                   or merchant toggle off (see §5.2's amendment:
            │                   the per-customer quote feature is NOT checked
            │                   here — it surfaces as a gateway refusal instead)
            └─ RequestQuoteTool / QuoteStatusTool  (#[AsTool])
                 └─ BuyerQuoteGatewayInterface   ← the seam that already exists
                      └─ SwagCommercial CartToQuote → quote in `open`
                           └─ existing servicing loop replies, unchanged
```

Four decisions inside that.

### 2.1 The unprivileged tool tier

We implement `ToolFactoryInterface` and tag `swag_assistant.tool_factory`, not
the grounded tier.

The starter kit's `GroundedToolContext` exists to keep catalogue facts on
exactly one path, so that the model is structurally incapable of inventing a
price. A quote is not a catalogue fact — it is the buyer's own document — so we
have no business taking the privileged tier or the grounding duty that comes
with it.

### 2.2 The sales-channel context

`ToolContext` carries only `TraceRecorder` and `AssistantConfig`. Upstream
asserts that exact property list in `ToolAuthorityTest` precisely so it does not
grow, so asking them to widen it is not an option and should not be proposed.

Our **factory** is our own DI service, so it takes whatever collaborators it
likes. It injects `RequestStack` and reads the storefront `SalesChannelContext`
off the master request, plus `BuyerQuoteGatewayInterface`. The factory is
invoked once per turn, inside the storefront request, so the context is present
exactly when a real shopper is on the other end.

### 2.3 Gating on the bundle, not the classpath

New `AssistantAvailability`, modelled directly on `Ucp\UcpAvailability`: it
reads `kernel.bundles`. It does **not** use `class_exists`, which lies for
vendored plugins.

Without the starter-kit bundle, `Resources/config/services.php` never registers
the factory, so its class is never autoloaded and the absent upstream interface
cannot fatal at boot.

### 2.4 Stubs, and the one test that makes them honest

There is no Packagist package for the starter kit, and this repo takes no
composer dependency on it. The two upstream types we touch are hand-written
stubs under `tests/`, for static analysis only.

Stubs catch nothing when upstream renames something. The mitigation is the one
this repo already uses for SwagCommercial's class-name literals: a live wiring
test (§5.5) that resolves the real tag name and the real interface FQCN against
a shop with both plugins installed.

## 3. The gateway change

`SwagCommercialBuyerQuoteGateway::requestQuote()` today **adds** the supplied
line items to the buyer's cart and then converts that cart, and it rejects an
empty `$lineItems`.

The assistant's buyer has already filled the cart through the starter kit's
`add_to_cart`. Passing the cart's contents back in would double every line. UCP
never met this because a buyer agent arrives with no cart.

Two changes, both inside the existing method:

1. The emptiness guard moves from "`$lineItems` must be non-empty" to "the cart
   must be non-empty **after** additions". An empty array becomes legal and
   means *quote what is already in the cart*.
2. A line item carrying `product_id` and `requested_unit_price` but **no
   `quantity`** is price-only: it records the ask and adds no cart line. That is
   how a target price attaches to a line the buyer already added.

UCP's behaviour does not change. It always sends quantities, and its previous
empty-array rejection now fails one step later, on the empty cart, with the same
outcome.

## 4. The two tools

### 4.1 `request_quote`

| | |
|---|---|
| arguments | `comment` (the buyer's own sentence), optional `targets[]` of `{product_id, unit_price}`, `target_source` |
| effect | cart → quote in `open` |
| returns | `{quote_number, state, note}` — **no prices** |

Returning no figures is deliberate. The merchant agent's reply is asynchronous
and lands minutes later, so any number this tool returned would be the buyer's
own ask echoed back — which is exactly what a model turns into "I got you 12%
off". The `note` instructs the model to say the request is in and that the shop
will reply, and not to predict an outcome.

This is the same failure the starter kit's `EscalateTool` had to write an
explicit prohibition against, after a live model claimed "I've flagged this to
the team" in six of six runs. The wording is a request; §5.3 is the guarantee.

### 4.2 `quote_status`

| | |
|---|---|
| arguments | optional `quote_number` |
| effect | reads the buyer's own quotes, newest first |
| returns | `{state, replied_total, valid_until}` as server-formatted strings |

Totals are formatted server-side and the model is told to quote them verbatim,
for the same reason the starter kit renders catalogue facts rather than letting
the model restate them.

### 4.3 Ask provenance

The assistant may propose a target price — the buyer says "see if they'll do
better", the model suggests 10%, the buyer agrees. That is a deliberate product
decision, and it means the figure entering the policy engine was authored by a
model rather than typed by a person.

So the design records who wrote it. `request_quote` takes
`target_source: buyer_stated | assistant_proposed`, and the tool stamps it
onto the quote's `customFields` through the same `QuoteUpdate(customFields:)`
path `Protocol\Ingress\A2cnSessionStamp` already uses. No schema change. The
admin decision view can then show "assistant proposed, buyer confirmed" rather
than attributing the figure to the buyer.

**Out of scope by explicit decision:** `Negotiation\CappedAuthority` continues to
treat an assistant-proposed figure as the ask ceiling, exactly as it treats a
typed one. Narrowing the cap for model-authored asks is a policy change, not an
integration detail, and belongs in its own spec.

## 5. Tests

Written in this order.

1. **Gateway guard** — empty `$lineItems` with a non-empty cart succeeds; an
   empty cart still throws; a price-only line records the ask and adds no cart
   line.
2. **Factory gating** — `null` for each of: guest, toggle off, no storefront
   context. **Amendment:** the per-customer "quote feature" check is NOT one
   of the factory's null-return cases. It lives in
   `CommercialQuoteAccess::assertCustomerHasQuoteFeature()`, called from
   inside `SwagCommercialBuyerQuoteGateway::requestQuote()`, and it is not
   exposed on `BuyerQuoteGatewayInterface` — adding an interface method just
   to move this one check into the factory is a larger change than this
   feature warrants. So a customer without the quote feature IS offered the
   tool; calling it raises the gateway's `ValidationException`, which
   `RequestQuoteTool` catches and turns into a structured `not_created`
   refusal rather than a broken chat turn. Recorded as the decision, not a
   gap.
3. **Tool arguments** — rejects a `target_source` the model invented; bounds the
   comment length.
4. **Provenance** — an `assistant_proposed` target reaches the quote's
   `customFields`.
5. **`ToolWiringTest`** (integration, live shop) — resolves the real
   `swag_assistant.tool_factory` tag and the real `ToolFactoryInterface` FQCN.
   With stubs in place this is the only thing that catches an upstream rename.

## 6. Configuration

One new `config.xml` field, `assistantQuoteRequests`, **default off**.

The assistant creating a quote is an outward-facing act in the buyer's name, so
it is opt-in rather than something that switches on the moment both plugins are
present. Off means the factory returns `null`, so the tool is never constructed
and the model never sees it — capability control by toolbox construction, never
by prompt instruction, which is the starter kit's own rule and this plugin's.

Adding the field touches `PluginConfigTest`, which is sensitive to install-time
defaults against a configured shop.

## 7. Documentation

- A section in `docs/end-to-end.md`.
- A README paragraph shaped like the existing "Agentic Commerce is optional"
  one. This is the second optional front door onto the same servicing loop, and
  the symmetry is the point worth showing.

## 8. Explicitly not in this design

- Counter, accept and decline from chat. The merchant's reply is asynchronous
  and carries real numbers; the buyer meets those in the quote UI, where
  accepting places an order.
- Any UCP or A2CN involvement. There is no counterparty agent, so there is no
  mandate, no signed act chain and no end-of-session record.
- Any change to the starter kit repository.
