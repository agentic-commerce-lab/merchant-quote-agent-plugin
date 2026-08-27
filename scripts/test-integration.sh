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
