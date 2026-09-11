# Closing the A2CN interface gaps

**Status:** proposed design, 2026-09-10. Implements issues #111, #112, #113, #114, #115. Extends the module designed in `2026-09-04-a2cn-protocol-module-design.md`.

**The problem in one sentence:** the A2CN module can sign, mirror and serve evidence, but a counterparty agent has no way to *give* us any — every act in the live end-to-end test had to be seeded into `quote.custom_fields` through backend access.

**In scope:** an A2CN-conformant inbound act route, the session-to-quote lookup it needs, two timestamp checks that make the chain's timeline defensible, the buyer's organization name on the transaction record, and a reference from that record to the order the quote became.

**Out of scope, with reasons:**

| Left out | Why |
| --- | --- |
| `POST /sessions` (session establishment) | Sessions stay *derived* from quotes. `SessionId::forQuote` is what makes emission idempotent, and `SessionIdCheck` exists to refuse a foreign id. Accepting `session_init` would mean adopting an id we did not open — the one thing that check forbids. |
| A jti anti-replay store | The reference server keeps `(iss, jti)` to reject a replayed request. Ours does not need to: the act append is idempotent on `message_id` and on `(sequence, role)`, so a replayed POST is a no-op that returns the same 200. A store buys nothing and costs a table. |
| Acting on inbound `acceptance` / `rejection` / `withdrawal` | They are stored and mirrored as evidence like any other act. Nothing in the quote's commercial state moves because of one — acts are evidence, never a second input path. |
| Outbound delivery to the buyer's endpoint | Their discovery document advertises one, and the reference client POSTs our counteroffers to it. We stay pull-only: the buyer reads our acts from the chain and from `GET .../messages`. A push path is its own issue. |
| `application/a2cn+json` on the existing read routes | Changing the content type of routes a counterparty already consumes is a break for no gain. The new route uses it; the old ones keep `application/json`. |
| `agreed_terms.custom_terms` for the order reference | `agreed_terms` is the final offer's `terms` **verbatim**. Anything written into it makes the record's own copy disagree with the bytes the offer was signed over. |

## Why

Two facts from the live verification against `b2bseller-shoelscher.eu-core-1.shopdev.de`:

1. There is no public route that accepts an act. `UcpQuoteController` takes `line_items` and `comment` and nothing else. The module design declared this a non-goal for phase one, deliberately (`Inbound acts | Seller-side evidence only`). Phase one is over: an autonomous buyer agent over public HTTP cannot negotiate with us at all.
2. The chain we produced carried a seller counteroffer timestamped 17 seconds *before* the buyer offer it answered, and `EvidenceInspector` reported it clean. An offer timestamped after the counteroffer answering it is not a timeline any auditor will accept.

The remaining three are smaller holes in the same surface: a permanently blank `organization_name` for the initiator, and a transaction record that names the quote but never the order the quote became — which is the entity every downstream ERP, accounting and dispute system actually works with.

## What we verified before designing

Read out of the counterparty's verification kit (`~/projects/_confidential/a2cn-verification-kit-quote-1083/`, extracted to a scratch directory, **not** committed — see `TERMS.txt`) and out of this repository. Not assumed:

- **A2CN has a default transport, and we do not speak it.** `vendor/a2cn/server.py` exposes, relative to the `endpoint` an agent advertises in its discovery document: `POST /sessions`, `GET /sessions/{id}`, **`POST /sessions/{id}/messages`**, `GET /sessions/{id}/messages`, `GET /sessions/{id}/record`, `GET /sessions/{id}/evidence`, `GET /sessions/{id}/audit`, `POST /sessions/{id}/approval-receipt`. `vendor/a2cn/client.py` builds every one of those URLs as `f"{endpoint}/..."`.
- **`endpoint` is not the discovery host.** `client.fetch_discovery(base_url)` reads `{base_url}/.well-known/a2cn-agent`, and every later call is built from the `endpoint` *field* of that document. The two may differ, which is what lets us keep `/.well-known/` at the domain root and still serve conformant session paths under a prefix.
- **Writes are authenticated with an ES256 Bearer JWT** (`verify_jwt_auth`, spec §12.1.4): `iss` is the sender DID, the header `kid` names the verification method, `aud` is the server's DID, `exp` is enforced. §14.1 additionally binds the body: `session_id` must equal the path, and `sender_did` must equal the JWT `iss`.
- **`CompactJws` cannot verify that token.** It refuses any protected-header member outside `alg` and `kid` — deliberately, and a real JWT carries `typ`. The act verifier's strictness is not going to be loosened for a transport token.
- **The transaction record is unconstrained by any shipped schema.** The kit's four schemas cover the session evidence record, the offer, the rejection and the declared mandate — not our `a2cn_transaction_record`. `record.verify_transaction_record()` recomputes `record_hash` over the whole dict and reads named keys, so an added top-level key verifies.
- **The offer schema pins the timestamp format** as `^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$` for both `timestamp` and `expires_at`, which is exactly what `ProtocolTimestamp` documents and what `Act::fromArray()` never checks.
- **`SessionId` is one-way.** UUIDv5 over the quote id under a public namespace. Given a session id there is no way back to the quote — which is the problem the first inbound act runs into, and the reason for the stamp below.
- **The DAL can filter on a custom field.** `EntityDefinitionQueryHelper::getFieldAccessor()` routes `customFields.a2cn_session` through `buildInheritedAccessor` because `CustomFields` is `StorageAware` and not an association, producing a JSON accessor. Unregistered fields compare as strings, which is what a UUID is.
- **`quote.customerId` is the company** on a B2B shop (`QuoteIdentity`'s own docblock), and `QuoteDefinition` declares a `customer` association alongside it.
- **`quote.order` carries no `ApiAware` flag on either 7.12 or 7.13**, but `quote.orderId` does, and `Bridge\History\QuoteHistoryReads` already reads it off the entity. The order number therefore comes from a lookup in the core `order` repository by id, never from traversing the association.
- **`QuoteUpdate.customFields` is shallow-merged** by the gateway, one act per top-level key, precisely so a buyer append and ours cannot destroy each other. The write path for an inbound act already exists.

## The inbound act route (#111)

### Shape

`POST /a2cn/sessions/{sessionId}/messages`, on a new `Protocol\Http\A2cnMessagesController`. `A2cnRecordsController` stays read-only; it is already at the per-class complexity gate.

The discovery document changes in one place: `endpoint` becomes `{base}/a2cn` instead of `{base}`. `mandate_url` and the three `/.well-known/` routes keep the bare base — well-known URIs are domain-root by definition — and `records_url` keeps the value it has today. A conformant client that reads our discovery document then builds `{base}/a2cn/sessions/{id}/messages` and hits the route.

Two aliases, three lines each, added as extra `#[Route]` attributes on methods that already exist:

| Canonical path | Existing method |
| --- | --- |
| `GET /a2cn/sessions/{id}/messages` | `A2cnRecordsController::acts()` |
| `GET /a2cn/sessions/{id}/record` | `A2cnRecordsController::record()` |

`/a2cn/sessions/{id}/acts` and `/a2cn/records/{id}` keep working. With the aliases, the reference client's `get_session_messages` and `get_transaction_record` work against us unmodified, which is the whole point of following the default.

**Not at the domain root.** The canonical paths are `/sessions/...`, and claiming `/sessions` at the root of a Shopware storefront is a landgrab on the merchant's URL space — a CMS page or a future core route can own that. The prefix costs one field in the discovery document and buys collision safety.

### Finding the quote

`SessionId::forQuote` is a hash, so the first act's session id names a quote we cannot look up. Two writes fix it:

1. `QuoteCapability::requestQuote()` stamps `customFields[a2cn_session] = SessionId::forQuote($id)` on the new quote, through the seller-side `QuoteGatewayInterface` (nullable — an unlicensed shop skips it). Failure is logged and swallowed: a stamp is not worth failing a quote request over.
2. The UCP quote snapshot gains `a2cn_session_id` in `toArray()`, so the buyer reads the id instead of reimplementing UUIDv5.

The stamp is inert for everything else. `SellerActEmitter::run()` gates on `$chain->isEmpty()`, which is about *acts*, not about the session key, so a stamped quote with no acts still returns `EmissionOutcome::inert()` and no session is opened unilaterally. `A2cnRecordsController` still 404s a session with no acts.

`Protocol\Http\SessionQuoteLocator` resolves session → quote: `ActStoreInterface::quoteIdForSession()` first (indexed, and correct for every act after the first), then a DAL search with `new EqualsFilter('customFields.a2cn_session', $sessionId)`. An integration test pins the filter; if it ever proves unreliable, the fallback is a two-column `session_id → quote_id` table and the locator is the only class that changes.

### The pipeline

All of it under the existing `QuoteServicingLock` for that quote — two concurrent appends would otherwise compute the same `nextSequence()` and race two acts onto one key, which is the exact failure `ActKey`'s role suffix cannot protect against when both writers are the buyer.

| # | Check | On failure |
| --- | --- | --- |
| 1 | Bearer JWT verifies: ES256, `kid` resolves through `DidWebResolver`, `aud` = this installation's DID, `exp` in the future | 401 `invalid_jwt` |
| 2 | body `session_id` = path `{sessionId}` (§14.1) | 400 `session_id_mismatch` |
| 3 | body `sender_did` = JWT `iss` (§14.1) | 401 `sender_did_mismatch` |
| 4 | `Act::fromArray()` returns an act (this enforces the 64 KiB cap and every envelope field) | 400 `invalid_act` |
| 5 | the session resolves to a quote | 404 `not_found` |
| 6 | the quote is not in a terminal state — `accepted`, `declined`, `expired`, `cancelled` or `withdrawn`, and separately: not expired, and carrying no acceptance act | 409 `session_closed` |
| 7 | the chain is not already full: `count($chain->acts()) < ActChain::MAX_ACTS` | 409 `chain_length_exceeded` |
| 8 | `sender_did` is not our own seller DID, and equals the DID the chain's first foreign act pinned (an empty chain pins it here) | 403 `sender_did_not_party` |
| 9 | `timestamp`, and `expires_at` when present, match the strict Zulu pattern — **#113** | 400 `timestamp_format_invalid` |
| 10 | `timestamp` is not earlier than the chain's last act — **#112** | 409 `timestamp_inversion` |
| 11 | `sequence_number` = `$chain->nextSequence()` | 409 `sequence_conflict` |
| 12 | the act's `protocol_act_hash` covers its own signed view, and its signature verifies against the key its `sender_verification_method` resolves to | 403 `act_unverified` |

An act whose `message_id` already appears on the chain never reaches step 11: it answers `200` with the accepted body and writes nothing. Checked *before* the sequence rule, because a replayed act carries the sequence it was first accepted at and would otherwise read as a conflict. Replays and retries being free is what makes the missing jti store defensible.

On success: `updateQuote($quoteId, new QuoteUpdate(customFields: [ActKey::for($seq, ActRole::Buyer) => $act->raw(), ActKey::SESSION_KEY => $sessionId]))`, then `ChainMirror::mirrorOne()`, then `201 application/a2cn+json`:

```json
{"session_id": "…", "accepted": {"message_id": "…", "sequence_number": 3}}
```

No emission is triggered. The seller act still follows a state transition, exactly as `SellerActEmitter`'s docblock insists — the buyer appends its ask, the agent services the quote, the quote enters `replied`, and the counteroffer is signed then. Which also puts the two acts in causal order by construction.

### The refactor this needs

Step 12 is the verification `BuyerSignatureCheck::reasonItDoesNotVerify()` already performs. It moves to `Protocol\Check\ActVerifier`, which returns the reason an act does not verify or null; `BuyerSignatureCheck` wraps it in a `ProtocolViolation` and the ingress turns it into a 403. One implementation, two callers — not a copy, and not a check invoked through a chain it has no chain for.

The Bearer token gets its own verifier, `Protocol\Http\A2cnBearerJwt`, over `Base64Url` + `Es256Signature` + `openssl_verify`: a JSON claim set, `typ` tolerated, `alg` pinned to ES256 and `none` refused. `CompactJws` is untouched.

### Authorization, stated plainly

The writer is authenticated as an **A2CN agent**, not as the Shopware customer who owns the quote. `Authorization` carries one credential, and A2CN's is the DID-signed JWT, so demanding the agent-customer credential as well would mean an off-the-shelf buyer cannot talk to us — which defeats following the default.

What that leaves: someone who holds a session id (a UUIDv5 over an unguessable quote id) and controls any did:web could open a chain on a quote that has none, or, after act 1, could not append at all — the DID is pinned. The blast radius is a polluted evidence chain on one quote, which `BuyerTermsCheck` and `BuyerSignatureCheck` then flag, and which cannot move a price: the engine reads the Shopware snapshot, never an act. Storage abuse is bounded by the 64 KiB act cap and the 512-act chain cap, both enforced at ingress.

If that ever stops being acceptable, the upgrade is step 8 becoming "the DID must already be recorded on the quote", written there by an authenticated UCP call. That needs the buyer's DID on the quote-request payload and is a separate issue.

### Non-A2CN buyers

An explicit acceptance criterion, because it is the thing most easily broken by accident: a UCP agent that never posts an act sees no change. It gets the same quote, the same states, the same replies. The only difference in its world is one extra field in the quote response and one extra key in `customFields`. There is a regression test for exactly this, driving the full buyer flow with no act anywhere.

## Timestamps (#112, #113)

The strict pattern becomes `ProtocolTimestamp::PATTERN`, next to the format string it mirrors, so the writer and the reader cannot drift apart.

Two checks, both local, registered in `services.php` after `DuplicateSequenceCheck` and before `BuyerSignatureCheck` — the order in that file is normative and every local comparison runs before the network hop:

| `violation_type` | Rejects |
| --- | --- |
| `timestamp_format_invalid` | A counterparty act whose `timestamp`, or whose `expires_at` if present, is not strict ISO-8601 Zulu at second resolution. Registered **first** of the two: an offset-form timestamp makes any ordering comparison meaningless. |
| `timestamp_inversion` | Any chain where `act[i].timestamp < act[i-1].timestamp`, in wire order. Compared as instants, not as strings, so the check is right even about acts that predate the format check. Zero tolerance. |

**Format is checked, never repaired.** Normalizing `+00:00` to `Z` would change the bytes the counterparty signed and break their own hash. The act is refused, verbatim, and the refusal is the record.

**Only counterparty acts are format-checked**; ours go through `ProtocolTimestamp` and cannot fail it. Monotonicity is checked over the *whole* chain, ours included, because an inversion between our act and theirs is exactly the case that was observed.

The chain check is the backstop, not the fix. A chain that reaches it inverted is refused permanently — no seller act is ever emitted for that quote again, and the audit log says why. That is acceptable only because the ingress route now refuses the inverted act before it lands, which is where a live negotiation gets its second chance. A chain seeded by any other means (a backend write, a migration, the fixture path) keeps the loud failure.

## The initiator's organization name (#114)

`RecordPartiesResolver::initiator()` hardcodes `organizationName: ''` because the act wire schema carries no organization. The name comes from Shopware instead:

`QuoteIdentity` gains `companyName` (read from the `customer` association, added to `QuoteSnapshotReader`'s criteria) → `QuoteTerminalState` gains `buyerOrganizationName` → `RecordPartiesResolver::resolve()` passes it into the initiator party.

Shopware's own customer record, not the counterparty's self-declaration: it is who they are to us contractually, it needs no network hop during record derivation, and it cannot be forged by an act. A customer with no company keeps the blank, which is what `RecordParty` already renders.

## The order reference (#115)

`QuoteIdentity` gains `orderId` (read off the quote, never through the association). `QuoteTerminalStateReader` takes the core `order` repository and resolves the number by id; a failed or empty lookup leaves it null and the record is served without the reference.

`TransactionRecord::build()` then emits a top-level

```json
"order_reference": "order:10014"
```

**omitted entirely** when the quote has no order — determinism rule 2, absent never null, and a record for a session that never converted must not carry an empty string that a verifier could read as a claim.

`subject` and `subject_reference` keep the values they have (`1023`, `quote:1023`). A comma-joined compound would be a string two parties have to agree how to parse; a named key is self-describing, and the kit's verifier tolerates it.

## Error handling

| Situation | Behaviour |
| --- | --- |
| Any ingress check fails | The documented status and a `{"status": "<code>"}` body. Nothing is written; nothing is recorded as a protocol violation. A refused *request* is not evidence about the chain. |
| The gateway is absent (unlicensed shop) | `503 {"status":"quote_backend_unavailable"}`, matching how the discovery routes answer a missing key. |
| `MissingSigningKey` while resolving `aud` | `503 {"status":"signing_key_missing"}`. We cannot state who the audience should be. |
| The lock is busy | `409 {"status":"session_busy"}`. The buyer retries; we do not queue. |
| The `customFields` write throws | `502`. The act is not acknowledged, so the buyer's retry is the recovery. |
| The order-number lookup fails | The record is served without `order_reference`. A records request must not depend on a second read succeeding. |
| The customer association is missing | Blank organization name, as today. |

## Testing

**Unit, no shop:**

- `A2cnBearerJwt`: valid token; wrong `aud`; expired; `alg: none`; `alg: RS256`; unresolvable `kid`; a token signed by a different key.
- Each of the twelve ingress rules, one test per refusal, plus the happy path asserting the exact `customFields` written.
- Idempotency: the same act posted twice writes once and answers 200 both times.
- `ActVerifier` keeps every case `BuyerSignatureCheckTest` covers today; `BuyerSignatureCheck` is retested through the extracted class.
- `TimestampFormatCheck`: Zulu passes; `+00:00` fails; a bad `expires_at` fails; a seller act with an odd timestamp is not the counterparty's problem.
- `TimestampMonotonicityCheck`: ordered chain passes; equal timestamps pass; the observed 17-second inversion fails; an inversion between our act and theirs fails.
- Discovery document: `endpoint` carries the `/a2cn` suffix, `mandate_url` and the well-known paths do not.
- `TransactionRecord`: `order_reference` present with an order, the key absent without one, and `record_hash` recomputed over both shapes.
- `RecordPartiesResolver`: the company name lands on the initiator, and a blank stays blank.

**Integration, needs a shop:**

- The `customFields.a2cn_session` DAL filter finds a stamped quote — this is the one assumption in the design that the DAL could disappoint.
- Full inbound round trip: request a quote over UCP, POST act 1 to the canonical path with a real signed JWT, service the quote to `replied`, assert the seller counteroffer is sequence 2 and timestamped after act 1.
- **A UCP-only buyer flow with no act anywhere behaves exactly as it does today** — the regression guard for "it still works without A2CN".
- `order_reference` on a real accepted quote that converted to a real order, on both the 7.13 and the 7.12 lane.

**Acceptance:** unchanged from the module's standing bar. Emit an act through the real `SellerActFactory`, write it plus a matching DID document, and run the counterparty's `tools/verify_record.py` for `VERIFIED`. Nothing in this work may regress that.

## Risks

| Risk | Mitigation |
| --- | --- |
| The `customFields` DAL filter does not behave as read | It has its own integration test, run first. The fallback — a `session_id → quote_id` table — changes only `SessionQuoteLocator`. |
| Changing `endpoint` in the discovery document breaks a consumer that assumed the bare host | The only known consumer is the counterparty's client, which builds session URLs from `endpoint` and reads well-known at the root. Both stay correct. Raise it with them when the conversation resumes. |
| Stamping `a2cn_session` on every quote adds a write to the buyer's hot path | One `customFields` merge, fail-open and logged. Measured before merge; if it costs, it moves to the first act instead and the locator loses its fast path. |
| An attacker with a session id opens a chain on a quote that has none | Stated in full under "Authorization, stated plainly". Bounded, flagged by the existing checks, cannot move a price. The upgrade path is written down. |
| Zero-tolerance monotonicity refuses a chain over honest clock skew between two hosts | Real, and accepted: the ingress refuses the act while the buyer can still correct it, so a chain only reaches the check inverted if it was written outside our route. A tolerance window would have let the observed 17-second inversion through. |
| The extracted `ActVerifier` changes `BuyerSignatureCheck`'s behaviour | It is a move, not a rewrite; the existing test file is the guard and must pass untouched apart from the class it names. |
| `order_reference` breaks a strict external validator | No schema pins our transaction record, and the kit's verifier recomputes `record_hash` over the whole object. Verified by reading `vendor/a2cn/record.py`, not assumed. |
