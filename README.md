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

## Operating the servicing loop

The servicing loop (issue #4) only queues messages when a buyer comments or a
quote enters `open`/`change_requested` — nothing is serviced until a worker
consumes them:

    php bin/console messenger:consume async -vv

Without that running, quotes queue silently and forever; there is no other
symptom.

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
