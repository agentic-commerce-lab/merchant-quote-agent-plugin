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

main() {
  step_home
  step_fetch
  step_boot
  step_seed
  step_urls
  log "part 1 done: seeded and reachable at $SHOP_URL"
}

main "$@"
