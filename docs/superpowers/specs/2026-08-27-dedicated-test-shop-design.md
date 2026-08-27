# Dedicated test shop: docker compose in this repo (issue #8)

**Status:** approved design, 2026-08-27. Supersedes the "Target shop" section of
`2026-08-26-shopware-quote-bridge-design.md`, which stated the requirements; this
spec is how they get met. Due date from #8: shop up, plugin installed, shared with
the PM by **2026-09-04**.

## Why

The #3 integration suite (39 tests) runs against `shopware-trunk`, a
`dockware/shopware:dev-main` container owned by the `agentic-supplier-gateway`
compose project. That project's directory no longer exists on the machine, so
the only environment the suite runs in cannot be rebuilt. If the container dies,
the suite has nowhere to run and the data it depends on (36 quotes, 8 customers,
16 products) is gone with it.

The borrowed shop is also the wrong shape for this plugin: PHP 8.2 as default
CLI against our `php ^8.3`, SwagAgenticCommerce 1.1.1 against our
`ucp-php-sdk/core >=0.0.5`, no `cuyz/valinor`, and two stray files
(`config/packages/zz-ucp-sdk-test.yaml`, `.env.test` with
`KERNEL_CLASS='App\Kernel'`) that break `TestBootstrapper` for any plugin. Our
integration bootstrap routes around all of it; a dedicated shop makes the
workarounds unnecessary.

## What we verified before designing

- **SwagCommercial is fetchable.** `github.com/shopware/SwagCommercial` is
  private but the developer's `gh` auth reaches it. Releases `7.13.0`
  (2026-08-05) and `7.13.1` (2026-08-25) each ship `SwagCommercial.zip` (14 MB).
  The borrowed shop's copy is a bare directory (no `.git`, no `packages.shopware.com`
  auth), registered through dockware's `custom/plugins/*` composer *path*
  repository — copying was already the mechanism, not a shortcut. Its 368 MB is
  292 MB `node_modules` + 22 MB tests; the zip is what matters.
- **Agentic Commerce 1.2.0 is released** (2026-08-07, upstream
  `shopware/agentic-commerce`) and requires `ucp-php-sdk/symfony-bundle
  >=0.0.5 <0.1.0`, satisfying our constraint. Its `SwagAgenticCommerce.zip`
  (443 KB) carries built admin assets and no `vendor/`; the shop's root
  composer resolves the sdk. There is **no 1.3.0 release** — `acl/main` in the
  lab repo is versioned 1.3.0 but is a mirror of upstream `main` after 1.2.0
  (snippets, SEO URL twig function, api catalog, profile link header), none of
  which this plugin uses. Every earlier "≥ 1.3.0" (commit `4e51fe8`, the bridge
  spec, issue #8) meant 1.2.0.
- **This plugin needs only five sdk contracts** — `CapabilityInterface`,
  `CapabilityDescriptor`, `PlatformProfile`, `ProfileBuildInput`,
  `ProfileContributorInterface` — plus the `ucp_sdk.capability` /
  `ucp_sdk.profile_contributor` tags. Unchanged 0.0.2 → 0.0.5.
- **The RFQ feature is SwagCommercial's** (B2B QuoteManagement, what the bridge
  talks to). The UCP layer on top is this plugin's job (#9). The lab repo's
  `test/mandate-on-sdk-compat` branch, which carries a prototype of that layer
  pinned to sdk 0.0.2 by a "do not merge" compatibility commit, is **not** part
  of the shop. Stack: SwagCommercial + Agentic Commerce unmodified + this plugin.
- **The licence is database state**, not environment: `system_config` rows
  `core.store.licenseHost` and `core.store.licenseKey`. A fresh shop needs them
  written after boot; `SwagCommercial`'s `License::get()` reads them at runtime.
- **Quotes have no demo-data generator** in SwagCommercial (only
  `EmployeeGenerator` under B2B). The borrowed shop's database is 37 MB and
  contains everything the fixture needs. A dump is cheap and exact.
- **The dump is a trunk schema.** The image is `6.7.x-dev`. Importing it into a
  pinned `6.7.2.2` release image risks migrations from the future. Pinning the
  *current* image by digest keeps the dump importable and is the exact
  environment the 39 tests are known to pass on.
- **Image facts:** `dockware/shopware@sha256:458696e775b08d0bc9fa38874a6f9a706e16047ba7109934104b271b618f4e3d`,
  arm64, created 2026-05-14, Ubuntu 24.04, PHP 8.2/8.3/8.4 installed with
  `PHP_VERSION` honoured by `/entrypoint.sh`. Host: arm64, Compose v5.1.2. The
  borrowed container maps `80 → 8090` and persists `/var/lib/mysql` and
  `/var/www/html` as named volumes.
- **Mail:** `mailcatcher` runs inside the dockware image (`--ip=0.0.0.0`) and the
  shop has active `action.mail.send` flows on entering `in_review` and
  `replied`. The image already provides the catching mailer #8 demands.
- **Worktrees** do not share untracked files. Anything that must persist across
  worktrees — secrets, downloads, the seed — cannot live inside the checkout.

## Decision

A `docker compose` definition committed to this repo, one shared shop with a
fixed identity, plugins **fetched** from GitHub releases with a hand-copied
fallback, the database **seeded** from a one-time dump of the borrowed shop,
and all persistent state in a cache directory outside any worktree.

### One shop, fixed identity, shared by every worktree

`docker/compose.yaml`:

```yaml
name: merchant-quote-shop            # fixed: every worktree addresses the same project
services:
  shop:
    image: dockware/shopware@sha256:458696e775b08d0bc9fa38874a6f9a706e16047ba7109934104b271b618f4e3d
    container_name: merchant-quote-shop
    ports:
      - "8095:80"                      # coexists with the old shop on 8090 until retired
    environment:
      PHP_VERSION: "8.3"
      XDEBUG_ENABLED: "0"
      SW_TASKS_ENABLED: "0"
    volumes:
      - db:/var/lib/mysql
      - html:/var/www/html
volumes:
  db:
  html:
```

The fixed `name:` is what makes worktrees work: `docker compose -f
docker/compose.yaml …` from any checkout targets the same containers and
volumes, so there is never a second shop, a port clash, or a stale copy. The
compose file carries no secrets, so it needs no `.env`.

### No bind mount; the sync stays

A bind mount of the plugin directory would tie the container to one worktree.
`scripts/sync-to-shop.sh` (tar-copy into `custom/plugins/MerchantQuoteAgentPlugin`)
already lets whichever worktree runs the tests push its code first, and
`composer run test:integration` runs it immediately before PHPUnit, so "the
container holds the last synced worktree" is correct by construction. Only
change: the default `SHOP_CONTAINER` becomes `merchant-quote-shop`.

### Persistent state lives outside the worktree

`MQ_SHOP_HOME`, default `~/.cache/merchant-quote-shop/`:

```
.env                     # SHOP_LICENSE_HOST, SHOP_LICENSE_KEY, SHOP_ADMIN_USER, SHOP_ADMIN_PASSWORD
plugins/SwagCommercial.zip
plugins/SwagAgenticCommerce.zip
seed/shopware.sql.gz
```

The repo holds `docker/compose.yaml`, `docker/.env.example` (the template for
`MQ_SHOP_HOME/.env`, every key documented), and the scripts. Nothing under
`MQ_SHOP_HOME` is ever committed; the seed contains demo customers and the
licence key.

**B's fallback is this directory.** If a developer cannot reach the SwagCommercial
repo, they drop the two zips into `plugins/` by hand and the setup script uses
them; there is no special mode, only "file already present → skip download".

### `scripts/shop-setup.sh` — idempotent, ordered

Each step checks its own postcondition and skips when met, so the script is
safe to rerun after a partial failure.

1. **Home.** Create `MQ_SHOP_HOME`; if `.env` is missing, copy
   `docker/.env.example` there and stop with instructions to fill it. Source it.
2. **Fetch.** `gh release download 7.13.1 -R shopware/SwagCommercial -p SwagCommercial.zip`
   and `gh release download 1.2.0 -R shopware/agentic-commerce -p SwagAgenticCommerce.zip`
   into `plugins/`, each skipped if present. On `gh` failure, print the fallback
   instruction (put the zip there yourself) and stop.
3. **Boot.** `docker compose -f docker/compose.yaml up -d`; wait until
   `bin/console` answers inside the container.
4. **Seed.** If the `quote` table is empty, `gunzip -c seed/shopware.sql.gz |
   docker exec -i … mysql shopware`. If `seed/` is empty, stop and point at
   `shop-export-seed.sh`. The seed is imported *before* plugins are installed
   because it carries the `plugin` table in the borrowed shop's state
   (SwagCommercial 7.13.0, SwagAgenticCommerce 1.1.1 installed and active).
5. **Plugin files.** Unzip both into `custom/plugins/` inside the container;
   `sync-to-shop.sh` puts ours next to them.
6. **Composer.** In the container: `composer require shopware/commercial:7.13.1
   shopware/agentic-commerce:1.2.0 shopware/merchant-quote-agent-plugin:@dev`.
   dockware's `custom/plugins/*` path repositories resolve all three; the sdk
   (≥ 0.0.5) and `cuyz/valinor` come from Packagist. This is the step that
   proves our `composer.json` is installable — the packaging blocker from #3.
7. **Plugins.** `plugin:refresh`; `plugin:update SwagCommercial SwagAgenticCommerce`
   (runs the 7.13.0→7.13.1 and 1.1.1→1.2.0 migrations the seed predates);
   `plugin:install --activate MerchantQuoteAgentPlugin`; `cache:clear`.
8. **Licence.** `system:config:set core.store.licenseHost` and
   `core.store.licenseKey` from `.env`. Skipped when the seed already carries
   them and they match.
9. **Admin.** `user:create` `SHOP_ADMIN_USER` with `SHOP_ADMIN_PASSWORD` if absent
   — the credentials #8 wants shared with the PM, known rather than inherited.
10. **Buyer gate.** For every customer in the seed's test buyer set, ensure
    `customer_specific_features` is the map `{"QUOTE_MANAGEMENT": true}` (an
    array is silently ignored — #9). Implemented as one SQL `UPDATE` guarded by
    a `SELECT`, listed in the script by customer number. Scripted because a
    newly registered buyer cannot request a quote until this is set.
11. **Accept.** Run `composer run test:integration`. The setup is done when the
    39 tests pass against the new container. This is the script's own test.

Everything the script does inside the container goes through `docker exec`;
it never touches another compose project.

### `scripts/shop-export-seed.sh` — run once, now

`mysqldump --single-transaction --routines` from a source container (default
`shopware-trunk`, overridable) to `MQ_SHOP_HOME/seed/shopware.sql.gz`. Run
before the borrowed container disappears; rerun only to refresh the seed
deliberately. It prints row counts for `quote`, `customer`, `product` so the
export is checkable at a glance.

### Harness changes

- `scripts/sync-to-shop.sh`: default `SHOP_CONTAINER=merchant-quote-shop`.
- `composer.json` `test:integration`: same default.
- `tests/Integration/bootstrap.php`: unchanged. `SHOPWARE_ROOT` stays
  `/var/www/html`. The `.env`-parsing and `KERNEL_CLASS` workarounds stay until
  the old shop is retired, then get removed in their own commit with the reason.
- README gets a "Test shop" section: the four commands, the env vars, the
  fallback, and the port.

### Versions, pinned

| Component | Version | Source |
|---|---|---|
| dockware image | digest `458696e7…` (6.7.x-dev, arm64) | Docker Hub |
| PHP default CLI | 8.3 | `PHP_VERSION` |
| SwagCommercial | 7.13.1 | GitHub release zip |
| Agentic Commerce | 1.2.0 | GitHub release zip |
| ucp-php-sdk | ≥ 0.0.5 <0.1.0 | Packagist, via Agentic Commerce |
| This plugin | `@dev` from `custom/plugins` | `sync-to-shop.sh` |

Moving from the digest to a release tag (`dockware/dev:6.7.2.x`) is a later,
separate change: it needs SwagCommercial 7.13's `shopware/core` requirement
confirmed against that release and the seed re-exported from a release-schema
shop.

## Issue #8 requirements, mapped

| #8 asks | This spec |
|---|---|
| PHP 8.3 default CLI, Shopware 6.7 | `PHP_VERSION=8.3`, 6.7.x-dev image |
| SwagCommercial ≥ 7.13, licence toggle active | 7.13.1; licence rows from `.env`; `HarnessSmokeTest` asserts the toggle |
| SwagAgenticCommerce "≥ 1.3.0" | **1.2.0** — the version that actually exists and satisfies our constraint; #8's text needs correcting |
| clean `config/packages` and `.env.test` | fresh dockware image; the stray files were the other project's |
| demo quotes in editable states | the seed: 36 quotes, 29 editable with lines |
| catching mailer | mailcatcher in the image; UI on container port 1080 |
| `SHOP_CONTAINER` / `SHOPWARE_ROOT` documented | README section |
| buyer `QUOTE_MANAGEMENT` gate, scripted | setup step 10 |
| shared with the PM incl. admin credentials | setup step 9 creates known credentials. **Where** the PM reaches the shop is not decided here — see Open questions |
| must actually charge shipping | **verify, do not assume** — see Risks |

## Testing

- The acceptance test is the existing suite: `composer run test:integration`
  green (39 tests, 0 skipped) against `merchant-quote-shop`. Nothing in the
  suite changes; only the container it targets does.
- `HarnessSmokeTest` already proves SwagCommercial's services resolve and the
  licence toggle is on — i.e. steps 5–8 worked.
- Idempotency: running `shop-setup.sh` twice must be a no-op the second time,
  checked by hand once and stated in the script header.
- A fresh-worktree check: `git worktree add`, run `composer run test:integration`
  from it with no other setup, expect green. This is the requirement that shaped
  the design; it gets exercised once before the PR.
- Shop safety carries over unchanged: `DatabaseTransactionBehaviour` rolls every
  test back; mailcatcher holds any mail the flows send.

## Risks and what verifies them

- **Shipping is €0 on trunk.** #8 says the current demo's trunk delivery
  calculator returns €0, which blocks the free-shipping path and the
  non-price-terms decision (#11). The pinned image is trunk too, so it may share
  the defect. Verify in the plan: price a cart against the seed's shipping
  method and record the figure. If it is €0, that is a reason to move to a
  release image sooner, not to hide the finding.
- **`plugin:update` across the seed's versions.** The seed says 7.13.0/1.1.1
  installed; the files are 7.13.1/1.2.0. `plugin:update` is the documented path
  and runs the migrations. Verify by checking `plugin.version` after step 7 and
  running the suite.
- **Packagist availability of the sdk.** The borrowed shop resolved
  `ucp-php-sdk/core` from a GitHub source dist, which is how Packagist serves
  it. If `composer require` cannot find it, the fix is a `vcs` repository entry
  in the shop's root `composer.json`, added by the script — not a change to our
  constraints.
- **The digest is arm64.** A developer on amd64 needs the amd64 digest of the
  same tag; the compose file takes it from `SHOP_IMAGE` with the arm64 digest
  as default. Recorded, not solved, until someone on amd64 needs it.
- **Seed drift.** The seed is a snapshot; the suite's fixtures select quotes by
  state, not by id, so modest drift is tolerated. A re-export is one command.

## Non-goals

- No Dockerfile; nothing is baked into an image (the licence never could be).
- No per-worktree shops. One shop, fixed name, by design.
- No quote demo-data generator. The seed covers it; a generator becomes worth
  writing only when the seed stops importing.
- No production-grade / hosted parity shop. #8's first half (a production-grade
  Shopware as A/B target for the TypeScript app) is a different shop with
  different requirements (real shipping, comparable versions to the demo shop).
  This spec delivers the *dev shop* half of #8; the parity shop is a follow-up
  that can reuse this compose as its base.

## Open questions

1. **Where does the PM reach it?** A compose on a developer laptop is not
   "shared with Juan including admin credentials." Options: run this compose on
   a small VM with the port exposed behind basic auth, or a tunnel from the
   laptop for the test round. Decision needed before 2026-09-04; not this
   spec's to make.
2. **Retire the old shop when?** Once the new shop is green, the bootstrap
   workarounds can go. Proposed: after this PR merges and #17 is rebased onto it.
