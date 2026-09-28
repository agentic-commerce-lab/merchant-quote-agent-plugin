# Draft Mode and Merchant Feedback

Date: 2026-09-23

## Status

Approved in chat, section by section. No issue number yet.

## Problem

The agent acts on its own: once a pass decides, it writes prices, posts the
reply and moves the quote to `replied`, and Flow Builder mails the buyer. A
merchant trying the agent out has no way to let it work while keeping the last
word, and when the agent gets something wrong the only record of *why* is in
the merchant's head.

Two features, one flow:

1. **Draft Mode.** A per-sales-channel toggle. While on, the agent never sends
   anything buyer-visible. It prepares the reply and the price changes and
   hands every quote to a human, who reviews, optionally edits, and sends — or
   rejects.
2. **Feedback.** On any decision the merchant can say what was wrong (reason
   categories plus a comment). Feedback, the text actually sent and the
   changes actually sent land in the JSONL export, so failed drafts can be
   analysed.

## Decisions

| Decision | Chosen | Rejected |
|---|---|---|
| How a draft reaches the buyer | One-click **Send** from a review panel on the decision page | Merchant re-types it in SwagCommercial's editor (slow, error-prone); agent pre-fills the live quote (buyer sees unreviewed prices) |
| Where the drafted prices live | A **DAL version** of the quote | Pricing through quote→cart without persisting (second code path; drafted and sent totals can differ); dry-run write rolled back in a transaction (flows and queue messages fire before the rollback) |
| Feedback shape | Reason categories + comment | Comment only (not countable); thumbs up/down (asks for a rating on every quote) |
| Merchant edits | Reply text **and** drafted prices/discount/validity | Reply text only |
| A2CN mandate while Draft Mode is on | Publish `autoGrantMaxBps: 0` | Leave it advertising an auto-grant nobody performs |

### Why a DAL version works (spike, 2026-09-23)

SwagCommercial's own admin quote editor already drafts every edit in a DAL
version (`quoteRepository.createVersion` → edit → `quoteApiService.recalculate(id, versionId)`
→ `mergeVersion`, `sw-quote-detail/index.ts:530,748,861`), and
`SalesChannelContextRestorer::restoreByQuote()` carries the context's version
into the recalculation. A throwaway integration test on the local shop
(SwagCommercial 7.13.1) wrote through `QuoteWriter` / `QuoteLineItemWriter`
into a version, recalculated there, and merged:

| Case | Live before | Draft version | Live after draft | Live after merge |
|---|---|---|---|---|
| 10% quote-wide | 672.27 net / 800.00 gross | 605.04 / 720.00 | unchanged | 605.04 / 720.00 |
| Per-line −10% | 672.27 / 800.00 | 605.04 / 720.00 | unchanged | 605.04 net |

`QuoteServicingTrigger::isOwnWrite()` already ignores every non-live version
(`QuoteServicingTrigger.php:163`), so draft-version writes never start a pass.
**Not yet verified on the 6.7.12 lane** — the first integration task must run
there before anything is built on top.

## Setting

`draftMode` — `<input-field type="bool">` in the *Agent activation* card,
default `false`, read per sales channel through `QuoteAgentSettingsReader`
like every other key. Exposed as a raw accessor on `QuoteAgentSettings`
(`draftMode(): bool`, `=== true`), the same pattern as
`notifyBuyerOnEscalation()`.

Help text: "The agent prepares every reply and price change but never sends
them. Each draft waits for you under Quote agent, where you can edit, send or
reject it."

Switching it off leaves pending drafts reviewable; new passes act on their
own again, and the buyer's next message supersedes an old draft (below).

## Agent behaviour in Draft Mode

The pipeline runs unchanged up to the offer round. What changes is where its
side effects go.

| Outcome | Today | Draft Mode |
|---|---|---|
| `offered` / `countered` | claim (`process`), write prices/discount/validity, recalculate, verify, post reply, `sent` → `replied` | create a draft version; write, recalculate and verify **in the version**; compose the reply and record it; **no comment, no transition** |
| `clarified` | post the clarifying question | record the question as the draft reply; not posted; no version |
| `acknowledged` | post the quote back as it stands, `sent` / `admin_resend` → `replied` | record the acknowledgement as the draft reply; not posted; no version; **no transition** |
| `escalated` | buyer notice (if `notifyBuyerOnEscalation`) + merchant notification | merchant notification only; **no buyer notice** |
| `nothing_to_do`, `handed_over` | nothing buyer-visible | unchanged |

Also:

- **No disclosure banner.** `AgentDisclosure::stampFor()` does not stamp a
  drafted pass: a human sends the reply.
- **AskMirror is unchanged.** The buyer's own ask is still mirrored onto the
  line's `requested_price` on the live quote. It is the buyer's own data and
  helps the merchant in SwagCommercial's editor.
- **Fingerprint is stamped as today**, so the draft pass is not repeated until
  the buyer does something new.
- **Merchant notification.** `EscalationNotifierInterface` is reused with a
  `draft_ready` notice: the admin notification plus the existing
  `merchant_quote_agent.quote.escalated` flow event, whose reason carries
  `draft_ready`. The seeded (inactive) escalation mail flow therefore covers
  drafts too. No new flow event.
- **Superseding.** A new pass on a quote whose newest decision is `pending`
  deletes that draft version and marks the old row `superseded` before
  drafting again (or acting on its own, if Draft Mode was switched off).
- **A2CN mandate.** `NegotiationBands::fromPolicy()` publishes
  `autoGrantMaxBps: 0` (and `escalateAboveBps: 0`) for a channel in Draft
  Mode, so the signed mandate never claims authority the agent does not use.

### Where the fork sits

- `Negotiation` may not import Shopware (`NamespacePurityTest`), so version
  handling lives in `Bridge`: a draft-version port (create / merge / delete)
  and a way to obtain a `QuoteGatewayInterface` bound to a version.
  `SwagCommercialQuoteGateway` currently hardcodes `AgentContext::create()`
  (live); the version-bound variant uses
  `AgentContext::create()->createWithVersionId($v)` with `STATE` re-added
  (`createWithVersionId` drops states — see `AgentContext`), and reads the
  draft version, not `QuoteVersion::Live`, for its revision precondition and
  snapshots.
- `OfferRound` gets the version-bound gateway for `OfferApplier` in Draft
  Mode. `ReplyComposer` is split into *compose* (reword, returns text + hash)
  and *post* (comment + `send`); Draft Mode calls compose only.
  `ClarificationRound` and `QuoteEscalator` likewise skip their buyer write.
- A failed verification in the version escalates exactly as today; the
  version is deleted, since nothing in it may be sent.

## Review lifecycle

Per decision row: `pending` → `sent` | `rejected` | `superseded`.
Autonomous rows keep `review_status = null`.

A new `src/Review` namespace owns the admin endpoints and the services behind
them. All routes are admin API, under `/api/_action/merchant-quote-agent/decision/{id}/…`,
and guarded by `merchant_quote_agent_decision:update`. The decision entity
stays write-protected: the services write in system scope, like
`EscalationResolutionWriter`.

### Preview — `POST …/preview`

Body: `{ discountPercent?: float, linePrices?: {lineItemId: unitPriceNet}, expiresAt?: date }`.
Only on a `pending` row with a draft version.

1. Write the edits into the draft version through the version-bound gateway;
   recalculate there.
2. Re-compose the reply with `ReplyComposer`'s compose step against the
   version's snapshot (model rewording, template fallback), so the figures in
   the text match.
3. Return `{ totals: {net, gross, before: {net, gross}}, expiresAt, reply }`.

Preview is a convenience; Send does not require it.
Edits and the preview marker commit together only after repricing and reply
composition succeed; a failed preview leaves the stored draft unchanged.
Preview and Send reject an edited total that exceeds the current live total.
If configuration is unavailable, Preview does not claim the existing reply
was freshly checked.

### Send — `POST …/send`

Body: `{ reply: string, discountPercent?, linePrices?, expiresAt? }`. Taken
under the same per-quote lock servicing uses, so a pass and a Send cannot
interleave.

1. **Refuse (409) if stale:** the row is no longer `pending`, the quote is not
   in a state the agent would serve, the buyer wrote again or changed
   requested prices, or the live quote's line prices, quantities, discount,
   totals or validity changed since the draft was prepared. The buyer-input
   half uses `ServicingFingerprint::review()` at draft time and
   `ServicingFingerprint::of()` at review time; the pricing half is a sorted,
   normalized snapshot of live fields. Neither half includes the plugin's
   bookkeeping writes. On released SwagCommercial 6.7.12, DAL merge overwrites
   an intervening live merchant line-price edit even if the draft changed only
   the quote discount; the former assumption that it would survive is false.
   Blocking Send is the safe supported-lane behavior. Reject the stale draft
   and handle the quote in SwagCommercial or wait for a fresh buyer ask.
   Send locks the live quote and its lines, rechecks this fingerprint, and
   merges in one database transaction so a concurrent merchant edit cannot
   land between the final check and merge.
2. Apply any edits to the draft version and recalculate (same code as Preview).
   This edit stage is atomic: an invalid price increase does not leave a changed
   private draft behind.
3. Merge the draft version into live.
4. Claim (`process`) if the quote is still `open`.
5. Post the reply **in the logged-in admin user's context**, so SwagCommercial
   records the merchant as author and `MerchantHandover` sees a human action.
6. `sent` / `admin_resend` → `replied`, as `ReplyComposer::send()` does.
   Flow Builder mails the buyer as today.
7. Record `review_status = sent`, `reviewed_at`, `sent_reply` (always the text
   actually sent) and `sent_changes`.

A `clarified` draft has no version: Send posts the question (steps 1, 5, 7).
An `acknowledged` draft has no version either, but Send still moves the quote
to `replied` after posting it (steps 1, 5, 6, 7): the buyer's comment moved it
to `change_requested` / `reopen`, and only that transition makes the standing
offer acceptable again.

A failure after the merge is logged with the exception and surfaced to the
admin as an error. The row stays `pending` so the failure is visible; there is
no rollback, the same stance `OfferApplier` takes.
Send records the merchant-comment count before publishing. If a failure leaves
the row pending but a new merchant-authored comment is visible, subsequent
review actions return `published` (409), rather than allowing a duplicate Send
or misleading Reject. The merchant must inspect and reconcile the quote.
The `sent_changes` totals come from the live quote after merge and publication,
so unrelated live merchant edits retained by the merge are represented.

### Reject — `POST …/reject`

Deletes the draft version and records `rejected` + `reviewed_at`. The admin
then opens the feedback form. The quote is untouched; the merchant handles it
in SwagCommercial.

### Feedback — `PUT …/feedback`

Body: `{ reasons: list<Reason>, comment: string }`. Allowed on **any**
decision row, not only drafts. Saving again overwrites; `feedback_at` is
restamped.

- `Reason`: `wrong_price`, `wrong_wording`, `misunderstood_buyer`,
  `should_have_escalated`, `should_not_have_escalated`, `other`.
- `comment` at most 2,000 characters.
- At least one reason or a non-empty comment.
- Mapped with valinor (`ArrayMapper`) into a `Review` DTO; no new
  `ValidatorInterface` consumer (AGENTS.md, *Shared contracts*).

## Data

One migration, `Migration1789800000AddDraftReviewToDecision`, adds nullable
columns to `merchant_quote_agent_decision`, idempotent via `SHOW COLUMNS`
like `Migration1789700000AddBuyerAskToDecision`:

| Column | Type | Written by |
|---|---|---|
| `draft_version_id` | `BINARY(16)` | the drafting pass (via `DecisionDraft`) |
| `review_status` | `VARCHAR(16)` | drafting pass; Send / Reject / supersede |
| `review_fingerprint` | `LONGTEXT` | drafting pass; Send compares it with the live quote |
| `reviewed_at` | `DATETIME(3)` | Send / Reject |
| `sent_reply` | `LONGTEXT` | Send |
| `sent_changes` | `JSON` | Send — `{discountPercent, totalNet, totalGross, expiresAt, editedByMerchant}` |
| `feedback_reasons` | `JSON` | Feedback |
| `feedback_comment` | `LONGTEXT` | Feedback |
| `feedback_at` | `DATETIME(3)` | Feedback |

`QuoteDecisionRecord` gains the fields, all admin-API-only and
write-protected like the rest. `draft_version_id` and `review_status` are
mirrored on `DecisionDraft`; the other seven join the later-written exclusion
list in `DraftMirrorsEntityTest`.

`DecisionEraser` nulls `sent_reply` and `feedback_comment` along with the
other free text. The export drops `draft_version_id` and `review_fingerprint`.
Preview temporarily stores only `editedByMerchant: true` in `sent_changes`
so the flag survives a page reload; the export shows `sentChanges` only once
the review status is `sent`, when Send replaces the marker with actual totals.
Before Send, the bridge requires both the draft version record and its quote
row; Reject deletes them in one transaction so a partial deletion cannot be
merged into the live quote. Draft cleanup failures are logged and do not
redeliver the servicing pass.

### Deviations recorded during planning

1. The fork is a `DraftModePipeline` decorator and a `DraftingQuoteGateway`, not branches inside negotiation rounds. This keeps Shopware out of `Negotiation`; the observable outcome table above is unchanged.
2. A separate `review_fingerprint` captures the buyer comments and asks the pass serviced (excluding the pass's own mirrored asks), plus the live pricing snapshot. The handler's stamp alone cannot serve as the Send staleness check, and the pricing component prevents an older supported DAL merge from overwriting an intervening merchant edit.
3. The review card and Preview consume one backend view (`GET /decision/{id}/draft`) of live versus drafted values, rather than reading a DAL version in the administration.
4. `sent_changes` records totals-level values and whether the merchant edited the proposal, not per-line identifiers.
5. Any new pass supersedes a pending draft, including `nothing_to_do`, because a new buyer message makes it stale.
6. Review access is the additional permission `merchant_quote_agent_drafts.review`; the existing `editor` role continues to mean strategy editing.
7. Pending, rejected and superseded drafts do not count as buyer answers on the dashboard. Every drafted pass counts against auto-execution, and pending drafts have the `awaitingReview` disposition.

## Admin

- **List page.** An *Awaiting review* filter (`review_status = pending`) and a
  status badge per row, using the semantic `mt-badge` variants (`success` is
  invalid and renders unstyled).
- **Detail page — review card.** Shown at the top while the newest pass is
  `pending`:
  - proposed changes, live → drafted: per-line net unit prices *or* the
    quote-wide discount %, net and gross totals, valid-until — drafted values
    read from the draft version (admin repository with the version's
    `versionId`), and editable;
  - **Update preview** calls Preview; if the merchant has hand-edited the
    reply, it asks before replacing it;
  - a hint (not a block) when an edited discount exceeds the configured
    maximum — the caps bind the agent, not the merchant;
  - the reply in an editable textarea;
  - **Send** (primary) and **Reject**; a 409 shows why the draft is stale and
    offers Reject.
- **Feedback.** Every pass in the history stream gets *Give feedback*, opening
  a modal (reason checkboxes + comment). Saved feedback shows under the pass.
  After Reject the modal opens automatically; after a Send whose reply or
  prices differ from the draft it is offered.
- **ACL.** A new role *Quote agent: review drafts* granting
  `merchant_quote_agent_decision:update` (plus what the review card reads).
  The viewer role stays read-only.
- **Snippets** in `en-GB` and `de-DE`.
- Pure logic (request payloads, "was it edited", status labels) in
  `review.ts` with a `review.check.mjs`, added to `composer run quality:admin`.

## Export

`AnonymizedDecision` classifies every new field (`ExportFieldCoverageTest`
fails until it does):

| Field | List | Note |
|---|---|---|
| `reviewStatus`, `reviewedAt`, `sentChanges`, `feedbackReasons`, `feedbackAt` | `VERBATIM` | numbers, enums and dates only |
| `sentReply`, `feedbackComment` | `FREE_TEXT` | included by the admin Export button by default; the CLI needs `--include-comments` |
| `draftVersionId` | `DROPPED` | an internal id with no meaning outside the shop |

Drafted vs sent is then readable per row: `replyToBuyer` against `sentReply`,
`totalNetAfter` against `sentChanges.totalNet`.

`docs/for-merchants.md` gets the new fields in its export section and a Draft
Mode section.

## Testing

- **Unit**
  - Pipeline in Draft Mode: no `addComment`, no `transition`, writes go to the
    version-bound gateway; reply recorded; row `pending`.
  - Clarified and escalated passes in Draft Mode post nothing.
  - Superseding marks the old row and deletes its version.
  - `NegotiationBands` publishes 0 bps in Draft Mode.
  - Feedback mapping: reasons, length cap, "reason or comment" rule.
  - Send's stale check; Send and Reject refuse a non-pending row.
  - Config schema/reader/settings tests; migration timestamp test;
    `DecisionEraserTest` and `ExportFieldCoverageTest` via the new fields.
- **Integration** (local 7.13 shop **and** the 6.7.12 lane)
  - Draft pass: live quote unchanged, version holds the verified offer.
  - Preview with edits recalculates in the version only.
  - Send merges, posts as the admin user, reaches `replied`; the trigger does
    not start a pass.
  - Reject deletes the version.
  - Stale refusal after a buyer comment.
- **Admin**: `review.check.mjs`, `quality:admin`, `quality:admin:shop`, then a
  look at the real page locally.

## Out of scope

- Switching a draft between quote-wide and per-line pricing in the panel.
- Turning an escalation into an offer from the panel.
- A dedicated "draft ready" flow event.
- Recording which admin user reviewed or sent.
