# Plan: Sweep Leftovers (#146, #147)

Spec: `docs/superpowers/specs/2026-09-16-sweep-leftovers-design.md`
Branch: `fix/146-147-sweep-leftovers`
Two commits: #146 (tests) and #147 (src), in that order.

## Task 1 — #146, the derived reply

Files:

- `tests/Unit/Negotiation/ScriptedClient.php`
- `tests/Integration/PipelineFixture.php`
- `tests/Integration/HistoryInjectionAssertions.php`
- `tests/Integration/NegotiationPipelineTest.php`
- `tests/Integration/HistoryInjectionTest.php`
- `tests/Integration/DecisionRecordTest.php`

1. `ScriptedClient::spy()` / `returning()` accept
   `list<string|\Closure(string): string>`. Resolve a closure inside the
   `MockHttpClient` callable, where the user prompt is already read, and wrap its
   result with `NegotiationFixture::modelReply()`. String entries keep their
   current eager path. No existing call site changes.
2. `PipelineFixture::replyTemplateFor(QuoteSnapshot $before, QuoteSnapshot $after): string`
   — the template the composer builds, reconstructed from the re-read snapshot.
   Lifted verbatim from `assertPrivateReplyBoundary()`, which now calls it.
3. `PipelineFixture::reworded(string $template): string` —
   `str_replace('. The offer is valid until ', ', valid until ', $template)`,
   with an assertion that the result differs from the input and a docblock
   saying why that assertion is load-bearing.
4. Replace all six `'We can offer 5% off.'` queue entries with
   `self::reworded(...)`. Leave `NegotiationPipelineTest:124`
   (`$gateway->addComment(...)`, not a scripted reply) and `RecordedPassTest:221`
   alone.
5. Assertions, per the spec's table. `NegotiationPipelineTest:83`'s test and
   `DecisionRecordTest:231`'s test need a `$before` snapshot captured before the
   pass, and an `$after` one after it.

Verify: `composer run test:integration -- --filter '(NegotiationPipelineTest|HistoryInjectionTest|DecisionRecordTest)'`
green, then `composer run test` green (ScriptedClient is a unit double).

## Task 2 — RED check for #146

Force `RewordingGuard::unsafeBecause()` to return a constant reason, run the six,
record each failure message, revert the force, re-run green. Evidence goes in the
final report.

## Task 3 — #147, the guarded fallback

File: `src/Negotiation/ReplyComposer.php`

Private `fallback(string $template, string $message, array<string, mixed> $context): array{0: string, 1: null}`
that wraps `$this->logger->warning()` in `catch (\Throwable)` and returns
`[$template, null]`. Both `reword()` branches — the `ModelUnavailable` catch and
the guard rejection — return through it, keeping their existing comments. The
docblock carries the argument from the spec and names the neighbours.

Verify: `composer run test`, `composer run quality`.

## Gates before reporting

`composer run test`, `composer run quality`, `composer run test:integration`.
Anything red that is not `PluginConfigTest::testInstallTimeDefaultsArePersistedWithNativeTypes`
gets reproduced on the base commit checked out detached before it is called a
regression.
