# The Escalation Notice Reaches the Buyer

Date: 2026-09-16

Issue: #140.

## Status

Approved (see "Where a human would have been asked" — this was designed without
a human in the loop, so every open question is recorded with its assumption).

## Context

`ServicingConfigGateTest::testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly`
fails on a correctly-configured shop. It invokes the handler twice against a
channel with no LLM API key and asserts that the buyer gets exactly one
escalation comment.

Issue #140 reads the failure as **two** comments and proposes a mechanism: the
marker guard in `QuoteEscalator::escalate()` returns early only when the
INCOMING snapshot already carries the marker, and the comment is written before
the marker is stamped, so a second pass with a stale snapshot would comment
again.

**That reading is wrong, and the mechanism it proposes is not the defect.**

### What the evidence says

PHPUnit's `assertSame($expected, $actual)` renders its failure as
"Failed asserting that {actual} is identical to {expected}". Confirmed against
this repo's own PHPUnit 11.5.55 with a throwaway `assertSame(2, 1)`:

```
Failed asserting that 1 is identical to 2.
```

The gate test fails with exactly that string. So the actual comment count is
**1** — the one comment the fixture quote already carried — and the expected
count is 2. **Zero escalation comments were written.** The buyer is told
nothing at all, which is the opposite of the reported symptom.

Three throwaway integration probes against `merchant-quote-shop` (written,
run, and deleted — they are not part of this change) settle the rest:

1. **The marker round-trips.** `updateQuote(customFields: [MARKER_KEY =>
   'not_configured'])` followed by a fresh `fetchSnapshot()` reads the marker
   back, alongside the untouched `a2cn_act_*` keys. `QuoteWriter`'s JSON_SET
   merge and `QuoteSnapshotReader::readLifecycle()` both behave as documented.
2. **The marker guard works.** `QuoteEscalator::escalate(..., notifyBuyer:
   true)` called twice, each time with a freshly fetched snapshot: pass 1
   writes a comment (1 → 2), pass 2 sees the marker and writes nothing (2 → 2).
3. **`ServicingPreflight::check()` writes the marker and no comment.** Three
   consecutive calls with the wired container preflight: the marker appears
   after the first, the comment count never moves off its starting value.
   Driving the same scenario through `ServiceQuoteHandler` twice — the gate
   test's exact shape — reproduces it: marker stamped after invocation 1,
   comment count unchanged after both.

So the two handler invocations do share a transaction (`DatabaseTransactionBehaviour`),
the first one's `updateQuote` *is* visible to the second one's `fetchSnapshot`,
and the marker guard suppresses the second escalation exactly as designed. The
test never reaches the marker question, because the first escalation writes no
comment either.

### The root cause

`QuoteEscalator::shouldNotifyBuyer()`:

```php
try {
    return $this->settingsSource->forSalesChannel($salesChannelId)?->notifyBuyerOnEscalation ?? false;
} catch (InvalidQuoteAgentConfiguration) {
    return false;
}
```

`QuoteEscalationReason::NotConfigured` is escalated by `ServicingPreflight`
from the `catch (InvalidQuoteAgentConfiguration)` arm of the *same*
`forSalesChannel()` call. When the escalator asks the same source the same
question a microsecond later, it necessarily throws again. **The condition that
triggers a `NotConfigured` escalation is, by construction, the condition that
silences its buyer notice.** This is not a race, a caching artefact or a test
artefact; it is unconditional on every shop.

The affected reason is `NotConfigured` only. `OfferRound::escalated()` escalates
from inside a pipeline that only runs once `forSalesChannel()` returned valid
settings, so the pipeline's escalations notify correctly today.

### How it got here

`docs/superpowers/specs/2026-09-10-silent-escalation-toggle-design.md` decided,
in the same breath:

- default `notifyBuyerOnEscalation` to **false** (silent), and
- "If configuration is invalid (`InvalidQuoteAgentConfiguration`), fall back to
  `false` (silent)."

Consistent: the fallback matched the default. Commit `532992d`
("feat(config): tell the buyer about an escalation unless told not to") flipped
the default to notify — `config.xml` `<defaultValue>true</defaultValue>`,
`QuoteAgentSettingsFactory`'s `!== false`, `QuoteAgentSettings::$notifyBuyerOnEscalation
= true` — and left the fallback at `false`. The fallback stopped meaning "the
default" and started meaning "the opposite of the default", in the one case
where the setting cannot be read.

`ServicingPreflight`'s own docblock has said all along what the intended
behaviour is:

> a missing API key or config that fails its own constraints is a
> misconfiguration, and silence there is the exact bug issue #5 exists to
> remove, so it escalates: an error log line for the merchant, carrying the
> problems, and a neutral comment for the buyer.

The buyer comment has been missing since `532992d`.

### The test that hid it

`ServicingPreflightTest::testAMisconfiguredChannelWithBuyerNotificationEnabledWritesCommentAndMarksQuote`
passes, and asserts `['addComment', 'updateQuote']` for exactly this scenario.
It passes because it builds **two different settings sources**: one that throws,
handed to the preflight, and a separate non-throwing one that returns valid
settings with `notifyBuyerOnEscalation: true`, handed to the escalator. In
`services.php` both arguments are `service(QuoteAgentSettingsSource::class)` —
the same instance. The test asserts a wiring that cannot exist.

This is the #81 / #87 pattern the repo has been bitten by: a test that passes
whether or not the thing it names is true.

`QuoteEscalatorTest::testItFallsBackToSilentWhenSettingsSourceThrows` pins the
defect as intended behaviour. It was correct under the pre-`532992d` default and
is wrong now; it is replaced (see Decisions).

### Reconciling with the flock / `PrivateTmp` diagnosis

The two diagnoses do not compete, because they are about different symptoms:

| | Prior (2026-09-08, hoelshare) | This issue |
|---|---|---|
| Symptom | Buyer got **two** escalation notices | Buyer gets **zero** |
| Mechanism | Two processes serviced one quote concurrently; Symfony's `FlockStore` keys on `sys_get_temp_dir()`, and Apache's `PrivateTmp` gave the web-request admin worker a different `/tmp` from the CLI `messenger:consume` worker, so `QuoteServicingLock` did not exclude them | `shouldNotifyBuyer()` cannot read the toggle when the config is invalid |
| Layer | Infrastructure / lock store | Application logic |

The flock finding stands, unamended, and remains the only established
real-world cause of two escalation notices. Nothing in this change touches the
lock, and this change cannot substitute for it: the marker guard is a
**sequential** backstop — it suppresses a second pass that reads after the first
has committed. Two concurrent passes that both read before either writes see no
marker and both comment. Only the lock prevents that, which is exactly why the
lock exists and why `QuoteServicingLock`'s docblock documents the `PrivateTmp`
ceiling in detail.

Issue #140's marker-ordering theory is therefore a third thing: a mechanism
inferred from reading, for a symptom that does not occur. It is not adopted.

## Decisions

### 1. Read the toggle without validating the rest of the configuration

`notifyBuyerOnEscalation` is a plain merchant-facing boolean. Nothing about it
depends on the LLM credentials being present or the policy numbers being
in-range. Routing it through `QuoteAgentSettingsFactory` — which throws before
it constructs anything — is what makes it unreadable exactly when it matters.

A new one-method interface in `Config`:

```php
interface BuyerNotificationPreference
{
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool;
}
```

implemented by `QuoteAgentSettingsReader`, which already holds
`SystemConfigService` and already lists the key in `KEYS`. It reads that one key
and applies the same `!== false` rule the factory applies, so an unset value and
a `true` value both mean notify, and only an explicit `false` means silence.
This keeps the reader's stated invariant — it remains the one class that
touches the configuration store.

`QuoteEscalator`'s constructor takes `?BuyerNotificationPreference` in place of
`?QuoteAgentSettingsSource`, and `shouldNotifyBuyer()` loses its try/catch
entirely; there is nothing left to throw.

**Rejected: return `true` from the existing `catch`.** One line, and it is the
laziest thing that turns the test green — but it overrides an explicit merchant
`false` in precisely the state where the merchant is least able to correct it.
The silent-escalation toggle exists because some merchants must not have
automated comments posted to buyers at all; honouring it only while the rest of
the configuration is valid is not honouring it.

**Rejected: add the method to `QuoteAgentSettingsSource`.** Seven anonymous test
implementations would have to grow a method six of them do not care about, and
`MandateDocumentResponder` would inherit a concern that is none of its business.

**Rejected: carry the flag on `InvalidQuoteAgentConfiguration`.** Turns an
exception into a data channel, and leaves two ways to answer one question.

### 2. `escalate()` keeps writing the comment before the marker

The ordering is deliberate and documented, and the docblock's argument survives
scrutiny — but not for the reason it gives. Its stated reason (a notifier that
throws must not cost the buyer their comment) is about the `notify()` call,
which already runs after both writes and is independently try/caught; it does
not bear on the `addComment` / `updateQuote` order.

The order is still right, on a different argument. If `updateQuote` throws after
`addComment` succeeds, the next pass comments a second time — the buyer is told
twice. If the order were reversed and `addComment` threw after the marker was
stamped, the marker would suppress every later attempt and the buyer would never
be told, silently, forever. The current order fails toward "said twice"; the
reversed order fails toward "never said". For a notice whose whole purpose is
that the buyer is not left in silence, the current order is the safe one.

Left unchanged. The docblock gains a sentence naming this argument, so the next
reader does not have to re-derive it.

### 3. The marker stays inside `escalate()`

#140 asks whether the marker belongs to the caller that decides to escalate.
It does not. Two callers escalate (`ServicingPreflight`, `OfferRound::escalated`),
the marker's read and its write are two halves of one guard, and
`QuoteEscalator::releaseFor()` already owns the key's clearing rule. Moving the
write out would split one invariant across three files to fix a bug that the
evidence shows does not exist.

### 4. The integration test sets the toggle it depends on

`ServicingConfigGateTest::testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly`
currently depends on the shop's stored `notifyBuyerOnEscalation` without saying
so, which per MEMORY's "test shop config vs install-time defaults" is how these
suites go red for reasons unrelated to the code. It sets the key explicitly to
`true`, next to the two keys it already sets.

With the fix in place the test then exercises what its name claims for the first
time: the first invocation writes one comment, the second is suppressed by the
marker. Until now it could not have detected a marker-guard regression, because
there was never a first comment for a second one to duplicate.

### 5. The two lying unit tests are corrected

- `ServicingPreflightTest::testAMisconfiguredChannelWithBuyerNotificationEnabledWritesCommentAndMarksQuote`
  gives the preflight a throwing `QuoteAgentSettingsSource` and the escalator a
  `BuyerNotificationPreference` that returns `true`. That is the production
  wiring: two collaborators, two questions, and the second one answerable while
  the first throws. Same assertions.
- `QuoteEscalatorTest::testItFallsBackToSilentWhenSettingsSourceThrows` is
  replaced by `testItNotifiesTheBuyerWhenTheConfigurationIsUnreadable`, plus its
  mirror: an explicit merchant `false` stays silent even then.

## Testing

Unit (`composer run test`):

1. `QuoteEscalatorTest` — an unreadable configuration notifies the buyer; an
   explicit `false` does not; `notifyBuyer:` passed explicitly still wins; a
   null preference (no source wired) stays silent, as the existing
   `testItDefaultsToSilentWhenNoSettingsSourceProvided` requires.
2. `QuoteAgentSettingsReaderTest` — `notifyBuyerOnEscalation()` returns `true`
   for an unset key, `true` for `true`, `false` for `false`, and answers
   **while `forSalesChannel()` throws** for the same channel. That last case is
   the regression test for this bug at the unit level.
3. `ServicingPreflightTest` — as decision 5.

Integration (`composer run test:integration`):

4. `ServicingConfigGateTest::testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly`
   passes against `merchant-quote-shop`, with the toggle set explicitly.

Quality (`composer run quality`): unchanged gates.

## Where a human would have been asked

No human was available; each question is recorded with the assumption taken.

1. **Should an explicit `notifyBuyerOnEscalation = false` still be honoured when
   the rest of the configuration is broken?** Assumed **yes** — it is the whole
   point of the toggle, and it is what drove decision 1 over the one-line catch
   change. If the answer were "no, a broken shop always tells the buyer", the
   fix collapses to returning `true` from the existing `catch` and the new
   interface is unnecessary.
2. **Should #140 be closed as "not reproducible as described" rather than
   fixed?** Assumed **no**. The test is red for a real defect, just not the one
   the issue names; the issue's title ("comments the buyer twice") is wrong and
   the coordinator should correct it when the PR is opened.
3. **Is a buyer notice on an unconfigured channel wanted at all, or should a
   shop that has never been configured stay silent until the merchant finishes
   setup?** Assumed **wanted** — `ServicingPreflight`'s docblock and issue #5
   are explicit that silence there is the bug. Noted because a shop mid-setup
   will now post the notice on any quote whose buyer comments, which a merchant
   could reasonably find surprising.

## Not in scope

- The `flock` / `PrivateTmp` ceiling. Documented in `QuoteServicingLock`, fixed
  on hoelshare as an ops change, unchanged here.
- #35's audit row for preflight escalations. Built in parallel; this change
  touches `QuoteEscalator`, `QuoteAgentSettingsReader` and `services.php`, and
  leaves `ServicingPreflight`'s body alone apart from nothing at all.
- The escalation copy, the marker's key, and `releaseFor()`'s rule.
