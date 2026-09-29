# Merchant Quote Agent Plugin

A Shopware 6.7 plugin that negotiates B2B quotes on the merchant's behalf,
within limits the merchant sets, and hands a human anything outside them.

It sits on top of SwagCommercial's B2B QuoteManagement. When a buyer asks for a
discount, the plugin reads the ask, checks it against the merchant's configured
bands, has a model choose the numbers inside that band, writes the offer,
verifies what actually landed in the database, and replies. Every failure
escalates; nothing falls back to negotiating without a policy check.

**Agentic Commerce is optional.** Install it and buyer agents can request and
negotiate quotes themselves over UCP. Leave it out and the agent still services
every quote a buyer creates by hand in the storefront — same policy, same
replies, same escalations, same decision log. What is not registered without it
is listed under
[Without Agentic Commerce](docs/end-to-end.md#11-without-agentic-commerce).

**The shopping-assistant-starter-kit is optional the same way.** Install it and
a shopper chatting with the storefront assistant can ask it to request a quote
on their cart and check what happened to one they already have — the same
servicing loop answers either way, unaware whether the ask came from a form or
a chat message. Reading a quote's status is always on; requesting one waits on
its own toggle, `assistantQuoteRequests`, off by default, because it acts in
the buyer's name rather than the buyer's own click. Leave the starter kit out
and neither tool exists to be gated. See
[The shopping assistant](docs/end-to-end.md#12-the-shopping-assistant).

**Building or operating it? [`docs/end-to-end.md`](docs/end-to-end.md)** — the
full process with a TL;DR at the top: triggers, the pass, the model calls,
escalation, configuration, the dashboard, the A2CN evidence trail, and running
it in production.

**Running the shop? [`docs/for-merchants.md`](docs/for-merchants.md)** — the same
story without the code: what to set up, how it decides, what always goes to a
person, what it costs, and what reaches your AI provider.

## Requirements

- Shopware core **6.7.1** or newer
- **SwagCommercial 6.7.1.2** or newer, with B2B quote management licensed
  (`QUOTE_MANAGEMENT-6302947`) — its version numbers resemble core's but are
  not the same series
- A `messenger:consume` worker — nothing is serviced without one
- An LLM API key, base URL and model name (the merchant's own)
- **SwagAgenticCommerce** only for the agent-facing surface: the `/ucp/quotes`
  endpoints, identity linking, the Agent access page and the A2CN evidence
  layer. Everything else runs without it.

Neither plugin is a Composer dependency; both are detected at runtime, so this
one installs and runs on a shop that has neither. Both probes read
`kernel.bundles` — SwagCommercial for `QuoteManagement` (the bundle that owns
the quote entities; SwagCommercial registers each feature as its own bundle),
Agentic Commerce for `UcpSdkBundle` — because a class stays loadable after a
`composer require`d plugin is deactivated, a registered bundle does not. The
SwagCommercial gate also keeps a class check on the side, against the
`@internal` classes this bridge is written to. See
[ADR 0001](docs/adr/0001-runtime-plugin-dependencies.md) and its 2026-09-10
amendment.

## Install

CI packages an installable zip on every merge to main (*Plugin Zip* workflow).
It carries the compiled administration bundle but no `vendor/`. The shop needs
Composer >= 2.10.0.

```bash
unzip MerchantQuoteAgentPlugin.zip -d /path/to/shop/custom/plugins/
cd /path/to/shop
composer require shopware/merchant-quote-agent-plugin
rm -f config/packages/ai_generic_platform.yaml
bin/console plugin:refresh
bin/console plugin:install --activate MerchantQuoteAgentPlugin
bin/console cache:clear
```

Both the `composer require` and the `rm` matter, and skipping either fails in a
way that does not name this plugin.
[Installing into a shop](docs/end-to-end.md#9-installing-into-a-shop) explains
why, and what installing from the administration does differently.

**The agent ships switched off.** `enabled` is false and `maxDiscountPercent`
is `0`, so a fresh install answers nothing and an enabled channel with no bands
escalates everything. Configure it under **Settings → Extensions → Merchant
Quote Agent**, per sales channel — see
[Configuration](docs/end-to-end.md#6-configuration).

## Development

### Test shop

The integration suite (`tests/Integration`) runs against a real Shopware with
SwagCommercial. This repo defines that shop, and every worktree shares the one
container.

```bash
cp docker/.env.example ~/.cache/merchant-quote-shop/.env   # fill licence + admin password
scripts/shop-setup.sh                                     # first run: ~10 minutes
composer run test:integration                             # from any worktree
```

`shop-setup.sh` is idempotent — rerun it after any failure. It downloads
SwagCommercial 7.13.1 and Agentic Commerce 1.3.0 from their GitHub releases
(`gh auth login` first; SwagCommercial's repo is private). Without access, put
the two zips into `~/.cache/merchant-quote-shop/plugins/` by hand and rerun —
which is the only route while Agentic Commerce 1.3.0 is unreleased. Anything
older than 1.3.0 fails the container build against this plugin's SDK floor; see
docs/end-to-end.md §9.
The database is seeded from a dump of the previous shop; ask a colleague for
`~/.cache/merchant-quote-shop/seed/shopware.sql.gz` if it is gone.

| What | Where |
|---|---|
| Shop / admin | http://localhost:8095 (`SHOP_URL`, `SHOP_PORT`) — admin user from `.env` |
| Caught mail | http://localhost:8096 (`SHOP_MAIL_PORT`) — the flows mail on `in_review` and `replied` |
| Container | `merchant-quote-shop` (`SHOP_CONTAINER` to target another) |
| State | `~/.cache/merchant-quote-shop/` (`MQ_SHOP_HOME`): `.env`, `plugins/`, `seed/` — never committed |
| Shipping probe | `scripts/shop-check-shipping.sh` |

**Mail transport (#170).** This docker shop already has one: `MAILER_DSN`
points at the `mailcatcher` container, which is what lets the `in_review` and
`replied` flows show up at the "Caught mail" URL above. A shop built any other
way — a bare `bin/console system:install`, a manual dev VM, a hand-rolled test
shop — defaults to no transport at all (`mailer.dsn: 'null://null'` unless
something sets it), and mail sent there vanishes silently: no error, no
bounce, nothing in a log unless the sender happens to catch and report it.
`ShopwareEscalationNotifier`'s Administration notification does not depend on
mail and always works; the escalation flow this plugin ships
(`Migration1789500001SeedEscalationMailAndFlow`, disabled by default — enable
it in Flow Builder) does, so before concluding an escalation email is broken,
confirm `MAILER_DSN`/`mailer.dsn` actually points somewhere on the shop under
test.

Every integration test runs inside a rolled-back transaction, so the seed stays
as it was.

The buyer-history tests need orders for at least two quote customers sharing a
product. Populate the shop once after syncing:

```bash
scripts/sync-to-shop.sh
docker exec merchant-quote-shop php8.3 /var/www/html/bin/console cache:clear --env=dev --no-debug
docker exec -e APP_ENV=dev merchant-quote-shop php8.3 \
  /var/www/html/custom/plugins/MerchantQuoteAgentPlugin/scripts/seed-order-history.php --per-customer=4
```

That creates quotes and orders through Commercial's real checkout flow with
dates spread over 18 months. It prints the target database before writing,
refuses production environments, skips existing seed slots on rerun, and
suppresses mail and agent servicing. Existing quotes are left alone.

Touched anything under `src/Resources/app/administration`? `sync-to-shop.sh`
only pushes sources, so compile the bundle in the container:

```bash
docker exec merchant-quote-shop bash -lc 'cd /var/www/html && ./bin/build-administration.sh'
```

Skip it and the admin keeps serving the previous build. If the compiled bundle
goes missing entirely, Shopware drops the module without a word and its routes
render a blank administration rather than an error.

### Checks

```bash
composer run test        # unit suite, no kernel
composer run quality     # format, lint, typecheck, file size, admin checks, dupes, deps, audit
```

`composer run quality:admin` runs the administration module's assert-based
self-checks, which stand in for a JS test runner the project deliberately does
not have. Conventions and the per-change checks are in
[`AGENTS.md`](AGENTS.md).

Those self-checks reach only the three extracted pure modules. The components
are covered by a second command, which needs the test shop:

```bash
composer run quality:admin:shop                      # vue-tsc + ESLint, in the shop
composer run quality:admin:shop -- --verbose         # show the baselined findings
composer run quality:admin:shop -- --fix             # apply the ESLint autofixes
```

It syncs this checkout into the shop and runs Shopware's own extension
toolchain against the live installed Administration types — the only surface
that carries the real `Repository` class, so it catches a call to a method that
does not exist. It is not part of `composer run quality` and does not run in
CI, because the entity schema it needs is generated from a live database.

The plugin's 677 pre-existing findings are recorded in
`.shopware-admin-baseline.json`, so the check fails only on new ones. It does
**not** validate icon names (`icon` is typed `string`) and nothing renders a
component, so a method that type-checks and throws at runtime still ships. A
change that both fixes one occurrence of a baselined message and introduces a
new, unrelated occurrence of the identical message in the same file leaves the
recorded count unchanged and so is not reported either.

Two guards are skipped unless you have the relevant clone beside this
repository: `CoreFloorCompatibilityTest` needs `shopware/shopware` (or
`MQ_CORE_CLONE`) to confirm nothing in `src/` uses a core API newer than the
6.7.1 floor, and `ReleaseCapabilityMatrixTest` needs a SwagCommercial clone to
confirm the capability matrix still matches what each release declares.

## Evals

`composer run eval` answers "is the negotiation logic on the shop still correct?". It plays every scenario in `tests/Bench/scenarios/` three times as an external UCP buyer against the deployed shop (default `https://sw-ag.dev`), with no SSH involved. It reads the agent's decisions back through the Admin API, checks the money in code, lets Claude Code judge the replies, and exits 0 (all pass), 1 (a scenario failed) or 2 (inconclusive).

It tests the **deployed** plugin: deploy a branch before you evaluate it.

One-time setup:

1. Claim an ngrok static domain and add it to the shop's *Agent access → Profile hosts*.
2. Create an Admin API integration with read on `merchant_quote_agent_decision`, `merchant_quote_agent_trace`, `sales_channel` and `plugin`, read and write on `system_config`, and read and update on `product`.
3. Pick a storefront customer with `QUOTE_MANAGEMENT`.
4. Run the setup, which opens the shop's consent page for that customer:

```bash
EVAL_NGROK_DOMAIN=<your-domain>.ngrok-free.app EVAL_ADMIN_CLIENT_ID=... EVAL_ADMIN_CLIENT_SECRET=... \
EVAL_PRODUCT_ID=<product uuid> composer run eval:setup
```

Every run:

```bash
EVAL_NGROK_DOMAIN=... EVAL_ADMIN_CLIENT_ID=... EVAL_ADMIN_CLIENT_SECRET=... EVAL_PRODUCT_ID=... composer run eval
```

Four scenarios need settings the shop doesn't have by default (a margin floor, rounding, a zero cap). They change the shop's config for about a minute each, then restore it. After a hard crash, run `composer run eval:restore var/eval/<runId>`.

Output goes to `var/eval/<runId>/`:

- `verdict.json`: the result;
- `report.md`: Claude's write-up;
- `runs.jsonl`: every decision row;
- `judgments/`: the judge's answers.

To re-judge without new negotiations: `composer run eval -- --from=judge var/eval/<runId>`.

## Documentation

| Document | What it covers |
|---|---|
| [`docs/end-to-end.md`](docs/end-to-end.md) | **The whole process, with a TL;DR.** Start here if you build or operate it. |
| [`docs/for-merchants.md`](docs/for-merchants.md) | **The same story for whoever runs the shop.** No code: setup, decisions, escalations, costs, data. |
| [`docs/for-merchants.md#costs-and-data`](docs/for-merchants.md#costs-and-data) | **What the anonymized export sends, and what it never does.** Read before running `merchant-quote-agent:export`. |
| [`docs/adr/`](docs/adr/) | Architectural decisions. |
| [`docs/2026-08-25-quote-agent-shopware-plugin-design.md`](docs/2026-08-25-quote-agent-shopware-plugin-design.md) | Why this is a plugin rather than a hosted app. |
| [`docs/superpowers/specs/`](docs/superpowers/specs/) | Per-feature design records, one per issue. |
| [`docs/superpowers/plans/`](docs/superpowers/plans/) | The implementation plans those specs produced. |
