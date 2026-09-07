# A2CN protocol module — design

**Date:** 2026-09-04 · **Status:** approved for planning · **Branch:** `worktree-feat-a2cn-port`

Ports the A2CN evidence layer from the TypeScript `merchant-quote-agent` into this
plugin as a new top-level `src/Protocol/` module: the act chain, the four evidence
checks, the end-of-session records, and the signed seller mandate with its
discovery documents.

## Why now

The plugin advertises and services quotes but produces **no cryptographic
evidence of what was negotiated**. Everything A2CN is still in the TypeScript
repository (~2,500 lines across `src/a2cn/`, `src/crypto/`, `src/app/a2cn-*`) or
on the Agentic Commerce fork (`A2cnActField`, `A2cnMandateProfileContributor` on
`feat/a2cn-act-carrier`). Consequences today:

- #10 (retire the TypeScript quote path) and #8 (second test shop) both gate on
  "the full A2CN scenario set" passing here. That is unsatisfiable while the
  plugin has no A2CN at all, so the TypeScript path cannot be retired.
- The fork cannot be deleted: the buyer-quote-transport plan
  (`docs/superpowers/plans/2026-08-31-buyer-quote-transport.md:2204`) says not to
  delete it until the deferred A2CN pieces have their own issues. They never got
  any.
- #20 (sign outgoing agent messages) is specified as reusing
  "`sha256Base64Url(canonicalizeJson(...))` then `signCompactJws` — no new
  crypto" from a Protocol module that does not exist here.

What already exists is the *invariant* the port needs, deliberately preserved:
`QuoteWriter` shallow-merges `customFields` (one act per top-level key, so buyer
and seller appends never collide), pinned against the live shop by
`tests/Integration/UpdateQuoteTest.php`. `QuoteBaseline`, `ClarificationMarker`,
`QuoteEscalator` and `ServicingFingerprint` all document their own keys as
sitting beside the act chain. `Policy\Data\QuoteLineSnapshot` records that it
dropped `calculatedPrice` because it "only feeds A2CN net-normalization, which
belongs to the later Protocol module" — this is that module.

## Scope decisions

Four decisions taken with the user on 2026-09-04, each narrowing the port:

| Decision | Choice | Consequence |
| --- | --- | --- |
| Interop bar | **Spec-conformant only** | No golden vectors generated from the TypeScript output. The PHP is validated against the A2CN spec and against external RFC vectors, not against the TS bytes. A canonicalization divergence between the two implementations would surface only against a live counterparty — accepted, recorded under Risks. |
| Signing key | **Dedicated A2CN key in `system_config`** | Generated at install, isolated from the UCP request-signing key so rotating one does not invalidate the other. Keygen and JWK handling still reuse the UCP SDK's `SigningKeyManager`. |
| Inbound acts | **Seller-side evidence only** | No `a2cn_act` write path on the quote endpoints; `A2cnActField` is *not* ported. We read whatever chain exists, verify the buyer acts on it, sign our own acts into it, and serve the records. |
| Delivery | **One branch, one PR** | Everything below lands on this worktree branch, reviewed once. |

## Non-goals

Stated so the plan does not drift into them:

- **No inbound act acceptance.** Nothing in this module lets a counterparty POST
  an act. A chain exists only if something else wrote `a2cn_session` +
  `a2cn_act_*` onto the quote's `customFields`.
- **No session establishment.** We never emit `session_init` or `session_ack`.
  The mandate is discovery-style, published and fetchable; it is not delivered
  per session.
- **Only `counteroffer` is emitted.** No `offer`, `acceptance`, `rejection` or
  `withdrawal` from our side, matching the TypeScript behaviour.
- **No price cross-check.** The buyer-terms check compares line identity and
  quantity only. Buyer ask and seller offer legitimately differ on price, and the
  two sides quote in different price spaces on a gross channel.
- **No key rotation, no multi-tenant key.** One key per installation.
- **No receipt-loss retry**, no record caching, no A2A negotiation.
- **Non-price terms stay out of `terms`.** See "Terms mapping".

## Module layout

`src/Protocol/`, one responsibility per group. File-size and complexity caps
(400 lines, cyclomatic 10, 4 nesting levels, 5 parameters) are what drive the
number of classes, not taste.

```
src/Protocol/
  Crypto/    ProtocolHash  CompactJws  Es256Signature  SessionId
  Identity/  A2cnKeyStore  A2cnSigningKey  A2cnIdentity  A2cnIdentityResolver
  Act/       ActRole  ActKey  Act  ActParser  ActChain  SignedView
  Check/     ProtocolViolation  EvidenceCheckInterface  EvidenceInspector
             SessionIdCheck  DuplicateSequenceCheck  BuyerTermsCheck
             BuyerSignatureCheck
  Did/       DidWebResolver  DidDocument  DidWebUrl
  Emitter/   SellerActEmitter  ChainMirror  EmissionOutcome  ObserveInput
             OfferVisibleStateSubscriber  ObserveQuoteMessage  ObserveQuoteHandler
  Http/      A2cnDiscoveryController  A2cnRecordsController
  Mandate/   NegotiationBands  SellerMandateFactory  MandateSigner
             SignedSellerMandate
  Record/    OfferChainHash  TransactionRecord  AuditLog  SessionOutcome
             RecordParty
  Store/     ActStoreInterface  DbalActStore  ApprovalReceipt  ActRecord
  Terms/     MinorUnits  TermsFactory  TermsComparison
src/Ucp/Profile/A2cnMandateProfileContributor.php   (ported from the fork)
src/Migration/Migration<unix-timestamp>CreateA2cnEvidence.php
```

## The emission flow

**The trigger is a state transition, not an agent decision.** A quote entered a
buyer-visible offer state and its terms differ from our last signed act. That one
rule covers the agent's own offer, a human's post-escalation edit, and any future
path that writes an offer; hooking the negotiation pipeline would miss the human
entirely.

`OfferVisibleStateSubscriber` subscribes to `state_machine.quote.state_changed`
(the same core event `QuoteServicingTrigger` uses, enter side only) and dispatches
`ObserveQuoteMessage` when the entered state is **`replied`** — the only
offer-visible state, matching the TypeScript `OFFER_VISIBLE_STATES`. Emission is
never inline in the triggering request: it signs, resolves a `did:web` document
over HTTP, and writes to the quote.

`ObserveQuoteHandler` takes the existing per-quote `QuoteServicingLock` before
running the emitter. The TypeScript serialized concurrent observations with an
in-process promise queue; the Symfony lock is strictly better here because it
holds across workers. A busy lock is a retry, exactly as `ServiceQuoteHandler`
treats it.

`SellerActEmitter::observe(ObserveInput): EmissionOutcome` then runs these gates
in order — the order is normative, and each early return has a reason:

1. **Inert** when `customFields['a2cn_session']` is absent, or the chain is empty.
   No session means no counterparty is negotiating over A2CN; we do not open one
   unilaterally. This is also the module's only feature gate — no config toggle.
2. **Mirror the whole known chain** into our own store, keyed under the session
   *derived from the quote id*, never each act's claimed `session_id`. The whole
   chain, not just what we are about to sign: a buyer act that never triggers an
   emission (terms unchanged, a violation, a state we do not counter into) must
   still reach our independent copy. `append()` is idempotent on
   `(session_id, sequence)`, so re-mirroring is free.
3. **Unchanged** when the state is not offer-visible.
4. **Unchanged** when `TermsComparison::equal(lastSellerAct.terms, terms)` — this
   is what makes the whole path idempotent under duplicate triggers, and it is
   why the subscriber can fire as often as it likes.
5. **Violation** when `EvidenceInspector` finds an evidence problem. The
   violation is persisted and no act is emitted; we do not counter-sign into a
   chain we cannot defend.
6. **Emit.** Build the signed protocol act object, sign it, **mirror before
   wire**, then append to the quote's `customFields` under
   `a2cn_act_<0000-padded sequence>_s` via `QuoteWriter`.

**Mirror before wire** is deliberate and load-bearing: if the wire write then
fails, our mirror already reflects the changed terms, so the next observation
still sees `termsEqual` as false and retries the append — instead of the wire
holding one act, the mirror holding another, and `offer_chain_hash` diverging
silently and permanently.

**Approval receipts.** A receipt records that a human stood behind terms the agent
itself would have escalated. The plugin has no `humanApproved` flag to pass, so
authorship is read from state that already exists: `QuoteEscalator::MARKER_KEY`
(`merchant_quote_agent_escalated`) is set exactly when the agent escalated and has
not answered since — a pass that answers releases it (`QuoteEscalator::releaseFor`).
So at emission time an unreleased marker means the offer now on the quote was
authored by a human resolving that escalation. The receipt's `threshold_crossed`
is the marker's own reason value; absent a marker, no receipt is written. Receipt
persistence has its own try/catch: the act is already mirrored and on the wire, so
a receipt failure must not be reported as a failed emission. A lost receipt is
logged and genuinely lost — there is no retry path.

## The four evidence checks

`EvidenceInspector` returns the first `ProtocolViolation` or none, ordered
cheapest-first — every local comparison before anything that resolves a `did:web`
document over the network.

| Order | `violation_type` | What it rejects |
| --- | --- | --- |
| 1 | `session_id_mismatch` | `chain[0].session_id` is not `SessionId::forQuote(quoteId)`. The session id is *derived*, never accepted from the counterparty: adopting a foreign id would file our signed evidence under a session we did not open. |
| 2 | `duplicate_sequence` | A sequence number claimed more than once. Both acts survive (role-suffixed keys cannot collide), so nothing is lost — but the order is ambiguous and signing into it would attest an order we cannot defend. |
| 3 | `act_terms_mismatch` | A buyer act whose line items disagree with what Shopware actually recorded — line identity and quantity only. Acts are evidence, never a second input path: the engine reads the Shopware snapshot, and an act that disagrees with it is a desync or a forgery attempt. |
| 4 | `buyer_act_unverified` | A buyer act whose signature does not verify against the key its `sender_verification_method` resolves to. Checked last: it is the only network hop. |

A fifth check, `chain_length_exceeded`, guards our own read cap rather than the
counterparty's conduct: a chain longer than `ActChain::MAX_ACTS` is read up to
the cap and refused, because signing into a chain we could only read part of
would attest a position we cannot compute.

Violations are persisted **only** on this path. An exception thrown by our own
signing, store or append code is reported as `emission_failed` and is **not**
persisted as a protocol violation — recording our own bug as evidence against the
counterparty would mislead a legal reader of the audit log.

## Determinism rules

The signed bytes must be reproducible by a counterparty, so these are rules, not
preferences:

1. **Every amount is integer minor units.** `MinorUnits::from(float)` rounds
   **half away from zero** with a `1e-9` correction for binary-float
   representation error (`1.005 * 100` is `100.49999999999999`), and **throws** on
   a non-finite amount rather than letting a `NAN` serialize to `null` and diverge
   the cross-party hash. Not PHP's `round()` default alone: the quote discount is
   a negative line item, so `-1.005` and `1.005` must round symmetrically.
2. **Absent, never null.** An optional field we did not negotiate must be missing
   from the signed bytes: `{a:1}` and `{a:1,b:null}` canonicalize differently.
   **One exception, and it is not ours to choose: the protocol act object.** The
   counterparty's reference `protocol_act_object()` returns all nine signed
   fields unconditionally, so an act with no expiry is signed as
   `"expires_at":null`, and `SignedView` does the same (rule 5). Their
   implementation is what every A2CN party verifies against, which makes it the
   interop authority — a rule of ours that diverges the agreed bytes is a rule
   that produces evidence nobody can check, in both directions: our own acts
   would not verify for them, and we would reconstruct the wrong signed object
   for an act of theirs that carries no expiry. Inside `terms`, and in every
   record this module publishes, absent-never-null still holds; nothing there is
   hashed against a foreign implementation's field list.
3. **`total` is authoritative; `unit_price` is derived** and need not multiply out
   (A2CN determinism rule 3). `unit_price = round(total / quantity)`, or `total`
   when quantity is 0.
4. **Never emit an empty map.** PHP cannot distinguish `[]` from `{}`, and the
   SDK canonicalizer renders an empty array as `[]`. Every map we build
   (`custom_terms`) is unconditionally non-empty; optional maps are omitted
   entirely rather than emitted empty.
5. **The signed field set is normative.** `SignedView` produces exactly
   `protocol_version` (`'0.2'`, the literal the counterparty's reference
   `protocol_act_object()` supplies — not our own record version, which stays
   `0.1`), `session_id`, `round_number`, `sequence_number`,
   `message_type`, `sender_did`, `timestamp`, `expires_at`, `terms` — and nothing
   else. All nine are always present, `null` where the act has no value (see the
   exception in rule 2). Adding a field changes every hash. Envelope fields (`message_id`,
   `in_reply_to`, `sender_agent_id`, `sender_verification_method`, the proof
   itself) are **not** signed.
6. **The hash is `base64url(SHA-256(JCS(object)))`**, and the compact JWS payload
   segment is `base64url(ASCII(that hash string))` — a string payload, not a JSON
   claim set.
7. **Parsing inbound acts is tolerant, and `terms` is kept verbatim.** Unknown
   fields must not make a buyer act invisible to the record, and `terms` is hashed
   wholesale, so `Act` retains the raw `terms` array exactly as read rather than
   round-tripping it through a typed DTO that would drop unmodelled keys.
   `ActParser` returns null for a malformed act instead of throwing: a malformed
   buyer act is an evidence problem, never a reason to stop servicing the quote.

## The act chain on the quote

One act per **top-level** `customFields` key, because Shopware merges
`customFields` shallowly on update. Key format `a2cn_act_<index>_<role>`
(`a2cn_act_0003_s`): zero padding makes lexical order chronological, and the role
suffix (`b` buyer, `s` seller) means the two parties can never target the same
key, so a concurrent append at the same sequence keeps **both** acts instead of
one clobbering the other. Plain lexical sort remains a total order every reader
agrees on, so `offer_chain_hash` stays agreed even after such a collision — the
duplicate sequence is merely visible, and check 2 surfaces it.

`ActChain` provides: `read(customFields)` (parse + lexical order),
`sessionId()`, `nextSequence()`, `nextRound()` (rounds count only `offer` /
`counteroffer` — session establishment does not consume a round), `lastAct()`,
`lastSellerAct(sellerDid)`, `duplicateSequences()`.

`SessionId::forQuote()` is UUIDv5 (SHA-1, RFC 4122/9562) over the quote id under
the A2CN Appendix A namespace `f4a2c1e0-8b3d-4f7a-9c2e-1d5b6a8f3e7c` — derived,
not random, which is what makes the whole path idempotent and the session id
unguessable-but-recomputable.

**Read cap.** Inbound act size is unbounded in the TypeScript version (a caveat it
documents). Here, `ActChain::read()` skips any act whose encoded form exceeds
64 KiB and records nothing for it; a chain longer than 512 acts is read up to the
cap and reported as a violation rather than processed. Both bounds are constants
with a `ponytail:` note.

## Terms mapping

`TermsFactory` maps a `Bridge\Data\QuoteSnapshot` (already **net** throughout —
`QuoteLineNet` takes the tax off each line, reconciled against `amount_net` on the
live shop) to the A2CN v0.2.0 base `terms`:

```
total_value   = sum of line totals, integer minor units
currency      = quote currency ISO
line_items[]  = { id, description, quantity, unit, unit_price, total }
custom_terms  = { tax_status: 'net', quote_number, shopware_net_total_minor }
```

`unit` is the constant `'piece'`: `Bridge\Data\QuoteLineIdentity` carries no unit
field (only the Policy DTO does), so a real unit short code needs the bridge to
read it first, which is out of scope here. `description` falls back to the line
item id when the line has no label. `shopware_net_total_minor` is the quote's own
net total, recorded so any divergence from the summed line totals is **visible
rather than hidden**.

**`payment_terms`, `delivery_terms` and `deposit_bps` are deliberately omitted.**
The TypeScript version populated them from the harness's non-price proposal, but
this plugin does not persist non-price terms at all — that is exactly the open
product decision in #11. Signing a `net_days` the quote does not carry would put a
commitment into cryptographic evidence that nothing downstream honours, which is
worse than omitting it. When #11 lands on option 1 (persist as custom fields), the
mapping extends here, and `custom_terms` gains `deposit_bps` /
`free_shipping_granted` / `expedited` in the sanctioned escape hatch.

`TermsComparison::equal()` compares canonicalized forms, so "terms changed" means
exactly "the signed bytes would change".

## Records

Both records are pure derivations over the chain — producing them ourselves is
what proves our hashes match a counterparty's — and are **derived per request,
never cached**: a stored record can go stale against its own chain.

- `OfferChainHash`: `base64url(SHA-256(JCS([protocol_act_hash, ...])))` in chain
  order. This is what makes a dropped act detectable after the fact.
- `TransactionRecord` (`record_type: a2cn_transaction_record`, version `0.1`):
  `record_id` = UUIDv5(session id), parties, deal type, currency, subject
  (quote number) and `subject_reference` (`quote:<number>`), `agreed_terms` from
  the final offer act, a negotiation summary (round and message counts,
  timestamps, DIDs), the final offer's proof, the final acceptance, the
  offer-chain hash, and a `record_hash` computed over the record with
  `record_hash: ''` in place.
- `AuditLog` (`log_type: a2cn_audit_log`, version `0.1`): session timeline, one
  entry per act (sequence, type, id, sender, timestamp, round, offered total,
  act hash), the persisted protocol violations, and `audit_metadata` with
  `ai_system_involved: true`, `human_oversight_present` = any receipt exists,
  `autonomous_decision` = its negation, and the receipts themselves.
- `SessionOutcome::for(state, expired)`: `declined` → `REJECTED_FINAL`, expired →
  `TIMED_OUT`, otherwise the session is live and has no record yet. `WITHDRAWN`
  and `ERROR` are never produced: the seller does not withdraw, and a protocol
  error leaves the session live with a violation entry instead.

The initiator party is taken from the first act we did not sign; the responder is
this installation's own identity.

## Identity, keys and discovery documents

**Key.** ES256 (P-256), generated at plugin install — and again on `activate()`,
so a shop that updates into this version gets one without being reinstalled —
via the UCP SDK's
`SigningKeyManagerInterface::generate()`, stored as a private JWK in
`system_config` under `MerchantQuoteAgentPlugin.a2cn.signingKeyJwk` — deliberately
**not** under the `…config.*` prefix the admin UI renders, because a private key
must never appear in a config form. `A2cnKeyStore` is the only reader; it throws a
domain exception when the key is missing rather than falling back to an ephemeral
key. (The TypeScript version fell back to a generated dev key and logged that
emitted acts would not verify after a restart — a caveat worth not porting: silent
worthless evidence is worse than a loud refusal.)

**Identity.** `A2cnIdentityResolver` builds, per sales channel:

- `merchantDid` = `did:web:<host>` from the channel's primary domain
  (reusing `SalesChannelDomainUrlReader`'s single-column lookup pattern),
- `verificationMethod` = `<merchantDid>#<kid>`,
- `principalOrganization` = configured organization name, defaulting to the sales
  channel name,
- `agentId` = `merchant-quote-agent` (constant), `authorizedDealTypes` =
  `['goods_procurement']` (constant), `conformanceLevel` = `'acts'`.

One key, many domains: the DID differs per domain, the `kid` and JWK do not.

**Configuration.** Exactly one new `config.xml` field:
`MerchantQuoteAgentPlugin.config.a2cnOrganizationName` (optional text, sales-channel
scoped) — the name published as `principal_organization` / `organization.name`,
defaulting to the sales channel's own name when blank. There is deliberately **no
enable toggle**: the presence of `a2cn_session` on a quote is the gate, so a shop
nobody negotiates with over A2CN pays nothing and shows nothing. The private key
lives outside the `config.*` namespace (above) and so never renders in the admin.

**Documents**, served on the storefront route scope so they answer on the
merchant's own domain — which is the point of moving the mandate here:

| Path | Body |
| --- | --- |
| `/.well-known/a2cn-agent` | Discovery: `a2cn_version` `0.2`, agent DID, verification method, `mandate_methods: ['declared']`, deal types, conformance level, organization, endpoint, `updated_at`, plus the `mandate_url` and `records_url` extensions. |
| `/.well-known/did.json` | did:web document with one `JsonWebKey2020` verification method, `authentication` and `assertionMethod` both referencing it. |
| `/.well-known/a2cn-seller-mandate` | The signed seller mandate. |

Discovery and DID documents are cacheable (`public, max-age=300`); records are
`no-store`.

`A2cnMandateProfileContributor` (ported from the fork) advertises capability
`com.a2cn.negotiation-mandate` in the UCP profile so a buyer agent starting at
`/.well-known/ucp` can discover the mandate. Unlike the fork's version it needs no
`a2cnDiscoveryUrl` config: the URL is our own route on the requested
sales-channel domain. It keeps the fork's lower service priority so the SDK's
capability filter does not drop it (the reason #12 exists upstream).

## The seller mandate

`SellerMandateFactory` packages the bands the agent already enforces —
`NegotiationPolicy` / `QuoteLimits` — declaratively, in **basis points** per the
A2CN `_bps` convention (the merchant configures whole percents; the factory
multiplies by 100):

- `autoGrantMaxBps` from `maxDiscountPercent`, inclusive boundary (mirrors
  `QuoteBandDecider`'s `≤ max + EPSILON`).
- `counterUpToBps` / `counterAtBps` from `counterOfferMaxPercent` — **advisory
  only**, published as the guidance a human follows. The agent never
  auto-counters; anything above the grant ceiling escalates.
- `escalateAboveBps` = `autoGrantMaxBps`.
- `max_commitment_value` from `QuoteValueCeiling::net` in minor units, with
  `max_commitment_currency`.
- `valid_from` = key creation time, `valid_until` = one year later.
- Delivery / payment / bundle blocks are published from the corresponding
  sub-policies when configured, as a documented extension.

`MandateSigner` produces a **detached** JWS proof (`JsonWebSignature2020`,
`verification_method`, `created`, `jws`) over `base64url(SHA-256(JCS(mandate)))`
with the proof excluded from the signed body. Signed once per request cycle;
correctness does not depend on caching, so there is none.

## Storage

One migration, three tables, prefixed like the existing decision-record table and
following `DbalPendingAuthorizationStore`'s access style (direct DBAL, no DAL
entity — nothing here is administrated through Shopware's admin API):

| Table | Columns | Keys |
| --- | --- | --- |
| `merchant_quote_agent_a2cn_act` | `session_id` CHAR(36), `quote_id` CHAR(32), `sequence` INT UNSIGNED, `act` JSON, `created_at` DATETIME(3) | PK `(session_id, sequence)` — makes append idempotent; index on `quote_id` |
| `merchant_quote_agent_a2cn_violation` | `id` BINARY(16), `session_id` CHAR(36), `violation` JSON, `created_at` DATETIME(3) | PK `id`, index on `session_id` |
| `merchant_quote_agent_a2cn_receipt` | `session_id` CHAR(36), `offer_hash` VARCHAR(64), `receipt` JSON, `created_at` DATETIME(3) | PK `(session_id, offer_hash)` |

Ids are stored as the strings the module already works in — the session id in its
canonical UUIDv5 form, the quote id as Shopware's 32-char hex — rather than
`BINARY(16)`. These tables are read by the records endpoints and by a human
auditing a session, never joined against a DAL entity, so hex-to-binary
conversion at every boundary would buy nothing.

Append uses `INSERT … ON DUPLICATE KEY UPDATE` of no meaningful column so a
re-mirror is a no-op rather than an error. `DbalActStore` also answers
`quoteIdForSession()` — the records endpoints need it, and UUIDv5 is not
reversible.

**Uninstall drops all three** when the user did not ask to keep data. #59 records
that uninstall currently leaves the decision-record table behind; this module does
not add a fourth instance of that bug.

## HTTP surface for records

| Route | Response |
| --- | --- |
| `GET /a2cn/sessions/{sessionId}/acts` | `{session_id, acts[]}` from our mirror, or 404 when the session is unknown |
| `GET /a2cn/records/{sessionId}` | The transaction record once an acceptance act exists; otherwise the audit log if the session reached a terminal outcome; otherwise 409 `session_live`. 409 `protocol_violation` / `accepted_without_offer` when an acceptance has no prior offer — reporting the buyer's protocol bug rather than manufacturing a hollow record. 502 when the quote state lookup fails, so a records request does not depend on Shopware being reachable through a generic 500. |

**Authorization is the session id itself** — a UUIDv5 over a Shopware quote UUID,
so unguessable, and the buyer already holds it. A fully public endpoint would let
anyone enumerate a merchant's deal terms; "verification is open to anyone" means
verifying a record you were given. Carried over with its `ponytail:` note: a
capability URL with no revocation, upgradable to signed fetch if a leaked link
ever matters.

## Reuse map

| Need | Reused from | Not written |
| --- | --- | --- |
| RFC 8785 JCS | UCP SDK `Ucp\Sdk\Service\DeterministicJsonInterface` (public alias over `DefaultJsonCanonicalization`) | our own canonicalizer |
| ES256 keygen, JWK ↔ PEM | UCP SDK `SigningKeyManagerInterface`, `PublicSigningKey` | key generation, JWK parsing |
| SHA-256, base64url, ECDSA sign/verify | `hash()`, `openssl_sign`/`openssl_verify`, `sodium_bin2base64` | — |
| Per-quote serialization | `Servicing\QuoteServicingLock` | a second lock |
| `customFields` append that preserves siblings | `Bridge\QuoteWriter` | direct DAL writes |
| Quote read model in net space | `Bridge\QuoteSnapshotReader`, `QuoteLineNet` | tax normalization |
| Authorship signal | `Servicing\QuoteEscalator::MARKER_KEY` | a new flag or column |
| Sales-channel domain URL | `Identity\Authorization\SalesChannelDomainUrlReader` pattern | a new lookup |
| HTTP client for did:web | `guzzlehttp/guzzle` (already required) | a new dependency |

The one genuinely new primitive is `CompactJws`: `openssl_sign` returns a DER
`SEQUENCE`, while a JWS ES256 signature is raw `R‖S` (64 bytes), so
`Es256Signature` converts both ways. No JWS library is added: `firebase/php-jwt`
cannot sign a bare string payload (it insists on a claim array), and
`web-token/jwt-library` pulls a multi-package tree into a plugin zip for ~60 lines
of conversion. The conversion is pinned by an external vector (below), which is
what makes hand-rolling it defensible.

## Testing strategy

Unit tests for every unit, with **external vectors wherever one exists** — this is
what replaces cross-checking against the TypeScript output:

- `CompactJws::verify` against **RFC 7515 Appendix A.3** (ES256 JWS with its
  published P-256 JWK and signature). ECDSA signing is non-deterministic, so
  signing is pinned by sign→verify round trip plus a fixed-vector *verify*.
- `SessionId` against the **RFC 4122/9562 UUIDv5 vector** (namespace DNS +
  `python.org` → `886313e1-3b8a-5372-9b90-0c9aee199e5d`) and then against the A2CN
  namespace for stability.
- `MinorUnits` with explicit vectors: `1.005 → 101`, `-1.005 → -101`,
  `0.005 → 1`, `-0.005 → -1`, `NAN`/`INF` throw.
- `ProtocolHash` pinned to a fixed expected digest for a fixed object, so a JCS
  change in the SDK fails here rather than silently in production.
- `ActChain`: lexical ordering, zero padding, role suffixes, duplicate detection,
  round counting excluding session acts, tolerant parsing of an act with unknown
  fields (and that its `terms` survive verbatim), the size and length caps.
- One test per check, each asserting the exact `violation_type`, plus
  `EvidenceInspector` ordering (a chain with both a session mismatch and an
  unverifiable buyer act reports the mismatch, and resolves no DID).
- `SellerActEmitter`: inert without a session; unchanged on unchanged terms;
  violation persists and emits nothing; emission writes exactly one padded
  seller key; mirror-before-wire proven by a store that succeeds while the writer
  throws; receipt written only with an unreleased escalation marker; a throwing
  signer yields `emission_failed` and persists **no** violation.
- `TermsFactory` from a Bridge snapshot fixture, including the omitted non-price
  fields and the `shopware_net_total_minor` reconciliation figure.
- Records: `offer_chain_hash` stability, `record_hash` computed over the
  hash-blanked record, `accepted_without_offer`, outcome mapping.
- Mandate: bands in bps with the inclusive grant boundary, proof verifiable
  against the published DID document's JWK, and a one-byte edit failing.
- Controllers: response shape and status codes for unknown session, live session,
  terminal session; `no-store` on records.

Integration (live shop, `phpunit.integration.xml.dist`), kept narrow:

- One emission end to end proving the act lands in `customFields` **without
  disturbing sibling keys**, extending the pattern
  `tests/Integration/UpdateQuoteTest.php` already uses for
  `a2cn_act_0001_b` / `_s`.
- One records round trip: emit, then fetch `/a2cn/sessions/{id}/acts` and confirm
  the mirror and the wire agree.

Note for the plan: **CI runs no phpunit at all** (`.github/workflows` has only
quality-gate, plugin-zip and dependency-freshness). Two stale unit tests were
broken on `main` and unnoticed for that reason; they were repaired in this
branch's first commit. Wiring phpunit into CI is #39-adjacent and out of scope
here, but the plan should call the gap out rather than assume green CI means
green tests.

## Caveats carried over from the TypeScript design

Recorded because they remain true, and a reader of the evidence deserves them:

1. **Shopware is custodian of the authoritative chain.** It could in principle
   omit an act. Our mirror is what lets us show the omission; it is not a second
   authority.
2. **Line quantity is checked, price is not.** A desync in price space passes
   check 3.
3. **An approval receipt can be lost while its act is emitted** (see the emitter).
4. **Single-tenant.** One installation, one key, one identity per domain.
5. **No `did:web` path form.** Only `did:web:<host>` is produced. A storefront
   served under a path prefix still resolves its DID document at the domain root,
   which the storefront route provides only when that domain is the root.

## Risks and open items

| Risk | Handling |
| --- | --- |
| Interop divergence with the TypeScript implementation (the "spec-conformant only" choice) | `ProtocolHash` and `MinorUnits` are pinned to fixed vectors, so a *change* is caught; agreement with the TS bytes is not proven. If #10 gets serious about retiring the TS path, generate its vectors then. |
| `/.well-known/did.json` collision with another plugin on the same domain | **Closed by Task 21's live check.** `grep -rn "well-known/did.json" vendor/ custom/plugins` inside the test shop's container finds only this plugin's own references; no other installed plugin claims the route. `did:web:<host>` stays host-only. |
| SDK's JCS implementation is `@internal` behind a public alias | We inject the public interface. `ProtocolHash`'s fixed-digest test fails loudly if an SDK upgrade changes the bytes; a replacement canonicalizer is ~40 lines if that ever happens. |
| #11 (non-price terms) may change the terms mapping | The mapping omits them today rather than signing unpersisted promises; extending it is additive. |
| #20 (outbound message signing) overlaps this crypto | #20 becomes a consumer of `CompactJws` and `ProtocolHash` instead of adding its own; nothing here blocks it. |
| **New, found by Task 21's live proof:** on a storefront domain with a non-default port, the emitter's identity and the discovery document's identity diverge | `SalesChannelHostReader::hostFor()` (which builds `SellerActFactory`'s identity, used to sign acts) keeps a non-default port in the host. `A2cnDiscoveryController::resolveIdentity()` builds identity from `$request->getHost()`, which Symfony's `Request` always strips the port from regardless of the incoming `Host` header. On this shop's own test domain (`localhost:8095`), acts are signed under `did:web:localhost%3A8095`, but `/.well-known/did.json` on that exact host names `did:web:localhost`. Plausibly a one-line fix (`$request->getHttpHost()`, which keeps a non-default port, in place of `getHost()`), but it touches `A2cnDiscoveryController`, outside Task 21's touch scope — reported here rather than fixed, the same way the did.json-collision risk above was handled before this task closed it. |

## Acceptance criteria

1. A quote carrying `a2cn_session` and a buyer act, entering `replied` with
   changed terms, gains exactly one `a2cn_act_<n>_s` key whose act verifies
   against the published DID document, and gains nothing else.
2. The same transition repeated changes nothing (no second act, no error).
3. A quote with no `a2cn_session` is untouched, and no row is written.
4. Each of the four violation types is reachable, is persisted, and suppresses
   emission; an internal failure is logged as `emission_failed` and persists no
   violation.
5. `/.well-known/a2cn-agent`, `/.well-known/did.json` and
   `/.well-known/a2cn-seller-mandate` answer on the sales-channel domain, and the
   mandate's proof verifies against the DID document's JWK using only published
   material.
6. `/a2cn/sessions/{id}/acts` and `/a2cn/records/{id}` answer per the table above;
   an unknown session id yields 404.
7. Servicing is never blocked by this module: with the key absent, the store
   unavailable or `did:web` unreachable, a quote still gets serviced and the
   failure is logged.
8. `composer run quality` and `composer run test` both pass; uninstall without
   "keep user data" leaves no A2CN table behind.
