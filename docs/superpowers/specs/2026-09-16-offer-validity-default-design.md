# Offer validity: a default that is not "already expired"

Date: 2026-09-16

## Status

Approved, not yet implemented. Closes #57.

## Context

`validityDays` is the one merchant cap whose default value is not conservative
but simply wrong. Everything below was re-read against the working tree on
2026-09-16; the line numbers are this tree's.

**The value.** `src/Resources/config/config.xml:61-64` ships
`<defaultValue>0</defaultValue>`. `src/Config/NegotiationPolicyArray.php:35`
maps a cleared field to `0`. `src/Policy/Data/QuoteLimits.php:19,40` defaults
to `0` in both the constructor and `fromArray()`. The constraint is
`Assert\PositiveOrZero`, so `0` validates.

**What the value does.** `src/Negotiation/OfferApplier.php:130` writes

    $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $limits->validityDays));

`+0 days` is *now*. The quote's `expiresAt` is stamped at the instant of the
write, and `src/Negotiation/ReplyComposer.php:60` then tells the buyer "the
offer is valid until <today>". A merchant who enables the agent and never
opens "Default offer validity (days)" sends every auto-offer already expired.

**Why the gate does not catch it.** `src/Policy/ExpirationOfferVerifier.php:26`
computes `now + (validityDays + 1) days` and returns no violation when the
offer's expiry is *at or below* that. It is a one-sided bound: it asks whether
the agent was too generous, never whether it wrote a date in the past. With
`validityDays = 0` the bad value is inside the window it defines, so the
authoritative post-write gate — the one class in the pipeline whose whole job
is to refuse an offer the merchant did not authorise — waves it through.

**Where 14 comes from.** `ReplyComposer.php:60` already falls back to
`new \DateTimeImmutable('+14 days')` when the quote carries no `expiresAt` at
all, and every test fixture in `tests/Fixtures/Policy/` states `"validityDays":
14`. The codebase has been asserting that 14 is the sane default everywhere
except in the field that decides it.

### What an existing shop actually has stored

This is the part that decides the shape of the fix, so it was verified in core
rather than assumed.

`PluginLifecycleService::installPlugin()` (vendor, line 157) calls
`savePluginConfiguration($pluginBaseClass, true)`, and
`SystemConfigService::saveConfig()` (vendor, lines 389-403) writes every
element's `defaultValue` into `system_config`. The defaults are not resolved at
read time — they are **rows**. `tests/Integration/PluginConfigTest.php:82`
pins exactly that: `assertSame(0, $config->get(... . 'validityDays'))` with no
`set()` beforehand, described in its own docblock as "the install-time values".

`PluginLifecycleService::updatePlugin()` (vendor, line 297) calls
`savePluginConfiguration($pluginBaseClass)` — `$override = false` — and
`saveConfig()` then writes a default only `if ($override || !isset($relevantSettings[$key]))`.

So: **every shop that has ever installed this plugin holds a literal `0` row
for `validityDays`, and changing `config.xml` alone will never touch it.** A
fix that stops at the default value fixes nothing for anybody who already has
the plugin, which is everybody it is broken for.

## Questions I would have asked

The house workflow asks these of a human; this ran unattended, so each is
recorded with the assumption taken in its place.

1. **Is `validityDays = 0` ever a deliberate merchant setting?** Assumed **no**.
   There is no reading of "this offer is valid for zero days" that a merchant
   could want: the buyer cannot accept it, and the reply misstates it. Every
   other zero in this config means something ("0 means every price ask
   escalates"); this one means "broken". The whole upgrade-path decision below
   rests on this assumption, so it is stated first.
2. **Is taking an existing shop's agent out of service on update acceptable as
   the loud failure?** Assumed **no** — see Approach B.
3. **Should there be an upper bound (an offer valid for 9,000 days)?** Assumed
   **not now**. `Positive` is what #57 asks for, an absurd-but-positive validity
   is visible in the admin field and harms nobody, and inventing a ceiling
   invents a merchant policy. Revisit if a shop ever files it.
4. **Should `ReplyComposer`'s hard-coded `'+14 days'` fallback become
   `validityDays`?** Assumed **no** — see "Considered and rejected".

## The upgrade path

Three ways to get an already-installed shop off its stored `0`. This is the
only genuinely contested decision in the issue.

**A — migrate the stored `0` to `14`.** Existing shops start sending 14-day
offers. Costs: behaviour changes on update without the merchant choosing it.

**B — leave the stored value alone and let `Positive` reject it.** Truest to
"never change behaviour silently": on the next servicing pass the config fails
validation, `InvalidQuoteAgentConfiguration` is thrown, and — per
`docs/end-to-end.md`, "Invalid configuration is refused whole ... takes the
whole sales channel out of service" — the agent stops answering quotes until
the merchant sets a number.

Rejected. B is not the quiet option; it is the *loudest possible* option
applied to a value the merchant never chose. Updating the plugin would take
every existing installation's agent offline, with no warning in the update
itself, over a field whose value the plugin itself wrote. Trading "sends
expired offers" for "silently stops working" is not a smaller behaviour change
on update — it is a bigger one, and it is the one the merchant is least able to
diagnose.

**C — A and B together, which is what "loud" actually costs.** Migrate the
stored `0` to `14`, *and* make the field `Positive` so it cannot come back.

**Chosen: C.** The two halves are not independent, and neither is correct
alone:

- `Positive` without the migration is Approach B with extra steps: it takes
  every existing shop offline.
- The migration without `Positive` fixes today's shops and leaves the trap
  armed — clear the field tomorrow and `NegotiationPolicyArray`'s `?? 0` puts
  the bug straight back.

On "silent behaviour change on update", which this codebase does forbid: the
migration rewrites **only** rows whose stored value is exactly the old shipped
default, `0`. Per assumption 1 that is not a merchant's setting being
overwritten — it is the plugin correcting a value the plugin itself wrote and
that denotes a broken state. A merchant who typed `7` keeps `7`; a merchant who
typed `30` keeps `30`. The change is recorded in the migration's docblock and in
`docs/end-to-end.md`'s config table, which is where a merchant reads what an
update did.

## Decisions

1. **`config.xml` defaults `validityDays` to `14`,** and gains a `helpText`
   saying what the field controls and that it must be at least 1. Fresh
   installs get a working agent out of the box; this is the half of the fix
   that #57 names and the half that helps nobody who already has the plugin.

2. **`QuoteLimits::$validityDays` becomes `Assert\Positive`.** `0` is the value
   `NegotiationPolicyArray` produces for a cleared field, and after this change
   it fails validation with `price.validityDays: This value should be positive.`
   — the same loud refusal the other caps already get, through the existing
   `QuoteAgentSettingsFactory` → `$this->validator->validate($policy)` path. No
   new validation machinery.

3. **The `?? 0` fallbacks in `NegotiationPolicyArray::build()` and
   `QuoteLimits::fromArray()` stay, and are documented as the rejection
   sentinel.** This is deliberate and is the opposite of what a first reading
   suggests. `0` is now the single value meaning "nobody set this", in all three
   places it can arise (absent key, cleared admin field, constructor default),
   and the one rule covering all three is "validation rejects it". Substituting
   `14` at those points would be the plugin inventing a validity on the
   merchant's behalf and would silently swallow exactly the cleared field #57
   wants to hear about.

   The constructor default `= 0` stays for the same reason. It is reached by 28
   test construction sites and by no production code — `fromArray()` is the only
   production constructor, and `NegotiationPolicyArray` always supplies the key
   — so in production `validityDays` can only ever be a validated positive
   integer. One test (`NegotiationPolicyValidationTest::testAValidPolicyReportsNothing`)
   asserts today that a `QuoteLimits` built without a validity is valid; that
   assertion is now wrong and changes.

4. **A migration rewrites every stored `validityDays` of `0` to `14`.**
   `Migration1789500000DefaultOfferValidityDays`, following
   `Migration1789400002MigrateNegotiationStrategyText` and
   `NegotiationStrategyTextConfigRows`: read the `system_config` rows for
   `MerchantQuoteAgentPlugin.config.validityDays` at every scope, decode the
   `{"_value": ...}` envelope in PHP rather than in SQL, and `UPDATE` by row id
   only where the decoded value is numerically zero.

   Decoding in PHP, not in a `JSON_EXTRACT(...) = 0` predicate, is not
   fastidiousness: `system:config:set` without `--json` stores the *string*
   `"0"` (`docs/end-to-end.md` warns about exactly this), and SQL's JSON
   comparison of a JSON string to a number is the kind of thing that differs
   between MySQL and MariaDB. The decision "is this row a zero" is testable
   without a database and gets a unit test.

   Rows the merchant set to anything else are untouched, as is any row whose
   value does not decode — a value we cannot read is not one we may overwrite.

5. **`ExpirationOfferVerifier` gains the lower bound it never had.** An
   `expirationDate` at or before `now` is a violation:

       offer validity <date> is not in the future

   This is the gate that should have caught #57 and did not, and it is what
   stops the next cause of a past expiry — a clock skew, an `updateQuote` whose
   `expiresAt` silently did not land, a merchant editing the quote's expiry
   between the write and the read — from reaching a buyer. It costs three lines
   in a class whose one-sided window is the actual defect.

   `at or before now`, with no grace period, is the right threshold given how
   the value arrives: `SnapshotAdapter::toPolicy()` formats `expiresAt` as
   `'Y-m-d'`, so the smallest legal validity (`1`) reads back as tomorrow's
   date at midnight — comfortably future — while `validityDays = 0` reads back
   as today at midnight, which is in the past for every write after 00:00. A
   grace period would have to be smaller than a day to be useful and would then
   only blur the boundary. A `null` expirationDate still returns no violation,
   unchanged: nothing was written, so there is nothing to measure.

## Considered and rejected

- **Driving `ReplyComposer`'s `'+14 days'` fallback from `validityDays`.** That
  fallback fires only when the quote we just wrote comes back with no
  `expiresAt` at all, which decision 5 now makes a verification failure. Wiring
  the policy into a branch that is about to become unreachable adds a
  dependency to buy nothing. It stays as the literal it is.
- **An upper bound on `validityDays`** — see question 3.
- **Removing the constructor default so the type system enforces a validity.**
  28 construction sites, all tests, none of which validate. A large diff to
  restate a rule that decision 3 states in one place.

## Testing

Pinning tests first, per #57's own request.

1. **`QuoteAgentSettingsFactoryTest`** — a raw config with `validityDays => 0`
   (and with it cleared to `null`) throws `InvalidQuoteAgentConfiguration`
   naming `price.validityDays`. This is the buyer-visible bug reduced to one
   assertion: before the fix it returns settings, after it refuses. The
   existing test at line 87 asserting `0` survives as a valid settings object is
   the one this replaces.
2. **`NegotiationPolicyValidationTest`** — a `QuoteLimits` with no validity now
   reports `['price.validityDays']`; a valid policy states one.
3. **`OfferVerifierTest`** — new fixture cases in
   `tests/Fixtures/Policy/offer-verify.json`: an expiry before `now` and an
   expiry equal to `now` each produce the not-in-the-future violation; an expiry
   one day out produces none. The existing nine cases stay green — their
   expiries are all future relative to their fixture `now`.
4. **Migration unit test** — the "is this row a zero" decision over `{"_value":0}`,
   `{"_value":"0"}`, `{"_value":14}`, `{"_value":null}` and undecodable junk,
   with no database.
5. **`PluginConfigTest::testInstallTimeDefaultsArePersistedWithNativeTypes`** —
   the install-time default is `14`, still persisted as a native `int`. That
   test exists because the *type* Shopware stores the default with is
   load-bearing; changing the value must not change the type.
6. **`OfferApplierTest`** — an applied offer's `expiresAt` is `validityDays`
   ahead of the write, so the fixed default is visible end to end rather than
   only in config.

Integration tests need a running shop; #57 is reproducible and verifiable
entirely in the unit suite.

## What must not change

- The one-sided upper bound in `ExpirationOfferVerifier` and its message text
  (`offer validity %s exceeds the %d-day window`) — one fixture asserts it
  verbatim and it is a port of the retired TS agent's `verifyExpiration`.
- `maxDiscountPercent`'s `0` default and its meaning. It is the safe reading of
  "unset" for that field — escalate everything — and the reason the two zeros
  read differently is the point of decision 3.
- The stored rows of any merchant who set a validity themselves.
