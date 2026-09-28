# A Price Ask Is Never Answered With 0%

Date: 2026-09-24

## Status

Implemented. 2026-09-24.

## Context

The user: "the agent should never answer with 0% discount — either grant some
discount or escalate if hitting a limit."

Two live quotes on sw-ag.dev, read from `merchant_quote_agent_decision` and
`quote_line_item`:

| Quote | Line price (net) | Buyer's requested price | Ask | Band | Model proposal | Reply |
|---|---|---|---|---|---|---|
| 1097 | 654.53 × 10 | 600.00 | 8.33% | `grant` (cap 15%) | `terms: {discountPercent: null, linePricesNet: null}` | "The total for this quote is 7788.91 EUR. This offer remains valid until …" |
| 1099 | 654.53 × 1 | 590.00 | 9.86% | `grant` (cap 15%) | `terms: null` | "The quote is confirmed at 778.89 EUR. …" |

Both were the first pass (`trigger_reason: state_entered`), with no buyer
comment, no extract call and no `buyer_ask`. They negotiated because
`StructuredAsk::isUnmet()` (since renamed `isOpen()`) saw a `requested_price` below the line price. Both
asks were inside the merchant's authority, and both buyers were answered with
the unchanged quote.

### Root cause

`OfferProposer::userPrompt()` renders each line as
`lineItemId | productId | label | quantity | unit price net`. **The requested
price never reaches the negotiate prompt.** On a structured-only ask the
buyer's comment is empty too, so the model is shown no ask at all. It then
proposes no terms, which is the sensible answer to what it was shown. The
negotiate prompt already tells the model how to answer a line-level ask
("buyer asks <price> per unit") — nothing ever produces that text.

### Why it reached the buyer

A pass whose write lowers the total by nothing
(`OfferRound`: `$grantedThisPass === false`) is answered with
`ReplyTemplate::holds()` — "This quote stands at …" — by design since
#174/#175. That design avoided announcing "down by 0%", but still answers a
price ask with no concession. The same path serves four situations:

1. the model holds (proposes no terms, 0%, or prices at the current level);
2. the buyer repeats an ask after the cap was used up on an earlier round;
3. the minimum-margin floor (#202) leaves nothing more to give;
4. an earlier round already granted the most the band allows.

## Decision

### 1. Show the model the buyer's requested price

`OfferProposer::userPrompt()` adds a sixth column to each line:

```
Line items (lineItemId | productId | label | quantity | unit price net | buyer asks per unit net):
<id> | <product> | Widget | 10 | 654.53 | 600.00
<id> | <product> | Bolt   | 5  | 12.00  |
```

- The value is `QuoteLineSnapshot::$requestedUnitPrice` of the snapshot the
  proposer already prices against (anchored on the baseline; the live
  requested price rides along through `asOriginalOf()`). Empty when null.
- It is net, like every other figure in the prompt. `QuoteLineNet` has already
  converted it, and `Bridge\QuoteLineMapper` has already hidden prices the
  agent itself mirrored onto the line (`MirroredAsks`), so only a number the
  buyer entered appears.
- A requested price at or above the line price is shown as it is. It is not an
  ask for a markup, and the band and `AskedDiscountCeiling` already treat it as
  none.
- The negotiate prompt's per-line rule names the column: when a line shows a
  "buyer asks" price, that line is being negotiated per line and gets an entry
  in `terms.linePricesNet`.

This is the fix for 1097 and 1099. They would have received per-line offers
at, or towards, 600.00 and 590.00.

### 2. A pass that concedes nothing escalates

In `OfferRound::play()`, a verified write that did not lower the total
(`$grantedThisPass === false`, the existing `Epsilon::MONEY` comparison of
`OfferApplier`'s pre-write and post-write reads) no longer replies. It
escalates, through the same `escalated()` path the verification failure
already uses, with a new reason:

```php
// Policy\Data\QuoteEscalationReason
case NoFurtherConcession = 'no_further_concession';
```

- The check runs after the write, deliberately. One comparison covers all four
  situations above, whatever produced them, and the write it follows changed
  no price. At most it refreshed the expiry, stamped the baseline and claimed
  the quote, which the escalation then hands to a human anyway.
- The model's own `escalate` answer keeps its existing reason
  (`model_unavailable`, #169). This reason is only for an offer that went
  through and conceded nothing.
- The buyer sees the existing generic escalation notice (`QuoteEscalator`,
  gated by `notifyBuyerOnEscalation`). No reason-specific text reaches the
  buyer.
- The audit record gets `outcome: escalated`, `escalation_reason:
  no_further_concession`, plus the writes that did happen. The dashboard and
  the review queue count it with the other escalations.

`ReductionForPass::of()` loses its `$grantedThisPass` parameter and its hold
branch, because `OfferRound` no longer calls it for a pass that moved nothing.
What stays is the #175 case where a real write prints as `0` at two decimals
(0.50 EUR off 34456.73). That pass DID concede something, so it keeps
answering with `ReplyTemplate::holds()` and the new, lower total. It is not a
0% answer: the total moved, and the sentence states the total the buyer now
has.

### 3. The negotiate prompt forbids a 0% offer

One rule in `config/agents/quote-negotiate-agent.prompt.md`, next to the
existing escalation rule:

> Never answer a price ask with no concession: an offer of 0%, or line prices
> equal to the ones shown, is not an answer. Offer a real concession within
> your authority, or set action "escalate".

The guard in §2 enforces this whatever the model does. The prompt rule only
makes a hold rarer, so a grantable ask is not handed to a human needlessly.

### 4. Administration

`snippet/en.json` and `snippet/de.json`, in both blocks every reason already
has (the label map and `escalationWhy`):

- label: "No further concession" / "Kein weiteres Entgegenkommen"
- why: "The customer asked for a better price, but this pass could not lower the quote: your maximum discount or minimum margin is already reached, or the agent found nothing it could offer. A person decides whether to go further." / "Der Kunde wollte einen besseren Preis, aber dieser Durchlauf konnte das Angebot nicht senken: Ihr maximaler Rabatt oder Ihre Mindestmarge ist bereits erreicht, oder der Agent fand nichts, was er anbieten konnte. Ein Mensch entscheidet, ob weiter entgegengekommen wird."

The admin has shipped keyed to values PHP no longer wrote twice before, so check
every place it enumerates reasons, not only the snippets.

## What does not change

- A comment that holds no ask is still acknowledged with
  `ReplyTemplate::acknowledges()` ("Thank you for your message. This quote
  stands at …"). That path is `PassedOver`, not `OfferRound`, and it answers a
  message, not a price ask.
- The band decision, the caps, `AskedDiscountCeiling`, the margin floor and
  `OfferVerifier` are untouched.
- A pass that grants anything is answered exactly as today.

## Edge cases

| Case | Behaviour |
|---|---|
| Structured ask inside the cap (1097, 1099) | The model now sees it; per-line offer; answered |
| Model still holds | `no_further_concession` escalation |
| Buyer insists after the cap was used up | `no_further_concession` escalation (reverses #174/#175 for this case, as asked) |
| Margin floor leaves nothing to give | `no_further_concession` escalation |
| A real write that prints as `0` at two decimals | Answered with the new total, as today (#175) |
| Comment with no ask | Acknowledged, as today |
| Comment with no ask after a storefront ask was countered | Acknowledged: the ask's token is in the servicing stamp, so `StructuredAsk::isOpen()` is false even though `requested_price` still sits below the line |
| Pass retried after its write landed but before its reply | Escalates; the human sees the offer already on the quote |
| Requested price at or above the line price | Shown in the prompt; not an ask; the band grants nothing to it |

## Testing

- `OfferProposerTest` (or its prompt test): a line with a requested price
  renders `| 600.00`; a line without one ends in `| ` with no value.
- `OfferRoundTest`: a pass whose write leaves the total unchanged returns
  `NegotiationOutcome::Escalated` with `no_further_concession`, posts no
  "stands at" reply and calls the escalator; a pass that lowers the total
  replies as today.
- `ReductionForPassTest`: the `grantedThisPass: false` case goes; the
  two-decimal `0` case stays.
- `QuoteNegotiatePromptTest`: the prompt carries the no-0% rule.
- Admin: `composer run quality:admin` passes; both snippet files carry the new
  key in both blocks.

## Out of scope

- Falling back to the deterministic pricer when the model holds (the user
  chose escalation).
- A second model call with a "0% is not allowed" nudge.
- Rewording the #175 tiny-reduction reply.
