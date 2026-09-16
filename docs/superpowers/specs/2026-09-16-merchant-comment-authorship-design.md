# A merchant's comment is not the buyer's ask

Date: 2026-09-16

Issue: #55 (1.8, from the 2026-09-02 security audit)

## Status

Approved, implemented on `fix/55-merchant-comment-not-a-buyer-ask`.

## Context

`QuoteComment::isAuthored()` is a two-way discriminator over a three-party
conversation:

```php
return $this->createdById !== null || $this->customerId !== null || $this->employeeId !== null;
```

It was measured, not assumed: #3 found an agent-written comment null on all
three columns, and `AddCommentTest::testAgentCommentCarriesNoAuthorAtAll` pins
that. What the measurement never covered is the third party. A merchant typing
an internal note in the administration writes a comment with `createdById` set,
so `isAuthored()` says true and every reader downstream reads "the buyer".

Four consequences, each verified against the code on 2026-09-16:

1. `QuoteServicingTrigger::onQuoteCommentWritten` filters on the write
   operation, the live version and the agent's own context stamp — and on
   nothing else. A merchant's note queues a servicing pass.
2. `ServicingFingerprint` counts it in both comment components, so the pass it
   queued sees "something new happened" and does not return early.
3. `SnapshotAdapter::conversation()` puts it in the `buyer` bucket, so
   `hasNewBuyerAsk()` is true and `newestBuyerText()` hands the merchant's own
   sentence to the extract model as the ask to answer.
4. SwagCommercial quote comments have no private half — `QuoteEscalator`'s
   docblock says so and `AccountQuoteDetailPageLoader` proves it, loading the
   `comments` association with no authorship filter at all. So the reply to
   "customer wants 10%, check with sales" is written where the customer reads
   it.

### The premise, verified against SwagCommercial's own code

The three-way split the issue proposes is a claim about what SwagCommercial
persists, and this repo has been wrong about exactly that kind of claim before
(see `docs/` and the admin-vocabulary drift). It was therefore checked against
the SwagCommercial source at `/Users/sebastian/projects/SwagCommercial`,
branch `trunk`, `composer.json` version **7.13.0**, commit `ad4947ee`.

**Every** `quote_comment` row in that tree is written through one class,
`src/B2B/QuoteManagement/Domain/Comment/QuoteCommenter.php` (plus
`QuoteHistoryWriter`, which only mirrors an already-written row into the
snapshot lane and copies `createdById` / `customerId` / `employeeId`
verbatim). `QuoteCommenter` resolves authorship in one helper:

```php
private function getAdminUserId(Context $context): ?string
{
    $contextSource = $context->getSource();
    return $contextSource instanceof AdminApiSource ? $contextSource->getUserId() : null;
}
```

- **Merchant, via the administration.** `QuoteActionController::comment()` and
  `::replyHistory()` call `$this->quoteCommenter->comment($context, $comment,
  $quoteId, null, null, null, $lineItemId)` — `customerId` and `employeeId`
  are hard-coded `null` at the call site, and the context is an
  `AdminApiSource`, so `createdById` is the merchant's user id. The
  administration's own `quote-management.api.service.ts` posts to exactly that
  route.
- **Buyer / B2B employee, via the storefront.** `QuoteCommentRoute` (store-api
  scope) passes `$context->getCustomerId()` and the employee extension's id.
  The source is a `SalesChannelApiSource`, never `AdminApiSource`, so
  `createdById` is always null there. `customerId` is set for every
  authenticated storefront session; `employeeId` is set additionally when a B2B
  employee is logged in — the two are not exclusive.
- **Belt and braces.** The definition declares `created_by_id` as core's
  `CreatedByField([CRUD_API_SCOPE, SYSTEM_SCOPE])`
  (`QuoteCommentDefinition.php:81`), whose serializer populates it only on
  INSERT, only on the live version, only when the source is an
  `AdminApiSource` with a user id, and only when no explicit value was given.
  It can therefore never back-fill `created_by_id` for a storefront write, and
  it agrees with the explicit value `QuoteCommenter` already passes.
- **The agent.** A message-handler context carries a `SystemSource`, so all
  three are null — the #3 measurement, unchanged.

So the premise holds on trunk: merchant ⇒ `createdById` only; buyer ⇒
`customerId` and/or `employeeId`; agent ⇒ none.

**Measured against real rows.** The `merchant-quote-shop` container was brought
up afterwards and its `quote_comment` table counted, across both version lanes:

| `created_by_id` | `customer_id` | `employee_id` | rows |
|---|---|---|---|
| — | set | — | 72 |
| — | — | — | 49 |
| set | set | — | 7 |
| set | — | — | 4 |

The four `createdById`-only rows are two real merchant comments, each mirrored
into the snapshot lane — "450 kann ich machen" and "450 is too less. What about
490 instead", typed by an admin user in the administration. That is #55's
subject, in the shop, in the merchant's own words.

The seven rows carrying *both* an admin user and a customer are the interesting
ones, and they are why §1 leans the way it does: their text is the buyer's
("Can you do better on the price?"), seeded through an admin-api context that
also passed a `customerId`. Under `isBuyerAuthored()` they classify as the
buyer's and get serviced, which is correct for what they are — and would have
been the safe answer even if they had not been.

No row anywhere carries `employeeId`, so that column's behaviour rests on source
reading alone.

**What is still not verified.** The released SwagCommercial line (6.7.1.2–6.7.12)
was not re-read; `employee_id` arrived there in a later migration
(`Migration1749437764`), so a legacy shop may have only two of the three
columns, which the design below tolerates by construction.

## Decisions

### 1. Three-way classification lives on `QuoteComment`, as two predicates

```php
public function isAuthored(): bool;      // unchanged — a PERSON wrote it
public function isBuyerAuthored(): bool; // customerId !== null || employeeId !== null
```

Merchant is `isAuthored() && !isBuyerAuthored()`; agent is `!isAuthored()`.

No enum, no third predicate, no author value object: two booleans express three
states, `isAuthored()` keeps its measured meaning and its pin
(`AddCommentTest`, `QuoteCommentTest`), and the agent-side readers that already
spell agent as `!isAuthored()` (`HistoryInjectionAssertions`) keep working
without being touched.

**Direction of doubt.** `isBuyerAuthored()` is positive on `customerId` or
`employeeId`, so anything ambiguous — a hypothetical row carrying both an admin
user and a customer — classifies as *buyer* and gets serviced. Answering
something that was not asked is the harm this issue names; failing to answer a
real buyer is worse. Every rule below leans the same way.

### 2. `SnapshotAdapter::conversation()` drops merchant comments entirely

The `buyer` bucket becomes `isBuyerAuthored()`, the `agent` bucket stays
`!isAuthored()`, and a merchant comment lands in neither — it is not an ask and
it is not something the agent said.

Rejected: feeding merchant notes to the model as thread context. It would put
merchant-internal text into the negotiate prompt, where the model may repeat it
into `replyToBuyer` — which is buyer-visible — or read it as an instruction.
The merchant has a supported channel for steering the agent (the strategy
library and the policy settings); a comment on a quote is not it.

This is what actually protects the buyer: `hasNewBuyerAsk()` goes false, so
`AskInterpreter::interpret()` returns null without a model call, and
`ReplyComposer` has nothing to send.

### 3. The fingerprint counts buyer comments only

`ServicingFingerprint::authored()` becomes `buyerAuthored()` —
`isBuyerAuthored()` instead of `isAuthored()` — feeding both comment
components (the count and the newest `createdAt`).

This is the load-bearing change and the risky one, because the fingerprint is
the mechanism that stops a buyer being answered twice. The reasoning, component
by component:

- **What it must never do is stop moving for a buyer comment.** Nothing in this
  change touches how a buyer comment is counted: a comment with `customerId` or
  `employeeId` still increments the count and still sets the newest timestamp.
  The count still catches a buyer comment older than the agent's reply, which
  is the case a "newest only" marker would hide.
- **What it now ignores is a merchant comment**, which is precisely a comment
  that must not buy a pass. A merchant note therefore leaves the fingerprint
  identical, and the pass it may still have queued returns at
  `ServiceQuoteHandler`'s "nothing has happened on this quote" check, before
  the preflight's escalation path and before any model call.
- **It cannot make a duplicate look new.** The change only ever removes
  components from the composed string, never adds or reorders; two reads of the
  same quote still compose the same string.

**Deploy churn, accepted deliberately.** Markers stamped before this change
counted merchant comments, so on a quote that carries one, the recomputed
fingerprint differs from its stamp exactly once and buys exactly one pass. The
same one-off was accepted for the `asks` component, for the same reason: there
is no migration for a hash, and the alternative (keeping merchant comments in
the count to preserve old stamps) is the bug. That one pass is harmless by
inspection: with §2 in place `hasNewBuyerAsk()` is false, so
`NegotiationPipeline::negotiate()` returns `NothingToDo` unless a structured
line-item ask is genuinely unmet, and the only write on that path is
`OfferRound::finishStrandedReply()`, which does nothing unless the quote is
stuck in `in_review`. A quote with a genuinely unanswered buyer ask gets
answered — which is correct, not churn.

### 4. The trigger skips a comment it can positively identify as the merchant's

`onQuoteCommentWritten` gains one filter, on the written payload:

```php
// merchant iff createdById is set and neither buyer column is
```

It skips only a *positive* identification. A payload that does not say who
wrote it still queues a pass, because the trigger sees a write payload, not the
persisted row, and a missing key must never be read as "nobody". That is safe:
the fingerprint (§3) and the conversation split (§2) both stand behind it, so
the worst case of an unrecognised merchant comment is a queued message that
returns early, and the worst case of a wrongly-skipped buyer comment would be a
dropped ask.

The filter is therefore a cost saving and a defence in depth, not the fix. It
is worth having anyway: it keeps a merchant's note from writing the crash-budget
counter, taking the per-quote lock and logging a pass that did nothing.

### 5. The administration's thread labels a merchant comment as the merchant's

`decision.ts::conversation()` carries the same two-way split in TypeScript, and
its own docblock says the two must move together. Today the detail page's
merged stream renders every non-agent comment under
`merchant-quote-agent.detail.fromCustomer` — so a merchant reads their own
internal note attributed to their customer.

`conversation()` gains `fromMerchant` alongside `fromAgent`, and the stream's
message entry picks the heading from it. Nothing is hidden: a merchant note
belongs in the sequence, because it is often why a pass did what it did. Only
the attribution changes.

## Testing

Unit (run in `composer run test`, no shop needed):

1. `QuoteCommentTest` — a `createdById`-only comment is authored but **not**
   buyer-authored; `customerId`-only and `employeeId`-only are both; the
   all-null agent comment is neither. The existing `isAuthored()` assertions
   stay exactly as they are — they are the #3 pin.
2. `SnapshotAdapterTest` — a merchant comment appears in neither bucket, and a
   merchant comment newer than every buyer comment does not make
   `hasNewBuyerAsk()` true nor become `newestBuyerText()`.
3. `ServicingFingerprintTest` — appending a merchant comment leaves the
   fingerprint identical (the mirror of the existing agent-reply test), while
   the existing buyer-comment test still shows it moving.
4. `decision.check.mjs` — `conversation()` marks a `createdById`-only comment
   `fromMerchant`, a `customerId` one neither, an author-less one `fromAgent`.

Integration (needs a shop, `composer run test:integration`):

5. `ServicingTriggerTest::testAMerchantAdminCommentQueuesNothing` — the issue's
   own acceptance test. Write a comment on a real open quote through the
   `quote_comment` repository with `createdById` set to a real admin user and
   no customer, the way `QuoteActionController` does, with the real trigger
   subscribed: the collecting bus stays empty, and `ServicingFingerprint::of()`
   over a gateway snapshot taken before and after is unchanged. The
   fingerprint half also proves the bridge mapper reads `createdById` off a
   real row — the premise of this whole design, measured rather than asserted.

## Questions not asked, and the assumptions taken instead

No user was reachable while this was designed; each of these would otherwise
have been a question.

1. **Should a merchant comment ever reach the model as context?** Assumed no
   (§2). If merchants want to steer a pass in words, that is a feature with its
   own prompt surface, not a side effect of authorship.
2. **Should a merchant comment suppress a pending buyer ask?** Assumed no: it
   is neither an ask nor an answer, so it leaves `hasNewBuyerAsk()` exactly as
   it was. A merchant who wants the agent to stop has the kill switch and the
   escalation path.
3. **Should the administration hide merchant comments from the stream?**
   Assumed no (§5) — mislabelled, not unwanted.
4. **Does a released 6.7.x shop write authorship the same way?** Assumed yes,
   unverified (see Context). The design degrades safely if `employee_id` is
   absent there: `isBuyerAuthored()` then rests on `customerId`, which is the
   column `CommercialQuoteSnapshotMapper` has always used as its buyer test.

## Risks

- **A buyer comment misclassified as a merchant's would be silently dropped.**
  It requires `customerId` and `employeeId` both null on a storefront write,
  which `QuoteCommentRoute` cannot produce. The trigger's positive-only rule
  and the buyer-leaning predicate are the guards.
- **A future SwagCommercial that stamps `createdById` on agent writes** would
  make every agent comment look like a merchant's. It would also break
  `AddCommentTest`, loudly, which is the signal that already exists for it.
- **The integration test is the only place the premise is measured**, so a shop
  that cannot run it leaves this design resting on source reading alone. It was
  run: `ServicingTriggerTest` is green on `merchant-quote-shop`, and reverting
  the four production files to the pre-fix commit makes the new test fail with
  exactly one queued `ServiceQuoteMessage` — so it reproduces the bug rather
  than merely asserting the fix.

  The full integration suite on that shop, after its pending plugin migrations
  were applied, reports 164 tests with 2 failures:
  `DecisionRecordTest::testAStringLongerThanItsColumnIsRejectedAtWriteTime`
  (the `band` column truncates in the driver instead of the DAL raising a
  `WriteException`) and
  `ServicingConfigGateTest::testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly`
  (two escalation comments where one is required — `QuoteEscalator`'s early
  return reads the marker off the snapshot, and the second pass's fresh read
  does not see the first pass's write). Both reproduce identically on this
  branch's base commit, and neither touches comment authorship, so neither is
  this change's — but both are real and worth their own issues.
