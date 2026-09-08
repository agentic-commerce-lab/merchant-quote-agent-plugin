#!/usr/bin/env bash
# Sync this checkout into the test shop, then run the integration suite there.
# Any worktree can run this: the sync pushes *this* checkout's files, so the
# container always holds the code of whoever ran the tests last.
#
#   composer run test:integration                       # against merchant-quote-shop
#   composer run test:integration -- --filter Fetch     # phpunit args pass through
#   SHOP_CONTAINER=shopware-trunk composer run test:integration   # another shop
#
# Two shops matter for this suite. `merchant-quote-shop` runs unreleased
# SwagCommercial (trunk); `merchant-quote-shop-6712` runs the newest release,
# 6.7.12. The plugin supports 6.7.1.2 and up, and the two shops are the two
# capability profiles — a change to src/Bridge should run against both.
#
# A remote, non-Docker shop (e.g. the released-SwagCommercial host) is driven
# over SSH instead, by setting SHOP_SSH (user@host) and SHOP_PATH (its
# docroot, an ABSOLUTE path on the remote host — `~` will not expand there):
#
#   SHOP_SSH=user@host SHOP_PATH=/home/user/files/shop composer run test:integration
#
# SHOP_PHP defaults to php8.3, this plugin's own floor — but a remote host's
# vendor tree may demand newer than that (a released SwagCommercial shop
# has needed php8.4), so override it there:
#
#   SHOP_SSH=user@host SHOP_PATH=/home/user/files/shop SHOP_PHP=php8.4 composer run test:integration
#
# That host IP-bans on frequent connections, so this script opens exactly one
# ssh ControlMaster socket up front and routes both the sync (via
# scripts/sync-to-shop.sh, handed the same socket through MQA_SSH_SOCKET) and
# the remote phpunit invocation through it — one TCP connection and one
# authentication for the whole sync+test run, regardless of how many remote
# commands that takes internally.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -n "${SHOP_SSH:-}" ]; then
  : "${SHOP_PATH:?SHOP_PATH (the remote docroot) must be set alongside SHOP_SSH}"
  # Must be an absolute path on the REMOTE host, checked before the SSH
  # master below opens — see scripts/sync-to-shop.sh's matching guard for
  # why no form of `~` works here.
  case "$SHOP_PATH" in
    /*) ;;
    *)
      echo "SHOP_PATH must be an absolute path on the remote host (e.g. /home/user/files/shop); '~' does not expand there. Got: $SHOP_PATH" >&2
      exit 1
      ;;
  esac
  # Same reasoning as Docker's explicit `php8.3`: the remote host may run
  # several PHP versions side by side, so name the one Shopware 6.7 needs
  # rather than trusting a bare `phpunit` shebang. Override if this host's
  # binary is named differently.
  SHOP_PHP="${SHOP_PHP:-php8.3}"

  SOCKET="$(mktemp -u /tmp/mqa-ssh-XXXXXX.sock)"
  ssh -M -S "$SOCKET" -fN -o ControlPersist=60 "$SHOP_SSH"
  cleanup_socket() {
    ssh -S "$SOCKET" -O exit "$SHOP_SSH" >/dev/null 2>&1 || true
  }
  trap cleanup_socket EXIT

  MQA_SSH_SOCKET="$SOCKET" SHOP_SSH="$SHOP_SSH" SHOP_PATH="$SHOP_PATH" scripts/sync-to-shop.sh

  # Quote each phpunit arg individually before splicing it into the one
  # remote command string ssh sends to the shop's shell — "$@" may contain
  # e.g. `--filter "Some Test"` and must survive that trip intact.
  REMOTE_ARGS=""
  for arg in "$@"; do
    printf -v quoted '%q' "$arg"
    REMOTE_ARGS="$REMOTE_ARGS $quoted"
  done

  # bootstrap.php falls back to /var/www/html (the Docker docroot) unless
  # SHOPWARE_ROOT says otherwise; on a remote shop the real docroot is
  # $SHOP_PATH, so export it for the remote phpunit process.
  ssh -S "$SOCKET" "$SHOP_SSH" \
    "cd '$SHOP_PATH/custom/plugins/MerchantQuoteAgentPlugin' && SHOPWARE_ROOT='$SHOP_PATH' '$SHOP_PHP' '$SHOP_PATH/vendor/bin/phpunit' -c phpunit.integration.xml.dist$REMOTE_ARGS"
  exit 0
fi

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"

SHOP_CONTAINER="$CONTAINER" scripts/sync-to-shop.sh
docker exec -w /var/www/html/custom/plugins/MerchantQuoteAgentPlugin "$CONTAINER" \
  php8.3 /var/www/html/vendor/bin/phpunit -c phpunit.integration.xml.dist "$@"
