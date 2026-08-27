#!/usr/bin/env bash
# Copy this plugin into the live shop container. /var/www/html is a named
# volume, not a bind mount, so there is nothing to symlink — we push files.
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-shopware-trunk}"
DEST="/var/www/html/custom/plugins/MerchantQuoteAgentPlugin"

# Wipe first: a stale destination can carry macOS AppleDouble (._*) files from
# an earlier sync, and PHPUnit picks those up as test classes.
docker exec "$CONTAINER" rm -rf "$DEST"
docker exec "$CONTAINER" mkdir -p "$DEST"
COPYFILE_DISABLE=1 tar --exclude=vendor --exclude=.git --exclude=report --exclude=node_modules -cf - . \
  | docker exec -i "$CONTAINER" tar -xf - -C "$DEST"

# Not installed as a Shopware plugin: the bridge only needs its classes
# autoloadable and SwagCommercial's services in the container (see bootstrap.php
# and ADR 0001), and this plugin's own composer requirements (cuyz/valinor,
# ucp-php-sdk/core) aren't satisfiable in this shop's vendor tree anyway.
echo "synced to $CONTAINER:$DEST"
