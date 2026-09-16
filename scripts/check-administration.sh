#!/usr/bin/env bash
# Type-check and lint this plugin's administration sources, in the test shop.
#
#   composer run quality:admin:shop                      # the gate
#   composer run quality:admin:shop -- --verbose         # show the baselined findings
#   composer run quality:admin:shop -- --update-baseline # re-record the baseline
#   composer run quality:admin:shop -- --fix             # apply ESLint autofixes
#   SHOP_CONTAINER=other composer run quality:admin:shop # another shop
#
# This borrows the shop's toolchain the same way scripts/test-integration.sh
# borrows its PHPUnit, and for the same reason: the only complete, correct
# Administration type surface is the installed one. Shopware ships it as
# vendor/shopware/administration/.../extension-tooling/, driven by the three
# console commands below.
#
# It is deliberately NOT part of `composer run quality`. That is what CI runs,
# on a runner with no database and no Shopware install, and the entity schema
# below is generated from a live database. A gate that silently skips in CI
# teaches as little as one that always fails.
#
# EXPERIMENTAL upstream: `administration:setup-extension-tooling` says so
# itself. Command names, flags and the generated-file layout can change in any
# Shopware release. A breakage surfaces as "TOOLING ERROR" and exit 1, not as a
# false green.
#
# If the check reports the plugin as an unknown --only entry, the shop's
# var/plugins.json predates its install:
#   docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console bundle:dump
set -euo pipefail
cd "$(dirname "$0")/.."

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
ADMIN=/var/www/html/vendor/shopware/administration/Resources/app/administration

console() {
  docker exec -w /var/www/html "$CONTAINER" php8.3 bin/console "$@"
}

SHOP_CONTAINER="$CONTAINER" scripts/sync-to-shop.sh

# A shop installed for production carries only the Administration's runtime
# node_modules -- 539 packages, no vue-tsc, no typescript-eslint, no globals.
# That, and not a broken config, is why the first attempt at this gate failed
# with "Cannot find module 'typescript-eslint'" (issue #39).
#
# `npm install`, never `npm ci`: ci empties node_modules before refilling it,
# and this shop is shared with other agents who may be building admin assets at
# that moment. install is additive against the same committed package-lock.json.
if ! docker exec "$CONTAINER" test -d "$ADMIN/node_modules/vue-tsc"; then
  echo "installing the administration's dev dependencies (one-time, ~10s)..."
  docker exec -w "$ADMIN" "$CONTAINER" npm install --no-audit --no-fund --ignore-scripts
fi

# Without this the plugin's own entities are missing from EntitySchema.Entities,
# every read through them collapses to `never`, and ~20 findings appear that say
# nothing about the code. Regenerated every run so the baseline cannot depend on
# when someone last ran it by hand.
console administration:generate-entity-schema-types -n

# sync-to-shop.sh rm -rf's the plugin directory, taking the git-ignored
# .shopware/ bridge with it, so this has to run after every sync rather than
# once per shop.
console administration:setup-extension-tooling -n

# --fail-on-skipped: without it a tool that could not run at all still exits 0,
# so a green result could mean "checked nothing".
console administration:check-extensions -n -- \
  --only=MerchantQuoteAgentPlugin --fail-on-skipped "$@"
