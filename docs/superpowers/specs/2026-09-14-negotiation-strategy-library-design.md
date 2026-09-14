# A library of negotiation strategies, versioned

Date: 2026-09-14

Source brief: [Quote Agent — negotiation strategy requirements](https://linear.app/agentic-commerce-lab/document/quote-agent-negotiation-strategy-requirements-d75a0a66f1d1)

## Status

Approved.

## Why

`negotiationStrategy` is a free-text textarea in `config.xml`. A merchant faces
a blank box and has to invent, from nothing, the commercial posture of an agent
that will negotiate on their behalf. Most will type nothing, and the agent
falls back to a neutral tone.

The brief asks for three curated built-in strategies a merchant can pick
instead, plus the ability to save named strategies of their own and reuse them.
It also asks that each negotiation record which strategy produced it — which
the free-text field cannot do, because the text can be edited at any time and
nothing remembers what it used to say.

Separately, and unrelated to any of that: the LLM API key sits in
`system_config` as plaintext, readable by any token holding
`system_config:read` and by anyone with a database dump. This design adds an
environment-variable route that keeps it out of the database entirely.

## What we verified before designing

- `config.xsd` line 21 (`vendor/shopware/core/System/SystemConfig/Schema/config.xsd`)
  allows `<component name="...">` inside a `<card>`. A registered admin
  component can render inside the ordinary plugin configuration page, bound to
  a config key. We do not have to move the other four cards anywhere.
- `merchant-quote-agent-access` is an existing hand-built settings page in this
  module with its own sales-channel switcher, writing `system_config` through
  the admin API. The strategy library page copies its shape rather than
  inventing one.
- `merchant_quote_agent_decision` has no foreign keys — `quote_id` and
  `sales_channel_id` are bare `BINARY(16)`. The audit table's convention is
  already "reference by id, survive the referent".
- `ServicingPreflight.php:71` resolves settings from
  `$snapshot->identity->salesChannelId`, and that same identity already carries
  `customerId` (read at `OfferRound.php:45` to build buyer history). Customer
  context is already present at the one call site that chooses a strategy.
- `QuoteAgentSettingsFactory` is pure — no Shopware — and its docblock says so.
  Resolution must therefore happen in the reader, not the factory.
- `defuse/php-encryption` is present in `vendor/` but only transitively, and
  core does not use it. It is not a dependency we may lean on.

## The shape

Three surfaces, each owning one thing.

**A versioned library** in its own tables. Strategies are lineages; prompts are
immutable versions of a lineage. Editing a prompt appends a version, it never
mutates one.

**A Settings page** owns the library: list, create, duplicate, edit, rename,
archive.

**A `<component>` in `config.xml`** owns the assignment: which strategy this
sales channel uses. One select, bound to one config key, saved by the config
page's own Save button like every other field.

The split matters. The library is shop-global entity data; the assignment is
per-sales-channel configuration with inheritance. Shopware already does the
second thing for free and cannot do the first at all.

## Data model

Two new tables, hand-written migrations, DAL attribute entities in the style of
`QuoteDecisionRecord` — attribute entities carry no schema generator, so schema
and class ship together (AGENTS.md).

`merchant_quote_agent_strategy` — the lineage:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BINARY(16)` | PK |
| `name` | `VARCHAR(255)` | not null |
| `description` | `LONGTEXT` | the brief's "Purpose" line; merchant-authored and optional for custom strategies. For the three built-ins this holds the English text, and the admin prefers the snippet keyed on the row's id — see below |
| `archived_at` | `DATETIME(3)` | null while live |
| `created_at` / `updated_at` | `DATETIME(3)` | |

`merchant_quote_agent_strategy_version` — the immutable prompt:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BINARY(16)` | PK |
| `strategy_id` | `BINARY(16)` | indexed |
| `version` | `INT` | unique together with `strategy_id` |
| `prompt` | `LONGTEXT` | not null |
| `created_at` | `DATETIME(3)` | |

`merchant_quote_agent_decision` gains `strategy_version_id BINARY(16) NULL`
plus an index. No foreign key, per the table's existing convention: an audit
row must keep resolving after anything it references is gone.

### Deletion is archival

Hard-deleting a strategy would destroy exactly the snapshot that versioning
exists to preserve. `archived_at` hides a strategy from the selector and from
the page's default listing while its versions keep resolving on old decisions.

### Two invariants, enforced in PHP

A `PreWriteValidationEvent` subscriber, `Strategy\StrategyWriteGuard`:

1. `merchant_quote_agent_strategy_version` rejects `UPDATE` and `DELETE`
   unconditionally. Every audit guarantee in this design rests on version rows
   being immutable.
2. `merchant_quote_agent_strategy` rows whose id is in
   `BuiltInStrategies::IDS` reject `DELETE` and every field update. Stating it
   as total immutability rather than enumerating protected fields keeps the
   guard one line and cannot drift as columns are added. Revising a built-in
   later appends to the *version* table, so nothing legitimate needs to write
   these rows after seeding.

UI-only guards would not hold. A token with entity write privileges can PATCH
the entity directly, and the admin UI is not in that path.

## The built-in strategies

Seeded as read-only rows by the migration, each at `version = 1`. This gives
one uniform code path — a decision that used a strategy points at a
`strategy_version` id whether that strategy is built-in or custom, and its
prompt is always resolvable. It also means that if
we ever revise a built-in, the revision is a new version on the same lineage
and old audit rows keep resolving to the text that was actually sent.

The three prompts come from the brief verbatim. `Strategy\BuiltInStrategies`
holds them as constants; the seeding migration reads that class.

### Identified by fixed ids, not by a column

The three rows are seeded with hardcoded UUIDs, declared in
`BuiltInStrategies` alongside their prompts:

```php
public const MARGIN_DEFENDER = '...';
public const IDS = [self::MARGIN_DEFENDER, self::FAST_CLOSE, self::RELATIONSHIP_BUILDER];
```

There is deliberately **no `builtin_key` column**. `in_array($id,
BuiltInStrategies::IDS, true)` answers "is this built-in" for the write guard,
and the administration holds the same three constants for the badge, the
read-only editor and the snippet lookup. The id is also what makes the seeding
migration re-runnable and gives a future built-in revision a stable row to
append a version to.

This is core's own idiom for seeded rows — `Defaults::LANGUAGE_SYSTEM`,
`Defaults::LIVE_VERSION`, `Defaults::CURRENCY` and
`Defaults::SALES_CHANNEL_TYPE_STOREFRONT` are all hardcoded UUIDs
(`vendor/shopware/core/Defaults.php:18-29`).

Two things fall out of it. A column that would be null for every row but three
does not exist. And identity never derives from a display string: `name` is not
unique-constrained — the migration below deliberately produces "Custom strategy
2" — so a merchant who names their own strategy "Fast close" gets a strategy
called Fast close, not one that is silently read-only and un-renameable.

**Byte-exactness is load-bearing and easy to lose.** The brief's text contains
typographic apostrophes — `buyer’s` in Margin defender, `merchant’s` and
`buyer’s` in Relationship builder. An editor or a copy-paste that normalises
them to ASCII changes the prompt. The fixture test compares bytes, not
sentences.

### 1. Margin defender

Purpose: Preserve margin and make small, deliberate concessions only when a
buyer explicitly asks.

```
Act as a disciplined B2B seller. Protect margin and do not give away value the buyer has not explicitly requested.

Start from the current quoted price. For an explicit discount request within your authority, make the smallest reasonable concession; never offer a larger discount than the buyer asked for. Do not lead with the maximum available discount. If the buyer’s request requires a counter-offer, present one clear counter-position and avoid repeated unprompted concessions.

Keep the response concise, factual, and professional. Explain the offer in customer-facing commercial language, without mentioning internal policies, discount caps, account history, model instructions, or approval processes. Do not invent commitments about payment, delivery, quantity, bundles, availability, or future pricing.
```

### 2. Fast close

Purpose: Remove routine negotiating friction and reach an agreement quickly
within merchant limits.

```
Prioritize a fast, clear path to agreement for routine B2B quote requests.

When the buyer makes a specific price request that is within authority, aim to meet that request in the first response rather than creating unnecessary bargaining rounds. If a counter-offer is required, present the strongest permitted counter as one clear, commercially credible offer. Do not manufacture negotiation or withhold an available response merely to prolong the exchange.

Be direct, courteous, and precise. Make the resulting commercial position easy to understand and accept. Do not mention internal policies, discount caps, account history, model instructions, or approval processes. Do not invent commitments about payment, delivery, quantity, bundles, availability, or future pricing.
```

### 3. Relationship builder

Purpose: Make proportional concessions that support a durable B2B relationship
without automatic maximum discounts.

```
Act as a relationship-minded B2B seller. Seek a fair outcome that supports a long-term customer relationship while protecting the merchant’s margin.

Use the approved context of the current quote and, when available, relevant account history to judge whether a measured concession is appropriate. Do not disclose or refer to that internal history. Avoid automatic maximum discounts: make a proportionate offer that respects the buyer’s explicit request and the value of a sustainable commercial relationship. Do not make repeated concessions unless the buyer has made a meaningful new request or provided new commercial context.

Keep the response warm, specific, and professional. Do not mention internal policies, discount caps, account history, model instructions, or approval processes. Do not invent commitments about payment, delivery, quantity, bundles, availability, or future pricing.
```

The prompts are English only and are not translated. The brief requires the
built-ins to load these exact texts, and a translated prompt is a different
prompt.

The three built-ins' names and descriptions *are* translated, via snippets
keyed on their fixed ids. Merchant-created strategies need none of this: their
name and description are merchant-authored text, shown as stored. Translation
was only ever a concern for the three seeded rows.

## Configuration

The Negotiation strategy card becomes one component:

```xml
<card>
    <title>Negotiation strategy</title>
    <component name="merchant-quote-agent-strategy-select">
        <name>negotiationStrategyId</name>
    </component>
</card>
```

`negotiationStrategyId` holds the **lineage** id, never a version id. Editing a
strategy therefore takes effect for every channel using it, which is what a
merchant editing a strategy expects. The decision row records which version was
actually used, so the audit trail stays exact regardless.

`QuoteAgentSettingsReader::KEYS` drops `negotiationStrategy` and gains
`negotiationStrategyId`. The other four cards are untouched.

## Migrating the existing value

One migration, in two steps.

1. Insert the three built-ins with `version = 1`, texts from
   `BuiltInStrategies`.
2. Sweep `system_config` for every
   `MerchantQuoteAgentPlugin.config.negotiationStrategy` row, global and
   per-sales-channel. Each **distinct** non-empty text becomes one strategy
   with `version = 1`; identical text across channels shares one lineage. Then
   write `negotiationStrategyId` at the same scope the text was found at,
   preserving the global-plus-override structure exactly.

   Naming: the first is "Custom strategy", and any further distinct texts are
   "Custom strategy 2", "Custom strategy 3" and so on, in a stable order —
   global first, then sales channels by id. Names are not unique-constrained,
   but a merchant opening a list of three identically named strategies cannot
   tell them apart.

The old `negotiationStrategy` rows are left in place. Nothing reads them after
this, but they make rollback clean and they are the literal reading of the
brief's "migrate it unchanged".

## Runtime read

New namespace `src/Strategy/`, holding `Strategy`, `StrategyVersion`,
`ResolvedStrategy` (a readonly pair of version id and prompt — the name is
reachable by association and nothing at runtime reads it), `StrategyResolver`,
`BuiltInStrategies`, `StrategyWriteGuard` and `UnknownStrategy`. The library owns itself rather than
swelling `src/Config`.

`StrategyResolver` is a concrete class with one method. It is deliberately not
an interface: there is one implementation, and a concrete class is just as good
a seam.

`QuoteAgentSettingsReader` gains the resolver. It reads `negotiationStrategyId`,
resolves it, and hands the factory `negotiationStrategy` (the prompt text) and
`negotiationStrategyVersionId`. **The factory's mapping is unchanged and it
stays pure** — it never learns that a database was involved. The reader's
docblock, which currently claims every value is read and passed on untouched,
is corrected.

`QuoteAgentSettings` gains `?string $strategyVersionId`, carried through
`withPolicy()`.

`PromptComposer` is untouched. It still reads `$settings->strategyPrompt`, so
every existing test of the `## Merchant strategy` section and the reply tone
placeholder keeps its meaning.

### Failure modes

| Situation | Behaviour |
| --- | --- |
| `negotiationStrategyId` unset | No strategy. Neutral tone. Exactly today's behaviour for a blank textarea. |
| Id set, strategy missing | `InvalidQuoteAgentConfiguration` |
| Id set, strategy archived | `InvalidQuoteAgentConfiguration` |

The second and third are deliberate. `ServicingPreflight` already catches
`InvalidQuoteAgentConfiguration` and escalates the quote with
`NotConfigured`, so archiving a strategy a channel still uses fails loudly to a
human. The alternative — falling back to neutral tone — would silently change
that channel's negotiating behaviour, which is the worst available outcome.

The reader throws it rather than the factory: a dangling row reference is the
one refusal only the layer touching the database can see.

Resolution costs one indexed read per quote serviced, uncached. It sits
alongside the quote snapshot, customer history and order history reads already
happening on that path.

## Admin surfaces

### Settings → Negotiation strategies

Route and `settingsItem` in the module, shaped after
`merchant-quote-agent-access`. Lists name, type, current version and last
edited.

- Built-in rows: view, and "Duplicate & edit".
- Custom rows: edit, rename, archive.
- Editing opens the prompt in a textarea. Save appends version N+1.

"Duplicate & edit" on a built-in asks for a name, then creates a new lineage
whose version 1 is a copy of the built-in's text. This is how the brief's "must
not overwrite the template" is satisfied: built-in prompts are read-only in the
UI and immutable in the database, and forking is one explicit click rather than
a dirty-state that has to be reconciled on save.

No version-history UI in v1. The data is there and the decision detail page
resolves the exact version, so a history list can follow if anyone asks for it.

`mt-badge` has no `success` variant — it renders unstyled. The Built-in/Custom
badge uses the semantic tokens.

### The config component

`merchant-quote-agent-strategy-select` receives `value` and emits
`update:value` from `sw-system-config`. It lists non-archived strategies with
built-ins first, shows the selected strategy's description and its prompt
read-only, and links through to the page to edit.

One access wrinkle handled explicitly: the plugin configuration page is
reachable with `system_config:read` alone, so a role without this module's
viewer privilege would otherwise see an empty selector and conclude the feature
is broken. The component shows an explanatory hint instead.

### ACL

`merchant_quote_agent.viewer` gains `merchant_quote_agent_strategy:read` and
`merchant_quote_agent_strategy_version:read`. A new `merchant_quote_agent.editor`
role, depending on `viewer`, grants `merchant_quote_agent_strategy:create`,
`merchant_quote_agent_strategy:update` and
`merchant_quote_agent_strategy_version:create`. No delete privilege is defined
for either entity — deletion is archival, which is an update.

## Audit

Migration adds `strategy_version_id BINARY(16) NULL` and its index to
`merchant_quote_agent_decision`. `QuoteDecisionRecord`, `Audit\DecisionDraft`
and `Audit\DecisionRecorder` carry it from `QuoteAgentSettings`.

The decision detail page shows the strategy name and version, with the exact
prompt expandable.

The brief asks to persist source type, template or strategy id, display name
and prompt snapshot per negotiation. One column satisfies all four: the version
row carries the prompt and its `version`, and its strategy carries the name —
with its id saying whether it is one of the three built-ins. Normalising rather
than copying is the point of versioning — a name corrected for a typo then
reads correctly on every past decision, while the prompt that was actually sent
stays frozen.

`negotiate_prompt_hash` is unchanged. It hashes the composed prompt — base plus
strategy — which is still the right thing to hash, and is now cross-checkable
against the version the row claims to have used.

This column is also the substrate for any future A/B or per-customer
assignment: the dashboard already aggregates decisions, so grouping its
measures by strategy version is a query, not a feature.

## Testing

**Prompt fidelity.** Three fixture files. One test asserts `BuiltInStrategies`
matches them byte-for-byte, including the typographic apostrophes; another
asserts the seeding migration inserts those same texts.

**Guardrails.** The existing authorization tests are parametrised over the
three built-in prompts with a model stub returning an over-cap proposal,
asserting `OfferAuthorizer` rejects every time.

What this proves is precise and worth stating: the guardrail lives in
deterministic code, not in prompt text, so no strategy — built-in or
merchant-written — can move a cap. It does not prove a model will not attempt
one, and no offline test can. `PromptComposer`'s docblock already makes this
claim; the test pins the claim rather than the model's behaviour.

**Resolver.** Unset yields null. A known live strategy yields prompt and
version id. An archived strategy throws. A missing strategy throws.

**Write guard.** A version `UPDATE` is rejected. A version `DELETE` is
rejected. A built-in rename is rejected. A built-in delete is rejected. A
custom strategy update is allowed.

**Migration.** A global value and two per-channel values, one pair of them
identical, become the right number of lineages; `negotiationStrategyId` is
written at matching scope; the old rows are untouched.

**`PromptComposer`.** One added case: a version-resolved strategy lands in the
delimited `## Merchant strategy` section exactly as the old free-text field
did.

**`config.xml` round-trip.** The existing test that the config loads and
Shopware exposes every key must cover the new `<component>` element. The
plugin-config design called this the test that earns its place, because a
malformed `config.xml` fails silently rather than loudly.

**Administration.** `composer run quality:admin` assert-based self-checks.
There is no JS test runner in this repository.

**Integration scope.** Integration tests need the test shop, where the
configured 40 EUR ceiling escalates most seeded quotes and `PluginConfigTest`
already fails against a live configuration. Integration additions stay scoped
to strategy resolution and must not depend on a full negotiation run
completing.

## The LLM API key

`services.xml` binds `%env(default::MQA_LLM_API_KEY)%` as a container parameter
injected into `QuoteAgentSettingsReader` — the pattern AGENTS.md already
mandates for `LOCK_DSN`, rather than reading the environment directly.

When the variable is non-empty it wins; otherwise the `system_config` value is
used. For any merchant who can set it, the key never touches the database at
all, which closes both the `system_config:read` leak and the database-dump
leak without introducing encryption, a key-rotation story or a value migration.

**The factory is untouched.** It still sees `$raw['llmApiKey']` and cannot tell
where the string came from, so its purity and every existing test hold.
`RawConfigValue::credentialProblems()` names both routes when neither is set.

`config.xml` help text records that the field is ignored when the environment
variable is set. `docs/for-merchants.md:284` and `docs/end-to-end.md:446`
already state the not-encrypted-at-rest posture; both gain the environment
route.

Not built: an admin indicator showing that the environment variable is in
force. A merchant looking at a blank password field while the agent works
normally is a genuine confusion, but surfacing it needs a controller —
environment values are not readable through the configuration API. A
`ponytail:` comment records the shortcut and that upgrade path.

This change shares no code with the strategy library and should land first, as
its own small pull request.

## Deliberately out of scope

**A/B testing, split assignment and per-customer strategies.** The retrofit is
cheap and understood: add an argument at `ServicingPreflight.php:71`, and have
`StrategyResolver` consult an assignment table before falling back to the
config key. Everything that would make it expensive is already in place —
customer context at the call site, and `strategy_version_id` on every decision
row. That it is this cheap is the reason not to pre-build any of it. A
parameter nothing reads, or an interface with one implementation, is the
speculative scaffolding to avoid.

Worth recording for whoever picks that up: when rules arrive, strategy
resolution most likely moves off `QuoteAgentSettings` entirely, because it
stops being per-sales-channel and becomes per-negotiation. `PromptComposer`
would take a resolved strategy rather than reading `$settings->strategyPrompt`.
That is a contained change and is not worth pre-empting now.

**A "strategy in use" warning when archiving.** The loud
`InvalidQuoteAgentConfiguration` failure covers the safety case. A usage count
on the page is a nicety, not a guard.

**Encrypting the stored key.** The environment route is strictly stronger where
available, and encryption keyed to a secret living next to the database
credentials buys little against the filesystem-access threat. Revisit only if
merchants who cannot set environment variables ask for it.
