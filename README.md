# Merchant Quote Agent Plugin

Shopware 6.7 plugin: the merchant-side quote negotiation agent on top of
SwagCommercial's B2B QuoteManagement, exposed to buyer agents through Agentic
Commerce's UCP surface. Design documents live in `docs/`; decisions in
`docs/adr/`.

## Test shop

The integration suite (`tests/Integration`) runs against a real Shopware with
SwagCommercial. This repo defines that shop; every worktree shares the one
container.

    cp docker/.env.example ~/.cache/merchant-quote-shop/.env   # fill licence + admin password
    scripts/shop-setup.sh                                       # first run: ~10 minutes
    composer run test:integration                               # from any worktree

`shop-setup.sh` is idempotent — rerun it after any failure. It downloads
SwagCommercial 7.13.1 and Agentic Commerce 1.2.0 from their GitHub releases
(`gh auth login` first; SwagCommercial's repo is private). No access? Put the
two zips into `~/.cache/merchant-quote-shop/plugins/` by hand and rerun.

The database is seeded from a dump of the previous shop
(`scripts/shop-export-seed.sh` produced it; ask a colleague for
`~/.cache/merchant-quote-shop/seed/shopware.sql.gz` if the old shop is gone).

| What | Where |
|---|---|
| Shop / admin | http://localhost:8095 (`SHOP_URL`, `SHOP_PORT`) — admin user from `.env` |
| Caught mail | http://localhost:8096 (`SHOP_MAIL_PORT`) — the quote flows send mail on `in_review` and `replied` |
| Container | `merchant-quote-shop` (`SHOP_CONTAINER` to target another) |
| State | `~/.cache/merchant-quote-shop/` (`MQ_SHOP_HOME`): `.env`, `plugins/`, `seed/` — never committed |
| Shipping probe | `scripts/shop-check-shipping.sh` |

Every integration test runs inside a rolled-back transaction, so the seed
stays as it was. Design: `docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md`.

## Installing into another shop

CI packages an installable zip on every merge to main (the *Plugin Zip*
workflow). It carries the compiled administration bundle but **no `vendor/`**:
shopware-cli skips dependency bundling for Shopware >= 6.5, because the shop is
meant to resolve a plugin's Composer requirements itself.

    unzip MerchantQuoteAgentPlugin.zip -d /path/to/shop/custom/plugins/
    cd /path/to/shop
    composer require shopware/merchant-quote-agent-plugin   # via the custom/plugins/* path repo
    bin/console plugin:refresh
    bin/console plugin:install --activate MerchantQuoteAgentPlugin
    bin/console cache:clear

That `composer require` is the step that is easy to skip and expensive to
diagnose. `plugin:install` does refuse without it — *Required plugin/package
"cuyz/valinor ^2.6" is missing or not installed and activated* — but a plugin
forced past that check activates cleanly and then throws `Class
"CuyZ\Valinor\MapperBuilder" not found` on every servicing pass, from inside
the settings read, before the state check and before any model call. Nothing
in that message names the plugin or the step that was skipped, and under the
admin worker (below) it leaves no trace at all.

Prerequisites are the plugin's, not the zip's: SwagCommercial with the
QuoteManagement licence active, and SwagAgenticCommerce.

## Operating the servicing loop

The servicing loop (issue #4) only queues messages when a buyer comments or a
quote enters `open`/`change_requested` — nothing is serviced until a worker
consumes them:

    php bin/console messenger:consume async -vv

Without one, quotes queue silently and forever — unless the shop runs
Shopware's admin worker (`GET /api/_info/config` →
`adminWorker.enableAdminWorker`), which looks like a substitute and is not. It
runs only while someone has the administration open in a browser, and
`ConsumeMessagesController` hands it a bare event dispatcher carrying neither
`SendFailedMessageForRetryListener` nor
`SendFailedMessageToFailureTransportListener`. Symfony's `Worker` therefore
`reject()`s any message whose handler threw, and the doctrine transport deletes
the row: no retry, nothing in `failed`, nothing for `messenger:failed:show`,
and the quote untouched. Everything below about messages parking in `failed`
describes a real `messenger:consume` worker and only that one.

Locks default to `flock` (Shopware's own default), which only coordinates
processes on one host. `QuoteServicingLock` logs a startup warning if so, but
two workers on different hosts will still race the same quote. Point
`LOCK_DSN` at a shared store (e.g. Redis) before running workers on more than
one node.

A quote that fails servicing four times *without a thrown exception* (a
segfaulted worker, not a caught error — see `ServiceQuoteHandler::MAX_ATTEMPTS`)
parks permanently: every future trigger, including a genuine buyer comment, is
skipped until the `merchant_quote_agent_attempts` custom field is cleared. It
is deliberately unregistered, so it will not show in the admin. Clear it with
a PATCH against the Admin API:

    PATCH /api/quote/{id}
    { "customFields": { "merchant_quote_agent_attempts": null } }

or the SQL equivalent against the `quote.custom_fields` JSON column.

Two other messages end up in the `failed` transport rather than being retried
forever or acked away:

- **The SwagCommercial licence is off.** The quote was queued while the plugin
  was licensed, and the handler now has no gateway. The message parks
  immediately; re-license the shop and replay it with
  `messenger:failed:retry`.
- **The quote reached `accepted`, `declined`, `expired` or `cancelled`.** These
  are the states SwagCommercial itself will not edit, so a comment on one is
  logged and acked — nothing to service, nothing to park.

A quote already claimed by another worker is *not* parked: the delivery is
refused with a flat 5-second retry until the lock frees, so a buyer comment
that lands mid-pass is serviced rather than dropped.

## Configuring the agent

Everything is in the plugin's own settings, per sales channel:
**Settings → Extensions → Merchant Quote Agent**. A sales channel inherits the
global value until you override it, so you can configure once and raise the
ceiling on a pilot channel first.

**The agent ships switched off, and a fresh install answers nothing.** That is
deliberate on two counts: `enabled` defaults to false, and `maxDiscountPercent`
defaults to `0` with every non-price dimension blank, so even once enabled the
agent escalates every ask until you set bands. A silent agent is far more often
"not configured yet" than "broken".

**You supply the model credentials.** The API key is yours, so per-tenant model
cost is not the plugin's, and the base URL lets you point at Azure, your own
gateway or a self-hosted model. To state plainly rather than imply: the key is
stored in Shopware's `system_config` table, obscured behind a password field in
the admin but **not encrypted at rest** — the same posture as every other
secret a Shopware plugin holds.

**An empty API key is never a quiet fall back to deterministic decisions.** An
enabled channel with no key, or no model name, is a misconfiguration: the agent
escalates the quote with a comment and logs which fields are wrong.

**Rules-only mode still needs a key.** It means *no model decides or writes* —
the band picks the number and a template writes the reply — but reading a
buyer's free-text ask is itself a model call, and nothing else can do it. There
is no mode in which the agent negotiates without an API key.

**Configuring from the CLI needs `--json`.** `bin/console system:config:set <key>
<value>` stores the raw *string* unless you pass `--json`, and a string is not
what the plugin expects for any non-text field. `system:config:set
...validityDays 30` stores `"30"`, which is refused as a wrong-typed value and
takes the whole sales channel out of service; `system:config:set ...enabled
true` stores `"true"`, which is not the boolean `true` and so reads as switched
off — silently. Always write `bin/console system:config:set --json <key>
<value>`, e.g. `--json ...validityDays 30`.

**Invalid configuration is refused whole.** A discount cap above 100, a bad
ceiling currency or a volume-tier line that does not parse makes the whole
sales channel unusable and escalates, rather than applying the half of the
policy that happened to be valid.

## How the agent negotiates

Each servicing pass runs six stages: read the quote, interpret the buyer's ask,
classify it against the merchant's bands, propose an offer, apply and verify it,
then reply.

**The bands decide what is permitted; the model decides how much within it.**
An ask above the counter-offer ceiling escalates to a human *before* any
proposal is requested — so an out-of-authority ask costs one model call rather
than three, and it escalates even when the model is unreachable. A proposal
that comes back outside authority is rejected by the policy layer regardless of
what the merchant's strategy prompt asked for.

**Up to three model calls per pass**, each with its own prompt under
`config/agents/`: extract (read the ask), negotiate (choose the offer), reply
(word it). A re-trigger with nothing new since the agent's last reply makes
none of them.

**No decision ever falls back to a guess.** A model that cannot be reached
while reading the buyer's ask or choosing the offer, a proposal outside
authority, or a verifier that disagrees with what actually landed in the
database all send the quote to a human — the discount, the validity, every
number the shop commits to, is unaffected. The third call only words the
reply: if it fails there, the offer is already applied and verified, so the
agent sends the plain template rather than leaving the buyer with a changed
quote and no message at all. The agent never falls back to *deciding*
deterministically when a model fails — that guarantee is about decisions, and
it holds even here.

**A second round of per-line negotiation goes to a human.** The reference
prices a per-line offer is bounded against are captured fresh each pass, so a
second per-line concession would be measured against the first one's already
reduced prices and compound past the merchant's cap. Persisting that reference
across passes is issue #2(a); until it lands, an agent comment already on the
quote sends the next per-line ask to a human. Quote-wide rounds are unaffected.

**Anything that is not a price goes to a human.** Free shipping, payment terms
and bundles are read out of the comment and then escalated: only the price ask
reaches the deciders, and the quote gateway cannot write a delivery or payment
term at all — so answering the price half alone would drop the rest in silence,
and granting the rest would promise the buyer something that never lands.

**A verification failure leaves the applied changes in place.** Rolling back is
a write that can itself fail, and a failed rollback leaves the quote in a third
state nobody intended. The escalation tells a human what the database actually
says.

Prices, discounts and expiry dates are written as absolute values, so a worker
that dies mid-pass and retries produces the same quote rather than stacking a
second discount on the first — and the buyer is never messaged twice.
