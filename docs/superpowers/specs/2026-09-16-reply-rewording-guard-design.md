# Reply Rewording Guard

Date: 2026-09-16

## Status

Approved. Written for issue #53.

## Context

`ReplyComposer::reword()` asks the model to reword the deterministic reply that
`ReplyTemplate::compose()` wrote, and posts the result to the buyer with
`$gateway->addComment()`. The only thing standing between the model's free text
and the buyer is `ReplyTemplate::keepsTheFacts()`, which is three
`str_contains()` calls plus a non-empty check: the reduction percentage, the
total, the validity date.

Nothing bounds length, sentence count, extra numbers or vocabulary. A rewording
that appends "…and we will also include free shipping and Net 90 terms" keeps
all three facts and ships verbatim.

Three things make this the sharpest edge in the plugin rather than a cosmetic
concern:

1. **It is the only remaining path for model free text to reach a buyer.** The
   negotiate call's `modelMessage` is discarded; `QuoteEscalator`'s buyer
   message is a constant; the extract call writes nothing buyer-facing. This
   one is not.

2. **The terms the model can write are terms the system has deliberately made
   impossible to authorise.** Since payment and delivery were retired from the
   mandate (`1b947a9`), `AskGate::refuse()` escalates every non-price ask,
   `OfferTerms` no longer carries `delivery` or `payment`, and `OfferApplier`
   writes price and expiry only. `AskGate`'s own docblock says it: "promising
   shipping that never lands is worse still." The guard is the hole through
   which exactly that promise still escapes — not merely unverified, but
   unhonourable by construction. The buyer holds a written commitment that
   nothing downstream can act on.

3. **Merchant strategy text steers this call.** `PromptComposer::reply()`
   substitutes the merchant's `strategyPrompt` into the reply prompt's
   `{{tone}}` placeholder. `StrategyCannotBypassGuardrailsTest` pins that no
   strategy can move a *cap*, because the band gate is deterministic code ahead
   of the model. There is no equivalent pin on what a strategy can make the
   model *say*, because the thing that would do the pinning is these three
   `str_contains()` calls. A strategy of "be generous and accommodating, offer
   extras where you can" is not an attack — it is a plausible merchant
   sentence — and today it reaches the buyer intact.

The prompt (`config/agents/quote-reply-agent.prompt.md`) asks for "max 5
sentences" and "never invent prices, discounts, or terms". That is prose. It
is not enforced anywhere.

### What "safe" means here, and what it costs

The existing docblock states the principle this design must preserve:

> a plainer sentence reaching a buyer is strictly better than a fluent one with
> the wrong number in it.

The fallback on rejection is the template — correct, complete, and blunt. That
makes over-rejection cheap per occurrence and expensive in aggregate: a guard
that fires on every legitimate rewording does not fail loudly, it quietly turns
the rewording feature off while every gate stays green. Bounding false
rejections is therefore a first-class requirement of this change, not a
courtesy.

## Decisions

### 1. The guard reports a reason instead of a boolean

`keepsTheFacts(string, float, float, \DateTimeImmutable): bool` becomes
`unsafeBecause(string, float, float, \DateTimeImmutable): ?string` — `null`
when the rewording may ship, otherwise a short operator-readable reason.

The signature is otherwise unchanged and the method stays the single choke
point, so every caller is fixed by fixing it. `ReplyComposer::reword()` logs
the reason.

This matters because of the failure mode named above. A guard that turns the
feature off silently is indistinguishable in the logs from a guard that never
fires. `'reworded' => $reworded` alone makes an operator reconstruct the
verdict by eye; the reason makes "every rewording is being rejected, and always
for the same rule" a one-line grep.

### 2. Numbers: a positive list, decided by the template's own formatters

Every number-shaped token in the rewording must be one the template put there.

Tokens are extracted with `/\d+(?:[.,:\/-]\d+)*/`, which takes `2026-09-11`,
`950.00` and `5` each as one token, and `Net 90` as `90`.

A token is accepted when either:

- it is exactly `percent($reductionPercent)`, `money($totalNet)` or
  `$validUntil->format('Y-m-d')`; or
- it is `is_numeric()` and `money((float) $token)` equals `money()` of the
  reduction percent or of the total.

`percent()` and `money()` already exist precisely so "both sides agree on the
string" — the guard reuses them rather than re-deriving number parsing. The
second branch exists only to absorb the one harmless formatting drift a model
actually produces: writing `5.00%` where `percent()` wrote `5`. Today
`str_contains($reworded, '5')` accepts that by accident, since `5` is a
substring of `5.00`; a token-exact rule would newly reject it. Comparing
through `money()` keeps it accepted without inventing tolerance rules.

Rejected by construction, and correctly: `Net 30`, `Net 90`, `2% 10 net 30`,
`within 48 hours`, `valid for 14 days`, `order 3 more units`, a second date in
any format, a phone number, a time of day.

### 3. Sentences: the prompt's own cap, enforced in code

More than **5** sentence terminators rejects. A terminator is `.`, `!` or `?`
followed by whitespace or end of string, which is what keeps the `.` inside
`950.00` from counting.

Five, not a tighter number. The cap's job is to make the prompt's existing
limit load-bearing, and code that is stricter than the prompt rejects a model
that did exactly as it was told — which is the false-rejection failure mode,
arrived at deliberately. The template writes two sentences; five leaves an
opener and a sign-off and a merchant tone that wants both.

Be precise about what this buys: it bounds the *volume* of unauthorised prose,
not its content. Three digit-free, vocabulary-clean sentences of invention
still pass. That residual is accepted (see Risks).

### 4. Vocabulary: a short deny list, honestly scoped

Rejects, case-insensitively and on word boundaries:

`shipping`, `freight`, `delivery`, `payment`, `invoice`, `deposit`,
`warranty`, `instalment`, `installment`

Every one of these names a concession `AskGate` escalates and `OfferApplier`
cannot write. None can appear in a faithful rewording of a sentence about a
percentage, a total and a date.

The words deliberately **left out** matter as much, because each is a false
rejection waiting to happen:

- **`free`** — "feel free to reach out" is ordinary merchant tone. A bare
  `\bfree\b` would reject a large share of warm-toned reworded replies. The
  concession it guards against is `free shipping`, and `shipping` already
  catches that.
- **`net`** — `Net 30`/`Net 60`/`Net 90` all carry a digit and are already
  rejected by §2. Meanwhile "Netto" is the ordinary German word for the very
  total being quoted, and `\bnet\b` in an English reply reads as "net total"
  far more often than as payment terms.
- **`terms`** — the template's own subject. "the terms of this offer" is a
  legitimate rewording.

### 5. Language

The word list is English-only and this design does not pretend otherwise.

It is a backstop for the language the model is overwhelmingly prompted in, not
the boundary. The load-bearing rules are §2 and §3, both language-independent:
digits are digits, and `.`/`!`/`?` terminate sentences in every language this
plugin plausibly replies in.

There is also a floor under the whole question that pre-dates this change: the
rewording must already contain `950.00` and `2026-09-11` as ASCII literals to
pass the fact check at all. A reply in a locale that would naturally write
`950,00` or `11.09.2026` already falls back to the template today. This design
does not improve that and does not worsen it.

### 6. The prompt gains one line

`config/agents/quote-reply-agent.prompt.md` gets an explicit statement of what
the code now enforces:

> Add nothing: no number, date, price, discount, term, or promise that is not
> already in the text you are given.

Steering the model to pass is the cheapest possible reduction in false
rejections, and it keeps prompt and code saying the same thing — the divergence
between them is what issue #53 is.

### 7. Rejection still ships the template, and still succeeds

A rejected rewording does not escalate, does not retry, does not fail the pass.
The offer is already applied and the template states it correctly. This is the
existing behaviour on a dropped fact and on `ModelUnavailable`, and this change
does not touch it.

## Questions I could not ask

Recorded as required: this work ran without a channel to the requester, so each
of these is an assumption, not an agreement.

1. **Should the sentence cap be tighter than the prompt's 5?** Assumed no; the
   prompt's number is enforced as written (§3). Tightening the code below the
   prompt would reject a compliant model. Tightening both is a separate,
   reversible decision with a tone cost.
2. **Should the guard strip the offending clause instead of rejecting?**
   Assumed no. Edited text is authored by neither the merchant nor the model,
   and a half-deleted sentence is a worse buyer-facing artefact than the plain
   template.
3. **Should a rejection escalate to a human?** Assumed no (§7). The buyer gets
   a correct reply either way; turning a wording guard into an ops queue is a
   behaviour change outside #53.
4. **Should the deny list be merchant-configurable?** Assumed no. A merchant
   who wants to promise shipping needs a system that can write shipping, not a
   guard that lets the model say it.
5. **Should the guard also require the currency code?** Assumed no. `EUR`
   carries no digits, `keepsTheFacts()` never checked it, and a total without a
   currency beside a quote the buyer is already reading is not an unauthorised
   commitment. Adding it would be a new rejection cause for no safety gain.

## Risks

- **Digit-free, vocabulary-clean invention still passes**, bounded only by the
  5-sentence cap. "We would be glad to accommodate you further." is not
  reachable by substring rules. The honest ceiling of this approach.
- **The deny list is English.** §5.
- **A single-digit reduction percent weakens nothing but loses a little
  precision**: with `percent()` = `5`, a stray `5` anywhere is accepted. It is
  one authorised figure appearing twice, which is what a rewording does.

## Testing

All unit-level; the guard is pure and needs no shop.

1. **`ReplyTemplateTest`** (new) — a table of reworded strings against the
   fixture facts (5%, 950.00 EUR, 2026-09-11), asserting `unsafeBecause()`.
   - *Must pass*: the template verbatim; a warm opener plus the two facts; a
     sign-off; `5.00%` in place of `5`; the percent restated twice; the total
     written as `EUR 950.00`; five sentences exactly; a formal German-toned
     rewording that still carries the ASCII figures.
   - *Must reject*: free shipping appended; `Net 90 terms`; a second invented
     date; `within 14 days`; six sentences; a dropped total; a dropped date;
     empty; and each deny-listed word in a sentence a model would plausibly
     write.
2. **`ReplyComposerTest`** — a rewording that keeps all three facts *and*
   appends an unauthorised concession must reach the buyer as the template, and
   `reply()` must return `null` (template-authored). This is the issue's
   acceptance test.
3. **`ReplyComposerTest`** — the existing cases stay green unchanged; several
   of them feed reworded strings that keep no facts, and those must still fall
   back for the same reason as before.
4. **`StrategyCannotBypassGuardrailsTest`'s sibling claim** — a merchant
   strategy instructing the model to offer extras is pinned at the reply guard:
   the strategy reaches `{{tone}}`, the model complies, and the buyer still
   receives the template.

## Non-goals

- Changing what the template says, or `validityDays` (issue #57 owns that
  vicinity).
- Escalation, retry, or audit-shape changes.
- Any locale-aware number or date handling.
