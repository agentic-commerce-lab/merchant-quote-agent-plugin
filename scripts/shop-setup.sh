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

# `.` below expands an unquoted $, so a licence key can arrive truncated and a
# password empty. Compare what bash read against what the file literally says.
raw_env_value() {
    awk -v k="$1" 'index($0, k "=") == 1 { sub(/^[^=]*=/, ""); print; exit }' "$HOME_DIR/.env"
}
strip_quotes() {
    local v="$1"; v="${v%\'}"; v="${v#\'}"; v="${v%\"}"; v="${v#\"}"; printf '%s' "$v"
}

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
  for key in SHOP_LICENSE_KEY SHOP_ADMIN_PASSWORD; do
      if [ "$(strip_quotes "$(raw_env_value "$key")")" != "${!key}" ]; then
          die "$key was altered while reading $HOME_DIR/.env — single-quote it (see docker/.env.example)"
      fi
  done
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
  docker compose -f "$REPO/docker/compose.yaml" up -d
  local i
  for i in $(seq 1 60); do
    if console about >/dev/null 2>&1; then return; fi
    sleep 2
  done
  die "$CONTAINER did not answer bin/console within 120s (docker logs $CONTAINER)"
}

step_seed() {
  local quotes seed
  seed="$HOME_DIR/seed/shopware.sql.gz"
  quotes="$(sql 'SELECT COUNT(*) FROM quote' || true)"
  if [ "${quotes:-0}" -gt 0 ]; then log "database already seeded ($quotes quote rows)"; return; fi
  [ -f "$seed" ] \
    || die "no seed at $seed — run scripts/shop-export-seed.sh against the old shop first"
  # A failed export leaves a valid but near-empty gzip behind, which -f alone accepts.
  gzip -t "$seed" 2>/dev/null \
    || die "$seed is not a valid gzip — re-run scripts/shop-export-seed.sh"
  [ "$(wc -c < "$seed")" -gt 1000000 ] \
    || die "$seed is only $(wc -c < "$seed") bytes; a real seed is ~1.7 MB — re-run scripts/shop-export-seed.sh"
  log "importing seed"
  gunzip -c "$seed" | docker exec -i "$CONTAINER" mysql -uroot -proot -h127.0.0.1 shopware
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

# The seed is a dump of the dev-trunk shop: its migration table reaches
# 1784518893 while the image's baked v6.7.10.0 core only ships up to
# 1777362745, so the imported schema is ahead of this code. dev-trunk is also
# what makes SwagCommercial 7.13.x resolvable at all (it requires
# shopware/core >=6.7.13.0, and the image pins v6.7.10.0). Moving core to
# dev-trunk reproduces exactly the shop the seed came from, where these 39
# tests already pass.
step_core() {
    local core_versions
    # Capture first, then grep: under `set -o pipefail`, `grep -q` closing the
    # pipe on its first match SIGPIPEs the still-writing composer process,
    # which turns a real match into a false pipeline failure.
    core_versions="$(in_shop composer show shopware/core 2>/dev/null || true)"
    if printf '%s' "$core_versions" | grep -qa 'dev-trunk'; then
        log "core already on dev-trunk"
        return
    fi
    log "moving shopware/core to dev-trunk (several minutes)"
    # -W only rewrites dependencies of the packages named on the command line, not
    # packages that depend on them, and administration/storefront/elasticsearch each
    # pin an exact shopware/core version in their own composer.json. So core's three
    # first-party dependents have to move to dev-trunk in the same command, or -W
    # just reproduces the "conflicts with root composer.json require" errors above.
    in_shop env COMPOSER_MEMORY_LIMIT=-1 composer require --no-interaction --no-scripts \
        "shopware/core:dev-trunk" "shopware/administration:dev-trunk" \
        "shopware/storefront:dev-trunk" "shopware/elasticsearch:dev-trunk" -W
    # --no-scripts means the migrations dev-trunk shipped since the seed's
    # newest applied migration never ran; plugin:refresh/update fail with
    # "Unknown column" until they do.
    console database:migrate --all -n >/dev/null
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

main() {
  step_home
  step_fetch
  step_boot
  step_seed
  step_urls
  step_plugin_files
  step_core
  step_composer
  step_plugins
  step_license
  step_admin
  step_buyer_gate
  step_accept
  log "done: $CONTAINER is green. Admin: $SHOP_URL/admin ($SHOP_ADMIN_USER). Mail: http://localhost:${SHOP_MAIL_PORT:-8096}"
}

main "$@"
