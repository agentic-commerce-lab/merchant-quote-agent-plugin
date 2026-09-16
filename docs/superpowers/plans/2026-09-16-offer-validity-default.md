# Offer Validity Default Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the agent stamping every auto-offer on a default configuration with an expiry of "now", and make the value that caused it impossible to configure again.

**Architecture:** Four independent surfaces, in order of increasing blast radius. The validation constraint on `QuoteLimits::$validityDays` turns `0` from an accepted value into a refusal; `config.xml` ships `14` so a fresh install is correct; a migration moves shops that already stored `0` onto `14`, because core never rewrites a config default that is already present; and `ExpirationOfferVerifier` gains the lower bound that would have caught all of this — an expiry in the past — so no future cause of a past expiry reaches a buyer.

**Tech Stack:** PHP 8.3, Shopware 6.7 plugin, Symfony Validator attributes, Shopware `MigrationStep`, PHPUnit 11, mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-09-16-offer-validity-default-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file. Enforced by mago (`strict-types`, level error, not disableable).
- Cyclomatic complexity per unit ≤ 10; parameter lists ≤ 5; nesting ≤ 4. Enforced by `mago lint`.
- Typed class constants (`private const int FOO = 1;`) — the codebase's existing style, e.g. `ExpirationOfferVerifier::DAY_IN_SECONDS`.
- Docblocks say **why**, with measured evidence, not what. Match the surrounding files; read the neighbours before writing.
- `0` remains the single value meaning "nobody set `validityDays`" in every path that can produce it (absent array key, cleared admin field, constructor default). **Do not** substitute `14` at those points. Validation is what rejects it.
- The upper-bound message `offer validity %s exceeds the %d-day window` is asserted verbatim by a fixture and must not change.
- Gates that must be green at the end: `composer run test` and `composer run quality`.
- Every commit message ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Branch is `fix/57-validity-days-default`. Do not push, do not open a PR, do not touch the GitHub issue.

---

### Task 1: `validityDays` must be positive

Turns the accepted-but-broken `0` into a loud configuration refusal, through the validator `QuoteAgentSettingsFactory` already runs.

**Files:**
- Modify: `src/Policy/Data/QuoteLimits.php:18-19`
- Test: `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`
- Test: `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `QuoteLimits::$validityDays` is `#[Assert\Positive]`. Any `QuoteLimits` constructed without an explicit `validityDays` now fails validation — later tasks and every future test that validates a policy must pass one.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`, add two cases to the end of `invalidConfigurations()` (after the `'blank model name'` line):

```php
        // #57. `null` is the merchant clearing the field and `0` is the
        // default this plugin used to ship; both wrote `+0 days` in
        // OfferApplier, i.e. an offer stamped as expired the moment it was
        // sent, and PositiveOrZero waved both through.
        yield 'cleared offer validity' => [['validityDays' => null], 'price.validityDays'];
        yield 'zero offer validity' => [['validityDays' => 0], 'price.validityDays'];
```

Replace `testAnUntouchedInstallEscalatesEverythingRatherThanFailing()` entirely with:

```php
    public function testAnUntouchedInstallEscalatesEverythingRatherThanFailing(): void
    {
        // Every field a merchant may leave blank, left blank. `validityDays`
        // stopped being one of them in #57: config.xml ships 14, and a blank
        // one is refused by invalidConfigurations() above rather than read as
        // "valid for no days at all".
        $settings = self::build([
            'maxDiscountPercent' => null,
            'counterOfferMaxPercent' => null,
            'maxQuoteValueNet' => null,
        ]);

        self::assertNotNull($settings);
        self::assertSame(0.0, $settings->policy->price->maxDiscountPercent);
        self::assertNull($settings->policy->price->valueCeiling);
        self::assertSame(14, $settings->policy->price->validityDays);
    }
```

In `tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php`, add `validityDays: 14` to the three existing `new QuoteLimits(...)` calls so they keep asserting the single path they are about. They currently rely on a `QuoteLimits` with no validity being otherwise valid, which this task ends:

```php
    public function testAnOutOfRangePriceCapIsReportedAtItsNestedPath(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 150.0, validityDays: 14));

        self::assertSame(['price.maxDiscountPercent'], self::paths(self::validator()->validate($policy)));
    }
```

```php
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            valueCeiling: new QuoteValueCeiling(['EUR' => 50_000.0, 'USD' => -1.0]),
            validityDays: 14,
        ));
```

```php
    public function testAValidPolicyReportsNothing(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            valueCeiling: new QuoteValueCeiling(['EUR' => 50_000.0]),
            validityDays: 14,
        ));

        self::assertSame([], self::paths(self::validator()->validate($policy)));
    }
```

And add, after `testAValidPolicyReportsNothing()`:

```php
    public function testAnUnsetValidityIsRejectedRatherThanReadAsZeroDays(): void
    {
        // #57. `0` is what every "nobody set this" path produces — an absent
        // array key, a cleared admin field, this constructor's own default —
        // and an offer valid for zero days is one sent already expired. The
        // constraint is the only thing standing between those three paths and
        // a buyer being told an offer is valid until today.
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0));

        self::assertSame(['price.validityDays'], self::paths(self::validator()->validate($policy)));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter 'QuoteAgentSettingsFactoryTest|NegotiationPolicyValidationTest'
```

Expected: FAIL. `cleared offer validity` and `zero offer validity` fail with "Invalid configuration was accepted."; `testAnUnsetValidityIsRejectedRatherThanReadAsZeroDays` fails comparing `[]` to `['price.validityDays']`; `testAnUntouchedInstallEscalatesEverythingRatherThanFailing` fails comparing `0` to `14`. The three edited tests that now pass `validityDays: 14` still pass — they are being made future-proof, not driven.

- [ ] **Step 3: Change the constraint**

In `src/Policy/Data/QuoteLimits.php`, replace

```php
        #[Assert\PositiveOrZero]
        public int $validityDays = 0,
```

with

```php
        /**
         * At least one day.
         *
         * `0` is what every path meaning "nobody set this" produces: an absent
         * key in fromArray() below, a cleared admin field in
         * NegotiationPolicyArray::build(), and this default. Keeping all three
         * at `0` and rejecting it here is deliberate — substituting a number
         * would be the plugin inventing a validity on the merchant's behalf.
         *
         * #57: this was PositiveOrZero and config.xml shipped `0`, so
         * OfferApplier wrote `+0 days` — an expiry of *now* — and
         * ExpirationOfferVerifier's one-sided window had nothing to say about
         * it. Every auto-offer on an untouched install went out already
         * expired, and the buyer reply said "valid until <today>".
         *
         * The default stays `0` rather than becoming `14`: no production code
         * reaches it (fromArray() is the only production constructor, and
         * NegotiationPolicyArray always supplies the key), so changing it
         * would only hide this constraint from the tests that construct
         * QuoteLimits directly.
         */
        #[Assert\Positive]
        public int $validityDays = 0,
```

- [ ] **Step 4: Say the same thing where the cleared field is mapped**

`src/Config/NegotiationPolicyArray.php:35` is where a cleared admin field becomes `0`. It carries no comment while the line above it explains `maxDiscountPercent`'s zero, which now reads as though the two zeros mean the same thing. Replace the `validityDays` line with:

```php
            // Also null when cleared, but there is no safe reading here: an
            // offer valid for zero days is one sent already expired (#57), so
            // this zero is the sentinel QuoteLimits' Positive constraint
            // rejects rather than a conservative default.
            'validityDays' => RawConfigValue::int($raw, 'validityDays') ?? 0,
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --filter 'QuoteAgentSettingsFactoryTest|NegotiationPolicyValidationTest'
```

Expected: PASS.

- [ ] **Step 6: Run the whole unit suite**

```bash
composer run test
```

Expected: PASS. If another test fails, it is a `QuoteLimits` built without a validity and then validated — fix it by stating `validityDays: 14`, not by weakening the constraint.

- [ ] **Step 7: Commit**

```bash
git add src/Policy/Data/QuoteLimits.php src/Config/NegotiationPolicyArray.php tests/Unit/Config/QuoteAgentSettingsFactoryTest.php tests/Unit/Policy/Data/NegotiationPolicyValidationTest.php
git commit -m "$(cat <<'EOF'
fix(config): refuse a validityDays of zero instead of shipping expired offers

PositiveOrZero accepted the `0` this plugin shipped as its default, and `0`
means OfferApplier writes `+0 days` — an expiry of now. Positive turns every
path that means "nobody set this" into the same loud configuration refusal
the other caps already get.

Refs #57

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: ship 14 as the default, and say so

Fixes fresh installs and the two places that document the value.

**Files:**
- Modify: `src/Resources/config/config.xml:59-64`
- Modify: `tests/Integration/PluginConfigTest.php:82`
- Modify: `docs/end-to-end.md:453`

**Interfaces:**
- Consumes: Task 1's `Assert\Positive` — the help text describes it.
- Produces: `config.xml` default of `14`, persisted as a native `int`.

- [ ] **Step 1: Write the failing test**

In `tests/Integration/PluginConfigTest.php`, in `testInstallTimeDefaultsArePersistedWithNativeTypes()`, change the last assertion to:

```php
        self::assertSame(14, $config->get(QuoteAgentSettingsReader::DOMAIN . 'validityDays'));
```

- [ ] **Step 2: Run it to verify it fails**

```bash
composer run test:integration -- --filter testInstallTimeDefaultsArePersistedWithNativeTypes
```

Expected: FAIL comparing `0` to `14` — **if a shop is reachable**. This suite needs a running Shopware. If it is not reachable, say so plainly and move on; do not claim it passed, and do not skip Step 1.

- [ ] **Step 3: Change the default**

In `src/Resources/config/config.xml`, replace the `validityDays` field with:

```xml
        <input-field type="int">
            <name>validityDays</name>
            <label>Default offer validity (days)</label>
            <defaultValue>14</defaultValue>
            <helpText>How long an offer the agent sends stays valid. At least 1: a blank or zero value takes this sales channel out of service rather than sending offers that have already expired.</helpText>
        </input-field>
```

- [ ] **Step 4: Update the merchant-facing table**

In `docs/end-to-end.md`, replace the `validityDays` row of the config table:

```markdown
| `validityDays` | `14` | How long an auto-offer stays valid. At least 1 — blank or `0` takes the channel out of service rather than sending an offer stamped as already expired. A shop updating from a release that defaulted this to `0` has that `0` rewritten to `14`; a value the merchant set is left alone. |
```

- [ ] **Step 5: Verify the XML still parses and the suite is green**

```bash
php -r 'var_dump(simplexml_load_file("src/Resources/config/config.xml") !== false);'
composer run test
```

Expected: `bool(true)` and PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/config/config.xml tests/Integration/PluginConfigTest.php docs/end-to-end.md
git commit -m "$(cat <<'EOF'
fix(config): default offer validity to 14 days

14 is the number this codebase has been assuming everywhere else — the
ReplyComposer fallback and every policy fixture — while the field that
actually decides it shipped 0. The help text states the new floor, since a
cleared field now takes the channel out of service.

Refs #57

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: give `ExpirationOfferVerifier` a lower bound

The authoritative post-write gate had a one-sided window and let the bug through. This is the defence that stops the next cause of a past expiry.

**Files:**
- Modify: `src/Policy/ExpirationOfferVerifier.php:23-36`
- Test: `tests/Fixtures/Policy/offer-verify.json`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: a second violation string, `offer validity <Y-m-d> is not in the future`. The existing `offer validity %s exceeds the %d-day window` is unchanged.

- [ ] **Step 1: Write the failing fixtures**

Append three cases to the array in `tests/Fixtures/Policy/offer-verify.json`. Each reuses the shape of the existing `"offer within all limits -> no violations"` case — same reference, same final, only `expirationDate` differs — so nothing but the expiry check can fire:

```json
  {
    "description": "an expiry already in the past is a violation, not only one too far out",
    "input": {
      "reference": {
        "quoteId": "q1",
        "quoteNumber": "1000",
        "stateTechnicalName": "in_review",
        "currencyIso": "EUR",
        "totalNet": 1000,
        "lines": [
          { "lineItemId": "l1", "quantity": 10, "unitPriceNet": 100, "totalNet": 1000 }
        ]
      },
      "final": {
        "quoteId": "q1",
        "quoteNumber": "1000",
        "stateTechnicalName": "in_review",
        "currencyIso": "EUR",
        "totalNet": 950,
        "lines": [
          { "lineItemId": "l1", "quantity": 10, "unitPriceNet": 95, "totalNet": 950 }
        ],
        "expirationDate": "2026-07-01T00:00:00Z"
      },
      "limits": { "maxDiscountPercent": 10, "maxQuoteValueNet": 5000, "validityDays": 14 },
      "now": "2026-07-22T12:00:00.000Z"
    },
    "expected": ["offer validity 2026-07-01 is not in the future"]
  },
  {
    "description": "an expiry of today is a violation -- this is exactly what validityDays 0 wrote (#57)",
    "input": {
      "reference": {
        "quoteId": "q1",
        "quoteNumber": "1000",
        "stateTechnicalName": "in_review",
        "currencyIso": "EUR",
        "totalNet": 1000,
        "lines": [
          { "lineItemId": "l1", "quantity": 10, "unitPriceNet": 100, "totalNet": 1000 }
        ]
      },
      "final": {
        "quoteId": "q1",
        "quoteNumber": "1000",
        "stateTechnicalName": "in_review",
        "currencyIso": "EUR",
        "totalNet": 950,
        "lines": [
          { "lineItemId": "l1", "quantity": 10, "unitPriceNet": 95, "totalNet": 950 }
        ],
        "expirationDate": "2026-07-22T00:00:00Z"
      },
      "limits": { "maxDiscountPercent": 10, "maxQuoteValueNet": 5000, "validityDays": 0 },
      "now": "2026-07-22T12:00:00.000Z"
    },
    "expected": ["offer validity 2026-07-22 is not in the future"]
  },
  {
    "description": "the smallest legal validity, one day out, passes",
    "input": {
      "reference": {
        "quoteId": "q1",
        "quoteNumber": "1000",
        "stateTechnicalName": "in_review",
        "currencyIso": "EUR",
        "totalNet": 1000,
        "lines": [
          { "lineItemId": "l1", "quantity": 10, "unitPriceNet": 100, "totalNet": 1000 }
        ]
      },
      "final": {
        "quoteId": "q1",
        "quoteNumber": "1000",
        "stateTechnicalName": "in_review",
        "currencyIso": "EUR",
        "totalNet": 950,
        "lines": [
          { "lineItemId": "l1", "quantity": 10, "unitPriceNet": 95, "totalNet": 950 }
        ],
        "expirationDate": "2026-07-23T00:00:00Z"
      },
      "limits": { "maxDiscountPercent": 10, "maxQuoteValueNet": 5000, "validityDays": 1 },
      "now": "2026-07-22T12:00:00.000Z"
    },
    "expected": []
  }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter OfferVerifierTest
```

Expected: FAIL — the first two new cases get `[]` where a violation is expected. The third already passes; it is the boundary that must stay passing.

- [ ] **Step 3: Add the lower bound**

Replace the body of `verify()` in `src/Policy/ExpirationOfferVerifier.php` with:

```php
    /** @return list<string> */
    public function verify(QuoteSnapshot $final, QuoteLimits $limits, \DateTimeImmutable $now): array
    {
        $expirationDate = $final->lifecycle->expirationDate;
        if ($expirationDate === null) {
            return [];
        }

        $expiresAt = (new \DateTimeImmutable($expirationDate))->getTimestamp();
        $shown = substr($expirationDate, offset: 0, length: 10);

        // #57. The window used to be one-sided: it asked only whether the
        // agent had been too generous. A validityDays of 0 made OfferApplier
        // write `+0 days`, an expiry of now, which sits comfortably inside
        // "no later than now + 1 day" — so the one gate whose job is to keep
        // an unauthorised offer unsent signed off on an offer that had
        // already expired.
        //
        // At or before `now`, with no grace period, is the right threshold for
        // how this value arrives: SnapshotAdapter::toPolicy() formats it as
        // 'Y-m-d', so the smallest legal validity of 1 reads back as tomorrow
        // at midnight and clears this bound by hours, while a validity of 0
        // reads back as today at midnight and fails it for every write after
        // 00:00. A grace period would have to be sub-daily to change any of
        // that, and would only blur the boundary.
        if ($expiresAt <= $now->getTimestamp()) {
            return [sprintf('offer validity %s is not in the future', $shown)];
        }

        // One extra day of slack for timezone conversion of date-only asks.
        $latestAllowed = $now->getTimestamp() + (($limits->validityDays + 1) * self::DAY_IN_SECONDS);
        if ($expiresAt <= $latestAllowed) {
            return [];
        }

        return [sprintf('offer validity %s exceeds the %d-day window', $shown, $limits->validityDays)];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --filter OfferVerifierTest
composer run test
```

Expected: PASS, including the nine pre-existing `offer-verify.json` cases and both other fixture files.

- [ ] **Step 5: Commit**

```bash
git add src/Policy/ExpirationOfferVerifier.php tests/Fixtures/Policy/offer-verify.json
git commit -m "$(cat <<'EOF'
fix(policy): flag an offer expiry that is not in the future

The expiration check was a one-sided window: it caught an expiry too far out
and had nothing to say about one already past, which is why a validityDays of
0 reached buyers through the gate meant to stop exactly that. The bound costs
three lines and covers every other way a past expiry could arise -- a write
that did not land, clock skew, a merchant editing the quote mid-pass.

Refs #57

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: migrate the `0` that already exists in every installed shop

Without this, Tasks 1 and 2 together take every existing installation's agent offline on update. See the spec's "The upgrade path" for why that, and not this, would be the silent change.

**Files:**
- Create: `src/Migration/Migration1789500000DefaultOfferValidityDays.php`
- Create: `tests/Unit/Migration/OfferValidityDefaultMigrationTest.php`

**Interfaces:**
- Consumes: Task 2's default of `14`, which this writes.
- Produces: `Migration1789500000DefaultOfferValidityDays::isZero(string $configurationValue): bool` — static, database-free, the whole decision the migration makes per row.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Migration/OfferValidityDefaultMigrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Migration;

use MerchantQuoteAgentPlugin\Migration\Migration1789500000DefaultOfferValidityDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which stored `system_config` values the #57 migration may rewrite, without
 * a database.
 *
 * The envelope is decoded in PHP rather than matched in SQL because
 * `system:config:set` without `--json` stores the *string* "0" — the trap
 * docs/end-to-end.md warns about — and that row is as broken as the numeric
 * one. A value that does not decode is left alone: one we cannot read is not
 * one we may overwrite.
 */
final class OfferValidityDefaultMigrationTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function storedValues(): iterable
    {
        yield 'the shipped default' => ['{"_value":0}', true];
        yield 'a float zero' => ['{"_value":0.0}', true];
        yield 'set from the CLI without --json' => ['{"_value":"0"}', true];
        yield 'a validity the merchant set' => ['{"_value":14}', false];
        yield 'a validity of one' => ['{"_value":1}', false];
        yield 'an explicitly null value' => ['{"_value":null}', false];
        yield 'a non-numeric string' => ['{"_value":"none"}', false];
        yield 'no envelope' => ['0', false];
        yield 'undecodable junk' => ['{"_value":', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('storedValues')]
    public function testOnlyAStoredZeroIsRewritten(string $stored, bool $expected): void
    {
        self::assertSame($expected, Migration1789500000DefaultOfferValidityDays::isZero($stored));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit --filter OfferValidityDefaultMigrationTest
```

Expected: FAIL — class `Migration1789500000DefaultOfferValidityDays` not found.

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1789500000DefaultOfferValidityDays.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Moves every shop still holding the old `validityDays` default of `0` onto
 * `14` (#57).
 *
 * Editing config.xml reaches nobody who already has the plugin. Core writes
 * every `defaultValue` into `system_config` at install
 * (`SystemConfigService::saveConfig()`), and on update calls
 * `savePluginConfiguration()` with `$override = false`, which writes a default
 * only `if (!isset($relevantSettings[$key]))` — i.e. never for a key that is
 * already there. Every existing installation therefore holds a literal `0`
 * row that no change to config.xml can touch, and `0` is what made
 * OfferApplier write `+0 days`: an expiry stamped at the instant of the write,
 * with the buyer told the offer was valid until today.
 *
 * Only rows whose stored value reads as zero are rewritten, at every scope. A
 * merchant who typed a number keeps it. `0` is not a setting anyone can have
 * meant — an offer valid for no days cannot be accepted — so this is the
 * plugin correcting a value the plugin itself wrote, not overwriting a
 * choice. The alternative, letting the new `Assert\Positive` reject the stored
 * `0`, would take every installed shop's agent out of service on update over
 * that same value; see the design doc for the argument.
 *
 * A shop cannot arrive back here by clearing the field: that path now fails
 * configuration validation instead, so this migration is a one-off.
 */
class Migration1789500000DefaultOfferValidityDays extends MigrationStep
{
    private const string KEY = 'MerchantQuoteAgentPlugin.config.validityDays';

    private const int DEFAULT_VALIDITY_DAYS = 14;

    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789500000;
    }

    /**
     * Whether one stored `system_config` value is the broken zero.
     *
     * Static and database-free so the only decision this migration makes can
     * be tested directly, which is also why the `{"_value": ...}` envelope is
     * decoded here rather than matched with `JSON_EXTRACT(...) = 0`:
     * `system:config:set` without `--json` stores the string `"0"` (see
     * docs/end-to-end.md), and SQL's comparison of a JSON string against a
     * number is not something to rely on across MySQL and MariaDB.
     *
     * Anything that does not decode, or decodes to something other than a
     * zero-valued number or numeric string, is left alone.
     */
    public static function isZero(string $configurationValue): bool
    {
        $decoded = json_decode($configurationValue, true);
        $value = \is_array($decoded) ? $decoded['_value'] ?? null : null;

        if (!\is_int($value) && !\is_float($value) && !(\is_string($value) && is_numeric($value))) {
            return false;
        }

        return (float) $value === 0.0;
    }

    /** @throws DbalException|\JsonException */
    #[Override]
    public function update(Connection $connection): void
    {
        /** @var list<array{id: string, configuration_value: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT `id`, `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::KEY],
        );

        $fixed = json_encode(['_value' => self::DEFAULT_VALIDITY_DAYS], \JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            if (!self::isZero($row['configuration_value'])) {
                continue;
            }

            $connection->executeStatement(
                'UPDATE `system_config` SET `configuration_value` = :value WHERE `id` = :id',
                ['value' => $fixed, 'id' => $row['id']],
            );
        }
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing to drop: this migration rewrote one value in place.
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --filter OfferValidityDefaultMigrationTest
composer run test
```

Expected: PASS.

- [ ] **Step 5: Check the analyzer is happy with the class**

```bash
composer run typecheck
composer run lint
```

Expected: PASS. If `check-throws` objects to `json_encode` despite `JSON_THROW_ON_ERROR` being in the `@throws` list, mirror whatever `src/Migration/NegotiationStrategyTextConfigRows.php` does — it makes the same call — rather than inventing a new suppression.

- [ ] **Step 6: Commit**

```bash
git add src/Migration/Migration1789500000DefaultOfferValidityDays.php tests/Unit/Migration/OfferValidityDefaultMigrationTest.php
git commit -m "$(cat <<'EOF'
fix(migration): rewrite a stored validityDays of 0 to 14

Config defaults are system_config rows written at install, and plugin update
calls savePluginConfiguration() with $override = false, so it never rewrites
a key that already exists: changing config.xml leaves every installed shop on
its stored 0. Without this, the new Positive constraint would take those
shops' agents out of service on update over a value the plugin itself wrote.

Only a row that reads as zero is touched, at every scope, decoded in PHP so
the string "0" a CLI set without --json leaves behind is caught too.

Refs #57

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: pin the expiry end to end

The unit tests so far pin configuration and verification. This pins the thing the buyer sees: that the value written to the quote is the configured validity ahead of the write, not "now".

**Files:**
- Modify: `tests/Unit/Servicing/FakeQuoteGateway.php` (the `updateQuote()` method and a new public property)
- Test: `tests/Unit/Negotiation/OfferApplierTest.php`

**Interfaces:**
- Consumes: `NegotiationFixture::settings()`, whose policy already states `validityDays: 14` (`tests/Unit/Negotiation/NegotiationFixture.php:128`).
- Produces: `FakeQuoteGateway::$quoteUpdates`, a `list<QuoteUpdate>` in call order, for any later test that needs to see what was written rather than only that something was.

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Negotiation/OfferApplierTest.php`, after `testAQuoteWideOfferWritesADiscountAndAnExpiry()`:

```php
    public function testTheWrittenExpiryIsTheConfiguredValidityAheadOfTheWrite(): void
    {
        // #57 end to end. Asserting that *an* expiry was written is what the
        // suite did before, and `+0 days` satisfies that: the quote came back
        // stamped with an expiry of now and the buyer was told the offer was
        // valid until today. The value is the assertion.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        $expiresAt = $gateway->quoteUpdates[0]->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertSame((new \DateTimeImmutable('+14 days'))->format('Y-m-d'), $expiresAt->format('Y-m-d'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit --filter testTheWrittenExpiryIsTheConfiguredValidityAheadOfTheWrite
```

Expected: FAIL — `FakeQuoteGateway::$quoteUpdates` is not defined.

- [ ] **Step 3: Record the writes in the fake**

In `tests/Unit/Servicing/FakeQuoteGateway.php`, add the property next to the existing `$customFieldWrites` declaration:

```php
    /** @var list<QuoteUpdate> every quote update in call order, so a test can assert what was written and not only that something was */
    public array $quoteUpdates = [];
```

and record it as the first thing `updateQuote()` does after logging the call:

```php
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        $this->calls[] = 'updateQuote';
        $this->quoteUpdates[] = $update;
        $this->firstExpectedRevision ??= $expected;

        if ($update->customFields !== null) {
            $this->customFieldWrites[] = $update->customFields;
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --filter OfferApplierTest
composer run test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Servicing/FakeQuoteGateway.php tests/Unit/Negotiation/OfferApplierTest.php
git commit -m "$(cat <<'EOF'
test(negotiation): pin the written expiry, not just that one was written

The suite asserted that OfferApplier called updateQuote; `+0 days` satisfied
that assertion all the way to production. This one fails if the expiry is not
the configured validity ahead of the write.

Refs #57

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: gates

**Files:** none — verification only.

- [ ] **Step 1: Full unit suite**

```bash
composer run test
```

Expected: PASS, with a test count of at least the 1110 the branch started with, plus the cases added above.

- [ ] **Step 2: Full quality gate**

```bash
composer run quality
```

Expected: PASS across format, lint, mago analyze, file size, the admin `.check.mjs` suites, jscpd, dependency analysis and composer audit. Fix anything it reports; `composer run format` rewrites formatting.

- [ ] **Step 3: Integration suite, honestly**

```bash
composer run test:integration
```

Expected: PASS if a shop is reachable. If it is not, report that plainly — "the integration suite needs a running shop and none was reachable" — and never as a pass. `PluginConfigTest` is also known to fail against a shop whose config has been set by hand; if it fails that way, say which test and why rather than changing the test.

- [ ] **Step 4: Confirm the branch is clean and nothing was pushed**

```bash
git status --short
git log --oneline main..HEAD
```

Expected: a clean working tree and seven commits (the spec, the plan, and the five implementation commits from Tasks 1-5) on `fix/57-validity-days-default`. Do not push, do not open a PR, do not comment on or close issue #57.
