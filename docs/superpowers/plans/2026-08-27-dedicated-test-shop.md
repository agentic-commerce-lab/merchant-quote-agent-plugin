# Dedicated Test Shop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `docker compose` test shop defined in this repo that any worktree can bring from nothing to "39 integration tests green" with two commands, replacing the irreproducible borrowed `shopware-trunk` container.

**Architecture:** One dockware container with a fixed compose project name (`merchant-quote-shop`) and named volumes; two bash scripts — `shop-export-seed.sh` (one-time dump of the old shop) and `shop-setup.sh` (idempotent: fetch plugins from GitHub releases, boot, seed, install, licence, admin, buyer gate, run the suite). All persistent state (`.env`, plugin zips, seed) lives in `MQ_SHOP_HOME` (default `~/.cache/merchant-quote-shop`) outside every checkout. The integration harness keeps its tar-copy sync so the container always holds whichever worktree ran the tests last.

**Tech Stack:** Docker Compose v5, `dockware/shopware` (6.7.x-dev, arm64, pinned by digest), PHP 8.3, MySQL 8 (in-container), bash, `gh` CLI, SwagCommercial 7.13.1, Agentic Commerce 1.2.0.

**Spec:** `docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md`

## Global Constraints

- Image: `dockware/shopware@sha256:458696e775b08d0bc9fa38874a6f9a706e16047ba7109934104b271b618f4e3d` (arm64). SwagCommercial 7.13.1 requires `shopware/core >=6.7.13.1 <6.8`; the image's `6.7.x-dev` satisfies it, a `6.7.2.x` release image does not — so the digest pin is a requirement, not a preference.
- Versions, verbatim: SwagCommercial **7.13.1** (`SwagCommercial.zip`, 14 MB), Agentic Commerce **1.2.0** (`SwagAgenticCommerce.zip`, 443 KB, no `vendor/`), `PHP_VERSION=8.3`, container `merchant-quote-shop`, host port **8095** (old shop stays on 8090), mailcatcher UI on host **8096**.
- Nothing secret or heavy is ever committed: `.env`, zips and the seed live only under `MQ_SHOP_HOME`. The repo gets `docker/compose.yaml`, `docker/.env.example`, scripts, docs.
- Everything inside the container goes through `docker exec` on `merchant-quote-shop`. The old `shopware-trunk` container is **read-only** for this work: the export dumps it, nothing writes to it.
- Composer package names, verbatim: `shopware/commercial`, `shopware/agentic-commerce`, `shopware/merchant-quote-agent-plugin` (ours, type `shopware-platform-plugin`, resolved through dockware's `custom/plugins/*` path repositories).
- `docker exec` runs as `www-data` in this image; anything that must write as root says `-u root` explicitly and `chown`s back.
- Shell: `set -euo pipefail`, `bash -n` clean, `shellcheck` clean if `shellcheck` is installed (it is optional — say in the report whether it ran).
- Signed commits are required. If `git commit` fails with `1Password: failed to fill whole buffer`, retry; never `--no-gpg-sign`.
- The acceptance test for the whole plan is the unchanged suite: `composer run test:integration` → 39 tests, 0 skipped, against `merchant-quote-shop`.

---

## File map

| File | Responsibility |
|---|---|
| `scripts/shop-export-seed.sh` (new) | Dump the old shop's DB into `MQ_SHOP_HOME/seed/shopware.sql.gz`, print row counts |
| `docker/compose.yaml` (new) | The shop: fixed project name, pinned image, ports, volumes, env |
| `docker/.env.example` (new) | Template for `MQ_SHOP_HOME/.env`; every key documented |
| `scripts/test-integration.sh` (new) | Sync + run PHPUnit in the container; `SHOP_CONTAINER` default `merchant-quote-shop` |
| `scripts/sync-to-shop.sh` (modify) | Default container → `merchant-quote-shop`; stale comment fixed |
| `composer.json` (modify) | `test:integration` calls the script instead of hardcoding `shopware-trunk` |
| `scripts/shop-setup.sh` (new) | Idempotent bring-up, steps 1–11 of the spec plus the URL rewrite |
| `scripts/shop-check-shipping.sh` (new) | Measures whether the shop charges shipping (spec risk) |
| `README.md` (new) | "Test shop" section: commands, env vars, fallback, ports |
| spec (modify) | Write back measured results (shipping, idempotency, worktree check) |

---

### Task 1: Export the seed from the old shop — now, before it disappears

**Files:**
- Create: `scripts/shop-export-seed.sh`

**Interfaces:**
- Produces: `MQ_SHOP_HOME/seed/shopware.sql.gz` — a full `mysqldump` of database `shopware` with `--add-drop-table`, importable with `mysql shopware < dump`. Consumed by Task 4's `step_seed`.
- Env: `SEED_SOURCE_CONTAINER` (default `shopware-trunk`), `MQ_SHOP_HOME` (default `$HOME/.cache/merchant-quote-shop`).

- [ ] **Step 1: Write the script**

```bash
#!/usr/bin/env bash
# Export the borrowed shop's database as the seed for merchant-quote-shop.
#
# Run once, now, before the shopware-trunk container disappears (its compose
# project no longer exists on disk). Rerun only to refresh the seed on purpose.
# The dump carries demo customers and the SwagCommercial licence key: it lives
# under MQ_SHOP_HOME and is never committed.
set -euo pipefail

SOURCE="${SEED_SOURCE_CONTAINER:-shopware-trunk}"
HOME_DIR="${MQ_SHOP_HOME:-$HOME/.cache/merchant-quote-shop}"
OUT="$HOME_DIR/seed/shopware.sql.gz"

mkdir -p "$HOME_DIR/seed"

docker exec "$SOURCE" mysqldump -uroot -proot -h127.0.0.1 \
  --single-transaction --quick --routines --triggers --add-drop-table \
  shopware 2>/dev/null | gzip > "$OUT"

echo "seed written: $OUT ($(du -h "$OUT" | cut -f1))"
for table in quote customer product; do
  count="$(docker exec "$SOURCE" mysql -uroot -proot -h127.0.0.1 -N -B shopware \
    -e "SELECT COUNT(*) FROM $table" 2>/dev/null)"
  printf '  %-10s %s rows\n' "$table" "$count"
done
```

- [ ] **Step 2: Syntax-check it**

Run: `chmod +x scripts/shop-export-seed.sh && bash -n scripts/shop-export-seed.sh && (command -v shellcheck >/dev/null && shellcheck scripts/shop-export-seed.sh || echo "shellcheck not installed")`
Expected: no output from `bash -n`; shellcheck clean or the "not installed" line.

- [ ] **Step 3: Run it against the old shop**

Run: `scripts/shop-export-seed.sh`
Expected: `seed written: /Users/<you>/.cache/merchant-quote-shop/seed/shopware.sql.gz (…M)` and three row counts. `quote` counts both DAL lanes (71: 36 live + 35 snapshot-lane rows), `customer` 8, `product` 16. If `quote` is 0 or the file is under 1 MB, the dump failed — check `docker ps` shows `shopware-trunk` running.

- [ ] **Step 4: Verify the dump is a complete, importable dump**

Run: `gunzip -c ~/.cache/merchant-quote-shop/seed/shopware.sql.gz | grep -c '^DROP TABLE IF EXISTS' ; gunzip -c ~/.cache/merchant-quote-shop/seed/shopware.sql.gz | grep -m1 'CREATE TABLE `quote`'`
Expected: a table count in the hundreds (a Shopware schema), and the `quote` CREATE line — proving `--add-drop-table` is in effect and SwagCommercial's tables are included.

- [ ] **Step 5: Commit**

```bash
git add scripts/shop-export-seed.sh
git commit -m "feat: export the borrowed shop's database as the test-shop seed"
```

---

### Task 2: The compose file, its env template, and a first boot

**Files:**
- Create: `docker/compose.yaml`
- Create: `docker/.env.example`

**Interfaces:**
- Produces: a container named `merchant-quote-shop`, project `merchant-quote-shop`, `bin/console` reachable via `docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console …`, HTTP on `http://localhost:${SHOP_PORT:-8095}`, mailcatcher UI on `http://localhost:${SHOP_MAIL_PORT:-8096}`. Compose variables `SHOP_IMAGE`, `SHOP_PORT`, `SHOP_MAIL_PORT` with defaults; Task 4 passes `--env-file "$MQ_SHOP_HOME/.env"`.
- `.env.example` keys (Task 4 reads exactly these): `SHOP_LICENSE_HOST`, `SHOP_LICENSE_KEY`, `SHOP_ADMIN_USER`, `SHOP_ADMIN_PASSWORD`, `SHOP_ADMIN_EMAIL`, `SHOP_URL`, `SHOP_PORT`, `SHOP_MAIL_PORT`, `SHOP_BUYERS`.

- [ ] **Step 1: Write `docker/compose.yaml`**

```yaml
# The plugin's dedicated test shop. One shop for every worktree: the fixed
# `name:` makes `docker compose -f docker/compose.yaml …` from any checkout
# address the same project, container and volumes.
#
# The image is pinned by digest on purpose: SwagCommercial 7.13.1 requires
# shopware/core >=6.7.13.1 <6.8, which this 6.7.x-dev build satisfies and a
# 6.7.2.x release image does not; and the seed is a dump of the same build.
# Bring-up is scripts/shop-setup.sh — this file holds no secrets and needs
# no .env of its own.
name: merchant-quote-shop

services:
  shop:
    image: ${SHOP_IMAGE:-dockware/shopware@sha256:458696e775b08d0bc9fa38874a6f9a706e16047ba7109934104b271b618f4e3d}
    container_name: merchant-quote-shop
    ports:
      - "${SHOP_PORT:-8095}:80"      # the old shop keeps 8090 until it is retired
      - "${SHOP_MAIL_PORT:-8096}:1080" # mailcatcher UI: the flows on in_review/replied send mail here
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

- [ ] **Step 2: Write `docker/.env.example`**

```bash
# Template for the test shop's private settings.
#   cp docker/.env.example ~/.cache/merchant-quote-shop/.env   # then fill the blanks
# scripts/shop-setup.sh reads that copy (path overridable with MQ_SHOP_HOME).
# The copy is never committed: it holds the SwagCommercial licence.

# SwagCommercial licence, written to system_config by shop-setup.sh
# (core.store.licenseHost / core.store.licenseKey). From the shop owner's
# Shopware account.
SHOP_LICENSE_HOST=
SHOP_LICENSE_KEY=

# Admin login shop-setup.sh creates — the credentials shared with the PM for
# the test round (#8). Known, rather than inherited from the seed.
SHOP_ADMIN_USER=mqadmin
SHOP_ADMIN_PASSWORD=
SHOP_ADMIN_EMAIL=mqadmin@example.com

# Where the shop answers. shop-setup.sh rewrites APP_URL and the sales-channel
# domains to SHOP_URL, so change both lines together if you expose the shop
# elsewhere (a VM, a tunnel). Compose maps SHOP_PORT:80 and SHOP_MAIL_PORT:1080.
SHOP_URL=http://localhost:8095
SHOP_PORT=8095
SHOP_MAIL_PORT=8096

# Customers whose QUOTE_MANAGEMENT gate shop-setup.sh guarantees, by customer
# number. Without the gate a customer cannot request a quote (#9). These four
# already carry it in the seed; add numbers here to gate more.
SHOP_BUYERS="TRUNK-0001 SWDEMO10000 10005 10000"
```

- [ ] **Step 3: Validate the compose file**

Run: `docker compose -f docker/compose.yaml config | grep -E 'name:|image:|container_name|"80(95|96)|PHP_VERSION'`
Expected: `name: merchant-quote-shop`, the digest image, `container_name: merchant-quote-shop`, both port mappings, `PHP_VERSION: "8.3"`. If `config` errors, the YAML is wrong — fix before continuing.

- [ ] **Step 4: Boot it and prove PHP 8.3 is the default CLI**

Run:
```bash
docker compose -f docker/compose.yaml up -d
for i in $(seq 1 60); do docker exec -w /var/www/html merchant-quote-shop php bin/console about >/dev/null 2>&1 && break; sleep 2; done
docker exec merchant-quote-shop php -v | head -1
docker exec merchant-quote-shop sh -c 'id -un; ls /var/www/html/custom/plugins'
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8095/
curl -s http://localhost:8096/messages
```
Expected: `PHP 8.3.31 …` (not 8.2), `www-data`, an empty `custom/plugins` listing (fresh volume — the seed and plugins come in Task 4/5), HTTP `200` or `302` from the shop, `[]` from mailcatcher. First `up` pulls nothing new if the digest is local (it is — the old shop uses the same image); a pull means the digest differs from what `docker images` shows.

- [ ] **Step 5: Commit**

```bash
git add docker/compose.yaml docker/.env.example
git commit -m "feat: docker compose definition for the dedicated test shop"
```

---

### Task 3: Point the integration harness at a configurable container

**Files:**
- Create: `scripts/test-integration.sh`
- Modify: `scripts/sync-to-shop.sh:6` (default) and its trailing comment (lines 17–21)
- Modify: `composer.json` scripts `test:integration`

**Interfaces:**
- Consumes: nothing new.
- Produces: `composer run test:integration [-- <phpunit args>]` runs against `${SHOP_CONTAINER:-merchant-quote-shop}`; `SHOP_CONTAINER=shopware-trunk composer run test:integration` still targets the old shop, which is how this task is verified before the new shop is ready. Task 4/5 call `scripts/sync-to-shop.sh` and `composer run test:integration` with `SHOP_CONTAINER` set.

- [ ] **Step 1: Write `scripts/test-integration.sh`**

```bash
#!/usr/bin/env bash
# Sync this checkout into the test shop, then run the integration suite there.
# Any worktree can run this: the sync pushes *this* checkout's files, so the
# container always holds the code of whoever ran the tests last.
#
#   composer run test:integration                       # against merchant-quote-shop
#   composer run test:integration -- --filter Fetch     # phpunit args pass through
#   SHOP_CONTAINER=shopware-trunk composer run test:integration   # another shop
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
cd "$(dirname "$0")/.."

SHOP_CONTAINER="$CONTAINER" scripts/sync-to-shop.sh
docker exec -w /var/www/html/custom/plugins/MerchantQuoteAgentPlugin "$CONTAINER" \
  php8.3 /var/www/html/vendor/bin/phpunit -c phpunit.integration.xml.dist "$@"
```

- [ ] **Step 2: Change the sync script's default and its stale comment**

In `scripts/sync-to-shop.sh` replace line 6:
```bash
CONTAINER="${SHOP_CONTAINER:-shopware-trunk}"
```
with
```bash
CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
```
and replace the closing comment block (the four lines beginning `# Not installed as a Shopware plugin:` through `# ... aren't satisfiable in this shop's vendor tree anyway.`) with:
```bash
# In merchant-quote-shop the plugin IS installed (shop-setup.sh requires it
# through dockware's custom/plugins/* composer path repository), so this sync
# only refreshes its source files in place; the path is what composer's
# autoload points at. Against the old shopware-trunk container it was never
# installed and the integration bootstrap autoloads it by PSR-4 instead.
```

- [ ] **Step 3: Point composer at the script**

In `composer.json`, replace the `test:integration` value
```json
"test:integration": "scripts/sync-to-shop.sh && docker exec -w /var/www/html/custom/plugins/MerchantQuoteAgentPlugin shopware-trunk php8.3 /var/www/html/vendor/bin/phpunit -c phpunit.integration.xml.dist",
```
with
```json
"test:integration": "scripts/test-integration.sh",
```

- [ ] **Step 4: Syntax-check and prove the old shop still works through the new path**

Run: `chmod +x scripts/test-integration.sh && bash -n scripts/test-integration.sh scripts/sync-to-shop.sh && SHOP_CONTAINER=shopware-trunk composer run test:integration 2>&1 | grep -E "^OK|FAIL|Tests:"`
Expected: `Tests: 39, Assertions: …` with `OK` — identical to before this task. Then `composer run test:integration -- --filter HarnessSmokeTest 2>&1 | grep Tests:` against the default container is **expected to fail** right now (the new shop has no SwagCommercial yet) — confirm it fails for that reason (`Commercial service … could not be resolved`), not with a script error.

- [ ] **Step 5: Run the quality gates that see these files**

Run: `composer run quality:depcheck && composer run format:check && echo gates-ok`
Expected: `gates-ok`. (`composer.json` changed; depcheck must still report no issues.)

- [ ] **Step 6: Commit**

```bash
git add scripts/test-integration.sh scripts/sync-to-shop.sh composer.json
git commit -m "feat: run the integration suite against a configurable shop container"
```

---

### Task 4: `shop-setup.sh` part 1 — home, fetch, boot, seed, URLs

**Files:**
- Create: `scripts/shop-setup.sh`

**Interfaces:**
- Consumes: `docker/compose.yaml`, `docker/.env.example` (Task 2), the seed (Task 1).
- Produces: functions `log`, `die`, `in_shop`, `console`, `sql`, `step_home`, `step_fetch`, `step_boot`, `step_seed`, `step_urls` and a `main` that calls them in order; Task 5 appends more `step_*` functions and extends `main`. Variables set by `step_home`: `HOME_DIR`, everything from `.env`, `SHOP_URL`. Constants: `CONTAINER`, `SWAG_COMMERCIAL_VERSION`, `AGENTIC_COMMERCE_VERSION`, `REPO`.
- After this task: the container is up, seeded (36 live quotes), `APP_URL` and both `sales_channel_domain` rows point at `SHOP_URL`.

- [ ] **Step 1: Write the script**

```bash
#!/usr/bin/env bash
# Bring merchant-quote-shop from nothing to "integration suite green".
#
# Idempotent: every step checks its own postcondition and skips when it already
# holds, so rerun this after any failure. Design and the reasons behind each
# step: docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md
#
#   cp docker/.env.example ~/.cache/merchant-quote-shop/.env   # fill it in
#   scripts/shop-setup.sh
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
HOME_DIR="${MQ_SHOP_HOME:-$HOME/.cache/merchant-quote-shop}"
CONTAINER="merchant-quote-shop"
SWAG_COMMERCIAL_VERSION="7.13.1"
AGENTIC_COMMERCE_VERSION="1.2.0"

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'shop-setup: %s\n' "$*" >&2; exit 1; }
in_shop() { docker exec -w /var/www/html "$CONTAINER" "$@"; }
console() { in_shop php8.3 bin/console "$@"; }
# Query the shop DB; prints nothing (and fails) when the table does not exist yet.
sql() { in_shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "$1" 2>/dev/null; }

step_home() {
  mkdir -p "$HOME_DIR/plugins" "$HOME_DIR/seed"
  if [ ! -f "$HOME_DIR/.env" ]; then
    cp "$REPO/docker/.env.example" "$HOME_DIR/.env"
    die "created $HOME_DIR/.env from docker/.env.example — fill SHOP_LICENSE_HOST, SHOP_LICENSE_KEY and SHOP_ADMIN_PASSWORD, then rerun"
  fi
  set -a
  # shellcheck disable=SC1091
  . "$HOME_DIR/.env"
  set +a
  : "${SHOP_LICENSE_HOST:?set it in $HOME_DIR/.env}"
  : "${SHOP_LICENSE_KEY:?set it in $HOME_DIR/.env}"
  : "${SHOP_ADMIN_PASSWORD:?set it in $HOME_DIR/.env}"
  SHOP_URL="${SHOP_URL:-http://localhost:8095}"
  SHOP_ADMIN_USER="${SHOP_ADMIN_USER:-mqadmin}"
  SHOP_ADMIN_EMAIL="${SHOP_ADMIN_EMAIL:-mqadmin@example.com}"
  SHOP_BUYERS="${SHOP_BUYERS:-}"
}

# fetch_release <github repo> <tag> <asset>: skip when the asset is already in
# MQ_SHOP_HOME/plugins — which is also the hand-copied fallback for anyone
# without access to the release.
fetch_release() {
  local target="$HOME_DIR/plugins/$3"
  if [ -f "$target" ]; then log "have $3"; return; fi
  log "downloading $3 from $1 $2"
  gh release download "$2" -R "$1" -p "$3" -D "$HOME_DIR/plugins" \
    || die "could not download $3 (no gh auth for $1?). Put the file at $target yourself and rerun"
}

step_fetch() {
  fetch_release shopware/SwagCommercial   "$SWAG_COMMERCIAL_VERSION"  SwagCommercial.zip
  fetch_release shopware/agentic-commerce "$AGENTIC_COMMERCE_VERSION" SwagAgenticCommerce.zip
}

step_boot() {
  log "starting $CONTAINER"
  docker compose -f "$REPO/docker/compose.yaml" --env-file "$HOME_DIR/.env" up -d
  local i
  for i in $(seq 1 60); do
    if console about >/dev/null 2>&1; then return; fi
    sleep 2
  done
  die "$CONTAINER did not answer bin/console within 120s (docker logs $CONTAINER)"
}

step_seed() {
  local quotes
  quotes="$(sql 'SELECT COUNT(*) FROM quote' || true)"
  if [ "${quotes:-0}" -gt 0 ]; then log "database already seeded ($quotes quote rows)"; return; fi
  [ -f "$HOME_DIR/seed/shopware.sql.gz" ] \
    || die "no seed at $HOME_DIR/seed/shopware.sql.gz — run scripts/shop-export-seed.sh against the old shop first"
  log "importing seed"
  gunzip -c "$HOME_DIR/seed/shopware.sql.gz" \
    | docker exec -i "$CONTAINER" mysql -uroot -proot -h127.0.0.1 shopware
}

# The seed points at the old shop (localhost:8090). Admin and storefront only
# work from the host when APP_URL and the sales-channel domains match the URL
# you actually open, so both follow SHOP_URL.
step_urls() {
  local port
  port="$(printf '%s' "$SHOP_URL" | sed -nE 's#^https?://[^/:]+:([0-9]+)/?$#\1#p')"
  log "pointing the shop at $SHOP_URL"
  in_shop sed -i "s#^APP_URL=.*#APP_URL=${SHOP_URL}#" .env
  sql "UPDATE sales_channel_domain SET url='${SHOP_URL}' WHERE url LIKE 'http://localhost:%' OR url='${SHOP_URL}'"
  if [ -n "$port" ]; then
    sql "UPDATE sales_channel_domain SET url='http://host.docker.internal:${port}' WHERE url LIKE 'http://host.docker.internal:%'"
  fi
  console cache:clear -n >/dev/null
}

main() {
  step_home
  step_fetch
  step_boot
  step_seed
  step_urls
  log "part 1 done: seeded and reachable at $SHOP_URL"
}

main "$@"
```

- [ ] **Step 2: Syntax-check**

Run: `chmod +x scripts/shop-setup.sh && bash -n scripts/shop-setup.sh && (command -v shellcheck >/dev/null && shellcheck scripts/shop-setup.sh || echo "shellcheck not installed")`
Expected: clean.

- [ ] **Step 3: First run creates `.env` and stops with instructions**

Run: `mv ~/.cache/merchant-quote-shop/.env ~/.cache/merchant-quote-shop/.env.bak 2>/dev/null; scripts/shop-setup.sh; echo "exit=$?"`
Expected: `shop-setup: created …/.env from docker/.env.example — fill … then rerun` and `exit=1`. Then restore or fill: `mv ~/.cache/merchant-quote-shop/.env.bak ~/.cache/merchant-quote-shop/.env` if you had one, otherwise edit the new `.env` and set `SHOP_LICENSE_HOST`, `SHOP_LICENSE_KEY` (values from the old shop: `docker exec shopware-trunk mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "SELECT configuration_key, JSON_UNQUOTE(JSON_EXTRACT(configuration_value,'$._value')) FROM system_config WHERE configuration_key IN ('core.store.licenseHost','core.store.licenseKey')"` — copy them, print nothing into the report) and a `SHOP_ADMIN_PASSWORD`.

- [ ] **Step 4: Second run fetches, boots, seeds, rewrites URLs**

Run: `scripts/shop-setup.sh`
Expected, in order: `downloading SwagCommercial.zip …`, `downloading SwagAgenticCommerce.zip …` (or `have …` if Task 2's boot left them — it did not), `starting merchant-quote-shop`, `importing seed`, `pointing the shop at http://localhost:8095`, `part 1 done`. Runtime: the import is ~37 MB of SQL, expect 1–3 minutes.

- [ ] **Step 5: Verify the postconditions directly**

Run:
```bash
ls -la ~/.cache/merchant-quote-shop/plugins/
docker exec merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "SELECT (SELECT COUNT(*) FROM quote WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')), (SELECT COUNT(*) FROM customer), (SELECT GROUP_CONCAT(url) FROM sales_channel_domain)" 2>/dev/null
docker exec merchant-quote-shop grep '^APP_URL' /var/www/html/.env
```
Expected: `SwagCommercial.zip` (~14 MB) and `SwagAgenticCommerce.zip` (~443 KB); `36  8  http://host.docker.internal:8095,http://localhost:8095` (order may differ); `APP_URL=http://localhost:8095`.

- [ ] **Step 6: Rerun is a no-op**

Run: `scripts/shop-setup.sh 2>&1 | grep -E '^==>'`
Expected: `have SwagCommercial.zip`, `have SwagAgenticCommerce.zip`, `starting …` (compose reports the container as running, no restart), `database already seeded (… quote rows)`, `pointing the shop at …`, `part 1 done`. No `importing seed`.

- [ ] **Step 7: Commit**

```bash
git add scripts/shop-setup.sh
git commit -m "feat: shop-setup.sh brings the test shop up and seeds it"
```

---

### Task 5: `shop-setup.sh` part 2 — plugin files, composer, install, licence, admin, buyer gate, acceptance

**Files:**
- Modify: `scripts/shop-setup.sh` (append functions before `main`, extend `main`)

**Interfaces:**
- Consumes: Task 4's helpers and `step_*` functions; `scripts/sync-to-shop.sh` (Task 3); `composer run test:integration` (Task 3).
- Produces: a shop where `plugin` rows read `SwagCommercial 7.13.1 active`, `SwagAgenticCommerce 1.2.0 active`, `MerchantQuoteAgentPlugin active`; `system_config` holds the licence; admin `SHOP_ADMIN_USER` exists; every `SHOP_BUYERS` customer has `{"QUOTE_MANAGEMENT": true}`; and the 39-test suite is green against it. This is the spec's definition of done.

- [ ] **Step 1: Append the functions (insert directly above `main() {`)**

```bash
# Plugin sources go into the html volume from the zips. Unzip as root because
# docker cp lands the zip owned by root, then hand the tree to www-data, which
# is who runs PHP here.
step_plugin_files() {
  local name
  for name in SwagCommercial SwagAgenticCommerce; do
    if in_shop test -f "custom/plugins/$name/composer.json"; then log "have plugin files: $name"; continue; fi
    log "unpacking $name"
    docker cp "$HOME_DIR/plugins/$name.zip" "$CONTAINER:/tmp/$name.zip"
    docker exec -u root -w /var/www/html/custom/plugins "$CONTAINER" sh -c \
      "rm -rf '$name' && unzip -q '/tmp/$name.zip' && rm '/tmp/$name.zip' && chown -R www-data:www-data '$name'"
  done
  SHOP_CONTAINER="$CONTAINER" "$REPO/scripts/sync-to-shop.sh"
}

# dockware's root composer.json already lists custom/plugins/* as path
# repositories, so requiring the three packages resolves them from the files
# above and pulls ucp-php-sdk (>=0.0.5) and cuyz/valinor from Packagist. This
# is the step that proves this plugin's composer.json is installable.
step_composer() {
  if in_shop composer show shopware/merchant-quote-agent-plugin >/dev/null 2>&1; then log "composer packages present"; return; fi
  log "composer require (this takes a few minutes)"
  in_shop env COMPOSER_MEMORY_LIMIT=-1 composer require --no-interaction \
    "shopware/commercial:${SWAG_COMMERCIAL_VERSION}" \
    "shopware/agentic-commerce:${AGENTIC_COMMERCE_VERSION}" \
    "shopware/merchant-quote-agent-plugin:@dev"
}

# The seed says SwagCommercial 7.13.0 and SwagAgenticCommerce 1.1.1 are
# installed and active; the files are 7.13.1 and 1.2.0. plugin:update runs the
# migrations in between. Ours was never installed in the old shop.
step_plugins() {
  log "refreshing, updating and installing plugins"
  console plugin:refresh -n >/dev/null
  console plugin:update SwagCommercial SwagAgenticCommerce -n
  if [ "$(sql "SELECT COUNT(*) FROM plugin WHERE name='MerchantQuoteAgentPlugin' AND active=1")" = "1" ]; then
    log "MerchantQuoteAgentPlugin already active"
  else
    console plugin:install --activate MerchantQuoteAgentPlugin -n
  fi
  console cache:clear -n >/dev/null
}

# SwagCommercial reads the licence from system_config at runtime; the seed
# carries the old shop's values, this makes them the ones from .env.
step_license() {
  log "writing the licence"
  console system:config:set core.store.licenseHost "$SHOP_LICENSE_HOST" -n >/dev/null
  console system:config:set core.store.licenseKey "$SHOP_LICENSE_KEY" -n >/dev/null
}

step_admin() {
  if [ "$(sql "SELECT COUNT(*) FROM user WHERE username='${SHOP_ADMIN_USER}'")" = "1" ]; then log "admin ${SHOP_ADMIN_USER} exists"; return; fi
  log "creating admin ${SHOP_ADMIN_USER}"
  console user:create "$SHOP_ADMIN_USER" --admin --password="$SHOP_ADMIN_PASSWORD" \
    --email="$SHOP_ADMIN_EMAIL" --firstName=Merchant --lastName=Quote -n >/dev/null
}

# customer_specific_features.features must be the MAP {"QUOTE_MANAGEMENT": true};
# an array is silently ignored and the only symptom is a 403 (#9).
step_buyer_gate() {
  local number id gated
  for number in $SHOP_BUYERS; do
    id="$(sql "SELECT LOWER(HEX(id)) FROM customer WHERE customer_number='${number}'")"
    [ -n "$id" ] || die "buyer ${number} (SHOP_BUYERS) is not in the seed"
    gated="$(sql "SELECT JSON_EXTRACT(features,'\$.QUOTE_MANAGEMENT') FROM customer_specific_features WHERE customer_id=UNHEX('${id}')")"
    if [ "$gated" = "true" ]; then continue; fi
    log "gating ${number} for QUOTE_MANAGEMENT"
    if [ -n "$gated" ] || [ "$(sql "SELECT COUNT(*) FROM customer_specific_features WHERE customer_id=UNHEX('${id}')")" = "1" ]; then
      sql "UPDATE customer_specific_features SET features=JSON_SET(COALESCE(features,'{}'),'\$.QUOTE_MANAGEMENT',true), updated_at=NOW(3) WHERE customer_id=UNHEX('${id}')"
    else
      sql "INSERT INTO customer_specific_features (id, customer_id, features, created_at) VALUES (UNHEX(REPLACE(UUID(),'-','')), UNHEX('${id}'), JSON_OBJECT('QUOTE_MANAGEMENT', true), NOW(3))"
    fi
  done
}

step_accept() {
  log "acceptance: the integration suite against $CONTAINER"
  (cd "$REPO" && SHOP_CONTAINER="$CONTAINER" composer run test:integration)
}
```

- [ ] **Step 2: Extend `main`**

Replace the body of `main()` with:
```bash
main() {
  step_home
  step_fetch
  step_boot
  step_seed
  step_urls
  step_plugin_files
  step_composer
  step_plugins
  step_license
  step_admin
  step_buyer_gate
  step_accept
  log "done: $CONTAINER is green. Admin: $SHOP_URL/admin ($SHOP_ADMIN_USER). Mail: http://localhost:${SHOP_MAIL_PORT:-8096}"
}
```

- [ ] **Step 3: Syntax-check**

Run: `bash -n scripts/shop-setup.sh && (command -v shellcheck >/dev/null && shellcheck scripts/shop-setup.sh || echo "shellcheck not installed")`
Expected: clean.

- [ ] **Step 4: Run it**

Run: `scripts/shop-setup.sh 2>&1 | tee /tmp/shop-setup.log | grep -E '^==>|^OK|FAIL|Tests:|shop-setup:'`
Expected: the part-1 `have …`/`already seeded` lines, then `unpacking SwagCommercial`, `unpacking SwagAgenticCommerce`, `synced to merchant-quote-shop:…`, `composer require (this takes a few minutes)`, `refreshing, updating and installing plugins`, `writing the licence`, `creating admin mqadmin`, no `gating …` lines (the four default buyers are gated in the seed), `acceptance: …`, `Tests: 39, Assertions: …` with `OK`, `done: merchant-quote-shop is green`.

If `composer require` fails resolving `ucp-php-sdk/*`: the spec's fallback is a `vcs` repository entry for `https://github.com/agentic-commerce-alliance/ucp-php-sdk-core.git` (and `…-symfony-bundle.git`) added to the shop's root `composer.json` via `in_shop composer config repositories.ucp-core vcs <url>` **inside `step_composer` before the require** — not a change to this plugin's constraints. Record which happened.

If `plugin:update` refuses the version jump, stop and report the exact message with `docker exec merchant-quote-shop mysql … -e "SELECT name, version, upgrade_version, active FROM plugin"`; do not work around it with SQL.

- [ ] **Step 5: Verify the postconditions directly, not through the log**

Run:
```bash
docker exec merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "SELECT name, version, active FROM plugin ORDER BY name" 2>/dev/null
docker exec merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "SELECT username FROM user WHERE admin=1 ORDER BY username" 2>/dev/null | tr '\n' ' '; echo
docker exec merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "SELECT c.customer_number, f.features FROM customer c JOIN customer_specific_features f ON f.customer_id=c.id ORDER BY c.customer_number" 2>/dev/null
docker exec -w /var/www/html merchant-quote-shop composer show 2>/dev/null | grep -E 'ucp-php-sdk|cuyz/valinor|shopware/(commercial|agentic|merchant)'
```
Expected: `MerchantQuoteAgentPlugin <ver> 1`, `SwagAgenticCommerce 1.2.0 1`, `SwagCommercial 7.13.1 1`; admins `admin mqadmin ucpadmin`; four gated rows each `{"QUOTE_MANAGEMENT": true}`; `ucp-php-sdk/core 0.0.5`, `ucp-php-sdk/symfony-bundle 0.0.5`, `cuyz/valinor 2.x`, and the three plugins. **This is the moment the #3 packaging blocker is closed** — say so in the report.

- [ ] **Step 6: Prove `services.php` compiled — the one thing #3 could not verify**

Run: `docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console debug:container 'MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface' 2>&1 | grep -E 'Service ID|Class|Factory' ; docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console debug:container --tag=ucp_sdk.capability 2>&1 | grep -i quote`
Expected: the gateway service listed with `QuoteGatewayFactory` as factory, and `QuoteCapability` under the `ucp_sdk.capability` tag. A `ServiceNotFoundException` here is a real finding about `src/Resources/config/services.php` — report it verbatim; do not patch the shop.

- [ ] **Step 7: Rerun is a no-op that still ends green**

Run: `scripts/shop-setup.sh 2>&1 | grep -E '^==>|Tests:'`
Expected: every step reports `have …`/`already …`/`present`/`exists` (`plugin:update` and the licence writes run again harmlessly), then `Tests: 39` green and `done:`.

- [ ] **Step 8: Commit**

```bash
git add scripts/shop-setup.sh
git commit -m "feat: shop-setup.sh installs the plugins, licence, admin and buyer gate, then runs the suite"
```

---

### Task 6: Measure the spec's open risks — shipping, fresh worktree — and write the results back

**Files:**
- Create: `scripts/shop-check-shipping.sh`
- Modify: `docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md` (Risks section)

**Interfaces:**
- Consumes: the green shop from Task 5.
- Produces: measured figures in the spec; the shipping script for #11 to reuse.

- [ ] **Step 1: Write the shipping probe**

```bash
#!/usr/bin/env bash
# Does this shop charge shipping? Issue #8 says the old demo shop's trunk
# delivery calculator returned €0, which blocks the free-shipping path and the
# non-price-terms decision (#11). This puts one standalone product in a
# store-api cart and prints what the shop charges for delivery.
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
URL="${SHOP_URL:-http://localhost:8095}"

sql() { docker exec "$CONTAINER" mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "$1" 2>/dev/null; }

KEY="$(sql "SELECT access_key FROM sales_channel WHERE active=1 ORDER BY created_at LIMIT 1")"
PRODUCT="$(sql "SELECT LOWER(HEX(id)) FROM product WHERE parent_id IS NULL AND COALESCE(child_count,0)=0 AND active=1 ORDER BY product_number LIMIT 1")"
[ -n "$KEY" ] && [ -n "$PRODUCT" ] || { echo "no active sales channel or standalone product in $CONTAINER" >&2; exit 1; }

TOKEN="$(curl -sf "$URL/store-api/context" -H "sw-access-key: $KEY" \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')"

curl -sf -X POST "$URL/store-api/checkout/cart/line-item" \
  -H "sw-access-key: $KEY" -H "sw-context-token: $TOKEN" -H 'Content-Type: application/json' \
  -d "{\"items\":[{\"type\":\"product\",\"referencedId\":\"$PRODUCT\",\"quantity\":1}]}" \
  | php -r '
    $cart = json_decode(stream_get_contents(STDIN));
    $delivery = $cart->deliveries[0] ?? null;
    printf("shipping: %s via %s | cart total: %s | product %s\n",
      $delivery?->shippingCosts?->totalPrice ?? "n/a",
      $delivery?->shippingMethod?->name ?? "n/a",
      $cart->price->totalPrice ?? "n/a",
      $cart->lineItems[0]->label ?? "n/a");'
```

- [ ] **Step 2: Run it against the new shop**

Run: `chmod +x scripts/shop-check-shipping.sh && bash -n scripts/shop-check-shipping.sh && scripts/shop-check-shipping.sh`
Expected: one line like `shipping: 4.99 via Standard | cart total: … | product …` — or `shipping: 0 …`. Either is a result; record the exact line. If `curl` fails with 4xx, print the body without `-f` to see the store-api error and report it.

- [ ] **Step 3: The fresh-worktree check — the requirement that shaped the design**

Run:
```bash
git worktree add /tmp/mq-worktree-check feat/8-dedicated-test-shop
(cd /tmp/mq-worktree-check && composer run test:integration 2>&1 | grep -E "synced to|^OK|FAIL|Tests:")
git worktree remove /tmp/mq-worktree-check
```
Expected: `synced to merchant-quote-shop:…` followed by `Tests: 39 … OK` — from a checkout that has no `vendor/`, no `.env`, nothing but the repo files. (`composer run` needs no vendor for a script that only shells out.) If it fails because `composer` refuses to run scripts without `vendor/`, the fallback is `scripts/test-integration.sh` directly — record which worked.

- [ ] **Step 4: Write the measurements into the spec's Risks section**

In `docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md`, under `## Risks and what verifies them`, replace the `**Shipping is €0 on trunk.**` bullet's last sentence (`If it is €0, … not to hide the finding.`) with a sentence stating the measured line from Step 2 and the date, e.g. `Measured 2026-08-27 with scripts/shop-check-shipping.sh: "<the line>". <€0 → "so the parity shop (#8's first half) must run a release image"; >0 → "so this shop can exercise the paid-shipping path">`. Under `## Testing`, after the fresh-worktree bullet, add one sentence: `Done 2026-08-27: a worktree at /tmp with no vendor ran composer run test:integration green against merchant-quote-shop.` Under `## Issue #8 requirements, mapped`, change the shipping row's cell to the measured verdict.

- [ ] **Step 5: Commit**

```bash
git add scripts/shop-check-shipping.sh docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md
git commit -m "test: measure shipping on the new shop and prove a fresh worktree runs the suite"
```

---

### Task 7: README, spec write-back for what changed in execution, PR

**Files:**
- Create: `README.md`
- Modify: `docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md` (only where execution deviated)

**Interfaces:**
- Consumes: everything above.
- Produces: the documented new-developer path, and the PR.

- [ ] **Step 1: Write `README.md`**

```markdown
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
```

- [ ] **Step 2: Reconcile the spec with what actually happened**

Re-read the spec's `### scripts/shop-setup.sh` section against the script as committed. Two known deviations to write in: (a) the **URL rewrite step** (`step_urls`) exists and why (the seed's `localhost:8090` domains); (b) if the Packagist fallback or any plugin:update detail from Task 5 Step 4 was needed, state it. Add a `## Execution notes (2026-08-27)` section at the end with the three commands' real runtimes, the composer resolution outcome, and the `debug:container` result from Task 5 Step 6.

- [ ] **Step 3: Full local gate sweep**

Run: `for g in format:check lint typecheck quality:filesize quality:dupes quality:depcheck quality:security; do composer run $g >/dev/null 2>&1 && echo "$g ok" || echo "$g FAILED"; done; vendor/bin/phpunit 2>&1 | grep -E '^OK|FAIL'; composer run test:integration 2>&1 | grep -E 'Tests:|^OK|FAIL'`
Expected: seven `ok`, `OK (72 tests …)`, `Tests: 39 … OK`.

- [ ] **Step 4: Commit and push**

```bash
git add README.md docs/superpowers/specs/2026-08-27-dedicated-test-shop-design.md
git commit -m "docs: test shop quickstart and execution notes"
git push -u origin feat/8-dedicated-test-shop
```

- [ ] **Step 5: Open the PR**

If PR #17 has merged: `git rebase origin/main` first (resolve nothing — the branches touch different files apart from the spec, which #17 does not modify after `ec54b8d`), force-with-lease push, base `main`. Otherwise base `feat/shopware-bridge-quote-services` and say in the body that it retargets to `main` after #17.

```bash
gh pr create --base main --title "Dedicated docker compose test shop (issue #8)" --body "$(cat <<'EOF'
Closes the dev-shop half of #8.

One `docker compose` shop defined in this repo — fixed project name so every worktree shares it — brought up by an idempotent `scripts/shop-setup.sh`: plugins fetched from the SwagCommercial 7.13.1 and Agentic Commerce 1.2.0 GitHub releases (hand-copied fallback), database seeded from a dump of the old `shopware-trunk` container, licence/admin/buyer-gate scripted, ending by running the 39-test integration suite against itself.

Closes the #3 packaging blocker for real: `composer require shopware/merchant-quote-agent-plugin` resolves in this shop (ucp-php-sdk 0.0.5, cuyz/valinor), the plugin installs, and `debug:container` shows `services.php` compiled.

Measured, not assumed (see spec Risks): shipping on this image, and a fresh worktree with no vendor running the suite green.

Not in here: where the PM reaches the shop (laptop compose vs VM/tunnel — open question in the spec), and the production-grade parity shop from #8's first half.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: a PR URL. Report it.
