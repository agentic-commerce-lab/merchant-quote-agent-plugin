# Strategy assignment: pins, rules, and a weighted split

Date: 2026-09-18

## Status

Approved, not yet implemented.

## Why

The strategy library landed with exactly one assignment mechanism: a
`<component>` in `config.xml` naming one strategy per sales channel. That was
deliberate — the 2026-09-14 design's "Deliberately out of scope" section says
so, and says why: everything expensive was already in place, so pre-building
the cheap part would have been speculative scaffolding.

The merchant ask is now concrete, and it is three things at once:

- a **percentage split**, so 20% of customers meet strategy A and 80% meet B;
- **rule-based** assignment, using the rule builder merchants already know;
- a **customer pin**, so a named account always gets a named strategy.

They are not alternatives. A merchant wants to pin their three largest accounts
to a consultative posture *and* A/B the rest of the book. So this is one
precedence chain, not a mode switch.

## What we verified before designing

All of it re-read against this working tree and against the parity shop
(`agenticquote-shoelscher`, Shopware 6.7.13.1, SwagCommercial present) on
2026-09-18.

**Shopware rules can be evaluated against a quote, including cart
conditions.** This was the open question and the answer is yes. Every
`RuleScope` in core requires a `SalesChannelContext`
(`Framework/Rule/RuleScope.php`, both accessors abstract and non-nullable),
and the cart-shaped conditions additionally require a `Cart`. Both are
obtainable for an existing quote, and the plugin already uses half of the
machinery:

- `SalesChannelContextRestorer::restoreByQuote($quoteId, $context)` returns a
  full `SalesChannelContext` with the quote's customer, customer group,
  currency, language, shipping method and payment method applied. Already
  called from `src/Bridge/QuoteRecalculator.php:29`.
- `QuoteToCartConverter::convertToCart($quote, $salesChannelContext)` returns
  a real `Cart`. Public, not `@internal` — only its constructor carries that
  annotation, the same shape as the two services the bridge already injects.
  `QuoteCalculator::recalculate()` calls it itself.

With both, `new CartRuleScope($cart, $context)` is a quote's rule scope.

**Coverage, counted over core's 117 rule classes:**

| Scope the condition requires | Count | Matches under `CartRuleScope`? |
| --- | ---: | --- |
| `CartRuleScope` | 50 | yes |
| `LineItemScope` (derived from the cart) | 40 | yes |
| Checkout / customer only | 16 | yes |
| `FlowRuleScope` only | 11 | **no — silently false** |

The last row is the hazard worth naming, and it is not the cart one. Core's
conditions open with a scope guard that *returns false* rather than throwing —
`GoodsPriceRule::match()` is
`if (!$scope instanceof CartRuleScope && !$scope instanceof LineItemScope) return false;`.
A merchant who builds an order-placed condition gets a rule that never matches
and no error anywhere. Building a `CartRuleScope` rather than a
`CheckoutRuleScope` moves 90 of the 117 conditions out of that trap; the
remaining 11 stay in it and are documented in the admin instead.

**`rule.payload` is readable server-side and only server-side.**
`RuleDefinition.php:78` declares it
`(new BlobField('payload', 'payload'))->removeFlag(ApiAware::class)`, so the
compiled `Rule` object hydrates through the DAL but never crosses the Admin
API. Resolution therefore has to happen in PHP on the servicing path, which is
where it happens anyway.

**Rule ordering already exists.** `rule.priority` is a core field with admin
UI, so "first matching rule wins" needs no ordering column of our own.

**The measurement half is already built and on `main`.**
`strategy-measures.ts` groups every dashboard measure by strategy, carries N
per row, reports the version spread it collapsed, and counts quotes whose
passes name more than one strategy as `mixed`. Nothing in this design needs to
recompute a measure.

## The shape

A ladder, walked once per pass, at the point where settings are already read:

```
StrategyAssignmentResolver::assign(QuoteSnapshot, Context): ?ResolvedStrategy

  1. pin    customer_id = snapshot.identity.customerId
  2. rule   rows joined to `rule`, ORDER BY rule.priority DESC, first match
  3. split  sha1(customerId . salesChannelId) % 100 -> weighted bucket
  4. (null) -> caller keeps the strategy the config key already resolved
```

`customerId` is the B2B **company**, per `QuoteIdentity`'s own docblock: one
id covering every employee and every organization unit of an account. That is
the right grain for all three rungs. A pin means "this company"; a split
bucket means "this company", so an account meets one posture consistently
rather than a different one per employee.

Each rung is scoped the way the configuration store already scopes everything
else: a row with `sales_channel_id NULL` applies everywhere, a row naming a
channel wins over it. Rungs resolve independently, so a global pin beats a
channel-specific rule — the ladder order is the precedence, not the scope.

### Wiring

Shorter than the 2026-09-14 design predicted. It expected `PromptComposer`
would have to take a resolved strategy instead of reading
`$settings->strategyPrompt`. It does not need to:

1. `QuoteAgentSettingsReader::resolveStrategy()` is unchanged. The config key
   stays rung 4 and keeps producing a prompt and a version id.
2. `QuoteAgentSettings` gains `withStrategy(ResolvedStrategy $strategy): self`,
   beside the existing `withPolicy()`.
3. `ServicingPreflight::check()` gains one collaborator, calls
   `assign()` after the settings read, and returns
   `$settings->withStrategy($assigned)` when a rung matched.

`PromptComposer`, `NegotiationPipeline` and `DecisionRecorder` are untouched.
`strategyVersionId` already rides from settings to the decision row via
`NegotiationPipeline.php:236`, so the audit trail follows for free.

### Laziness on the rule rung

Rung 2 costs a quote read with three associations, a
`restoreByQuote()` and a `convertToCart()`. All three run only if rung 2 has
at least one row for this sales channel, and the scope is built once and
reused across every row. A shop running only a split pays nothing.

**The no-SwagCommercial lane needs nothing from this design.**
`src/Resources/config/services.php:571` early-returns out of the entire
commercial block, and `ServicingPreflight` is registered at line 761 — after
it. On a shop without SwagCommercial there is no servicing path at all, so the
resolver never runs and the scope factory is never built. Both register inside
that gate, and the factory's two commercial service ids take
`ignoreOnInvalid()` exactly like `QuoteRecalculator`'s, so a SwagCommercial
rename surfaces as a TypeError on a non-nullable `object` parameter rather than
taking the whole shop's container down at compile time.

## Data model

One table, `merchant_quote_agent_strategy_assignment`:

```sql
CREATE TABLE IF NOT EXISTS `merchant_quote_agent_strategy_assignment` (
    `id`               BINARY(16)  NOT NULL,
    `kind`             VARCHAR(16) NOT NULL,
    `sales_channel_id` BINARY(16)  NULL,
    `customer_id`      BINARY(16)  NULL,
    `rule_id`          BINARY(16)  NULL,
    `weight`           INT(11)     NULL,
    `strategy_id`      BINARY(16)  NOT NULL,
    `created_at`       DATETIME(3) NOT NULL,
    `updated_at`       DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.mqasa.customer` (`sales_channel_id`, `customer_id`),
    KEY `idx.mqasa.kind_channel` (`kind`, `sales_channel_id`),
    CONSTRAINT `fk.mqasa.rule_id` FOREIGN KEY (`rule_id`)
        REFERENCES `rule` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.mqasa.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
        REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `ck.mqasa.kind` CHECK (
        (`kind` = 'customer' AND `customer_id` IS NOT NULL AND `rule_id` IS NULL AND `weight` IS NULL)
     OR (`kind` = 'rule'     AND `rule_id`     IS NOT NULL AND `customer_id` IS NULL AND `weight` IS NULL)
     OR (`kind` = 'split'    AND `weight`      IS NOT NULL AND `customer_id` IS NULL AND `rule_id` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Migration `Migration1789600000CreateStrategyAssignment`, hand-written like
`Migration1789400000CreateQuoteAgentStrategy` — attribute entities carry no
schema generator, so the table and the entity class must be kept in step by
hand.

**An explicit `kind` rather than one derived from which column is non-null.**
The derived version reads fine while you are writing it and is the thing
someone decodes at 3am. The CHECK constraint then makes the two
representations unable to disagree.

**The unique key does not catch every duplicate pin.** MySQL treats NULLs as
distinct in a unique index, so two global pins for the same customer —
`(NULL, customerX)` twice — are accepted by the schema. The index still catches
the channel-scoped case, which is the common one. Rung 1 therefore reads with
`ORDER BY sales_channel_id IS NULL, created_at LIMIT 1` so a duplicate resolves
deterministically rather than by row order, and the admin grid refuses to add a
second pin for a customer already pinned at the same scope. A generated column
to make the index total is available if duplicates ever appear in the wild;
both belt and braces for a row a merchant creates by hand is not worth it now.

**No foreign key on `customer_id`.** Core's `customer` rows are deletable and
a pin outliving its account is harmless — rung 1 simply never matches again.
A cascade there would be a silent config change on customer deletion.

**`ON DELETE CASCADE` on `rule_id`.** This is the one place the design does
*not* mirror `QuoteAgentSettingsReader`'s refusal of a dangling reference.
Deleting a rule happens in core's rule builder, which offers no hook to refuse
from, so the choice is between a cascade and a dangling row that silently
never matches. The cascade at least removes the assignment the merchant can
see.

**No archival column, unlike `Strategy`.** A decision row records the strategy
version it used and — see below — how that strategy was assigned, so history
stays readable with the assignment row gone.

## Behaviour when a rule cannot be evaluated

`convertToCart()` throws when the quote's customer has no active shipping
address, or when an association is missing.

**Rung 2 is skipped and the ladder continues**, with a warning log line naming
the quote and the sales channel. Not an escalation: a missing shipping address
is a data condition, not a misconfiguration, and escalating every such quote
to a human is worse for the merchant than servicing it on the next rung.

The whole rung is skipped, never part of it. A half-evaluated rule list would
pick a strategy by accident of ordering, which is worse than not picking one.

This is the one rung whose failure is invisible in the negotiation itself, so
it is made visible in the audit instead — which is the next section, and the
reason that column exists.

**An assignment naming a missing or archived strategy is a different case and
escalates.** `assign()` resolves the matched `strategy_id` through the existing
`StrategyResolver`, so a dangling reference raises `UnknownStrategy` exactly as
it does for the config key. The resolver lets it surface as
`InvalidQuoteAgentConfiguration`, and `ServicingPreflight` escalates on the path
it already has. This is not inconsistent with the fall-through above: a rule the
merchant cannot evaluate is a data condition, while a strategy row the merchant
archived while an assignment still points at it is a configuration they made in
our own UI, and silently negotiating with a different posture than they
configured is the failure `QuoteAgentSettingsReader` already refuses.

## Audit

One nullable column on `merchant_quote_agent_decision`:
`strategy_assignment_source`, one of `pin`, `rule`, `split`, `config`. Written
by `DecisionRecorder` beside `strategy_version_id`, which it explains.

Migration `Migration1789600001AddAssignmentSourceToDecision`, modelled on
`Migration1789400003AddStrategyVersionToDecision`.

`AnonymizedDecision` passes it through unpseudonymized. It is a four-value
enum describing plugin configuration, not a shop identifier — pseudonymizing
it would destroy the only thing it is for while protecting nothing.

## Admin surfaces

A second tab on the existing strategies page, not a new page and not
`config.xml`. A precedence chain with three row kinds does not fit a config
component, and the page it belongs beside already exists.

Three grids, in ladder order, so the page reads the way resolution runs:

1. **Pinned customers** — customer select, strategy select, optional sales
   channel.
2. **Rules** — `sw-entity-single-select` on `rule`, strategy select, optional
   sales channel, and a read-only priority column sourced from the rule so the
   merchant can see the order without leaving the page. A snippet under the
   grid states that order and flow conditions never match here.
3. **Split** — strategy, weight, optional sales channel. Weights are shown as
   a percentage of their own sum, so a merchant typing 1 and 4 sees 20% and
   80% rather than a validation error.

ACL: `merchant_quote_agent_strategy_assignment:read` joins the `viewer` role's
privilege list; write joins whichever role already writes the strategy
library. `rule:read` is added to `viewer` for the rule grid's labels.

The per-strategy measures table gains an `assignment` spread column, rendered
exactly like the `versions` spread it already renders. That is where a
fall-through becomes legible: a row labelled with a strategy and showing
`rule: 12, config: 40` means the rule matched far less often than the merchant
believes it does.

## Decisions

**The split is sticky and hashed on `customerId`, not on quote id.** A
re-rolled bucket would give the same buyer a different negotiating personality
on their next quote, and would contaminate the comparison with within-company
variance. `sha1(customerId . salesChannelId)`, first 8 hex digits, mod 100.
The sales channel is in the hash so a company in a two-channel shop can land
in different arms per channel, which is what per-channel weights imply.

**N weighted arms, not exactly two.** `[{strategy, weight}]` needs the same
validation as two fields ("weights must be positive") and makes a third arm a
row rather than a schema change.

**Customer groups are not a fourth rung.** Customer group is already a
checkout-scope rule condition, so it works on rung 2 today. A second pin list
keyed on groups would be a rung that rung 2 already covers.

**Rule order comes from `rule.priority`.** Core field, core UI, already
understood by merchants, and one fewer column to keep consistent.

**The split is standing configuration, with no experiment lifecycle.** No
start date, no end date, no winner promotion. The dashboard already reports
per-strategy measures with N, and B2B quote volume is small enough that a
merchant reading those numbers and changing the weights by hand is the honest
mechanism. An auto-promoting winner would need a significance model, a
minimum-sample guard, and a story for why the agent silently changed how it
negotiates — all of it before the first merchant has run a single split.

## Considered and rejected

**Fold all three into rules, with a custom hash-bucket condition.** Fewest
concepts and the most reuse of UI merchants know. Rejected because "give 20%
of customers arm A" then becomes a rule-builder chore performed once per arm,
and pinning one account becomes a rule with a customer condition — both of
them worse than the row they replace.

**A mode switch: split *or* rules *or* pins, per channel.** Simplest to
explain and to read in the KPI view. Rejected because it makes the actual ask
— pin the key accounts, A/B everything else — impossible.

**Degrade to `CheckoutRuleScope` when the cart cannot be built.** Would keep
the 16 customer-scope conditions working. Rejected: a merchant's cart
condition would then silently return false instead of the rung being skipped,
which is the exact trap this design exists to avoid.

**Filtering the 11 flow-only conditions out of the rule select.** Requires
mirroring a core list that changes between versions, to hide conditions that a
snippet can warn about in one sentence.

## Landing order

Two pull requests, in this order, because the first is testable without the
second:

1. **The ladder.** Entity, migration, `StrategyAssignmentResolver`,
   `QuoteRuleScopeFactory`, the `withStrategy()` clone, the
   `ServicingPreflight` call, the audit column, and every test below.
   Assignments are seedable by API, so the whole ladder is provable with no UI.
2. **The admin tab.** Three grids, ACL, snippets, and the `assignment` spread
   column on the measures table.

## Testing

**A frozen hash fixture, and it is the one that matters.** A table of
`customerId` to bucket, asserted value by value, so a later refactor of the
split function cannot silently re-randomize every live experiment on every
shop. Same reasoning as the byte-for-byte prompt fixtures in the strategy
library: the value is not just correct, it is *load-bearing across time*.

**`StrategyAssignmentResolverTest`** covers the ladder itself: rung order;
global-versus-channel precedence *within* each rung; bucket boundaries with
weights summing to zero, to 100, and to neither; and that a scope failure
skips the entire rule list rather than the rows after the failure.

**`QuoteAgentSettings::withStrategy()`** — one assertion, that it clones every
other field.

**Integration, on a shop with SwagCommercial:** build a real `CartRuleScope`
from a seeded quote and match a `cartGoodsPrice` rule against it. This is the
test that proves the finding above rather than this document's reading of it,
and the legacy-compatibility work is the precedent: running it against a real
shop found breakages that reading the same code did not.

**`GatewayWiringTest`** gains `QUOTE_TO_CART_CONVERTER`, resolved against the
live shop like the other commercial service ids. Static analysis cannot see
these strings, so this test is the only thing that catches a SwagCommercial
rename.

## What must not change

`strategy_version_id` keeps being written for every pass, by the same code
path, whichever rung chose the strategy. It is what the entire dashboard
aggregates on, and `strategy_assignment_source` explains it rather than
replacing it.

`QuoteAgentSettingsReader` keeps refusing a dangling configured strategy id.
Rung 4 is still the config key, and a merchant pointing it at an archived
strategy is still a misconfiguration that escalates.
