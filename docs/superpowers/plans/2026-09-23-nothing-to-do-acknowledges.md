# An Empty Extraction Acknowledges the Buyer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A pass that read a buyer comment and found no ask in it posts a deterministic acknowledgement restating the quote and moves the quote back to `replied`, recorded as the new outcome `acknowledged` — except on an escalated quote, which stays silent `nothing_to_do`.

**Architecture:** The existing `hasNoAsk()` gate in `NegotiationPipeline::negotiate()` is unchanged; what happens inside it moves into a new static `Negotiation\PassedOver::handle()` (the pipeline, `OfferRound` and `ReplyComposer` are all at their class complexity limit — measured). `PassedOver` chooses silence or acknowledgement; the acknowledgement is `OfferRound::acknowledge()` → `ReplyComposer::acknowledge()`, which posts `ReplyTemplate::acknowledges()` and reuses `ReplyComposer::send()` for the transition. No model call, no price write.

**Tech Stack:** PHP 8.3, PHPUnit, Mago (lint/analyze), Shopware 6.7 admin (TypeScript, plain-node `*.check.mjs`).

**Spec:** `docs/superpowers/specs/2026-09-23-nothing-to-do-acknowledges-design.md`

## Global Constraints

- `declare(strict_types=1)`; mago analyze at full strictness; no `mixed`, no unsafe casts.
- Gate thresholds: cyclomatic complexity 10 (class), nesting 4, parameters 5, ~400 lines/file.
- `src/Negotiation` may import no Shopware class beyond `IllegalTransitionException` (`NamespacePurityTest`).
- The acknowledgement is NOT a model call and writes nothing to prices, discounts or expiry.
- `answeredTheBuyer()` stays **false** for `Acknowledged`; `AgentDisclosure::stampFor()` **stamps** it.
- An escalated quote (`QuoteEscalator::MARKER_KEY` holds a non-empty string) never acknowledges.
- Keep the log message prefix `Nothing to answer on this quote` and its `commentRead` key; keep `buyer_ask`; do not touch `NoAskFieldCoverageTest`.
- Customer copy: ≤ 5 sentences, no `RewordingGuard` concession word, no figure except the total and the date.
- Match the surrounding comment density: every non-obvious decision gets a short "why" comment, as the neighbouring code does.
- Checks: `composer run format:check && composer run lint && composer run typecheck && composer run test`; admin: `composer run quality:admin`.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: The outcome, its readers, and the template

**Files:**
- Modify: `src/Negotiation/NegotiationOutcome.php`
- Modify: `src/Servicing/AgentDisclosure.php:54-64`
- Modify: `src/Negotiation/ReplyTemplate.php`
- Test: `tests/Unit/Negotiation/InterpretedAskTest.php` (`testAClarificationIsNotAnAnswerToTheBuyer`)
- Test: `tests/Unit/Negotiation/ClarificationMarkerTest.php`
- Test: `tests/Unit/Servicing/AgentDisclosureTest.php`, `tests/Unit/Servicing/AgentDisclosureServicingTest.php`
- Test: `tests/Unit/Negotiation/ReplyTemplateTest.php`

**Interfaces:**
- Produces: `NegotiationOutcome::Acknowledged` (value `'acknowledged'`); `ReplyTemplate::acknowledges(float $total, string $currencyIso, ?\DateTimeImmutable $validUntil): string`.

- [ ] **Step 1: Write the failing tests**

In `InterpretedAskTest::testAClarificationIsNotAnAnswerToTheBuyer`, after the `HandedOver` assertion:

```php
        // An acknowledgement restates the standing quote; it is not an offer.
        // True here would release the escalation and clarification markers
        // and put a figure-less pass in the admin's "Agent Offer" column.
        self::assertFalse(NegotiationOutcome::Acknowledged->answeredTheBuyer());
```

In `ClarificationMarkerTest`, beside the existing `NothingToDo` line:

```php
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::Acknowledged));
```

In `AgentDisclosureTest::outcomes()`, before the `handed over` row:

```php
        // The agent put a comment in front of the buyer.
        yield 'acknowledged - the agent restated the quote' => [NegotiationOutcome::Acknowledged, true];
```

In `AgentDisclosureServicingTest::outcomes()`, before `handed over`:

```php
        yield 'acknowledged' => [NegotiationOutcome::Acknowledged, true];
```

In `ReplyTemplateTest`, after `testHoldsStatesTheTotalAndTheDateWithNoPercentage` (add `use MerchantQuoteAgentPlugin\Negotiation\RewordingGuard;` if absent):

```php
    public function testAcknowledgesRestatesTheQuoteAndNamesBothNextSteps(): void
    {
        $sentence = ReplyTemplate::acknowledges(3500.17, 'EUR', new \DateTimeImmutable('2026-10-08'));

        self::assertSame(
            'Thank you for your message. This quote stands at 3500.17 EUR. '
            . 'The offer remains valid until 2026-10-08. '
            . 'You can accept it as it is, or tell us what you would like changed.',
            $sentence,
        );
    }

    public function testAcknowledgesInventsNoValidityForAQuoteWithoutOne(): void
    {
        $sentence = ReplyTemplate::acknowledges(3500.17, 'EUR', null);

        self::assertSame(
            'Thank you for your message. This quote stands at 3500.17 EUR. '
            . 'You can accept it as it is, or tell us what you would like changed.',
            $sentence,
        );
    }

    /**
     * No model writes this sentence, so the guard never sees it at runtime.
     * Running it through anyway pins the copy to the same rules every
     * buyer-facing sentence obeys: a later edit that adds a figure, a
     * concession word or a sixth sentence fails here.
     */
    public function testAcknowledgesPassesTheRewordingGuard(): void
    {
        $validUntil = new \DateTimeImmutable('2026-10-08');

        self::assertNull(RewordingGuard::unsafeBecause(
            ReplyTemplate::acknowledges(3500.17, 'EUR', $validUntil),
            null,
            3500.17,
            $validUntil,
        ));
        self::assertNull(RewordingGuard::unsafeBecause(
            ReplyTemplate::acknowledges(3500.17, 'EUR', null),
            null,
            3500.17,
            $validUntil,
        ));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --testsuite unit --filter 'InterpretedAskTest|ClarificationMarkerTest|AgentDisclosure|ReplyTemplateTest'`
Expected: FAIL — `Undefined constant NegotiationOutcome::Acknowledged` / undefined method `acknowledges`.

- [ ] **Step 3: Implement**

`NegotiationOutcome.php` — add the case after `HandedOver` and extend the docblock:

```php
 * `Acknowledged` read a buyer comment that held no ask and answered it by
 * restating the quote as it stands, then moved it back to `replied` so the
 * buyer can accept or counter again. It is not an offer, so
 * `answeredTheBuyer()` stays false: it releases neither marker and carries
 * no figures for the admin's offer column.
```

```php
    case Acknowledged = 'acknowledged';
```

`AgentDisclosure::stampFor()` — add `NegotiationOutcome::Acknowledged,` to the stamping arm (after `Escalated`), and add one sentence to the class docblock after the `Clarified` sentence: `Acknowledged restated the quote to the buyer in an agent-written comment.`

`ReplyTemplate.php` — after `holds()`:

```php
    /**
     * The answer to a comment that held no ask: the quote as it stands, and
     * the two things the buyer can do with it. Without a reply the quote never
     * leaves `change_requested`, and over UCP a buyer can neither accept nor
     * counter from there (live quote 1056).
     *
     * The validity sentence is dropped rather than invented when the quote has
     * no expiry: this restates the quote, it does not add a term to it.
     */
    public static function acknowledges(float $total, string $currencyIso, ?\DateTimeImmutable $validUntil): string
    {
        $validity = $validUntil === null
            ? ''
            : sprintf(' The offer remains valid until %s.', $validUntil->format('Y-m-d'));

        return sprintf(
            'Thank you for your message. This quote stands at %s %s.%s '
            . 'You can accept it as it is, or tell us what you would like changed.',
            self::money($total),
            $currencyIso,
            $validity,
        );
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter 'InterpretedAskTest|ClarificationMarkerTest|AgentDisclosure|ReplyTemplateTest|QuoteEscalatorTest'`
Expected: PASS. Then `composer run typecheck` — expected clean (the `AgentDisclosure` match is exhaustive again).

- [ ] **Step 5: Commit**

```bash
git add src/Negotiation/NegotiationOutcome.php src/Servicing/AgentDisclosure.php src/Negotiation/ReplyTemplate.php tests/Unit
git commit -m "feat(negotiation): add the acknowledged outcome and its reply template"
```

---

### Task 2: The acknowledgement path

**Files:**
- Create: `src/Negotiation/PassedOver.php`
- Modify: `src/Negotiation/ReplyComposer.php` (new public `acknowledge()` beside `reply()`)
- Modify: `src/Negotiation/OfferRound.php` (new public `acknowledge()` beside `finishStrandedReply()`)
- Modify: `src/Negotiation/NegotiationPipeline.php:192-213` (the body of the `hasNoAsk()` branch)
- Test: `tests/Unit/Negotiation/NegotiationPipelineTest.php`
- Test: `tests/Unit/Negotiation/RecordedBuyerAskTest.php`
- Test: `tests/Unit/Negotiation/StructuredAskGateTest.php`

**Interfaces:**
- Consumes: `NegotiationOutcome::Acknowledged`, `ReplyTemplate::acknowledges(float, string, ?\DateTimeImmutable): string` (Task 1).
- Produces: `PassedOver::handle(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, BuyerConversation $conversation, ?InterpretedAsk $ask, OfferRound $round): NegotiationPass`; `OfferRound::acknowledge(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void`; `ReplyComposer::acknowledge(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void`.

**Why this shape (measured, do not "simplify" it back):** adding the branch to `OfferRound` or `ReplyComposer` trips mago `cyclomatic-complexity` on the class; `NegotiationPipeline` already carries a `@mago-expect` whose docblock argues its branch count exactly. So the branch lives in a stateless static class that takes the `OfferRound` the pipeline already holds — the `ClarificationRound` pattern — and `OfferRound::acknowledge()` / `ReplyComposer::acknowledge()` are branch-free. No already-answered guard in `ReplyComposer::acknowledge()`: `PassedOver` only acknowledges when `$ask !== null`, and `AskInterpreter::interpret()` returns null unless `hasNewBuyerAsk()` — so a retry after the comment landed reads the agent as newest, gets `$ask === null`, and stays silent.

- [ ] **Step 1: Update and add the failing tests**

`NegotiationPipelineTest` — add `use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;` and `use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;` if absent. Replace `testAnExtractionWithNoAskInAnyFieldEndsThePassAsNothingToDo` with:

```php
    public function testAnExtractionWithNoAskInAnyFieldAcknowledgesTheBuyer(): void
    {
        // #177: quote 1039, "Nice, thanks!" -- every field null or empty. The
        // pass must not reach the band (no unsolicited offer) and must not
        // escalate (#167). Live quote 1056 is why it must still ANSWER: the
        // comment moved the quote to change_requested, only a reply moves it
        // back to replied, and over UCP the buyer can neither accept nor
        // counter until it does.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(state: 'change_requested', comments: [
            NegotiationFixture::buyerComment('Nice, thanks!', '2026-09-18 09:58:40'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame(
            1,
            $harness->spy->calls,
            'The extract call still happens -- the extractor is what found nothing; negotiate and reply must not.',
        );
        self::assertSame([ReplyTemplate::acknowledges(
            $snapshot->totals->buyerFacingTotal(),
            'EUR',
            $snapshot->lifecycle->expiresAt,
        )], $harness->gateway->comments);
        self::assertSame([QuoteTransition::AdminResend], $harness->gateway->transitions);
        self::assertSame([], $harness->gateway->lineItemChanges, 'An acknowledgement writes nothing to prices.');
        self::assertSame([], $harness->gateway->quoteUpdates, 'Nor to the quote itself: no discount, no expiry.');
    }

    public function testAnAcknowledgementQuotesTheGrossTotalTheBuyerReads(): void
    {
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::grossSnapshot(comments: [
            NegotiationFixture::buyerComment('Apply the disconut to the whole quote', '2026-09-23 12:43:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString('stands at 1000.00 EUR', $harness->gateway->comments[0] ?? '');
    }

    public function testAnEmptyExtractionOnAFreshQuoteIsSentAtItsCurrentPrices(): void
    {
        // "Every read comment" is the scope the user chose: an RFQ whose
        // comment asks for nothing is sent as quoted -- always inside the
        // merchant's authority, and what the buyer asked for.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(state: 'open', comments: [
            NegotiationFixture::buyerComment('Please send me a quote.', '2026-09-23 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame([QuoteTransition::Sent], $harness->gateway->transitions);
    }

    public function testAnEscalatedQuoteStaysSilent(): void
    {
        // A human owns this quote and the buyer already has the escalation
        // notice. Acknowledging would contradict it -- and moving the quote to
        // replied makes SellerActPublisher::recordApproval() read the
        // unreleased marker as a human standing behind terms nobody approved.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(state: 'change_requested', comments: [
                NegotiationFixture::buyerComment('ok, thanks', '2026-09-23 09:00:00'),
            ]),
            [QuoteEscalator::MARKER_KEY => 'discount_limit_exceeded'],
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame([], $harness->gateway->comments);
        self::assertSame([], $harness->gateway->transitions);
    }
```

Leave `testNothingNewCostsNothingAndWritesNothing`, `testAReplyPostedByADeadPassStillReachesReplied` and `testAQuoteAHumanLeftInReviewIsNotTransitionedByUs` as they are — all three have `$ask === null` and must stay `NothingToDo`.

`RecordedBuyerAskTest`:
- In `testAPassThatFoundNoAskStillRecordsWhatItWasAsked`: expect `NegotiationOutcome::Acknowledged`, and change the message to `'A pass that found no ask must still say which comment it read, or the acknowledged rows cannot be reviewed.'`
- Rename `testAPassThatAnsweredACommentWithSilenceSaysSoInTheLog` → `testAPassThatFoundNoAskSaysSoInTheLog`, keep its assertions, and add after the `commentRead` assertion:

```php
        self::assertTrue(
            $context['acknowledged'] ?? null,
            'The count that matters is still countable once the comment is answered: '
            . 'acknowledged separates a read comment the agent restated the quote for from a silent pass.',
        );
```

- In `testADuplicateTriggerIsTheSameLineWithTheFlagDown`, add:

```php
        self::assertFalse($harness->logger->contextOf('Nothing to answer on this quote')['acknowledged'] ?? null);
```

- Update the class docblock where it says the pass ends as `NothingToDo`/silence to say a read comment is now acknowledged, and a silent `nothing_to_do` is left for no comment or an escalated quote.

`StructuredAskGateTest` (the test at ~line 60-80 with `'can you do these prices?'` and `requestedUnitPrice: 100.0`): expect `NegotiationOutcome::Acknowledged`, keep `1` model call and the `buyerAsk` assertion, and replace the `ponytail:` paragraph with:

```php
        // The known limit #180 pinned here is now answered: a comment that
        // merely points at an already-granted price gets the acknowledgement
        // that restates it, not silence.
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --testsuite unit --filter 'NegotiationPipelineTest|RecordedBuyerAskTest|StructuredAskGateTest'`
Expected: FAIL — outcome `NothingToDo` where `Acknowledged` is expected; no comment posted; no `acknowledged` log key.

- [ ] **Step 3: Implement `ReplyComposer::acknowledge()`**

Insert directly after `reply()`:

```php
    /**
     * The reply to a comment that held no ask (see PassedOver): the quote as
     * it stands, posted as written. No model call, so there is no rewording
     * for RewordingGuard to check and no reply prompt hash to record.
     *
     * No already-answered guard, unlike reply(): PassedOver only gets here
     * with an interpreted ask, and AskInterpreter only interprets a buyer
     * comment newer than every agent one. A retry after this comment landed
     * reads the agent as newest and never arrives.
     *
     * Same order as reply(): comment, record, then the transition that makes
     * the standing offer acceptable again.
     */
    public function acknowledge(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void
    {
        $text = ReplyTemplate::acknowledges(
            $snapshot->totals->buyerFacingTotal(),
            $snapshot->identity->currencyIso,
            $snapshot->lifecycle->expiresAt,
        );

        $gateway->addComment($snapshot->identity->quoteId, $text);
        $this->recorder->recordReply($text, null);
        $this->send($gateway, $snapshot->identity->quoteId, $snapshot->lifecycle->stateTechnicalName);
    }
```

- [ ] **Step 4: Implement `OfferRound::acknowledge()`**

Insert directly after `finishStrandedReply()`:

```php
    /** Public for PassedOver, which holds this round but not its reply composer. */
    public function acknowledge(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void
    {
        $this->reply->acknowledge($gateway, $snapshot);
    }
```

- [ ] **Step 5: Create `src/Negotiation/PassedOver.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;

/**
 * What a pass with nothing to answer does instead: acknowledge the buyer, or
 * stay silent.
 *
 * A read comment that held no ask ("thanks", "ok", or a question the extract
 * model mis-read as empty) is acknowledged. Silence there dead-ended the
 * buyer: the comment had moved the quote to `change_requested`, only a reply
 * moves it back to `replied`, and over UCP a buyer can neither accept nor
 * counter until it does (live quote 1056). It is still not an escalation --
 * #167 exists so that "thanks" never reaches a human.
 *
 * Silent `NothingToDo` is left for two cases. No comment was read at all (a
 * duplicate trigger, or a reply stranded in `in_review`, which is finished
 * here as before). Or the quote is escalated: a human owns it, the buyer
 * already has the escalation notice, and moving it to `replied` would make
 * `SellerActPublisher::recordApproval()` read the unreleased marker as a human
 * standing behind terms nobody approved. The marker test is that method's own.
 *
 * Static and handed the round, like ClarificationRound, because the pipeline,
 * OfferRound and ReplyComposer are each at their class complexity limit.
 */
final class PassedOver
{
    private function __construct() {}

    public static function handle(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        BuyerConversation $conversation,
        ?InterpretedAsk $ask,
        OfferRound $round,
    ): NegotiationPass {
        $escalation = $snapshot->lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null;

        if ($ask === null || \is_string($escalation) && $escalation !== '') {
            $round->finishStrandedReply($gateway, $snapshot, $conversation);

            return new NegotiationPass(NegotiationOutcome::NothingToDo, extractHash: $ask?->promptHash);
        }

        $round->acknowledge($gateway, $snapshot);

        return new NegotiationPass(NegotiationOutcome::Acknowledged, extractHash: $ask->promptHash);
    }
}
```

- [ ] **Step 6: Wire it into `NegotiationPipeline::negotiate()`**

Replace the body of the `if (($ask === null || $ask->hasNoAsk()) && !StructuredAsk::isUnmet($snapshot))` branch — the long comment, the `logger->info('Nothing to answer on this quote; standing down.', …)`, the `finishStrandedReply()` call and the `return` — with:

```php
            // The one outcome nothing else counts. A comment the agent reads
            // as holding no ask is acknowledged, not escalated (PassedOver) --
            // so if the extract prompt ever regresses, the symptom is real
            // questions getting a polite restatement of the quote, and this
            // line is the only thing that counts them.
            //
            // `commentRead` is what makes the count worth alerting on: false
            // is an ordinary duplicate trigger or a stranded reply, true is a
            // human writing something the agent found no ask in.
            // `acknowledged` says whether they were answered or, on an
            // escalated quote, left to the human. The words themselves stay
            // out of the log and go to the audit record instead (`buyer_ask`).
            $pass = PassedOver::handle($gateway, $snapshot, $conversation, $ask, $this->round);

            $this->logger->info('Nothing to answer on this quote.', [
                'quoteId' => $snapshot->identity->quoteId,
                'commentRead' => $ask !== null,
                'acknowledged' => $pass->outcome === NegotiationOutcome::Acknowledged,
            ]);

            return $pass;
```

Leave the class-level `@mago-expect lint:cyclomatic-complexity` docblock alone: this adds no branch to the class.

- [ ] **Step 7: Run to verify they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter 'NegotiationPipelineTest|RecordedBuyerAskTest|StructuredAskGateTest|ReplyComposerTest|RecordedPassTest|RecordedOutcomePathsTest'`
Expected: PASS.

Then the full gate: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all green. If any other unit test pinned `NothingToDo` for a READ comment with an empty extraction, it now sees `Acknowledged`: update it to the new outcome with a one-line comment pointing at quote 1056, and list it in your report. If `composer run lint` reports `cyclomatic-complexity` on any class, STOP and report — do not add a `@mago-expect`.

- [ ] **Step 8: Commit**

```bash
git add src/Negotiation tests/Unit
git commit -m "feat(negotiation): acknowledge a comment with no ask instead of answering with silence"
```

---

### Task 3: Admin vocabulary

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts` (`OUTCOME_VARIANTS`, `DISPOSITIONS`, `passNotes`)
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`, `snippet/de.json` (`outcome`, `note`)
- Test: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`

**Interfaces:**
- Consumes: the stored outcome value `'acknowledged'` (Task 1).

- [ ] **Step 1: Write the failing assertions** in `decision.check.mjs`, each beside its `nothing_to_do` sibling:

```js
assert.equal(outcomeVariant('acknowledged'), 'neutral');
```
```js
// Restates the quote; not an offer (mirrors NegotiationOutcome::answeredTheBuyer()).
assert.equal(answeredTheBuyer('acknowledged'), false);
```
```js
// The quote is back in `replied` with the standing offer in front of the buyer.
assert.equal(disposition('acknowledged'), 'answered');
```
```js
assert.deepEqual(passNotes(vm, { outcome: 'acknowledged', attempt: 0 }).map((note) => note.key), ['acknowledged']);
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer run quality:admin`
Expected: FAIL on the first new assertion.

- [ ] **Step 3: Implement**

`decision.ts`: add `acknowledged: 'neutral',` to `OUTCOME_VARIANTS` and `acknowledged: 'answered',` to `DISPOSITIONS`; in `passNotes`, after the `nothing_to_do` block:

```ts
    if (round.outcome === 'acknowledged') {
        notes.push(note('acknowledged', 'neutral'));
    }
```

`en.json` → `outcome`: `"acknowledged": "Acknowledged"`; `note`: `"acknowledged": "The customer's message held no request the agent could act on, so it replied by restating the quote as it stands and sent it back for acceptance. Nothing on the quote changed."`

`de.json` → `outcome`: `"acknowledged": "Bestätigt"`; `note`: `"acknowledged": "Die Nachricht des Kunden enthielt keine Anfrage, auf die der Agent reagieren konnte. Er hat das Angebot deshalb unverändert bestätigt und wieder zur Annahme freigegeben. Am Angebot hat sich nichts geändert."`

Keep the key order of the neighbouring entries (after `handed_over` / `handedOver`) and valid JSON (commas).

- [ ] **Step 4: Run to verify it passes**

Run: `composer run quality:admin`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent
git commit -m "feat(admin): label and explain the acknowledged outcome"
```

---

### Task 4: Docs, export help, and the audit docblocks

**Files:**
- Modify: `src/Command/DecisionExportCommand.php:69` (option help)
- Modify: `src/Audit/Export/DecisionExportStream.php:~117` (comment)
- Modify: `src/Audit/DecisionRecorder.php:~130-140` (`recordBuyerAsk` docblock), `src/Audit/QuoteDecisionRecord.php:~236-245` (`buyerAsk` docblock), `src/Migration/Migration1789700000AddBuyerAskToDecision.php:~21` (docblock only — never change migration SQL)
- Modify: `docs/for-merchants.md:~390-405`, `docs/end-to-end.md:~246-250`

- [ ] **Step 1: Export help** — change the option description to:

```php
            'Only records with this outcome (e.g. acknowledged, nothing_to_do, escalated, offered, countered, clarified).'
            . ' An unknown value exports nothing.',
```

- [ ] **Step 2: Comments** — wherever these docblocks say an empty extraction ends the pass as `NothingToDo`/silence, say instead: a read comment with no ask is now `acknowledged` (the quote restated, back to `replied`), and silent `nothing_to_do` remains for no comment read or an escalated quote; reviewing passed-over comments means reading both outcomes. One or two sentences each, in the file's own voice; keep the rest of each docblock.

- [ ] **Step 3: `docs/for-merchants.md`** — keep the existing command block and add the `acknowledged` one above it, then rewrite the paragraph after it:

```
bin/console merchant-quote-agent:export --from=2026-09-01 --to=2026-10-01 \
    --outcome=acknowledged --include-comments
```

"…gives you every pass that read a customer's message and found nothing in it to act on. The agent answered each one by restating the quote and sending it back for acceptance, so the customer is never left waiting — but if one of those messages was a real question, this is where you find it. `--outcome=nothing_to_do` lists the passes that stayed silent: no new message, or a quote already escalated to your team." Keep whatever follows in that section if it still holds; fix it if it says the agent stays silent.

- [ ] **Step 4: `docs/end-to-end.md`** — replace the sentence at ~248 with:

"If there is no ask at all and no unmet structured target price, a pass that read a buyer comment ends as `acknowledged`: it posts `ReplyTemplate::acknowledges()` — the buyer-facing total and expiry as the quote holds them, no model call, no price write — and moves the quote to `replied` (`sent`, or `admin_resend` from the renegotiation states). On an escalated quote, or with no comment read, it ends as `nothing_to_do` instead — first finishing a stranded `in_review → replied` transition, but only when the agent's own comment is the newest one on the quote." Add `acknowledged` wherever that document lists the outcomes.

- [ ] **Step 5: Verify and commit**

Run: `composer run format:check && composer run lint && vendor/bin/phpunit --testsuite unit`
Expected: PASS.

```bash
git add src/Command src/Audit src/Migration docs/for-merchants.md docs/end-to-end.md
git commit -m "docs: the empty-extraction review now reads acknowledged and nothing_to_do"
```

---

## After the tasks (controller, not a subagent)

- Full gate: `composer run quality` if time allows, else `format:check && lint && typecheck && test` plus `quality:admin`.
- Integration suite needs the local test shop (`composer run test:integration`); `tests/Integration/Bench/*` and `DecisionExportTest` touch the outcome — run if the shop is up.
- Live check after deploy to sw-ag.dev (with the user): replay quote 1056's shape; the comment-only counter must get the ack and the next counter/accept must be accepted.
