# A2CN interface gaps — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a counterparty A2CN agent deliver signed acts to the shop over its own default transport, and make the evidence we serve back defensible about time, parties and outcome.

**Architecture:** A new `POST /a2cn/sessions/{id}/messages` accepts a DID-signed act behind an ES256 Bearer JWT, runs it through four small refusal gates, and appends it to the quote's `customFields` under the existing act-key convention. Two new local `EvidenceCheckInterface` implementations make the chain's timeline checkable after the fact. Three read models grow one field each so the transaction record can name the buyer's company and the order the quote became.

**Tech Stack:** PHP 8.3, Shopware 6.7 (DAL + storefront routes), Symfony HttpFoundation/Routing/Lock, PHPUnit, `mago` (fmt/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-09-10-a2cn-interface-gaps-design.md`

## Global Constraints

- **Quality gates, all of them, before every commit:** `composer format:check`, `composer lint`, `composer typecheck`, `composer quality:filesize`. The pre-commit hook runs fmt + lint on staged files; it does not run the analyzer, so run it yourself.
- **Per-file cap 400 lines; per-class cyclomatic complexity 10; nesting 4; parameters 5.** These caps are why several tiny classes exist below instead of one big one. Do not merge them "for tidiness" — the gate will reject it.
- **Every class gets a docblock that says *why*, not *what*.** Match the density of the surrounding `src/Protocol/` code. A `ponytail:` comment marks a deliberate shortcut and names its ceiling.
- **Timestamps this module publishes go through `ProtocolTimestamp`.** Never `\DATE_ATOM`, never a hand-rolled format string.
- **"Absent, never null"** in any signed or hashed structure: an optional field we do not have is *missing*, not `null`. The one exception is `SignedView`, which is already written.
- **Acts are evidence, never a second input path.** Nothing added here may change a price, a quantity or a quote state.
- **Fail-open on commerce.** No new code path may stop a quote being serviced because A2CN failed.
- **Unit tests run without a shop** (`composer test`); anything needing SwagCommercial goes in `tests/Integration` (`composer test:integration`).
- Namespace root is `MerchantQuoteAgentPlugin\`; tests are `MerchantQuoteAgentPlugin\Tests\Unit\…` / `…\Tests\Integration\…`.
- Commit after every task. Conventional Commits, and end each message with:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`

## File structure

**New — timestamps (#112, #113)**

| File | Responsibility |
| --- | --- |
| `src/Protocol/Check/TimestampFormatCheck.php` | Counterparty act timestamps match the strict Zulu pattern. |
| `src/Protocol/Check/TimestampMonotonicityCheck.php` | The chain's timestamps never go backwards. |

**New — inbound acts (#111)**

| File | Responsibility |
| --- | --- |
| `src/Protocol/Check/ActVerifier.php` | The reason one act does not verify against its own did:web key, or null. Extracted from `BuyerSignatureCheck`. |
| `src/Protocol/Http/A2cnBearerJwt.php` | Verifies the transport's ES256 Bearer JWT and returns its issuer. |
| `src/Protocol/Ingress/InboundActRefusal.php` | One refusal: HTTP status + wire code. |
| `src/Protocol/Ingress/InboundActEnvelope.php` | §14.1 binding: body `session_id` = path, body `sender_did` = JWT `iss`. |
| `src/Protocol/Ingress/InboundActEligibility.php` | May this session take an act at all: quote live, chain below cap, sender is this session's counterparty. |
| `src/Protocol/Ingress/InboundActConformance.php` | Is this act well-formed evidence: timestamp format, no inversion, expected sequence, signature. |
| `src/Protocol/Ingress/InboundActAppender.php` | Orchestrates the three gates, answers a replay, writes the act, mirrors it. |
| `src/Protocol/Ingress/SessionQuoteLocator.php` | Session id → quote id. |
| `src/Protocol/Ingress/A2cnSessionStamp.php` | Writes `a2cn_session` onto a freshly requested quote. |
| `src/Protocol/Http/A2cnMessagesController.php` | The route itself. |

**Modified**

| File | Change |
| --- | --- |
| `src/Protocol/ProtocolTimestamp.php` | Add `PATTERN` and `matches()`. |
| `src/Protocol/Check/BuyerSignatureCheck.php` | Delegate to `ActVerifier`. |
| `src/Protocol/Http/A2cnDiscoveryController.php` | `endpoint` gains the `/a2cn` suffix; add `messages_url`. |
| `src/Protocol/Http/A2cnRecordsController.php` | Two canonical route aliases. |
| `src/Protocol/Http/QuoteTerminalState.php` | `buyerOrganizationName`, `orderNumber`. |
| `src/Protocol/Http/QuoteTerminalStateReader.php` | Fill both. |
| `src/Protocol/Http/RecordPartiesResolver.php` | Use `buyerOrganizationName`. |
| `src/Protocol/Record/TransactionRecord.php` | Optional top-level `order_reference`. |
| `src/Protocol/Http/RecordResponder.php` | Pass the order reference through. |
| `src/Bridge/Data/QuoteIdentity.php` | `companyName`, `orderId`. |
| `src/Bridge/QuoteSnapshotReader.php` | Read both; associate `customer`. |
| `src/Ucp/Quote/QuoteSnapshot.php` | `a2cnSessionId` + `withA2cnSession()`. |
| `src/Ucp/Quote/Controller/UcpQuoteController.php` | Stamp the session on `requestQuote`. |
| `src/Resources/config/services.php` | Register everything new. |
| `src/Resources/config/routes.php` | Import the new controller inside the commercial gate. |

---

### Task 1: The strict timestamp pattern and its check (#113)

**Files:**
- Modify: `src/Protocol/ProtocolTimestamp.php`
- Create: `src/Protocol/Check/TimestampFormatCheck.php`
- Modify: `src/Resources/config/services.php:339` (the check list)
- Test: `tests/Unit/Protocol/Check/TimestampFormatCheckTest.php`

**Interfaces:**
- Consumes: `EvidenceCheckInterface`, `ActChain::buyerActs()`, `ProtocolFixtures`.
- Produces: `ProtocolTimestamp::PATTERN` (string), `ProtocolTimestamp::matches(string): bool`, `TimestampFormatCheck` emitting `violation_type: timestamp_format_invalid`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Check/TimestampFormatCheckTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\TimestampFormatCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class TimestampFormatCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesOnZuluTimestamps(): void
    {
        self::assertNull($this->check([
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $this->session()),
        ]));
    }

    public function testItReportsAnOffsetFormTimestamp(): void
    {
        $act = ProtocolFixtures::buyerAct(1, $this->session());
        $act['timestamp'] = '2026-09-10T09:42:07+00:00';

        $violation = $this->check([ActKey::for(1, ActRole::Buyer) => $act]);

        self::assertNotNull($violation);
        self::assertSame('timestamp_format_invalid', $violation->violationType);
        self::assertSame($act['message_id'], $violation->messageId);
        self::assertStringContainsString('timestamp', $violation->description);
    }

    public function testItReportsAMalformedExpiresAt(): void
    {
        $act = ProtocolFixtures::buyerAct(1, $this->session());
        $act['expires_at'] = '2026-09-11 10:00:00';

        $violation = $this->check([ActKey::for(1, ActRole::Buyer) => $act]);

        self::assertNotNull($violation);
        self::assertStringContainsString('expires_at', $violation->description);
    }

    public function testItIgnoresOurOwnActs(): void
    {
        $seller = ProtocolFixtures::sellerAct(1, $this->session());
        $seller['timestamp'] = '2026-09-10T09:42:07+00:00';

        // Our own acts go through ProtocolTimestamp; a check against them
        // would report our bug as the counterparty's misconduct.
        self::assertNull($this->check([ActKey::for(1, ActRole::Seller) => $seller]));
    }

    private function session(): string
    {
        return SessionId::forQuote(self::QUOTE_ID);
    }

    /** @param array<string, mixed> $acts */
    private function check(array $acts): ?\MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation
    {
        return (new TimestampFormatCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $this->session(), ...$acts]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter TimestampFormatCheckTest`
Expected: FAIL — `Class "…\TimestampFormatCheck" not found`.

- [ ] **Step 3: Add the pattern to `ProtocolTimestamp`**

Insert after the `FORMAT` constant, and make `FORMAT` and `PATTERN` neighbours so a reader sees they are two spellings of one rule:

```php
    /**
     * The same rule as FORMAT, spelled as the counterparty's act schema
     * spells it. They live side by side deliberately: a change to one that
     * is not made to the other is a writer and a reader that disagree about
     * what a timestamp is.
     */
    public const PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/';

    public static function matches(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
```

- [ ] **Step 4: Write the check**

`src/Protocol/Check/TimestampFormatCheck.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * A counterparty act that states a time in a format A2CN does not accept.
 *
 * The counterparty's act schema pins ISO-8601 UTC at second resolution with a
 * literal `Z`, and `\DATE_ATOM` — which is what a PHP or Python agent reaches
 * for by default — writes `+00:00` instead. Both name the same instant, so
 * nothing here is about correctness of the clock: it is about the BYTES. An
 * act whose timestamp is written the other way canonicalizes to a different
 * hash for any party that normalizes before hashing, and is refused outright
 * by a strict schema validator.
 *
 * Registered before TimestampMonotonicityCheck, because comparing two times
 * written in two formats is a comparison of nothing.
 *
 * The act is refused, never repaired. Rewriting `+00:00` to `Z` would change
 * the bytes the counterparty signed, and their own signature would then fail
 * against their own act.
 *
 * Only THEIR acts are checked. Ours go through ProtocolTimestamp and cannot
 * fail this; reporting one as a protocol violation would blame the
 * counterparty for our bug.
 */
final readonly class TimestampFormatCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($chain->buyerActs($sellerDid) as $act) {
            $field = self::malformedField($act);
            if ($field !== null) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'timestamp_format_invalid',
                    messageId: $act->messageId(),
                    description: \sprintf(
                        'act %s states %s as "%s", which is not ISO-8601 UTC at second resolution',
                        $act->messageId(),
                        $field,
                        $field === 'timestamp' ? $act->timestamp() : (string) $act->expiresAt(),
                    ),
                );
            }
        }

        return null;
    }

    private static function malformedField(Act $act): ?string
    {
        if (!ProtocolTimestamp::matches($act->timestamp())) {
            return 'timestamp';
        }

        $expires = $act->expiresAt();

        return $expires !== null && !ProtocolTimestamp::matches($expires) ? 'expires_at' : null;
    }
}
```

- [ ] **Step 5: Register it, first of the two timestamp checks**

In `src/Resources/config/services.php`, add the `use` import and insert into the ordered list so it reads:

```php
    $services->set(SessionIdCheck::class);
    $services->set(DuplicateSequenceCheck::class);
    $services->set(ChainLengthCheck::class);
    $services->set(TimestampFormatCheck::class);
    $services->set(BuyerTermsCheck::class);
    $services->set(BuyerSignatureCheck::class);
    $services->set(EvidenceInspector::class)->args([[
        service(SessionIdCheck::class),
        service(DuplicateSequenceCheck::class),
        service(ChainLengthCheck::class),
        service(TimestampFormatCheck::class),
        service(BuyerTermsCheck::class),
        service(BuyerSignatureCheck::class),
    ]]);
```

- [ ] **Step 6: Run the tests**

Run: `composer test -- --filter 'TimestampFormatCheckTest|ProtocolTimestamp'`
Expected: PASS.

- [ ] **Step 7: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/ProtocolTimestamp.php src/Protocol/Check/TimestampFormatCheck.php src/Resources/config/services.php tests/Unit/Protocol/Check/TimestampFormatCheckTest.php
git commit -m "feat(a2cn): refuse counterparty acts that misspell a timestamp

Closes #113.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Timestamp monotonicity (#112, chain half)

**Files:**
- Create: `src/Protocol/Check/TimestampMonotonicityCheck.php`
- Modify: `src/Resources/config/services.php` (the check list from Task 1)
- Test: `tests/Unit/Protocol/Check/TimestampMonotonicityCheckTest.php`

**Interfaces:**
- Consumes: `EvidenceCheckInterface`, `ActChain::acts()`.
- Produces: `TimestampMonotonicityCheck` emitting `violation_type: timestamp_inversion`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Check/TimestampMonotonicityCheckTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Check\TimestampMonotonicityCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class TimestampMonotonicityCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesOnAnOrderedChain(): void
    {
        self::assertNull($this->check('2026-09-10T09:42:07Z', '2026-09-10T09:45:00Z'));
    }

    public function testItPassesOnEqualTimestamps(): void
    {
        // Two acts within the same second are not a causal problem.
        self::assertNull($this->check('2026-09-10T09:42:07Z', '2026-09-10T09:42:07Z'));
    }

    public function testItReportsTheInversionObservedInProduction(): void
    {
        // Buyer offer at 09:42:07, seller counteroffer 17 seconds EARLIER.
        $violation = $this->check('2026-09-10T09:42:07Z', '2026-09-10T09:41:50Z');

        self::assertNotNull($violation);
        self::assertSame('timestamp_inversion', $violation->violationType);
        self::assertStringContainsString('09:41:50Z', $violation->description);
    }

    public function testItAttributesTheInversionToTheLaterActInTheChain(): void
    {
        $violation = $this->check('2026-09-10T09:42:07Z', '2026-09-10T09:41:50Z');

        self::assertNotNull($violation);
        // Sequence 2 is the act that sits out of order, whoever wrote it.
        self::assertStringContainsString(':2', (string) $violation->messageId);
    }

    private function check(string $first, string $second): ?ProtocolViolation
    {
        $session = SessionId::forQuote(self::QUOTE_ID);

        $buyer = ProtocolFixtures::buyerAct(1, $session);
        $buyer['timestamp'] = $first;
        $seller = ProtocolFixtures::sellerAct(2, $session);
        $seller['timestamp'] = $second;

        return (new TimestampMonotonicityCheck())->check(
            ActChain::read([
                ActKey::SESSION_KEY => $session,
                ActKey::for(1, ActRole::Buyer) => $buyer,
                ActKey::for(2, ActRole::Seller) => $seller,
            ]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter TimestampMonotonicityCheckTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the check**

`src/Protocol/Check/TimestampMonotonicityCheck.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * An act that claims to have happened before the act it follows.
 *
 * The chain's order is its sequence, and a reader takes that order to be
 * causal: act n answers act n-1. An offer timestamped after the counteroffer
 * answering it is not a timeline any auditor accepts, and signing further
 * into it would attest a history we cannot defend. Observed live on
 * 2026-09-10: a seller counteroffer 17 seconds older than the buyer offer it
 * answered, because the buyer had rewritten act 1 in place after we signed.
 *
 * Checked over the WHOLE chain, ours included — the observed inversion was
 * between our act and theirs, and a check that only compared their acts to
 * each other would have reported it clean, exactly as the four original
 * checks did.
 *
 * Compared as instants, not as strings. Zulu strings happen to sort
 * chronologically, but this check must also be right about an act written
 * before TimestampFormatCheck existed, and about one this plugin refuses but
 * still has to read.
 *
 * Zero tolerance for clock skew between the two parties. The inbound act
 * route refuses an inverted act while the buyer can still correct it, so a
 * chain only arrives here inverted if it was written some other way — and a
 * window wide enough to absorb honest skew is wide enough to absorb the
 * inversion this exists to catch.
 * ponytail: no skew window. Add one only against a real counterparty whose
 * clock is provably off and who cannot fix it.
 */
final readonly class TimestampMonotonicityCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $previous = null;
        foreach ($chain->acts() as $act) {
            $instant = strtotime($act->timestamp());
            if ($instant === false) {
                // Unreadable, so not comparable. TimestampFormatCheck owns
                // the complaint about how it is written; this check has
                // nothing to say and must not swallow the act silently by
                // treating it as zero.
                continue;
            }

            if ($previous !== null && $instant < $previous) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'timestamp_inversion',
                    messageId: $act->messageId(),
                    description: \sprintf(
                        'act %s is timestamped %s, before the act it follows',
                        $act->messageId(),
                        $act->timestamp(),
                    ),
                );
            }

            $previous = $instant;
        }

        return null;
    }
}
```

- [ ] **Step 4: Register it directly after `TimestampFormatCheck`**

Both in the `$services->set(...)` list and in the `EvidenceInspector` argument array, immediately after `TimestampFormatCheck::class` and before `BuyerTermsCheck::class`.

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter 'Timestamp|EvidenceInspector|A2cn'`
Expected: PASS.

- [ ] **Step 6: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Check/TimestampMonotonicityCheck.php src/Resources/config/services.php tests/Unit/Protocol/Check/TimestampMonotonicityCheckTest.php
git commit -m "feat(a2cn): refuse a chain whose timestamps go backwards

Closes #112 for chains not written through the inbound route.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Extract `ActVerifier` from `BuyerSignatureCheck`

**Files:**
- Create: `src/Protocol/Check/ActVerifier.php`
- Modify: `src/Protocol/Check/BuyerSignatureCheck.php`
- Modify: `src/Resources/config/services.php` (register `ActVerifier`, inject it)
- Test: `tests/Unit/Protocol/Check/BuyerSignatureCheckTest.php` (must pass unchanged in substance)

**Interfaces:**
- Consumes: `DidWebResolver::publicKeyPemFor(string): ?string`, `ProtocolHash::of(array): string`, `SignedView::of(Act): array`, `CompactJws::verify(string, string): ?string`.
- Produces: `ActVerifier::reasonItDoesNotVerify(Act $act): ?string` — the human-readable reason, or null when the act verifies. Used by `BuyerSignatureCheck` (Task 3) and `InboundActConformance` (Task 8).

This is a **move, not a rewrite.** The four steps and their order are load-bearing and are documented in `BuyerSignatureCheck`'s docblock; carry that documentation to the new class.

- [ ] **Step 1: Add the test that pins the new seam**

Append to `tests/Unit/Protocol/Check/BuyerSignatureCheckTest.php` — read the file first and reuse whatever act/key helpers it already has:

```php
    public function testTheVerifierIsUsableOnASingleActWithoutAChain(): void
    {
        // The inbound route verifies one act before any chain exists, so the
        // verification must not be reachable only through a chain walk.
        $verifier = new \MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier(
            $this->resolver(),
            new \MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash(),
        );

        self::assertNull($verifier->reasonItDoesNotVerify($this->signedBuyerAct()));
    }
```

If the existing test file has no `resolver()` / `signedBuyerAct()` helper under those names, use the names it does have and keep the assertion identical.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter BuyerSignatureCheckTest`
Expected: FAIL — `Class "…\ActVerifier" not found`.

- [ ] **Step 3: Create `ActVerifier` with the moved logic**

`src/Protocol/Check/ActVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;

/**
 * Why one act does not verify against its own did:web key, or null when it
 * does.
 *
 * Split out of BuyerSignatureCheck so the inbound act route can ask the same
 * question about a single act, before there is a chain to walk or a violation
 * to record. The check wraps the answer in a ProtocolViolation; the route
 * turns it into a 403. One implementation, because two would drift and the
 * drift would be a hole.
 *
 * Four steps, all load-bearing:
 *   0. require `sender_verification_method` to be controlled by `sender_did`
 *      (equal to it, or `sender_did . '#...'`) — a free local comparison, run
 *      before the network hop it would otherwise waste. Without it, an act
 *      could claim `sender_did: did:web:buyer.example` while naming a
 *      verification method under a DIFFERENT did:web authority, and steps
 *      1-3 would happily verify the signature against that other party's
 *      key: the act would read as buyer-signed while being signed by
 *      whoever controls the named method.
 *   1. resolve the key the act names,
 *   2. recompute the hash from the act's own signed view and compare — without
 *      this, a signature that is valid over some OTHER object would pass,
 *   3. verify the JWS and require its payload to be exactly that hash.
 */
final readonly class ActVerifier
{
    public function __construct(
        private DidWebResolver $resolver,
        private ProtocolHash $hash,
    ) {}

    public function reasonItDoesNotVerify(Act $act): ?string
    {
        $mismatch = self::verificationMethodMismatch($act);
        if ($mismatch !== null) {
            return $mismatch;
        }

        $expected = $this->hash->of(SignedView::of($act));
        if ($expected !== $act->hash()) {
            return \sprintf('carries a protocol_act_hash that does not cover it (expected %s)', $expected);
        }

        $pem = $this->resolver->publicKeyPemFor($act->verificationMethod());
        if ($pem === null) {
            return \sprintf('names a verification method that does not resolve (%s)', $act->verificationMethod());
        }

        if (CompactJws::verify($act->signature(), $pem) !== $expected) {
            return 'did not verify against its did:web key';
        }

        return null;
    }

    /**
     * A conformant `sender_verification_method` is the sender's own DID, or
     * that DID plus a `#fragment` — never a method under a different DID.
     * Without this, an act naming a foreign verification method would still
     * reach DidWebResolver, which resolves whatever DID a verification method
     * NAMES, not whatever DID the act CLAIMS as its sender.
     */
    private static function verificationMethodMismatch(Act $act): ?string
    {
        $senderDid = $act->senderDid();
        $method = $act->verificationMethod();
        if ($method === $senderDid || str_starts_with($method, $senderDid . '#')) {
            return null;
        }

        return \sprintf('names a verification method (%s) not controlled by its sender_did (%s)', $method, $senderDid);
    }
}
```

- [ ] **Step 4: Reduce `BuyerSignatureCheck` to the wrapper**

Replace its constructor and both private methods; the class docblock keeps its first two paragraphs and points at `ActVerifier` for the four steps:

```php
final readonly class BuyerSignatureCheck implements EvidenceCheckInterface
{
    public function __construct(
        private ActVerifier $verifier,
    ) {}

    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($chain->buyerActs($sellerDid) as $act) {
            $reason = $this->verifier->reasonItDoesNotVerify($act);
            if ($reason !== null) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'buyer_act_unverified',
                    messageId: $act->messageId(),
                    description: \sprintf('act %s %s', $act->messageId(), $reason),
                );
            }
        }

        return null;
    }
}
```

Drop the now-unused `Act`, `SignedView`, `CompactJws`, `ProtocolHash` and `DidWebResolver` imports.

- [ ] **Step 5: Rewire the container**

In `services.php`, register `ActVerifier` with the two arguments `BuyerSignatureCheck` used to take, and give `BuyerSignatureCheck` the verifier:

```php
    $services->set(ActVerifier::class);
    $services->set(BuyerSignatureCheck::class)->args([service(ActVerifier::class)]);
```

Autowiring resolves `ActVerifier`'s `DidWebResolver` and `ProtocolHash` the same way it resolved them for `BuyerSignatureCheck`; if the existing registration passed them explicitly, copy those `service()` references onto `ActVerifier` instead.

- [ ] **Step 6: Run the whole protocol suite**

Run: `composer test -- --filter Protocol`
Expected: PASS, including every pre-existing `BuyerSignatureCheckTest` case unchanged.

- [ ] **Step 7: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Check/ActVerifier.php src/Protocol/Check/BuyerSignatureCheck.php src/Resources/config/services.php tests/Unit/Protocol/Check/BuyerSignatureCheckTest.php
git commit -m "refactor(a2cn): make single-act verification reachable without a chain

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The transport Bearer token

**Files:**
- Create: `src/Protocol/Http/A2cnBearerJwt.php`
- Test: `tests/Unit/Protocol/Http/A2cnBearerJwtTest.php`

**Interfaces:**
- Consumes: `Base64Url::decode()`, `Es256Signature::toDer()`, `DidWebResolver::publicKeyPemFor()`, `TestActSigner::key()` / `::publicKeyPem()` in tests.
- Produces: `A2cnBearerJwt::issuerOf(string $authorizationHeader, string $audience, \DateTimeImmutable $now): ?string` — the verified `iss`, or null.

**Why not `CompactJws`:** it refuses any protected-header member outside `alg` and `kid`, and a real JWT carries `typ`. That strictness is correct for act signatures and is not being relaxed for a transport token. This class is the second, looser reader — looser about `typ` only, never about `alg`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Http/A2cnBearerJwtTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnBearerJwt;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;

final class A2cnBearerJwtTest extends TestCase
{
    private const BUYER = 'did:web:buyer.example';
    private const METHOD = self::BUYER . '#key-1';
    private const SELLER = 'did:web:shop.example';

    public function testItReturnsTheIssuerOfAValidToken(): void
    {
        self::assertSame(self::BUYER, $this->verify($this->token()));
    }

    public function testItRefusesAWrongAudience(): void
    {
        self::assertNull($this->verify($this->token(audience: 'did:web:someone.else')));
    }

    public function testItRefusesAnExpiredToken(): void
    {
        self::assertNull($this->verify($this->token(exp: 1_600_000_000)));
    }

    public function testItRefusesAlgNone(): void
    {
        $header = Base64Url::encode('{"alg":"none","kid":"' . self::METHOD . '"}');
        $claims = Base64Url::encode((string) json_encode(['iss' => self::BUYER, 'aud' => self::SELLER, 'exp' => 4_000_000_000]));

        self::assertNull($this->verify($header . '.' . $claims . '.'));
    }

    public function testItRefusesATokenWithNoIssuer(): void
    {
        self::assertNull($this->verify($this->token(issuer: '')));
    }

    public function testItRefusesAMissingBearerPrefix(): void
    {
        self::assertNull((new A2cnBearerJwt($this->resolver()))->issuerOf(
            $this->token(),
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    public function testItRefusesAnUnresolvableKid(): void
    {
        $resolver = new class extends DidWebResolver {
            public function __construct() {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return null;
            }
        };

        self::assertNull((new A2cnBearerJwt($resolver))->issuerOf(
            'Bearer ' . $this->token(),
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    private function verify(string $token): ?string
    {
        return (new A2cnBearerJwt($this->resolver()))->issuerOf(
            'Bearer ' . $token,
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        );
    }

    private function token(
        string $issuer = self::BUYER,
        string $audience = self::SELLER,
        int $exp = 4_000_000_000,
    ): string {
        $header = Base64Url::encode('{"alg":"ES256","typ":"JWT","kid":"' . self::METHOD . '"}');
        $claims = Base64Url::encode((string) json_encode([
            'iss' => $issuer,
            'aud' => $audience,
            'exp' => $exp,
            'jti' => 'token-1',
        ]));

        $der = '';
        openssl_sign($header . '.' . $claims, $der, TestActSigner::key()->privateKeyPem, \OPENSSL_ALGO_SHA256);

        return $header . '.' . $claims . '.' . Base64Url::encode(Es256Signature::toRaw($der));
    }

    private function resolver(): DidWebResolver
    {
        return new class extends DidWebResolver {
            public function __construct() {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return $verificationMethod === A2cnBearerJwtTest::METHOD ? TestActSigner::publicKeyPem() : null;
            }
        };
    }
}
```

Read `tests/Unit/Protocol/TestActSigner.php` first and use whatever the private-key property is actually called on `A2cnSigningKey`; adjust `TestActSigner::key()->privateKeyPem` to match.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter A2cnBearerJwtTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the verifier**

`src/Protocol/Http/A2cnBearerJwt.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use MerchantQuoteAgentPlugin\Protocol\Crypto\MalformedSignature;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;

/**
 * The A2CN transport token: an ES256 Bearer JWT whose `iss` is the sender's
 * DID and whose header `kid` names the verification method that signed it
 * (spec §12.1.4).
 *
 * Not CompactJws. That class signs and verifies a STRING payload and refuses
 * any protected-header member outside `alg` and `kid` — deliberately, because
 * an act signature that tolerated `crit` or an embedded `jwk` would be
 * interpreting an instruction rather than checking a signature. A real JWT
 * carries `typ`, so it would refuse every conformant token. Rather than
 * loosen the act verifier, this is a second reader, looser about `typ` and
 * about nothing else: `alg` is pinned to ES256, and `none` is refused with
 * everything else.
 *
 * Returns the issuer or null. Null, not an exception: an unauthenticated
 * request is a 401, not an error in our own control flow.
 *
 * ponytail: no (iss, jti) replay store, unlike the reference server. The act
 * append behind this token is idempotent on `message_id`, so a replayed
 * request writes nothing and answers the same 200. Add a store if a token
 * ever authorizes something that is NOT idempotent.
 */
final readonly class A2cnBearerJwt
{
    private const ALG = 'ES256';

    private const PREFIX = 'Bearer ';

    public function __construct(
        private DidWebResolver $resolver,
    ) {}

    public function issuerOf(string $authorizationHeader, string $audience, \DateTimeImmutable $now): ?string
    {
        if (!str_starts_with($authorizationHeader, self::PREFIX)) {
            return null;
        }

        $segments = explode('.', substr($authorizationHeader, \strlen(self::PREFIX)));
        if (\count($segments) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $segments;
        $kid = self::kidOf(Base64Url::decode($header));
        $claims = self::claimsOf(Base64Url::decode($payload), $audience, $now);
        if ($kid === null || $claims === null) {
            return null;
        }

        $pem = $this->resolver->publicKeyPemFor($kid);

        return $pem !== null && self::signatureIsGood($header . '.' . $payload, $signature, $pem) ? $claims : null;
    }

    /** The `kid` of an ES256 header, or null for anything else — `none` included. */
    private static function kidOf(string $decoded): ?string
    {
        $header = json_decode($decoded, associative: true);
        if (!\is_array($header) || ($header['alg'] ?? null) !== self::ALG) {
            return null;
        }

        $kid = $header['kid'] ?? null;

        return \is_string($kid) && $kid !== '' ? $kid : null;
    }

    /** The `iss` of a live token addressed to us, or null. */
    private static function claimsOf(string $decoded, string $audience, \DateTimeImmutable $now): ?string
    {
        $claims = json_decode($decoded, associative: true);
        if (!\is_array($claims)) {
            return null;
        }

        $issuer = $claims['iss'] ?? null;
        $expires = $claims['exp'] ?? null;
        if (!\is_string($issuer) || $issuer === '' || ($claims['aud'] ?? null) !== $audience) {
            return null;
        }

        // An expiry is REQUIRED, not optional: a token with none never stops
        // being usable by whoever picks it out of a log.
        return \is_int($expires) && $expires > $now->getTimestamp() ? $issuer : null;
    }

    private static function signatureIsGood(string $signingInput, string $signature, string $pem): bool
    {
        try {
            $der = Es256Signature::toDer(Base64Url::decode($signature));
        } catch (MalformedSignature) {
            return false;
        }

        return openssl_verify($signingInput, $der, $pem, \OPENSSL_ALGO_SHA256) === 1;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `composer test -- --filter A2cnBearerJwtTest`
Expected: PASS, all seven.

- [ ] **Step 5: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Http/A2cnBearerJwt.php tests/Unit/Protocol/Http/A2cnBearerJwtTest.php
git commit -m "feat(a2cn): verify the transport's ES256 bearer token

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Stamp the session on a new quote, and tell the buyer

**Files:**
- Modify: `src/Ucp/Quote/QuoteSnapshot.php`
- Create: `src/Protocol/Ingress/A2cnSessionStamp.php`
- Modify: `src/Ucp/Quote/Controller/UcpQuoteController.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Ingress/A2cnSessionStampTest.php`
- Test: `tests/Unit/Ucp/Quote/QuoteSnapshotTest.php` (extend if it exists, create if not)

**Interfaces:**
- Consumes: `SessionId::forQuote()`, `QuoteGatewayInterface::updateQuote()`, `QuoteUpdate`, `ActKey::SESSION_KEY`, `Ucp\Quote\QuoteSnapshot`.
- Produces: `Ucp\Quote\QuoteSnapshot::withA2cnSession(string $sessionId): self` and the `a2cn_session_id` key in `toArray()`; `A2cnSessionStamp::stamp(QuoteSnapshot $snapshot): QuoteSnapshot`.

**Why this exists:** `SessionId::forQuote()` is a one-way UUIDv5, so the first inbound act names a quote we cannot look up. Stamping the derived id onto the quote at creation is what makes the reverse lookup in Task 6 possible.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Protocol/Ingress/A2cnSessionStampTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Ingress\A2cnSessionStamp;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class A2cnSessionStampTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItWritesTheDerivedSessionAndReturnsItOnTheSnapshot(): void
    {
        $gateway = new RecordingQuoteGateway();

        $stamped = (new A2cnSessionStamp(new NullLogger(), $gateway))->stamp($this->snapshot());

        self::assertCount(1, $gateway->updates);
        self::assertSame(
            [ActKey::SESSION_KEY => SessionId::forQuote(self::QUOTE_ID)],
            $gateway->updates[0]->customFields,
        );
        self::assertSame(SessionId::forQuote(self::QUOTE_ID), $stamped->toArray()['a2cn_session_id']);
    }

    public function testItLeavesTheSnapshotAloneWhenTheWriteFails(): void
    {
        // An id we advertise but cannot resolve back to a quote is a lie the
        // buyer would act on, so a failed stamp advertises nothing.
        $stamped = (new A2cnSessionStamp(new NullLogger(), new RecordingQuoteGateway(failOnUpdate: true)))
            ->stamp($this->snapshot());

        self::assertArrayNotHasKey('a2cn_session_id', $stamped->toArray());
    }

    public function testItIsInertWithoutAGateway(): void
    {
        $stamped = (new A2cnSessionStamp(new NullLogger()))->stamp($this->snapshot());

        self::assertArrayNotHasKey('a2cn_session_id', $stamped->toArray());
    }

    private function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(id: self::QUOTE_ID, quoteNumber: 'Q-1001', state: 'requested');
    }
}
```

Read `src/Ucp/Quote/QuoteSnapshot.php` first and build the fixture with whatever its constructor actually requires — the three named arguments above are illustrative, the real signature wins.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter A2cnSessionStampTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Add `withA2cnSession()` to the UCP snapshot**

Mirror the existing `withOrder()` exactly: a new nullable `?string $a2cnSessionId = null` constructor property, a `withA2cnSession(string $sessionId): self` that rebuilds the object with every other field carried over, and in `toArray()`:

```php
        if (null !== $this->a2cnSessionId) {
            $payload['a2cn_session_id'] = $this->a2cnSessionId;
        }
```

Absent when unknown, following the same rule as `order`.

- [ ] **Step 4: Write the stamp**

`src/Protocol/Ingress/A2cnSessionStamp.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Writes the quote's derived A2CN session id onto the quote, at the moment
 * the quote is created.
 *
 * SessionId::forQuote() is a one-way UUIDv5, so a session id on an inbound
 * act names a quote nobody can look up. This stamp is the index: it puts the
 * id where SessionQuoteLocator can find it, and returns it to the buyer so
 * they need not reimplement the derivation.
 *
 * It does NOT open a session. SellerActEmitter gates on the chain being empty
 * of ACTS, not on the session key, so a stamped quote with no acts is still
 * inert and we still never emit first.
 *
 * Fail-open in both directions: a shop with no gateway (unlicensed) or a
 * write that throws leaves the snapshot untouched and the quote request
 * successful. Advertising an id we failed to persist would be worse than
 * advertising none — the buyer would sign an act for a session that cannot be
 * resolved.
 *
 * `$gateway` is nullable, defaulted and last, matching SellerActEmitter and
 * QuoteTerminalStateReader: QuoteGatewayFactory::create() returns null on a
 * shop where SwagCommercial's classes exist but the licence does not.
 */
final readonly class A2cnSessionStamp
{
    public function __construct(
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function stamp(QuoteSnapshot $snapshot): QuoteSnapshot
    {
        if ($this->gateway === null) {
            return $snapshot;
        }

        $sessionId = SessionId::forQuote($snapshot->id);

        try {
            $this->gateway->updateQuote(
                $snapshot->id,
                new QuoteUpdate(customFields: [ActKey::SESSION_KEY => $sessionId]),
            );
        } catch (\Throwable $error) {
            $this->logger->warning('A2CN could not stamp a session id onto a new quote.', [
                'quoteId' => $snapshot->id,
                'exception' => $error,
            ]);

            return $snapshot;
        }

        return $snapshot->withA2cnSession($sessionId);
    }
}
```

- [ ] **Step 5: Call it from the controller**

In `UcpQuoteController`, add `private readonly A2cnSessionStamp $sessions` to the constructor and wrap the one call:

```php
        $snapshot = $this->sessions->stamp($this->quoteCapability->requestQuote(
            $this->customerContext($request, $context),
            $this->requestValidator->lineItems($payload, true),
            $this->requestValidator->comment($payload),
        ));
```

`requestQuote` only. `getQuote` and `counterQuote` are untouched — a buyer that lost the id recomputes it, and re-reading `customFields` on every quote read to echo a value the buyer can derive is not worth the read.

- [ ] **Step 6: Register it**

Inside the `CommercialAvailability` block in `services.php`, beside the other gateway consumers:

```php
    $services->set(A2cnSessionStamp::class)->args([
        service('logger'),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
    ]);
```

and add `service(A2cnSessionStamp::class)` as the last argument of the existing `UcpQuoteController` registration (add the argument list if the controller is currently autowired).

- [ ] **Step 7: Run the tests**

Run: `composer test -- --filter 'A2cnSessionStamp|QuoteSnapshot|UcpQuote'`
Expected: PASS.

- [ ] **Step 8: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Ucp/Quote/QuoteSnapshot.php src/Ucp/Quote/Controller/UcpQuoteController.php src/Protocol/Ingress/A2cnSessionStamp.php src/Resources/config/services.php tests/Unit
git commit -m "feat(a2cn): stamp the derived session id onto a new quote

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Session id → quote id

**Files:**
- Create: `src/Protocol/Ingress/SessionQuoteLocator.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Ingress/SessionQuoteLocatorTest.php`

**Interfaces:**
- Consumes: `ActStoreInterface::quoteIdForSession(string): ?string`, Shopware `EntityRepository` + `Criteria` + `EqualsFilter`, `ActKey::SESSION_KEY`.
- Produces: `SessionQuoteLocator::quoteIdFor(string $sessionId): ?string`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Ingress/SessionQuoteLocatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use PHPUnit\Framework\TestCase;

final class SessionQuoteLocatorTest extends TestCase
{
    public function testItPrefersTheMirrorWhichIsIndexed(): void
    {
        $store = new InMemoryActStore();
        // Seed one act so the mirror knows the session; see InMemoryActStore
        // for the exact seeding helper it exposes.
        $store->seedSession('session-1', 'quote-1');

        $locator = new SessionQuoteLocator($store, null);

        self::assertSame('quote-1', $locator->quoteIdFor('session-1'));
    }

    public function testItReturnsNullWhenNothingKnowsTheSession(): void
    {
        self::assertNull((new SessionQuoteLocator(new InMemoryActStore(), null))->quoteIdFor('session-unknown'));
    }
}
```

Read `tests/Unit/Protocol/InMemoryActStore.php` and use its real seeding API; add a `seedSession()` helper to it if it has none.

The DAL fallback is **not** unit-tested — a repository double would only assert that we call Shopware the way we think we do, which is the very thing in doubt. It gets an integration test in Task 11.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter SessionQuoteLocatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the locator**

`src/Protocol/Ingress/SessionQuoteLocator.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Which quote a session id belongs to.
 *
 * SessionId::forQuote() is a UUIDv5 — one way, by design, because a derived
 * id is what makes emission idempotent and what makes the id unguessable to
 * anyone who does not already hold the quote. The price is that the mapping
 * has to be stored somewhere, twice over:
 *
 *   1. The mirror already knows it, from the moment the session has one act.
 *      Indexed, and the answer for every act after the first.
 *   2. A2cnSessionStamp wrote it into the quote's own `customFields` when the
 *      quote was created. This is the answer for the FIRST act, when the
 *      mirror has nothing.
 *
 * The custom-field read is a DAL filter on a JSON path, which Shopware
 * supports for custom fields and which an integration test pins — it is the
 * one assumption in this design the platform could disappoint.
 * ponytail: if that filter ever proves unreliable, replace step 2 with a
 * two-column session→quote table. This class is the only one that changes.
 *
 * `$quotes` is nullable and last, like every other gateway-shaped dependency
 * in this module: the repository is resolved by string id and is absent on a
 * shop without SwagCommercial.
 */
final readonly class SessionQuoteLocator
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection>|null $quotes */
    public function __construct(
        private ActStoreInterface $store,
        private ?EntityRepository $quotes = null,
    ) {}

    public function quoteIdFor(string $sessionId): ?string
    {
        $mirrored = $this->store->quoteIdForSession($sessionId);
        if ($mirrored !== null) {
            return $mirrored;
        }

        if ($this->quotes === null) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.' . ActKey::SESSION_KEY, $sessionId));
        $criteria->setLimit(1);

        $id = $this->quotes->searchIds($criteria, Context::createDefaultContext())->firstId();

        return \is_string($id) ? $id : null;
    }
}
```

- [ ] **Step 4: Register it**

Inside the `CommercialAvailability` block:

```php
    $services->set(SessionQuoteLocator::class)->args([
        service(ActStoreInterface::class),
        service('quote.repository')->ignoreOnInvalid(),
    ]);
```

`quote.repository` is resolved by string id everywhere else in this plugin (see `QuoteSnapshotReader`'s registration) — no SwagCommercial class is named.

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter SessionQuoteLocatorTest`
Expected: PASS.

- [ ] **Step 6: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Ingress/SessionQuoteLocator.php src/Resources/config/services.php tests/Unit/Protocol/Ingress/SessionQuoteLocatorTest.php tests/Unit/Protocol/InMemoryActStore.php
git commit -m "feat(a2cn): resolve a session id back to its quote

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: The refusal gates

**Files:**
- Create: `src/Protocol/Ingress/InboundActRefusal.php`
- Create: `src/Protocol/Ingress/InboundActEnvelope.php`
- Create: `src/Protocol/Ingress/InboundActEligibility.php`
- Create: `src/Protocol/Ingress/InboundActConformance.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Ingress/InboundActGatesTest.php`

**Interfaces:**
- Consumes: `Act`, `ActChain`, `QuoteTerminalState`, `SessionOutcome::for(string, bool): ?…`, `ProtocolTimestamp::matches()`, `ActVerifier::reasonItDoesNotVerify()`.
- Produces:
  - `InboundActRefusal` — `public int $status`, `public string $code`; `->toArray(): array{status: string}`.
  - `InboundActEnvelope::refusal(Act $act, string $sessionId, string $issuerDid): ?InboundActRefusal`
  - `InboundActEligibility::refusal(Act $act, ActChain $chain, QuoteTerminalState $quote, string $sellerDid): ?InboundActRefusal`
  - `InboundActConformance::refusal(Act $act, ActChain $chain): ?InboundActRefusal` (instance method — it holds `ActVerifier`)

**Why four classes:** the per-class cyclomatic-complexity gate is 10 and these rules total twelve branches. The split is by question — is the envelope bound to this request, may this session take an act at all, is this act well-formed evidence — which is the same seam `RecordResponder` / `RecordPartiesResolver` already uses.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Ingress/InboundActGatesTest.php` — one case per refusal plus the clean path:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActEligibility;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActEnvelope;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class InboundActGatesTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testTheEnvelopeAcceptsABoundAct(): void
    {
        self::assertNull(InboundActEnvelope::refusal(
            $this->act(1),
            $this->session(),
            ProtocolFixtures::BUYER,
        ));
    }

    public function testTheEnvelopeRefusesASessionIdThatIsNotThePath(): void
    {
        $refusal = InboundActEnvelope::refusal($this->act(1), 'some-other-session', ProtocolFixtures::BUYER);

        self::assertNotNull($refusal);
        self::assertSame(400, $refusal->status);
        self::assertSame('session_id_mismatch', $refusal->code);
    }

    public function testTheEnvelopeRefusesASenderThatIsNotTheTokenIssuer(): void
    {
        $refusal = InboundActEnvelope::refusal($this->act(1), $this->session(), 'did:web:someone.else');

        self::assertNotNull($refusal);
        self::assertSame(401, $refusal->status);
        self::assertSame('sender_did_mismatch', $refusal->code);
    }

    public function testEligibilityAcceptsAFirstActOnALiveQuote(): void
    {
        self::assertNull(InboundActEligibility::refusal(
            $this->act(1),
            ActChain::read([ActKey::SESSION_KEY => $this->session()]),
            $this->quote(),
            ProtocolFixtures::SELLER,
        ));
    }

    public function testEligibilityRefusesAClosedSession(): void
    {
        $refusal = InboundActEligibility::refusal(
            $this->act(1),
            ActChain::read([ActKey::SESSION_KEY => $this->session()]),
            $this->quote(state: 'accepted'),
            ProtocolFixtures::SELLER,
        );

        self::assertNotNull($refusal);
        self::assertSame(409, $refusal->status);
        self::assertSame('session_closed', $refusal->code);
    }

    public function testEligibilityRefusesOurOwnSellerDid(): void
    {
        $act = Act::fromArray(ProtocolFixtures::sellerAct(1, $this->session()));
        self::assertNotNull($act);

        $refusal = InboundActEligibility::refusal(
            $act,
            ActChain::read([ActKey::SESSION_KEY => $this->session()]),
            $this->quote(),
            ProtocolFixtures::SELLER,
        );

        self::assertNotNull($refusal);
        self::assertSame(403, $refusal->status);
        self::assertSame('sender_did_not_party', $refusal->code);
    }

    public function testEligibilityRefusesAThirdPartyOnceTheChainHasPinnedTheBuyer(): void
    {
        $stranger = ProtocolFixtures::act(2, $this->session(), 'did:web:stranger.example', 'counteroffer');
        $act = Act::fromArray($stranger);
        self::assertNotNull($act);

        $refusal = InboundActEligibility::refusal(
            $act,
            ActChain::read([
                ActKey::SESSION_KEY => $this->session(),
                ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $this->session()),
            ]),
            $this->quote(),
            ProtocolFixtures::SELLER,
        );

        self::assertNotNull($refusal);
        self::assertSame('sender_did_not_party', $refusal->code);
    }

    private function session(): string
    {
        return SessionId::forQuote(self::QUOTE_ID);
    }

    private function act(int $sequence): Act
    {
        $act = Act::fromArray(ProtocolFixtures::buyerAct($sequence, $this->session()));
        self::assertNotNull($act);

        return $act;
    }

    private function quote(string $state = 'replied'): QuoteTerminalState
    {
        return new QuoteTerminalState(
            state: $state,
            expired: false,
            quoteNumber: 'Q-1001',
            salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
            acceptance: null,
        );
    }
}
```

Add conformance cases in the same file once Task 7 Step 5 lands — timestamp format, inversion, sequence conflict, unverifiable signature — each asserting the `status`/`code` pair from the table below.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter InboundActGatesTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the refusal value object**

`src/Protocol/Ingress/InboundActRefusal.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

/**
 * One reason an inbound act was not accepted, as an HTTP status and the wire
 * code the buyer reads.
 *
 * Deliberately NOT a ProtocolViolation. A violation is evidence about a chain
 * we hold; this is an answer about a request we refused. Nothing here is
 * persisted: anyone who holds a session id can produce these at will, and a
 * store of them would be an audit log a stranger can write to.
 */
final readonly class InboundActRefusal
{
    public function __construct(
        public int $status,
        public string $code,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['status' => $this->code];
    }
}
```

- [ ] **Step 4: Write the envelope and eligibility gates**

`src/Protocol/Ingress/InboundActEnvelope.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * A2CN §14.1: the act's own envelope must agree with the request that carried
 * it.
 *
 * Both bindings close the same hole from opposite sides. Without the session
 * binding, an act signed for one session could be replayed into another; the
 * signature would still verify, because `session_id` is inside the signed
 * view and the buyer really did sign it — for a different negotiation.
 * Without the sender binding, anyone holding a valid token of their own could
 * post someone else's act and have it recorded as delivered by them.
 */
final readonly class InboundActEnvelope
{
    private function __construct() {}

    public static function refusal(Act $act, string $sessionId, string $issuerDid): ?InboundActRefusal
    {
        if ($act->sessionId() !== $sessionId) {
            return new InboundActRefusal(400, 'session_id_mismatch');
        }

        return $act->senderDid() === $issuerDid ? null : new InboundActRefusal(401, 'sender_did_mismatch');
    }
}
```

`src/Protocol/Ingress/InboundActEligibility.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Record\SessionOutcome;

/**
 * May this session take an act from this sender at all — asked before the act
 * itself is examined, because none of these questions is about the act.
 *
 * The party rule is the whole authorization story for this route, so it is
 * worth stating plainly: the FIRST act on a chain pins the counterparty's
 * DID, and every act after it must carry the same one. A2CN authenticates the
 * writer as an agent (a DID-signed bearer token), never as the Shopware
 * customer who owns the quote — one Authorization header, one credential, and
 * demanding ours would mean no off-the-shelf A2CN buyer could reach us.
 *
 * What that leaves is bounded on purpose: someone holding a session id could
 * open a chain on a quote that has none. They cannot move a price — the
 * engine reads the Shopware snapshot, never an act — they cannot displace a
 * pinned buyer, and what they write is checked by every EvidenceCheck before
 * we sign anything into it.
 * ponytail: DID pinned by first act. Upgrade to "the DID must already be on
 * the quote" if the buyer's DID ever arrives on the quote-request payload.
 */
final readonly class InboundActEligibility
{
    private function __construct() {}

    public static function refusal(
        Act $act,
        ActChain $chain,
        QuoteTerminalState $quote,
        string $sellerDid,
    ): ?InboundActRefusal {
        // A quote that reached an outcome is a negotiation that is over. Its
        // record is already derivable, and an act appended after it would
        // change a record a counterparty may already hold.
        if ($quote->acceptance !== null || SessionOutcome::for($quote->state, $quote->expired) !== null) {
            return new InboundActRefusal(409, 'session_closed');
        }

        if ($chain->exceedsLengthCap()) {
            return new InboundActRefusal(409, 'chain_length_exceeded');
        }

        $pinned = $chain->firstForeignAct($sellerDid)?->senderDid();
        $sender = $act->senderDid();

        // Never our own DID: an act we did not sign, claiming to be from us,
        // is a forgery whatever else is true of it.
        if ($sender === $sellerDid || ($pinned !== null && $sender !== $pinned)) {
            return new InboundActRefusal(403, 'sender_did_not_party');
        }

        return null;
    }
}
```

- [ ] **Step 5: Write the conformance gate**

`src/Protocol/Ingress/InboundActConformance.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;

/**
 * Is this act evidence we can stand behind once it is on the chain.
 *
 * Every rule here has a matching EvidenceCheck that would catch the same
 * problem later. The point of catching it HERE is that later is too late: a
 * chain refused by EvidenceInspector never gets another seller act, for the
 * life of that quote, and the buyer has no way to withdraw what they wrote.
 * A 4xx at the door is the same judgement delivered while it can still be
 * acted on.
 *
 * Strictly append-only at `nextSequence()`. Not "any unused sequence": a gap
 * would leave the chain claiming a round that never happened, and a lower
 * number would rewrite history that another party may already have hashed.
 */
final readonly class InboundActConformance
{
    public function __construct(
        private ActVerifier $verifier,
    ) {}

    public function refusal(Act $act, ActChain $chain): ?InboundActRefusal
    {
        $expires = $act->expiresAt();
        if (!ProtocolTimestamp::matches($act->timestamp())
            || ($expires !== null && !ProtocolTimestamp::matches($expires))
        ) {
            return new InboundActRefusal(400, 'timestamp_format_invalid');
        }

        if (self::isInverted($act, $chain)) {
            return new InboundActRefusal(409, 'timestamp_inversion');
        }

        if ($act->sequenceNumber() !== $chain->nextSequence()) {
            return new InboundActRefusal(409, 'sequence_conflict');
        }

        return $this->verifier->reasonItDoesNotVerify($act) === null
            ? null
            : new InboundActRefusal(403, 'act_unverified');
    }

    private static function isInverted(Act $act, ActChain $chain): bool
    {
        $previous = $chain->last();
        if ($previous === null) {
            return false;
        }

        $before = strtotime($previous->timestamp());
        $now = strtotime($act->timestamp());

        // An unreadable predecessor is not this act's fault; the format rule
        // above already guarantees THIS act is readable.
        return $before !== false && $now !== false && $now < $before;
    }
}
```

- [ ] **Step 6: Register the one gate that has a dependency**

```php
    $services->set(InboundActConformance::class)->args([service(ActVerifier::class)]);
```

The other three are static or plain value objects and need no registration.

- [ ] **Step 7: Extend the test with the conformance cases and run**

Add to `InboundActGatesTest`, using `new InboundActConformance($verifier)` with an `ActVerifier` double that returns null (or a reason, for the last case):

| Case | Expect |
| --- | --- |
| act with `+00:00` timestamp | `400 timestamp_format_invalid` |
| act timestamped before `chain->last()` | `409 timestamp_inversion` |
| act at sequence 5 on a chain of 1 | `409 sequence_conflict` |
| verifier returns a reason | `403 act_unverified` |
| everything good | `null` |

Run: `composer test -- --filter InboundActGatesTest`
Expected: PASS, all cases.

- [ ] **Step 8: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Ingress src/Resources/config/services.php tests/Unit/Protocol/Ingress/InboundActGatesTest.php
git commit -m "feat(a2cn): the refusal rules for an inbound act

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: The appender

**Files:**
- Create: `src/Protocol/Ingress/InboundActAppender.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Ingress/InboundActAppenderTest.php`

**Interfaces:**
- Consumes: the three gates from Task 7, `ChainMirror::mirrorOne()`, `QuoteGatewayInterface::updateQuote()`, `ActKey::for()`, `ActRole::Buyer`, `QuoteUpdate`.
- Produces:
  - `InboundActRequest` — readonly DTO, seven public promoted properties: `Act $act`, `ActChain $chain`, `string $quoteId`, `string $sessionId`, `QuoteTerminalState $quote`, `string $sellerDid`, `string $issuerDid`. It exists because the append needs all seven and the per-method parameter cap is five; a parameter object is the repo's answer to that, not a signature that busts the gate.
  - `InboundActAppender::append(InboundActRequest $request): InboundActRefusal|Act` — the refusal, or the act as accepted. Returning the act rather than a bool is what lets the controller echo `message_id` and `sequence_number` for a fresh append and a replay alike, and lets it tell the two apart by identity.

Also create: `src/Protocol/Ingress/InboundActRequest.php`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Ingress/InboundActAppenderTest.php`, covering:

```php
    public function testItWritesTheActUnderTheBuyerKeyAtTheNextSequence(): void
    {
        $gateway = new RecordingQuoteGateway();
        $appender = $this->appender($gateway);

        $result = $appender->append($this->request(sequence: 2, chainActs: [1]));

        self::assertInstanceOf(Act::class, $result);
        self::assertCount(1, $gateway->updates);
        self::assertArrayHasKey(ActKey::for(2, ActRole::Buyer), (array) $gateway->updates[0]->customFields);
        self::assertSame(
            SessionId::forQuote(self::QUOTE_ID),
            ((array) $gateway->updates[0]->customFields)[ActKey::SESSION_KEY],
        );
    }

    public function testAReplayedActWritesNothingAndIsStillAccepted(): void
    {
        $gateway = new RecordingQuoteGateway();
        // The act is already on the chain under the same message_id.
        $result = $this->appender($gateway)->append($this->request(sequence: 1, chainActs: [1]));

        self::assertInstanceOf(Act::class, $result);
        self::assertSame([], $gateway->updates);
    }

    public function testARefusalFromAnyGateStopsTheWrite(): void
    {
        $gateway = new RecordingQuoteGateway();
        $result = $this->appender($gateway)->append($this->request(sequence: 2, chainActs: [1], issuerDid: 'did:web:someone.else'));

        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame('sender_did_mismatch', $result->code);
        self::assertSame([], $gateway->updates);
    }

    public function testItMirrorsWhatItWrote(): void
    {
        $store = new InMemoryActStore();
        $this->appender(new RecordingQuoteGateway(), $store)->append($this->request(sequence: 2, chainActs: [1]));

        self::assertCount(1, $store->listBySession(SessionId::forQuote(self::QUOTE_ID)));
    }

    public function testTheWriteHappensBeforeTheMirror(): void
    {
        // A mirror row for an act the buyer's chain never received would be
        // our own copy claiming evidence the wire does not carry.
        $store = new InMemoryActStore();
        $result = $this->appender(new RecordingQuoteGateway(failOnUpdate: true), $store)
            ->append($this->request(sequence: 2, chainActs: [1]));

        self::assertInstanceOf(InboundActRefusal::class, $result);
        self::assertSame(502, $result->status);
        self::assertSame([], $store->listBySession(SessionId::forQuote(self::QUOTE_ID)));
    }
```

Write the `appender()` and `request()` helpers to build the collaborators; use an `ActVerifier` double that always returns null so the test is about the appender, not about signatures.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter InboundActAppenderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `InboundActRequest`**

A plain readonly DTO, one docblock line per field, no logic.

- [ ] **Step 4: Write the appender**

`src/Protocol/Ingress/InboundActAppender.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use Psr\Log\LoggerInterface;

/**
 * Runs the three gates, then writes.
 *
 * Order is the whole design: gates first, then the WIRE, then our mirror.
 * ChainMirror is our independent copy of what the chain carries, so a mirror
 * row for an act the quote never received would be our own evidence claiming
 * something the counterparty can disprove. The wire write is therefore what
 * makes an act real; the mirror follows it and never precedes it.
 *
 * A replayed act — same `message_id`, already on the chain — is accepted
 * without a write. That is what makes the missing JWT replay store safe, and
 * it is what lets a buyer retry a request whose response they never saw.
 *
 * Concurrency is the CALLER's job, exactly as it is for SellerActEmitter: the
 * controller holds the per-quote servicing lock, because two overlapping
 * appends would both read the same `nextSequence()` and the second would
 * overwrite the first — the role suffix in the act key protects buyer from
 * seller, not buyer from buyer.
 */
final readonly class InboundActAppender
{
    public function __construct(
        private InboundActConformance $conformance,
        private ChainMirror $mirror,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function append(InboundActRequest $request): InboundActRefusal|Act
    {
        $refusal = InboundActEnvelope::refusal($request->act, $request->sessionId, $request->issuerDid)
            ?? InboundActEligibility::refusal($request->act, $request->chain, $request->quote, $request->sellerDid);
        if ($refusal !== null) {
            return $refusal;
        }

        $existing = self::alreadyOnChain($request);
        if ($existing !== null) {
            return $existing;
        }

        $refusal = $this->conformance->refusal($request->act, $request->chain);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->gateway === null) {
            return new InboundActRefusal(503, 'quote_backend_unavailable');
        }

        return $this->write($request);
    }

    private function write(InboundActRequest $request): InboundActRefusal|Act
    {
        $key = ActKey::for($request->act->sequenceNumber(), ActRole::Buyer);

        try {
            $this->gateway?->updateQuote($request->quoteId, new QuoteUpdate(customFields: [
                ActKey::SESSION_KEY => $request->sessionId,
                $key => $request->act->raw(),
            ]));
        } catch (\Throwable $error) {
            $this->logger->error('A2CN could not write an inbound act to the quote.', [
                'quoteId' => $request->quoteId,
                'exception' => $error,
            ]);

            return new InboundActRefusal(502, 'act_not_stored');
        }

        $this->mirror->mirrorOne($request->quoteId, $request->act, ActRole::Buyer);

        return $request->act;
    }

    /**
     * The same act, already on the chain. Matched on `message_id`, which the
     * counterparty owns and does not reuse — not on the sequence, because a
     * replay carries the sequence it was first accepted at and would
     * otherwise read as a conflict.
     */
    private static function alreadyOnChain(InboundActRequest $request): ?Act
    {
        foreach ($request->chain->acts() as $act) {
            if ($act->messageId() === $request->act->messageId()) {
                return $act;
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Register it inside the commercial gate**

```php
    $services->set(InboundActAppender::class)->args([
        service(InboundActConformance::class),
        service(ChainMirror::class),
        service('logger'),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
    ]);
```

- [ ] **Step 6: Run the tests**

Run: `composer test -- --filter InboundActAppenderTest`
Expected: PASS.

- [ ] **Step 7: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Ingress src/Resources/config/services.php tests/Unit/Protocol/Ingress/InboundActAppenderTest.php
git commit -m "feat(a2cn): append a verified inbound act to the quote's chain

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: The route

**Files:**
- Create: `src/Protocol/Http/A2cnMessagesController.php`
- Modify: `src/Protocol/Http/QuoteTerminalStateReader.php` (add `customFieldsFor()`)
- Modify: `src/Resources/config/routes.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Http/A2cnMessagesControllerTest.php`

**Interfaces:**
- Consumes: `A2cnBearerJwt::issuerOf()`, `SessionQuoteLocator::quoteIdFor()`, `QuoteTerminalStateReader::for()`, `A2cnIdentityResolver::forSalesChannel()`, `QuoteServicingLock::for()`, `InboundActAppender::append()`, `InboundActRequest`, `Act::fromArray()`, `JsonEnvelope::noStore()`.
- Produces: `POST /a2cn/sessions/{sessionId}/messages`, route name `frontend.merchant_quote_agent.a2cn.messages.post`; `QuoteTerminalStateReader::customFieldsFor(string $quoteId): ?array` (throws `QuoteStateUnavailable`, same posture as `for()`).

The response body on success, with `Content-Type: application/a2cn+json` and `Cache-Control: no-store`:

```json
{"session_id": "…", "accepted": {"message_id": "…", "sequence_number": 3}}
```

`201` for a fresh append, `200` for a replay. Every refusal answers `{"status": "<code>"}` at the refusal's status.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Protocol/Http/A2cnMessagesControllerTest.php`. Build the controller with doubles for every collaborator. The happy path, in full, to fix the harness shape:

```php
    public function testItAcceptsAVerifiedActAndEchoesWhatItStored(): void
    {
        $act = ProtocolFixtures::buyerAct(1, $this->session());

        $response = $this->controller()->messages($this->session(), Request::create(
            '/a2cn/sessions/' . $this->session() . '/messages',
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
            content: (string) json_encode($act),
        ));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/a2cn+json', $response->headers->get('Content-Type'));
        self::assertSame('no-store', $response->headers->get('Cache-Control'));

        $body = json_decode((string) $response->getContent(), associative: true);
        self::assertIsArray($body);
        self::assertSame($this->session(), $body['session_id']);
        self::assertSame($act['message_id'], $body['accepted']['message_id']);
        self::assertSame(1, $body['accepted']['sequence_number']);
    }
```

`controller()` builds the six collaborators as anonymous subclasses: an `A2cnBearerJwt` returning `ProtocolFixtures::BUYER` for `self::TOKEN`, a `SessionQuoteLocator` returning the quote id, a `QuoteTerminalStateReader` returning a live `QuoteTerminalState` and empty `customFields`, an `A2cnIdentityResolver` returning `ProtocolFixtures::SELLER` (`TestActSigner::identities()` already does this), a `QuoteServicingLock` over Symfony's `LockFactory` with an `InMemoryStore`, and a real `InboundActAppender` over `RecordingQuoteGateway` plus an `ActVerifier` double that returns null.

Then, one test per row, each asserting the status and the `status` key of the body:

| Case | Expect |
| --- | --- |
| no `Authorization` header | 401 `invalid_jwt` |
| token verifies, body is not JSON | 400 `invalid_act` |
| body is JSON but `Act::fromArray()` returns null | 400 `invalid_act` |
| session resolves to no quote | 404 `not_found` |
| `QuoteStateUnavailable` from the reader | 502 `quote_state_unavailable` |
| `forSalesChannel()` returns null | 503 `signing_key_missing` |
| the lock is already held | 409 `session_busy` |
| the appender returns a refusal | that refusal's status and code |
| the appender returns an act | 201, body carries `message_id` and `sequence_number`, `Content-Type: application/a2cn+json` |

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter A2cnMessagesControllerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the controller**

`src/Protocol/Http/A2cnMessagesController.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActAppender;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRefusal;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRequest;
use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A2CN's own inbound message route: `POST {endpoint}/sessions/{id}/messages`,
 * where `{endpoint}` is what our discovery document advertises.
 *
 * The path is the protocol's, not ours. The reference implementation's client
 * builds every session URL as `f"{endpoint}/sessions/{session_id}/..."`, so a
 * conformant buyer agent needs no shop-specific code to negotiate with this
 * shop — which is the entire reason for not inventing a route here.
 *
 * Under `/a2cn` rather than at the domain root, and the discovery document
 * carries the prefix in `endpoint`. Claiming `/sessions` at the root of a
 * Shopware storefront would be a landgrab on the merchant's own URL space.
 *
 * Everything this method decides is a lookup or a translation; the rules live
 * in Ingress\, and the ordering of those rules is documented there. What is
 * decided HERE and nowhere else: the token's audience is the DID this SALES
 * CHANNEL signs under (the same identity the emitter uses, so the buyer's
 * `aud` matches the DID they discovered), and the whole append runs inside
 * the per-quote servicing lock.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final readonly class A2cnMessagesController
{
    private const CONTENT_TYPE = 'application/a2cn+json';

    public function __construct(
        private A2cnBearerJwt $tokens,
        private SessionQuoteLocator $locator,
        private QuoteTerminalStateReader $quotes,
        private A2cnIdentityResolver $identities,
        private QuoteServicingLock $locks,
        private InboundActAppender $appender,
    ) {}

    #[Route(
        path: '/a2cn/sessions/{sessionId}/messages',
        name: 'frontend.merchant_quote_agent.a2cn.messages.post',
        methods: ['POST'],
    )]
    public function messages(string $sessionId, Request $request): JsonResponse
    {
        $quoteId = $this->locator->quoteIdFor($sessionId);
        if ($quoteId === null) {
            return self::refuse(new InboundActRefusal(404, 'not_found'));
        }

        $now = new \DateTimeImmutable();

        try {
            $quote = $this->quotes->for($quoteId, $now);
        } catch (QuoteStateUnavailable) {
            return self::refuse(new InboundActRefusal(502, 'quote_state_unavailable'));
        }

        if ($quote === null) {
            return self::refuse(new InboundActRefusal(404, 'not_found'));
        }

        $identity = $this->identities->forSalesChannel($quote->salesChannelId);
        if ($identity === null) {
            // We cannot state who the token's audience should be, so we
            // cannot authenticate anyone. Same posture as the discovery
            // routes: say so, do not 500.
            return self::refuse(new InboundActRefusal(503, 'signing_key_missing'));
        }

        $issuer = $this->tokens->issuerOf(
            (string) $request->headers->get('Authorization', ''),
            $identity->did,
            $now,
        );
        $act = self::act($request);
        if ($issuer === null) {
            return self::refuse(new InboundActRefusal(401, 'invalid_jwt'));
        }

        if ($act === null) {
            return self::refuse(new InboundActRefusal(400, 'invalid_act'));
        }

        return $this->appendUnderLock($act, $quoteId, $sessionId, $quote, $identity->did, $issuer);
    }

    private function appendUnderLock(
        Act $act,
        string $quoteId,
        string $sessionId,
        QuoteTerminalState $quote,
        string $sellerDid,
        string $issuerDid,
    ): JsonResponse {
        $lock = $this->locks->for($quoteId);
        if (!$lock->acquire()) {
            return self::refuse(new InboundActRefusal(409, 'session_busy'));
        }

        try {
            $chainSource = $this->quotes->customFieldsFor($quoteId);
            $chain = ActChain::read($chainSource);
            $result = $this->appender->append(new InboundActRequest(
                act: $act,
                chain: $chain,
                quoteId: $quoteId,
                sessionId: $sessionId,
                quote: $quote,
                sellerDid: $sellerDid,
                issuerDid: $issuerDid,
            ));
        } finally {
            $lock->release();
        }

        if ($result instanceof InboundActRefusal) {
            return self::refuse($result);
        }

        $fresh = $result === $act;

        return self::accepted([
            'session_id' => $sessionId,
            'accepted' => [
                'message_id' => $result->messageId(),
                'sequence_number' => $result->sequenceNumber(),
            ],
        ], $fresh ? 201 : 200);
    }

    private static function act(Request $request): ?Act
    {
        $payload = json_decode($request->getContent(), associative: true);

        return \is_array($payload) ? Act::fromArray($payload) : null;
    }

    private static function refuse(InboundActRefusal $refusal): JsonResponse
    {
        return self::stamp(JsonEnvelope::noStore($refusal->toArray(), $refusal->status));
    }

    /** @param array<string, mixed> $body */
    private static function accepted(array $body, int $status): JsonResponse
    {
        return self::stamp(JsonEnvelope::noStore($body, $status));
    }

    private static function stamp(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
```

**Note the one new reader method** this needs: `QuoteTerminalStateReader::customFieldsFor(string $quoteId): ?array`, reading the quote's `customFields` fresh *inside* the lock. Add it in this task, next to `for()`, with the same `QuoteStateUnavailable` posture. Reading the chain outside the lock would race exactly the append the lock exists to serialize.

If the JWT check reads more naturally before the quote lookup, leave it where it is: the audience is not known until the sales channel is, and checking a signature against an audience we have not resolved is not a check.

- [ ] **Step 4: Wire the route and the service**

`routes.php`, inside the `CommercialAvailability::isAvailableByClass()` block, beside the records controller:

```php
        $routes->import(__DIR__ . '/../../Protocol/Http/A2cnMessagesController.php', 'attribute');
```

`services.php`, in the same gated block:

```php
    $services->set(A2cnBearerJwt::class);
    $services->set(A2cnMessagesController::class)->tag('controller.service_arguments');
```

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter 'A2cnMessagesController|Ingress'`
Expected: PASS.

- [ ] **Step 6: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol src/Resources/config tests/Unit/Protocol
git commit -m "feat(a2cn): accept inbound acts on A2CN's own message route

Closes #111.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Discovery and the canonical read aliases

**Files:**
- Modify: `src/Protocol/Http/A2cnDiscoveryController.php`
- Modify: `src/Protocol/Http/A2cnRecordsController.php`
- Test: `tests/Unit/Protocol/Http/A2cnDiscoveryControllerTest.php` (extend)

**Interfaces:**
- Produces: discovery `endpoint` = `{base}/a2cn`, new `messages_url` = `{base}/a2cn/sessions/{session_id}/messages`; route names `frontend.merchant_quote_agent.a2cn.messages.get` and `frontend.merchant_quote_agent.a2cn.record.canonical`.

- [ ] **Step 1: Write the failing test**

Extend the discovery test with:

```php
    public function testTheEndpointCarriesThePrefixTheSessionRoutesLiveUnder(): void
    {
        $document = $this->discoveryDocument();

        self::assertSame('https://shop.example/a2cn', $document['endpoint']);
        self::assertSame(
            'https://shop.example/a2cn/sessions/{session_id}/messages',
            $document['messages_url'],
        );
    }

    public function testTheWellKnownUrlsStayAtTheDomainRoot(): void
    {
        // A .well-known URI is domain-root by definition; only the session
        // routes moved under the prefix.
        self::assertSame(
            'https://shop.example/.well-known/a2cn-seller-mandate',
            $this->discoveryDocument()['mandate_url'],
        );
    }
```

Use whatever helper the existing test file has for building the document; if it has none, call `A2cnDiscoveryController::discovery()` with a `Request::create('https://shop.example/.well-known/a2cn-agent')` and decode the response.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter A2cnDiscoveryControllerTest`
Expected: FAIL on the endpoint assertion.

- [ ] **Step 3: Change the document**

In `discoveryDocument()`, introduce the session base and use it for the two session-scoped URLs only:

```php
        $sessions = $base . self::SESSION_PREFIX;

        return [
            // …unchanged fields…
            // The base every SESSION url is built from, which is not the
            // host the well-known documents are served on: A2CN's client
            // reads discovery at the domain root and then builds
            // `{endpoint}/sessions/...`, so the prefix belongs here and
            // nowhere else. Serving those routes at the domain root instead
            // would claim `/sessions` on the merchant's storefront.
            'endpoint' => $sessions,
            'updated_at' => ProtocolTimestamp::of($at),
            'mandate_url' => $base . self::MANDATE_PATH,
            'records_url' => $sessions . '/records/{session_id}',
            'messages_url' => $sessions . '/sessions/{session_id}/messages',
        ];
```

with `private const SESSION_PREFIX = '/a2cn';`. `records_url` keeps the exact value it has today — `{base}/a2cn/records/{session_id}` — it is only spelled differently.

- [ ] **Step 4: Add the two canonical aliases**

On `A2cnRecordsController::acts()`, add a second attribute above the existing one:

```php
    #[Route(
        path: '/a2cn/sessions/{sessionId}/messages',
        name: 'frontend.merchant_quote_agent.a2cn.messages.get',
        methods: ['GET'],
    )]
```

and on `record()`:

```php
    #[Route(
        path: '/a2cn/sessions/{sessionId}/record',
        name: 'frontend.merchant_quote_agent.a2cn.record.canonical',
        methods: ['GET'],
    )]
```

Add one paragraph to the class docblock: the canonical paths are A2CN's own, the older `/acts` and `/records/{id}` spellings stay because counterparties already hold those URLs, and the two names differ only in spelling.

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter 'A2cnDiscovery|A2cnRecords'`
Expected: PASS.

- [ ] **Step 6: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Protocol/Http tests/Unit/Protocol/Http
git commit -m "feat(a2cn): advertise and serve A2CN's canonical session paths

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: The buyer's organization name (#114)

**Files:**
- Modify: `src/Bridge/Data/QuoteIdentity.php`
- Modify: `src/Bridge/QuoteSnapshotReader.php`
- Modify: `src/Protocol/Http/QuoteTerminalState.php`
- Modify: `src/Protocol/Http/QuoteTerminalStateReader.php`
- Modify: `src/Protocol/Http/RecordPartiesResolver.php`
- Test: `tests/Unit/Protocol/Http/RecordPartiesResolverTest.php`

**Interfaces:**
- Produces: `QuoteIdentity::$companyName` (string, defaulted `''`), `QuoteTerminalState::$buyerOrganizationName` (string, defaulted `''`), and `RecordPartiesResolver::resolve()` reading it.

- [ ] **Step 1: Write the failing test**

```php
    public function testTheInitiatorCarriesTheBuyersCompanyName(): void
    {
        $parties = $this->resolver()->resolve(
            [$this->buyerAct()],
            $this->quoteState(buyerOrganizationName: 'Nordwind Handel GmbH'),
        );

        self::assertSame('Nordwind Handel GmbH', $parties->initiator->organizationName);
    }

    public function testAnAccountWithNoCompanyKeepsTheBlank(): void
    {
        $parties = $this->resolver()->resolve([$this->buyerAct()], $this->quoteState());

        self::assertSame('', $parties->initiator->organizationName);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter RecordPartiesResolverTest`
Expected: FAIL — unknown named argument / wrong value.

- [ ] **Step 3: Carry the company through the bridge**

`QuoteIdentity`: add `public string $companyName = ''` after `customerId`, with a docblock line — *the company's own name, for the parties block of an A2CN record; blank when the account has none, which is a fact about the account and not an error.*

`QuoteSnapshotReader::read()`: add `$criteria->addAssociation('customer');`. In `readIdentity()`:

```php
        $customer = $quote->get('customer');
        $company = $customer instanceof Entity ? $customer->get('company') : null;
```

and pass `companyName: \is_string($company) ? $company : ''`.

- [ ] **Step 4: Carry it to the record**

`QuoteTerminalState`: add `public string $buyerOrganizationName = ''` last, so existing constructions keep working.

`QuoteTerminalStateReader::for()`: pass `buyerOrganizationName: $snapshot->identity->companyName`.

`RecordPartiesResolver`: `initiator()` gains the name as a parameter and uses it in place of the hardcoded `''`. Replace the empty literal's absence of explanation with:

```php
        // Shopware's own record of who this account is, not the act's
        // self-declaration: the wire act carries no organization at all, and
        // the customer row is who they are to us contractually.
```

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter 'RecordParties|QuoteSnapshotReader|QuoteTerminalState'`
Expected: PASS.

- [ ] **Step 6: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Bridge src/Protocol/Http tests/Unit
git commit -m "feat(a2cn): name the buyer's company on the transaction record

Closes #114.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: The order reference (#115)

**Files:**
- Modify: `src/Bridge/Data/QuoteIdentity.php`
- Modify: `src/Bridge/QuoteSnapshotReader.php`
- Modify: `src/Protocol/Http/QuoteTerminalState.php`
- Modify: `src/Protocol/Http/QuoteTerminalStateReader.php`
- Modify: `src/Protocol/Http/RecordResponder.php`
- Modify: `src/Protocol/Record/TransactionRecord.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Record/TransactionRecordTest.php`

**Interfaces:**
- Produces: `QuoteIdentity::$orderId` (`?string`, defaulted null), `QuoteTerminalState::$orderNumber` (`?string`, defaulted null), and `TransactionRecord::build()` gaining a trailing `?string $orderReference = null` parameter.

**Read the ids, never the association.** `quote.order` carries no `ApiAware` flag on SwagCommercial 7.12 or 7.13; `quote.orderId` does. Read the id off the quote and look the number up in the core `order` repository.

- [ ] **Step 1: Write the failing test**

```php
    public function testItNamesTheOrderTheQuoteBecame(): void
    {
        $record = $this->record(orderReference: 'order:10014');

        self::assertSame('order:10014', $record['order_reference']);
    }

    public function testAnUnconvertedSessionCarriesNoOrderKeyAtAll(): void
    {
        // Absent, never null: an empty string is a claim, and there is
        // nothing to claim.
        self::assertArrayNotHasKey('order_reference', $this->record());
    }

    public function testTheRecordHashCoversTheOrderReference(): void
    {
        $with = $this->record(orderReference: 'order:10014');
        $without = $this->record();

        self::assertNotSame($with['record_hash'], $without['record_hash']);
    }

    public function testTheSubjectReferenceStillNamesOnlyTheQuote(): void
    {
        self::assertSame('quote:Q-1001', $this->record(orderReference: 'order:10014')['subject_reference']);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test -- --filter TransactionRecordTest`
Expected: FAIL — unknown argument / missing key.

- [ ] **Step 3: Add the field to the record**

In `TransactionRecord::build()`, add the trailing parameter `?string $orderReference = null` and, immediately before `'offer_chain_hash'`, insert the conditional key. Because the array is built as one literal, do it with a spread so the key is genuinely absent:

```php
            ...($orderReference === null ? [] : ['order_reference' => $orderReference]),
            'offer_chain_hash' => $this->chainHash->of($acts),
```

Add to the class docblock:

> `order_reference` names the Shopware order an accepted quote became, because every downstream system — ERP, accounting, dispute resolution — works with orders and not with quotes, and correlating them by hand through the shop's database is not an audit trail. It is **absent** when the quote never converted, following the module's absent-never-null rule, and it is inside `record_hash` like every other field. Not folded into `subject_reference`, which would make a compound string two parties have to agree how to parse; and never into `agreed_terms`, which must stay byte-identical to the terms the final offer was signed over.

- [ ] **Step 4: Resolve the number**

`QuoteIdentity`: add `public ?string $orderId = null`.

`QuoteSnapshotReader::readIdentity()`: `$orderId = $quote->get('orderId');` → `orderId: \is_string($orderId) ? $orderId : null`.

`QuoteTerminalState`: add `public ?string $orderNumber = null`.

`QuoteTerminalStateReader`: take a nullable `EntityRepository $orders` (last, defaulted, `ignoreOnInvalid()` — same posture as `$gateway`) and add:

```php
    /**
     * The human-facing order number for an id.
     *
     * A lookup, not an association traversal: `quote.order` carries no
     * ApiAware flag on either SwagCommercial version this plugin supports,
     * while `quote.orderId` does. A failed read returns null and the record
     * is served without the reference — a records request must not depend on
     * a second read succeeding.
     */
    private function orderNumber(?string $orderId): ?string
    {
        if ($orderId === null || $this->orders === null) {
            return null;
        }

        try {
            $order = $this->orders->search(new Criteria([$orderId]), Context::createDefaultContext())
                ->getEntities()
                ->first();
        } catch (\Throwable) {
            return null;
        }

        $number = $order instanceof Entity ? $order->get('orderNumber') : null;

        return \is_string($number) ? $number : null;
    }
```

and pass `orderNumber: $this->orderNumber($snapshot->identity->orderId)` into the `QuoteTerminalState`.

`RecordResponder::accepted()`: pass the reference through as

```php
            $quote->orderNumber === null ? null : 'order:' . $quote->orderNumber,
```

- [ ] **Step 5: Wire the repository**

```php
    $services->set(QuoteTerminalStateReader::class)->args([
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
        service('order.repository')->ignoreOnInvalid(),
    ]);
```

- [ ] **Step 6: Run the tests**

Run: `composer test -- --filter 'TransactionRecord|RecordResponder|QuoteTerminalState'`
Expected: PASS.

- [ ] **Step 7: Quality gates and commit**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
git add src/Bridge src/Protocol src/Resources/config tests/Unit
git commit -m "feat(a2cn): name the resulting order on the transaction record

Closes #115.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Integration tests

**Files:**
- Create: `tests/Integration/A2cnInboundActTest.php`
- Create: `tests/Integration/A2cnSessionLookupTest.php`
- Modify: `tests/Integration/BuyerQuoteFlowTest.php` (add the no-A2CN guard, or add a sibling test if the file is already at the size cap)

Read `tests/Integration/IntegrationTestCase.php` and `tests/Integration/BuyerQuoteFixture.php` first and build on them; do not invent a second harness.

- [ ] **Step 1: The DAL filter, on its own, first**

`A2cnSessionLookupTest`: create a quote through the existing fixture, write `customFields[a2cn_session]` onto it through `QuoteGatewayInterface::updateQuote()`, then assert `SessionQuoteLocator::quoteIdFor()` returns that quote id — and that an unknown session returns null.

This is the one platform assumption in the design. Run it before writing anything else in this task:

Run: `composer test:integration -- --filter A2cnSessionLookupTest`
Expected: PASS. **If it fails**, stop and report: the fallback is a two-column `session_id → quote_id` table, and only `SessionQuoteLocator` and one migration change.

- [ ] **Step 2: The round trip**

`A2cnInboundActTest`: request a quote over the UCP route; read `a2cn_session_id` off the response; build a buyer act for sequence 1 signed with a test key, and a matching Bearer JWT; serve a did:web document for the buyer DID (substitute `DidWebResolver`, as the unit tests do); POST it to `/a2cn/sessions/{id}/messages`. Assert:

- `201`, `Content-Type: application/a2cn+json`, body names sequence 1.
- The quote's `customFields` now carry `a2cn_act_0001_b`.
- Reposting the identical act answers `200` and leaves `customFields` unchanged.
- An act at sequence 5 answers `409 sequence_conflict`.
- An act signed by a different DID than act 1 answers `403 sender_did_not_party`.
- After servicing the quote into `replied`, the seller act is sequence 2 and its timestamp is not before act 1's.

- [ ] **Step 3: The no-A2CN guard**

Drive the complete buyer flow — request, counter, accept — with no act posted at any point, and assert the quote reaches the same states with the same totals it does today, and that no `a2cn_act_*` key exists on it. This is the regression test for "it still works without A2CN" and it must fail loudly if any of this work leaks into the plain UCP path.

- [ ] **Step 4: The order reference against a real order**

Extend whichever integration test already accepts a quote and produces an order: fetch `GET /a2cn/records/{session}` and assert `order_reference` matches `'order:' . $order->getOrderNumber()`.

- [ ] **Step 5: Run the full integration suite**

Run: `composer test:integration`
Expected: PASS. Note in the commit message which lane it ran against (7.13 parity shop or the 7.12 legacy shop) — the order-reference test is worth running on both.

- [ ] **Step 6: Commit**

```bash
git add tests/Integration
git commit -m "test(a2cn): integration coverage for inbound acts and the order reference

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 14: Acceptance — the counterparty's verifier

**Files:** none committed. This task produces evidence, not code.

The kit lives at `~/projects/_confidential/a2cn-verification-kit-quote-1083/` and its `TERMS.txt` forbids committing the verifier, the README, the signing recipe, the record or the golden vector. Extract to a scratch directory outside the repository.

- [ ] **Step 1: Emit an act through the real factory**

Use `SellerActFactory` (not a fixture) to produce a seller act, and write it plus the matching did:web document to the scratch directory.

- [ ] **Step 2: Run their verifier**

```bash
python tools/verify_record.py OUR_ACT.json --act --did-doc did:web:…=OUR_DID.json
```

Needs Python 3.11+ with `cryptography PyJWT jcs httpx jsonschema`. **Without `jsonschema` the schema check silently becomes `[NOT RUN]` and the run exits 3** — that is not a pass. Expected: `VERIFIED`.

- [ ] **Step 3: Report**

If it does not verify, stop and report the exact check that failed. Nothing in this plan is allowed to regress that result.

---

## Notes for the executor

- **Task order matters.** Task 3 (`ActVerifier`) must land before Task 7, and Task 1 (`ProtocolTimestamp::PATTERN`) before Tasks 2 and 7. Tasks 11 and 12 both touch `QuoteIdentity`, `QuoteTerminalState` and `QuoteSnapshotReader` — run them in order, not in parallel.
- **When a signature in this plan disagrees with the code, the code wins.** Several test fixtures above are written from a reading of the current classes; check the real constructor before copying one.
- **Do not add a `ponytail:` comment for a shortcut this plan did not sanction.** If you find yourself wanting one, that is a finding to report, not a decision to take.
