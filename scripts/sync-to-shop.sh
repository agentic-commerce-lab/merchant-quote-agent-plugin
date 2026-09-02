#!/usr/bin/env bash
# Copy this plugin into the live shop container. /var/www/html is a named
# volume, not a bind mount, so there is nothing to symlink — we push files.
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
DEST="/var/www/html/custom/plugins/MerchantQuoteAgentPlugin"

# Wipe first: a stale destination can carry macOS AppleDouble (._*) files from
# an earlier sync, and PHPUnit picks those up as test classes.
#
# But carry `src/Resources/public` across the wipe. That is the compiled
# administration bundle, built inside the container by bin/build-administration.sh
# and never committed, so the tar below cannot restore it. Shopware advertises a
# plugin's admin assets from the plugin-local
# src/Resources/public/administration/.vite/entrypoints.json — delete that and the
# module silently stops loading, which shows up as a blank administration on any
# of its routes rather than as an error.
BUILT="$DEST/src/Resources/public"
KEEP="/tmp/mqa-built-public"
docker exec "$CONTAINER" sh -c "rm -rf '$KEEP'; [ -d '$BUILT' ] && mv '$BUILT' '$KEEP' || true"
docker exec "$CONTAINER" rm -rf "$DEST"
docker exec "$CONTAINER" mkdir -p "$DEST"
# -m on extract: stamp the files with the time of the sync rather than keeping
# the source mtimes. Symfony decides a compiled container is still fresh by
# comparing its tracked resources' mtimes against its own, so a file whose
# mtime predates the container is treated as unchanged. Git checkouts and
# worktrees routinely produce such files, and with several sessions syncing
# their own checkouts into this one shop the result was a test run silently
# executing against a container compiled from someone else's source -- services
# missing, decorators absent, and no error to explain it.
COPYFILE_DISABLE=1 tar --exclude=vendor --exclude=.git --exclude=report --exclude=node_modules -cf - . \
  | docker exec -i "$CONTAINER" tar -xmf - -C "$DEST"
docker exec "$CONTAINER" sh -c "[ -d '$KEEP' ] && mkdir -p '$DEST/src/Resources' && mv '$KEEP' '$BUILT' || true"

# In merchant-quote-shop the plugin IS installed (shop-setup.sh requires it
# through dockware's custom/plugins/* composer path repository), so this sync
# only refreshes its source files in place; the path is what composer's
# autoload points at. Against the old shopware-trunk container it was never
# installed and the integration bootstrap autoloads it by PSR-4 instead.
echo "synced to $CONTAINER:$DEST"
