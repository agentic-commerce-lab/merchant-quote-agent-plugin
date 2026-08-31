#!/usr/bin/env bash
# Lint this plugin's administration sources with the shop's own ESLint.
# mago only reads src/*.php, so nothing in this repo's own gates sees the
# TypeScript. The shop ships the config; we borrow it, the same way
# test-integration.sh borrows the shop's PHPUnit.
#
#   composer run lint:administration
#   SHOP_CONTAINER=shopware-trunk composer run lint:administration
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
cd "$(dirname "$0")/.."

SHOP_CONTAINER="$CONTAINER" scripts/sync-to-shop.sh

docker exec -w /var/www/html/vendor/shopware/administration/Resources/app/administration "$CONTAINER" \
  npx eslint \
  --no-error-on-unmatched-pattern \
  "/var/www/html/custom/plugins/MerchantQuoteAgentPlugin/src/Resources/app/administration/src/**/*.ts" "$@"
