# Merchant Comment Authorship Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A merchant's admin comment on a quote must not be read as the buyer's ask — it must not queue a servicing pass, must not move the servicing fingerprint, and must never be answered in the buyer-visible comment thread.

**Architecture:** `QuoteComment` gains one predicate, `isBuyerAuthored()` (`customerId` or `employeeId` set). The three readers that currently spell "the buyer" as `isAuthored()` switch to it: `SnapshotAdapter::conversation()` (merchant comments land in neither bucket), `ServicingFingerprint` (buyer comments only), and — new — `QuoteServicingTrigger::onQuoteCommentWritten()`, which skips a write payload it can positively identify as a merchant's. The administration's TypeScript twin of the same split gains `fromMerchant` so the detail page stops attributing a merchant's note to the customer.

**Tech Stack:** PHP 8.3, Shopware 6.7 plugin, PHPUnit 11 (`composer run test` for unit, `composer run test:integration` for the shop suite), Mago (format/lint/analyze), plain TypeScript checked by `node --experimental-strip-types` (`composer run quality:admin`).

**Spec:** `docs/superpowers/specs/2026-09-16-merchant-comment-authorship-design.md`

## Global Constraints

- Do **not** change `QuoteComment::isAuthored()`'s semantics or its existing assertions in `tests/Unit/Bridge/Data/QuoteCommentTest.php` and `tests/Integration/AddCommentTest.php`. It is the measured agent/person discriminator from issue #3 and the whole agent split rests on it.
- Buyer is `customerId !== null || employeeId !== null`. Merchant is `createdById !== null` with both of those null. Agent is all three null.
- Ambiguity always resolves toward *buyer* (i.e. toward servicing). Never drop a buyer ask to avoid a merchant pass.
- Match the surrounding style: long docblocks that explain *why*, grounded in measured evidence. Read the neighbouring class before writing.
- Every commit message ends with: `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`
- `composer run test` and `composer run quality` must be green at the end. Gates run from the worktree root.
- Do not push, do not open a PR, do not touch the GitHub issue.

---

### Task 1: The buyer predicate on `QuoteComment`

**Files:**
- Modify: `src/Bridge/Data/QuoteComment.php`
- Test: `tests/Unit/Bridge/Data/QuoteCommentTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment::isBuyerAuthored(): bool` — true when `customerId !== null || employeeId !== null`. `isAuthored(): bool` keeps its current body and meaning ("a person wrote it"). Merchant is `isAuthored() && !isBuyerAuthored()`; agent is `!isAuthored()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Bridge/Data/QuoteCommentTest.php` (keep every existing test exactly as it is):

```php
    /**
     * The third party. A merchant's note through the administration sets
     * `createdById` and nothing else — SwagCommercial's QuoteActionController
     * hard-codes customerId and employeeId to null and QuoteCommenter takes
     * createdById from the AdminApiSource. It is authored, because a person
     * wrote it, and it is not the buyer's, which is the whole of #55.
     */
    public function testAMerchantCommentIsAuthoredButNotTheBuyers(): void
    {
        $comment = new QuoteComment('check with sales before replying', createdById: 'user-1');

        self::assertTrue($comment->isAuthored());
        self::assertFalse($comment->isBuyerAuthored());
    }

    public function testACustomerCommentIsTheBuyers(): void
    {
        self::assertTrue((new QuoteComment('can you do better?', customerId: 'customer-1'))->isBuyerAuthored());
    }

    /** A B2B employee buying on the company's behalf is the buyer too. */
    public function testAnEmployeeCommentIsTheBuyers(): void
    {
        self::assertTrue((new QuoteComment('can you do better?', employeeId: 'employee-1'))->isBuyerAuthored());
    }

    public function testAnAgentCommentIsNeitherAuthoredNorTheBuyers(): void
    {
        $comment = new QuoteComment('here is our offer');

        self::assertFalse($comment->isAuthored());
        self::assertFalse($comment->isBuyerAuthored());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter QuoteCommentTest`
Expected: FAIL with `Call to undefined method ...QuoteComment::isBuyerAuthored()`.

- [ ] **Step 3: Add the predicate**

In `src/Bridge/Data/QuoteComment.php`, below `isAuthored()`:

```php
    /**
     * The buyer's side of the conversation, which is NOT the same question as
     * `isAuthored()`.
     *
     * Three parties write on a quote and SwagCommercial gives each a different
     * column, measured against its own source (trunk 7.13.0, ad4947ee — every
     * quote_comment row in the tree is written by QuoteCommenter):
     *
     *  - the buyer, from the storefront: `customerId`, plus `employeeId` when
     *    a B2B employee is logged in. QuoteCommentRoute runs in store-api
     *    scope, whose SalesChannelApiSource can never yield an admin user, so
     *    `createdById` is null there — and the definition's CreatedByField
     *    cannot back-fill it, because its serializer requires an AdminApiSource.
     *  - the merchant, from the administration: `createdById` only.
     *    QuoteActionController passes customerId and employeeId as literal
     *    nulls.
     *  - the agent, from a message handler: nothing at all. A SystemSource
     *    yields no author of any kind (#3, pinned by AddCommentTest).
     *
     * So `isAuthored()` answers "did a person write this", which is what tells
     * the agent's own comments apart, and this answers "was it the buyer",
     * which is what tells an ASK apart from a merchant's internal note. #55:
     * one predicate doing both jobs meant a merchant's note queued a servicing
     * pass and got answered in the thread the customer reads.
     *
     * Positive on the buyer columns rather than negative on `createdById`, so
     * anything ambiguous counts as the buyer's and gets serviced: answering
     * something nobody asked is this issue's harm, and never answering a real
     * buyer is worse.
     */
    public function isBuyerAuthored(): bool
    {
        return $this->customerId !== null || $this->employeeId !== null;
    }
```

Also update the class docblock so it no longer claims the three columns split the world in two. Replace the existing class-level docblock with:

```php
/**
 * `createdById` / `customerId` / `employeeId` are how a reader tells the three
 * writers of a quote comment apart: the buyer (customer/employee), the
 * merchant (createdById alone) and the agent (none of them — #3 measured all
 * three as null and AddCommentTest pins it).
 *
 * `isAuthored()` is the agent discriminator; `isBuyerAuthored()` is the ask
 * discriminator. Servicing\ServicingFingerprint and Negotiation\SnapshotAdapter
 * read the second one, and #55 is what happens when they read the first.
 */
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter QuoteCommentTest`
Expected: PASS, including the four pre-existing `isAuthored()` tests.

- [ ] **Step 5: Commit**

```bash
git add src/Bridge/Data/QuoteComment.php tests/Unit/Bridge/Data/QuoteCommentTest.php
git commit -m "$(cat <<'EOF'
feat(bridge): tell a merchant's comment from the buyer's

isAuthored() is a two-way predicate over a three-party conversation: the
buyer writes customerId/employeeId, the merchant writes createdById alone,
and the agent writes none of them. isBuyerAuthored() answers the question
the servicing loop actually asks.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: A merchant comment is neither an ask nor an answer

**Files:**
- Modify: `src/Negotiation/SnapshotAdapter.php` (the `conversation()` method), `src/Negotiation/BuyerConversation.php` (class docblock only)
- Test: `tests/Unit/Negotiation/SnapshotAdapterTest.php`

**Interfaces:**
- Consumes: `QuoteComment::isBuyerAuthored()` and `QuoteComment::isAuthored()` from Task 1.
- Produces: `SnapshotAdapter::conversation()` keeps its signature (`(BridgeSnapshot): BuyerConversation`); `BuyerConversation->buyer` now holds buyer-authored comments only and `->agent` author-less ones only. Merchant comments appear in neither.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Negotiation/SnapshotAdapterTest.php` already has private helpers `self::buyer($text, $at)` and `self::agent($text, $at)` and `self::bridgeSnapshot(array $comments)`. Read the top of the file first, then add a merchant helper next to the existing ones, mirroring their style:

```php
    /** A merchant's note through the administration: createdById, nothing else. */
    private static function merchant(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, createdById: 'user-1', createdAt: new \DateTimeImmutable($at));
    }
```

and these tests:

```php
    /**
     * #55: a merchant's internal note is not the buyer talking. It must not
     * reach the buyer bucket, where it would become the ask a pass answers in
     * the thread the customer reads — SwagCommercial quote comments have no
     * private half.
     */
    public function testAMerchantCommentIsInNeitherBucket(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::merchant('customer wants 10%, check with sales', '2026-08-28 09:00:00'),
        ]));

        self::assertSame([], $conversation->buyer);
        self::assertSame([], $conversation->agent);
    }

    public function testAMerchantCommentIsNotANewAsk(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::buyer('can you do better?', '2026-08-28 09:00:00'),
            self::agent('here is our offer', '2026-08-28 09:30:00'),
            self::merchant('margin is thin on this one', '2026-08-28 10:00:00'),
        ]));

        self::assertFalse($conversation->hasNewBuyerAsk());
        self::assertSame('can you do better?', $conversation->newestBuyerText());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer run test -- --filter SnapshotAdapterTest`
Expected: FAIL — `testAMerchantCommentIsInNeitherBucket` finds the merchant comment in `buyer`, and `testAMerchantCommentIsNotANewAsk` sees `hasNewBuyerAsk()` true with `newestBuyerText()` returning the merchant's sentence.

- [ ] **Step 3: Split three ways**

Replace the body of `SnapshotAdapter::conversation()`:

```php
    /**
     * The comments split by who wrote them — and a merchant's own note belongs
     * to neither side.
     *
     * It is not an ask: answering it writes a reply to an internal note in the
     * one thread the customer reads (#55). And it is not something the agent
     * said, so it must not appear as the agent's prior words in the negotiate
     * prompt either — a merchant's aside is not a promise the agent made, and
     * the model must not repeat it back to the buyer.
     *
     * Dropping it is deliberate rather than incidental: the merchant's channel
     * for steering a pass is the strategy library and the policy settings, not
     * a sentence the buyer can also read.
     */
    public static function conversation(BridgeSnapshot $snapshot): BuyerConversation
    {
        $buyer = [];
        $agent = [];

        foreach ($snapshot->content->comments as $comment) {
            if ($comment->isBuyerAuthored()) {
                $buyer[] = $comment;

                continue;
            }

            if (!$comment->isAuthored()) {
                $agent[] = $comment;
            }
        }

        return new BuyerConversation($buyer, $agent);
    }
```

Then update `BuyerConversation`'s class docblock, whose second paragraph currently says authorship is the discriminator because an agent comment is author-less. Keep that measurement, add the third party:

```php
/**
 * The quote's comments split by who wrote them, which is the whole basis for
 * "is there anything new to answer?".
 *
 * Both buckets are narrow on purpose. `buyer` is the comments SwagCommercial
 * attributes to a customer or a B2B employee; `agent` is the ones with no
 * author at all, which is what the agent's own writes look like (#3 measured
 * all three columns null, pinned by AddCommentTest). A merchant's note, which
 * carries `createdById` alone, is in neither — see SnapshotAdapter's
 * conversation(), and #55 for what it cost while it was in `buyer`.
 *
 * The day SwagCommercial starts stamping an author on a system-source comment
 * is the day the agent bucket needs a new discriminator — and AddCommentTest
 * is what will tell us.
 */
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter SnapshotAdapterTest`
Expected: PASS, including the four pre-existing conversation tests.

Then run the whole negotiation suite, because `conversation()` feeds every pipeline test:

Run: `composer run test -- --filter Negotiation`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Negotiation/SnapshotAdapter.php src/Negotiation/BuyerConversation.php tests/Unit/Negotiation/SnapshotAdapterTest.php
git commit -m "$(cat <<'EOF'
fix(negotiation): a merchant's note is neither the ask nor the agent's reply

conversation() bucketed every authored comment as the buyer's, so a
merchant's internal note became the newest buyer text and the pass answered
it in the storefront thread. It now reaches neither bucket: not an ask, and
not words the agent may be shown as its own.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The fingerprint counts buyer comments only

**Files:**
- Modify: `src/Servicing/ServicingFingerprint.php`
- Test: `tests/Unit/Servicing/ServicingFingerprintTest.php`

**Interfaces:**
- Consumes: `QuoteComment::isBuyerAuthored()` from Task 1.
- Produces: `ServicingFingerprint::of()` and `::stamp()` keep their signatures. The private `authored()` becomes `buyerAuthored()` and filters on `isBuyerAuthored()`; both comment components (count, newest `createdAt`) are computed over buyer comments only.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Servicing/ServicingFingerprintTest.php` uses `QuoteSnapshotFixture::snapshot()` and `QuoteSnapshotFixture::buyerComment()`. Add a merchant fixture to `tests/Unit/Servicing/QuoteSnapshotFixture.php`, next to `buyerComment()`:

```php
    /** A merchant's note through the administration: createdById, nothing else. */
    public static function merchantComment(string $createdAt): QuoteComment
    {
        return new QuoteComment('internal note', createdById: 'user-1', createdAt: new \DateTimeImmutable($createdAt));
    }
```

and this test to `ServicingFingerprintTest`, directly after
`testAppendingAnAgentReplyDoesNotChangeTheFingerprint`:

```php
    /**
     * #55's second half. A merchant's note is not work the agent owes anyone,
     * so it must not read as "something new happened" — otherwise it buys a
     * pass, and that pass reads the note as the ask.
     *
     * This is the check that makes the fix hold even when the trigger cannot
     * tell who wrote a comment: the pass returns at ServiceQuoteHandler's
     * fingerprint comparison, before the preflight and before any model call.
     */
    public function testAppendingAMerchantCommentDoesNotChangeTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);
        $after = QuoteSnapshotFixture::snapshot('open', [
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
            QuoteSnapshotFixture::merchantComment('2026-08-27 10:00:07.000'),
        ]);

        self::assertSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter ServicingFingerprintTest`
Expected: FAIL on the new test — the merchant comment moves both the count and the newest-timestamp component.

- [ ] **Step 3: Narrow the filter to the buyer**

In `src/Servicing/ServicingFingerprint.php`, rename the private `authored()` to `buyerAuthored()`, switch its predicate, and update both call sites in `of()` and `stamp()`:

```php
    /**
     * Only the BUYER's comments. A merchant's own note carries `createdById`
     * and neither buyer column, and it is not work anyone is waiting on:
     * counting it made a merchant's aside look like a new ask, which bought a
     * pass that then answered the aside (#55).
     *
     * @return array<int, QuoteComment>
     */
    private static function buyerAuthored(QuoteSnapshot $snapshot): array
    {
        return array_filter(
            $snapshot->content->comments,
            static fn(QuoteComment $comment): bool => $comment->isBuyerAuthored(),
        );
    }
```

Update the class docblock's component list. Replace the two comment bullets and the closing paragraph so they describe the new rule and keep the measured evidence:

- `- **authored comment count**` becomes `- **buyer comment count**`, with its text ending: `...which a "newest comment" marker alone would hide. The BUYER's comments only: see buyerAuthored().`
- `- **newest authored createdAt**` becomes `- **newest buyer createdAt**`.
- The closing paragraph becomes:

```
 * Two of the three writers are excluded, for different reasons. An agent
 * comment is author-less on createdById, customerId and employeeId alike (#3,
 * pinned by AddCommentTest), so our own reply cannot move the marker and
 * re-trigger us forever. A merchant's note carries createdById alone and is
 * excluded because it is not an ask — see #55; the fingerprint is what makes
 * the trigger's own author filter a cost saving rather than the fix. The 42
 * pre-existing author-less comments in the test shop are historical and
 * static, so they cannot move it either.
 *
 * Both exclusions only ever REMOVE components from the composed string, never
 * add or reorder, so two reads of the same quote still compose the same
 * string. What they do change is the string a quote composed BEFORE this
 * deploy: a quote carrying a merchant comment differs from its own stamp once
 * and buys exactly one pass, the same one-off the asks component accepted.
 * That pass is a no-op by construction — with the conversation split fixed
 * there is no new buyer ask, so the pipeline records nothing_to_do without a
 * model call.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter ServicingFingerprint`
Expected: PASS — the new test plus every existing one, including `testAppendingABuyerCommentChangesTheFingerprint` and the mid-pass-comment test.

- [ ] **Step 5: Commit**

```bash
git add src/Servicing/ServicingFingerprint.php tests/Unit/Servicing/ServicingFingerprintTest.php tests/Unit/Servicing/QuoteSnapshotFixture.php
git commit -m "$(cat <<'EOF'
fix(servicing): a merchant's comment is not "something new happened"

Both comment components counted every authored comment, so a merchant's
note differed the fingerprint from its stamp and bought a servicing pass.
Buyer comments only now; the agent exclusion and the deploy-churn reasoning
are in the docblock.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: The trigger skips a comment it can prove is the merchant's

**Files:**
- Modify: `src/Servicing/QuoteServicingTrigger.php` (the `onQuoteCommentWritten()` method)
- Test: `tests/Integration/ServicingTriggerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks — this reads the DAL write payload, not a `QuoteComment`.
- Produces: `QuoteServicingTrigger::onQuoteCommentWritten()` keeps its signature. New private `static isMerchantComment(array $payload): bool`.

**Note for the implementer:** this task's test is an integration test and needs a running shop. Write it whether or not one is reachable; report honestly whether it executed. The unit-level gates (`composer run test`) do not run `tests/Integration`.

- [ ] **Step 1: Write the failing integration test**

Add to `tests/Integration/ServicingTriggerTest.php`, after `testABuyerCommentQueuesTheQuoteOnce()`. Note the existing helpers in that file: `self::collectingBus()`, `$this->withTrigger($bus, $write)`, `$this->anyCustomerId()`, `QuoteFixture::anyQuoteId(...)`.

```php
    /**
     * #55: a merchant typing an internal note in the administration is not a
     * buyer ask. SwagCommercial's QuoteActionController writes exactly this
     * row — createdById from the AdminApiSource, customerId and employeeId
     * hard-coded null — so this reproduces the persisted shape rather than
     * the transport.
     *
     * Both halves matter. The empty bus is the pass that never gets queued;
     * the unchanged fingerprint is what would stop the pass even if the
     * trigger could not tell who wrote the comment, and it also proves the
     * bridge mapper reads `createdById` off a real row, which is the premise
     * the whole three-way split rests on.
     */
    public function testAMerchantAdminCommentQueuesNothingAndChangesNoFingerprint(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $comments = static::getContainer()->get('quote_comment.repository');
        self::assertInstanceOf(EntityRepository::class, $comments);
        $userId = $this->anyAdminUserId();
        $bus = self::collectingBus();

        $before = ServicingFingerprint::of(static::gateway()->fetchSnapshot($quoteId));

        $this->withTrigger($bus, static function () use ($comments, $quoteId, $userId): void {
            $comments->create([[
                'quoteId' => $quoteId,
                'comment' => 'ServicingTriggerTest merchant note: check with sales before replying',
                'createdById' => $userId,
            ]], Context::createDefaultContext());
        });

        self::assertSame([], $bus->messages, 'A merchant admin comment queued a servicing pass.');
        self::assertSame(
            $before,
            ServicingFingerprint::of(static::gateway()->fetchSnapshot($quoteId)),
            'A merchant admin comment moved the servicing fingerprint, which buys a pass on the next trigger.',
        );
    }

    private function anyAdminUserId(): string
    {
        $repository = static::getContainer()->get('user.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = $repository->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($id, 'The shop has no admin user to attribute a merchant comment to.');

        return $id;
    }
```

Add `use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;` to the file's imports.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test:integration -- --filter testAMerchantAdminCommentQueuesNothingAndChangesNoFingerprint`
Expected (with a shop): FAIL — one `ServiceQuoteMessage` on the bus, and the fingerprint differs.
If no shop is reachable, say so explicitly and continue; do not claim the test ran.

- [ ] **Step 3: Add the author filter**

In `QuoteServicingTrigger::onQuoteCommentWritten()`, inside the write-results loop, directly after the INSERT check:

```php
            if (self::isMerchantComment($result->getPayload())) {
                continue;
            }
```

and the predicate below `isNotOurBusiness()`:

```php
    /**
     * A comment the payload PROVES a merchant wrote: `createdById` set and
     * neither buyer column. SwagCommercial's QuoteActionController writes
     * exactly that shape — it passes customerId and employeeId as literal
     * nulls and QuoteCommenter fills createdById from the AdminApiSource —
     * while the storefront's QuoteCommentRoute, in store-api scope, can never
     * produce an admin user id at all.
     *
     * Positive identification only, and that is the point. This reads a WRITE
     * PAYLOAD, not the persisted row: a key that is simply absent must never
     * be read as "nobody wrote it", or a payload shape we have not seen would
     * silently drop a buyer's ask. Anything unrecognised still queues a pass,
     * and ServicingFingerprint stops it there — a merchant comment leaves the
     * fingerprint identical, so the pass returns before the preflight and
     * before any model call.
     *
     * So this filter is not the fix for #55; the fingerprint and the
     * conversation split are. It is worth having anyway: without it a
     * merchant's note takes the per-quote lock, writes the crash-budget
     * counter and logs a pass that did nothing.
     *
     * @param array<string, mixed> $payload
     */
    private static function isMerchantComment(array $payload): bool
    {
        $createdById = $payload['createdById'] ?? null;

        return \is_string($createdById)
            && $createdById !== ''
            && ($payload['customerId'] ?? null) === null
            && ($payload['employeeId'] ?? null) === null;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test:integration -- --filter ServicingTriggerTest`
Expected (with a shop): PASS, all five tests — the new one plus the four that pin the agent, the buyer and the state transitions.
Without a shop: report that it has never executed.

- [ ] **Step 5: Commit**

```bash
git add src/Servicing/QuoteServicingTrigger.php tests/Integration/ServicingTriggerTest.php
git commit -m "$(cat <<'EOF'
fix(servicing): do not queue a pass for a merchant's own comment

onQuoteCommentWritten filtered on the operation, the version and the agent's
context stamp, but never on who wrote the comment, so a merchant's admin note
queued a pass. It now skips a payload that positively identifies a merchant —
createdById with neither buyer column — and still queues anything it cannot
identify, where the fingerprint stops it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The administration stops calling a merchant's note the customer's

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts` (the `conversation()` function)
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig` (the `entry.kind === 'message'` template)
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en-GB.json` and `snippet/de-DE.json`
- Test: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`

**Interfaces:**
- Consumes: nothing at runtime — this is the TypeScript twin of Task 1's split.
- Produces: each entry from `conversation()` gains `fromMerchant: boolean`. `mergeStream()` is unchanged; it still drops `fromAgent` entries and keeps everything else.

**Note for the implementer:** read the existing snippet files first and follow their nesting exactly — the detail page's keys live under `merchant-quote-agent.detail`, beside `fromCustomer`. The twig change must keep the existing `fromCustomer` key for buyer comments.

- [ ] **Step 1: Write the failing check**

`decision.check.mjs` is a plain assert script, not a test framework. Find the existing `conversation([...])` block (around line 301, asserting `thread.map((m) => m.fromAgent)`), read it, and extend the same fixture array with a merchant comment plus these assertions:

```js
// #55: a merchant's note carries createdById alone. It is not the agent's, and
// it is not the customer's either — the page used to head it "From customer".
const attributed = conversation([
    { id: 'm1', comment: 'Can you do 12%?', customerId: 'c1', createdAt: '2026-09-08T09:00:00+00:00' },
    { id: 'm2', comment: 'We can bring this down by 5%.', createdAt: '2026-09-08T09:05:00+00:00' },
    { id: 'm3', comment: 'Margin is thin here.', createdById: 'u1', createdAt: '2026-09-08T09:10:00+00:00' },
    { id: 'm4', comment: 'Still too much.', employeeId: 'e1', createdAt: '2026-09-08T09:15:00+00:00' },
]);

assert.deepEqual(attributed.map((m) => m.fromAgent), [false, true, false, false]);
assert.deepEqual(attributed.map((m) => m.fromMerchant), [false, false, true, false]);
```

- [ ] **Step 2: Run the check to verify it fails**

Run: `composer run quality:admin`
Expected: FAIL — `fromMerchant` is `undefined` on every entry.

- [ ] **Step 3: Attribute the third writer**

In `decision.ts::conversation()`, add the field to the mapped object:

```ts
            fromAgent: !comment.createdById && !comment.customerId && !comment.employeeId,
            fromMerchant: !!comment.createdById && !comment.customerId && !comment.employeeId,
```

and extend that function's docblock paragraph about authorship (currently "Authorship follows QuoteComment::isAuthored() exactly...") to:

```
 * Authorship follows the backend's three-way split exactly: a comment with
 * customerId or employeeId is the buyer's, one with createdById alone is the
 * merchant's own note, and one with none of them is the agent's — issue #3
 * measured that last one and AddCommentTest pins it. That is not elegant, it
 * is what SwagCommercial writes. If this and the backend ever disagree, the
 * page credits the agent's own words to the customer, so the two must move
 * together — which is why #55 changed both.
```

In the detail page twig, the `message` entry currently hard-codes
`$tc('merchant-quote-agent.detail.fromCustomer')` as its title. Make it pick:

```twig
                                <h4 class="mqa-entry__title">{{ entry.message.fromMerchant ? $tc('merchant-quote-agent.detail.fromMerchant') : $tc('merchant-quote-agent.detail.fromCustomer') }}</h4>
```

and add the `fromMerchant` snippet beside the existing `fromCustomer` in both snippet files (en-GB: `"fromMerchant": "Internal note"`, de-DE: `"fromMerchant": "Interne Notiz"` — match the casing and tone of the neighbouring values).

- [ ] **Step 4: Run the check to verify it passes**

Run: `composer run quality:admin`
Expected: PASS — all three check scripts.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent
git commit -m "$(cat <<'EOF'
fix(admin): head a merchant's own note as an internal note

decision.ts carries the backend's authorship split in TypeScript, so it had
the same two-way bug: every non-agent comment was headed "From customer",
including the merchant's own internal note. The two must move together, and
they now do.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Gates

**Files:** none — this task only runs what is already there and fixes whatever it turns up.

- [ ] **Step 1: Run the unit suite**

Run: `composer run test`
Expected: PASS, no skipped-for-error. If anything else in `tests/Unit` reads `isAuthored()` as "the buyer", fix the caller, not the predicate.

- [ ] **Step 2: Run the quality gate**

Run: `composer run quality`
Expected: PASS. It runs format:check, lint, analyze, the file-length check, the three admin check scripts, jscpd, the dependency analyser and `composer audit`. Mago's formatter is authoritative: if `format:check` fails, run `composer run format` and amend.

- [ ] **Step 3: Attempt the integration suite**

Run: `composer run test:integration -- --filter ServicingTriggerTest`
If the shop container is not running, try `scripts/shop-setup.sh` once; if it still cannot be reached, stop and report plainly that the integration test has never executed. Do not report it as passing.

- [ ] **Step 4: Commit any fixes the gates forced**

```bash
git commit -am "$(cat <<'EOF'
chore: satisfy the quality gate

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

(Skip this step if the gates were green with no changes.)
