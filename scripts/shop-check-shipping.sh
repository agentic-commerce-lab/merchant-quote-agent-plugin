#!/usr/bin/env bash
# Does this shop charge shipping? Issue #8 said the old demo shop's trunk
# delivery calculator returned €0, blocking the free-shipping path and the
# non-price-terms decision (#11). This puts one standalone product in a
# store-api cart and prints what the shop charges for delivery.
#
# Measured 2026-08-27: the calculator is not broken. Both active shipping
# methods have price rows that read {"net": "0", "gross": "0"} — the €0 is the
# seeded price, not a calculator defect (verified by setting the rows to 4.99,
# seeing shipping charged, then reverting). #11 unblocks with a config change
# (a non-zero shipping_method_price.currency_price) measured by this script,
# not a new shop.
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-merchant-quote-shop}"
URL="${SHOP_URL:-http://localhost:8095}"

sql() { docker exec "$CONTAINER" mysql -uroot -proot -h127.0.0.1 -N -B shopware -e "$1" 2>/dev/null; }

# Pick a (sales channel, product) pair that actually belong together: this
# shop has more than one active sales channel, and a product picked without
# regard to product_visibility can 404 in the channel the access key belongs to.
PAIR="$(sql "SELECT sc.access_key, LOWER(HEX(p.id))
  FROM product_visibility pv
  JOIN product p ON p.id = pv.product_id AND p.version_id = pv.product_version_id
  JOIN sales_channel sc ON sc.id = pv.sales_channel_id
  WHERE sc.active=1 AND p.parent_id IS NULL AND COALESCE(p.child_count,0)=0 AND p.active=1
  ORDER BY p.product_number LIMIT 1")"
KEY="$(echo "$PAIR" | cut -f1)"
PRODUCT="$(echo "$PAIR" | cut -f2)"
[ -n "$KEY" ] && [ -n "$PRODUCT" ] || { echo "no active sales channel with a visible standalone product in $CONTAINER" >&2; exit 1; }

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
