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
