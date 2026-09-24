# Never Answer a Price Ask With 0% Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The negotiate model sees the buyer's storefront requested price. A pass that lowers nothing escalates as `no_further_concession` instead of answering "This quote stands at …".

**Architecture:** One more column in `OfferProposer::userPrompt()`, plus two rules in the negotiate prompt. A new `QuoteEscalationReason` case, raised in `OfferRound::play()` wherever `$grantedThisPass` is false, before any reply is composed. `ReductionForPass` drops the branch that path used to take. Admin labels in English and German.

**Tech Stack:** PHP 8.3, PHPUnit, Mago, Shopware admin snippets (JSON), a Markdown prompt file.

**Spec:** `docs/superpowers/specs/2026-09-24-never-answer-zero-discount-design.md`. Read it before starting any task.

## Global Constraints

- `declare(strict_types=1)` in every file; target PHP 8.3. Gate thresholds: cyclomatic complexity 10 per class, nesting depth 4, at most 5 parameters, ~400 lines/file. Never relax a threshold. A new `@mago-expect` needs a stated reason.
- `src/Negotiation` imports no Shopware except `IllegalTransitionException`; `src/Policy` imports none.
- New escalation reason value, verbatim: `no_further_concession` (enum case `NoFurtherConcession`).
- Only the buyer's own requested price is shown to the model: `Policy\Data\QuoteLineSnapshot::$requestedUnitPrice`, which `Bridge\QuoteLineMapper` already clears for agent-mirrored prices. Net, two decimals, empty when null.
- The no-ask acknowledgement (`PassedOver` → `ReplyComposer::acknowledge()` → `ReplyTemplate::acknowledges()`) must not change.
- A pass whose write DID move the total but prints as `0` at two decimals (#175, `ReductionForPass`) keeps answering with `ReplyTemplate::holds()`.
- Checks: `composer run format:check && composer run lint`, `composer run typecheck`, `composer run test`, `composer run quality:admin` for snippet changes. `composer run format` fixes formatting.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. If 1Password signing fails, retry. Never use bare `git stash`.

---

### Task 1: The model sees the requested price and may not hold

**Files:**
- Modify: `src/Negotiation/OfferProposer.php` (`userPrompt()`, ~lines 185-236)
- Modify: `config/agents/quote-negotiate-agent.prompt.md`
- Create: `tests/Unit/Negotiation/NegotiateRequestedPriceTest.php`
- Modify: `tests/Unit/Negotiation/QuoteNegotiatePromptTest.php`
- Modify: `tests/Integration/LiveHistoryMessageTest.php:38-39` (its hand-built copy of the prompt's line block)

**Interfaces:**
- Produces: the negotiate user prompt's line block header `Line items (lineItemId | productId | label | quantity | unit price net | buyer asks per unit net):`, and each line `<id> | <productId> | <label> | <qty> | <unit %.2f> | <requested %.2f or empty>`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Negotiation/NegotiateRequestedPriceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * sw-ag.dev quotes 1097 and 1099: the buyer entered a requested price in the
 * storefront (600.00 and 590.00 against 654.53, both inside the 15% cap) and
 * wrote no comment. The negotiate prompt listed the lines without that price,
 * so the model was shown no ask at all, proposed no terms, and the buyer was
 * answered with the unchanged quote.
 */
final class NegotiateRequestedPriceTest extends TestCase
{
    public function testTheNegotiatePromptShowsTheBuyersRequestedUnitPrice(): void
    {
        // A structured-only ask: no comment, so no extract call. The first
        // scripted reply is the negotiate answer.
        $harness = PipelineHarness::with([
            '{"action":"offer","message":"90 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":90}]}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::snapshot(requestedUnitPrice: 90.0);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[0] ?? '';
        self::assertStringContainsString(
            'Line items (lineItemId | productId | label | quantity | unit price net | buyer asks per unit net):',
            $negotiatePrompt,
        );
        self::assertStringContainsString('line-1 | prod-1 | Widget | 10 | 100.00 | 90.00', $negotiatePrompt);
    }

    public function testALineWithoutARequestedPriceLeavesTheColumnEmpty(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[1] ?? '';
        self::assertMatchesRegularExpression('/^line-1 \| prod-1 \| Widget \| 10 \| 100\.00 \| $/m', $negotiatePrompt);
    }
}
```

In `QuoteNegotiatePromptTest::testTheShippedPromptStatesTheHistoryDisclosureAuthorityAndRequestRules()` add:

```php
        self::assertStringContainsString('Never answer a price ask with no concession', $prompt);
        self::assertStringContainsString('"buyer asks per unit net"', $prompt);
```

- [ ] **Step 2: Run them to verify they fail**

Run: `composer run test -- --filter 'NegotiateRequestedPriceTest|QuoteNegotiatePromptTest'`
Expected: FAIL. The header and line strings are not found, and neither are the two prompt rules.
If the first test fails for any other reason (e.g. the harness makes an extract call on a structured-only ask), read `NegotiationPipeline::negotiate()` and fix the TEST's scripted replies. Do not change the production flow.

- [ ] **Step 3: Implement**

In `OfferProposer::userPrompt()`, the line map becomes:

```php
        $lines = array_map(static fn(PolicyQuoteLineSnapshot $l): string => sprintf(
            '%s | %s | %s | %d | %.2f | %s',
            $l->lineItemId(),
            $l->identity->productId ?? '',
            $l->label() ?? '',
            $l->quantity,
            $l->unitPriceNet,
            // The storefront's per-line "Requested price", in net. Without it
            // a structured-only ask reached the model as no ask at all
            // (sw-ag.dev quotes 1097/1099) and was answered with 0%.
            $l->requestedUnitPrice === null ? '' : sprintf('%.2f', $l->requestedUnitPrice),
        ), $snapshot->lines);
```

In the final `sprintf`'s format string, change `Line items (lineItemId | productId | label | quantity | unit price net):` to `Line items (lineItemId | productId | label | quantity | unit price net | buyer asks per unit net):`.

In `config/agents/quote-negotiate-agent.prompt.md`:

1. In the first bullet ("ANSWER AT THE LEVEL THE BUYER ASKED"), change `When the buyer negotiates per line item ("buyer asks <price> per unit" on a line, or a comment tagged \`[line <id>]\`),` to `When the buyer negotiates per line item (a price in a line's "buyer asks per unit net" column, or a comment tagged \`[line <id>]\`),`. Keep the rest of the bullet.
2. Directly after the bullet that starts `- If the buyer demands more than you may give,`, add:

```markdown
- Never answer a price ask with no concession: an offer of 0%, or line prices
  equal to the ones shown, is not an answer. Offer a real concession within
  your authority, or set action "escalate".
```

In `tests/Integration/LiveHistoryMessageTest.php`, update its hand-built line block to the new header and line: `line-1 | prod-1 | Widget | 10 | 100.00 | ` (trailing space, empty column), so it mirrors what `userPrompt()` now sends.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter 'NegotiateRequestedPriceTest|QuoteNegotiatePromptTest|OfferProposer|HistoryBoundary|NegotiateTargetSpace'`, then `composer run test`.
Expected: PASS. If an existing test pinned the old five-column line, update its expectation to the six-column form and name it in the report.

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Negotiation/OfferProposer.php config/agents/quote-negotiate-agent.prompt.md tests/Unit/Negotiation tests/Integration/LiveHistoryMessageTest.php
git commit -m "fix(negotiation): show the model the buyer's requested price

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: A pass that concedes nothing escalates

**Files:**
- Modify: `src/Policy/Data/QuoteEscalationReason.php`
- Modify: `src/Negotiation/OfferRound.php` (`play()`, ~lines 116-181)
- Modify: `src/Negotiation/ReductionForPass.php`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json` and `.../snippet/de.json` (the reason label map near line 28 and `escalationWhy` near line 337)
- Modify: `docs/end-to-end.md` (~line 444, the reasons list)
- Test: `tests/Unit/Negotiation/OfferRoundTest.php` (`testAPassThatGrantsNothingReportsAHoldNotABaselinePercentageQuote1045Shape`, ~line 298), `tests/Unit/Negotiation/ReductionForPassTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `QuoteEscalationReason::NoFurtherConcession` (`'no_further_concession'`); `ReductionForPass::of(float $beforeNet, float $afterNet): array{0: ?float, 1: bool}` (the third parameter is removed).

- [ ] **Step 1: Write the failing tests**

In `OfferRoundTest`, replace `testAPassThatGrantsNothingReportsAHoldNotABaselinePercentageQuote1045Shape` (keep its setup) with:

```php
    /**
     * Quote 1045's shape (#174/#175): the write changes nothing (34000.00 ->
     * 34000.00) against a stored baseline of 34456.73. That used to be
     * answered "This quote stands at 34000.00 EUR"; the user's rule is that a
     * price ask is never answered with no concession, so it now goes to a
     * human (spec 2026-09-24-never-answer-zero-discount).
     */
    public function testAPassThatGrantsNothingEscalatesInsteadOfReplyingQuote1045Shape(): void
    {
        // (the existing setup: the model proposes discountPercent 0, the
        //  baseline is 34456.73, and both reads report 34000.00)

        $pass = $round->play($gateway, $snapshot, self::settings(), self::decision(), null);

        self::assertSame(NegotiationOutcome::Escalated, $pass->outcome);
        self::assertSame(QuoteEscalationReason::NoFurtherConcession, $pass->escalationReason);
        foreach ($gateway->comments as $comment) {
            self::assertStringNotContainsString('stands at', $comment, 'No hold reply reaches the buyer.');
        }
    }
```

In `ReductionForPassTest`:
- Delete `testAPassThatWroteNothingIsAHold`, because that case no longer reaches this class.
- Drop the `grantedThisPass: true` argument from the three remaining calls, leaving `ReductionForPass::of(1000.0, 850.0)` and so on.
- Update the class docblock sentence that mentions the "wrote nothing" shape.

- [ ] **Step 2: Run them to verify they fail**

Run: `composer run test -- --filter 'OfferRoundTest|ReductionForPassTest'`
Expected: FAIL. `NoFurtherConcession` is undefined, and `ReductionForPass::of()` is still missing its argument.

- [ ] **Step 3: Implement**

`QuoteEscalationReason`: add after `UnplaceableAsk`:

```php
    // A price ask whose pass wrote no concession: the model held, the cap or
    // the minimum-margin floor left nothing to give, or an earlier round
    // already gave it. Never answered with 0% (spec
    // 2026-09-24-never-answer-zero-discount). OfferRound.
    case NoFurtherConcession = 'no_further_concession';
```

`OfferRound::play()`: right after `$grantedThisPass = …;`, before `ReductionForPass::of(`, insert:

```php
        if (!$grantedThisPass) {
            // The user's rule: a price ask is never answered with no
            // concession. One comparison covers every way to get here -- the
            // model held, the cap or the margin floor left nothing, an earlier
            // round already gave it -- and the write it follows changed no
            // price. A person decides whether to go further.
            $this->logger->info('This pass conceded nothing on a price ask; a human takes it.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalated(
                $gateway,
                $applied->after,
                QuoteEscalationReason::NoFurtherConcession,
                $extractHash,
                $answer->promptHash,
            );
        }
```

Remove `$grantedThisPass` from the `ReductionForPass::of(...)` call. In the long comment above `$grantedThisPass`, replace the sentence starting `A pass that changed nothing (the band allowed a concession and the model, or the rules, held) is a real outcome, not a 0% discount, and gets its own sentence below` with: `A pass that changed nothing escalates below (no_further_concession): a price ask is never answered with no concession.`

`ReductionForPass::of()`: remove the `bool $grantedThisPass` parameter, its `@param` and the `if (!$grantedThisPass) { return [null, false]; }` branch. Update the class docblock's "Three outcomes" sentence: the hold now means only "a real write too small to print".

If `OfferRound` exceeds cyclomatic complexity 10 (`composer run lint`), move the new branch into a small static helper in `src/Negotiation`, following the `ReductionForPass` precedent. Do not add a suppression.

Snippets. `en.json` label map (next to `"unplaceable_ask"`):

```json
            "no_further_concession": "No further concession"
```

`en.json` `escalationWhy`:

```json
            "no_further_concession": "The customer asked for a better price, but this pass could not lower the quote: your maximum discount or minimum margin is already reached, or the agent found nothing it could offer. A person decides whether to go further."
```

`de.json` label map:

```json
            "no_further_concession": "Kein weiteres Entgegenkommen"
```

`de.json` `escalationWhy`:

```json
            "no_further_concession": "Der Kunde wollte einen besseren Preis, aber dieser Durchlauf konnte das Angebot nicht senken: Ihr maximaler Rabatt oder Ihre Mindestmarge ist bereits erreicht, oder der Agent fand nichts, was er anbieten konnte. Ein Mensch entscheidet, ob weiter entgegengekommen wird."
```

Mind the JSON commas: the entry that was last in each block gains a trailing comma.

Then grep the admin module for every other place that lists escalation reasons (`grep -rn "unplaceable_ask" src/Resources/app/administration/src`) and add `no_further_concession` wherever a list of reasons exists. Report which files you checked.

`docs/end-to-end.md` (~line 444): the list says "The eight reasons:" and is stale. Replace it with every case of `QuoteEscalationReason` including the new one, and say "The twelve reasons:". Count the enum cases and use the real number.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer run test -- --filter 'OfferRoundTest|ReductionForPassTest|NegotiationPipelineTest|ReplyComposerTest|RecordedOutcomePathsTest'`, then `composer run test`, then `composer run quality:admin`.
Expected: PASS. `ReplyComposerTest::testANullReductionPercentPostsTheHoldTemplate` still passes, because the #175 two-decimal case keeps that template. If another test pinned the old hold reply on a pass that wrote nothing, update it to the escalation and name it in the report.

- [ ] **Step 5: Gate and commit**

```bash
composer run format:check && composer run lint && composer run typecheck
git add src/Policy/Data/QuoteEscalationReason.php src/Negotiation/OfferRound.php src/Negotiation/ReductionForPass.php src/Resources/app/administration docs/end-to-end.md tests/Unit/Negotiation
git commit -m "fix(negotiation): escalate a price ask the pass could not concede on

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Full gate and spec status

**Files:**
- Modify: `docs/superpowers/specs/2026-09-24-never-answer-zero-discount-design.md` (`## Status`)

- [ ] **Step 1:** `composer run quality`. Expected: PASS. Fix failures at their cause.
- [ ] **Step 2:** `composer run test:integration` against the Docker shop `merchant-quote-shop`. For any failure, check it on `origin/main` from a throwaway `git worktree` before blaming this branch. Re-run this branch last, so the shop is left on it.
- [ ] **Step 3:** Set the spec status to `Implemented. 2026-09-24.`
- [ ] **Step 4:** Commit:

```bash
git add docs/superpowers/specs/2026-09-24-never-answer-zero-discount-design.md
git commit -m "docs(spec): mark the zero-discount rule implemented

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
