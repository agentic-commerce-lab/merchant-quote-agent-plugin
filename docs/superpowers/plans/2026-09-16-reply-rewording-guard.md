# Reply Rewording Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop a model rewording from adding numbers, dates, prose or concession terms the merchant never authorised, before it reaches the buyer.

**Architecture:** `ReplyTemplate::keepsTheFacts(): bool` becomes `ReplyTemplate::unsafeBecause(): ?string` — the same single choke point, now running four checks instead of one, and returning an operator-readable reason instead of a boolean. The checks are: not empty; no more than five sentences; no word naming a concession `AskGate` escalates; and one pass over the rewording's number-shaped tokens that requires every token to be a figure the template wrote AND every figure the template wrote to appear as a token. That last pass replaces the three `str_contains()` calls rather than joining them — `str_contains($reworded, '5')` is satisfied by `950.00`, so the old check could not see a dropped single-digit reduction at all. `ReplyComposer::reword()` logs the reason and falls back to the template, exactly as it does today.

**Tech Stack:** PHP 8.3, PHPUnit 11, mago (fmt + lint + analyze), no new dependencies.

**Spec:** `docs/superpowers/specs/2026-09-16-reply-rewording-guard-design.md`

## Global Constraints

- PHP 8.3, `declare(strict_types=1)` in every file.
- No new dependency. `preg_*`, `is_numeric`, `sprintf` only.
- mago lint: cyclomatic complexity ≤ 10 per function, ≤ 5 parameters per function/constructor, nesting ≤ 4.
- mago analyze runs as PHPStan-max-equivalent: no `int|false` leaking out of `preg_match_all`, no untyped arrays without a docblock.
- `php scripts/check_file_length.php src` fails any `src/` file over 400 physical lines.
- Formatting is gated: run `composer run format` before committing, never hand-align.
- Commit message trailer on every commit: `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Do not push, do not open a PR, do not touch the GitHub issue.
- Do not touch `validityDays` or its defaults — issue #57 owns that vicinity in the same file.
- Docblocks in this repo explain *why*, grounded in measured evidence. Match the neighbours in `src/Negotiation/`.

**The fixture facts** every test below uses (from `tests/Unit/Negotiation/NegotiationFixture.php`):
reduction `5.0` → `percent()` writes `5`; total `950.0` → `money()` writes `950.00`; expiry `NegotiationFixture::EXPIRES` = `2026-09-11`. The template for those facts is exactly:

```
We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11.
```

---

### Task 1: The guard

**Files:**
- Modify: `src/Negotiation/ReplyTemplate.php` (replace `keepsTheFacts()` at `:67-80`)
- Modify: `src/Negotiation/ReplyComposer.php:96-102` (call site + log line)
- Test: `tests/Unit/Negotiation/ReplyTemplateTest.php` (create)

**Interfaces:**
- Consumes: `ReplyTemplate::percent(float): string`, `ReplyTemplate::money(float): string` — both already exist and already format the figures so "both sides agree on the string". Use them; do not re-derive number formatting.
- Produces: `ReplyTemplate::unsafeBecause(string $reworded, float $reductionPercent, float $totalNet, \DateTimeImmutable $validUntil): ?string` — `null` means the rewording may ship. `keepsTheFacts()` is removed; it has exactly one caller in `src/`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/ReplyTemplateTest.php`. Two data providers: reworded strings that MUST ship, and reworded strings that MUST fall back. The "must ship" table is the one that bounds false rejections — it is the point of the test, not the decoration.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a rewording is allowed to say.
 *
 * The guard's two failure modes are not symmetric. Letting an invented term
 * through puts a commitment in front of a buyer that OfferApplier never wrote
 * and AskGate would have escalated. Rejecting a good rewording costs a plainer
 * sentence -- but it costs it EVERY time, silently, with every gate still
 * green, which is how a guard turns a feature off without anyone noticing.
 * So the accepting table below is as load-bearing as the rejecting one.
 */
final class ReplyTemplateTest extends TestCase
{
    private const PERCENT = 5.0;

    private const TOTAL = 950.0;

    private static function validUntil(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(NegotiationFixture::EXPIRES);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptableReasonings(): iterable
    {
        yield 'the template itself' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11.',
        ];
        yield 'a warm opener and a sign-off' => [
            'Thank you for coming back to us. We can bring this quote down by 5% to 950.00 EUR. '
                . 'The offer is valid until 2026-09-11. Do let us know if you would like to proceed.',
        ];
        yield 'five sentences exactly, the prompt ceiling' => [
            'Thanks for your patience. We reviewed the quote with our team. We can bring it down by 5% '
                . 'to 950.00 EUR. That offer is valid until 2026-09-11. We hope this works for you.',
        ];
        yield 'the percentage written with the trailing zeros a model adds' => [
            'We can reduce this quote by 5.00% to 950.00 EUR, valid until 2026-09-11.',
        ];
        yield 'the same figure restated' => [
            'A 5% reduction brings the quote to 950.00 EUR. That is 5% off, valid until 2026-09-11.',
        ];
        yield 'currency code ahead of the amount' => [
            'We can bring this quote down by 5% to EUR 950.00. The offer is valid until 2026-09-11.',
        ];
        yield 'a formal German tone that still carries the ASCII figures' => [
            'Gerne kommen wir Ihnen entgegen: Wir reduzieren dieses Angebot um 5% auf 950.00 EUR. '
                . 'Das Angebot ist gültig bis 2026-09-11.',
        ];
        yield 'an exclamation and a question, still under the cap' => [
            'Good news! We can bring this quote down by 5% to 950.00 EUR. '
                . 'The offer is valid until 2026-09-11 — shall we proceed?',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectableReasonings(): iterable
    {
        yield 'the concession from the issue' => [
            'We can bring this quote down by 5% to 950.00 EUR and we will also include free shipping. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'payment terms nobody authorised' => [
            'We can bring this quote down by 5% to 950.00 EUR on Net 90 terms. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a delivery promise' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11 '
                . 'and delivery is on us.',
        ];
        yield 'an invented second date' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11, '
                . 'and we can hold it again until 2026-12-31.',
        ];
        yield 'an invented validity window' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11, '
                . 'which is 14 days from today.',
        ];
        yield 'an invented quantity' => [
            'We can bring this quote down by 5% to 950.00 EUR. Order 20 more units and we can do better. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a deposit' => [
            'We can bring this quote down by 5% to 950.00 EUR against a deposit. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a warranty' => [
            'We can bring this quote down by 5% to 950.00 EUR, warranty included. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'six sentences' => [
            'Hello. Thank you for your patience. We looked at this again. We can bring it down by 5% '
                . 'to 950.00 EUR. It is valid until 2026-09-11. Let us know.',
        ];
        yield 'the total dropped' => ['We can bring this quote down by 5%, valid until 2026-09-11.'];
        yield 'the date dropped' => ['We can bring this quote down by 5% to 950.00 EUR.'];
        yield 'the percentage dropped' => ['We can bring this quote to 950.00 EUR, valid until 2026-09-11.'];
        yield 'the percentage dropped, surviving only inside the total' => [
            // Passes TODAY: str_contains($reworded, '5') is satisfied by
            // 950.00, so the old guard could not see the missing reduction.
            'We can bring this quote to 950.00 EUR, valid until 2026-09-11.',
        ];
        yield 'empty' => [''];
    }

    #[DataProvider('acceptableReasonings')]
    public function testAFaithfulRewordingReachesTheBuyer(string $reworded): void
    {
        self::assertNull(
            ReplyTemplate::unsafeBecause($reworded, self::PERCENT, self::TOTAL, self::validUntil()),
            'This rewording adds nothing, so rejecting it silently disables rewording for this tone.',
        );
    }

    #[DataProvider('rejectableReasonings')]
    public function testARewordingThatAddsOrDropsAFactIsRejected(string $reworded): void
    {
        self::assertNotNull(
            ReplyTemplate::unsafeBecause($reworded, self::PERCENT, self::TOTAL, self::validUntil()),
            'This rewording would put something in front of a buyer that nothing downstream can honour.',
        );
    }

    /** The reason is what makes an over-firing guard greppable rather than invisible. */
    public function testTheReasonNamesTheRuleThatFired(): void
    {
        $reason = ReplyTemplate::unsafeBecause(
            'We can bring this quote down by 5% to 950.00 EUR with free shipping. '
                . 'The offer is valid until 2026-09-11.',
            self::PERCENT,
            self::TOTAL,
            self::validUntil(),
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('shipping', $reason);
    }

    /**
     * A per-line concession carries no discountPercent at all, so 0% is a real
     * production case -- and `0` is a substring of `950.00`, which is the
     * substring hole from the other side. The template must accept itself.
     */
    public function testAZeroPercentReductionStillAcceptsItsOwnTemplate(): void
    {
        $template = ReplyTemplate::compose(0.0, 950.0, 'EUR', self::validUntil());

        self::assertNull(ReplyTemplate::unsafeBecause($template, 0.0, 950.0, self::validUntil()));
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `composer run test -- --filter ReplyTemplateTest`
Expected: FAIL — `Call to undefined method ...ReplyTemplate::unsafeBecause()`.

- [ ] **Step 3: Write the implementation**

In `src/Negotiation/ReplyTemplate.php`, delete `keepsTheFacts()` and add the following. Keep `compose()`, `reduction()`, `percent()` and `money()` untouched.

```php
    /**
     * The prompt's own limit, enforced here so it is load-bearing rather than
     * advisory. Five, not tighter: code stricter than the prompt rejects a
     * model that did exactly as it was told, and the cost of that is not a
     * failure but a silent fallback to the plain template on every pass.
     */
    private const MAX_SENTENCES = 5;

    /**
     * Words that name a concession this system cannot make.
     *
     * AskGate escalates every non-price ask and OfferApplier writes price and
     * expiry only, so none of these can be honoured even in principle -- a
     * rewording that says one of them hands the buyer a promise in writing
     * that nothing downstream will ever act on.
     *
     * The omissions are deliberate. `free` matches "feel free to reach out",
     * which is ordinary merchant tone, and the concession it would guard
     * against is "free shipping", already caught by `shipping`. `net` reads as
     * "net total" far more often than as payment terms, and German "Netto"
     * names the very figure being quoted; `Net 30`/`Net 90` carry a digit and
     * are rejected as unauthorised figures instead. `terms` is the template's
     * own subject matter.
     *
     * This list is English. It is a backstop for the language the model is
     * overwhelmingly prompted in, not the boundary -- the boundary is the
     * figure check and the sentence cap, which are language-independent.
     */
    private const CONCESSIONS = [
        'shipping',
        'freight',
        'delivery',
        'payment',
        'invoice',
        'deposit',
        'warranty',
        'instalment',
        'installment',
    ];

    /**
     * Whether this rewording may reach the buyer, and if not, why.
     *
     * This is the last thing between a model's free text and a buyer: the
     * negotiate call's message is discarded and the escalation copy is a
     * constant, so nothing else the model writes is ever read on the other
     * side. It used to be three `str_contains()` calls, which asked only
     * whether the facts were STILL THERE and never whether anything had been
     * added -- so a rewording ending "...and we will also include free
     * shipping and Net 90 terms" passed intact (issue #53).
     *
     * @return string|null null when the rewording may ship
     */
    public static function unsafeBecause(
        string $reworded,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): ?string {
        if ($reworded === '') {
            return 'it is empty';
        }

        $sentences = (int) preg_match_all('#[.!?](?=\s|$)#u', $reworded);
        if ($sentences > self::MAX_SENTENCES) {
            return sprintf('it runs to %d sentences, past the %d it may use', $sentences, self::MAX_SENTENCES);
        }

        foreach (self::CONCESSIONS as $word) {
            if (preg_match('#\b' . $word . '\b#i', $reworded) === 1) {
                return 'it names a concession nobody authorised: ' . $word;
            }
        }

        return self::figuresAreWrong($reworded, $reductionPercent, $totalNet, $validUntil);
    }

    /**
     * The figures, checked in both directions in one pass over one token list.
     *
     * `#\d+(?:[.,:/-]\d+)*#` takes `2026-09-11`, `950.00` and `5` each as one
     * token and `Net 90` as `90`, which is what makes "an extra number" a
     * decidable question at all. Forwards: every token must be a figure the
     * template wrote. Backwards: every figure the template wrote must appear
     * as a token.
     *
     * The backwards direction is what replaces `str_contains()`, and it is
     * not a restatement of it. `str_contains($reworded, '5')` is satisfied by
     * `950.00`, so "We can bring this quote to 950.00 EUR, valid until
     * 2026-09-11." -- a reply that dropped the reduction entirely -- passed
     * the old guard. Every single-digit reduction has that shape, and so does
     * every 0% one, which is exactly what a per-line concession produces.
     */
    private static function figuresAreWrong(
        string $reworded,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): ?string {
        preg_match_all('#\d+(?:[.,:/-]\d+)*#', $reworded, $matches);
        /** @var list<string> $tokens */
        $tokens = $matches[0];

        $facts = [
            'the reduction percentage' => self::percent($reductionPercent),
            'the new total' => self::money($totalNet),
            'the validity date' => $validUntil->format('Y-m-d'),
        ];

        foreach ($tokens as $token) {
            if (!self::statedAmong($facts, $token)) {
                return 'it states a figure nobody authorised: ' . $token;
            }
        }

        foreach ($facts as $name => $fact) {
            if (!self::statedAmong($tokens, $fact)) {
                return 'it dropped ' . $name;
            }
        }

        return null;
    }

    /**
     * Whether any of $figures is the same figure as $subject.
     *
     * Symmetric on purpose: the same helper answers "is this token one of the
     * facts" and "is this fact one of the tokens", which is the whole of the
     * check above.
     *
     * The numeric branch absorbs exactly one measured drift and no more: a
     * model asked to keep `5` writes `5.00%`. Today `str_contains()` accepts
     * that by accident, and a string-exact rule would newly reject a rewording
     * that changed nothing. Comparing through `money()` -- which exists so
     * both sides agree on the string -- keeps it accepted without inventing a
     * tolerance rule. A date never reaches that branch: `2026-09-11` is not
     * numeric.
     *
     * @param iterable<string> $figures
     */
    private static function statedAmong(iterable $figures, string $subject): bool
    {
        foreach ($figures as $figure) {
            if ($figure === $subject) {
                return true;
            }

            if (is_numeric($figure) && is_numeric($subject) && self::money((float) $figure) === self::money((float) $subject)) {
                return true;
            }
        }

        return false;
    }
```

Also extend the class docblock's second paragraph so it still describes the class: after "It cannot hallucinate, which is why it is the safe side of that choice — a plainer sentence reaching a buyer is strictly better than a fluent one with the wrong number in it." add:

```
 * `unsafeBecause()` is the other half of that sentence: it decides when the
 * fallback fires, and it is the only code between a model's free text and a
 * buyer.
```

In `src/Negotiation/ReplyComposer.php`, replace lines 96-102 with:

```php
        $unsafe = ReplyTemplate::unsafeBecause($reworded, $reductionPercent, $total, $validUntil);

        if ($unsafe !== null) {
            // Logged with the reason, not just the text: the fallback is a
            // correct reply, so an over-firing guard fails nothing and shows
            // up nowhere except as replies that never sound reworded. The
            // reason makes "always the same rule" one grep.
            $this->logger->warning('The reworded reply did not survive the guard; sending the template instead.', [
                'reason' => $unsafe,
                'reworded' => $reworded,
            ]);

            return [$template, null];
        }
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `composer run test -- --filter ReplyTemplateTest`
Expected: PASS, every data row.

Then run the whole suite — several existing tests feed reworded strings through this guard:

Run: `composer run test`
Expected: PASS. If `ReplyComposerTest` or any pipeline test now falls back where it used to ship, do NOT relax the guard to suit the fixture: read the fixture string, decide whether a buyer should receive it, and fix whichever of the two is wrong. Report the decision.

- [ ] **Step 5: Format, lint, commit**

```bash
composer run format
composer run lint
composer run typecheck
/usr/bin/git add src/Negotiation/ReplyTemplate.php src/Negotiation/ReplyComposer.php tests/Unit/Negotiation/ReplyTemplateTest.php
/usr/bin/git commit -F - <<'MSG'
fix(reply): let a rewording keep the facts and add nothing else

keepsTheFacts() checked that three figures were still present and never
that nothing had been added, so a rewording ending "...and we will also
include free shipping and Net 90 terms" reached the buyer intact. Since
1b947a9 that is not merely an unverified term but an unhonourable one:
AskGate escalates every non-price ask and OfferApplier writes price and
expiry only, so nothing downstream could act on it.

unsafeBecause() replaces it with one pass over the rewording's
number-shaped tokens, run in both directions: every token must be a
figure the template wrote, and every figure the template wrote must
appear as a token. The second direction is not a restatement of the old
check -- str_contains($reworded, '5') is satisfied by 950.00, so a reply
that dropped a single-digit reduction entirely passed too, and every 0%
reduction (what a per-line concession produces) had the same shape.

On top of that: the prompt's own five-sentence cap, now enforced in
code, and a short English list of concession nouns. It returns the
reason rather than a boolean, because the fallback is a correct reply --
an over-firing guard fails nothing and would otherwise be invisible.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
```

---

### Task 2: Pin the buyer-facing behaviour, and pin it against a strategy

**Files:**
- Modify: `tests/Unit/Negotiation/ReplyComposerTest.php` (add two cases)
- Test: same file

**Interfaces:**
- Consumes: `ReplyTemplate::unsafeBecause()` from Task 1; `ScriptedClient::spy(array $replies): array{0: ModelPlatform, 1: object}`, `NegotiationFixture::snapshot()`, `NegotiationFixture::settings(strategy: ?string)`, `SnapshotAdapter::conversation()` — all already used by every case in this file.
- Produces: nothing consumed later.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Negotiation/ReplyComposerTest.php`, before the closing brace. The first is the issue's acceptance test. The second is the sibling of `StrategyCannotBypassGuardrailsTest` that the spec argues for: that test pins what a strategy cannot move; this one pins what a strategy cannot make the model say.

```php
    /**
     * Issue #53's acceptance test. Every fact survives -- the percentage, the
     * total, the date -- and the sentence still ends with a commitment the
     * merchant never made. The old guard passed this verbatim to the buyer.
     *
     * Free shipping is not a term the agent failed to verify; it is a term
     * AskGate escalates and OfferApplier cannot write, so the buyer would be
     * holding a promise that nothing in this system can honour.
     */
    public function testARewordingThatKeepsEveryFactAndAddsAConcessionFallsBackToTheTemplate(): void
    {
        [$client] = ScriptedClient::spy([
            'We can bring this quote down by 5% to 950.00 EUR, and we will also include free shipping '
                . 'and Net 90 terms. The offer is valid until 2026-09-11.',
        ]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertSame(
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11.',
            $gateway->comments[0],
        );
        self::assertStringNotContainsString('shipping', $gateway->comments[0]);
    }

    /**
     * StrategyCannotBypassGuardrailsTest pins that no strategy can move a cap,
     * because the band gate is deterministic code ahead of the model. This is
     * the same claim one stage later: the strategy reaches the reply prompt's
     * {{tone}} placeholder, the model does as it is told, and the guard is
     * what decides the buyer still reads only the template.
     *
     * Be precise about what this proves: it pins the guard, not the model. No
     * offline test can show a model will not try.
     */
    public function testAGenerousStrategyCannotPutAnExtraInTheBuyersReply(): void
    {
        [$client] = ScriptedClient::spy([
            'Thank you for your patience. We can bring this quote down by 5% to 950.00 EUR and cover '
                . 'delivery for you. The offer is valid until 2026-09-11.',
        ]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(
                strategy: 'Be generous and accommodating. Where you can, throw in an extra to close the deal.',
            ),
            5.0,
            SnapshotAdapter::conversation($after),
        );

        self::assertNull($hash);
        self::assertStringNotContainsString('delivery', $gateway->comments[0]);
    }
```

- [ ] **Step 2: Run the tests and verify they pass**

They should pass immediately — Task 1 implemented the behaviour, and these pin it at the buyer-facing boundary rather than at the guard. Confirm by stashing nothing and instead checking the assertions are meaningful: temporarily change `self::assertNull($hash)` to `self::assertNotNull($hash)` in the first test, confirm it FAILS, then change it back.

Run: `composer run test -- --filter ReplyComposerTest`
Expected: PASS, and the temporary inversion FAILED before you reverted it.

- [ ] **Step 3: Commit**

```bash
composer run format
/usr/bin/git add tests/Unit/Negotiation/ReplyComposerTest.php
/usr/bin/git commit -F - <<'MSG'
test(reply): pin that a concession never reaches the buyer

Two cases at the buyer-facing boundary rather than at the guard: the
rewording from issue #53, which keeps all three facts and still promises
free shipping and Net 90 terms, and a merchant strategy that asks the
model to throw in an extra. Both must arrive as the template.

The second is the sibling of StrategyCannotBypassGuardrailsTest: that one
pins what a strategy cannot move, this one pins what a strategy cannot
make the model say.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
```

---

### Task 3: Make the prompt say what the code enforces

**Files:**
- Modify: `config/agents/quote-reply-agent.prompt.md`
- Modify: `src/Negotiation/PromptComposer.php:52-55` (stale method name in a comment)
- Modify: `src/Policy/OfferLevelMirror.php:38` (stale method name in a docblock)

**Interfaces:**
- Consumes: `ReplyTemplate::unsafeBecause()` from Task 1 — these two files name the old `keepsTheFacts()` in prose and would otherwise send a reader to a method that no longer exists.
- Produces: nothing.

- [ ] **Step 1: Add the rule to the prompt**

`config/agents/quote-reply-agent.prompt.md` currently reads:

```
You reword a B2B quote reply for a merchant. Keep every fact exactly as given:
the percentage the quote came down by, the new total, every listed change to the
offer, and the validity date (keep every number and the date verbatim, dates as
YYYY-MM-DD). Never invent prices, discounts, or terms.
Reply with the reworded text only, plain text, max 5 sentences.

Tone instructions from the merchant: {{tone}}
```

Insert one sentence after "Never invent prices, discounts, or terms.":

```
Add nothing: no number, date, price, discount, term, or promise that is not
already in the text you are given. Anything you add is dropped and the plain
text is sent instead.
```

Leave "max 5 sentences" exactly as it is — the code now enforces that number, and changing one without the other is what issue #53 is.

- [ ] **Step 2: Fix the two stale references**

In `src/Negotiation/PromptComposer.php`, the comment in `reply()` says "ReplyTemplate::keepsTheFacts() sends the template instead if a number moved". Replace that clause with one that matches the guard's new scope:

```php
        // The reply's tone comes from the same strategy field that tunes the
        // negotiation prompt; there is no separate tone setting any more. The
        // reply model can only reword -- ReplyTemplate::unsafeBecause() sends
        // the template instead if a figure moved or a new one appeared -- so a
        // strategy that talks about percentages cannot price anything from
        // here, and one that talks about extras cannot promise anything.
```

In `src/Policy/OfferLevelMirror.php:38`, the docblock says "keepsTheFacts() rejects a rewording". Change that name to `unsafeBecause()` and leave the surrounding sentence intact.

- [ ] **Step 3: Verify nothing else names the old method**

Run: `grep -rn "keepsTheFacts" src tests config docs`
Expected: matches only inside `docs/superpowers/` (the spec and this plan describe the change and should keep the old name).

- [ ] **Step 4: Run the full gates**

Run: `composer run test`
Expected: PASS.

Run: `composer run quality`
Expected: PASS, every sub-task.

Run: `composer run test:integration`
Expected: needs a running shop. If it is unreachable, say so plainly in the report — do not claim it passed.

- [ ] **Step 5: Commit**

```bash
/usr/bin/git add config/agents/quote-reply-agent.prompt.md src/Negotiation/PromptComposer.php src/Policy/OfferLevelMirror.php
/usr/bin/git commit -F - <<'MSG'
docs(reply): tell the model the rule the code now enforces

The prompt asked the model not to invent terms and said nothing about
adding them, which is the gap issue #53 walked through. Steering the
model to pass is the cheapest reduction in false rejections there is,
and the fallback on rejection is a plainer reply on every single pass.

"max 5 sentences" is left exactly as it was: the code now enforces that
number, and a code limit stricter than the prompt would reject a model
that did as it was told.

Also renames keepsTheFacts() where PromptComposer and OfferLevelMirror
name it in prose.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
```

---

## Self-Review

**Spec coverage:** §1 reason-returning guard → Task 1. §2 numeric positive list → Task 1 (`unauthorisedFigure`). §3 five-sentence cap → Task 1 (`MAX_SENTENCES`). §4 vocabulary → Task 1 (`CONCESSIONS`). §5 language honesty → Task 1 (docblock). §6 prompt line → Task 3. §7 rejection still ships the template → unchanged code, pinned by Task 2. Spec's Testing §1 → Task 1's `ReplyTemplateTest`; §2 → Task 2 case one; §3 → Task 1 Step 4 full-suite run; §4 → Task 2 case two.

**Placeholders:** none. Every code step carries the code.

**Type consistency:** `unsafeBecause(string, float, float, \DateTimeImmutable): ?string` is named identically in Tasks 1, 2 and 3. `figuresAreWrong` takes four parameters (mago's limit is five). `statedAmong(iterable<string>, string): bool` is called with `array<string, string>` in one direction and `list<string>` in the other — both are `iterable<string>`, which is why the parameter is typed that way. `preg_match_all` is cast to `int` before comparison so no `int|false` escapes into mago analyze; the second `preg_match_all` is used only for its `$matches` out-parameter, whose `[0]` is always a `list<string>`.
