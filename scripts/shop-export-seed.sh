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
