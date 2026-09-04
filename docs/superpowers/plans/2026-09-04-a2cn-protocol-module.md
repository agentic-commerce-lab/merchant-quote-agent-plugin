# A2CN Protocol Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the plugin its own A2CN evidence layer — a signed act chain on the quote, four evidence checks, end-of-session records, and a published signed seller mandate — so what the agent negotiated is cryptographically provable without the TypeScript service.

**Architecture:** A new top-level `src/Protocol/` module. Pure units first (money, hashing, signatures, chain reading), then the checks, then storage, then the emitter that a quote state transition drives through a Messenger message under the existing per-quote lock, then the HTTP surface that serves records and the mandate. Nothing in the module may block quote servicing: every failure path logs and returns.

**Tech Stack:** PHP 8.3, Shopware 6.7 (DAL for quotes, DBAL for our own tables, Messenger, storefront routes), UCP PHP SDK (`DeterministicJsonInterface` for RFC 8785 JCS, `SigningKeyManagerInterface`/`PublicSigningKey` for ES256 keys and JWK↔PEM), `openssl_*` for ECDSA, Guzzle for `did:web`, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-09-04-a2cn-protocol-module-design.md` — read it before Task 1. It carries the rationale this plan does not repeat, and the non-goals that keep the module small. Issue: #76.

## Global Constraints

- `declare(strict_types=1);` in every file; PHP 8.3 target; Mago analyze at full strictness — no `mixed` escape hatches, no unsafe casts.
- Complexity caps enforced by CI: cyclomatic 10, nesting depth 4, **parameters 5**, ~400 lines per file. The parameter cap is why collaborators come in bundles and DTOs are grouped.
- Namespace root `MerchantQuoteAgentPlugin\Protocol\…`; tests mirror under `MerchantQuoteAgentPlugin\Tests\Unit\Protocol\…`.
- PSR-3 logging only; no `echo`/`var_dump`. Log the exception object, never just its message. No secrets or buyer PII in log context.
- Throw `Throwable` subclasses only; preserve `$previous` when wrapping.
- Time is passed in as a `\DateTimeImmutable` parameter, never read inside a unit — the codebase's existing pattern (`OfferApplier`'s `now:` argument).
- **Signed field set is normative:** `protocol_version` `'0.1'`, `session_id`, `round_number`, `sequence_number`, `message_type`, `sender_did`, `timestamp`, `expires_at`, `terms`. Optional fields are **absent, never null**.
- Money in signed bytes is **integer minor units**, rounded half away from zero.
- Never emit an empty map: PHP renders `[]` as a JSON array, and `{}` vs `[]` changes every hash.
- Run after each task: `composer run test` (must stay green), then `vendor/bin/mago fmt <changed files>` and `composer run lint`. `composer run quality` runs once in Task 21.
- **CI does not run phpunit** (only quality-gate, plugin-zip, dependency-freshness workflows exist). Green CI is not green tests — run the suite locally, every task.

## File Structure

New, all under `src/Protocol/` unless noted. One responsibility each; the grouping is what keeps every file inside the caps.

| File | Responsibility |
| --- | --- |
| `Terms/MinorUnits.php` | float currency → integer minor units, half away from zero; throws on non-finite |
| `Terms/NonFiniteAmount.php` | the exception that throw uses |
| `Terms/TermsFactory.php` | `Bridge\Data\QuoteSnapshot` → A2CN `terms`; canonical equality of two terms |
| `Crypto/Base64Url.php` | base64url encode/decode |
| `Crypto/Es256Signature.php` | DER `SEQUENCE{r,s}` ↔ raw `R‖S` |
| `Crypto/CompactJws.php` | ES256 compact JWS over a **string** payload: sign, verify |
| `Crypto/ProtocolHash.php` | `base64url(SHA-256(JCS(value)))` over the SDK canonicalizer |
| `Crypto/SessionId.php` | UUIDv5 under the A2CN namespace; `forQuote()` |
| `Act/ActRole.php` | `b` / `s` |
| `Act/ActKey.php` | the `a2cn_act_0003_s` key convention and the `a2cn_session` key |
| `Act/Act.php` | one act: its raw array plus typed accessors; tolerant `fromArray()` |
| `Act/ActChain.php` | the chain as read off `customFields`: order, sequences, rounds, duplicates |
| `Act/SignedView.php` | the normative signed subset of an act |
| `Check/ProtocolViolation.php` | violation DTO + `toArray()` |
| `Check/EvidenceCheckInterface.php` | one check |
| `Check/SessionIdCheck.php` | `session_id_mismatch` |
| `Check/DuplicateSequenceCheck.php` | `duplicate_sequence` |
| `Check/BuyerTermsCheck.php` | `act_terms_mismatch` |
| `Check/BuyerSignatureCheck.php` | `buyer_act_unverified` |
| `Check/EvidenceInspector.php` | runs the checks in tagged order, first violation wins |
| `Did/DidWebUrl.php` | `did:web:host#kid` → HTTPS document URL + fragment |
| `Did/DidWebResolver.php` | fetch the document, find the method, normalize its JWK, return a PEM |
| `Store/ActRecord.php` | `(sessionId, quoteId, sequence, Act)` |
| `Store/ApprovalReceipt.php` | receipt DTO + `toArray()` |
| `Store/ActStoreInterface.php` | the mirror's contract |
| `Store/DbalActStore.php` | DBAL implementation over the three tables |
| `Identity/A2cnSigningKey.php` | kid + PEMs + createdAt, as stored |
| `Identity/A2cnKeyStore.php` | read/generate the key in `system_config` |
| `Identity/MissingSigningKey.php` | the exception when it is absent |
| `Identity/A2cnIdentity.php` | did, verification method, organization, agent id |
| `Identity/A2cnIdentityResolver.php` | identity for a sales channel / request host |
| `Emitter/ActSigner.php` | signed view → `{protocol_act_hash, protocol_act_signature}` |
| `Emitter/SellerActFactory.php` | snapshot + chain → the wire act to append |
| `Emitter/ChainMirror.php` | mirror the chain, persist violations and receipts |
| `Emitter/EmissionOutcome.php` | `inert` / `unchanged` / `violation` / `emitted` / `emission_failed` |
| `Emitter/SellerActEmitter.php` | the gate order, mirror-before-wire, the append |
| `Emitter/OfferVisibleStateSubscriber.php` | `state_machine.quote.state_changed` → message |
| `Emitter/ObserveQuoteMessage.php` | the message |
| `Emitter/ObserveQuoteHandler.php` | lock, load snapshot, run the emitter |
| `Record/OfferChainHash.php` | hash over the ordered act hashes |
| `Record/SessionOutcome.php` | quote state → terminal A2CN outcome |
| `Record/RecordParty.php`, `Record/RecordParties.php`, `Record/RecordSubject.php`, `Record/AuditEvidence.php` | record inputs, grouped for the parameter cap |
| `Record/TransactionRecord.php` | the accepted-session record |
| `Record/AuditLog.php` | the non-accepted terminal record |
| `Mandate/NegotiationBands.php` | the bands, in basis points |
| `Mandate/SellerMandateFactory.php` | `NegotiationPolicy` + identity → mandate body |
| `Mandate/MandateSigner.php` | detached JWS proof over the body |
| `Http/A2cnRecordsController.php` | `/a2cn/sessions/{id}/acts`, `/a2cn/records/{id}` |
| `Http/A2cnDiscoveryController.php` | the three `/.well-known/*` documents |
| `Http/QuoteTerminalStateReader.php` | quote state + expiry + number + acceptance act, for records |
| `src/Ucp/Profile/A2cnMandateProfileContributor.php` | advertise `com.a2cn.negotiation-mandate` in the UCP profile |
| `src/Migration/Migration1788600000CreateA2cnEvidence.php` | the three tables |

Modified: `src/Resources/config/services.php` (registrations), `src/Resources/config/routes.php` (route file already loads controllers by directory — verify), `src/Resources/config/config.xml` (one new field), `src/MerchantQuoteAgentPlugin.php` (install keygen, uninstall drop), `README.md` (a short A2CN section in Task 21).

---

### Task 1: Minor units

**Files:**
- Create: `src/Protocol/Terms/MinorUnits.php`, `src/Protocol/Terms/NonFiniteAmount.php`
- Test: `tests/Unit/Protocol/Terms/MinorUnitsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `MinorUnits::from(float $amount): int`, `NonFiniteAmount extends \RuntimeException`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Terms;

use MerchantQuoteAgentPlugin\Protocol\Terms\MinorUnits;
use MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase
{
    /**
     * Half away from zero, symmetrically: the quote discount is a negative line
     * item, so -1.005 and 1.005 must round to the same magnitude.
     */
    public function testItRoundsHalvesAwayFromZeroSymmetrically(): void
    {
        self::assertSame(101, MinorUnits::from(1.005));
        self::assertSame(-101, MinorUnits::from(-1.005));
        self::assertSame(1, MinorUnits::from(0.005));
        self::assertSame(-1, MinorUnits::from(-0.005));
    }

    public function testItConvertsOrdinaryAmounts(): void
    {
        self::assertSame(0, MinorUnits::from(0.0));
        self::assertSame(76000, MinorUnits::from(760.0));
        self::assertSame(79999, MinorUnits::from(799.99));
    }

    public function testItRefusesNonFiniteAmounts(): void
    {
        $this->expectException(NonFiniteAmount::class);

        MinorUnits::from(\NAN);
    }

    public function testItRefusesInfinity(): void
    {
        $this->expectException(NonFiniteAmount::class);

        MinorUnits::from(\INF);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter MinorUnitsTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Protocol\Terms\MinorUnits" not found`.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Terms;

/**
 * Money for the signed record: the single rounding rule, stated once.
 *
 * Both sides of an A2CN session must land on the same integers or the act
 * hashes diverge, so this is a rule and not a preference. Rounds HALF AWAY
 * FROM ZERO — `round()` on the magnitude, so 1.005 and -1.005 are treated
 * symmetrically, which matters because the quote discount is a negative line
 * item.
 *
 * The 1e-9 nudge corrects binary-float representation error (1.005 * 100 is
 * 100.49999999999999, not 100.5). It cannot flip a genuine value: money here
 * is never finer than 1e-4.
 *
 * ponytail: float math with a nudge. Move to integer cents end to end if a
 * rounding dispute ever actually surfaces in a record.
 */
final class MinorUnits
{
    private function __construct() {}

    /** @throws NonFiniteAmount */
    public static function from(float $amount): int
    {
        // A non-finite amount would serialize to `null` and silently diverge
        // the cross-party hash. A record that disagrees byte-for-byte with the
        // counterparty's is worse than no record, so this throws and the
        // emitter's caller carries on servicing the quote without an act.
        if (!\is_finite($amount)) {
            throw new NonFiniteAmount(\sprintf('A2CN minor units received a non-finite amount: %s', \var_export($amount, true)));
        }

        $magnitude = (int) round(abs($amount) * 100 + 1e-9);

        return $amount < 0 ? -$magnitude : $magnitude;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Terms;

final class NonFiniteAmount extends \RuntimeException {}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter MinorUnitsTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Terms tests/Unit/Protocol/Terms
git commit -m "feat(protocol): minor-unit rounding for signed A2CN amounts"
```

---

### Task 2: ES256 compact JWS

**Files:**
- Create: `src/Protocol/Crypto/Base64Url.php`, `src/Protocol/Crypto/Es256Signature.php`, `src/Protocol/Crypto/CompactJws.php`, `src/Protocol/Crypto/MalformedSignature.php`
- Test: `tests/Unit/Protocol/Crypto/CompactJwsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Base64Url::encode(string): string`, `Base64Url::decode(string): string`; `Es256Signature::toRaw(string $der): string`, `Es256Signature::toDer(string $raw): string`; `CompactJws::sign(string $payload, string $privateKeyPem): string`, `CompactJws::verify(string $jws, string $publicKeyPem): ?string` (the payload, or null when it does not verify); `MalformedSignature extends \RuntimeException`.

**Why hand-rolled:** `openssl_sign` produces a DER `SEQUENCE{INTEGER r, INTEGER s}`; a JWS ES256 signature is the raw 64-byte `R‖S`. That conversion is the whole delta. `firebase/php-jwt` cannot sign a bare string payload (it requires a claim array) and `web-token/jwt-library` drags a package tree into the plugin zip. **The RFC 7515 Appendix A.3 vector below has been run against exactly this code and verifies** — keep it as the guard.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Security\PublicSigningKey;

final class CompactJwsTest extends TestCase
{
    /**
     * RFC 7515 Appendix A.3 — the published ES256 example. ECDSA signing is
     * non-deterministic, so a fixed vector can only pin VERIFICATION; that is
     * also the direction a counterparty exercises against us.
     */
    private const RFC7515_A3_JWS = 'eyJhbGciOiJFUzI1NiJ9.'
        . 'eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ.'
        . 'DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q';

    private const RFC7515_A3_JWK = [
        'kid' => 'rfc7515-a3',
        'kty' => 'EC',
        'alg' => 'ES256',
        'use' => 'sig',
        'crv' => 'P-256',
        'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
        'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
    ];

    public function testItVerifiesTheRfc7515Es256Vector(): void
    {
        $pem = PublicSigningKey::fromJwk(self::RFC7515_A3_JWK)->publicKeyPem;
        self::assertIsString($pem);

        $payload = CompactJws::verify(self::RFC7515_A3_JWS, $pem);

        self::assertIsString($payload);
        self::assertStringContainsString('"iss":"joe"', $payload);
    }

    public function testItRejectsATamperedSignature(): void
    {
        $pem = PublicSigningKey::fromJwk(self::RFC7515_A3_JWK)->publicKeyPem;
        self::assertIsString($pem);

        [$header, $body, $signature] = explode('.', self::RFC7515_A3_JWS);
        $tampered = $header . '.' . $body . 'x.' . $signature;

        self::assertNull(CompactJws::verify($tampered, $pem));
    }

    public function testItRoundTripsAStringPayload(): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();

        // The A2CN payload is the act hash as an ASCII string, NOT a claim set.
        $jws = CompactJws::sign('URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI', $private);

        self::assertSame(3, \count(explode('.', $jws)));
        self::assertSame('URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI', CompactJws::verify($jws, $public));
    }

    public function testItSignsWithARawSixtyFourByteSignature(): void
    {
        ['private' => $private] = self::keyPair();

        $signature = Base64Url::decode(explode('.', CompactJws::sign('payload', $private))[2]);

        // JWS ES256 is raw R||S, not DER — 64 bytes for P-256, always.
        self::assertSame(64, \strlen($signature));
    }

    public function testItRoundTripsDerAndRawSignatures(): void
    {
        ['private' => $private] = self::keyPair();
        $der = '';
        self::assertNotFalse(openssl_sign('base', $der, $private, \OPENSSL_ALGO_SHA256));

        $raw = Es256Signature::toRaw($der);

        self::assertSame(64, \strlen($raw));
        self::assertSame($der, Es256Signature::toDer($raw));
    }

    /** @return array{private: string, public: string} */
    private static function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($resource);
        $private = '';
        self::assertTrue(openssl_pkey_export($resource, $private));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $private, 'public' => $details['key']];
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter CompactJwsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/** base64url (RFC 4648 §5) without padding — the only encoding JWS and A2CN use. */
final class Base64Url
{
    private function __construct() {}

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Returns '' for input that is not valid base64url, so callers fail closed. */
    public static function decode(string $value): string
    {
        $padded = strtr($value, '-_', '+/') . str_repeat('=', (4 - \strlen($value) % 4) % 4);
        $decoded = base64_decode($padded, strict: true);

        return $decoded === false ? '' : $decoded;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/**
 * ECDSA P-256 signatures in the two shapes that matter here.
 *
 * `openssl_sign`/`openssl_verify` speak DER `SEQUENCE{INTEGER r, INTEGER s}`;
 * a JWS ES256 signature is the fixed-width concatenation `R‖S` (32 bytes each,
 * RFC 7518 §3.4). Everything else in the module is built on this conversion.
 */
final class Es256Signature
{
    private const COORDINATE_BYTES = 32;

    private function __construct() {}

    /** @throws MalformedSignature */
    public static function toRaw(string $der): string
    {
        $offset = 0;
        self::expect($der, $offset, "\x30");
        $length = self::byteAt($der, $offset);
        if ($length > 0x80) {
            // Long form: the low nibble counts the length-of-length bytes.
            $offset += $length - 0x80;
        }

        $parts = [];
        for ($index = 0; $index < 2; ++$index) {
            self::expect($der, $offset, "\x02");
            $size = self::byteAt($der, $offset);
            $value = ltrim(substr($der, $offset, $size), "\x00");
            $offset += $size;
            if (\strlen($value) > self::COORDINATE_BYTES) {
                throw new MalformedSignature('ECDSA integer wider than the P-256 coordinate size.');
            }
            $parts[] = str_pad($value, self::COORDINATE_BYTES, "\x00", \STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /** @throws MalformedSignature */
    public static function toDer(string $raw): string
    {
        if (\strlen($raw) !== self::COORDINATE_BYTES * 2) {
            throw new MalformedSignature(\sprintf('A raw ES256 signature is 64 bytes, got %d.', \strlen($raw)));
        }

        $body = self::integer(substr($raw, 0, self::COORDINATE_BYTES))
            . self::integer(substr($raw, self::COORDINATE_BYTES));

        return "\x30" . \chr(\strlen($body)) . $body;
    }

    private static function integer(string $value): string
    {
        $trimmed = ltrim($value, "\x00");
        if ($trimmed === '') {
            $trimmed = "\x00";
        }
        // DER integers are signed: a leading bit set means prepend a zero byte.
        if ((\ord($trimmed[0]) & 0x80) !== 0) {
            $trimmed = "\x00" . $trimmed;
        }

        return "\x02" . \chr(\strlen($trimmed)) . $trimmed;
    }

    /** @throws MalformedSignature */
    private static function expect(string $der, int &$offset, string $tag): void
    {
        if (($der[$offset] ?? '') !== $tag) {
            throw new MalformedSignature('Unexpected DER tag in an ECDSA signature.');
        }
        ++$offset;
    }

    /** @throws MalformedSignature */
    private static function byteAt(string $der, int &$offset): int
    {
        $byte = $der[$offset] ?? '';
        if ($byte === '') {
            throw new MalformedSignature('Truncated DER ECDSA signature.');
        }
        ++$offset;

        return \ord($byte);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

final class MalformedSignature extends \RuntimeException {}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/**
 * ES256 compact JWS over a STRING payload.
 *
 * A2CN signs the act hash itself (spec 7.4): JCS → base64url(SHA-256) → sign
 * that hash string as an attached compact JWS. So the payload segment is
 * `base64url(ASCII(hash))`, not a JSON claim set — which is why no JWT library
 * fits: they all insist on claims.
 *
 * `verify()` returns the payload or null. Null, not an exception: a
 * counterparty act that does not verify is evidence about them, handled as a
 * protocol violation, never an error in our own control flow.
 */
final class CompactJws
{
    private const HEADER = '{"alg":"ES256"}';

    private function __construct() {}

    /** @throws MalformedSignature|\RuntimeException */
    public static function sign(string $payload, string $privateKeyPem): string
    {
        $signingInput = Base64Url::encode(self::HEADER) . '.' . Base64Url::encode($payload);
        $der = '';
        if (!openssl_sign($signingInput, $der, $privateKeyPem, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the A2CN payload with the configured key.');
        }

        return $signingInput . '.' . Base64Url::encode(Es256Signature::toRaw($der));
    }

    public static function verify(string $jws, string $publicKeyPem): ?string
    {
        $segments = explode('.', $jws);
        if (\count($segments) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $segments;
        if (Base64Url::decode($header) !== self::HEADER) {
            // Only ES256 with a bare alg header is accepted. Anything else —
            // another algorithm, `none`, extra header fields — is not ours to
            // interpret, and guessing would be the classic JWS confusion bug.
            return null;
        }

        try {
            $der = Es256Signature::toDer(Base64Url::decode($signature));
        } catch (MalformedSignature) {
            return null;
        }

        if (openssl_verify($header . '.' . $payload, $der, $publicKeyPem, \OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        return Base64Url::decode($payload);
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter CompactJwsTest`
Expected: PASS (5 tests). If the RFC vector fails, the DER conversion is wrong — do not "fix" the vector.

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Crypto tests/Unit/Protocol/Crypto
git commit -m "feat(protocol): ES256 compact JWS over a string payload"
```

---

### Task 3: Protocol hash

**Files:**
- Create: `src/Protocol/Crypto/ProtocolHash.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Crypto/ProtocolHashTest.php`

**Interfaces:**
- Consumes: `Ucp\Sdk\Service\DeterministicJsonInterface` (public service alias in the SDK bundle), `Base64Url`.
- Produces: `ProtocolHash::__construct(DeterministicJsonInterface $json)`, `->of(array $value): string`, `->canonical(array $value): string`.

- [ ] **Step 1: Write the failing test**

The expected values below were produced by the SDK's own canonicalizer — they pin our bytes, so a canonicalization change anywhere fails here instead of in production.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class ProtocolHashTest extends TestCase
{
    public function testItCanonicalizesKeysInCodeUnitOrder(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertSame('{"a":2,"b":1}', $hash->canonical(['b' => 1, 'a' => 2]));
    }

    public function testItPinsTheDigestOfAKnownSignedObject(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertSame('URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI', $hash->of(self::signedObject()));
    }

    public function testItHashesAListForTheOfferChain(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertSame('n6b0RQ2eX2xHsYIKnSUzelAONkFLftZzriw5jhlAVuA', $hash->of(['h1', 'h2']));
    }

    /** @return array<string, mixed> */
    private static function signedObject(): array
    {
        return [
            'protocol_version' => '0.1',
            'session_id' => 'a2cn-session',
            'sequence_number' => 1,
            'message_type' => 'counteroffer',
            'sender_did' => 'did:web:shop.example',
            'timestamp' => '2026-09-04T10:00:00Z',
            'terms' => [
                'total_value' => 760000,
                'currency' => 'EUR',
                'line_items' => [
                    [
                        'id' => 'line-1',
                        'description' => 'FusionGlow Sport',
                        'quantity' => 10,
                        'unit' => 'piece',
                        'unit_price' => 76000,
                        'total' => 760000,
                    ],
                ],
                'custom_terms' => [
                    'tax_status' => 'net',
                    'quote_number' => 'Q-1001',
                    'shopware_net_total_minor' => 760000,
                ],
            ],
        ];
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter ProtocolHashTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

use Ucp\Sdk\Service\DeterministicJsonInterface;

/**
 * `base64url(SHA-256(JCS(value)))` — the digest A2CN signs over.
 *
 * RFC 8785 canonicalization comes from the UCP SDK, which already needs it for
 * request signing; a second implementation in this plugin would be a second
 * thing to get wrong. The SDK's implementation is `@internal` behind the public
 * `DeterministicJsonInterface` alias, so we depend on the interface and pin the
 * resulting bytes in ProtocolHashTest: an SDK change that moves them fails
 * there rather than silently diverging a counterparty's hash.
 *
 * Absent-not-null is the caller's job, but it is free here: the canonicalizer
 * receives PHP arrays, and an unset key simply is not in the array.
 */
final readonly class ProtocolHash
{
    public function __construct(
        private DeterministicJsonInterface $json,
    ) {}

    /** @param array<array-key, mixed> $value */
    public function of(array $value): string
    {
        return Base64Url::encode(hash('sha256', $this->canonical($value), binary: true));
    }

    /**
     * The canonical string itself. Used for equality comparisons where the
     * digest would be wasted work — "the signed bytes would change" is exactly
     * "the canonical form differs".
     *
     * @param array<array-key, mixed> $value
     */
    public function canonical(array $value): string
    {
        return $this->json->canonicalize($value);
    }
}
```

Register it in `src/Resources/config/services.php` next to the other Protocol services added later:

```php
$services->set(ProtocolHash::class);
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter ProtocolHashTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Crypto tests/Unit/Protocol/Crypto src/Resources/config/services.php
git commit -m "feat(protocol): JCS-backed act hashing"
```

---

### Task 4: Session id

**Files:**
- Create: `src/Protocol/Crypto/SessionId.php`
- Test: `tests/Unit/Protocol/Crypto/SessionIdTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SessionId::NAMESPACE` (the A2CN Appendix A namespace), `SessionId::forQuote(string $quoteId): string`, `SessionId::derive(string $name, string $namespace = self::NAMESPACE): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use PHPUnit\Framework\TestCase;

final class SessionIdTest extends TestCase
{
    /** The published RFC 4122 / RFC 9562 name-based vector: DNS namespace + "python.org". */
    public function testItMatchesThePublishedUuidV5Vector(): void
    {
        self::assertSame(
            '886313e1-3b8a-5372-9b90-0c9aee199e5d',
            SessionId::derive('python.org', '6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
        );
    }

    public function testItDerivesTheSessionFromTheQuoteId(): void
    {
        // Derived, not random: this is what makes emission idempotent and lets
        // a records reader recompute the id from the quote it already holds.
        self::assertSame(
            '57d88e14-5e38-5b75-a94e-1b46206f6215',
            SessionId::forQuote('11111111111111111111111111111111'),
        );
    }

    public function testItIsStableAndVersionFive(): void
    {
        $id = SessionId::forQuote('0189d1c8f4f27c3ea0d4a5b6c7d8e9f0');

        self::assertSame($id, SessionId::forQuote('0189d1c8f4f27c3ea0d4a5b6c7d8e9f0'));
        self::assertSame('5', $id[14]);
        self::assertContains($id[19], ['8', '9', 'a', 'b']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter SessionIdTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/**
 * The A2CN session id: RFC 4122 name-based UUIDv5 (SHA-1) over the Shopware
 * quote id, under the namespace from the A2CN spec's Appendix A.
 *
 * Derived rather than generated, for three reasons that all matter: emission
 * stays idempotent across restarts and workers, a counterparty can recompute
 * the id from the quote it already holds, and the id is unguessable to anyone
 * who does not — which is what lets the records endpoints treat it as the
 * capability.
 *
 * Fifteen lines of `sha1()`, so no UUID dependency: Shopware's own Uuid helper
 * only makes random v4 ids.
 */
final class SessionId
{
    public const NAMESPACE = 'f4a2c1e0-8b3d-4f7a-9c2e-1d5b6a8f3e7c';

    private function __construct() {}

    public static function forQuote(string $quoteId): string
    {
        return self::derive($quoteId);
    }

    public static function derive(string $name, string $namespace = self::NAMESPACE): string
    {
        $bytes = hex2bin(str_replace('-', '', $namespace));
        if ($bytes === false) {
            throw new \InvalidArgumentException(\sprintf('Not a UUID namespace: %s', $namespace));
        }

        $digest = sha1($bytes . $name, binary: true);
        // Version 5 in the high nibble of octet 6; RFC 4122 variant in octet 8.
        $digest[6] = \chr((\ord($digest[6]) & 0x0F) | 0x50);
        $digest[8] = \chr((\ord($digest[8]) & 0x3F) | 0x80);

        $hex = bin2hex(substr($digest, 0, 16));

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter SessionIdTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Crypto tests/Unit/Protocol/Crypto
git commit -m "feat(protocol): derive the A2CN session id from the quote id"
```

---

### Task 5: The act and its key convention

**Files:**
- Create: `src/Protocol/Act/ActRole.php`, `src/Protocol/Act/ActKey.php`, `src/Protocol/Act/Act.php`
- Test: `tests/Unit/Protocol/Act/ActKeyTest.php`, `tests/Unit/Protocol/Act/ActTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `enum ActRole: string { case Buyer = 'b'; case Seller = 's'; }`
  - `ActKey::PREFIX = 'a2cn_act_'`, `ActKey::SESSION_KEY = 'a2cn_session'`, `ActKey::for(int $sequence, ActRole $role): string`, `ActKey::isActKey(string $key): bool`
  - `Act::fromArray(array $raw): ?self`; accessors `raw(): array`, `messageType(): string`, `messageId(): string`, `sessionId(): string`, `sequenceNumber(): int`, `roundNumber(): ?int`, `senderDid(): string`, `senderAgentId(): ?string`, `verificationMethod(): string`, `timestamp(): string`, `expiresAt(): ?string`, `terms(): ?array`, `hash(): string`, `signature(): string`, `isOffer(): bool`, `lineQuantities(): array<string, int>`
  - `Act::OFFER_TYPES = ['offer', 'counteroffer']`, `Act::MAX_ENCODED_BYTES = 65536`

**The act IS its raw array.** `terms` is hashed wholesale, so an act that round-tripped through a strict DTO would drop the counterparty's unmodelled keys and change the bytes we verify. `Act` therefore holds the array it was read from and exposes typed accessors over it; `fromArray()` is the tolerant gate that rejects only what makes an act unusable (missing or wrongly typed required envelope fields), returning null rather than throwing — a malformed buyer act is an evidence problem, never a reason to stop servicing a quote.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use PHPUnit\Framework\TestCase;

final class ActKeyTest extends TestCase
{
    public function testItZeroPadsSoLexicalOrderIsChronological(): void
    {
        self::assertSame('a2cn_act_0001_b', ActKey::for(1, ActRole::Buyer));
        self::assertSame('a2cn_act_0003_s', ActKey::for(3, ActRole::Seller));
        self::assertSame('a2cn_act_0042_s', ActKey::for(42, ActRole::Seller));

        $keys = [ActKey::for(10, ActRole::Seller), ActKey::for(2, ActRole::Seller)];
        sort($keys);
        self::assertSame([ActKey::for(2, ActRole::Seller), ActKey::for(10, ActRole::Seller)], $keys);
    }

    public function testTheTwoRolesCanNeverTargetTheSameKey(): void
    {
        self::assertNotSame(ActKey::for(3, ActRole::Buyer), ActKey::for(3, ActRole::Seller));
    }

    public function testItRecognizesOnlyActKeys(): void
    {
        self::assertTrue(ActKey::isActKey('a2cn_act_0001_b'));
        self::assertFalse(ActKey::isActKey(ActKey::SESSION_KEY));
        self::assertFalse(ActKey::isActKey('merchant_quote_agent_serviced'));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use PHPUnit\Framework\TestCase;

final class ActTest extends TestCase
{
    public function testItReadsTheEnvelope(): void
    {
        $act = Act::fromArray(self::buyerAct());

        self::assertNotNull($act);
        self::assertSame('offer', $act->messageType());
        self::assertSame('session:1', $act->messageId());
        self::assertSame(1, $act->sequenceNumber());
        self::assertSame(1, $act->roundNumber());
        self::assertSame('did:web:buyer.example', $act->senderDid());
        self::assertSame('did:web:buyer.example#key-1', $act->verificationMethod());
        self::assertTrue($act->isOffer());
    }

    public function testItKeepsUnmodelledTermsKeysVerbatim(): void
    {
        $raw = self::buyerAct();
        $raw['terms']['not_modelled_yet'] = ['nested' => true];
        $raw['also_unknown'] = 'kept';

        $act = Act::fromArray($raw);

        self::assertNotNull($act);
        self::assertSame(['nested' => true], $act->terms()['not_modelled_yet'] ?? null);
        self::assertSame($raw, $act->raw());
    }

    public function testItReturnsNullForAMalformedAct(): void
    {
        $raw = self::buyerAct();
        unset($raw['sender_verification_method']);

        self::assertNull(Act::fromArray($raw));
        self::assertNull(Act::fromArray(['message_type' => 'offer']));
    }

    public function testItRefusesAnOversizedAct(): void
    {
        $raw = self::buyerAct();
        $raw['terms']['custom_terms']['padding'] = str_repeat('x', Act::MAX_ENCODED_BYTES);

        self::assertNull(Act::fromArray($raw));
    }

    public function testItExposesLineQuantitiesForTheTermsCheck(): void
    {
        $act = Act::fromArray(self::buyerAct());

        self::assertNotNull($act);
        self::assertSame(['line-1' => 10], $act->lineQuantities());
    }

    /** @return array<string, mixed> */
    private static function buyerAct(): array
    {
        return [
            'message_type' => 'offer',
            'message_id' => 'session:1',
            'session_id' => '57d88e14-5e38-5b75-a94e-1b46206f6215',
            'round_number' => 1,
            'sequence_number' => 1,
            'sender_did' => 'did:web:buyer.example',
            'sender_agent_id' => 'buyer-agent',
            'sender_verification_method' => 'did:web:buyer.example#key-1',
            'timestamp' => '2026-09-04T09:00:00Z',
            'terms' => [
                'total_value' => 760000,
                'currency' => 'EUR',
                'line_items' => [
                    ['id' => 'line-1', 'description' => 'FusionGlow Sport', 'quantity' => 10, 'unit' => 'piece', 'unit_price' => 76000, 'total' => 760000],
                ],
                'custom_terms' => ['tax_status' => 'net'],
            ],
            'protocol_act_hash' => 'URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI',
            'protocol_act_signature' => 'eyJhbGciOiJFUzI1NiJ9.dGVzdA.c2ln',
        ];
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "ActKeyTest|ActTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/** Which party wrote an act. Part of the customFields key, so appends never collide. */
enum ActRole: string
{
    case Buyer = 'b';
    case Seller = 's';
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The quote `customFields` key convention for the A2CN act chain.
 *
 * One act per TOP-LEVEL key, because Shopware merges customFields shallowly on
 * update (see Bridge\QuoteWriter): separate keys make the buyer's append and
 * ours conflict-free, where a single `a2cn.acts[]` blob would lose one of two
 * concurrent writes.
 *
 * The key carries a zero-padded index AND the writer's role
 * (`a2cn_act_0003_b` / `a2cn_act_0003_s`). The index makes lexical order equal
 * chronological order; the role means the two parties can never target the same
 * key, so a concurrent append at the same sequence leaves both acts intact
 * instead of one silently overwriting the other. A duplicate sequence number is
 * then merely visible, and DuplicateSequenceCheck reports it rather than
 * evidence being destroyed.
 */
final class ActKey
{
    public const PREFIX = 'a2cn_act_';
    public const SESSION_KEY = 'a2cn_session';

    private const INDEX_WIDTH = 4;

    private function __construct() {}

    public static function for(int $sequence, ActRole $role): string
    {
        return self::PREFIX . str_pad((string) $sequence, self::INDEX_WIDTH, '0', \STR_PAD_LEFT) . '_' . $role->value;
    }

    public static function isActKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * One act, as it sits on the wire.
 *
 * The act IS its raw array: `terms` is hashed wholesale, so a counterparty term
 * this plugin does not model must survive verbatim or the hash we verify is not
 * the hash they signed. Typed accessors read over that array; `fromArray()` is
 * the tolerant gate.
 *
 * `fromArray()` returns null rather than throwing. A malformed buyer act is an
 * evidence problem — it belongs in a violation, not in an exception that would
 * interrupt quote servicing.
 */
final readonly class Act
{
    /** Acts that consume a negotiation round. Session establishment does not. */
    public const OFFER_TYPES = ['offer', 'counteroffer'];

    /**
     * A cap on what we will read off a quote. The counterparty controls this
     * value, and an unbounded act would be an unbounded hash, log line and row.
     * ponytail: 64 KiB is generous for terms; raise it only against a real act
     * that needs more.
     */
    public const MAX_ENCODED_BYTES = 65536;

    /** @param array<string, mixed> $raw */
    private function __construct(
        private array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $encoded = json_encode($raw);
        if ($encoded === false || \strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return null;
        }

        foreach (['message_type', 'message_id', 'session_id', 'sender_did', 'sender_verification_method', 'timestamp', 'protocol_act_hash', 'protocol_act_signature'] as $field) {
            if (!\is_string($raw[$field] ?? null)) {
                return null;
            }
        }

        if (!\is_int($raw['sequence_number'] ?? null)) {
            return null;
        }

        if (\array_key_exists('terms', $raw) && !\is_array($raw['terms'])) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return new self($raw);
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function messageType(): string
    {
        return $this->string('message_type');
    }

    public function messageId(): string
    {
        return $this->string('message_id');
    }

    public function sessionId(): string
    {
        return $this->string('session_id');
    }

    public function senderDid(): string
    {
        return $this->string('sender_did');
    }

    public function verificationMethod(): string
    {
        return $this->string('sender_verification_method');
    }

    public function timestamp(): string
    {
        return $this->string('timestamp');
    }

    public function hash(): string
    {
        return $this->string('protocol_act_hash');
    }

    public function signature(): string
    {
        return $this->string('protocol_act_signature');
    }

    public function sequenceNumber(): int
    {
        $value = $this->raw['sequence_number'] ?? null;

        return \is_int($value) ? $value : 0;
    }

    public function roundNumber(): ?int
    {
        $value = $this->raw['round_number'] ?? null;

        return \is_int($value) ? $value : null;
    }

    public function senderAgentId(): ?string
    {
        $value = $this->raw['sender_agent_id'] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function expiresAt(): ?string
    {
        $value = $this->raw['expires_at'] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @return array<string, mixed>|null */
    public function terms(): ?array
    {
        $value = $this->raw['terms'] ?? null;

        /** @var array<string, mixed>|null $value */
        return \is_array($value) ? $value : null;
    }

    public function isOffer(): bool
    {
        return \in_array($this->messageType(), self::OFFER_TYPES, strict: true);
    }

    /**
     * The act's line items as `id => quantity`, for the structural cross-check.
     * A line without a string id or int quantity is skipped: the check compares
     * what the act actually claims, and a claim we cannot read is not a claim.
     *
     * @return array<string, int>
     */
    public function lineQuantities(): array
    {
        $lines = $this->terms()['line_items'] ?? null;
        if (!\is_array($lines)) {
            return [];
        }

        $quantities = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $id = $line['id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (\is_string($id) && \is_int($quantity)) {
                $quantities[$id] = $quantity;
            }
        }

        return $quantities;
    }

    private function string(string $field): string
    {
        $value = $this->raw[$field] ?? null;

        return \is_string($value) ? $value : '';
    }
}
```

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "ActKeyTest|ActTest"`
Expected: PASS (8 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Act tests/Unit/Protocol/Act
git commit -m "feat(protocol): the act, its key convention and tolerant parsing"
```

---

### Task 6: The chain

**Files:**
- Create: `src/Protocol/Act/ActChain.php`
- Test: `tests/Unit/Protocol/Act/ActChainTest.php`

**Interfaces:**
- Consumes: `Act`, `ActKey`.
- Produces: `ActChain::read(?array $customFields): self`, `->acts(): list<Act>`, `->isEmpty(): bool`, `->hasSession(): bool`, `->sessionId(): ?string`, `->nextSequence(): int`, `->nextRound(): int`, `->last(): ?Act`, `->lastSellerAct(string $sellerDid): ?Act`, `->firstForeignAct(string $sellerDid): ?Act`, `->buyerActs(string $sellerDid): list<Act>`, `->duplicateSequences(): list<int>`, `->hasOffer(): bool`, `->exceedsLengthCap(): bool`, `ActChain::MAX_ACTS = 512`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use PHPUnit\Framework\TestCase;

final class ActChainTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';
    private const SELLER = 'did:web:shop.example';

    public function testItReadsActsInLexicalKeyOrderRegardlessOfArrayOrder(): void
    {
        $chain = ActChain::read([
            ActKey::for(2, ActRole::Seller) => self::act(2, self::SELLER, 'counteroffer'),
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'offer'),
            'merchant_quote_agent_serviced' => 'unrelated',
        ]);

        self::assertSame([1, 2], array_map(static fn (Act $act): int => $act->sequenceNumber(), $chain->acts()));
        self::assertSame(self::SESSION, $chain->sessionId());
    }

    public function testItIsEmptyWithoutASession(): void
    {
        $chain = ActChain::read(['merchant_quote_agent_serviced' => 'x']);

        self::assertTrue($chain->isEmpty());
        self::assertFalse($chain->hasSession());
        self::assertNull($chain->sessionId());
        self::assertSame(1, $chain->nextSequence());
        self::assertSame(1, $chain->nextRound());
    }

    public function testItSkipsUnparseableActsWithoutLosingTheRest(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => ['message_type' => 'offer'],
            ActKey::for(2, ActRole::Seller) => self::act(2, self::SELLER, 'counteroffer'),
        ]);

        self::assertCount(1, $chain->acts());
        self::assertSame(2, $chain->acts()[0]->sequenceNumber());
    }

    public function testItCountsTheNextSequenceAndRound(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'session_init'),
            ActKey::for(2, ActRole::Buyer) => self::act(2, 'did:web:buyer.example', 'offer'),
        ]);

        self::assertSame(3, $chain->nextSequence());
        // session_init does not consume a round; the buyer's offer is round 1,
        // so our counteroffer is round 2.
        self::assertSame(2, $chain->nextRound());
        self::assertTrue($chain->hasOffer());
    }

    public function testItFindsOurLastActAndTheirFirst(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'offer'),
            ActKey::for(2, ActRole::Seller) => self::act(2, self::SELLER, 'counteroffer'),
            ActKey::for(3, ActRole::Buyer) => self::act(3, 'did:web:buyer.example', 'counteroffer'),
        ]);

        self::assertSame(2, $chain->lastSellerAct(self::SELLER)?->sequenceNumber());
        self::assertSame(1, $chain->firstForeignAct(self::SELLER)?->sequenceNumber());
        self::assertCount(2, $chain->buyerActs(self::SELLER));
        self::assertSame(3, $chain->last()?->sequenceNumber());
    }

    public function testItReportsDuplicateSequences(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => self::SESSION,
            ActKey::for(1, ActRole::Buyer) => self::act(1, 'did:web:buyer.example', 'offer'),
            ActKey::for(1, ActRole::Seller) => self::act(1, self::SELLER, 'counteroffer'),
        ]);

        self::assertSame([1], $chain->duplicateSequences());
        // Both acts survive the collision — role-suffixed keys cannot overwrite.
        self::assertCount(2, $chain->acts());
    }

    public function testItStopsAtTheLengthCap(): void
    {
        $fields = [ActKey::SESSION_KEY => self::SESSION];
        for ($sequence = 1; $sequence <= ActChain::MAX_ACTS + 5; ++$sequence) {
            $fields[ActKey::for($sequence, ActRole::Buyer)] = self::act($sequence, 'did:web:buyer.example', 'offer');
        }

        $chain = ActChain::read($fields);

        self::assertCount(ActChain::MAX_ACTS, $chain->acts());
        self::assertTrue($chain->exceedsLengthCap());
    }

    /** @return array<string, mixed> */
    private static function act(int $sequence, string $did, string $type): array
    {
        return [
            'message_type' => $type,
            'message_id' => self::SESSION . ':' . $sequence,
            'session_id' => self::SESSION,
            'round_number' => $type === 'session_init' ? null : 1,
            'sequence_number' => $sequence,
            'sender_did' => $did,
            'sender_verification_method' => $did . '#key-1',
            'timestamp' => '2026-09-04T09:00:00Z',
            'protocol_act_hash' => 'hash-' . $sequence,
            'protocol_act_signature' => 'signature-' . $sequence,
        ];
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter ActChainTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The shared act chain, as stored on the Shopware quote.
 *
 * Read-only and derived: the quote's `customFields` are the authoritative
 * chain, this is the ordered view of them. Plain lexical sort over the act keys
 * is a total order every reader agrees on — which is what keeps
 * `offer_chain_hash` agreed even when a concurrent append leaves two acts at
 * the same sequence.
 */
final readonly class ActChain
{
    /**
     * ponytail: a chain longer than this is not a negotiation, and reading it
     * unbounded would let a counterparty size our work. Read up to the cap and
     * let the caller report it.
     */
    public const MAX_ACTS = 512;

    /**
     * @param list<Act> $acts
     */
    private function __construct(
        private array $acts,
        private ?string $sessionId,
        private bool $exceedsLengthCap,
    ) {}

    /**
     * @param array<string, mixed>|null $customFields
     */
    public static function read(?array $customFields): self
    {
        $session = $customFields[ActKey::SESSION_KEY] ?? null;
        if (!\is_string($session) || $session === '') {
            return new self([], null, false);
        }

        $keys = array_filter(array_keys($customFields ?? []), ActKey::isActKey(...));
        sort($keys);
        $overflow = \count($keys) > self::MAX_ACTS;

        $acts = [];
        foreach (\array_slice($keys, 0, self::MAX_ACTS) as $key) {
            $value = $customFields[$key] ?? null;
            $act = \is_array($value) ? Act::fromArray($value) : null;
            if ($act !== null) {
                $acts[] = $act;
            }
        }

        return new self($acts, $session, $overflow);
    }

    /** @return list<Act> */
    public function acts(): array
    {
        return $this->acts;
    }

    public function isEmpty(): bool
    {
        return $this->acts === [];
    }

    public function hasSession(): bool
    {
        return $this->sessionId !== null;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** The session id the acts themselves claim, which is not necessarily ours. */
    public function claimedSessionId(): ?string
    {
        return $this->acts[0]?->sessionId();
    }

    public function exceedsLengthCap(): bool
    {
        return $this->exceedsLengthCap;
    }

    public function nextSequence(): int
    {
        $highest = 0;
        foreach ($this->acts as $act) {
            $highest = max($highest, $act->sequenceNumber());
        }

        return $highest + 1;
    }

    public function nextRound(): int
    {
        $offers = 0;
        foreach ($this->acts as $act) {
            if ($act->isOffer()) {
                ++$offers;
            }
        }

        return $offers + 1;
    }

    public function last(): ?Act
    {
        return $this->acts === [] ? null : $this->acts[\count($this->acts) - 1];
    }

    public function lastSellerAct(string $sellerDid): ?Act
    {
        $found = null;
        foreach ($this->acts as $act) {
            if ($act->senderDid() === $sellerDid) {
                $found = $act;
            }
        }

        return $found;
    }

    /** The counterparty's first act — whoever we did not sign for. */
    public function firstForeignAct(string $sellerDid): ?Act
    {
        foreach ($this->acts as $act) {
            if ($act->senderDid() !== $sellerDid) {
                return $act;
            }
        }

        return null;
    }

    /** @return list<Act> */
    public function buyerActs(string $sellerDid): array
    {
        return array_values(array_filter($this->acts, static fn (Act $act): bool => $act->senderDid() !== $sellerDid));
    }

    /** @return list<int> */
    public function duplicateSequences(): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($this->acts as $act) {
            $sequence = $act->sequenceNumber();
            if (isset($seen[$sequence])) {
                $duplicates[$sequence] = $sequence;
            }
            $seen[$sequence] = true;
        }

        return array_values($duplicates);
    }

    public function hasOffer(): bool
    {
        foreach ($this->acts as $act) {
            if ($act->isOffer()) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter ActChainTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Act tests/Unit/Protocol/Act
git commit -m "feat(protocol): ordered act chain over the quote custom fields"
```

---

### Task 7: The signed view

**Files:**
- Create: `src/Protocol/Act/SignedView.php`
- Test: `tests/Unit/Protocol/Act/SignedViewTest.php`

**Interfaces:**
- Consumes: `Act`.
- Produces: `SignedView::PROTOCOL_VERSION = '0.1'`, `SignedView::of(Act $act): array<string, mixed>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use PHPUnit\Framework\TestCase;

final class SignedViewTest extends TestCase
{
    public function testItSignsExactlyTheNormativeFieldSet(): void
    {
        $act = Act::fromArray(self::act());
        self::assertNotNull($act);

        self::assertSame([
            'protocol_version' => '0.1',
            'session_id' => 'session-1',
            'round_number' => 2,
            'sequence_number' => 3,
            'message_type' => 'counteroffer',
            'sender_did' => 'did:web:shop.example',
            'timestamp' => '2026-09-04T10:00:00Z',
            'expires_at' => '2026-09-18T10:00:00Z',
            'terms' => ['total_value' => 1, 'currency' => 'EUR'],
        ], SignedView::of($act));
    }

    public function testItOmitsOptionalFieldsRatherThanNullingThem(): void
    {
        $raw = self::act();
        unset($raw['round_number'], $raw['expires_at'], $raw['terms']);
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        $view = SignedView::of($act);

        // `{a:1}` and `{a:1,b:null}` canonicalize differently, so an absent
        // field must be absent, not null.
        self::assertArrayNotHasKey('round_number', $view);
        self::assertArrayNotHasKey('expires_at', $view);
        self::assertArrayNotHasKey('terms', $view);
    }

    public function testItExcludesEnvelopeAndProofFields(): void
    {
        $act = Act::fromArray(self::act());
        self::assertNotNull($act);

        $view = SignedView::of($act);

        foreach (['message_id', 'in_reply_to', 'sender_agent_id', 'sender_verification_method', 'protocol_act_hash', 'protocol_act_signature'] as $field) {
            self::assertArrayNotHasKey($field, $view);
        }
    }

    /** @return array<string, mixed> */
    private static function act(): array
    {
        return [
            'message_type' => 'counteroffer',
            'message_id' => 'session-1:3',
            'in_reply_to' => 'session-1:2',
            'session_id' => 'session-1',
            'round_number' => 2,
            'sequence_number' => 3,
            'sender_did' => 'did:web:shop.example',
            'sender_agent_id' => 'merchant-quote-agent',
            'sender_verification_method' => 'did:web:shop.example#key-1',
            'timestamp' => '2026-09-04T10:00:00Z',
            'expires_at' => '2026-09-18T10:00:00Z',
            'terms' => ['total_value' => 1, 'currency' => 'EUR'],
            'protocol_act_hash' => 'hash',
            'protocol_act_signature' => 'signature',
        ];
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter SignedViewTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The protocol act object: the never-transmitted construct that is actually
 * signed.
 *
 * Keep it separate from the wire act, or the hash silently covers envelope
 * fields the counterparty does not sign. The field set below is NORMATIVE —
 * adding, removing or reordering a field changes every hash this plugin
 * produces and breaks agreement with every counterparty. `protocol_version` is
 * signed but never appears on the wire act.
 *
 * Field ORDER here is irrelevant to the bytes (JCS sorts keys), but it is kept
 * in spec order so a reader can diff it against the spec.
 */
final class SignedView
{
    public const PROTOCOL_VERSION = '0.1';

    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(Act $act): array
    {
        $view = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'session_id' => $act->sessionId(),
        ];

        $round = $act->roundNumber();
        if ($round !== null) {
            $view['round_number'] = $round;
        }

        $view['sequence_number'] = $act->sequenceNumber();
        $view['message_type'] = $act->messageType();
        $view['sender_did'] = $act->senderDid();
        $view['timestamp'] = $act->timestamp();

        $expiresAt = $act->expiresAt();
        if ($expiresAt !== null) {
            $view['expires_at'] = $expiresAt;
        }

        $terms = $act->terms();
        if ($terms !== null) {
            $view['terms'] = $terms;
        }

        return $view;
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter SignedViewTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Act tests/Unit/Protocol/Act
git commit -m "feat(protocol): the normative signed view of an act"
```
---

### Task 8: Terms from a quote snapshot

**Files:**
- Create: `src/Protocol/Terms/TermsFactory.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Terms/TermsFactoryTest.php`

**Interfaces:**
- Consumes: `MinorUnits`, `ProtocolHash`, `Bridge\Data\QuoteSnapshot`.
- Produces: `TermsFactory::__construct(ProtocolHash $hash)`, `->fromSnapshot(QuoteSnapshot $snapshot): array<string, mixed>`, `->unchanged(?array $previous, array $current): bool`.

**Two facts about the source data.** The Bridge snapshot is already **net**
throughout — `QuoteLineNet` subtracts each line's own `calculatedTaxes`, and
`QuoteTotals::totalNet` comes from `amountNet` — so nothing here re-derives tax.
And `Bridge\Data\QuoteLineIdentity` has **no unit field** (only the Policy DTO
does), so `unit` is the constant `'piece'`; a real unit short code needs the
bridge to read it first, which is out of scope.

**What is deliberately absent:** `payment_terms`, `delivery_terms`,
`deposit_bps`, `free_shipping_granted`, `expedited`. The plugin does not persist
non-price terms at all (#11), and signing a commitment nothing downstream
honours is worse than omitting it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Terms;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Terms\TermsFactory;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class TermsFactoryTest extends TestCase
{
    public function testItBuildsTermsInIntegerMinorUnits(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot());

        self::assertSame(760000, $terms['total_value']);
        self::assertSame('EUR', $terms['currency']);
        self::assertSame([
            [
                'id' => 'line-1',
                'description' => 'FusionGlow Sport',
                'quantity' => 10,
                'unit' => 'piece',
                'unit_price' => 76000,
                'total' => 760000,
            ],
        ], $terms['line_items']);
    }

    public function testItRecordsTheReconciliationFiguresInCustomTerms(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot());

        self::assertSame([
            'tax_status' => 'net',
            'quote_number' => 'Q-1001',
            'shopware_net_total_minor' => 760000,
        ], $terms['custom_terms']);
    }

    public function testItOmitsNonPriceTermsTheQuoteDoesNotPersist(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot());

        foreach (['payment_terms', 'delivery_terms'] as $absent) {
            self::assertArrayNotHasKey($absent, $terms);
        }
        self::assertArrayNotHasKey('deposit_bps', $terms['custom_terms']);
    }

    public function testItFallsBackToTheLineIdWhenALineHasNoLabel(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot(label: null));

        self::assertSame('line-1', $terms['line_items'][0]['description']);
    }

    public function testUnitPriceIsDerivedAndTotalIsAuthoritative(): void
    {
        // 3 x 33.34 net: the total is what the buyer is invoiced; the unit price
        // need not multiply back out exactly (A2CN determinism rule 3).
        $terms = self::factory()->fromSnapshot(self::snapshot(quantity: 3, unitNet: 33.34, totalNet: 100.01));

        self::assertSame(10001, $terms['line_items'][0]['total']);
        self::assertSame(3334, $terms['line_items'][0]['unit_price']);
    }

    public function testItComparesTermsByTheirSignedBytes(): void
    {
        $factory = self::factory();
        $terms = $factory->fromSnapshot(self::snapshot());

        self::assertTrue($factory->unchanged($terms, $terms));
        self::assertTrue($factory->unchanged(array_reverse($terms, preserve_keys: true), $terms));
        self::assertFalse($factory->unchanged(null, $terms));
        self::assertFalse($factory->unchanged($factory->fromSnapshot(self::snapshot(quantity: 9)), $terms));
    }

    private static function factory(): TermsFactory
    {
        return new TermsFactory(new ProtocolHash(new DefaultJsonCanonicalization()));
    }

    private static function snapshot(
        ?string $label = 'FusionGlow Sport',
        int $quantity = 10,
        float $unitNet = 76000.0 / 100 / 10,
        float $totalNet = 7600.0,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity(quoteId: 'quote-1', quoteNumber: 'Q-1001', currencyIso: 'EUR'),
            revision: new QuoteRevision('rev-1'),
            totals: new QuoteTotals(totalNet: $totalNet),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'replied'),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity(lineItemId: 'line-1', label: $label),
                    quantity: $quantity,
                    unitPriceNet: $unitNet,
                    totalNet: $totalNet,
                ),
            ]),
        );
    }
}
```

Before running: open `src/Bridge/Data/QuoteRevision.php` and `QuoteTotals.php` and match the constructor arguments the fixture uses — if `QuoteRevision` takes something other than a single string, fix the fixture, not the production code.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter TermsFactoryTest`
Expected: FAIL — `TermsFactory` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Terms;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;

/**
 * Shopware quote → A2CN `terms` (spec v0.2.0 base schema).
 *
 * Every amount is integer minor units. `total` is authoritative and
 * `total_value = sum(total)` reconciles with what the buyer is invoiced;
 * `unit_price` is derived and need NOT multiply out — see the spec's
 * determinism rule 3.
 *
 * The bridge snapshot is already net (QuoteLineNet takes each line's own
 * calculated taxes off, and QuoteTotals::totalNet comes from `amountNet`), so
 * there is no tax arithmetic here. `shopware_net_total_minor` carries the
 * quote's own net total beside the summed line totals, so a divergence — a
 * quote-level discount, a rounding disagreement — is visible to a reader
 * instead of hidden inside one number.
 *
 * No non-price terms: this plugin does not persist payment term, net days,
 * deposit or lead time (#11), and a signed promise nothing downstream honours
 * is worse than an omitted field.
 */
final readonly class TermsFactory
{
    private const TAX_STATUS = 'net';

    /** Bridge\Data\QuoteLineIdentity carries no unit, so every line is 'piece'. */
    private const DEFAULT_UNIT = 'piece';

    public function __construct(
        private ProtocolHash $hash,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws NonFiniteAmount
     */
    public function fromSnapshot(QuoteSnapshot $snapshot): array
    {
        $lineItems = array_map($this->lineItem(...), $snapshot->content->lines);

        $totalValue = 0;
        foreach ($lineItems as $lineItem) {
            $totalValue += $lineItem['total'];
        }

        return [
            'total_value' => $totalValue,
            'currency' => $snapshot->identity->currencyIso,
            'line_items' => array_values($lineItems),
            'custom_terms' => [
                'tax_status' => self::TAX_STATUS,
                'quote_number' => $snapshot->identity->quoteNumber,
                'shopware_net_total_minor' => MinorUnits::from($snapshot->totals->totalNet),
            ],
        ];
    }

    /**
     * "Terms changed" must mean exactly "the signed bytes would change", so the
     * comparison runs over the canonical form rather than PHP equality — key
     * order differs freely between a rebuilt array and one read back off an act.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     */
    public function unchanged(?array $previous, array $current): bool
    {
        if ($previous === null) {
            return false;
        }

        return $this->hash->canonical($previous) === $this->hash->canonical($current);
    }

    /**
     * @return array{id: string, description: string, quantity: int, unit: string, unit_price: int, total: int}
     *
     * @throws NonFiniteAmount
     */
    private function lineItem(QuoteLineSnapshot $line): array
    {
        $total = MinorUnits::from($line->totalNet);
        $quantity = $line->quantity;

        return [
            'id' => $line->identity->lineItemId,
            'description' => $line->identity->label ?? $line->identity->lineItemId,
            'quantity' => $quantity,
            'unit' => self::DEFAULT_UNIT,
            'unit_price' => $quantity === 0 ? $total : (int) round($total / $quantity),
            'total' => $total,
        ];
    }
}
```

Register it: `$services->set(TermsFactory::class);`

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter TermsFactoryTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Terms tests/Unit/Protocol/Terms src/Resources/config/services.php
git commit -m "feat(protocol): map a quote snapshot to A2CN terms"
```

---

### Task 9: Violations and the two local checks

**Files:**
- Create: `src/Protocol/Check/ProtocolViolation.php`, `src/Protocol/Check/EvidenceCheckInterface.php`, `src/Protocol/Check/SessionIdCheck.php`, `src/Protocol/Check/DuplicateSequenceCheck.php`, `src/Protocol/Check/ChainLengthCheck.php`
- Test: `tests/Unit/Protocol/Check/SessionIdCheckTest.php`, `tests/Unit/Protocol/Check/DuplicateSequenceCheckTest.php`, `tests/Unit/Protocol/Check/ChainLengthCheckTest.php`

**Interfaces:**
- Consumes: `ActChain`, `Bridge\Data\QuoteSnapshot`, `SessionId`.
- Produces:
  - `ProtocolViolation::__construct(string $timestamp, string $violationType, ?string $messageId, string $description)`, `->toArray(): array<string, mixed>`, `ProtocolViolation::fromArray(array $row): ?self`
  - `interface EvidenceCheckInterface { public function check(ActChain $chain, QuoteSnapshot $snapshot, string $sellerDid, \DateTimeImmutable $at): ?ProtocolViolation; }`
  - `SessionIdCheck`, `DuplicateSequenceCheck`, `ChainLengthCheck` implementing it, with violation types `session_id_mismatch`, `duplicate_sequence`, `chain_length_exceeded`.

`ChainLengthCheck` is **not** one of the four ported checks: it reports the local
read cap from `ActChain::MAX_ACTS`, so a chain we could only read partially is
recorded rather than silently signed into.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\SessionIdCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class SessionIdCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';
    private const SELLER = 'did:web:shop.example';

    public function testItPassesWhenTheChainDeclaresTheDerivedSession(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);

        $violation = (new SessionIdCheck())->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), self::SELLER, ProtocolFixtures::at());

        self::assertNull($violation);
    }

    public function testItRefusesAForeignSessionId(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => 'not-ours',
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, 'not-ours'),
        ]);

        $violation = (new SessionIdCheck())->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), self::SELLER, ProtocolFixtures::at());

        self::assertNotNull($violation);
        self::assertSame('session_id_mismatch', $violation->violationType);
        self::assertSame(ProtocolFixtures::buyerAct(1, 'not-ours')['message_id'], $violation->messageId);
        self::assertStringContainsString(SessionId::forQuote(self::QUOTE_ID), $violation->description);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\DuplicateSequenceCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class DuplicateSequenceCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesOnDistinctSequences(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ActKey::for(2, ActRole::Seller) => ProtocolFixtures::sellerAct(2, $session),
        ]);

        self::assertNull((new DuplicateSequenceCheck())->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at()));
    }

    public function testItReportsASequenceClaimedTwice(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ActKey::for(1, ActRole::Seller) => ProtocolFixtures::sellerAct(1, $session),
        ]);

        $violation = (new DuplicateSequenceCheck())->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at());

        self::assertNotNull($violation);
        self::assertSame('duplicate_sequence', $violation->violationType);
        // No single act is at fault, so no message id is attributed.
        self::assertNull($violation->messageId);
        self::assertStringContainsString('1', $violation->description);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ChainLengthCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class ChainLengthCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItReportsAChainLongerThanWeWillRead(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $fields = [ActKey::SESSION_KEY => $session];
        for ($sequence = 1; $sequence <= ActChain::MAX_ACTS + 1; ++$sequence) {
            $fields[ActKey::for($sequence, ActRole::Buyer)] = ProtocolFixtures::buyerAct($sequence, $session);
        }

        $violation = (new ChainLengthCheck())->check(ActChain::read($fields), ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at());

        self::assertNotNull($violation);
        self::assertSame('chain_length_exceeded', $violation->violationType);
    }
}
```

And the shared fixture the check tests use:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;

/**
 * Fixtures shared by the Protocol unit tests. Deliberately plain arrays and
 * real DTOs — an act is its raw array, and a builder would hide the shape the
 * tests are about.
 */
final class ProtocolFixtures
{
    public const SELLER = 'did:web:shop.example';
    public const BUYER = 'did:web:buyer.example';

    private function __construct() {}

    public static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-04T10:00:00+00:00');
    }

    /** @return array<string, mixed> */
    public static function buyerAct(int $sequence, string $session, string $type = 'offer'): array
    {
        return self::act($sequence, $session, self::BUYER, $type);
    }

    /** @return array<string, mixed> */
    public static function sellerAct(int $sequence, string $session, string $type = 'counteroffer'): array
    {
        return self::act($sequence, $session, self::SELLER, $type);
    }

    /** @return array<string, mixed> */
    public static function act(int $sequence, string $session, string $did, string $type): array
    {
        return [
            'message_type' => $type,
            'message_id' => $session . ':' . $sequence,
            'session_id' => $session,
            'round_number' => 1,
            'sequence_number' => $sequence,
            'sender_did' => $did,
            'sender_agent_id' => $did === self::SELLER ? 'merchant-quote-agent' : 'buyer-agent',
            'sender_verification_method' => $did . '#key-1',
            'timestamp' => '2026-09-04T09:00:00Z',
            'terms' => self::terms(),
            'protocol_act_hash' => 'hash-' . $sequence,
            'protocol_act_signature' => 'signature-' . $sequence,
        ];
    }

    /** @return array<string, mixed> */
    public static function terms(int $quantity = 10): array
    {
        return [
            'total_value' => 760000,
            'currency' => 'EUR',
            'line_items' => [
                ['id' => 'line-1', 'description' => 'FusionGlow Sport', 'quantity' => $quantity, 'unit' => 'piece', 'unit_price' => 76000, 'total' => 760000],
            ],
            'custom_terms' => ['tax_status' => 'net', 'quote_number' => 'Q-1001', 'shopware_net_total_minor' => 760000],
        ];
    }

    /** @param array<string, mixed> $customFields */
    public static function snapshot(
        string $quoteId = '11111111111111111111111111111111',
        string $state = 'replied',
        array $customFields = [],
        int $quantity = 10,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity(quoteId: $quoteId, quoteNumber: 'Q-1001', currencyIso: 'EUR'),
            revision: new QuoteRevision('rev-1'),
            totals: new QuoteTotals(totalNet: 7600.0),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $state,
                expiresAt: new \DateTimeImmutable('2026-09-18T10:00:00+00:00'),
                customFields: $customFields,
            ),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity(lineItemId: 'line-1', label: 'FusionGlow Sport'),
                    quantity: $quantity,
                    unitPriceNet: 760.0,
                    totalNet: 7600.0,
                ),
            ]),
        );
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "SessionIdCheckTest|DuplicateSequenceCheckTest|ChainLengthCheckTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

/**
 * One evidence problem, as it is persisted and as it appears in the audit log.
 *
 * The shape is the spec's (section 10) and is read by a third party, so the
 * field names are wire names, not PHP names.
 */
final readonly class ProtocolViolation
{
    public function __construct(
        public string $timestamp,
        public string $violationType,
        public ?string $messageId,
        public string $description,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'violation_type' => $this->violationType,
            'message_id' => $this->messageId,
            'description' => $this->description,
        ];
    }

    /**
     * Reads a persisted row back. Returns null rather than throwing: a row we
     * cannot read must not take down a records request for the whole session.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $timestamp = $row['timestamp'] ?? null;
        $type = $row['violation_type'] ?? null;
        $description = $row['description'] ?? null;
        $messageId = $row['message_id'] ?? null;

        if (!\is_string($timestamp) || !\is_string($type) || !\is_string($description)) {
            return null;
        }

        return new self($timestamp, $type, \is_string($messageId) ? $messageId : null, $description);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;

/**
 * One reason not to counter-sign into a chain.
 *
 * Implementations are registered in order (services.php) and run cheapest
 * first: every local comparison before anything that touches the network.
 */
interface EvidenceCheckInterface
{
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation;
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use Override;

/**
 * The session id must be DERIVED from the quote, never accepted from the
 * counterparty: SessionId::forQuote is what makes emission idempotent, and
 * adopting a foreign id would file our signed evidence under a session we did
 * not open.
 */
final readonly class SessionIdCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $expected = SessionId::forQuote($snapshot->identity->quoteId);
        $claimed = $chain->claimedSessionId();

        if ($claimed === $expected) {
            return null;
        }

        return new ProtocolViolation(
            timestamp: $at->format(\DATE_ATOM),
            violationType: 'session_id_mismatch',
            messageId: $chain->acts()[0]?->messageId(),
            description: \sprintf('chain declares session %s, expected %s for this quote', $claimed ?? 'none', $expected),
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use Override;

/**
 * A sequence number claimed more than once.
 *
 * Both acts survived — role-suffixed keys cannot collide — so nothing is lost.
 * But the sequence is ambiguous, and signing into it would attest an order we
 * cannot defend. No single act is at fault, hence no message id.
 */
final readonly class DuplicateSequenceCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $duplicates = $chain->duplicateSequences();
        if ($duplicates === []) {
            return null;
        }

        return new ProtocolViolation(
            timestamp: $at->format(\DATE_ATOM),
            violationType: 'duplicate_sequence',
            messageId: null,
            description: \sprintf('sequence number(s) %s claimed more than once', implode(', ', $duplicates)),
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use Override;

/**
 * The chain is longer than ActChain will read.
 *
 * Not one of the four ported checks: it reports OUR read cap. Signing into a
 * chain we could only read part of would attest a position we cannot compute,
 * so this refuses instead — and the record says why.
 */
final readonly class ChainLengthCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        if (!$chain->exceedsLengthCap()) {
            return null;
        }

        return new ProtocolViolation(
            timestamp: $at->format(\DATE_ATOM),
            violationType: 'chain_length_exceeded',
            messageId: null,
            description: \sprintf('chain carries more than %d acts, which is more than this agent reads', ActChain::MAX_ACTS),
        );
    }
}
```

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "SessionIdCheckTest|DuplicateSequenceCheckTest|ChainLengthCheckTest"`
Expected: PASS (5 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Check tests/Unit/Protocol
git commit -m "feat(protocol): protocol violations and the local evidence checks"
```

---

### Task 10: The buyer terms cross-check

**Files:**
- Create: `src/Protocol/Check/BuyerTermsCheck.php`
- Test: `tests/Unit/Protocol/Check/BuyerTermsCheckTest.php`

**Interfaces:**
- Consumes: `ActChain`, `Act::lineQuantities()`, `QuoteSnapshot`.
- Produces: `BuyerTermsCheck` implementing `EvidenceCheckInterface`, violation type `act_terms_mismatch`.

Scoped to **structure** — line identity and quantity. Prices are deliberately not
compared: the buyer's ask and our offer legitimately differ, and the two sides
quote in different price spaces on a gross channel.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\BuyerTermsCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class BuyerTermsCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesWhenTheActDescribesTheRecordedQuote(): void
    {
        self::assertNull(self::run(quantityOnAct: 10, quantityOnQuote: 10));
    }

    public function testItReportsAQuantityTheQuoteDoesNotRecord(): void
    {
        $violation = self::run(quantityOnAct: 25, quantityOnQuote: 10);

        self::assertNotNull($violation);
        self::assertSame('act_terms_mismatch', $violation->violationType);
        self::assertStringContainsString('25', $violation->description);
        self::assertStringContainsString('10', $violation->description);
    }

    public function testItReportsALineTheQuoteDoesNotHave(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = ProtocolFixtures::buyerAct(1, $session);
        $act['terms']['line_items'][0]['id'] = 'line-ghost';

        $violation = (new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => $act]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNotNull($violation);
        self::assertStringContainsString('line-ghost', $violation->description);
    }

    public function testItIgnoresOurOwnActs(): void
    {
        // Our own act legitimately carries the terms we are about to offer,
        // which need not equal what is on the quote yet.
        $session = SessionId::forQuote(self::QUOTE_ID);
        $ours = ProtocolFixtures::sellerAct(1, $session);
        $ours['terms']['line_items'][0]['quantity'] = 999;

        self::assertNull((new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Seller) => $ours]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        ));
    }

    public function testItIgnoresPriceDifferences(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = ProtocolFixtures::buyerAct(1, $session);
        $act['terms']['line_items'][0]['unit_price'] = 1;
        $act['terms']['total_value'] = 1;

        self::assertNull((new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => $act]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        ));
    }

    private static function run(int $quantityOnAct, int $quantityOnQuote): ?\MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = ProtocolFixtures::buyerAct(1, $session);
        $act['terms']['line_items'][0]['quantity'] = $quantityOnAct;

        return (new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => $act]),
            ProtocolFixtures::snapshot(self::QUOTE_ID, quantity: $quantityOnQuote),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter BuyerTermsCheckTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use Override;

/**
 * Does the counterparty's act describe the quote Shopware actually recorded?
 *
 * Acts are evidence, never a second input path: the engine reads the Shopware
 * snapshot, and an act that disagrees with it is a desync or a forgery attempt,
 * so we refuse to counter-sign into that chain.
 *
 * Scoped to STRUCTURE — line identity and quantity. Prices are deliberately
 * not compared: the buyer's ask and our offer legitimately differ, and the two
 * sides quote in different price spaces on a gross channel.
 * ponytail: structural check only. Add a price comparison if a desync ever gets
 * past this and the price spaces have been unified first.
 */
final readonly class BuyerTermsCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $recorded = [];
        foreach ($snapshot->content->lines as $line) {
            /** @var QuoteLineSnapshot $line */
            $recorded[$line->identity->lineItemId] = $line->quantity;
        }

        foreach ($chain->buyerActs($sellerDid) as $act) {
            $violation = $this->crossCheck($act, $recorded, $at);
            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $recorded
     */
    private function crossCheck(Act $act, array $recorded, \DateTimeImmutable $at): ?ProtocolViolation
    {
        foreach ($act->lineQuantities() as $id => $quantity) {
            if (!\array_key_exists($id, $recorded)) {
                return $this->violation($act, $at, \sprintf('act references line %s, which the quote does not have', $id));
            }

            if ($recorded[$id] !== $quantity) {
                return $this->violation($act, $at, \sprintf('act claims quantity %d for line %s, quote records %d', $quantity, $id, $recorded[$id]));
            }
        }

        return null;
    }

    private function violation(Act $act, \DateTimeImmutable $at, string $description): ProtocolViolation
    {
        return new ProtocolViolation(
            timestamp: $at->format(\DATE_ATOM),
            violationType: 'act_terms_mismatch',
            messageId: $act->messageId(),
            description: $description,
        );
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter BuyerTermsCheckTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol
composer run lint
git add src/Protocol/Check tests/Unit/Protocol/Check
git commit -m "feat(protocol): cross-check counterparty acts against the recorded quote"
```

---

### Task 11: did:web resolution

**Files:**
- Create: `src/Protocol/Did/DidWebUrl.php`, `src/Protocol/Did/DidWebResolver.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Did/DidWebUrlTest.php`, `tests/Unit/Protocol/Did/DidWebResolverTest.php`

**Interfaces:**
- Consumes: `GuzzleHttp\ClientInterface`, `Ucp\Sdk\Service\SigningKeyManagerInterface`, PSR-3 logger.
- Produces: `DidWebUrl::forDid(string $did): ?string`, `DidWebUrl::didOf(string $verificationMethod): ?string`; `DidWebResolver::__construct(ClientInterface $client, SigningKeyManagerInterface $keys, LoggerInterface $logger)`, `->publicKeyPemFor(string $verificationMethod): ?string`.

**Every failure path returns null**, never throws: an unresolvable counterparty
DID means we cannot verify their act, which is an evidence problem, not a reason
to stop servicing the quote. `publicKeyPemFor` memoizes per instance.
**ponytail: process-lifetime memo, no TTL.** Add one if a counterparty starts
rotating keys mid-session.

The JWK found in a DID document is normalized before it reaches the SDK:
`PublicSigningKey::fromJwk()` requires `kid`, `alg`, `use` and `crv`, which real
DID documents routinely omit. We fill `kid` from the verification method's
fragment, `alg: ES256`, `use: sig`, and reject anything whose `crv` is not
`P-256` — a P-384 or Ed25519 counterparty key is an unverifiable act, not a
crash.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use MerchantQuoteAgentPlugin\Protocol\Did\DidWebUrl;
use PHPUnit\Framework\TestCase;

final class DidWebUrlTest extends TestCase
{
    public function testItResolvesAHostOnlyDidToTheWellKnownPath(): void
    {
        self::assertSame('https://buyer.example/.well-known/did.json', DidWebUrl::forDid('did:web:buyer.example'));
    }

    public function testItResolvesAPathDidWithoutTheWellKnownSegment(): void
    {
        // W3C did:web: path form drops /.well-known/ entirely.
        self::assertSame('https://buyer.example/agents/quote/did.json', DidWebUrl::forDid('did:web:buyer.example:agents:quote'));
    }

    public function testItDecodesAPercentEncodedAuthority(): void
    {
        self::assertSame('https://buyer.example:8443/.well-known/did.json', DidWebUrl::forDid('did:web:buyer.example%3A8443'));
    }

    public function testItRejectsAnythingThatIsNotDidWeb(): void
    {
        self::assertNull(DidWebUrl::forDid('did:key:z6Mk'));
        self::assertNull(DidWebUrl::forDid('https://buyer.example'));
        self::assertNull(DidWebUrl::forDid('did:web:'));
    }

    public function testItSplitsTheDidOffAVerificationMethod(): void
    {
        self::assertSame('did:web:buyer.example', DidWebUrl::didOf('did:web:buyer.example#key-1'));
        self::assertNull(DidWebUrl::didOf('did:web:buyer.example'));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

final class DidWebResolverTest extends TestCase
{
    private const METHOD = 'did:web:buyer.example#key-1';

    public function testItReturnsThePemForTheNamedVerificationMethod(): void
    {
        $resolver = self::resolver([new Response(200, [], self::document())]);

        $pem = $resolver->publicKeyPemFor(self::METHOD);

        self::assertIsString($pem);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $pem);
    }

    public function testItMemoizesSoOneChainCostsOneRequest(): void
    {
        $handler = new MockHandler([new Response(200, [], self::document())]);
        $resolver = new DidWebResolver(new Client(['handler' => HandlerStack::create($handler)]), new DefaultSigningKeyManager(), new NullLogger());

        $resolver->publicKeyPemFor(self::METHOD);
        $resolver->publicKeyPemFor(self::METHOD);

        // A second HTTP call would have thrown: the queue holds one response.
        self::assertCount(0, $handler);
    }

    public function testItReturnsNullWhenTheDocumentDoesNotListTheMethod(): void
    {
        $resolver = self::resolver([new Response(200, [], self::document(id: 'did:web:buyer.example#other'))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullOnATransportFailure(): void
    {
        $resolver = self::resolver([new Response(404)]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullForAnUnsupportedCurve(): void
    {
        $resolver = self::resolver([new Response(200, [], self::document(curve: 'P-384'))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullForANonDidWebMethod(): void
    {
        $resolver = self::resolver([]);

        self::assertNull($resolver->publicKeyPemFor('did:key:z6Mk#1'));
    }

    /** @param list<Response> $responses */
    private static function resolver(array $responses): DidWebResolver
    {
        return new DidWebResolver(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            new DefaultSigningKeyManager(),
            new NullLogger(),
        );
    }

    private static function document(string $id = 'did:web:buyer.example#key-1', string $curve = 'P-256'): string
    {
        // A minimal DID document as a counterparty really publishes one: no
        // kid, no alg, no use — which is why the resolver normalizes.
        return json_encode([
            '@context' => ['https://www.w3.org/ns/did/v1'],
            'id' => 'did:web:buyer.example',
            'verificationMethod' => [[
                'id' => $id,
                'type' => 'JsonWebKey2020',
                'controller' => 'did:web:buyer.example',
                'publicKeyJwk' => [
                    'kty' => 'EC',
                    'crv' => $curve,
                    'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                    'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
                ],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "DidWebUrlTest|DidWebResolverTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

/**
 * `did:web` → the URL its document is served from (W3C did:web method).
 *
 * Host-only DIDs resolve under `/.well-known/`; a DID with path segments
 * resolves at those segments and drops `/.well-known/` entirely, which is the
 * part of the method most implementations get wrong.
 */
final class DidWebUrl
{
    private const PREFIX = 'did:web:';

    private function __construct() {}

    public static function forDid(string $did): ?string
    {
        if (!str_starts_with($did, self::PREFIX)) {
            return null;
        }

        $identifier = substr($did, \strlen(self::PREFIX));
        if ($identifier === '') {
            return null;
        }

        $segments = explode(':', $identifier);
        $authority = rawurldecode((string) array_shift($segments));
        if ($authority === '') {
            return null;
        }

        return $segments === []
            ? \sprintf('https://%s/.well-known/did.json', $authority)
            : \sprintf('https://%s/%s/did.json', $authority, implode('/', array_map(rawurldecode(...), $segments)));
    }

    /** The DID part of a verification method (`did:web:host#key-1`). */
    public static function didOf(string $verificationMethod): ?string
    {
        $position = strpos($verificationMethod, '#');

        return $position === false || $position === 0 ? null : substr($verificationMethod, 0, $position);
    }

    /** The fragment, used as the `kid` a DID document usually omits. */
    public static function fragmentOf(string $verificationMethod): string
    {
        $position = strpos($verificationMethod, '#');

        return $position === false ? $verificationMethod : substr($verificationMethod, $position + 1);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/**
 * Resolves a counterparty's `did:web` verification key to a PEM.
 *
 * Every failure path returns null rather than throwing: an unresolvable buyer
 * DID means we cannot verify their act, which is an evidence problem handled by
 * BuyerSignatureCheck, not a reason to stop servicing the quote.
 *
 * JWK → PEM is the SDK's (`PublicSigningKey::fromJwk`), but that method demands
 * `kid`, `alg` and `use`, which real DID documents omit — so the JWK is
 * normalized first, and only P-256 is accepted.
 *
 * ponytail: process-lifetime memo, no TTL. Add one if a counterparty starts
 * rotating keys mid-session.
 */
final class DidWebResolver
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_DOCUMENT_BYTES = 262144;

    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly SigningKeyManagerInterface $keys,
        private readonly LoggerInterface $logger,
    ) {}

    public function publicKeyPemFor(string $verificationMethod): ?string
    {
        if (\array_key_exists($verificationMethod, $this->memo)) {
            return $this->memo[$verificationMethod];
        }

        return $this->memo[$verificationMethod] = $this->resolve($verificationMethod);
    }

    private function resolve(string $verificationMethod): ?string
    {
        $did = DidWebUrl::didOf($verificationMethod);
        $url = $did === null ? null : DidWebUrl::forDid($did);
        if ($url === null) {
            return null;
        }

        $document = $this->fetch($url);
        $jwk = $document === null ? null : $this->pickJwk($document, $verificationMethod);

        return $jwk === null ? null : $this->toPem($jwk, $verificationMethod);
    }

    /** @return array<array-key, mixed>|null */
    private function fetch(string $url): ?array
    {
        try {
            $response = $this->client->request('GET', $url, [
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        } catch (\Throwable $error) {
            $this->logger->info('A2CN could not fetch a did:web document.', ['url' => $url, 'exception' => $error]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody()->read(self::MAX_DOCUMENT_BYTES + 1);
        if (\strlen($body) > self::MAX_DOCUMENT_BYTES) {
            $this->logger->info('A2CN refused an oversized did:web document.', ['url' => $url]);

            return null;
        }

        $decoded = json_decode($body, associative: true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<string, mixed>|null
     */
    private function pickJwk(array $document, string $verificationMethod): ?array
    {
        $methods = $document['verificationMethod'] ?? null;
        if (!\is_array($methods)) {
            return null;
        }

        foreach ($methods as $entry) {
            if (!\is_array($entry) || ($entry['id'] ?? null) !== $verificationMethod) {
                continue;
            }

            $jwk = $entry['publicKeyJwk'] ?? null;

            /** @var array<string, mixed>|null $jwk */
            return \is_array($jwk) ? $jwk : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function toPem(array $jwk, string $verificationMethod): ?string
    {
        if (($jwk['crv'] ?? null) !== 'P-256') {
            $this->logger->info('A2CN found a did:web key on an unsupported curve.', [
                'verificationMethod' => $verificationMethod,
            ]);

            return null;
        }

        $normalized = array_map(
            static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
            array_filter($jwk, static fn (mixed $value): bool => \is_scalar($value)),
        );
        $normalized['kid'] = DidWebUrl::fragmentOf($verificationMethod);
        $normalized['alg'] = 'ES256';
        $normalized['use'] = 'sig';
        $normalized['kty'] = 'EC';

        try {
            return $this->keys->publicKeyFromJwk($normalized)->publicKeyPem;
        } catch (\Throwable $error) {
            $this->logger->info('A2CN could not read a did:web public key.', [
                'verificationMethod' => $verificationMethod,
                'exception' => $error,
            ]);

            return null;
        }
    }
}
```

Register it, wiring Guzzle explicitly (the plugin has no Guzzle service by default):

```php
$services->set(DidWebResolver::class)
    ->args([service('merchant_quote_agent.a2cn.http_client'), service(SigningKeyManagerInterface::class), service('logger')]);
$services->set('merchant_quote_agent.a2cn.http_client', \GuzzleHttp\Client::class);
```

If `SigningKeyManagerInterface` is not a public alias in this SDK version, register
`Ucp\Sdk\Internal\Security\DefaultSigningKeyManager` under our own service id and
inject that instead — note which one you used in the commit message.

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "DidWebUrlTest|DidWebResolverTest"`
Expected: PASS (11 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Did tests/Unit/Protocol/Did src/Resources/config/services.php
git commit -m "feat(protocol): resolve counterparty did:web verification keys"
```

---

### Task 12: The signature check and the inspector

**Files:**
- Create: `src/Protocol/Check/BuyerSignatureCheck.php`, `src/Protocol/Check/EvidenceInspector.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Check/BuyerSignatureCheckTest.php`, `tests/Unit/Protocol/Check/EvidenceInspectorTest.php`

**Interfaces:**
- Consumes: `DidWebResolver`, `ProtocolHash`, `SignedView`, `CompactJws`.
- Produces: `BuyerSignatureCheck::__construct(DidWebResolver $resolver, ProtocolHash $hash)` implementing `EvidenceCheckInterface` (type `buyer_act_unverified`); `EvidenceInspector::__construct(iterable $checks)`, `->firstViolation(ActChain $chain, QuoteSnapshot $snapshot, string $sellerDid, \DateTimeImmutable $at): ?ProtocolViolation`.

Verification is three steps, and all three matter: resolve the key, **recompute
the hash from the act's own signed view and compare it to the hash the act
carries** (otherwise a valid signature over a different object would pass), then
verify the JWS and require its payload to be that same hash.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Check\BuyerSignatureCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class BuyerSignatureCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesForAProperlySignedBuyerAct(): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();
        $chain = self::chainWith(self::signedBuyerAct($private));

        self::assertNull(self::check($public)->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at()));
    }

    public function testItReportsAnActWhoseKeyDoesNotResolve(): void
    {
        ['private' => $private] = self::keyPair();
        $chain = self::chainWith(self::signedBuyerAct($private));

        $violation = self::check(null)->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at());

        self::assertNotNull($violation);
        self::assertSame('buyer_act_unverified', $violation->violationType);
    }

    public function testItReportsAnActSignedByADifferentKey(): void
    {
        ['private' => $private] = self::keyPair();
        ['public' => $other] = self::keyPair();
        $chain = self::chainWith(self::signedBuyerAct($private));

        self::assertNotNull(self::check($other)->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at()));
    }

    public function testItReportsAnActWhoseHashDoesNotCoverIt(): void
    {
        // A valid signature over a hash that is not this act's hash: the
        // signature verifies, the binding does not.
        ['private' => $private, 'public' => $public] = self::keyPair();
        $act = self::signedBuyerAct($private);
        $act['timestamp'] = '2026-01-01T00:00:00Z';

        $violation = self::check($public)->check(self::chainWith($act), ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at());

        self::assertNotNull($violation);
        self::assertStringContainsString('hash', $violation->description);
    }

    public function testItIgnoresOurOwnActs(): void
    {
        $chain = self::chainWith(ProtocolFixtures::sellerAct(1, SessionId::forQuote(self::QUOTE_ID)), ActRole::Seller);

        self::assertNull(self::check(null)->check($chain, ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::SELLER, ProtocolFixtures::at()));
    }

    /** @param array<string, mixed> $act */
    private static function chainWith(array $act, ActRole $role = ActRole::Buyer): ActChain
    {
        $session = SessionId::forQuote(self::QUOTE_ID);

        return ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, $role) => $act]);
    }

    /** @return array<string, mixed> */
    private static function signedBuyerAct(string $privateKeyPem): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $act = ProtocolFixtures::buyerAct(1, SessionId::forQuote(self::QUOTE_ID));
        unset($act['protocol_act_hash'], $act['protocol_act_signature']);

        $parsed = Act::fromArray($act + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        self::assertNotNull($parsed);
        $digest = $hash->of(SignedView::of($parsed));

        $act['protocol_act_hash'] = $digest;
        $act['protocol_act_signature'] = CompactJws::sign($digest, $privateKeyPem);

        return $act;
    }

    private static function check(?string $publicKeyPem): BuyerSignatureCheck
    {
        $resolver = new class($publicKeyPem) extends DidWebResolver {
            public function __construct(private readonly ?string $pem)
            {
            }

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return $this->pem;
            }
        };

        return new BuyerSignatureCheck($resolver, new ProtocolHash(new DefaultJsonCanonicalization()));
    }

    /** @return array{private: string, public: string} */
    private static function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($resource);
        $private = '';
        self::assertTrue(openssl_pkey_export($resource, $private));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $private, 'public' => $details['key']];
    }
}
```

**Note for the implementer:** the anonymous subclass above only works if
`DidWebResolver` is non-final with a non-private `publicKeyPemFor`, and its
constructor is not called. If you prefer to keep `DidWebResolver` final, extract
a one-method interface (`DidKeyResolverInterface`) in this task and depend on
that — the codebase already does this for `SalesChannelDomainUrlReader` ("Not
`final`: the controller test substitutes it"). Either choice is fine; make it,
and make the test match.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceCheckInterface;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class EvidenceInspectorTest extends TestCase
{
    public function testItReturnsTheFirstViolationAndStops(): void
    {
        $second = self::check('second');
        $inspector = new EvidenceInspector([self::check('first'), $second]);

        $violation = $inspector->firstViolation(ActChain::read(null), ProtocolFixtures::snapshot(), ProtocolFixtures::SELLER, ProtocolFixtures::at());

        self::assertSame('first', $violation?->violationType);
        // Ordering is the point: the expensive network check must not run when
        // a local comparison already refused the chain.
        self::assertFalse($second->ran);
    }

    public function testItPassesWhenEveryCheckPasses(): void
    {
        $inspector = new EvidenceInspector([self::check(null), self::check(null)]);

        self::assertNull($inspector->firstViolation(ActChain::read(null), ProtocolFixtures::snapshot(), ProtocolFixtures::SELLER, ProtocolFixtures::at()));
    }

    private static function check(?string $type): EvidenceCheckInterface
    {
        return new class($type) implements EvidenceCheckInterface {
            public bool $ran = false;

            public function __construct(private readonly ?string $type)
            {
            }

            public function check(ActChain $chain, QuoteSnapshot $snapshot, string $sellerDid, \DateTimeImmutable $at): ?ProtocolViolation
            {
                $this->ran = true;

                return $this->type === null ? null : new ProtocolViolation($at->format(\DATE_ATOM), $this->type, null, 'test');
            }
        };
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "BuyerSignatureCheckTest|EvidenceInspectorTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use Override;

/**
 * The first counterparty act that does not verify against its own did:web key.
 *
 * Runs last of all the checks: it is the only one that touches the network.
 *
 * Three steps, all load-bearing:
 *   1. resolve the key the act names,
 *   2. recompute the hash from the act's own signed view and compare — without
 *      this, a signature that is valid over some OTHER object would pass,
 *   3. verify the JWS and require its payload to be exactly that hash.
 */
final readonly class BuyerSignatureCheck implements EvidenceCheckInterface
{
    public function __construct(
        private DidWebResolver $resolver,
        private ProtocolHash $hash,
    ) {}

    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($chain->buyerActs($sellerDid) as $act) {
            $reason = $this->reasonItDoesNotVerify($act);
            if ($reason !== null) {
                return new ProtocolViolation(
                    timestamp: $at->format(\DATE_ATOM),
                    violationType: 'buyer_act_unverified',
                    messageId: $act->messageId(),
                    description: \sprintf('act %s %s', $act->messageId(), $reason),
                );
            }
        }

        return null;
    }

    private function reasonItDoesNotVerify(Act $act): ?string
    {
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
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;

/**
 * The first evidence problem that means we must not counter-sign into this
 * chain, or null when it is clean.
 *
 * Order comes from the service definition and is normative: every local
 * comparison before anything that resolves a did:web document over the network.
 */
final readonly class EvidenceInspector
{
    /** @param iterable<EvidenceCheckInterface> $checks */
    public function __construct(
        private iterable $checks,
    ) {}

    public function firstViolation(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($this->checks as $check) {
            $violation = $check->check($chain, $snapshot, $sellerDid, $at);
            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }
}
```

Register the inspector with the order spelled out — it is the specification, so
it belongs in code a reviewer reads, not in a tag priority number:

```php
$services->set(SessionIdCheck::class);
$services->set(DuplicateSequenceCheck::class);
$services->set(ChainLengthCheck::class);
$services->set(BuyerTermsCheck::class);
$services->set(BuyerSignatureCheck::class);
$services->set(EvidenceInspector::class)->args([[
    service(SessionIdCheck::class),
    service(DuplicateSequenceCheck::class),
    service(ChainLengthCheck::class),
    service(BuyerTermsCheck::class),
    service(BuyerSignatureCheck::class),
]]);
```

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "BuyerSignatureCheckTest|EvidenceInspectorTest"`
Expected: PASS (7 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Check tests/Unit/Protocol/Check src/Resources/config/services.php
git commit -m "feat(protocol): verify counterparty act signatures, ordered inspection"
```

---

### Task 13: The evidence mirror

**Files:**
- Create: `src/Migration/Migration1788600000CreateA2cnEvidence.php`, `src/Protocol/Store/ActRecord.php`, `src/Protocol/Store/ApprovalReceipt.php`, `src/Protocol/Store/ActStoreInterface.php`, `src/Protocol/Store/DbalActStore.php`
- Modify: `src/MerchantQuoteAgentPlugin.php` (uninstall drop), `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Store/ApprovalReceiptTest.php`, `tests/Integration/A2cnActStoreTest.php`

**Interfaces:**
- Consumes: `Doctrine\DBAL\Connection`, `Act`, `ProtocolViolation`.
- Produces:
  - `ActRecord::__construct(string $sessionId, string $quoteId, int $sequence, Act $act)`
  - `ApprovalReceipt::__construct(string $receiptId, string $offerHash, string $thresholdCrossed, string $approvedAt)`, `->toArray()`, `::fromArray(array): ?self`
  - `ActStoreInterface`: `append(ActRecord $record): void`, `appendViolation(string $sessionId, ProtocolViolation $violation): void`, `appendReceipt(string $sessionId, ApprovalReceipt $receipt): void`, `listBySession(string $sessionId): list<Act>`, `listViolations(string $sessionId): list<ProtocolViolation>`, `listReceipts(string $sessionId): list<ApprovalReceipt>`, `quoteIdForSession(string $sessionId): ?string`
  - `DbalActStore` implementing it.

Why our own copy at all: **Shopware is custodian of the authoritative chain**,
which means it could in principle omit an act. This mirror is what lets us show
the omission, and it is what the records endpoints read so they never depend on
a live quote round trip.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use PHPUnit\Framework\TestCase;

final class ApprovalReceiptTest extends TestCase
{
    public function testItRoundTripsThroughItsWireShape(): void
    {
        $receipt = new ApprovalReceipt('session:hash', 'hash', 'discount above 15%', '2026-09-04T10:00:00+00:00');

        self::assertSame([
            'approval_receipt_id' => 'session:hash',
            'offer_hash' => 'hash',
            'threshold_crossed' => 'discount above 15%',
            'approved_at' => '2026-09-04T10:00:00+00:00',
        ], $receipt->toArray());

        self::assertEquals($receipt, ApprovalReceipt::fromArray($receipt->toArray()));
    }

    public function testItRefusesAnUnreadableRow(): void
    {
        self::assertNull(ApprovalReceipt::fromArray(['offer_hash' => 'hash']));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Protocol\Store\DbalActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * The mirror against a real MySQL: the idempotent primary key and the JSON
 * round trip are properties of the schema, not of PHP.
 */
final class A2cnActStoreTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const QUOTE_ID = '0189d1c8f4f27c3ea0d4a5b6c7d8e9f0';

    public function testAppendingTheSameActTwiceIsANoOp(): void
    {
        $store = new DbalActStore(self::connection());
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = Act::fromArray(ProtocolFixtures::sellerAct(1, $session));
        self::assertNotNull($act);

        $store->append(new ActRecord($session, self::QUOTE_ID, 1, $act));
        $store->append(new ActRecord($session, self::QUOTE_ID, 1, $act));

        self::assertCount(1, $store->listBySession($session));
        self::assertSame(self::QUOTE_ID, $store->quoteIdForSession($session));
    }

    public function testItReadsActsBackInSequenceOrder(): void
    {
        $store = new DbalActStore(self::connection());
        $session = SessionId::forQuote(self::QUOTE_ID);
        foreach ([2, 1] as $sequence) {
            $act = Act::fromArray(ProtocolFixtures::sellerAct($sequence, $session));
            self::assertNotNull($act);
            $store->append(new ActRecord($session, self::QUOTE_ID, $sequence, $act));
        }

        self::assertSame([1, 2], array_map(static fn (Act $act): int => $act->sequenceNumber(), $store->listBySession($session)));
    }

    public function testItStoresViolationsAndReceipts(): void
    {
        $store = new DbalActStore(self::connection());
        $session = SessionId::forQuote(self::QUOTE_ID);

        $store->appendViolation($session, new ProtocolViolation('2026-09-04T10:00:00+00:00', 'duplicate_sequence', null, 'sequence 1 twice'));
        $store->appendReceipt($session, new ApprovalReceipt($session . ':hash', 'hash', 'reason', '2026-09-04T10:00:00+00:00'));
        $store->appendReceipt($session, new ApprovalReceipt($session . ':hash', 'hash', 'reason', '2026-09-04T10:00:00+00:00'));

        self::assertCount(1, $store->listViolations($session));
        self::assertSame('duplicate_sequence', $store->listViolations($session)[0]->violationType);
        self::assertCount(1, $store->listReceipts($session));
    }

    public function testAnUnknownSessionIsEmptyRatherThanAnError(): void
    {
        $store = new DbalActStore(self::connection());

        self::assertSame([], $store->listBySession(SessionId::forQuote('ffffffffffffffffffffffffffffffff')));
        self::assertNull($store->quoteIdForSession('not-a-session'));
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
```

Follow whatever base class the existing integration tests use — check
`tests/Integration/UpdateQuoteTest.php` first and match it (it already solved
booting the kernel against the live shop). If it uses a different trait or a
`KernelTestCase`, use that instead of `IntegrationTestBehaviour`.

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter ApprovalReceiptTest`
Expected: FAIL — class not found. (The integration test runs in Step 4 via
`composer run test:integration`; it needs the migration.)

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Our own copy of the A2CN act chain, plus the evidence that is only ours: the
 * protocol violations we observed and the human approval receipts.
 *
 * Hand-written like the decision and pending-authorization tables, and must
 * stay in step with DbalActStore.
 *
 * Ids are stored as the strings the module works in — the session id in its
 * canonical UUIDv5 form, the quote id as Shopware's 32-char hex — because
 * nothing here joins a DAL entity, and the rows are read by a human auditing a
 * session.
 */
class Migration1788600000CreateA2cnEvidence extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1788600000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        // PRIMARY KEY (session_id, sequence) is what makes append() idempotent:
        // re-mirroring an act we already hold must be free, because the emitter
        // mirrors the whole known chain on every observation.
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_a2cn_act` (
                    `session_id` CHAR(36)      NOT NULL,
                    `quote_id`   CHAR(32)      NOT NULL,
                    `sequence`   INT UNSIGNED  NOT NULL,
                    `act`        JSON          NOT NULL,
                    `created_at` DATETIME(3)   NOT NULL,
                    PRIMARY KEY (`session_id`, `sequence`),
                    KEY `idx.a2cn_act.quote_id` (`quote_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_a2cn_violation` (
                    `id`         BINARY(16)  NOT NULL,
                    `session_id` CHAR(36)    NOT NULL,
                    `violation`  JSON        NOT NULL,
                    `created_at` DATETIME(3) NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.a2cn_violation.session_id` (`session_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_a2cn_receipt` (
                    `session_id` CHAR(36)    NOT NULL,
                    `offer_hash` VARCHAR(64) NOT NULL,
                    `receipt`    JSON        NOT NULL,
                    `created_at` DATETIME(3) NOT NULL,
                    PRIMARY KEY (`session_id`, `offer_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The tables are dropped on uninstall.
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/** One row of the mirror. */
final readonly class ActRecord
{
    public function __construct(
        public string $sessionId,
        public string $quoteId,
        public int $sequence,
        public Act $act,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

/**
 * A human approval receipt (spec section 10): what records that a person stood
 * behind terms the agent itself would have escalated.
 *
 * The signature on the act attests the ORGANISATION, never an individual — so
 * this receipt carries a threshold and a time, not a name.
 */
final readonly class ApprovalReceipt
{
    public function __construct(
        public string $receiptId,
        public string $offerHash,
        public string $thresholdCrossed,
        public string $approvedAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'approval_receipt_id' => $this->receiptId,
            'offer_hash' => $this->offerHash,
            'threshold_crossed' => $this->thresholdCrossed,
            'approved_at' => $this->approvedAt,
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $id = $row['approval_receipt_id'] ?? null;
        $hash = $row['offer_hash'] ?? null;
        $threshold = $row['threshold_crossed'] ?? null;
        $approvedAt = $row['approved_at'] ?? null;

        if (!\is_string($id) || !\is_string($hash) || !\is_string($threshold) || !\is_string($approvedAt)) {
            return null;
        }

        return new self($id, $hash, $threshold, $approvedAt);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;

/**
 * The evidence mirror.
 *
 * Shopware is custodian of the authoritative act chain, which means it could in
 * principle omit an act. This copy is what lets us show the omission, and it is
 * what the records endpoints read so a records request never depends on a live
 * quote round trip.
 */
interface ActStoreInterface
{
    /** Idempotent on (session, sequence). */
    public function append(ActRecord $record): void;

    public function appendViolation(string $sessionId, ProtocolViolation $violation): void;

    /** Idempotent on (session, offer hash). */
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void;

    /** @return list<Act> in sequence order */
    public function listBySession(string $sessionId): array;

    /** @return list<ProtocolViolation> */
    public function listViolations(string $sessionId): array;

    /** @return list<ApprovalReceipt> */
    public function listReceipts(string $sessionId): array;

    public function quoteIdForSession(string $sessionId): ?string;
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use Override;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The mirror over three hand-written tables. Direct DBAL rather than the DAL:
 * nothing here is administrated through Shopware's admin API, and the decision
 * record and pending-authorization stores set the same precedent.
 *
 * Every read tolerates an unreadable row by skipping it: a records request for
 * a whole session must not fail because one row cannot be decoded.
 */
final readonly class DbalActStore implements ActStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function append(ActRecord $record): void
    {
        // ON DUPLICATE KEY UPDATE of a column to its own value: the whole point
        // is that a re-mirror is free, not an error.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO `merchant_quote_agent_a2cn_act` (`session_id`, `quote_id`, `sequence`, `act`, `created_at`)
                VALUES (:session_id, :quote_id, :sequence, :act, :created_at)
                ON DUPLICATE KEY UPDATE `session_id` = `session_id`
                SQL,
            [
                'session_id' => $record->sessionId,
                'quote_id' => $record->quoteId,
                'sequence' => $record->sequence,
                'act' => json_encode($record->act->raw(), \JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        );
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function appendViolation(string $sessionId, ProtocolViolation $violation): void
    {
        $this->connection->insert('merchant_quote_agent_a2cn_violation', [
            'id' => Uuid::randomBytes(),
            'session_id' => $sessionId,
            'violation' => json_encode($violation->toArray(), \JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO `merchant_quote_agent_a2cn_receipt` (`session_id`, `offer_hash`, `receipt`, `created_at`)
                VALUES (:session_id, :offer_hash, :receipt, :created_at)
                ON DUPLICATE KEY UPDATE `session_id` = `session_id`
                SQL,
            [
                'session_id' => $sessionId,
                'offer_hash' => $receipt->offerHash,
                'receipt' => json_encode($receipt->toArray(), \JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        );
    }

    /**
     * @return list<Act>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function listBySession(string $sessionId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT `act` FROM `merchant_quote_agent_a2cn_act` WHERE `session_id` = :session_id ORDER BY `sequence` ASC',
            ['session_id' => $sessionId],
        );

        $acts = [];
        foreach ($rows as $row) {
            $act = \is_string($row) ? Act::fromArray(self::decode($row)) : null;
            if ($act !== null) {
                $acts[] = $act;
            }
        }

        return $acts;
    }

    /**
     * @return list<ProtocolViolation>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function listViolations(string $sessionId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT `violation` FROM `merchant_quote_agent_a2cn_violation` WHERE `session_id` = :session_id ORDER BY `created_at` ASC',
            ['session_id' => $sessionId],
        );

        $violations = [];
        foreach ($rows as $row) {
            $violation = \is_string($row) ? ProtocolViolation::fromArray(self::decode($row)) : null;
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return list<ApprovalReceipt>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function listReceipts(string $sessionId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT `receipt` FROM `merchant_quote_agent_a2cn_receipt` WHERE `session_id` = :session_id ORDER BY `created_at` ASC',
            ['session_id' => $sessionId],
        );

        $receipts = [];
        foreach ($rows as $row) {
            $receipt = \is_string($row) ? ApprovalReceipt::fromArray(self::decode($row)) : null;
            if ($receipt !== null) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function quoteIdForSession(string $sessionId): ?string
    {
        // UUIDv5 is not reversible, so the mirror is the only way back from a
        // session id to its quote — which is what the records endpoints need.
        $quoteId = $this->connection->fetchOne(
            'SELECT `quote_id` FROM `merchant_quote_agent_a2cn_act` WHERE `session_id` = :session_id LIMIT 1',
            ['session_id' => $sessionId],
        );

        return \is_string($quoteId) && $quoteId !== '' ? $quoteId : null;
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, associative: true);

        return \is_array($decoded) ? $decoded : [];
    }
}
```

Register: `$services->set(ActStoreInterface::class, DbalActStore::class);`

Then add the uninstall drop to `src/MerchantQuoteAgentPlugin.php` — the plugin
class is currently empty, so this is its first lifecycle hook. #59 records that
uninstall leaves the decision table behind; do not add a fourth instance of that
bug:

```php
    #[Override]
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        foreach (['merchant_quote_agent_a2cn_receipt', 'merchant_quote_agent_a2cn_violation', 'merchant_quote_agent_a2cn_act'] as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
```

- [ ] **Step 4: Run the tests**

Run: `composer run test -- --filter ApprovalReceiptTest` — expected PASS (2 tests).
Run: `composer run test:integration -- --filter A2cnActStoreTest` — expected PASS (4 tests). This needs the migration applied; if the shop does not pick it up automatically, run the plugin refresh/update the way `scripts/shop-setup.sh` does and note the command in the commit message.

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src tests
composer run lint
git add src/Migration src/Protocol/Store src/MerchantQuoteAgentPlugin.php tests src/Resources/config/services.php
git commit -m "feat(protocol): the A2CN evidence mirror and its schema"
```

---

### Task 14: Signing key and published identity

**Files:**
- Create: `src/Protocol/Identity/A2cnSigningKey.php`, `src/Protocol/Identity/A2cnKeyStore.php`, `src/Protocol/Identity/MissingSigningKey.php`, `src/Protocol/Identity/A2cnIdentity.php`, `src/Protocol/Identity/A2cnIdentityResolver.php`
- Modify: `src/MerchantQuoteAgentPlugin.php` (generate at install/activate), `src/Resources/config/config.xml` (organization name), `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Identity/A2cnIdentityTest.php`, `tests/Integration/A2cnKeyStoreTest.php`

**Interfaces:**
- Consumes: `Shopware\Core\System\SystemConfig\SystemConfigService`, `Ucp\Sdk\Service\SigningKeyManagerInterface`, `Doctrine\DBAL\Connection`.
- Produces:
  - `A2cnSigningKey::__construct(string $kid, string $privateKeyPem, string $publicKeyPem, string $createdAt)`, `->toArray()`, `::fromArray(array): ?self`
  - `A2cnKeyStore::CONFIG_KEY = 'MerchantQuoteAgentPlugin.a2cn.signingKeyJwk'`, `->current(): A2cnSigningKey` (throws `MissingSigningKey`), `->generateIfAbsent(): A2cnSigningKey`, `->publicJwk(A2cnSigningKey $key): array<string, string>`
  - `A2cnIdentity::__construct(string $did, string $verificationMethod, string $organizationName, string $agentId)`, `A2cnIdentity::AGENT_ID`, `A2cnIdentity::DEAL_TYPES`, `A2cnIdentity::CONFORMANCE_LEVEL`, `->forHost(...)`
  - `A2cnIdentityResolver->forHost(string $host, ?string $salesChannelId): A2cnIdentity`, `->forSalesChannel(string $salesChannelId): ?A2cnIdentity`

**The key is stored outside the `config.*` namespace on purpose.** Shopware's
admin renders `MerchantQuoteAgentPlugin.config.*`; a private key must never
appear in a config form, so it lives at
`MerchantQuoteAgentPlugin.a2cn.signingKeyJwk` where the admin does not look.

**No lazy generation.** `current()` throws when the key is absent. Generating on
first use would race two web requests into two keys, and the loser's acts would
stop verifying — silent worthless evidence, which is worse than a loud refusal.
Generation happens in `install()` and again in `activate()` (so a shop that
updates into this version gets one).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Identity;

use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use PHPUnit\Framework\TestCase;

final class A2cnIdentityTest extends TestCase
{
    public function testItBuildsADidWebIdentityFromAHostAndKid(): void
    {
        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');

        self::assertSame('did:web:shop.example', $identity->did);
        self::assertSame('did:web:shop.example#key-1', $identity->verificationMethod);
        self::assertSame('Example Shop', $identity->organizationName);
        self::assertSame('merchant-quote-agent', $identity->agentId);
    }

    public function testItPercentEncodesAPortInTheAuthority(): void
    {
        // did:web encodes the colon of a port, or the DID's own segment
        // separator would swallow it.
        self::assertSame('did:web:shop.example%3A8443', A2cnIdentity::forHost('shop.example:8443', 'key-1', 'Example Shop')->did);
    }

    public function testItPublishesOneDealTypeAndTheActsConformanceLevel(): void
    {
        self::assertSame(['goods_procurement'], A2cnIdentity::DEAL_TYPES);
        self::assertSame('acts', A2cnIdentity::CONFORMANCE_LEVEL);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

final class A2cnKeyStoreTest extends TestCase
{
    use IntegrationTestBehaviour;

    protected function tearDown(): void
    {
        self::config()->delete(A2cnKeyStore::CONFIG_KEY);
    }

    public function testItThrowsRatherThanInventingAKey(): void
    {
        self::config()->delete(A2cnKeyStore::CONFIG_KEY);

        $this->expectException(MissingSigningKey::class);

        self::store()->current();
    }

    public function testItGeneratesOnceAndKeepsTheSameKey(): void
    {
        $store = self::store();

        $first = $store->generateIfAbsent();
        $second = $store->generateIfAbsent();

        self::assertSame($first->kid, $second->kid);
        self::assertSame($first->privateKeyPem, $store->current()->privateKeyPem);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $first->publicKeyPem);
    }

    public function testItPublishesAP256PublicJwk(): void
    {
        $store = self::store();
        $key = $store->generateIfAbsent();

        $jwk = $store->publicJwk($key);

        self::assertSame('EC', $jwk['kty']);
        self::assertSame('P-256', $jwk['crv']);
        self::assertSame($key->kid, $jwk['kid']);
        self::assertArrayNotHasKey('d', $jwk);
    }

    private static function store(): A2cnKeyStore
    {
        return new A2cnKeyStore(self::config(), new DefaultSigningKeyManager());
    }

    private static function config(): SystemConfigService
    {
        $config = self::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter A2cnIdentityTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

/** The installation's A2CN signing key, as stored. */
final readonly class A2cnSigningKey
{
    public function __construct(
        public string $kid,
        public string $privateKeyPem,
        public string $publicKeyPem,
        public string $createdAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'kid' => $this->kid,
            'private_key_pem' => $this->privateKeyPem,
            'public_key_pem' => $this->publicKeyPem,
            'created_at' => $this->createdAt,
        ];
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): ?self
    {
        $kid = $stored['kid'] ?? null;
        $private = $stored['private_key_pem'] ?? null;
        $public = $stored['public_key_pem'] ?? null;
        $createdAt = $stored['created_at'] ?? null;

        if (!\is_string($kid) || !\is_string($private) || !\is_string($public) || !\is_string($createdAt)) {
            return null;
        }

        return new self($kid, $private, $public, $createdAt);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

final class MissingSigningKey extends \RuntimeException {}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/**
 * The installation's A2CN signing key, in `system_config`.
 *
 * Deliberately NOT under the `MerchantQuoteAgentPlugin.config.*` prefix the
 * admin renders: a private key must never appear in a config form.
 *
 * `current()` throws when the key is absent instead of generating one. Lazy
 * generation would let two concurrent requests create two keys, and every act
 * signed with the loser would stop verifying — silent worthless evidence is
 * worse than a loud refusal. Generation happens in the plugin's install() and
 * activate() hooks, where only one process runs.
 *
 * ES256 keygen and the JWK projection are the UCP SDK's; the key material never
 * leaves this class except as a PEM to sign with or a PUBLIC jwk to publish.
 */
final readonly class A2cnKeyStore
{
    public const CONFIG_KEY = 'MerchantQuoteAgentPlugin.a2cn.signingKeyJwk';

    private const KID_PREFIX = 'a2cn-';

    public function __construct(
        private SystemConfigService $systemConfig,
        private SigningKeyManagerInterface $keys,
    ) {}

    /** @throws MissingSigningKey */
    public function current(): A2cnSigningKey
    {
        $stored = $this->systemConfig->get(self::CONFIG_KEY);
        $key = \is_array($stored) ? A2cnSigningKey::fromArray($stored) : null;

        if ($key === null) {
            throw new MissingSigningKey('No A2CN signing key is configured for this installation; reinstall or reactivate the plugin to generate one.');
        }

        return $key;
    }

    public function generateIfAbsent(): A2cnSigningKey
    {
        try {
            return $this->current();
        } catch (MissingSigningKey) {
            $generated = $this->keys->generate(self::KID_PREFIX . bin2hex(random_bytes(8)));
            $key = new A2cnSigningKey(
                kid: $generated->kid,
                privateKeyPem: $generated->privateKeyPem,
                publicKeyPem: $generated->publicKeyPem,
                createdAt: $generated->createdAt ?? (new \DateTimeImmutable())->format(\DATE_ATOM),
            );

            $this->systemConfig->set(self::CONFIG_KEY, $key->toArray());

            return $key;
        }
    }

    /**
     * The public half, as the did:web document publishes it.
     *
     * @return array<string, string>
     */
    public function publicJwk(A2cnSigningKey $key): array
    {
        $managed = new \Ucp\Sdk\Model\Security\ManagedSigningKey($key->kid, $key->publicKeyPem, $key->privateKeyPem);

        return $this->keys->toPublicKey($managed)->toJwk();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

/**
 * Who this installation is, in A2CN terms.
 *
 * One key, many domains: the DID is derived from the domain a request arrived
 * on, the kid and key material do not change with it.
 */
final readonly class A2cnIdentity
{
    public const AGENT_ID = 'merchant-quote-agent';

    /** The one deal type this agent is authorized for. */
    public const DEAL_TYPES = ['goods_procurement'];

    /**
     * Honest self-declaration: the act chain and the end-of-session records are
     * served per session, not just a discovery document.
     */
    public const CONFORMANCE_LEVEL = 'acts';

    public function __construct(
        public string $did,
        public string $verificationMethod,
        public string $organizationName,
        public string $agentId = self::AGENT_ID,
    ) {}

    public static function forHost(string $host, string $kid, string $organizationName): self
    {
        // did:web uses ':' as its own segment separator, so a port has to be
        // percent-encoded or the authority would be read as a path.
        $authority = str_replace(':', '%3A', $host);
        $did = 'did:web:' . $authority;

        return new self($did, $did . '#' . $kid, $organizationName);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The identity to publish and sign under, for one sales channel or one request
 * host.
 *
 * The organization name comes from configuration when the merchant set one and
 * from the sales channel's own name otherwise — a published document should not
 * say "Merchant".
 */
final readonly class A2cnIdentityResolver
{
    public const ORGANIZATION_CONFIG_KEY = 'MerchantQuoteAgentPlugin.config.a2cnOrganizationName';

    public function __construct(
        private A2cnKeyStore $keys,
        private SystemConfigService $systemConfig,
        private Connection $connection,
    ) {}

    /** @throws MissingSigningKey */
    public function forHost(string $host, ?string $salesChannelId = null): A2cnIdentity
    {
        return A2cnIdentity::forHost($host, $this->keys->current()->kid, $this->organizationName($salesChannelId));
    }

    /**
     * The identity for a sales channel, from its primary domain.
     *
     * Null when the channel has no domain to be a did:web authority — a shop
     * reachable at no URL cannot publish a DID document either.
     *
     * @throws MissingSigningKey|\Doctrine\DBAL\Exception
     */
    public function forSalesChannel(string $salesChannelId): ?A2cnIdentity
    {
        if (!Uuid::isValid($salesChannelId)) {
            return null;
        }

        $url = $this->connection->fetchOne(
            'SELECT `url` FROM `sales_channel_domain` WHERE `sales_channel_id` = :id ORDER BY `id` ASC LIMIT 1',
            ['id' => Uuid::fromHexToBytes($salesChannelId)],
        );

        if (!\is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, \PHP_URL_PORT);

        return $this->forHost(\is_int($port) ? $host . ':' . $port : $host, $salesChannelId);
    }

    private function organizationName(?string $salesChannelId): string
    {
        $configured = $this->systemConfig->getString(self::ORGANIZATION_CONFIG_KEY, $salesChannelId);
        if (trim($configured) !== '') {
            return trim($configured);
        }

        if ($salesChannelId === null || !Uuid::isValid($salesChannelId)) {
            return 'Merchant';
        }

        $name = $this->connection->fetchOne(
            'SELECT `name` FROM `sales_channel_translation` WHERE `sales_channel_id` = :id LIMIT 1',
            ['id' => Uuid::fromHexToBytes($salesChannelId)],
        );

        return \is_string($name) && $name !== '' ? $name : 'Merchant';
    }
}
```

Add the config field to `src/Resources/config/config.xml`, in the existing card
structure (match the surrounding indentation and add a German label if the file
carries `de-DE` labels elsewhere):

```xml
<input-field type="text">
    <name>a2cnOrganizationName</name>
    <label>Organization name published in the A2CN seller mandate</label>
    <helpText>Leave empty to publish the sales channel name.</helpText>
</input-field>
```

Wire the lifecycle hooks in `src/MerchantQuoteAgentPlugin.php`, next to the
uninstall drop from Task 13:

```php
    #[Override]
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->generateSigningKey();
    }

    #[Override]
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        // Also here, so a shop that updates into this version gets a key
        // without being reinstalled.
        $this->generateSigningKey();
    }

    private function generateSigningKey(): void
    {
        $keys = $this->container?->get(A2cnKeyStore::class);
        if ($keys instanceof A2cnKeyStore) {
            $keys->generateIfAbsent();
        }
    }
```

Register both services, and make `A2cnKeyStore` public so the plugin class can
fetch it during install:

```php
$services->set(A2cnKeyStore::class)->public();
$services->set(A2cnIdentityResolver::class);
```

- [ ] **Step 4: Run the tests**

Run: `composer run test -- --filter A2cnIdentityTest` — expected PASS (3 tests).
Run: `composer run test:integration -- --filter A2cnKeyStoreTest` — expected PASS (3 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src tests
composer run lint
git add src/Protocol/Identity src/MerchantQuoteAgentPlugin.php src/Resources/config tests
git commit -m "feat(protocol): A2CN signing key and published did:web identity"
```
---

### Task 15: Signing an act

**Files:**
- Create: `src/Protocol/Emitter/ActSigner.php`, `src/Protocol/Emitter/SellerActFactory.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Emitter/SellerActFactoryTest.php`

**Interfaces:**
- Consumes: `A2cnKeyStore`, `ProtocolHash`, `CompactJws`, `SignedView`, `TermsFactory`, `A2cnIdentityResolver`, `ActChain`, `SessionId`.
- Produces:
  - `ActSigner::__construct(A2cnKeyStore $keys, ProtocolHash $hash)`, `->proofFor(array $signedView): array{protocol_act_hash: string, protocol_act_signature: string}`
  - `SellerActFactory::__construct(ActSigner $signer, TermsFactory $terms, A2cnIdentityResolver $identities)`, `->terms(QuoteSnapshot $snapshot): array`, `->termsUnchanged(?array $previous, array $current): bool`, `->identityFor(QuoteSnapshot $snapshot): ?A2cnIdentity`, `->build(QuoteSnapshot $snapshot, ActChain $chain, A2cnIdentity $identity, \DateTimeImmutable $now): Act`

`build()` produces the **wire** act and runs it back through `Act::fromArray()`
before returning — an act we cannot parse with our own reader must never reach a
quote. The signed view is derived from that same act, so the hash covers exactly
what a verifier will recompute.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class SellerActFactoryTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItBuildsACounterofferAtTheNextSequenceAndRound(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);

        $act = self::factory()->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertSame('counteroffer', $act->messageType());
        self::assertSame(2, $act->sequenceNumber());
        self::assertSame(2, $act->roundNumber());
        self::assertSame($session, $act->sessionId());
        self::assertSame($session . ':2', $act->messageId());
        self::assertSame($session . ':1', $act->raw()['in_reply_to'] ?? null);
        self::assertSame('did:web:shop.example', $act->senderDid());
        self::assertSame('merchant-quote-agent', $act->senderAgentId());
    }

    public function testItCarriesTheQuoteExpiryAndTheDerivedTerms(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session)]);

        $act = self::factory()->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertSame('2026-09-18T10:00:00+00:00', $act->expiresAt());
        self::assertSame(760000, $act->terms()['total_value'] ?? null);
    }

    public function testTheProofCoversExactlyTheSignedView(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session)]);
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        $act = self::factory()->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertSame($hash->of(SignedView::of($act)), $act->hash());
        self::assertSame($act->hash(), CompactJws::verify($act->signature(), TestActSigner::publicKeyPem()));
    }

    public function testItProducesAnActItsOwnReaderAccepts(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session)]);

        $act = self::factory()->build(ProtocolFixtures::snapshot(self::QUOTE_ID), $chain, self::identity(), ProtocolFixtures::at());

        self::assertNotNull(Act::fromArray($act->raw()));
        self::assertArrayNotHasKey('protocol_version', $act->raw(), 'protocol_version is signed but never on the wire');
    }

    public function testItComparesTermsByTheirSignedBytes(): void
    {
        $factory = self::factory();
        $terms = $factory->terms(ProtocolFixtures::snapshot(self::QUOTE_ID));

        self::assertTrue($factory->termsUnchanged($terms, $terms));
        self::assertFalse($factory->termsUnchanged(ProtocolFixtures::terms(quantity: 5), $terms));
    }

    private static function factory(): \MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActFactory
    {
        return TestActSigner::factory();
    }

    private static function identity(): A2cnIdentity
    {
        return A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
    }
}
```

And the test double that owns a real key pair, so the emitter tests can reuse it:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ActSigner;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActFactory;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Terms\TermsFactory;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

/**
 * A real ES256 key pair, generated once per process, wired into a real
 * ActSigner. The signing path is what these tests are about, so it is not
 * mocked — only the key's origin is.
 */
final class TestActSigner
{
    private static ?A2cnSigningKey $key = null;

    private function __construct() {}

    public static function key(): A2cnSigningKey
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($resource === false) {
            throw new \RuntimeException('Unable to generate a test key.');
        }
        $private = '';
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        if (!\is_array($details) || !\is_string($details['key'])) {
            throw new \RuntimeException('Unable to export the test key.');
        }

        return self::$key = new A2cnSigningKey('key-1', $private, $details['key'], '2026-09-01T00:00:00+00:00');
    }

    public static function publicKeyPem(): string
    {
        return self::key()->publicKeyPem;
    }

    public static function signer(): ActSigner
    {
        return new ActSigner(self::keyStore(), new ProtocolHash(new DefaultJsonCanonicalization()));
    }

    public static function factory(): SellerActFactory
    {
        return new SellerActFactory(
            self::signer(),
            new TermsFactory(new ProtocolHash(new DefaultJsonCanonicalization())),
            self::identities(),
        );
    }

    public static function keyStore(): A2cnKeyStore
    {
        return new class(self::key()) extends A2cnKeyStore {
            public function __construct(private readonly A2cnSigningKey $key)
            {
            }

            public function current(): A2cnSigningKey
            {
                return $this->key;
            }
        };
    }

    public static function identities(): A2cnIdentityResolver
    {
        return new class extends A2cnIdentityResolver {
            public function __construct()
            {
            }

            public function forSalesChannel(string $salesChannelId): ?A2cnIdentity
            {
                return A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
            }
        };
    }
}
```

**Note for the implementer:** these anonymous subclasses need `A2cnKeyStore` and
`A2cnIdentityResolver` to be non-final with non-private methods, exactly like
`SalesChannelDomainUrlReader` ("Not `final`: the controller test substitutes
it"). If you would rather keep them final, introduce
`SigningKeyProviderInterface` / `IdentityResolverInterface` in this task and
depend on those instead. Pick one, apply it consistently, and update Task 16's
tests to match.

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter SellerActFactoryTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;

/**
 * The proof fields for a signed view: `base64url(SHA-256(JCS(view)))` and an
 * ES256 compact JWS over that hash string (spec 7.4).
 *
 * Signing throws when no key is configured. That is deliberate — see
 * A2cnKeyStore: an act signed with a key nobody can resolve is evidence that
 * looks real and is worthless, so the emitter would rather emit nothing.
 */
final readonly class ActSigner
{
    public function __construct(
        private A2cnKeyStore $keys,
        private ProtocolHash $hash,
    ) {}

    /**
     * @param array<string, mixed> $signedView
     *
     * @return array{protocol_act_hash: string, protocol_act_signature: string}
     *
     * @throws MissingSigningKey
     */
    public function proofFor(array $signedView): array
    {
        $digest = $this->hash->of($signedView);

        return [
            'protocol_act_hash' => $digest,
            'protocol_act_signature' => CompactJws::sign($digest, $this->keys->current()->privateKeyPem),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Terms\TermsFactory;

/**
 * Builds the one act type this agent emits: a signed `counteroffer` at the next
 * sequence and round.
 *
 * The session id is DERIVED (SessionId::forQuote), never taken from the chain —
 * SessionIdCheck has already refused a chain that claims a different one, so
 * using the derived value here means the act cannot inherit a forged session.
 *
 * The wire act carries no `protocol_version`: that field is part of the SIGNED
 * object only (SignedView), and putting it on the wire would invite a verifier
 * to include it twice.
 */
final readonly class SellerActFactory
{
    private const MESSAGE_TYPE = 'counteroffer';

    public function __construct(
        private ActSigner $signer,
        private TermsFactory $terms,
        private A2cnIdentityResolver $identities,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws \MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount
     */
    public function terms(QuoteSnapshot $snapshot): array
    {
        return $this->terms->fromSnapshot($snapshot);
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     */
    public function termsUnchanged(?array $previous, array $current): bool
    {
        return $this->terms->unchanged($previous, $current);
    }

    public function identityFor(QuoteSnapshot $snapshot): ?A2cnIdentity
    {
        $salesChannelId = $snapshot->identity->salesChannelId;

        return $salesChannelId === '' ? null : $this->identities->forSalesChannel($salesChannelId);
    }

    /**
     * @throws \MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount
     * @throws \MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey
     * @throws UnbuildableAct
     */
    public function build(
        QuoteSnapshot $snapshot,
        ActChain $chain,
        A2cnIdentity $identity,
        \DateTimeImmutable $now,
    ): Act {
        $sessionId = SessionId::forQuote($snapshot->identity->quoteId);
        $sequence = $chain->nextSequence();
        $timestamp = $now->format(\DATE_ATOM);

        $wire = [
            'message_type' => self::MESSAGE_TYPE,
            'message_id' => $sessionId . ':' . $sequence,
            'session_id' => $sessionId,
            'round_number' => $chain->nextRound(),
            'sequence_number' => $sequence,
            'sender_did' => $identity->did,
            'sender_agent_id' => $identity->agentId,
            'sender_verification_method' => $identity->verificationMethod,
            'timestamp' => $timestamp,
            'terms' => $this->terms($snapshot),
        ];

        $inReplyTo = $chain->last()?->messageId();
        if ($inReplyTo !== null) {
            $wire['in_reply_to'] = $inReplyTo;
        }

        $expiresAt = $snapshot->lifecycle->expiresAt;
        if ($expiresAt !== null) {
            $wire['expires_at'] = $expiresAt->format(\DATE_ATOM);
        }

        // Sign the view of the act as it will actually stand, then attach the
        // proof: the hash covers exactly what a verifier recomputes.
        $unsigned = Act::fromArray($wire + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        if ($unsigned === null) {
            throw new UnbuildableAct('Refusing to emit an act this plugin cannot read back.');
        }

        $signed = Act::fromArray($wire + $this->signer->proofFor(SignedView::of($unsigned)));
        if ($signed === null) {
            throw new UnbuildableAct('Refusing to emit an act this plugin cannot read back.');
        }

        return $signed;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

final class UnbuildableAct extends \RuntimeException {}
```

Register: `$services->set(ActSigner::class); $services->set(SellerActFactory::class);`

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter SellerActFactoryTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Emitter tests/Unit/Protocol src/Resources/config/services.php
git commit -m "feat(protocol): build and sign the seller counteroffer act"
```

---

### Task 16: The emitter

**Files:**
- Create: `src/Protocol/Emitter/ChainMirror.php`, `src/Protocol/Emitter/EmissionStatus.php`, `src/Protocol/Emitter/EmissionOutcome.php`, `src/Protocol/Emitter/SellerActEmitter.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Emitter/SellerActEmitterTest.php`

**Interfaces:**
- Consumes: `SellerActFactory`, `EvidenceInspector`, `ActStoreInterface`, `Bridge\QuoteGatewayInterface`, `Servicing\QuoteEscalator::MARKER_KEY`, PSR-3 logger.
- Produces:
  - `ChainMirror::__construct(ActStoreInterface $store)`, `->mirror(string $quoteId, ActChain $chain): void`, `->mirrorOne(string $quoteId, Act $act): void`, `->recordViolation(string $sessionId, ProtocolViolation $violation): void`, `->recordReceipt(string $sessionId, ApprovalReceipt $receipt): void`
  - `enum EmissionStatus: string { Inert, Unchanged, Violation, Emitted, Failed }`
  - `EmissionOutcome::inert()`, `::unchanged()`, `::violation(ProtocolViolation)`, `::emitted(Act)`, `::failed()`, with `->status`, `->act`, `->violation`
  - `SellerActEmitter::__construct(SellerActFactory $acts, EvidenceInspector $inspector, ChainMirror $mirror, QuoteGatewayInterface $gateway, LoggerInterface $logger)`, `->observe(QuoteSnapshot $snapshot, \DateTimeImmutable $now): EmissionOutcome`

**The gate order is normative** (spec, "The emission flow"): inert → mirror →
state → terms → violation → emit. Two properties the tests pin because losing
them is silent and permanent:

- **Mirror before wire.** If the quote write fails after the mirror succeeded,
  the next observation still sees changed terms and retries the append. The
  other order leaves the wire holding one act, the mirror another, and
  `offer_chain_hash` diverging forever.
- **`emission_failed` persists no violation.** An exception from our own signing,
  store or gateway code is our bug; recording it as a protocol violation would
  present it as evidence against the counterparty to a legal reader.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionStatus;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SellerActEmitterTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItIsInertWithoutASession(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();

        $outcome = self::emitter($store, $gateway)->observe(ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Inert, $outcome->status);
        self::assertSame([], $gateway->updates);
        self::assertSame([], $store->acts);
    }

    public function testItMirrorsTheWholeChainEvenWhenItDoesNotEmit(): void
    {
        // A buyer act that triggers no emission must still reach our own copy:
        // the mirror is the only record we control.
        $store = new InMemoryActStore();
        $snapshot = self::snapshotWithChain(state: 'open');

        $outcome = self::emitter($store, new RecordingQuoteGateway())->observe($snapshot, ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Unchanged, $outcome->status);
        self::assertCount(1, $store->acts);
    }

    public function testItEmitsOneSellerActOnAnOfferVisibleState(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();

        $outcome = self::emitter($store, $gateway)->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Emitted, $outcome->status);
        self::assertCount(1, $gateway->updates);
        $customFields = $gateway->updates[0]->customFields ?? [];
        self::assertSame([ActKey::for(2, ActRole::Seller)], array_keys($customFields));
        self::assertSame(2, $customFields[ActKey::for(2, ActRole::Seller)]['sequence_number'] ?? null);
        // Mirror AND wire, both.
        self::assertCount(2, $store->acts);
    }

    public function testItDoesNothingOnASecondObservationOfTheSameTerms(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();
        $emitter = self::emitter($store, $gateway);
        $snapshot = self::snapshotWithChain();

        $emitter->observe($snapshot, ProtocolFixtures::at());
        $emitted = $gateway->updates[0]->customFields ?? [];
        $again = self::snapshotWithChain(extraFields: $emitted);

        $outcome = $emitter->observe($again, ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Unchanged, $outcome->status);
        self::assertCount(1, $gateway->updates);
    }

    public function testAViolationSuppressesEmissionAndIsPersisted(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();
        $emitter = self::emitter($store, $gateway, self::refusingInspector());

        $outcome = $emitter->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Violation, $outcome->status);
        self::assertSame('duplicate_sequence', $outcome->violation?->violationType);
        self::assertSame([], $gateway->updates);
        self::assertCount(1, $store->violations);
    }

    public function testItMirrorsBeforeTheWireWrite(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway(failOnUpdate: true);

        $outcome = self::emitter($store, $gateway)->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Failed, $outcome->status);
        // The act reached our mirror, so the next observation still sees the
        // terms as changed and retries the append.
        self::assertCount(2, $store->acts);
        // Our own failure is never recorded as the counterparty's violation.
        self::assertSame([], $store->violations);
    }

    public function testItWritesAReceiptOnlyWhenAnEscalationIsUnreleased(): void
    {
        $store = new InMemoryActStore();
        $withMarker = self::snapshotWithChain(extraFields: [QuoteEscalator::MARKER_KEY => 'discount_above_band']);

        self::emitter($store, new RecordingQuoteGateway())->observe($withMarker, ProtocolFixtures::at());

        self::assertCount(1, $store->receipts);
        self::assertSame('discount_above_band', $store->receipts[0]->thresholdCrossed);

        $clean = new InMemoryActStore();
        self::emitter($clean, new RecordingQuoteGateway())->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame([], $clean->receipts);
    }

    /** @param array<string, mixed> $extraFields */
    private static function snapshotWithChain(string $state = 'replied', array $extraFields = []): QuoteSnapshot
    {
        $session = SessionId::forQuote(self::QUOTE_ID);

        return ProtocolFixtures::snapshot(self::QUOTE_ID, state: $state, customFields: [
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ...$extraFields,
        ]);
    }

    private static function emitter(
        InMemoryActStore $store,
        QuoteGatewayInterface $gateway,
        ?EvidenceInspector $inspector = null,
    ): SellerActEmitter {
        return new SellerActEmitter(
            TestActSigner::factory(),
            $inspector ?? new EvidenceInspector([]),
            new ChainMirror($store),
            $gateway,
            new NullLogger(),
        );
    }

    private static function refusingInspector(): EvidenceInspector
    {
        return new EvidenceInspector([new class implements \MerchantQuoteAgentPlugin\Protocol\Check\EvidenceCheckInterface {
            public function check(ActChain $chain, QuoteSnapshot $snapshot, string $sellerDid, \DateTimeImmutable $at): ?ProtocolViolation
            {
                return new ProtocolViolation($at->format(\DATE_ATOM), 'duplicate_sequence', null, 'sequence 1 twice');
            }
        }]);
    }
}
```

Two more test doubles, in `tests/Unit/Protocol/`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use Override;

/** The mirror, in memory. Idempotent on (session, sequence), like the real one. */
final class InMemoryActStore implements ActStoreInterface
{
    /** @var array<string, Act> keyed by "session:sequence" */
    public array $acts = [];

    /** @var list<ProtocolViolation> */
    public array $violations = [];

    /** @var list<ApprovalReceipt> */
    public array $receipts = [];

    /** @var array<string, string> */
    public array $quotes = [];

    #[Override]
    public function append(ActRecord $record): void
    {
        $this->acts[$record->sessionId . ':' . $record->sequence] = $record->act;
        $this->quotes[$record->sessionId] = $record->quoteId;
    }

    #[Override]
    public function appendViolation(string $sessionId, ProtocolViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    #[Override]
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void
    {
        $this->receipts[] = $receipt;
    }

    /** @return list<Act> */
    #[Override]
    public function listBySession(string $sessionId): array
    {
        $acts = [];
        foreach ($this->acts as $key => $act) {
            if (str_starts_with($key, $sessionId . ':')) {
                $acts[] = $act;
            }
        }

        usort($acts, static fn (Act $a, Act $b): int => $a->sequenceNumber() <=> $b->sequenceNumber());

        return $acts;
    }

    /** @return list<ProtocolViolation> */
    #[Override]
    public function listViolations(string $sessionId): array
    {
        return $this->violations;
    }

    /** @return list<ApprovalReceipt> */
    #[Override]
    public function listReceipts(string $sessionId): array
    {
        return $this->receipts;
    }

    #[Override]
    public function quoteIdForSession(string $sessionId): ?string
    {
        return $this->quotes[$sessionId] ?? null;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Override;

/**
 * Records the quote writes the emitter makes, and can fail the one that
 * matters — the wire append after the mirror succeeded.
 *
 * Implement every method of QuoteGatewayInterface; only updateQuote and
 * fetchSnapshot are exercised. If the interface has grown since this plan was
 * written, add the new methods as `throw new \LogicException('not used')`.
 */
final class RecordingQuoteGateway implements QuoteGatewayInterface
{
    /** @var list<QuoteUpdate> */
    public array $updates = [];

    public function __construct(
        private readonly bool $failOnUpdate = false,
        private readonly ?QuoteSnapshot $snapshot = null,
    ) {}

    #[Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        return $this->snapshot ?? ProtocolFixtures::snapshot($quoteId);
    }

    #[Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        if ($this->failOnUpdate) {
            throw new \RuntimeException('wire write failed');
        }

        $this->updates[] = $update;
    }

    #[Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function recalculate(string $quoteId): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function addComment(string $quoteId, string $comment): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        throw new \LogicException('not used');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter SellerActEmitterTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;

/**
 * Mirrors the wire chain into our own copy.
 *
 * Mirrors the WHOLE known chain, not just what is about to be signed: this is
 * our only independent copy, so a buyer act that never triggers an emission
 * (terms unchanged, a violation, a state we do not counter into) must still
 * land here or it never reaches the mirror at all.
 *
 * Everything is keyed under the session DERIVED from the quote, never each
 * act's own claimed `session_id` — a misbehaving counterparty could forge that,
 * and our evidence would be filed under their session.
 */
final readonly class ChainMirror
{
    public function __construct(
        private ActStoreInterface $store,
    ) {}

    public function mirror(string $quoteId, ActChain $chain): void
    {
        foreach ($chain->acts() as $act) {
            $this->mirrorOne($quoteId, $act);
        }
    }

    public function mirrorOne(string $quoteId, Act $act): void
    {
        // Each act's OWN sequence, not a running counter, so this stays correct
        // however many rounds have already been mirrored. append() is
        // idempotent, so re-mirroring is free.
        $this->store->append(new ActRecord(
            sessionId: SessionId::forQuote($quoteId),
            quoteId: $quoteId,
            sequence: $act->sequenceNumber(),
            act: $act,
        ));
    }

    public function recordViolation(string $quoteId, ProtocolViolation $violation): void
    {
        $this->store->appendViolation(SessionId::forQuote($quoteId), $violation);
    }

    public function recordReceipt(string $quoteId, ApprovalReceipt $receipt): void
    {
        $this->store->appendReceipt(SessionId::forQuote($quoteId), $receipt);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

/** What one observation did. The values double as log markers. */
enum EmissionStatus: string
{
    case Inert = 'inert';
    case Unchanged = 'unchanged';
    case Violation = 'violation';
    case Emitted = 'emitted';
    case Failed = 'emission_failed';
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;

final readonly class EmissionOutcome
{
    private function __construct(
        public EmissionStatus $status,
        public ?Act $act = null,
        public ?ProtocolViolation $violation = null,
    ) {}

    public static function inert(): self
    {
        return new self(EmissionStatus::Inert);
    }

    public static function unchanged(): self
    {
        return new self(EmissionStatus::Unchanged);
    }

    public static function violation(ProtocolViolation $violation): self
    {
        return new self(EmissionStatus::Violation, violation: $violation);
    }

    public static function emitted(Act $act): self
    {
        return new self(EmissionStatus::Emitted, act: $act);
    }

    public static function failed(): self
    {
        return new self(EmissionStatus::Failed);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use Psr\Log\LoggerInterface;

/**
 * The single place a seller act is produced.
 *
 * The trigger is a STATE TRANSITION, not an agent decision: the quote entered a
 * buyer-visible offer state and its terms differ from our last signed act. That
 * one rule covers the agent's own offer, a human's post-escalation edit, and any
 * future path that writes an offer — hooking the negotiation pipeline would miss
 * the human entirely.
 *
 * Idempotent by construction (it appends only on a terms change), so it is safe
 * to call from more than one site. Concurrency is the CALLER's job:
 * ObserveQuoteHandler holds the per-quote servicing lock, because two
 * overlapping runs would both compute the same next sequence and race two
 * different signed payloads onto the same wire key.
 *
 * Fail-open throughout: an evidence failure never stops commerce.
 */
final readonly class SellerActEmitter
{
    /** The only state in which an offer is visible to the buyer. */
    private const OFFER_VISIBLE_STATES = ['replied'];

    public function __construct(
        private SellerActFactory $acts,
        private EvidenceInspector $inspector,
        private ChainMirror $mirror,
        private QuoteGatewayInterface $gateway,
        private LoggerInterface $logger,
    ) {}

    public function observe(QuoteSnapshot $snapshot, \DateTimeImmutable $now): EmissionOutcome
    {
        try {
            return $this->run($snapshot, $now);
        } catch (\Throwable $error) {
            // Fail-open on commerce: an evidence failure never stops servicing.
            // Deliberately NOT persisted as a protocol violation — this is a
            // throw from our own signing, store or gateway code, and recording
            // it as evidence against the counterparty would mislead a legal
            // reader of the audit log.
            $this->logger->error('A2CN act emission failed.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $error,
            ]);

            return EmissionOutcome::failed();
        }
    }

    private function run(QuoteSnapshot $snapshot, \DateTimeImmutable $now): EmissionOutcome
    {
        $quoteId = $snapshot->identity->quoteId;
        $chain = ActChain::read($snapshot->lifecycle->customFields);
        if ($chain->isEmpty()) {
            // No session, or no readable act: nobody is negotiating with us
            // over A2CN, and we do not open a session unilaterally.
            return EmissionOutcome::inert();
        }

        $identity = $this->acts->identityFor($snapshot);
        if ($identity === null) {
            $this->logger->warning('A2CN cannot identify this installation for a quote; no act emitted.', [
                'quoteId' => $quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);

            return EmissionOutcome::inert();
        }

        // See ChainMirror: the whole chain, before any gate, because a buyer act
        // that never triggers an emission must still reach our own copy.
        $this->mirror->mirror($quoteId, $chain);

        if (!\in_array($snapshot->lifecycle->stateTechnicalName, self::OFFER_VISIBLE_STATES, strict: true)) {
            return EmissionOutcome::unchanged();
        }

        $terms = $this->acts->terms($snapshot);
        if ($this->acts->termsUnchanged($chain->lastSellerAct($identity->did)?->terms(), $terms)) {
            return EmissionOutcome::unchanged();
        }

        $violation = $this->inspector->firstViolation($chain, $snapshot, $identity->did, $now);
        if ($violation !== null) {
            $this->mirror->recordViolation($quoteId, $violation);
            $this->logger->warning('A2CN refused to sign into a chain.', [
                'quoteId' => $quoteId,
                'violationType' => $violation->violationType,
                'description' => $violation->description,
            ]);

            return EmissionOutcome::violation($violation);
        }

        return $this->emit($snapshot, $chain, $identity, $now);
    }

    private function emit(
        QuoteSnapshot $snapshot,
        ActChain $chain,
        A2cnIdentity $identity,
        \DateTimeImmutable $now,
    ): EmissionOutcome {
        $quoteId = $snapshot->identity->quoteId;
        $act = $this->acts->build($snapshot, $chain, $identity, $now);

        // Mirror before wire. If the write below fails, our mirror already
        // reflects the changed terms, so the next observation sees the terms as
        // changed and retries the append — instead of the wire holding one act,
        // the mirror another, and offer_chain_hash diverging permanently.
        $this->mirror->mirrorOne($quoteId, $act);

        $this->gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ActKey::for($act->sequenceNumber(), ActRole::Seller) => $act->raw(),
        ]));

        $this->recordApproval($snapshot, $act, $now);

        return EmissionOutcome::emitted($act);
    }

    /**
     * A receipt records that a HUMAN stood behind terms the agent itself would
     * have escalated. An unreleased escalation marker is exactly that state:
     * the agent escalated and has not answered since (QuoteEscalator releases
     * the marker only on a pass that answered), so the offer now on the quote
     * is a person's.
     *
     * Own try/catch, deliberately separate from observe()'s: the act is already
     * mirrored AND on the wire, so a receipt failure must not turn this into a
     * failed emission — that would lie about whether the offer went out. The
     * receipt is genuinely lost for this call; logged, not silently swallowed.
     */
    private function recordApproval(QuoteSnapshot $snapshot, Act $act, \DateTimeImmutable $now): void
    {
        $marker = $snapshot->lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null;
        if (!\is_string($marker) || $marker === '') {
            return;
        }

        try {
            $this->mirror->recordReceipt($snapshot->identity->quoteId, new ApprovalReceipt(
                receiptId: $act->sessionId() . ':' . $act->hash(),
                offerHash: $act->hash(),
                thresholdCrossed: $marker,
                approvedAt: $now->format(\DATE_ATOM),
            ));
        } catch (\Throwable $error) {
            $this->logger->error('A2CN approval receipt lost; the act was already emitted.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $error,
            ]);
        }
    }
}
```

Register: `$services->set(ChainMirror::class); $services->set(SellerActEmitter::class);`
`SellerActEmitter` depends on `QuoteGatewayInterface`, which only exists where
SwagCommercial does — register it inside the same `CommercialAvailability` gate
`services.php` already uses for the other gateway consumers.

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter SellerActEmitterTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Emitter tests/Unit/Protocol src/Resources/config/services.php
git commit -m "feat(protocol): emit seller acts on an offer-visible state change"
```

---

### Task 17: The trigger

**Files:**
- Create: `src/Protocol/Emitter/ObserveQuoteMessage.php`, `src/Protocol/Emitter/ObserveQuoteHandler.php`, `src/Protocol/Emitter/OfferVisibleStateSubscriber.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Emitter/OfferVisibleStateSubscriberTest.php`, `tests/Unit/Protocol/Emitter/ObserveQuoteHandlerTest.php`

**Interfaces:**
- Consumes: `Symfony\Component\Messenger\MessageBusInterface`, `Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent`, `Servicing\QuoteServicingLock`, `QuoteGatewayInterface`, `SellerActEmitter`.
- Produces: `ObserveQuoteMessage::__construct(string $quoteId)` implementing `AsyncMessageInterface`; `ObserveQuoteHandler` with `#[AsMessageHandler]`; `OfferVisibleStateSubscriber` implementing `EventSubscriberInterface`.

Read `src/Servicing/QuoteServicingTrigger.php` before writing the subscriber and
copy its shape: same core event name (`state_machine.quote.state_changed`), the
same enter-side guard, and the same "is this our business" context guard. The
difference is the state list — `QuoteServicingTrigger` deliberately excludes
`replied` (that is the state its own servicing drives), and this subscriber
listens for exactly that one.

**Emission must not run inline in the transition's own request:** it signs,
resolves a `did:web` document over HTTP and writes back to the quote. Hence the
message.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteMessage;
use MerchantQuoteAgentPlugin\Protocol\Emitter\OfferVisibleStateSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OfferVisibleStateSubscriberTest extends TestCase
{
    public function testItSubscribesToTheCoreQuoteStateEvent(): void
    {
        self::assertArrayHasKey('state_machine.quote.state_changed', OfferVisibleStateSubscriber::getSubscribedEvents());
    }

    public function testItQueuesAnObservationWhenAQuoteEntersReplied(): void
    {
        $bus = self::bus();
        (new OfferVisibleStateSubscriber($bus))->onQuoteStateChanged(self::event('replied', StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER));

        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(ObserveQuoteMessage::class, $bus->messages[0]);
        self::assertSame('quote-1', $bus->messages[0]->quoteId);
    }

    public function testItIgnoresTheLeaveSideAndOtherStates(): void
    {
        $bus = self::bus();
        $subscriber = new OfferVisibleStateSubscriber($bus);

        $subscriber->onQuoteStateChanged(self::event('replied', StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE));
        $subscriber->onQuoteStateChanged(self::event('open', StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER));

        self::assertSame([], $bus->messages);
    }

    private static function event(string $state, string $side): StateMachineStateChangeEvent
    {
        $transition = new StateMachineTransitionEntity();
        $transition->setEntityId('quote-1');

        return new StateMachineStateChangeEvent(Context::createDefaultContext(), $side, $transition, 'quote.state', $state);
    }

    private static function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
```

**Note for the implementer:** `StateMachineStateChangeEvent`'s constructor and
`StateMachineTransitionEntity`'s setters differ between Shopware minor versions.
Check the signature in `vendor/shopware/core` and match this fixture to it — if
constructing the event is awkward, build the subscriber test around a small
factory method on the subscriber instead (`shouldObserve(string $state, string
$side): bool`) and test that directly, plus one dispatch test.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionStatus;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteHandler;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteMessage;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ObserveQuoteHandlerTest extends TestCase
{
    public function testItDoesNothingWithoutTheCommercialGateway(): void
    {
        // Same posture as ServiceQuoteHandler: no gateway means SwagCommercial
        // is absent or unlicensed, and evidence is not worth a parked message.
        $handler = new ObserveQuoteHandler(self::locks(free: true), new NullLogger(), null, null);

        $handler(new ObserveQuoteMessage('quote-1'));

        self::assertTrue(true, 'no exception, no work');
    }

    public function testItSkipsAQuoteAnotherProcessHasClaimed(): void
    {
        $emitter = self::emitter();
        $handler = new ObserveQuoteHandler(self::locks(free: false), new NullLogger(), new \MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway(), $emitter);

        $handler(new ObserveQuoteMessage('quote-1'));

        self::assertSame(0, $emitter->calls);
    }

    public function testItObservesUnderTheLockAndReleasesIt(): void
    {
        $emitter = self::emitter();
        $locks = self::locks(free: true);
        $handler = new ObserveQuoteHandler($locks, new NullLogger(), new \MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway(), $emitter);

        $handler(new ObserveQuoteMessage('quote-1'));

        self::assertSame(1, $emitter->calls);
    }

    private static function emitter(): object
    {
        return new class extends \MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function observe(\MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot, \DateTimeImmutable $now): \MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionOutcome
            {
                ++$this->calls;

                return \MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionOutcome::unchanged();
            }
        };
    }

    private static function locks(bool $free): \MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock
    {
        // Build the real QuoteServicingLock over an in-memory lock factory, the
        // way the servicing tests already do — copy that setup from
        // tests/Unit/Servicing rather than inventing a second approach.
        throw new \LogicException('Copy the lock fixture from the existing servicing tests.');
    }
}
```

**Implementer:** replace `self::locks()` with whatever
`tests/Unit/Servicing/*Test.php` already uses to construct
`QuoteServicingLock` (a `LockFactory` over `Symfony\Component\Lock\Store\InMemoryStore`
is the likely answer). Do not invent a second lock fixture, and delete the
`LogicException` placeholder — a plan-provided placeholder that survives into the
codebase is a bug.

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "OfferVisibleStateSubscriberTest|ObserveQuoteHandlerTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * "Look at this quote's act chain."
 *
 * Payload is the quote id and nothing that can go stale: the handler re-reads
 * the snapshot, which is a better source than a serialised copy.
 */
final readonly class ObserveQuoteMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $quoteId,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one observation, under the same per-quote lock quote servicing uses.
 *
 * The lock is the whole reason this is a handler and not an inline call: two
 * overlapping observations would both compute the same next sequence and race
 * two different signed payloads onto the same wire key. A busy lock is not an
 * error — whoever holds it is doing this work — so the message is dropped
 * rather than retried: the next state change or the servicing pass that follows
 * will observe again, and emission is idempotent.
 *
 * Nothing here throws. Evidence must never park a message or fail a worker.
 */
#[AsMessageHandler]
final readonly class ObserveQuoteHandler
{
    public function __construct(
        private QuoteServicingLock $locks,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
        private ?SellerActEmitter $emitter = null,
    ) {}

    public function __invoke(ObserveQuoteMessage $message): void
    {
        $gateway = $this->gateway;
        $emitter = $this->emitter;
        if ($gateway === null || $emitter === null) {
            // SwagCommercial absent or unlicensed. Servicing parks its message
            // in this case because a quote went unanswered; evidence has no
            // such duty, so this is a debug line and nothing more.
            $this->logger->debug('A2CN observation skipped: no commercial quote gateway.', ['quoteId' => $message->quoteId]);

            return;
        }

        $lock = $this->locks->for($message->quoteId);
        if (!$lock->acquire()) {
            $this->logger->debug('A2CN observation skipped: the quote is claimed elsewhere.', ['quoteId' => $message->quoteId]);

            return;
        }

        try {
            $outcome = $emitter->observe($gateway->fetchSnapshot($message->quoteId), new \DateTimeImmutable());
            $this->logger->debug('A2CN observation finished.', [
                'quoteId' => $message->quoteId,
                'outcome' => $outcome->status->value,
            ]);
        } catch (QuoteNotFoundException $error) {
            $this->logger->info('A2CN observation skipped: the quote is gone.', [
                'quoteId' => $message->quoteId,
                'exception' => $error,
            ]);
        } catch (\Throwable $error) {
            $this->logger->error('A2CN observation failed outside the emitter.', [
                'quoteId' => $message->quoteId,
                'exception' => $error,
            ]);
        } finally {
            $lock->release();
        }
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use Override;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues an A2CN observation when a quote enters a buyer-visible offer state.
 *
 * Deliberately a STATE subscription and not a hook into the negotiation
 * pipeline: a human who edits a quote after an escalation and replies to the
 * buyer produces an offer too, and that offer must be signed like any other.
 * `QuoteServicingTrigger` excludes `replied` for the opposite reason — that is
 * the state its own servicing drives — so the two subscriptions do not overlap.
 */
final readonly class OfferVisibleStateSubscriber implements EventSubscriberInterface
{
    private const OBSERVED_STATES = ['replied'];

    public function __construct(
        private MessageBusInterface $bus,
    ) {}

    /** @return array<string, string> */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return ['state_machine.quote.state_changed' => 'onQuoteStateChanged'];
    }

    /** @throws ExceptionInterface */
    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        // Fires twice per transition, leave then enter. Only entering is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!\in_array($event->getStateName(), self::OBSERVED_STATES, strict: true)) {
            return;
        }

        $this->bus->dispatch(new ObserveQuoteMessage($event->getTransition()->getEntityId()));
    }
}
```

Register the subscriber and handler; both are tagged automatically by
autoconfiguration if `services.php` enables it for this namespace — check how
`QuoteServicingTrigger` and `ServiceQuoteHandler` are registered and match that
exactly.

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "OfferVisibleStateSubscriberTest|ObserveQuoteHandlerTest"`
Expected: PASS.

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Emitter tests/Unit/Protocol src/Resources/config/services.php
git commit -m "feat(protocol): queue an observation when a quote enters replied"
```

---

### Task 18: The end-of-session records

**Files:**
- Create: `src/Protocol/Record/OfferChainHash.php`, `src/Protocol/Record/SessionOutcome.php`, `src/Protocol/Record/RecordParty.php`, `src/Protocol/Record/RecordParties.php`, `src/Protocol/Record/RecordSubject.php`, `src/Protocol/Record/AuditEvidence.php`, `src/Protocol/Record/TransactionRecord.php`, `src/Protocol/Record/AuditLog.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Record/OfferChainHashTest.php`, `tests/Unit/Protocol/Record/SessionOutcomeTest.php`, `tests/Unit/Protocol/Record/TransactionRecordTest.php`, `tests/Unit/Protocol/Record/AuditLogTest.php`

**Interfaces:**
- Consumes: `ProtocolHash`, `SessionId`, `Act`, `ProtocolViolation`, `ApprovalReceipt`.
- Produces:
  - `OfferChainHash::__construct(ProtocolHash $hash)`, `->of(list<Act> $acts): string`
  - `SessionOutcome::for(string $state, bool $expired): ?string`
  - `RecordParty::__construct(string $organizationName, string $did, string $agentId, string $verificationMethod, string $mandateType = 'declared')`, `->toArray()`
  - `RecordParties::__construct(RecordParty $initiator, RecordParty $responder)`
  - `RecordSubject::__construct(string $dealType, string $currency, string $subject, string $subjectReference)`
  - `AuditEvidence::__construct(list<ProtocolViolation> $violations, list<ApprovalReceipt> $receipts)`
  - `TransactionRecord::__construct(ProtocolHash $hash, OfferChainHash $chainHash)`, `->build(RecordParties $parties, array $acts, Act $acceptance, RecordSubject $subject, string $generatedAt): array`
  - `AuditLog::__construct(OfferChainHash $chainHash)`, `->build(RecordParties $parties, array $acts, string $outcome, AuditEvidence $evidence, string $generatedAt): array`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class OfferChainHashTest extends TestCase
{
    public function testItHashesTheOrderedActHashes(): void
    {
        $hash = new OfferChainHash(new ProtocolHash(new DefaultJsonCanonicalization()));

        // Pinned: the same two hashes in the same order must always give this.
        self::assertSame('n6b0RQ2eX2xHsYIKnSUzelAONkFLftZzriw5jhlAVuA', $hash->of([
            self::actWithHash('h1'),
            self::actWithHash('h2'),
        ]));
    }

    public function testOrderMatters(): void
    {
        $hash = new OfferChainHash(new ProtocolHash(new DefaultJsonCanonicalization()));

        self::assertNotSame(
            $hash->of([self::actWithHash('h1'), self::actWithHash('h2')]),
            $hash->of([self::actWithHash('h2'), self::actWithHash('h1')]),
        );
    }

    public function testADroppedActChangesTheHash(): void
    {
        // This is the property the whole record rests on: an omission is
        // detectable after the fact.
        $hash = new OfferChainHash(new ProtocolHash(new DefaultJsonCanonicalization()));

        self::assertNotSame(
            $hash->of([self::actWithHash('h1'), self::actWithHash('h2')]),
            $hash->of([self::actWithHash('h1')]),
        );
    }

    private static function actWithHash(string $digest): Act
    {
        $raw = ProtocolFixtures::sellerAct(1, 'session');
        $raw['protocol_act_hash'] = $digest;
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Record\SessionOutcome;
use PHPUnit\Framework\TestCase;

final class SessionOutcomeTest extends TestCase
{
    public function testItMapsQuoteStateToATerminalOutcome(): void
    {
        self::assertSame('REJECTED_FINAL', SessionOutcome::for('declined', expired: false));
        self::assertSame('TIMED_OUT', SessionOutcome::for('replied', expired: true));
    }

    public function testALiveSessionHasNoOutcome(): void
    {
        self::assertNull(SessionOutcome::for('replied', expired: false));
        self::assertNull(SessionOutcome::for('open', expired: false));
    }

    public function testDeclinedWinsOverExpiry(): void
    {
        self::assertSame('REJECTED_FINAL', SessionOutcome::for('declined', expired: true));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParty;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordSubject;
use MerchantQuoteAgentPlugin\Protocol\Record\TransactionRecord;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class TransactionRecordTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';

    public function testItRecordsTheAgreedTermsFromTheFinalOffer(): void
    {
        $record = self::build();

        self::assertSame('a2cn_transaction_record', $record['record_type']);
        self::assertSame('0.1', $record['record_version']);
        self::assertSame(self::SESSION, $record['session_id']);
        self::assertSame(760000, $record['agreed_terms']['total_value'] ?? null);
        self::assertSame('Q-1001', $record['subject']);
        self::assertSame('quote:Q-1001', $record['subject_reference']);
    }

    public function testItSummarizesTheNegotiation(): void
    {
        $record = self::build();

        self::assertSame(2, $record['negotiation_summary']['total_rounds']);
        self::assertSame(3, $record['negotiation_summary']['total_messages']);
        self::assertSame(ProtocolFixtures::SELLER, $record['final_offer']['sender_did']);
    }

    public function testTheRecordHashCoversTheRecordWithTheHashBlanked(): void
    {
        $record = self::build();
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        $recomputed = $hash->of(['...' => '']);
        self::assertIsString($record['record_hash']);
        self::assertNotSame('', $record['record_hash']);

        // Recompute the way a third party would: blank the field, hash the rest.
        $blanked = $record;
        $blanked['record_hash'] = '';
        self::assertSame($hash->of($blanked), $record['record_hash']);
        self::assertNotSame($recomputed, $record['record_hash']);
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $acts = [
            self::act(ProtocolFixtures::buyerAct(1, self::SESSION)),
            self::act(ProtocolFixtures::sellerAct(2, self::SESSION)),
            self::act(ProtocolFixtures::buyerAct(3, self::SESSION, 'acceptance')),
        ];

        return (new TransactionRecord($hash, new OfferChainHash($hash)))->build(
            new RecordParties(
                new RecordParty('', ProtocolFixtures::BUYER, 'buyer-agent', ProtocolFixtures::BUYER . '#key-1'),
                new RecordParty('Example Shop', ProtocolFixtures::SELLER, 'merchant-quote-agent', ProtocolFixtures::SELLER . '#key-1'),
            ),
            $acts,
            $acts[2],
            new RecordSubject('goods_procurement', 'EUR', 'Q-1001', 'quote:Q-1001'),
            '2026-09-04T10:00:00+00:00',
        );
    }

    /** @param array<string, mixed> $raw */
    private static function act(array $raw): Act
    {
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditEvidence;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditLog;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParty;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class AuditLogTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';

    public function testItLogsEveryActAndTheOutcome(): void
    {
        $log = self::build(new AuditEvidence([], []));

        self::assertSame('a2cn_audit_log', $log['log_type']);
        self::assertSame('TIMED_OUT', $log['session_outcome']);
        self::assertCount(2, $log['negotiation_log']);
        self::assertSame(760000, $log['negotiation_log'][0]['total_value_offered']);
    }

    public function testItReportsTheViolationsItActuallyRecorded(): void
    {
        $log = self::build(new AuditEvidence([
            new ProtocolViolation('2026-09-04T10:00:00+00:00', 'duplicate_sequence', null, 'sequence 1 twice'),
        ], []));

        self::assertCount(1, $log['protocol_violations']);
        self::assertSame('duplicate_sequence', $log['protocol_violations'][0]['violation_type']);
    }

    public function testHumanOversightFollowsTheReceipts(): void
    {
        $without = self::build(new AuditEvidence([], []));
        self::assertFalse($without['audit_metadata']['human_oversight_present']);
        self::assertTrue($without['audit_metadata']['autonomous_decision']);
        self::assertTrue($without['audit_metadata']['ai_system_involved']);

        $with = self::build(new AuditEvidence([], [new ApprovalReceipt('id', 'hash', 'reason', '2026-09-04T10:00:00+00:00')]));
        self::assertTrue($with['audit_metadata']['human_oversight_present']);
        self::assertFalse($with['audit_metadata']['autonomous_decision']);
        self::assertCount(1, $with['audit_metadata']['human_approval_receipts']);
    }

    /** @return array<string, mixed> */
    private static function build(AuditEvidence $evidence): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $acts = [
            self::act(ProtocolFixtures::buyerAct(1, self::SESSION)),
            self::act(ProtocolFixtures::sellerAct(2, self::SESSION)),
        ];

        return (new AuditLog(new OfferChainHash($hash)))->build(
            new RecordParties(
                new RecordParty('', ProtocolFixtures::BUYER, 'buyer-agent', ProtocolFixtures::BUYER . '#key-1'),
                new RecordParty('Example Shop', ProtocolFixtures::SELLER, 'merchant-quote-agent', ProtocolFixtures::SELLER . '#key-1'),
            ),
            $acts,
            'TIMED_OUT',
            $evidence,
            '2026-09-04T10:00:00+00:00',
        );
    }

    /** @param array<string, mixed> $raw */
    private static function act(array $raw): Act
    {
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "OfferChainHashTest|SessionOutcomeTest|TransactionRecordTest|AuditLogTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;

/**
 * `base64url(SHA-256(JCS([protocol_act_hash, …])))` in chain order.
 *
 * This is what makes a dropped act detectable after the fact: a chain missing
 * one act cannot reproduce the hash the parties agreed on.
 */
final readonly class OfferChainHash
{
    public function __construct(
        private ProtocolHash $hash,
    ) {}

    /** @param list<Act> $acts */
    public function of(array $acts): string
    {
        return $this->hash->of(array_map(static fn (Act $act): string => $act->hash(), $acts));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/**
 * Quote state → A2CN terminal session state (spec 8.2). Null means the session
 * is still live and has no record yet.
 *
 * `WITHDRAWN` and `ERROR` are never produced: the seller does not withdraw, and
 * a protocol error leaves the session live with a violation entry instead.
 */
final class SessionOutcome
{
    private function __construct() {}

    public static function for(string $state, bool $expired): ?string
    {
        if ($state === 'declined') {
            return 'REJECTED_FINAL';
        }

        if ($expired) {
            return 'TIMED_OUT';
        }

        return null;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/** One party to a session, as a record names them. */
final readonly class RecordParty
{
    public function __construct(
        public string $organizationName,
        public string $did,
        public string $agentId,
        public string $verificationMethod,
        public string $mandateType = 'declared',
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'did' => $this->did,
            'agent_id' => $this->agentId,
            'verification_method' => $this->verificationMethod,
            'mandate_type' => $this->mandateType,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/** Grouped so the record builders stay inside the five-parameter cap. */
final readonly class RecordParties
{
    public function __construct(
        public RecordParty $initiator,
        public RecordParty $responder,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/** What the session was about. */
final readonly class RecordSubject
{
    public function __construct(
        public string $dealType,
        public string $currency,
        public string $subject,
        public string $subjectReference,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;

/**
 * The evidence only we hold: what we observed and what a human approved.
 *
 * Read from the mirror, never hardcoded — an audit log that always claims zero
 * violations and no human oversight would misrepresent every session it
 * describes.
 */
final readonly class AuditEvidence
{
    /**
     * @param list<ProtocolViolation> $violations
     * @param list<ApprovalReceipt> $receipts
     */
    public function __construct(
        public array $violations,
        public array $receipts,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;

/**
 * The end-of-session transaction record (spec section 10), for a session that
 * reached an acceptance.
 *
 * A pure derivation over the chain, which is the point: producing it ourselves
 * is what proves our hashes match the counterparty's. Nothing is cached — a
 * stored record could go stale against its own chain.
 */
final readonly class TransactionRecord
{
    private const VERSION = '0.1';

    public function __construct(
        private ProtocolHash $hash,
        private OfferChainHash $chainHash,
    ) {}

    /**
     * @param list<Act> $acts
     *
     * @return array<string, mixed>
     */
    public function build(
        RecordParties $parties,
        array $acts,
        Act $acceptance,
        RecordSubject $subject,
        string $generatedAt,
    ): array {
        $offers = array_values(array_filter($acts, static fn (Act $act): bool => $act->isOffer()));
        $finalOffer = $offers === [] ? null : $offers[\count($offers) - 1];
        $firstOffer = $offers[0] ?? null;
        $sessionId = $acts === [] ? '' : $acts[0]->sessionId();

        $record = [
            'record_type' => 'a2cn_transaction_record',
            'record_version' => self::VERSION,
            'record_id' => SessionId::derive($sessionId),
            'session_id' => $sessionId,
            'generated_at' => $generatedAt,
            'parties' => [
                'initiator' => $parties->initiator->toArray(),
                'responder' => $parties->responder->toArray(),
            ],
            'deal_type' => $subject->dealType,
            'currency' => $subject->currency,
            'subject' => $subject->subject,
            'subject_reference' => $subject->subjectReference,
            'agreed_terms' => $finalOffer?->terms() ?? [],
            'negotiation_summary' => [
                'total_rounds' => \count($offers),
                'total_messages' => \count($acts),
                'session_created_at' => $acts[0]?->timestamp() ?? $generatedAt,
                'first_offer_at' => $firstOffer?->timestamp() ?? $generatedAt,
                'accepted_at' => $acceptance->timestamp(),
                'initiating_party_did' => $parties->initiator->did,
                'accepting_party_did' => $acceptance->senderDid(),
            ],
            'final_offer' => [
                'message_id' => $finalOffer?->messageId() ?? '',
                'sender_did' => $finalOffer?->senderDid() ?? '',
                'protocol_act_hash' => $finalOffer?->hash() ?? '',
                'protocol_act_signature' => $finalOffer?->signature() ?? '',
            ],
            'final_acceptance' => [
                'message_id' => $acceptance->messageId(),
                'sender_did' => $acceptance->senderDid(),
                'accepted_protocol_act_hash' => $finalOffer?->hash() ?? '',
                'acceptance_signature' => $acceptance->signature(),
            ],
            'offer_chain_hash' => $this->chainHash->of($acts),
            // Hashed with this field blank, so a third party can recompute it
            // by blanking it again.
            'record_hash' => '',
        ];

        $record['record_hash'] = $this->hash->of($record);

        return $record;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;

/**
 * The end-of-session audit log (spec section 10), for a session that reached a
 * terminal state without an acceptance.
 *
 * `ai_system_involved` is always true for this deployment: the negotiating
 * agent is an LLM under deterministic policy bounds.
 */
final readonly class AuditLog
{
    private const VERSION = '0.1';

    public function __construct(
        private OfferChainHash $chainHash,
    ) {}

    /**
     * @param list<Act> $acts
     *
     * @return array<string, mixed>
     */
    public function build(
        RecordParties $parties,
        array $acts,
        string $outcome,
        AuditEvidence $evidence,
        string $generatedAt,
    ): array {
        $first = $acts[0] ?? null;
        $last = $acts === [] ? null : $acts[\count($acts) - 1];
        $sessionId = $first?->sessionId() ?? '';
        $oversight = $evidence->receipts !== [];

        return [
            'log_type' => 'a2cn_audit_log',
            'log_version' => self::VERSION,
            'log_id' => SessionId::derive($sessionId . ':audit'),
            'session_id' => $sessionId,
            'record_id' => null,
            'generated_at' => $generatedAt,
            'session_outcome' => $outcome,
            'parties' => [
                'initiator' => $parties->initiator->toArray(),
                'responder' => $parties->responder->toArray(),
            ],
            'session_timeline' => [
                'session_init_at' => $first?->timestamp() ?? $generatedAt,
                'session_ack_at' => self::firstTimestampOfType($acts, 'session_ack'),
                'first_offer_at' => self::firstOfferTimestamp($acts),
                'terminal_state_at' => $last?->timestamp() ?? $generatedAt,
                'total_duration_seconds' => self::durationSeconds($first?->timestamp(), $generatedAt),
            ],
            'negotiation_log' => array_map(self::logEntry(...), $acts),
            'protocol_violations' => array_map(static fn (ProtocolViolation $v): array => $v->toArray(), $evidence->violations),
            'offer_chain_hash' => $this->chainHash->of($acts),
            'audit_metadata' => [
                'ai_system_involved' => true,
                'human_oversight_present' => $oversight,
                'autonomous_decision' => !$oversight,
                'human_approval_receipts' => array_map(static fn (ApprovalReceipt $r): array => $r->toArray(), $evidence->receipts),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function logEntry(Act $act): array
    {
        $total = $act->terms()['total_value'] ?? null;

        return [
            'sequence_number' => $act->sequenceNumber(),
            'message_type' => $act->messageType(),
            'message_id' => $act->messageId(),
            'sender_did' => $act->senderDid(),
            'timestamp' => $act->timestamp(),
            'round_number' => $act->roundNumber(),
            'total_value_offered' => \is_int($total) ? $total : null,
            'protocol_act_hash' => $act->hash(),
        ];
    }

    /** @param list<Act> $acts */
    private static function firstTimestampOfType(array $acts, string $type): ?string
    {
        foreach ($acts as $act) {
            if ($act->messageType() === $type) {
                return $act->timestamp();
            }
        }

        return null;
    }

    /** @param list<Act> $acts */
    private static function firstOfferTimestamp(array $acts): ?string
    {
        foreach ($acts as $act) {
            if ($act->isOffer()) {
                return $act->timestamp();
            }
        }

        return null;
    }

    private static function durationSeconds(?string $from, string $to): int
    {
        if ($from === null) {
            return 0;
        }

        $start = strtotime($from);
        $end = strtotime($to);
        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, $end - $start);
    }
}
```

Register: `$services->set(OfferChainHash::class); $services->set(TransactionRecord::class); $services->set(AuditLog::class);`

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "OfferChainHashTest|SessionOutcomeTest|TransactionRecordTest|AuditLogTest"`
Expected: PASS (13 tests).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config/services.php
composer run lint
git add src/Protocol/Record tests/Unit/Protocol/Record src/Resources/config/services.php
git commit -m "feat(protocol): derive the end-of-session transaction record and audit log"
```

---

### Task 19: Serving the acts and the records

**Files:**
- Create: `src/Protocol/Http/QuoteTerminalStateReader.php`, `src/Protocol/Http/A2cnRecordsController.php`
- Modify: `src/Resources/config/routes.php`, `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Http/A2cnRecordsControllerTest.php`

**Interfaces:**
- Consumes: `ActStoreInterface`, `TransactionRecord`, `AuditLog`, `SessionOutcome`, `A2cnIdentityResolver`, `QuoteGatewayInterface`.
- Produces:
  - `QuoteTerminalStateReader::__construct(QuoteGatewayInterface $gateway)`, `->for(string $quoteId, \DateTimeImmutable $now): ?QuoteTerminalState` where `QuoteTerminalState` carries `state`, `expired`, `quoteNumber`, `salesChannelId`, `acceptance` (the acceptance act off the chain, or null)
  - `A2cnRecordsController::acts(string $sessionId): JsonResponse`, `->record(string $sessionId): JsonResponse` on routes `/a2cn/sessions/{sessionId}/acts` and `/a2cn/records/{sessionId}`

**Authorization is the session id itself** — UUIDv5 over a quote UUID, so
unguessable, and the buyer already holds it. A public listing would let anyone
enumerate a merchant's deal terms. Keep the `ponytail:` note: capability URL, no
revocation; upgrade to a signed fetch if a leaked link ever matters.

Status codes, and the reasoning behind each:

| Situation | Response |
| --- | --- |
| No acts for the session | `404 {"status":"not_found"}` |
| Quote lookup throws | `502 {"status":"quote_state_unavailable"}` — a records request must not depend on Shopware being reachable through a generic 500 |
| Acceptance act present, but no offer in the chain | `409 {"status":"protocol_violation","reason":"accepted_without_offer"}` — reporting the buyer's protocol bug beats manufacturing a hollow record |
| Acceptance present with offers | `200` transaction record |
| No acceptance, terminal outcome | `200` audit log |
| No acceptance, still live | `409 {"status":"session_live"}` |

All records responses carry `Cache-Control: no-store`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnRecordsController;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditLog;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Protocol\Record\TransactionRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class A2cnRecordsControllerTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItServesTheMirroredActs(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $store = self::storeWith([1 => 'offer', 2 => 'counteroffer']);

        $response = self::controller($store, self::live())->acts($session);

        self::assertSame(200, $response->getStatusCode());
        $body = self::decode($response->getContent());
        self::assertSame($session, $body['session_id']);
        self::assertCount(2, $body['acts']);
        self::assertSame('no-store', $response->headers->get('Cache-Control'));
    }

    public function testAnUnknownSessionIsNotFound(): void
    {
        $response = self::controller(new InMemoryActStore(), self::live())->acts('not-a-session');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testALiveSessionHasNoRecordYet(): void
    {
        $store = self::storeWith([1 => 'offer', 2 => 'counteroffer']);

        $response = self::controller($store, self::live())->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('session_live', self::decode($response->getContent())['status']);
    }

    public function testATerminalSessionYieldsTheAuditLog(): void
    {
        $store = self::storeWith([1 => 'offer', 2 => 'counteroffer']);

        $response = self::controller($store, self::terminal())->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('a2cn_audit_log', self::decode($response->getContent())['log_type']);
    }

    public function testAnAcceptedSessionYieldsTheTransactionRecord(): void
    {
        $store = self::storeWith([1 => 'offer', 2 => 'counteroffer', 3 => 'acceptance']);
        $acceptance = $store->listBySession(SessionId::forQuote(self::QUOTE_ID))[2];

        $response = self::controller($store, self::accepted($acceptance))->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('a2cn_transaction_record', self::decode($response->getContent())['record_type']);
    }

    public function testAnAcceptanceWithoutAnOfferIsReportedAsAViolation(): void
    {
        $store = self::storeWith([1 => 'acceptance']);
        $acceptance = $store->listBySession(SessionId::forQuote(self::QUOTE_ID))[0];

        $response = self::controller($store, self::accepted($acceptance))->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('accepted_without_offer', self::decode($response->getContent())['reason']);
    }

    public function testAnUnreachableQuoteIsABadGatewayNotAServerError(): void
    {
        $store = self::storeWith([1 => 'offer']);

        $response = self::controller($store, null)->record(SessionId::forQuote(self::QUOTE_ID));

        self::assertSame(502, $response->getStatusCode());
    }

    /** @param array<int, string> $types */
    private static function storeWith(array $types): InMemoryActStore
    {
        $store = new InMemoryActStore();
        $session = SessionId::forQuote(self::QUOTE_ID);
        foreach ($types as $sequence => $type) {
            $raw = ProtocolFixtures::act($sequence, $session, $type === 'counteroffer' ? ProtocolFixtures::SELLER : ProtocolFixtures::BUYER, $type);
            $act = Act::fromArray($raw);
            self::assertNotNull($act);
            $store->append(new ActRecord($session, self::QUOTE_ID, $sequence, $act));
        }

        return $store;
    }

    private static function live(): QuoteTerminalState
    {
        return new QuoteTerminalState('replied', false, 'Q-1001', 'sales-channel', null);
    }

    private static function terminal(): QuoteTerminalState
    {
        return new QuoteTerminalState('declined', false, 'Q-1001', 'sales-channel', null);
    }

    private static function accepted(Act $acceptance): QuoteTerminalState
    {
        return new QuoteTerminalState('replied', false, 'Q-1001', 'sales-channel', $acceptance);
    }

    private static function controller(InMemoryActStore $store, ?QuoteTerminalState $state): A2cnRecordsController
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $chainHash = new OfferChainHash($hash);

        $reader = new class($state) extends \MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader {
            public function __construct(private readonly ?QuoteTerminalState $state)
            {
            }

            public function for(string $quoteId, \DateTimeImmutable $now): ?QuoteTerminalState
            {
                return $this->state;
            }
        };

        return new A2cnRecordsController(
            $store,
            new TransactionRecord($hash, $chainHash),
            new AuditLog($chainHash),
            $reader,
            \MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner::identities(),
        );
    }

    /** @return array<string, mixed> */
    private static function decode(string|false $content): array
    {
        self::assertIsString($content);
        $decoded = json_decode($content, associative: true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
```

The 502 case above returns `null` from the reader as a stand-in for "the quote
could not be read". Have `QuoteTerminalStateReader::for()` return `null` for
both "no such quote" and "the lookup failed" only if you also distinguish them
for the controller — the cleanest split is: the reader **throws** its own
`QuoteStateUnavailable` on a gateway failure and returns `null` when the quote
genuinely does not exist. Implement it that way and adjust the test's double to
throw for the 502 case and return `null` for a 404 case (add that test too).

- [ ] **Step 2: Run it and watch it fail**

Run: `composer run test -- --filter A2cnRecordsControllerTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

Write `QuoteTerminalState` (a readonly DTO: `state`, `expired`, `quoteNumber`,
`salesChannelId`, `?Act $acceptance`), `QuoteStateUnavailable extends \RuntimeException`,
and `QuoteTerminalStateReader`, which fetches the snapshot through the gateway,
computes `expired` from `lifecycle->expiresAt` against `$now`, and finds the
acceptance act by reading `ActChain::read($snapshot->lifecycle->customFields)`
and taking the last act whose `messageType()` is `'acceptance'`. Wrap gateway
failures in `QuoteStateUnavailable` (preserve `$previous`), and return `null` for
`QuoteNotFoundException`.

Then the controller:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditEvidence;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditLog;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParty;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordSubject;
use MerchantQuoteAgentPlugin\Protocol\Record\SessionOutcome;
use MerchantQuoteAgentPlugin\Protocol\Record\TransactionRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The act chain and the end-of-session records, over HTTP.
 *
 * Records are derived per request, never cached: a stored record can go stale
 * against its own chain, and deriving is cheap.
 *
 * Authorization is the session id itself — a UUIDv5 over a Shopware quote UUID,
 * so unguessable, and the buyer already holds it. A fully public endpoint would
 * let anyone enumerate a merchant's deal terms; "verification is open to
 * anyone" is about verifying a record you were given.
 * ponytail: capability URL, no revocation. Upgrade to a signed fetch on the UCP
 * key if a leaked link ever matters.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final readonly class A2cnRecordsController
{
    public function __construct(
        private ActStoreInterface $store,
        private TransactionRecord $transactionRecord,
        private AuditLog $auditLog,
        private QuoteTerminalStateReader $quotes,
        private A2cnIdentityResolver $identities,
    ) {}

    #[Route(path: '/a2cn/sessions/{sessionId}/acts', name: 'frontend.merchant_quote_agent.a2cn.acts', methods: ['GET'])]
    public function acts(string $sessionId): JsonResponse
    {
        $acts = $this->store->listBySession($sessionId);
        if ($acts === []) {
            return self::json(['status' => 'not_found'], 404);
        }

        return self::json([
            'session_id' => $sessionId,
            'acts' => array_map(static fn (Act $act): array => $act->raw(), $acts),
        ]);
    }

    #[Route(path: '/a2cn/records/{sessionId}', name: 'frontend.merchant_quote_agent.a2cn.record', methods: ['GET'])]
    public function record(string $sessionId): JsonResponse
    {
        $acts = $this->store->listBySession($sessionId);
        $quoteId = $this->store->quoteIdForSession($sessionId);
        if ($acts === [] || $quoteId === null) {
            return self::json(['status' => 'not_found'], 404);
        }

        try {
            $quote = $this->quotes->for($quoteId, new \DateTimeImmutable());
        } catch (QuoteStateUnavailable) {
            // A records request must not depend on Shopware being reachable
            // through a generic 500.
            return self::json(['status' => 'quote_state_unavailable', 'session_id' => $sessionId], 502);
        }

        if ($quote === null) {
            return self::json(['status' => 'not_found'], 404);
        }

        $parties = $this->partiesFor($acts, $quote);
        $generatedAt = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $acceptance = $quote->acceptance;
        if ($acceptance !== null) {
            return $this->accepted($sessionId, $acts, $acceptance, $parties, $quote, $generatedAt);
        }

        $outcome = SessionOutcome::for($quote->state, $quote->expired);
        if ($outcome === null) {
            return self::json(['status' => 'session_live', 'session_id' => $sessionId], 409);
        }

        return self::json($this->auditLog->build(
            $parties,
            $acts,
            $outcome,
            new AuditEvidence($this->store->listViolations($sessionId), $this->store->listReceipts($sessionId)),
            $generatedAt,
        ));
    }

    /**
     * @param list<Act> $acts
     */
    private function accepted(
        string $sessionId,
        array $acts,
        Act $acceptance,
        RecordParties $parties,
        QuoteTerminalState $quote,
        string $generatedAt,
    ): JsonResponse {
        // An acceptance with no prior offer is itself a protocol violation (the
        // buyer's chain, which we do not fully trust). Reporting that beats
        // manufacturing a record with empty agreed terms, which would be
        // indistinguishable from a bug in this code.
        $hasOffer = array_filter($acts, static fn (Act $act): bool => $act->isOffer()) !== [];
        if (!$hasOffer) {
            return self::json(['status' => 'protocol_violation', 'session_id' => $sessionId, 'reason' => 'accepted_without_offer'], 409);
        }

        $currency = $acts[\count($acts) - 1]->terms()['currency'] ?? 'EUR';

        return self::json($this->transactionRecord->build(
            $parties,
            $acts,
            $acceptance,
            new RecordSubject(
                dealType: \MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity::DEAL_TYPES[0],
                currency: \is_string($currency) ? $currency : 'EUR',
                subject: $quote->quoteNumber,
                subjectReference: 'quote:' . $quote->quoteNumber,
            ),
            $generatedAt,
        ));
    }

    /** @param list<Act> $acts */
    private function partiesFor(array $acts, QuoteTerminalState $quote): RecordParties
    {
        try {
            $identity = $this->identities->forSalesChannel($quote->salesChannelId);
        } catch (MissingSigningKey) {
            $identity = null;
        }

        $responderDid = $identity?->did ?? '';
        $responder = new RecordParty(
            organizationName: $identity?->organizationName ?? '',
            did: $responderDid,
            agentId: $identity?->agentId ?? '',
            verificationMethod: $identity?->verificationMethod ?? '',
        );

        // The counterparty, taken from the first act we did not sign.
        $theirs = null;
        foreach ($acts as $act) {
            if ($act->senderDid() !== $responderDid) {
                $theirs = $act;
                break;
            }
        }

        $initiator = new RecordParty(
            organizationName: '',
            did: $theirs?->senderDid() ?? '',
            agentId: $theirs?->senderAgentId() ?? '',
            verificationMethod: $theirs?->verificationMethod() ?? '',
        );

        return new RecordParties($initiator, $responder);
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
```

Import the controller in `src/Resources/config/routes.php`, inside the
`CommercialAvailability::isAvailableByClass()` branch (it depends on
`QuoteTerminalStateReader`, which depends on the gateway):

```php
        $routes->import(__DIR__ . '/../../Protocol/Http/A2cnRecordsController.php', 'attribute');
```

- [ ] **Step 4: Run it and watch it pass**

Run: `composer run test -- --filter A2cnRecordsControllerTest`
Expected: PASS (8 tests, including the 404-vs-502 split you added).

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src/Protocol tests/Unit/Protocol src/Resources/config
composer run lint
git add src/Protocol/Http tests/Unit/Protocol/Http src/Resources/config
git commit -m "feat(protocol): serve the act chain and the end-of-session records"
```

---

### Task 20: The signed seller mandate and discovery

**Files:**
- Create: `src/Protocol/Mandate/NegotiationBands.php`, `src/Protocol/Mandate/SellerMandateFactory.php`, `src/Protocol/Mandate/MandateSigner.php`, `src/Protocol/Http/A2cnDiscoveryController.php`, `src/Ucp/Profile/A2cnMandateProfileContributor.php`
- Modify: `src/Resources/config/routes.php`, `src/Resources/config/services.php`
- Test: `tests/Unit/Protocol/Mandate/SellerMandateFactoryTest.php`, `tests/Unit/Protocol/Mandate/MandateSignerTest.php`, `tests/Unit/Protocol/Http/A2cnDiscoveryControllerTest.php`

**Interfaces:**
- Consumes: `Config\QuoteAgentSettingsReader` (whatever the existing reader exposes — check it), `Policy\Data\NegotiationPolicy`, `A2cnKeyStore`, `A2cnIdentityResolver`, `ProtocolHash`, `CompactJws`.
- Produces:
  - `NegotiationBands::fromPolicy(NegotiationPolicy $policy): array<string, mixed>` (basis points)
  - `SellerMandateFactory->build(NegotiationPolicy $policy, A2cnIdentity $identity, \DateTimeImmutable $validFrom): array<string, mixed>`
  - `MandateSigner->sign(array $mandate, A2cnIdentity $identity, \DateTimeImmutable $now): array<string, mixed>` (the mandate plus a detached `proof`)
  - `A2cnDiscoveryController::discovery()`, `->didDocument()`, `->mandate()` on `/.well-known/a2cn-agent`, `/.well-known/did.json`, `/.well-known/a2cn-seller-mandate`

**Bands are basis points** (the A2CN `_bps` convention: 1500 = 15%); the merchant
configures whole percents, so the factory multiplies by 100. The grant boundary
is **inclusive**, mirroring `QuoteBandDecider`. `counterUpToBps` / `counterAtBps`
are **advisory only** — the agent never auto-counters; anything above the grant
ceiling escalates to a human, and these fields publish the guidance that human
follows.

- [ ] **Step 1: Write the failing tests**

Write three test classes:

1. `SellerMandateFactoryTest` — assert `mandate_type: 'declared'`, `agent_id`,
   `principal_organization`, `principal_did`, `authorized_deal_types`,
   `max_commitment_value` in minor units from `QuoteValueCeiling::net` (and that
   it is absent when no ceiling is configured), `max_commitment_currency`,
   `valid_from`/`valid_until` one year apart, and `negotiation_bands` with
   `autoGrantMaxBps: 1500` for a 15% `maxDiscountPercent`, `counterAtBps` from
   `counterOfferMaxPercent`, and `escalateAboveBps === autoGrantMaxBps`.
2. `MandateSignerTest` — sign a mandate with `TestActSigner::keyStore()`, then
   verify as a third party would: `CompactJws::verify($signed['proof']['jws'], $pem)`
   equals `$hash->of($mandateWithoutProof)`; assert the proof carries
   `type: 'JsonWebSignature2020'`, the identity's `verification_method` and a
   `created` timestamp; assert that flipping one byte of the mandate body makes
   the check fail.
3. `A2cnDiscoveryControllerTest` — the DID document lists exactly one
   `JsonWebKey2020` method whose `id` is the identity's verification method,
   `authentication` and `assertionMethod` both reference it, and the published
   JWK carries **no** `d` member; the discovery document carries `a2cn_version`
   `0.2`, `mandate_methods: ['declared']`, `conformance_level: 'acts'`,
   `mandate_url` and `records_url`; discovery and DID responses carry
   `Cache-Control: public, max-age=300`; a missing signing key yields `503`
   rather than a stack trace.

Use `TestActSigner` for the key and identity doubles, and build the
`NegotiationPolicy` fixture the way `tests/Unit/Policy` already does — check
those tests and reuse their builder rather than writing a new one.

- [ ] **Step 2: Run them and watch them fail**

Run: `composer run test -- --filter "SellerMandateFactoryTest|MandateSignerTest|A2cnDiscoveryControllerTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`NegotiationBands::fromPolicy()` — one static method, no state:

```php
        $limits = $policy->price;
        $autoGrantMaxBps = (int) round($limits->maxDiscountPercent * 100);
        $bands = [
            'autoGrantMaxBps' => $autoGrantMaxBps,
            'counterAtBps' => (int) round(($limits->counterOfferMaxPercent ?? $limits->maxDiscountPercent) * 100),
            'escalateAboveBps' => $autoGrantMaxBps,
        ];
        if ($limits->counterOfferMaxPercent !== null) {
            $bands['counterUpToBps'] = (int) round($limits->counterOfferMaxPercent * 100);
        }
```

plus the optional `delivery`, `payment` and `bundle` blocks from the
corresponding sub-policies when configured (percent fields to bps, money left in
the policy's currency unit) — read `src/Policy/Data/DeliveryPolicy.php`,
`PaymentPolicy.php` and `BundlePolicy.php` for the exact field names and mirror
them; an absent sub-policy contributes **no key** (absent, never null).

`SellerMandateFactory::build()` assembles the A2CN declared-mandate fields
(`mandate_type`, `agent_id`, `principal_organization`, `principal_did`,
`authorized_deal_types`, `max_commitment_value`, `max_commitment_currency`,
`valid_from`, `valid_until`) plus `negotiation_bands`. Document in the class
docblock that `negotiation_bands` is a **documented extension**, not normative
A2CN authority, and that the delivery/payment/bundle blocks publish the same
non-price authority the agent enforces.

`MandateSigner::sign()`:

```php
        $digest = $this->hash->of($mandate);
        $jws = CompactJws::sign($digest, $this->keys->current()->privateKeyPem);

        return $mandate + ['proof' => [
            'type' => 'JsonWebSignature2020',
            'verification_method' => $identity->verificationMethod,
            'created' => $now->format(\DATE_ATOM),
            'jws' => $jws,
        ]];
```

The proof is **detached**: the signature covers the mandate body with no `proof`
member, which is exactly what a verifier reconstructs by removing it.

`A2cnDiscoveryController` serves the three documents on the storefront scope,
resolving the identity from the request's host (`$request->getHost()` plus the
sales-channel id on the request attributes, `PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID`),
so one installation publishes correctly on every domain it answers on. Constants
for the three paths live on the controller and are reused by the discovery
document's `mandate_url` / `records_url` and by the profile contributor. Catch
`MissingSigningKey` and answer `503 {"status":"signing_key_missing"}` — a shop
that never generated a key should say so, not 500.

Finally port `A2cnMandateProfileContributor` from the fork
(`git -C ~/projects/agentic-commerce show feat/a2cn-act-carrier:src/Ucp/Profile/A2cnMandateProfileContributor.php`),
changing: namespace to `MerchantQuoteAgentPlugin\Ucp\Profile`, drop `@internal`,
drop the `SwagAgenticCommerce.config.a2cnDiscoveryUrl` config read (the URL is
our own route on the requested sales-channel domain — build it from the domain
resolver and the controller's path constant), and keep the capability id
`com.a2cn.negotiation-mandate`. Register it exactly like
`QuoteCapabilityProfileContributor` in `services.php`, including
`->autoconfigure(false)` and the lower priority that keeps the SDK's capability
filter from dropping it.

Import the controller in `routes.php` **outside** the commercial gate — the
mandate does not need the quote backend to be publishable:

```php
    $routes->import(__DIR__ . '/../../Protocol/Http/A2cnDiscoveryController.php', 'attribute');
```

- [ ] **Step 4: Run them and watch them pass**

Run: `composer run test -- --filter "SellerMandateFactoryTest|MandateSignerTest|A2cnDiscoveryControllerTest"`
Expected: PASS.

- [ ] **Step 5: Format, lint, commit**

```bash
vendor/bin/mago fmt src tests
composer run lint
git add src/Protocol/Mandate src/Protocol/Http src/Ucp/Profile src/Resources/config tests
git commit -m "feat(protocol): publish and sign the A2CN seller mandate"
```

---

### Task 21: Live proof, docs and the full gate

**Files:**
- Create: `tests/Integration/A2cnEmissionTest.php`
- Modify: `README.md`, `docs/superpowers/specs/2026-09-04-a2cn-protocol-module-design.md` (only if the build diverged from it)
- Test: the whole suite

**Interfaces:**
- Consumes: everything above.
- Produces: no new production interfaces.

- [ ] **Step 1: Write the live emission test**

Model it on `tests/Integration/UpdateQuoteTest.php` (same bootstrapping, same
quote fixture approach). It must prove the two things unit tests cannot:

```php
    public function testAnEmittedActLandsWithoutDisturbingSiblingKeys(): void
    {
        // Arrange: a real quote in `replied`, carrying a2cn_session, one buyer
        // act, and two unrelated plugin keys (the servicing fingerprint and the
        // baseline) written through the gateway.
        //
        // Act: run SellerActEmitter::observe() against the live snapshot.
        //
        // Assert: the quote now carries a2cn_act_0002_s; a2cn_act_0001_b, the
        // fingerprint and the baseline are all still there and unchanged; the
        // mirror holds both acts; and a second observe() writes nothing.
    }

    public function testTheEmittedActVerifiesAgainstThePublishedDidDocument(): void
    {
        // Fetch /.well-known/did.json through the kernel, take publicKeyJwk,
        // convert with PublicSigningKey::fromJwk, and verify the act's JWS.
        // This is the acceptance criterion a counterparty actually exercises:
        // published material alone must be enough to verify our act.
    }
```

Write the real bodies following `UpdateQuoteTest`'s helpers; the comments above
are the specification for them, not a substitute.

- [ ] **Step 2: Run the integration test and watch it fail, then pass**

Run: `composer run test:integration -- --filter A2cnEmissionTest`
Expected: FAIL first (no session on the fixture quote), then PASS once the
fixture is complete. If the shop's configured 40 EUR value ceiling escalates the
fixture quote, keep the fixture below it — see the project note about the live
shop's config differing from install-time defaults.

- [ ] **Step 3: Check the did.json route is actually ours**

Run:

```bash
grep -rn "well-known/did.json" vendor/ ../../../custom/plugins 2>/dev/null | grep -v merchant-quote-agent | head
```

If another plugin claims `/.well-known/did.json` on the same domain, switch the
identity to the path form (`did:web:<host>:quote-agent`, document served at
`/quote-agent/did.json`) and update `A2cnIdentity::forHost()` plus
`DidWebUrl::forDid()`'s expectations. Record what you found either way in the
commit message — the spec lists this as an open risk and it should not stay open.

- [ ] **Step 4: Document it**

Add a short `## A2CN evidence` section to `README.md`: what gets emitted and
when (a quote entering `replied` with changed terms, only when the quote carries
`a2cn_session`), the three published URLs, the two records URLs, how a
counterparty verifies an act (fetch the DID document, recompute
`base64url(SHA-256(JCS(signed view)))`, verify the JWS), where the key lives, and
the five carried-over caveats from the spec. Link the spec. Do not restate the
spec — link it.

- [ ] **Step 5: Full gate, then the branch**

```bash
composer run test
composer run quality
```

Both must pass. `quality` runs format, lint, Mago analyze, the file-length check,
jscpd, the dependency analyser and `composer audit`. Expect the dependency
analyser to want `guzzlehttp/guzzle` moved or confirmed (it is already a `require`,
so a shadow-dependency error means the wrong import) and jscpd to flag the three
near-identical `list*` methods in `DbalActStore` — if it does, either extract a
private `listDecoded(string $sql, string $sessionId, callable $hydrate)` helper or
add a narrowly scoped ignore with a comment explaining which duplication is
intentional. Do not raise a threshold.

Then, if the plan's self-review at the end of this file lists any deviation you
made (a different lock fixture, an interface instead of a non-final class, a
different did:web form), reflect it in the spec in the same commit — the spec is
the durable record.

```bash
git add -A
git commit -m "test(protocol): prove A2CN emission against the live shop, document the module"
git push -u origin worktree-feat-a2cn-port
gh pr create --title "A2CN protocol module: signed act chain, evidence checks, records, seller mandate" --body "Closes #76 — see docs/superpowers/specs/2026-09-04-a2cn-protocol-module-design.md"
```

---

## Plan Self-Review

Run against the spec after Task 21, before the PR:

1. **Spec coverage.** Every spec section maps to a task: module layout (all),
   emission flow (16, 17), four checks + local length guard (9, 10, 12),
   determinism rules (1, 3, 7, 8), act chain (5, 6), terms mapping (8), records
   (18, 19), identity/keys/discovery (14, 20), mandate (20), storage (13), HTTP
   (19, 20), testing strategy (every task + 21), acceptance criteria (21).
2. **Known deviations to carry back into the spec if they survive:**
   - The spec says `unit` comes from a line's declared unit; the Bridge DTO has
     no unit field, so Task 8 publishes `'piece'` for every line.
   - The spec lists four checks; Task 9 adds a fifth local guard
     (`chain_length_exceeded`) for our own read cap.
   - The spec says the key is generated at install; Task 14 also generates on
     `activate()` so an updated shop gets one.
3. **Placeholder scan.** Task 17's lock fixture and Task 21's integration bodies
   are the only deliberately-unwritten code, each with an explicit instruction to
   copy an existing pattern. No `LogicException` placeholder may survive into a
   commit.
4. **Type consistency.** `Act::fromArray` returns `?Act` everywhere;
   `EmissionOutcome` is constructed only through its named constructors;
   `ActStoreInterface` is the only store type any consumer names; identity is
   always `A2cnIdentity`, never a bare string pair.
