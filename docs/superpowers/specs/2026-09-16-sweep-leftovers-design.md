# Sweep Leftovers: A Reply The Guard Rejects, And A Logger That Takes It Down

Date: 2026-09-16

## Status

Approved. Written for issues #146 and #147.

Both are leftovers from the #141 sweep (PR #145), reported there and deliberately
not fixed there. They are one document because they are two halves of the same
path: #146 restores the integration suite's coverage of `ReplyComposer::reword()`,
and #147 hardens the branch #146 stops exercising.

### Where the user would have been asked

No user was reachable. Three questions would have been asked; each is recorded
with the assumption taken instead.

1. **#146 — may `ScriptedClient` grow a lazy reply?** The six sites run against a
   real shop quote, so the reply's figures are not known until after the write.
   The scripted client builds its whole response queue up front.
   **Assumed: yes, minimally.** One queue entry may be a
   `Closure(string $userPrompt): string` resolved at call time. The alternative —
   hardcoding the after-total — is the brittleness #145 removed, and the measured
   totals differ per quote (`90.25` vs `57.00` in the same run) and would move
   with any change to the seeded catalogue.
2. **#146 — may `assertPrivateReplyBoundary()` stop pinning the template?**
   Two of the six (`HistoryInjectionTest:31, 87`) *do* look at the comment today,
   and assert it equals the template, with the message "The short scripted reply
   must fall back to verified facts." That assertion is true but it pins the
   defect: the reword path is never taken. **Assumed: yes, replace it.** The
   trait's subject is the private-history boundary, and a guard-accepted
   rewording that still carries no private facts is a strictly stronger proof of
   it. The fallback itself stays pinned at unit level by `RewordingGuardTest` and
   by #145's `PipelineHarness::rewordedReply()` sites.
3. **#147 — guard only the guard-rejection warning, or its sibling too?** The
   `catch (ModelUnavailable)` branch three lines above is in the identical
   position: template composed, fallback decided, nothing left but to return it.
   **Assumed: both, through one helper.** Guarding the branch the issue names and
   leaving its twin is the shape a root-cause fix exists to avoid.

## Measurement first (#146)

Every one of the six scripted replies was run through the real pipeline against
`merchant-quote-shop` before anything changed, with a logger that printed the
guard's verdict and the template the composer had built.

All six reached `reword()`, all six were rejected, all six with the same reason:

```
reason=it dropped the new total   reworded=We can offer 5% off.
```

The facts the guard was comparing against:

| Site | reduction | total | currency | expiry |
|---|---|---|---|---|
| `NegotiationPipelineTest:33` | `5` | `90.25` | EUR | `2026-09-30` |
| `NegotiationPipelineTest:83` | `5` | `90.25` | EUR | `2026-09-30` |
| `HistoryInjectionTest:31` | `5` | `57.00` | EUR | `2026-09-30` |
| `HistoryInjectionTest:87` | `5` | `57.00` | EUR | `2026-09-30` |
| `HistoryInjectionTest:130` | `5` | `57.00` | EUR | `2026-09-30` |
| `DecisionRecordTest:231` | `5` | `90.25` | EUR | `2026-09-30` |

**The defect matches the issue, with one correction and one addition.**

- The issue says the string "carries no total and no validity date". Both are
  true, but the guard reports only its first objection, so the *date* is not
  what fires — and would not fire even after the total was fixed only by
  luck of ordering. This is the same "the guard reports one objection at a
  time" finding #145 recorded; here it collapses to one reason rather than two
  populations.
- The issue calls all six blind. Two are not: `HistoryInjectionTest:31` and
  `:87` route through `assertPrivateReplyBoundary()`, which asserts the comment
  and the audit `replyToBuyer` *equal the template*. They are not tests that
  cannot fail — they are tests that pin the fallback and say so. The coverage
  loss is the same; the fix has to change an assertion rather than add one.
  The remaining four (`NegotiationPipelineTest:33, 83`,
  `HistoryInjectionTest:130`, `DecisionRecordTest:231`) never look at the
  comment at all.

The expiry is `+14 days` from the run date, so no hardcoded date can survive.
The totals differ between the two quote fixtures. Together these are why the
reply must be derived, not typed.

## #146 — design

**The reply is derived from the template the composer hands the model.**

`ReplyComposer::reply()` passes `ReplyTemplate::compose()`'s output as the reply
call's *user prompt*. `assertPrivateReplyBoundary()` already asserts exactly
that, and that assertion passes today — so the template is available at call
time, carrying the re-read snapshot's own figures, without the test predicting
anything.

Three pieces:

1. `ScriptedClient` accepts `string|Closure(string $userPrompt): string` per
   queue entry. Strings behave exactly as before; a closure is resolved when the
   call arrives, with the user prompt it was sent. Six lines, no change to any
   existing call site.
2. `PipelineFixture::reworded(string $template): string` turns the template's
   final sentence into a trailing clause:
   `'. The offer is valid until '` → `', valid until '`. Same figures, different
   sentence — the shape `RewordingGuardTest` already pins as accepted, and the
   same shape `PipelineHarness::rewordedReply()` uses at unit level. It asserts
   its own output differs from its input: a transform that silently became a
   no-op would put the tests straight back where they started, since a scripted
   reply identical to the template ships either way.
3. `PipelineFixture::replyTemplateFor(QuoteSnapshot $before, QuoteSnapshot $after)`
   builds the template the way `assertPrivateReplyBoundary()` builds it today —
   hoisted out of that trait, which now calls it.

The scripted entry is then the first-class callable `self::reworded(...)`, and
the expectation is `self::reworded(self::replyTemplateFor($before, $after))`.

This is not circular. The scripted side transforms whatever prompt it is given;
the assertion side transforms a template the test reconstructs from the database.
A composer that handed the model the wrong template would produce a comment the
assertion rejects.

**What each site asserts afterwards**

| Site | added / changed |
|---|---|
| `NegotiationPipelineTest:33` | the new agent comment is the reworded reply |
| `NegotiationPipelineTest:83` | the single agent comment is the reworded reply |
| `HistoryInjectionTest:31, 87` | `assertPrivateReplyBoundary()` expects the reworded reply for the comment and `replyToBuyer`; the reply *prompt* stays pinned to the template |
| `HistoryInjectionTest:130` | the agent comment is the reworded reply |
| `DecisionRecordTest:231` | `replyToBuyer` is the reworded reply, replacing `assertNotNull` |

`RecordedPassTest:221` stays untouched, for the reason #145 recorded: its subject
is the write failure.

**RED check.** `RewordingGuard::unsafeBecause()` is forced to reject
unconditionally; each of the six must fail; the force is reverted.

## #147 — design

`ReplyComposer::reword()` has two branches that log and return the template.
Both reach that point with a correct reply composed and nothing left to do but
hand it back, so a logger that throws — Shopware's monolog stack can, on a full
disk or a failing handler — turns a handled case into an unanswered buyer.

Both route through one private `fallback(string $template, string $message, array $context)`
that wraps the `warning()` call in `catch (\Throwable)` and returns
`[$template, null]`. The docblock states the argument and names the neighbours it
follows: `NegotiationPipeline::record()`, `ShopwareEscalationNotifier::attempt()`,
`QuoteEscalator`.

### The rest of the `src/` sweep

The distinguishing property is narrow: **the answer is composed but not yet
delivered.** Only `reword()`'s two branches have it. Every other candidate was
checked and is not fixed:

- `ReplyComposer::send()` — the comment is already posted when this logs. A
  throw costs the state transition, not the buyer's answer, and the retry
  re-enters through `OfferRound::finishStrandedReply()`.
- `OfferRound::finishStrandedReply()` — same; it logs about an answer the buyer
  already has, and the state check that brings the retry back here is idempotent.
- `NegotiationPipeline::record()` — **already guarded.** Both its `error()` and
  its `info()` sit inside an outer `catch (\Throwable)` whose docblock gives this
  exact reason.
- `ShopwareEscalationNotifier::attempt()` — its own `error()` is unguarded, but
  `QuoteEscalator` wraps the notifier in `catch (\Throwable)` one level up, which
  the issue text already records.
- Everything else (`Protocol/`, `Servicing/`, `Audit/`, the remaining
  `Negotiation/` calls) logs before a decision rather than after a composed
  answer, and a throw there fails a pass that had nothing in hand to lose.

## Testing

#146 is itself the test change; its verification is the RED check plus the full
integration suite.

#147 gets no new test. The existing unit coverage of the fallback
(`ReplyComposerTest`, `RewordingGuardTest`) runs through the new helper and pins
that the template still ships; a test for "a logger that throws does not fail the
pass" would need a deliberately hostile logger double, which is the shape #145
used as a throwaway RED harness and not something worth keeping. Recorded as a
deliberate omission rather than an oversight.

## Gates

`composer run test`, `composer run quality`, `composer run test:integration`.
`PluginConfigTest::testInstallTimeDefaultsArePersistedWithNativeTypes` may be red
for an unrelated reason; anything else is verified against the base commit
checked out detached.
