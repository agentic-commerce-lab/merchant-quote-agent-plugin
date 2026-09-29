/**
 * The eval buyer's UCP transport, ported from scripts/ucp-quote-agent.py
 * (the interactive buyer, which stays as it is). Spec
 * 2026-09-28-claude-code-evals-design, "Stage 1 — the UCP buyer".
 *
 * Two quirks carried over on purpose:
 * - Signatures go on the wire DER-encoded, not as RFC 9421's raw r||s: the
 *   verifier is the UCP PHP SDK, which hands them to openssl_verify().
 *   node:crypto's sign() emits DER by default.
 * - The target URI is canonicalised the way Symfony rebuilds it (sorted
 *   query, RFC 3986 encoding); any other form fails verification.
 */
import { createHash, createPrivateKey, createPublicKey, generateKeyPairSync, randomBytes, sign } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync, renameSync, mkdirSync } from 'node:fs';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { dirname } from 'node:path';

export const UCP_VERSION = '2026-08-25';
export const KID = 'eval-buyer';

const rfc3986 = (value) => encodeURIComponent(value).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);

export function canonicalUri(url) {
    const parsed = new URL(url);
    const base = `${parsed.protocol}//${parsed.host}${parsed.pathname}`;
    const pairs = [...parsed.searchParams.entries()].sort(([a, av], [b, bv]) => (a === b ? (av < bv ? -1 : av > bv ? 1 : 0) : a < b ? -1 : 1));
    return pairs.length === 0 ? base : `${base}?${pairs.map(([k, v]) => `${rfc3986(k)}=${rfc3986(v)}`).join('&')}`;
}

export function signatureBase(method, url, digest, params) {
    return [`"@method": ${method}`, `"@target-uri": ${url}`, `"content-digest": ${digest}`, `"@signature-params": ${params}`].join('\n');
}

export function signHeaders(privateKey, method, url, body, now = Date.now()) {
    const digest = `sha-256=:${createHash('sha256').update(body).digest('base64')}:`;
    const created = Math.floor(now / 1000);
    const params = `("@method" "@target-uri" "content-digest");created=${created};expires=${created + 120};keyid="${KID}";alg="ES256"`;
    const der = sign('sha256', Buffer.from(signatureBase(method, url, digest, params)), privateKey);
    return { 'Content-Digest': digest, 'Signature-Input': `sig=${params}`, Signature: `sig=:${der.toString('base64')}:` };
}

function writePrivate(path, contents) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(`${path}.tmp`, contents, { mode: 0o600 });
    renameSync(`${path}.tmp`, path);
}

/** Created once: the profile URI is the OAuth client_id, so its key must outlive a run. */
export function loadOrCreateKey(path) {
    if (!existsSync(path)) {
        const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
        writePrivate(path, privateKey.export({ type: 'pkcs8', format: 'pem' }));
    }
    const privateKey = createPrivateKey(readFileSync(path));
    const { kty, crv, x, y } = createPublicKey(privateKey).export({ format: 'jwk' });
    return { privateKey, jwk: { kty, crv, x, y, kid: KID, alg: 'ES256', use: 'sig' } };
}

/** Mirrors the shop's capabilities: a profile without them negotiates down to nothing. */
export function profileDocument(capabilities, jwk) {
    return { ucp: { version: UCP_VERSION, capabilities }, signing_keys: [jwk] };
}

async function signedFetch({ fetchImpl, privateKey, profileUri }, method, url, { json, form, token } = {}) {
    const target = canonicalUri(url);
    const body = form ? new URLSearchParams(form).toString() : json !== undefined ? JSON.stringify(json) : '';
    const headers = { Accept: 'application/json', 'UCP-Version': UCP_VERSION, 'UCP-Agent': `profile="${profileUri}"`, ...signHeaders(privateKey, method, target, Buffer.from(body)) };
    if (form) headers['Content-Type'] = 'application/x-www-form-urlencoded';
    else if (json !== undefined) headers['Content-Type'] = 'application/json';
    if (method === 'POST') headers['Idempotency-Key'] = randomBytes(16).toString('base64url');
    if (token) headers.Authorization = `Bearer ${token}`;
    const response = await fetchImpl(target, { method, headers, body: body === '' ? undefined : body });
    const text = await response.text();
    let parsed = text;
    try {
        parsed = text.trim() === '' ? {} : JSON.parse(text);
    } catch {
        // keep the raw text: refusals are the interesting part of a negotiation
    }
    return { status: response.status, body: parsed };
}

/**
 * The buyer's grant on disk (mode 0600). Agentic Commerce rotates refresh
 * tokens and revokes the old one, so a rotated token is written BEFORE the
 * new access token is handed out -- a crash in between must never strand
 * the buyer with a revoked token (Review Focus 5).
 */
export function tokenStore({ file, tokenEndpoint, profileUri, privateKey, fetchImpl = fetch }) {
    const save = (grant) => {
        const previous = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {};
        writePrivate(file, JSON.stringify({
            refreshToken: grant.refresh_token ?? previous.refreshToken,
            accessToken: grant.access_token,
            expiresAt: Date.now() + (grant.expires_in ?? 0) * 1000,
        }));
    };
    const refresh = async (current) => {
        const refreshed = await signedFetch({ fetchImpl, privateKey, profileUri }, 'POST', tokenEndpoint, {
            form: { grant_type: 'refresh_token', refresh_token: current.refreshToken, client_id: profileUri },
        });
        if (refreshed.status !== 200 || !refreshed.body.access_token) {
            throw new Error(`the buyer token did not refresh (HTTP ${refreshed.status}) -- run \`composer run eval:setup\` again`);
        }
        save(refreshed.body);
        return refreshed.body.access_token;
    };
    // Single-flight: parallel lanes share one refresh. A second POST would
    // present a refresh token the first one already rotated out (and revoked).
    let inflight;
    const accessToken = async () => {
        if (!existsSync(file)) throw new Error('no buyer grant -- run `composer run eval:setup`');
        const current = JSON.parse(readFileSync(file, 'utf8'));
        if (current.accessToken && current.expiresAt > Date.now() + 60_000) return current.accessToken;
        return (inflight ??= refresh(current).finally(() => {
            inflight = undefined;
        }));
    };
    return { save, accessToken };
}

export function ucpClient({ shop, profileUri, privateKey, tokens, fetchImpl = fetch }) {
    return {
        async request(method, pathOrUrl, options = {}) {
            const url = pathOrUrl.startsWith('http') ? pathOrUrl : `${shop}${pathOrUrl}`;
            return signedFetch({ fetchImpl, privateKey, profileUri }, method, url, { ...options, token: await tokens.accessToken() });
        },
    };
}

export function startProfileServer({ port, capabilities, jwk }) {
    let resolveCallback;
    const callback = new Promise((resolve) => {
        resolveCallback = resolve;
    });
    const server = createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/.well-known/ucp') {
            response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(profileDocument(capabilities, jwk)));
        } else if (url.pathname === '/callback') {
            resolveCallback(Object.fromEntries(url.searchParams));
            response.writeHead(200, { 'Content-Type': 'text/html' }).end('<h1>Authorized.</h1><p>Back to the terminal.</p>');
        } else {
            response.writeHead(404).end('not found');
        }
    }).listen(port, '127.0.0.1');
    // rejects on a listen error (EADDRINUSE), which is otherwise an uncaught 'error' event
    const listening = new Promise((resolve, reject) => {
        server.once('listening', resolve);
        server.once('error', reject);
    });
    return { callback, listening, close: () => server.close() };
}

/** ngrok v3 on the user's static domain; waits until the profile answers through it. */
export async function startTunnel({ domain, port }) {
    // `--url` per `ngrok http --help` on v3.39.11; older v3 builds called it `--domain`.
    const child = spawn('ngrok', ['http', `--url=https://${domain}`, String(port)], { stdio: 'ignore' });
    for (let attempt = 0; attempt < 30; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        try {
            if ((await fetch(`https://${domain}/.well-known/ucp`)).ok) return { close: () => child.kill() };
        } catch {
            // not up yet
        }
    }
    child.kill();
    throw new Error(`ngrok did not serve https://${domain}/.well-known/ucp within 30 s`);
}

/** PKCE consent on the shop's own storefront page; returns the token grant. Human in the loop. */
export async function consent({ shop, profileUri, redirectUri, privateKey, callback, openUrl, fetchImpl = fetch }) {
    const meta = await (await fetchImpl(`${shop}/.well-known/oauth-authorization-server`)).json();
    const verifier = randomBytes(32).toString('base64url');
    const state = randomBytes(16).toString('base64url');
    const context = { fetchImpl, privateKey, profileUri };
    const registered = await signedFetch(context, 'POST', `${shop}/ucp/quote-agent/authorization-requests`, {
        json: {
            client_id: profileUri, redirect_uri: redirectUri, scope: (meta.scopes_supported ?? []).join(' '), state,
            code_challenge: createHash('sha256').update(verifier).digest('base64url'), code_challenge_method: 'S256',
        },
    });
    if (!registered.body.authorization_url) throw new Error(`the shop refused the authorization request: HTTP ${registered.status} ${JSON.stringify(registered.body)}`);
    openUrl(registered.body.authorization_url);
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('no consent within 10 minutes')), 600_000);
    });
    // cleared, or the pending timer would hold the process open for the full 10 minutes
    const answer = await Promise.race([callback, timeout]).finally(() => clearTimeout(timer));
    if (answer.error) throw new Error(`consent was refused: ${answer.error}`);
    if (answer.state !== state) throw new Error('OAuth state mismatch -- discarded');
    if (!answer.code) throw new Error('no authorization code in the consent callback');
    const granted = await signedFetch(context, 'POST', meta.token_endpoint, {
        form: { grant_type: 'authorization_code', code: answer.code, redirect_uri: redirectUri, client_id: profileUri, code_verifier: verifier },
    });
    if (!granted.body.access_token) throw new Error(`no access_token in the token response: HTTP ${granted.status}`);
    return { grant: granted.body, tokenEndpoint: meta.token_endpoint };
}
