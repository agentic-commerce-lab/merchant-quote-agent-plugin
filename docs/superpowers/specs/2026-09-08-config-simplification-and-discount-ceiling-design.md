# Config simplification and the discount ceiling

Date: 2026-09-08

## Status

Approved, not yet implemented.

## Context

Two problems surfaced from live quotes on the sw-ag.dev test shop.

**The agent grants more discount than the buyer asked for.** Quote 1017 asked for
2.70% and got 5%. Quote 1018 asked for 3.41% on 15 units and got 5%. Both were
inside the 15% `maxDiscountPercent`, both were authorized and verified, and both
gave away margin nobody requested.

The cause is a missing bound, not a broken one. The rules-only path is already
correct: `QuoteAutoReplyPricer:61` takes `min(requestedUnitPrice, unitPriceNet)`
and so can never price below the ask. The LLM path has no equivalent. Its
`AuthorityBrief` publishes the volume tier as guidance —

    - volume pricing the merchant publishes: 10+ units → 5.00%

— bounded only by `maxDiscountPercent` and checked afterwards by
`OfferAuthorizer`. Nothing anywhere compares the offer against what the buyer
actually asked for, so the model anchors on the tier and the checks wave it
through.

**The config surface advertises terms the agent never honours.** `AskGate`
escalates every non-price ask to a human before any decider sees it, and
`NegotiationAsks::hasAny()` covers delivery, payment *and* bundle. So
`PaymentDecider`, `DeliveryDecider` and their supporting verifiers are
unreachable: seven admin fields tune logic that cannot execute.

They are not inert, though. `NegotiationBands::withSubPolicies()` folds all
three sub-policies into the signed, published `com.a2cn.negotiation-mandate`
document — the capability the shop advertised during the 1018 buyer test. The
shop therefore publishes payment and delivery bands to counterparty agents and
honours none of them.

## Decisions

1. Cap every offer at the discount the buyer asked for.
2. Stop suggesting the volume tier to the model.
3. Retire the Payment and Delivery config, and drop their bands from the
   published mandate.
4. Delete the policy code those two sections fed, within the limits in
   "What must not be deleted" below.
5. Keep rules-only mode.
6. Relabel the volume-tier field so its format is legible.

## Design

### 1. The discount ceiling

Both enforcement points already bound against the same number:
`PriceOfferCheck:20` for the total, and `LinePriceOfferCheck`'s `floorFactor`
per line. So rather than add a ceiling check beside them, tighten that number
for the duration of the pass:

    effectiveMax = min(
        policy.price.maxDiscountPercent,
        max(0, MoneyMath::requestedDiscount(snapshot) ?? policy.price.maxDiscountPercent)
    )

`OfferRound::play()` already holds both the snapshot and the settings, so this is
one derivation at the top of the method. Everything downstream inherits it with
no signature change:

- `AuthorityBrief` states the tightened cap, so the model is told "up to 3.41%"
  rather than being told 15% and then clamped. Not anchoring high is the
  substance of the fix; the clamp is the backstop.
- `PriceOfferCheck` rejects a total discount above the ask.
- `LinePriceOfferCheck` raises the per-line floor to the ask.
- `OfferAuthorizer` inherits both.

`max(0, …)` is load-bearing: `MoneyMath::requestedDiscount()` returns a negative
percentage when the buyer's target is *above* the quoted total, which would
otherwise invert the floor into a mandatory markup.

When the buyer states no target, `requestedDiscount()` returns null and the
configured cap stands unchanged. Volume tiers still set the auto-grant band
through `BundleDecider`; they simply stop being an amount the model may offer.

The rules-only path is not touched.

This fits the grain of what is already there. `AuthorityBrief::of()` takes a
`$counteredRequestPercent` and, when set, tells the model *"the buyer asked for
%.2f%%, which is above your cap: counter, do not grant it"* — the exact mirror of
this change, for asks above the cap rather than below it. The gap was only ever
the downward direction. The two compose without interfering:

| buyer asks | `effectiveMax` | brief says |
|---|---|---|
| 25%, cap 15% | 15% (unchanged) | counter, do not grant |
| 3.41%, cap 15% | **3.41%** | grant up to 3.41% |
| nothing | 15% (unchanged) | grant up to 15% |

In the first row `max(0, 25)` exceeds the cap, so `min()` leaves the cap
standing and the existing counter instruction still fires.

### 2. The tier leaves the brief

Delete `AuthorityBrief::bundle()`. `BundleDecider` keeps classifying the band and
`BundleBand` stays in the mandate, so published volume pricing survives as a
threshold and as a protocol claim — it stops being a number suggested to a model
that will then exceed the buyer's ask to reach it.

### 3. Retiring Payment and Delivery

- `src/Resources/config/config.xml` — delete both cards, seven fields:
  `deliveryFreeShippingAboveNet`, `deliveryMaxShippingWaiverNet`,
  `deliveryExpeditedAllowed`, `deliveryCommittedLeadTimeDaysMin`,
  `paymentAllowedTerms`, `paymentMaxNetDays`, `paymentMinDepositPercent`.
- `QuoteAgentSettingsReader` — drop the seven keys.
- `NegotiationPolicyArray` — stop building the `payment` and `delivery`
  sections; both become null.
- `NegotiationBands::withSubPolicies()` — drop the payment and delivery bands.
  Keep bundle.
- `OfferLimitsBuilder` — `OfferLimits` loses its payment and delivery members.

Existing `system_config` rows stay on disk. They are ignored once the keys leave
the reader, they cost nothing, and leaving them is what makes a revert a code
revert rather than a data restore. No migration.

Behaviour does not change: `AskGate` escalated every non-price ask before this
change and still does. What changes is that the shop stops publishing bands it
does not honour.

### 4. What must not be deleted

Two traps, both found while scoping the deletion.

**The extract-side ask types stay.** `NegotiationAsks`, `DeliveryAsk`,
`PaymentAsk`, `BundleAsk` and the extract prompt's `negotiation` section are what
`AskGate::hasNonPriceAsk()` reads to decide to escalate. Delete them and a
shipping ask stops being *seen*, so instead of reaching a human it is silently
dropped — trading an advertised-but-unhonoured term for a lost buyer request,
which is worse. The model must keep extracting non-price asks precisely so the
agent can refuse them.

**`NonPriceTermsDecider` stays on the live path.** `NegotiationDecider::decide()`
calls it unconditionally and aggregates its band into `overall`
(`NegotiationDecider:36-39`). It simplifies to a constant granting, bandless
decision with no policy to read, but it cannot be removed: `overall` must remain
equal to the price band, which is what the pipeline's gate documents and relies
on.

Everything else that exists only to serve those two config sections goes: the
payment and delivery deciders, their grant verifiers and offer checks, the
`Offered*`/`*Decision`/`*OfferLimits` types they carry, the two decision
mergers, and `PaymentBand`/`DeliveryBand`. The implementation plan pins the exact
file list; scoping found roughly twenty files and about 690 lines under
`src/Policy/` matching the two policy types, of which `NonPriceTermsDecider` and
`OfferLimitsBuilder` survive in reduced form.

### 5. Rules-only mode stays

It is not dead code. `OfferProposer:62` and `ReplyComposer:78` read it to skip
model calls two and three while still running extract. Since
`NegotiationPipeline` deliberately refuses to fall back to rules-only on error,
this switch is the only way to obtain deterministic negotiation on purpose —
for a flaky provider, or a merchant who will not send buyer comments to an LLM.

### 6. The volume-tier field

`bundleVolumeTiers` keeps its `minQty:percent` string format and gains a label
and help text stating it outright: *"Minimum quantity : discount %. `10:5, 25:8`
means 10+ units → 5%, 25+ units → 8%."* A repeatable two-column field would read
better but needs a custom admin component for one field, which is not worth it.

## Testing

Test-driven, one failing test per change first.

The load-bearing case reproduces quote 1018 exactly: a buyer asking 3.41% on 15
units, a `10:5` tier, a 15% cap. It asserts the granted discount does not exceed
3.41% and that no line is priced below the buyer's €700 target. A second case
covers the no-target quote, asserting the configured cap still applies
unchanged, and a third covers a target above list, asserting the floor does not
invert.

`AuthorityBrief`'s test asserts the tier line is gone and the stated cap is the
tightened one.

For the retirement: the mandate tests (`NegotiationBandsTest`,
`SellerMandateFactoryTest`, `MandateDocumentResponderTest`,
`A2cnDiscoveryControllerTest` and its fixtures) assert the payment and delivery
bands are absent and that bundle survives. Any pinned canonical bytes or hashes
covering the mandate are regenerated in the same commit; a pinned-byte test that
changes silently is worse than one that fails.

One test must be added that does not exist today: that a delivery or payment ask
still escalates to a human after the deletion. That is the regression the
"must not be deleted" section exists to prevent, and nothing currently pins it.

## Consequences

**Good**

- The agent stops giving away unrequested margin on every tiered quote.
- The published mandate becomes truthful about what the shop will decide.
- The admin config loses seven fields that could not affect anything.
- Roughly 690 lines of unreachable policy code leave the repository.

**Bad, and accepted**

- Capping at the ask means a buyer entitled to a published 5% tier who asks for
  3.41% receives 3.41%. The mandate still advertises the tier, so a buying agent
  can read the entitlement and observe it was not volunteered. Accepted
  deliberately: revenue over volunteering, and dropping the tier from the brief
  keeps the agent from *claiming* the tier while underdelivering it.
- Deleting the non-price deciders means wiring non-price terms up later starts
  from git history rather than from live code. The extract side survives, so the
  asks are still captured and escalated in the meantime.
- The mandate shrinks, which is a change to a signed document counterparties
  read. No consumer is known to require the payment or delivery bands, but this
  cannot be proven from inside this repository.

**Revisit if**

- `QuoteUpdate` gains the ability to write delivery or payment terms, at which
  point answering non-price asks becomes possible and the config should return
  together with the deciders.
- A counterparty is found to depend on the payment or delivery bands.

## References

- `src/Negotiation/AskGate.php` — the non-price and structural escalations
- `src/Negotiation/AuthorityBrief.php:108-129` — the tier as guidance
- `src/Policy/MoneyMath.php:16-23` — `requestedDiscount()`
- `src/Policy/QuoteAutoReplyPricer.php:61` — the bound the rules path already has
- `src/Protocol/Mandate/NegotiationBands.php` — what the mandate publishes
- Quote 1017 (clarification bug, fixed in #94) and quote 1018 (buyer test) on
  sw-ag.dev
