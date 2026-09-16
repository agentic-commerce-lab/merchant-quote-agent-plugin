#!/usr/bin/env python3
"""Interactive UCP buyer agent for testing the merchant quote agent end to end.

Plays the role of an autonomous B2B buying agent against a live shop:
discovers the shop's UCP profile, finds a product, sends the human through the
shop's own sign-in and consent page, files an RFQ, then follows the
negotiation until it settles.

No credential is ever typed at or seen by this script. It publishes its own
signed UCP profile on an ngrok tunnel, registers a PKCE authorization request
with the shop, and opens the shop's own `authorization_url` in the browser —
the shop authenticates the customer through its storefront login and renders
the consent page itself. This agent never needs a Store API access key: it
only gets back an authorization code on the tunnel's /callback, which it
exchanges for an OAuth access token that lives in this process's memory and
nowhere else.

Requires: python3 (stdlib only), `openssl`, `ngrok` on PATH.
Run `--selftest` for the offline checks.
"""

import argparse
import base64
import hashlib
import http.server
import json
import os
import re
import secrets
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import webbrowser

UCP_VERSION = "2026-08-25"
KID = "quote-test-agent"
DEFAULT_SHOP = "https://agenticquote-shoelscher.eu-core-1.shopdev.de"
QUOTE_CAPABILITY = "com.shopware.quote"
SHOPPING_SERVICE = "dev.ucp.shopping"
IDENTITY_CAPABILITY = "identity_linking"
POLL_SECONDS = 5

# Only `replied` lets the buyer counter or accept — the plugin's published spec
# is explicit that acting from any other state fails.
ACTIONABLE = "replied"
TERMINAL = {"accepted", "declined", "expired", "withdrawn", "cancelled"}

STATE = {"caps": {}, "jwk": None, "key": None}
SIGNED_IN = threading.Event()


# --------------------------------------------------------------------------
# small helpers
# --------------------------------------------------------------------------
def b64u(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).decode().rstrip("=")


def sh(*args, inp=None) -> bytes:
    done = subprocess.run(args, input=inp, capture_output=True)
    if done.returncode:
        sys.exit(f"[fatal] {args} -> {done.stderr.decode()}")
    return done.stdout


def ask(prompt: str, default: str = "") -> str:
    shown = f"{prompt} [{default}]: " if default else f"{prompt}: "
    try:
        answer = input(shown).strip()
    except EOFError:
        return default
    return answer or default


def ask_int(prompt: str, default: int) -> int:
    while True:
        raw = ask(prompt, str(default))
        try:
            return int(raw)
        except ValueError:
            print(f"  not a whole number: {raw!r}")


def ask_float(prompt: str, default: float) -> float:
    while True:
        raw = ask(prompt, str(default))
        try:
            return float(raw.rstrip("%"))
        except ValueError:
            print(f"  not a number: {raw!r}")


def shopware_id(ucp_id: str) -> str:
    """UCP ids are GIDs; the quote capability wants the bare Shopware UUID.

    The shop currently emits bare 32-hex uuids, but the UCP product schema
    calls the field a GID, so accept either rather than break the day the
    catalog starts prefixing them.
    """
    found = re.findall(r"[0-9a-fA-F]{32}", ucp_id)
    return found[-1] if found else ucp_id


def major(amount, exponent: int = 2) -> float:
    """UCP catalog prices are integer ISO 4217 minor units; quotes use major."""
    return round(int(amount) / (10**exponent), 2)


def discounted(unit_price: float, percent: float) -> float:
    return round(unit_price * (1 - percent / 100), 2)


def money(value, currency: str = "") -> str:
    if value is None:
        return "—"
    return f"{value:.2f} {currency}".strip()


# --------------------------------------------------------------------------
# agent identity: ephemeral P-256 key -> JWK published on the tunnel
# --------------------------------------------------------------------------
def make_key(path: str) -> dict:
    sh("openssl", "ecparam", "-genkey", "-name", "prime256v1", "-noout", "-out", path)
    text = sh("openssl", "ec", "-in", path, "-text", "-noout").decode()
    match = re.search(r"pub:\s*\n((?:\s+[0-9a-fA-F:]+\n)+)", text)
    if match is None:
        sys.exit("[fatal] could not read the public point out of the generated key")
    point = bytes.fromhex(re.sub(r"[^0-9a-fA-F]", "", match.group(1)))
    if point[0] != 0x04 or len(point) != 65:
        sys.exit(f"[fatal] unexpected EC point: {len(point)} bytes")
    return {
        "kty": "EC",
        "crv": "P-256",
        "x": b64u(point[1:33]),
        "y": b64u(point[33:65]),
        "kid": KID,
        "alg": "ES256",
        "use": "sig",
    }


def canonical_uri(url: str) -> str:
    """Normalise a URI the way the shop will before it verifies the signature.

    The verifier rebuilds `@target-uri` from Symfony's Request::getUri(), and
    Symfony does not hand back the query string it received — it runs
    HeaderUtils::parseQuery, ksort, then http_build_query with
    PHP_QUERY_RFC3986. So a signed GET whose params are in a different order,
    or percent-encoded differently, fails verification with nothing to see but
    "Request signature verification failed". Sign and send this form and the
    shop's normalisation is a no-op.

    ponytail: sorts by (key, value) where PHP sorts by key alone — identical
    unless a key repeats, which no request here does. Fragments are dropped;
    they never reach the server anyway.
    """
    parts = urllib.parse.urlsplit(url)
    if not parts.query:
        return urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path, "", ""))
    pairs = sorted(urllib.parse.parse_qsl(parts.query, keep_blank_values=True))
    query = "&".join(
        f"{urllib.parse.quote(key, safe='')}={urllib.parse.quote(value, safe='')}"
        for key, value in pairs
    )
    return urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path, query, ""))


def signature_base(method: str, url: str, digest: str, params: str) -> str:
    return "\n".join(
        [
            f'"@method": {method}',
            f'"@target-uri": {url}',
            f'"content-digest": {digest}',
            f'"@signature-params": {params}',
        ]
    )


def sign_headers(method: str, url: str, body: bytes) -> dict:
    """RFC 9421 message signature over method, target URI and content digest.

    ponytail: the signature bytes go on the wire DER-encoded, not as the raw
    r||s pair RFC 9421 specifies for ES256. That is deliberate — the verifier
    on the other side is the UCP PHP SDK's Rfc9421RequestSignatureService,
    which hands the value straight to PHP's openssl_verify(), and that wants
    DER. Switch to raw r||s if a non-PHP verifier ever has to accept this.
    """
    digest = "sha-256=:" + base64.b64encode(hashlib.sha256(body).digest()).decode() + ":"
    created = int(time.time())
    params = (
        '("@method" "@target-uri" "content-digest")'
        f';created={created};expires={created + 120};keyid="{KID}";alg="ES256"'
    )
    der = sh(
        "openssl",
        "dgst",
        "-sha256",
        "-sign",
        STATE["key"],
        inp=signature_base(method, url, digest, params).encode(),
    )
    return {
        "Content-Digest": digest,
        "Signature-Input": f"sig={params}",
        "Signature": "sig=:" + base64.b64encode(der).decode() + ":",
    }


# --------------------------------------------------------------------------
# HTTP
# --------------------------------------------------------------------------
def call(
    method,
    url,
    body=None,
    *,
    form=None,
    token=None,
    agent=None,
    label="",
    quiet=False,
):
    url = canonical_uri(url)
    if form is not None:
        raw = urllib.parse.urlencode(form).encode()
        ctype = "application/x-www-form-urlencoded"
    elif body is not None:
        raw = json.dumps(body).encode()
        ctype = "application/json"
    else:
        raw, ctype = b"", None

    headers = {"Accept": "application/json", "UCP-Version": UCP_VERSION}
    headers.update(sign_headers(method, url, raw))
    if ctype:
        headers["Content-Type"] = ctype
    if method == "POST":
        headers["Idempotency-Key"] = b64u(secrets.token_bytes(16))
    if agent:
        headers["UCP-Agent"] = f'profile="{agent}"'
    if token:
        headers["Authorization"] = f"Bearer {token}"

    request = urllib.request.Request(url, data=raw or None, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            text = response.read().decode()
            if not quiet:
                print(f"\n[{label or method}] {response.status} {url}\n{text}", flush=True)
            return json.loads(text) if text.strip() else {}
    except urllib.error.HTTPError as error:
        text = error.read().decode()
        # Refusals are the interesting part of a negotiation test — verbatim.
        print(f"\n[{label or method}] HTTP {error.code} {url}\n{text}", flush=True)
        return {"__error__": error.code, "__body__": text}
    except urllib.error.URLError as error:
        sys.exit(f"[fatal] {url} unreachable: {error.reason}")


def get_json(url: str) -> dict:
    """Unsigned plain GET, for documents that sit outside the /ucp/ prefix."""
    try:
        with urllib.request.urlopen(url, timeout=30) as response:
            return json.loads(response.read().decode())
    except urllib.error.HTTPError as error:
        return {"__error__": error.code, "__body__": error.read().decode()}
    except urllib.error.URLError as error:
        sys.exit(f"[fatal] {url} unreachable: {error.reason}")


# --------------------------------------------------------------------------
# the agent's own profile + OAuth redirect, served on the tunnel
# --------------------------------------------------------------------------
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        print(f"[tunnel] {self.command} {self.path}", flush=True)

    def _send(self, body: bytes, ctype: str, status: int = 200):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == "/.well-known/ucp":
            # The shop fetches this to verify our signatures. Mirroring its own
            # capabilities keeps the negotiated intersection non-empty.
            self._send(
                json.dumps(
                    {
                        "ucp": {"version": UCP_VERSION, "capabilities": STATE["caps"]},
                        "signing_keys": [STATE["jwk"]],
                    }
                ).encode(),
                "application/json",
            )
        elif parsed.path == "/callback":
            STATE["callback"] = dict(urllib.parse.parse_qsl(parsed.query))
            SIGNED_IN.set()
            self._send(b"<h1>Authorized.</h1><p>Back to the terminal.</p>", "text/html")
        else:
            self._send(b"not found", "text/plain", 404)


def start_server() -> int:
    probe = socket.socket()
    probe.bind(("127.0.0.1", 0))
    port = probe.getsockname()[1]
    probe.close()
    server = http.server.ThreadingHTTPServer(("127.0.0.1", port), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    STATE["server"] = server
    return port


def start_ngrok(port: int) -> tuple:
    process = subprocess.Popen(
        ["ngrok", "http", "--log=stdout", "--log-format=json", str(port)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    for _ in range(60):
        time.sleep(1)
        tunnels = get_json("http://127.0.0.1:4040/api/tunnels")
        for tunnel in tunnels.get("tunnels", []) if isinstance(tunnels, dict) else []:
            public = tunnel.get("public_url", "")
            addr = tunnel.get("config", {}).get("addr", "")
            if public.startswith("https://") and (not addr or str(port) in addr):
                return process, public
    process.terminate()
    sys.exit("[fatal] ngrok did not open an https tunnel within 60s")


# --------------------------------------------------------------------------
# 2. discovery
# --------------------------------------------------------------------------
def discover(shop: str) -> tuple:
    profile = get_json(f"{shop}/.well-known/ucp")
    if "__error__" in profile:
        sys.exit(f"[fatal] {shop}/.well-known/ucp -> HTTP {profile['__error__']}\n{profile['__body__']}")

    ucp = profile.get("ucp", profile)
    STATE["caps"] = ucp.get("capabilities", {})
    print(f"\n[shop] UCP {ucp.get('version')}  keys: {len(profile.get('signing_keys', []))}")
    print(f"[shop] capabilities: {', '.join(sorted(STATE['caps']))}")

    if QUOTE_CAPABILITY not in STATE["caps"]:
        sys.exit(
            f"[fatal] {shop} does not advertise {QUOTE_CAPABILITY}. "
            "Is MerchantQuoteAgentPlugin installed and active, with SwagCommercial's "
            "QuoteManagement licence?"
        )

    entry = STATE["caps"][QUOTE_CAPABILITY]
    entry = entry[0] if isinstance(entry, list) else entry
    print(f"[shop] {QUOTE_CAPABILITY} v{entry.get('version')}")
    print(f"[shop]   spec:   {entry.get('spec')}")
    print(f"[shop]   schema: {entry.get('schema')}")

    # The quote descriptor carries no endpoint of its own: the paths live in
    # its OpenAPI document, so read them rather than assuming them.
    schema = get_json(entry["schema"])
    if "__error__" in schema:
        sys.exit(f"[fatal] cannot read the quote schema: HTTP {schema['__error__']}")
    paths = list(schema.get("paths", {}))
    print(f"[quote] endpoints: {', '.join(paths)}")
    collection = next((p for p in paths if p.rstrip("/").endswith("quotes")), None)
    if collection is None:
        sys.exit(f"[fatal] no quote collection path in {paths}")
    quotes = shop.rstrip("/") + collection

    services = ucp.get("services", {})
    shopping = services.get(SHOPPING_SERVICE, [])
    shopping = shopping if isinstance(shopping, list) else [shopping]
    rest = next((s for s in shopping if s.get("transport") == "rest"), None)
    if rest is None:
        sys.exit(f"[fatal] {shop} publishes no REST transport for {SHOPPING_SERVICE}")
    print(f"[shop] shopping REST: {rest['endpoint']}")

    return quotes, rest["endpoint"].rstrip("/")


def discover_oauth(shop: str) -> dict:
    meta = get_json(f"{shop}/.well-known/oauth-authorization-server")
    if "__error__" in meta or meta.get("ucp", {}).get("status") == "error":
        detail = json.dumps(meta.get("messages", meta))[:400]
        sys.exit(
            "[fatal] the shop publishes no OAuth metadata, so there is no login page to open.\n"
            f"        {detail}\n\n"
            f"        Enable identity linking for the sales channel — there is no checkbox for it\n"
            f"        in the admin UI (SwagAgenticCommerce ships it as a 'not ready' capability),\n"
            f"        so add {IDENTITY_CAPABILITY!r} to enabledCapabilities via the admin API:\n"
            f"          GET  {shop}/api/_admin/ucp/sales-channels/<salesChannelId>/config\n"
            f"          POST {shop}/api/_admin/ucp/sales-channels/<salesChannelId>/config"
        )
    print(f"\n[oauth] issuer:    {meta.get('issuer')}")
    print(f"[oauth] authorize: {meta.get('authorization_endpoint')}")
    print(f"[oauth] token:     {meta.get('token_endpoint')}")
    print(f"[oauth] scopes:    {', '.join(meta.get('scopes_supported', [])) or '(none)'}")
    print(
        "[oauth] note: no quote scope is offered — Agentic Commerce intersects requested\n"
        "        scopes against its own catalogue, which does not know the vendor\n"
        "        capability. Quote authorization rides on the token's subject (ownership),\n"
        "        not on a scope. Requesting the advertised scopes verbatim."
    )
    return meta


# --------------------------------------------------------------------------
# 3. the ask
# --------------------------------------------------------------------------
def pick_product(rest: str, agent: str, product_query: str = None) -> tuple:
    while True:
        query = product_query or ask("\nProduct to ask about")
        if not query:
            continue
        found = call(
            "POST",
            f"{rest}/catalog/search",
            {"query": query, "pagination": {"limit": 10}},
            agent=agent,
            label="catalog.search",
            quiet=True,
        )
        if "__error__" in found:
            print("[catalog] search refused; you can paste a product id instead.")
            products = []
        else:
            products = found.get("products", [])

        if not products:
            print(f"[catalog] nothing matched {query!r}.")
            if product_query:
                product_query = None
                continue
            manual = ask("Paste a Shopware product UUID (or Enter to search again)")
            if manual:
                price = ask_float("  its unit price in the shop currency", 0.0)
                return shopware_id(manual), manual, price, ""
            continue

        print(f"\n[catalog] {len(products)} match(es):")
        rows = []
        for index, product in enumerate(products, 1):
            variants = product.get("variants") or [{}]
            featured = variants[0]
            price = featured.get("price") or {}
            unit = major(price["amount"]) if "amount" in price else None
            currency = price.get("currency", "")
            rows.append((product, unit, currency, len(variants)))
            flag = "  ⚠ variant parent" if len(variants) > 1 else ""
            print(f"  {index}) {product.get('title')} — {money(unit, currency)}{flag}")
            print(f"     id {product.get('id')}")

        choice = 1 if product_query else ask_int("Which one", 1)
        if not 1 <= choice <= len(rows):
            print("  out of range")
            continue
        product, unit, currency, variant_count = rows[choice - 1]
        if variant_count > 1:
            # The plugin refuses these outright rather than let SwagCommercial
            # segfault on them, so say so before the RFQ fails.
            print(
                "  ⚠ this product has several variants. The plugin's "
                "VariantRejectingProductAdder refuses variants and variant parents; "
                "the RFQ will come back as an unsupported product."
            )
            if ask("  continue anyway? (y/N)", "n").lower() != "y":
                if product_query:
                    product_query = None
                continue
        if unit is None:
            unit = ask_float("  no price in the catalog; unit price in shop currency", 0.0)
        return shopware_id(product["id"]), product.get("title", ""), unit, currency


# --------------------------------------------------------------------------
# 4. login
# --------------------------------------------------------------------------
def login(shop: str, meta: dict, agent: str, redirect: str) -> str:
    """Register the request, send the human to the shop, exchange the code.

    The consent page is the shop's own now: it signs the customer in through
    the storefront login and renders the grant. This agent never sees a
    credential, and no longer needs a Store API access key to get one.
    """
    verifier = b64u(secrets.token_bytes(32))
    challenge = b64u(hashlib.sha256(verifier.encode()).digest())
    expected_state = b64u(secrets.token_bytes(16))

    registered = call(
        "POST",
        f"{shop}/ucp/quote-agent/authorization-requests",
        {
            "client_id": agent,
            "redirect_uri": redirect,
            "scope": " ".join(meta.get("scopes_supported", [])),
            "state": expected_state,
            "code_challenge": challenge,
            "code_challenge_method": "S256",
        },
        agent=agent,
        label="authorization-request",
        quiet=True,
    )
    if "__error__" in registered:
        sys.exit("[fatal] the shop refused to register the authorization request - see above.")

    url = registered.get("authorization_url")
    if not url:
        sys.exit(f"[fatal] no authorization_url in the response: {registered}")

    print(f"\n[login] opening the shop's own sign-in and consent page:\n        {url}\n", flush=True)
    try:
        with open("/tmp/auth_url.txt", "w") as _f:
            _f.write(url)
    except Exception:
        pass
    webbrowser.open(url)

    print("[login] waiting for consent to come back over the tunnel (10 min)...", flush=True)
    if not SIGNED_IN.wait(600):
        sys.exit("[fatal] no consent callback within 10 minutes")

    callback = STATE["callback"]
    if callback.get("error"):
        sys.exit(f"[fatal] consent was refused: {callback['error']}")
    if callback.get("state") != expected_state:
        sys.exit(f"[fatal] OAuth state mismatch - discarding. got {callback.get('state')!r}")
    code = callback.get("code")
    if not code:
        sys.exit(f"[fatal] no authorization code in the callback: {callback}")

    granted = call(
        "POST",
        meta["token_endpoint"],
        form={
            "grant_type": "authorization_code",
            "code": code,
            "redirect_uri": redirect,
            "client_id": agent,
            "code_verifier": verifier,
        },
        agent=agent,
        label="oauth.token",
        quiet=True,
    )
    token = granted.get("access_token")
    if not token:
        sys.exit(f"[fatal] no access_token in the token response: {granted}")
    print(f"[login] token acquired (scope: {granted.get('scope') or '(none)'}) - memory only")
    return token


# --------------------------------------------------------------------------
# 5-6. RFQ, then follow the negotiation
# --------------------------------------------------------------------------
def show(quote: dict) -> str:
    currency = quote.get("currency") or ""
    totals = quote.get("totals") or {}
    print(f"\n  quote {quote.get('quote_number') or quote.get('id')}  state: {quote.get('state')}")
    print(f"  expires: {quote.get('expiration_date') or '—'}")
    print(
        f"  totals: gross {money(totals.get('gross'), currency)} / "
        f"net {money(totals.get('net'), currency)}  (authoritative: {totals.get('tax_status') or '—'})"
    )
    for line in quote.get("line_items") or []:
        print(
            f"    {line.get('quantity')}× {line.get('label')}  "
            f"offered {money(line.get('unit_price'), currency)}/unit  "
            f"asked {money(line.get('requested_unit_price'), currency)}/unit  "
            f"= {money(line.get('total_price'), currency)}"
        )
    for comment in quote.get("comments") or []:
        print(f"    [{comment.get('author')}] {comment.get('comment')}")
    return quote.get("state") or ""


def poll(quotes: str, quote_id: str, token: str, agent: str, was: str) -> dict:
    """Print each new revision until the state moves off `was`."""
    print(f"\n[poll] every {POLL_SECONDS}s while state is {was!r} — Ctrl-C to stop")
    seen = None
    while True:
        quote = call("GET", f"{quotes}/{quote_id}", token=token, agent=agent, quiet=True)
        if "__error__" in quote:
            return quote
        fingerprint = json.dumps(quote, sort_keys=True)
        if fingerprint != seen:
            seen = fingerprint
            state = show(quote)
            if state != was:
                print(f"\n[poll] state moved {was!r} -> {state!r}")
                return quote
        else:
            print(".", end="", flush=True)
        time.sleep(POLL_SECONDS)


def negotiate(quotes: str, quote: dict, token: str, agent: str, currency: str) -> None:
    while True:
        state = quote.get("state") or ""
        quote_id = quote.get("id")

        if state in TERMINAL:
            print(f"\n[done] quote is {state} — nothing left to do.")
            return
        if state != ACTIONABLE:
            try:
                quote = poll(quotes, quote_id, token, agent, state)
            except KeyboardInterrupt:
                print("\n[poll] stopped.")
                return
            if "__error__" in quote:
                return
            continue

        print("\n  [c] counter   [a] accept   [d] decline   [r] refresh   [q] quit")
        choice = ask("  action", "r").lower()

        if choice == "q":
            print(f"[done] leaving quote {quote_id} in state {state}.")
            return
        if choice == "r":
            quote = call("GET", f"{quotes}/{quote_id}", token=token, agent=agent, quiet=True)
            show(quote)
        elif choice == "a":
            quote = call("POST", f"{quotes}/{quote_id}/accept", token=token, agent=agent, label="quote.accept")
        elif choice == "d":
            quote = call(
                "POST",
                f"{quotes}/{quote_id}/decline",
                {"comment": ask("  why", "Not viable at this price.")},
                token=token,
                agent=agent,
                label="quote.decline",
            )
        elif choice == "c":
            lines = quote.get("line_items") or []
            if not lines:
                print("  no line items to counter")
                continue
            counters = []
            for line in lines:
                offered = line.get("unit_price") or 0.0
                print(f"  {line.get('label')} — merchant offers {money(offered, currency)}/unit")
                counters.append(
                    {
                        "id": line["id"],
                        "requested_unit_price": ask_float("    your counter per unit", offered),
                    }
                )
            quote = call(
                "POST",
                f"{quotes}/{quote_id}/counter",
                {"line_items": counters, "comment": ask("  comment", "Countering on volume.")},
                token=token,
                agent=agent,
                label="quote.counter",
            )
        else:
            print("  ?")
            continue

        if "__error__" in quote:
            print("  that call was refused; refreshing.")
            quote = call("GET", f"{quotes}/{quote_id}", token=token, agent=agent, quiet=True)
            if "__error__" in quote:
                return


# --------------------------------------------------------------------------
def run(
    shop: str,
    product_query: str = None,
    quantity_opt: int = None,
    asking_price_opt: float = None,
    discount_opt: float = None,
) -> None:
    STATE["key"] = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".ucp-agent-key.pem")
    STATE["jwk"] = make_key(STATE["key"])
    print(f"[agent] ephemeral ES256 key, kid {KID}")

    port = start_server()
    ngrok, tunnel = start_ngrok(port)
    # The query string is a cache-buster: the shop caches fetched agent
    # profiles, and a rerun publishes a new key under the same tunnel host.
    agent = f"{tunnel}/.well-known/ucp?run={int(time.time())}"
    redirect = f"{tunnel}/callback"
    print(f"[agent] tunnel:  {tunnel}")
    print(f"[agent] profile: {agent}")

    try:
        quotes, rest = discover(shop)
        oauth = discover_oauth(shop)

        product_id, title, unit, currency = pick_product(rest, agent, product_query)
        quantity = quantity_opt if quantity_opt is not None else ask_int("Quantity", 100)
        if asking_price_opt is not None:
            asking = asking_price_opt
            percent = round((1 - asking / unit) * 100, 2) if unit else 0.0
        else:
            percent = discount_opt if discount_opt is not None else ask_float("Discount to ask for (%)", 20.0)
            asking = discounted(unit, percent)
        print(
            f"\n[ask] {quantity}× {title}\n"
            f"[ask] list {money(unit, currency)}/unit  →  asking {money(asking, currency)}/unit "
            f"({percent:g}% off), {money(round(asking * quantity, 2), currency)} total"
        )

        # A percentage is only meaningful against the price the catalog quoted.
        # With --asking-price the caller names the unit price outright, and the
        # shop may well price the line off a volume tier the catalog never
        # showed -- deriving a percentage from the catalog price then prints
        # nonsense like "-141.77% off", which the extract prompt rightly refers
        # to a human. State the number instead and let the shop do the maths.
        ask_comment = (
            f"Requesting {quantity} units of {title}. We can commit to "
            f"{money(asking, currency)} per unit on this volume. Please review."
            if asking_price_opt is not None
            else f"Requesting {quantity} units of {title}. Asking {percent:g}% off the "
            f"list price of {money(unit, currency)} per unit, i.e. "
            f"{money(asking, currency)} per unit on this volume. Please review."
        )

        token = login(shop, oauth, agent, redirect)

        created = call(
            "POST",
            quotes,
            {
                "line_items": [
                    {
                        "product_id": product_id,
                        "quantity": quantity,
                        "requested_unit_price": asking,
                    }
                ],
                "comment": ask_comment,
            },
            token=token,
            agent=agent,
            label="quote.request",
        )
        if "__error__" in created:
            sys.exit("[fatal] the RFQ was refused — see the response above.")

        show(created)
        negotiate(quotes, created, token, agent, created.get("currency") or currency)
    finally:
        ngrok.terminate()
        STATE.get("server") and STATE["server"].shutdown()
        try:
            os.unlink(STATE["key"])
        except OSError:
            pass
        print("[cleanup] tunnel closed, agent key deleted")


def selftest() -> None:
    assert discounted(19.99, 25) == 14.99, discounted(19.99, 25)
    assert discounted(10.0, 0) == 10.0
    assert discounted(10.0, 100) == 0.0
    assert major(1999) == 19.99
    assert major(0) == 0.0
    assert major(500, 0) == 500.0

    uuid = "0194f0e6c7a94e8fb1c2d3e4f5a6b7c8"
    assert shopware_id(uuid) == uuid
    assert shopware_id(f"gid://shopware/Product/{uuid}") == uuid
    assert shopware_id("no-id-here") == "no-id-here"

    # Symfony sorts and RFC3986-encodes the query before the shop verifies.
    assert canonical_uri("https://x/a?b=2&a=1") == "https://x/a?a=1&b=2"
    assert canonical_uri("https://x/a?s=a:b/c") == "https://x/a?s=a%3Ab%2Fc"
    assert canonical_uri("https://x/a?s=x y") == "https://x/a?s=x%20y"
    assert canonical_uri("https://x/a?s=-._~") == "https://x/a?s=-._~"
    assert canonical_uri("https://x/ucp/quotes") == "https://x/ucp/quotes"
    assert canonical_uri("https://x/a#frag") == "https://x/a"

    base = signature_base("POST", "https://x/ucp/quotes", "sha-256=:abc:", '("@method");created=1')
    assert base.splitlines() == [
        '"@method": POST',
        '"@target-uri": https://x/ucp/quotes',
        '"content-digest": sha-256=:abc:',
        '"@signature-params": ("@method");created=1',
    ], base
    assert money(None) == "—"
    assert money(5, "EUR") == "5.00 EUR"
    print("selftest ok")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--shop", help="shop base URL; prompted for if omitted")
    parser.add_argument("--product", help="product name or query to search for")
    parser.add_argument("--quantity", type=int, help="quantity to request")
    parser.add_argument("--asking-price", type=float, help="unit price to ask for")
    parser.add_argument("--discount", type=float, help="discount percent to ask for")
    parser.add_argument("--selftest", action="store_true", help="run the offline checks and exit")
    args = parser.parse_args()

    if args.selftest:
        selftest()
        sys.exit(0)

    try:
        run(
            (args.shop or ask("Shop address", DEFAULT_SHOP)).rstrip("/"),
            product_query=args.product,
            quantity_opt=args.quantity,
            asking_price_opt=args.asking_price,
            discount_opt=args.discount,
        )
    except KeyboardInterrupt:
        print("\n[abort]")
