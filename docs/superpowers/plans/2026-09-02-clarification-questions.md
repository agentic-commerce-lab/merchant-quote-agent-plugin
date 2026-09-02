# Clarification Questions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the model asks the buyer to clarify an ambiguous ask, send the question to the buyer once, and escalate to a human if the ambiguity survives their answer.

**Architecture:** A third guard in `NegotiationPipeline`, beside the two it already runs after `interpret()`, delegating to a stateless `ClarificationRound` that owns both branches — ask, or escalate because we already asked. A `customFields` marker mirrors `QuoteEscalator`'s, and a new `NegotiationOutcome::Clarified` keeps a clarification from counting as having answered the buyer.

**Tech Stack:** PHP 8.3, Shopware 6.7 plugin, PHPUnit 11, Mago (fmt + lint + typecheck).

**Spec:** `docs/superpowers/specs/2026-09-02-clarification-questions-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file. No `@internal` in `src/`.
- **Never suppress a lint or typecheck finding.** Restructure instead. If a finding cannot be restructured cleanly, stop and ask.
- `mago.toml` thresholds, all `error` level: `cyclomatic-complexity = 10` (measured **per class**), `excessive-parameter-list = 5`, `excessive-nesting = 4`. Roughly 400 lines per file.
- **`NegotiationPipeline` already has exactly 5 constructor parameters. Nothing new may be injected into it.** That is why `ClarificationRound` is stateless.
- `mago.toml` scopes `[source] paths = ["src"]`, so `composer run format` does **not** reach `tests/`. Run `vendor/bin/mago fmt` directly on every test file you touch, or the pre-commit hook rejects the commit.
- Baseline: `vendor/bin/phpunit --testsuite unit` is **403 passing**. It must never go down.
- Gate before each commit: `composer run format`, then `lint`, `typecheck`, `quality:depcheck`.
- Buyer-facing text is customer-facing copy: no field names, no reason values, no internal detail. The clarification questions are posted **verbatim** with nothing appended.
- Comments must go through `QuoteGatewayInterface::addComment()`, which carries `AgentContext::STATE` so the comment cannot re-trigger servicing.
- Commit with **explicit paths only**. Never `git add -A`, never a bare `git commit`.

---

### Task 1: The predicate and the outcome

**Files:**
- Modify: `src/Negotiation/InterpretedAsk.php` (add a method after `hasNonPriceAsk()`)
- Modify: `src/Negotiation/NegotiationOutcome.php`
- Test: `tests/Unit/Negotiation/InterpretedAskTest.php` (create — verified absent)

**Interfaces:**
- Consumes: `CommentInterpretation::$clarificationQuestions` (`list<string>`, already present).
- Produces: `InterpretedAsk::needsClarification(): bool`; `NegotiationOutcome::Clarified` whose `answeredTheBuyer()` is `false`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/InterpretedAskTest.php` — it does not exist yet:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use PHPUnit\Framework\TestCase;

final class InterpretedAskTest extends TestCase
{
    public function testAnAskNeedsClarificationOnlyWhenTheModelAskedSomething(): void
    {
        self::assertTrue($this->ask(['which line do you mean?'])->needsClarification());
        self::assertFalse($this->ask([])->needsClarification());

        // A populated price ask alongside a question is still ambiguous: the
        // question is the model saying it could not place that ask.
        $withPrice = new InterpretedAsk(
            new CommentInterpretation(
                price: new PriceAsk(additionalDiscountPercent: 5.0),
                clarificationQuestions: ['which line do you mean?'],
            ),
            'hash',
        );
        self::assertTrue($withPrice->needsClarification());
    }

    public function testAClarificationIsNotAnAnswerToTheBuyer(): void
    {
        // Load-bearing: QuoteEscalator::releaseFor() clears the escalation
        // marker only for an outcome that answered, so a clarification must
        // not clear an escalated quote's marker.
        self::assertFalse(NegotiationOutcome::Clarified->answeredTheBuyer());
        self::assertTrue(NegotiationOutcome::Offered->answeredTheBuyer());
        self::assertTrue(NegotiationOutcome::Countered->answeredTheBuyer());
    }

    /** @param list<string> $questions */
    private function ask(array $questions): InterpretedAsk
    {
        return new InterpretedAsk(
            new CommentInterpretation(clarificationQuestions: $questions),
            'hash',
        );
    }
}
```

`PriceAsk`'s first constructor parameter is `?float $additionalDiscountPercent = null` (`src/Policy/Data/PriceAsk.php:13`), verified — use it as written.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter InterpretedAskTest`
Expected: FAIL — `Call to undefined method ...::needsClarification()` and an undefined `NegotiationOutcome::Clarified`.

- [ ] **Step 3: Add the predicate**

In `src/Negotiation/InterpretedAsk.php`, after `hasNonPriceAsk()`:

```php
    /**
     * True when the model asked the buyer something instead of guessing. The
     * extract prompt reserves this for asks that are clear in intent but
     * ambiguous in reference — "10% off" on a five-line quote — so answering
     * one by picking a line is exactly the wrong move.
     */
    public function needsClarification(): bool
    {
        return $this->interpretation->clarificationQuestions !== [];
    }
```

- [ ] **Step 4: Add the outcome case**

In `src/Negotiation/NegotiationOutcome.php`, add the case and leave `answeredTheBuyer()` untouched — it already returns false for anything that is not `Offered` or `Countered`:

```php
    case Clarified = 'clarified';
```

Then extend that enum's class docblock with one sentence:

```
 * `Clarified` asked the buyer a question rather than answering them, so it
 * does not clear the escalation marker and does not count as a reply.
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter InterpretedAskTest`
Expected: PASS.

Then `vendor/bin/phpunit --testsuite unit` — expected 405 (403 + 2). If any existing test fails on the new enum case, it is matching exhaustively over `NegotiationOutcome`; report which, do not weaken it.

- [ ] **Step 6: Gate and commit**

```bash
composer run format && composer run lint && composer run typecheck
vendor/bin/mago fmt tests/Unit/Negotiation/InterpretedAskTest.php
git commit --only src/Negotiation/InterpretedAsk.php src/Negotiation/NegotiationOutcome.php tests/Unit/Negotiation/InterpretedAskTest.php -m "feat: recognise an ask the model could not place

The extract prompt reserves clarification_questions for asks that are clear in
intent but ambiguous in reference, so a question is the model reporting that it
could not place the ask -- answering it by picking a line is the wrong move.

Clarified does not answer the buyer, so it does not clear the escalation
marker."
```

---

### Task 2: The marker

**Files:**
- Create: `src/Negotiation/ClarificationMarker.php`
- Modify: `tests/Unit/Negotiation/NegotiationFixture.php` (add one helper)
- Test: `tests/Unit/Negotiation/ClarificationMarkerTest.php`

**Interfaces:**
- Consumes: `NegotiationOutcome::Clarified` (Task 1); `QuoteSnapshot::$lifecycle->customFields`.
- Produces:
  - `ClarificationMarker::MARKER_KEY` (`string`, value `'merchant_quote_agent_clarification_asked'`)
  - `ClarificationMarker::alreadyAsked(QuoteSnapshot $snapshot): bool`
  - `ClarificationMarker::set(): array<string, true>` — the fragment to write
  - `ClarificationMarker::releaseFor(NegotiationOutcome $outcome): array<string, null>`
  - `NegotiationFixture::withCustomFields(QuoteSnapshot $snapshot, array $customFields): QuoteSnapshot`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/ClarificationMarkerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The marker is what makes "ask once" true. If it read as set when it is not,
 * an ambiguity escalates without the buyer ever being asked; if it read as
 * unset when it is set, a talkative buyer collects one question per comment.
 */
final class ClarificationMarkerTest extends TestCase
{
    public function testItIsOnlyAlreadyAskedWhenTheMarkerIsPresentAndTrue(): void
    {
        self::assertFalse(ClarificationMarker::alreadyAsked(NegotiationFixture::snapshot()));

        $asked = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(),
            [ClarificationMarker::MARKER_KEY => true],
        );
        self::assertTrue(ClarificationMarker::alreadyAsked($asked));

        // A released marker is written as null, and an unrelated key must not
        // read as asked.
        $released = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(),
            [ClarificationMarker::MARKER_KEY => null, 'other_key' => true],
        );
        self::assertFalse(ClarificationMarker::alreadyAsked($released));
    }

    public function testOnlyAnAnsweringPassReleasesTheMarker(): void
    {
        self::assertSame([ClarificationMarker::MARKER_KEY => null], ClarificationMarker::releaseFor(NegotiationOutcome::Offered));
        self::assertSame([ClarificationMarker::MARKER_KEY => null], ClarificationMarker::releaseFor(NegotiationOutcome::Countered));

        // The pass that wrote the marker must not erase it, or the next buyer
        // comment is asked the same question again instead of escalating.
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::Clarified));
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::Escalated));
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::NothingToDo));
    }

    public function testTheSetFragmentWritesTheMarker(): void
    {
        self::assertSame([ClarificationMarker::MARKER_KEY => true], ClarificationMarker::set());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ClarificationMarkerTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker" not found`.

- [ ] **Step 3: Add the fixture helper**

In `tests/Unit/Negotiation/NegotiationFixture.php`, add this method. Do **not** add a parameter to `snapshot()` — it already has five and the pre-commit hook lints test files too.

```php
    /**
     * @param array<string, mixed> $customFields
     */
    public static function withCustomFields(QuoteSnapshot $snapshot, array $customFields): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $snapshot->lifecycle->stateTechnicalName,
                expiresAt: $snapshot->lifecycle->expiresAt,
                customFields: $customFields,
            ),
            content: $snapshot->content,
        );
    }
```

`QuoteLifecycle` is already imported in that file; confirm, and add the import if not.

- [ ] **Step 4: Write the marker**

Create `src/Negotiation/ClarificationMarker.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * Whether this quote has already been asked to clarify, so the agent asks once
 * and then hands an ambiguity that survived the answer to a human.
 *
 * Modelled on QuoteEscalator's marker and for the same reason: without one, a
 * misconfigured shop with a talkative buyer collects one question per buyer
 * comment. It clears the moment a pass actually answers with an offer, so
 * "once" means once per stuck point rather than once per quote lifetime — a
 * genuinely new ambiguity months later still gets asked about instead of
 * escalating in silence.
 *
 * QuoteWriter shallow-merges customFields, so this cannot disturb the A2CN act
 * chain or the two markers the servicing loop already keeps there.
 */
final class ClarificationMarker
{
    public const MARKER_KEY = 'merchant_quote_agent_clarification_asked';

    public static function alreadyAsked(QuoteSnapshot $snapshot): bool
    {
        return ($snapshot->lifecycle->customFields[self::MARKER_KEY] ?? null) === true;
    }

    /** @return array<string, true> the fragment that records the question was asked */
    public static function set(): array
    {
        return [self::MARKER_KEY => true];
    }

    /**
     * The fragment that releases the quote for a fresh question, to be spread
     * into a servicing pass's stamp.
     *
     * Only a pass that ANSWERED may clear it. The pass that asked wrote this
     * marker itself, and erasing it would ask the same question again on the
     * next buyer comment instead of escalating.
     *
     * @return array<string, null> empty when the pass did not answer the buyer
     */
    public static function releaseFor(NegotiationOutcome $outcome): array
    {
        return $outcome->answeredTheBuyer() ? [self::MARKER_KEY => null] : [];
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter ClarificationMarkerTest`
Expected: PASS (3 tests).

Then `vendor/bin/phpunit --testsuite unit` — expected 408.

- [ ] **Step 6: Gate and commit**

```bash
composer run format && composer run lint && composer run typecheck && composer run quality:depcheck
vendor/bin/mago fmt tests/Unit/Negotiation/ClarificationMarkerTest.php tests/Unit/Negotiation/NegotiationFixture.php
git commit --only src/Negotiation/ClarificationMarker.php tests/Unit/Negotiation/ClarificationMarkerTest.php tests/Unit/Negotiation/NegotiationFixture.php -m "feat: remember that a quote was already asked to clarify

Modelled on QuoteEscalator's marker, for the same reason: without one a
talkative buyer collects one question per comment. It clears when a pass
answers with an offer, so \"ask once\" means once per stuck point rather than
once per quote lifetime."
```

---

### Task 3: The ask

**Files:**
- Create: `src/Negotiation/ClarificationRound.php`
- Test: `tests/Unit/Negotiation/ClarificationRoundTest.php`

**Interfaces:**
- Consumes: `InterpretedAsk::needsClarification()` and `$ask->interpretation->clarificationQuestions` (Task 1); `ClarificationMarker::alreadyAsked()`, `::set()` (Task 2); `OfferRound::escalated(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, ?QuoteEscalationReason $reason, ?string $extractHash, ?string $negotiateHash): NegotiationPass`.
- Produces: `ClarificationRound::handle(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, InterpretedAsk $ask, OfferRound $round, LoggerInterface $logger): NegotiationPass`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/ClarificationRoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationRound;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of "ask once, then escalate", at the boundary that decides
 * which. Getting it backwards either escalates without ever asking the buyer,
 * or asks forever and never reaches a human.
 */
final class ClarificationRoundTest extends TestCase
{
    public function testItPostsEveryQuestionVerbatimAndMarksTheQuote(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot();

        $pass = ClarificationRound::handle(
            $harness->gateway,
            $snapshot,
            $this->ask(['Which line did you mean?', 'By when do you need it?']),
            $harness->round,
            $harness->logger,
        );

        self::assertSame(NegotiationOutcome::Clarified, $pass->outcome);
        self::assertSame('extract-hash', $pass->extractHash);
        self::assertNull($pass->negotiateHash, 'A clarification never reaches the negotiate call.');
        self::assertNull($pass->replyHash, 'The questions are posted as-is, so no reply prompt ran.');

        self::assertSame(
            ["Which line did you mean?\nBy when do you need it?"],
            $harness->gateway->comments,
            'Both questions, in order, with nothing added.',
        );
        self::assertSame(
            [ClarificationMarker::MARKER_KEY => true],
            $harness->gateway->customFieldWrites[0]->customFields ?? null,
        );
    }

    public function testItEscalatesWhenTheQuoteWasAlreadyAsked(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(),
            [ClarificationMarker::MARKER_KEY => true],
        );

        $pass = ClarificationRound::handle(
            $harness->gateway,
            $snapshot,
            $this->ask(['Which line did you mean?']),
            $harness->round,
            $harness->logger,
        );

        self::assertSame(NegotiationOutcome::Escalated, $pass->outcome);
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $pass->escalationReason);

        // The buyer gets the escalation constant, never the question again.
        self::assertCount(1, $harness->gateway->comments);
        self::assertStringNotContainsString('Which line', $harness->gateway->comments[0]);
    }

    /** @param list<string> $questions */
    private function ask(array $questions): InterpretedAsk
    {
        return new InterpretedAsk(
            new CommentInterpretation(clarificationQuestions: $questions),
            'extract-hash',
        );
    }
}
```

`PipelineHarness` builds its `OfferRound` inline inside `with()` and does not expose it, so this test cannot reach it yet. Make it available by hoisting that construction into a local and adding it to the harness — a mechanical change:

```php
        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder),
            new OfferApplier(new OfferVerifier(), $logger, $recorder),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            $escalator,
            $logger,
        );

        $pipeline = new NegotiationPipeline(
            new AskInterpreter($client, $prompts, $recorder),
            new NegotiationDecider(),
            $round,
            $recorder,
            $logger,
        );

        return new self($pipeline, $gateway, $spy, $logger, $writer, $round);
```

and adding `public OfferRound $round,` as the last promoted constructor parameter of `PipelineHarness`. It is a test double with six properties, not a production class, so the parameter threshold does not apply to its constructor — but if the pre-commit hook disagrees, stop and report rather than suppressing.

`FakeQuoteGateway::$customFieldWrites` collects the `QuoteUpdate` objects handed to `updateQuote()`; read that class to confirm the property shape before asserting against `->customFields`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ClarificationRoundTest`
Expected: FAIL — `Class "...ClarificationRound" not found`, and possibly an unknown `$harness->round`. Fix the harness accessors first, then re-run to confirm the failure is only the missing class.

- [ ] **Step 3: Write the round**

Create `src/Negotiation/ClarificationRound.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;

/**
 * Asks the buyer the question the model could not answer itself, once, and
 * hands the quote to a human if the ambiguity survives their reply.
 *
 * Stateless and static by necessity, not taste: NegotiationPipeline already
 * carries five constructor parameters and mago's excessive-parameter-list is an
 * error at five, so a sixth injected collaborator would fail the gate. Taking
 * `$round` as an argument reuses the OfferRound the pipeline already holds, so
 * the escalating branch goes through the same funnel as every other escalation
 * without the pipeline learning a new dependency.
 *
 * The questions are posted VERBATIM. The extract prompt promises the buyer sees
 * them as-is; rewording them through the model would pay for a call in order to
 * paraphrase a question into a different one. Nothing is appended, so there is
 * no template into which internal state could be interpolated — which matters,
 * because a quote comment is customer-facing copy (see QuoteEscalator).
 */
final class ClarificationRound
{
    public static function handle(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        InterpretedAsk $ask,
        OfferRound $round,
        LoggerInterface $logger,
    ): NegotiationPass {
        $quoteId = $snapshot->identity->quoteId;

        if (ClarificationMarker::alreadyAsked($snapshot)) {
            $logger->info('The buyer\'s ask is still ambiguous after we already asked; a human takes it.', [
                'quoteId' => $quoteId,
            ]);

            return $round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NeedsHumanReview,
                $ask->promptHash,
                null,
            );
        }

        $logger->info('The buyer\'s ask was ambiguous; asking them rather than guessing.', [
            'quoteId' => $quoteId,
            'questionCount' => \count($ask->interpretation->clarificationQuestions),
        ]);

        // Comment first, then mark. If the mark fails the buyer sees the
        // question twice, which is mildly annoying; the reverse order would
        // mark a quote as asked without asking, silently turning the next
        // ambiguity into an escalation.
        $gateway->addComment($quoteId, implode("\n", $ask->interpretation->clarificationQuestions));
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ClarificationMarker::set()));

        return new NegotiationPass(NegotiationOutcome::Clarified, $ask->promptHash);
    }
}
```

Note the log line carries `questionCount`, not the questions — the question text is already in the buyer's comment thread, and the log is the merchant's half.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter ClarificationRoundTest`
Expected: PASS (2 tests).

Then `vendor/bin/phpunit --testsuite unit` — expected 410.

- [ ] **Step 5: Gate and commit**

```bash
composer run format && composer run lint && composer run typecheck && composer run quality:depcheck
vendor/bin/mago fmt tests/Unit/Negotiation/ClarificationRoundTest.php tests/Unit/Negotiation/PipelineHarness.php
git commit --only src/Negotiation/ClarificationRound.php tests/Unit/Negotiation/ClarificationRoundTest.php tests/Unit/Negotiation/PipelineHarness.php -m "feat: ask the buyer once, then hand the ambiguity to a human

The questions go out verbatim: the extract prompt promises the buyer sees them
as-is, and rewording would pay for a model call to paraphrase a question into a
different one. Comment first, then mark -- the reverse would mark a quote as
asked without asking, silently turning the next ambiguity into an escalation."
```

---

### Task 4: The gate, the stamp, and the docs

**Files:**
- Modify: `src/Negotiation/NegotiationPipeline.php` (add the guard after the `hasNonPriceAsk()` block, before the `// \`overall\` IS the price band here` comment)
- Modify: `src/Servicing/ServiceQuoteHandler.php:194` (spread the new release into the same stamp)
- Modify: `README.md`
- Test: `tests/Unit/Negotiation/ClarificationGateTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: the end-to-end behaviour. No new public surface.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/ClarificationGateTest.php`. This mirrors `NonPriceAskGateTest`, its sibling gate's test.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The gate that asks the buyer instead of guessing.
 *
 * Before it existed, an ambiguous ask reached the decider with nothing set,
 * landed in the grant band at roughly 0% and got a generic offer reply — which
 * advanced hasNewBuyerAsk(), so the buyer's real question was never resurfaced.
 */
final class ClarificationGateTest extends TestCase
{
    private const AMBIGUOUS = '{"clarification_questions":["Which line did you mean?"]}';

    public function testAnAmbiguousAskIsPutToTheBuyerAndNeverReachesTheNegotiateCall(): void
    {
        $harness = PipelineHarness::with([self::AMBIGUOUS]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('can you do 10% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Clarified, $outcome);
        self::assertSame(1, $harness->spy->calls, 'An ambiguous ask must not reach the negotiate call.');
        self::assertSame(['Which line did you mean?'], $harness->gateway->comments);
    }

    public function testTheSameAmbiguityEscalatesOnceWeHaveAlreadyAsked(): void
    {
        $harness = PipelineHarness::with([self::AMBIGUOUS]);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment('I mean the widgets', '2026-08-28 09:00:00'),
            ]),
            [ClarificationMarker::MARKER_KEY => true],
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls);
    }

    public function testAStructuralAskThatIsAlsoAmbiguousEscalatesAsStructural(): void
    {
        // Guard order is load-bearing: changing WHAT is sold is outside the
        // mandate whether or not the ask is clear, so the structural gate above
        // must win. If this ever asks the buyer instead, the guards were
        // reordered.
        $harness = PipelineHarness::with([
            '{"clarification_questions":["Which line did you mean?"],'
                . '"line_changes":[{"line_item_id":"line-1","quantity":20}]}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('make it 20 of something', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame([], $harness->gateway->customFieldWrites === [] ? [] : [], 'no clarification marker is written');
    }
}
```

The extract wire keys above are verified against `src/Negotiation/Response/ExtractResponse.php:53-58` and its `LINE_CHANGE_KEYS` constant: the model sends `clarification_questions`, `line_changes`, and inside a line-change row `line_item_id` (**not** `line_id`), `quantity`, `target_unit_price`, `remove`. Use them as written.

The third test's last assertion is deliberately inert as written — replace it with a real check that no clarification marker was written. `FakeQuoteGateway::$customFieldWrites` collects the `QuoteUpdate` objects passed to `updateQuote()`, so assert that none of them carries `ClarificationMarker::MARKER_KEY`. An assertion that cannot fail is a plan defect; fix it and say so in your report.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ClarificationGateTest`
Expected: FAIL — the first test returns `Offered` (the current no-op behaviour this whole change exists to remove) rather than `Clarified`.

- [ ] **Step 3: Add the guard**

In `src/Negotiation/NegotiationPipeline.php`, immediately after the `if ($ask->hasNonPriceAsk()) { ... }` block and before the `// \`overall\` IS the price band here` comment:

```php
        if ($ask->needsClarification()) {
            // The model could not place the ask, so answering it means picking
            // a line at random. Before this gate the pass fell through with an
            // empty ask, landed in the grant band at roughly 0% and sent a
            // generic reply that advanced hasNewBuyerAsk() — so the buyer's
            // real question was answered with a no-op and then never asked
            // again. Which of ask-or-escalate happens is ClarificationRound's
            // call: the marker settles it, and it owns the marker.
            return ClarificationRound::handle($gateway, $snapshot, $ask, $this->round, $this->logger);
        }
```

Add `use` statements only if the classes are not already imported (they are in the same namespace, so none should be needed).

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter ClarificationGateTest`
Expected: PASS (3 tests).

Then `vendor/bin/phpunit --testsuite unit` — expected 413.

**Then check the class did not blow its complexity budget:**

Run: `vendor/bin/mago lint src/Negotiation/NegotiationPipeline.php`
Expected: no issues. If `cyclomatic-complexity` now trips, **do not suppress it** — report it, and propose moving the guard's condition into `ClarificationRound` so the pipeline gains no branch. Wait for a ruling.

- [ ] **Step 5: Spread the release into the stamp**

In `src/Servicing/ServiceQuoteHandler.php`, in the `updateQuote` call around line 190:

```php
            ...QuoteEscalator::releaseFor($outcome),
            ...ClarificationMarker::releaseFor($outcome),
```

Add the import for `MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker`.

Then extend the comment above that call so the second marker is not a mystery:

```
        // Two markers are released here, each by its owner's rule: a pass that
        // escalated keeps the escalation marker it just wrote, and a pass that
        // asked a clarification keeps that one.
```

- [ ] **Step 6: Verify the release actually happens**

Add to `tests/Unit/Negotiation/ClarificationGateTest.php`:

```php
    public function testAnsweringTheBuyerReleasesTheClarificationMarker(): void
    {
        // Without this, the first ambiguity in a quote's life permanently
        // consumes its one question and a genuinely new one months later
        // escalates in silence.
        self::assertSame(
            [ClarificationMarker::MARKER_KEY => null],
            ClarificationMarker::releaseFor(NegotiationOutcome::Offered),
        );
    }
```

If a servicing-handler test already asserts the exact stamp contents, it will now fail because the stamp carries a new key. Find it (`grep -rn "releaseFor\|MARKER_KEY" tests/`), and update it to expect both markers rather than loosening the assertion.

Run: `vendor/bin/phpunit --testsuite unit`
Expected: 414, all passing.

- [ ] **Step 7: Document it**

In `README.md`, find the section describing the servicing loop and what a pass can do, and add:

```markdown
An ambiguous ask is put back to the buyer rather than guessed at. When the
extract step returns `clarification_questions` — asks that are clear in intent
but ambiguous in reference, like "10% off" on a five-line quote — the agent
posts those questions verbatim, writes no offer, and does not spend the
negotiate call. If the ask is still ambiguous after the buyer answers, it goes
to a human instead of being asked again. The marker that enforces "once"
clears as soon as a pass answers with an offer, so a genuinely new ambiguity
later in the same quote is asked about rather than escalated silently.
```

Match the README's surrounding voice; if that section does not exist, put it beside the escalation documentation and say where you placed it.

- [ ] **Step 8: Gate and commit**

```bash
composer run format && composer run lint && composer run typecheck && composer run quality:depcheck
vendor/bin/mago fmt tests/Unit/Negotiation/ClarificationGateTest.php
git commit --only src/Negotiation/NegotiationPipeline.php src/Servicing/ServiceQuoteHandler.php README.md tests/Unit/Negotiation/ClarificationGateTest.php -m "feat: put an ambiguous ask back to the buyer

Closes #65. The extract prompt promised these questions were sent to the buyer
as-is; nothing read the field, so an ambiguous ask reached the decider empty,
landed in the grant band at roughly 0% and got a generic reply that advanced
hasNewBuyerAsk() -- answering the buyer's real question with a no-op and then
never asking it again.

The gate sits third, after the structural and non-price gates, so an ask that
is both structural and ambiguous still escalates as structural."
```

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
| --- | --- |
| 1. `InterpretedAsk::needsClarification()` | Task 1 |
| 2. The pipeline gate, guard order | Task 4 steps 1, 3 |
| 3. `ClarificationRound`, verbatim, stateless | Task 3 |
| 4. `NegotiationOutcome::Clarified`, `answeredTheBuyer()` false | Task 1 |
| 5. `ClarificationMarker`, clears on an answering pass, stamp | Task 2; Task 4 steps 5, 6 |
| Error handling: comment before mark | Task 3 step 3, with the comment explaining why |
| Testing: all six bullets | Tasks 1–4 |
| Risk: model text to the buyer | Task 3's docblock; nothing appended |

No spec requirement is unimplemented.

**Placeholder scan:** no TBD or TODO. Two steps deliberately tell the implementer to verify a guess against the source rather than trust the plan — the extract wire-format keys in Task 4 step 1, and the `PipelineHarness` accessors in Task 3 step 1. Both name exactly what to read and what to do if it differs. Task 4 step 1 also flags its own inert assertion and requires it be replaced.

**Type consistency:** `needsClarification()`, `Clarified`, `ClarificationMarker::{MARKER_KEY,alreadyAsked,set,releaseFor}`, `ClarificationRound::handle`, and `NegotiationFixture::withCustomFields` are each named identically everywhere they appear. `handle()` takes five parameters, at but not over the threshold. `OfferRound::escalated()`'s five arguments match its real signature as read from source.
