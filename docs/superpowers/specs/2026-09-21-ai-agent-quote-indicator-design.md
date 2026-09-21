# AI Agent Quote Indicator

Date: 2026-09-21

## Status

Proposed.

## Context

The agent writes into the buyer↔merchant conversation on a quote. It does so
through `QuoteGatewayInterface::addComment()` from three call sites —
`Negotiation\ReplyComposer`, `Negotiation\ClarificationRound` and
`Servicing\QuoteEscalator` — and the buyer reads those comments in their
storefront account, on the quote detail page.

Today the buyer is told a human wrote them.

That is not a figure of speech. On SwagCommercial 7.13 the storefront
conversation is rendered client-side by `B2bQuoteHistoryItemPlugin`
(`quote-history-item.plugin.ts`) from the store-api quote history payload. Its
actor resolution reads `entry.employee`, then `entry.customer`, then
`entry.createdBy`, and when all three are absent it falls back to the snippet
`merchantComment`, whose default value is the string `Merchant`. An agent
comment has none of the three, so it renders under the merchant's name, with
the merchant's avatar styling, in the merchant's half of the thread.

We want the buyer to know an AI agent handled the quote.

### What already identifies an agent comment

Nothing new needs to be persisted to answer the question "did the agent write
this".

Three parties write a `quote_comment`, and SwagCommercial gives each a
different column:

- the buyer, from the storefront: `customer_id`, plus `employee_id` for a B2B
  employee;
- the merchant, from the administration: `created_by_id` alone;
- the agent, from a message handler under a `SystemSource`: none of them.

`Bridge\Data\QuoteComment::isAuthored()` already encodes exactly this, it is
already load-bearing for the servicing fingerprint, and `QuoteCommentTest`
already pins it. The discriminator is measured behaviour, not an assumption.

It also survives into the store-api payload the storefront reads:
`QuoteCommentDefinition` flags `customerId`, `employeeId` and the `CreatedByField`
as `ApiAware`, so the absence of all three is visible to the frontend.

### Why "just extend the comment template" does not work

The obvious approach — extend the Twig that renders a comment and add a badge —
does not reach the modern lane at all. On 7.13 the comment article is an empty
`<template>` element (`history-item-template.html.twig`) filled in by JavaScript.
There is no server-rendered comment to extend.

### Scope decisions already taken

- **Both surfaces**: a per-message label *and* a quote-level banner.
- **Lane split**: the per-message label ships on the modern lane (7.13+) only;
  the banner is plain Twig and ships on both the modern and the released
  (6.7.1.2–6.7.12.x) lane. A released-lane buyer is still told an agent is
  involved, just not per message.
- **Always on.** This is treated as an AI-transparency obligation, not a
  merchant feature. No system config key, no per-sales-channel preference, no
  opt-out. This deliberately does *not* follow the `BuyerNotificationPreference`
  precedent that governs the escalation notice.

### A deliberate reversal

`Servicing\QuoteEscalator`'s class docblock states that buyer-facing copy must
carry "no mention of an agent". That rule was written to stop *internal detail*
(reason values, field names, constraint messages) leaking into customer-facing
text, and that part of it stands unchanged.

This spec narrows it: the *fact* that an agent is involved is now disclosed, by
design, on every quote the agent touches. The reasons, the policy, the
violations and the model's internals remain internal. The docblock must be
amended in the same change rather than left contradicting the shipped
behaviour.

## Decision

Derive the per-message signal; stamp the quote for the banner. Persist no new
per-comment state.

- **Per-message label** — derived at render time from the absence of all three
  author columns. No schema, no migration, no new route, and the frontend and
  the backend agree by construction because they read the same predicate.
- **Quote-level banner** — driven by a `customFields` flag written by the
  servicing pass, read directly off the quote entity in Twig.

The rejected alternative was extending `quote_comment` with a `customFields`
column via an `EntityExtension` plus a migration plus a second write (the
`QuoteCommenter` signature accepts no custom fields). It gives unambiguous
per-comment provenance and is immune to the collision risk noted under Risks,
but it is far more code and another hand-written table change in a plugin that
already carries that burden deliberately. It remains the upgrade path.

## Design

### 1. The agent marker on the quote

`Servicing\QuoteEscalator` already owns a `customFields` marker
(`merchant_quote_agent_escalated`) and the passes already write `customFields`;
`QuoteWriter` shallow-merges, so an added key cannot disturb the A2CN act
chain.

Add a second, separate key — `merchant_quote_agent_handled` — owned by a new
`Servicing\AgentDisclosure` holding the constant and the fragment helper. It
does not belong to `QuoteEscalator`: the escalator is one of three comment
writers, and the marker belongs to all of them.

It is set once and never cleared. The disclosure is about what happened on this
quote, so a later human reply does not make it untrue. This is the opposite
lifecycle to the escalation marker, which exists to be cleared, and that
difference is why it is a second key rather than a reuse of the first.

**Where it is written.** `ServiceQuoteHandler` already has a single end-of-pass
stamp that spreads owner-supplied fragments into one `updateQuote`
(`src/Servicing/ServiceQuoteHandler.php:197`, alongside
`QuoteEscalator::releaseFor()` and `ClarificationMarker::releaseFor()`).
`AgentDisclosure::stampFor($outcome)` slots in there as a third spread. No new
write, no new call site, and it is reached on every completed pass including one
that escalated.

**It is gated on the outcome — the agent must have acted on the quote.**
Stamped for `Offered`, `Countered`, `Clarified` and `Escalated`; not for
`HandedOver` or `NothingToDo`.

An earlier draft of this spec stamped unconditionally, on the reasoning that a
completed pass means the agent read the ask and decided. Reading
`NegotiationOutcome` kills that: `HandedOver` is defined as a pass that *found a
human merchant already on the quote and wrote nothing*. Stamping it would tell
the buyer an AI agent handled a quote that a human handled — a false statement
to the buyer, which is a worse failure than the under-disclosure the wider rule
was guarding against. `NothingToDo` is the same shape with nothing happening at
all.

The gate is deliberately wider than `answeredTheBuyer()`, which covers only
`Offered` and `Countered`. `Clarified` put an agent-written question in front of
the buyer. `Escalated` is included even on a sales channel where the buyer
notice is switched off and the buyer therefore sees no agent message: the agent
still made a determination about their quote, and that determination is the
thing being disclosed.

So the rule is "the agent acted on this quote", not "a pass completed" and not
"the buyer got a reply". The banner copy must match that and not promise a
reply the buyer may not find in the thread.

One gap follows from the placement: if the pipeline writes a comment and *then*
throws, the pass exits at
`src/Servicing/ServiceQuoteHandler.php:175` without stamping. The retry restamps
it, so the window is one delivery wide and self-healing. Not worth a second
write to close.

### 2. The banner (both lanes)

A Twig template in this plugin mirroring SwagCommercial's path, using
`sw_extends` on the quote detail page and appending to the block that wraps the
quote header. It renders a static notice when
`quote.customFields.merchant_quote_agent_handled` is truthy, and nothing
otherwise.

Copy lives in `messages.en-GB.json` under `merchantQuoteAgent.disclosure.*`,
alongside the existing `merchantQuoteAgent.consent.*` keys.

**Template precedence must be forced.** `BundleHierarchyBuilder` sorts bundles
by `getTemplatePriority()` (lower integer = higher precedence). Both
SwagCommercial and this plugin return the default `0`, and PHP's `asort` is
stable, so the tie falls through to bundle registration order — which
`DbalKernelPluginLoader` derives from `ORDER BY installed_at`. Which plugin
wins therefore depends on the order a given shop installed them in. Override
`MerchantQuoteAgentPlugin::getTemplatePriority()` to return `-1`.

This is the one part of the change that would pass on the test shop and fail
silently on a merchant's, so it gets its own integration assertion rather than
a manual check.

### 3. The per-message label (modern lane only)

This plugin has no storefront JavaScript today. It gains `app/storefront/` with
a `main.ts` and a webpack entry, whose only job is to override
`B2bQuoteHistoryItemPlugin` via the storefront `PluginManager`.

Two methods are overridden. Both are required; either alone is insufficient.

**`getActor(entry)`** — when the entry carries no `customer`, no `employee`, no
`customerId`, no `employeeId` and no `createdById`, return an agent actor
(disclosure name from a snippet, its own initials, `isCustomer: false`) instead
of falling through to `merchantComment`. Otherwise delegate to the parent.

**`isMerchantCommentOnlyEntry(entry)`** — return `false` for an entry with no
author of any kind, so an agent comment is never chosen as a merge source.

The second override is the non-obvious one, and it is load-bearing.
`isMerchantCommentOnlyEntry()` is `isCommentOnlyEntry() && !isCustomerOrEmployeeHistory()`,
and an agent comment satisfies both, because "no author at all" is not "customer
or employee". `mergeMerchantCommentHistories()` will then merge it into any
merchant entry whose `sentAt` is within `HISTORY_MERGE_WINDOW_MS` (15 seconds),
and `mergeCommentIntoHistoryEntry()` produces `{...target, comment: <agent text>,
createdById: target.createdById ?? ...}`. The merged entry carries the merchant's
`createdById` and the agent's words, and the null-author signal is destroyed
*before* `getActor()` ever sees it.

Concretely: a merchant editing the quote in the administration within 15 seconds
of an agent reply would see the agent's text attributed to a named human, with
the disclosure gone. That is the precise failure this feature exists to prevent,
so it cannot ship as a known gap.

Suppressing the merge costs a slightly more verbose timeline — an agent pass that
both changes the quote and comments now renders two articles instead of one.
That is an acceptable trade for a signal that cannot be silently lost.

The other merge path, `mergeAddedStatusCommentHistories()`, is gated on
`isRequestCommentOnlyEntry()` (`action === 'request'`), which an agent comment
never is. It needs no override.

### 4. Released lane

The per-message label is out of scope on the released lane. The banner covers
it. `Bridge\Commercial\CommercialCapabilities` is not extended for this: the
split here is "does the storefront render comments in JS", which is a
presentation question and not one of the four schema capabilities that class
describes. Keeping it out avoids implying a schema difference that does not
exist.

## What this deliberately does not do

- **No translation beyond en-GB.** The existing buyer-facing copy is already a
  hardcoded English constant (`QuoteEscalator::BUYER_MESSAGE`), so this matches
  the current bar rather than raising it. Recorded as a known gap, not solved
  here.
- **No merchant-side indicator.** The administration already distinguishes the
  writers, and the merchant knows the agent is running.
- **No retrospective marking.** Quotes the agent handled before this ships have
  no marker and get no banner. Their comments still get the per-message label on
  the modern lane, because that is derived rather than stored.
- **No change to the reply text itself.** Disclosure is presentational; the
  audit record's `replyToBuyer` stays exactly what the agent composed.

## Testing

- `QuoteCommentTest` already pins the null-author discriminator; extend it only
  if the predicate moves.
- Unit: the marker is written for `Offered`, `Countered`, `Clarified` and
  `Escalated`, and withheld for `HandedOver` and `NothingToDo` — covered per
  enum case, so a case added later fails the test rather than silently
  inheriting a default. It is not cleared by a later pass, including one that
  clears the escalation or clarification markers.
- Integration: `getTemplatePriority()` resolves this plugin ahead of
  SwagCommercial in the namespace hierarchy — asserted against the container,
  not by eyeballing a rendered page. Plus an assertion that the marker key
  constant and the literal in the Twig template still agree, since Twig cannot
  import the constant.
- Manual, against the test shop: a quote with the marker renders the banner and
  one without does not. Deliberately not automated — a full storefront page
  render needs a logged-in buyer and a quote fixture, and the two automated
  assertions above already cover the parts that fail silently (precedence and
  the key literal). What is left for the eye is whether the alert renders,
  which does not fail silently.
- Administration-style assert checks (`composer run quality:admin`) for the two
  JS overrides, matching how the existing admin module is checked — there is no
  JS test runner in this project. Both cases must be covered: an agent comment
  standing alone, and an agent comment 5 seconds after a merchant detail change
  (the merge case), asserting the agent actor survives.

## Risks

- **Collision on the derived signal.** Any *other* plugin writing a
  `quote_comment` under a `SystemSource` would be labelled as our AI. Narrow in
  a shop running this plugin, but not zero. The mitigation is the rejected
  `EntityExtension` approach, kept as a documented upgrade path; the predicate
  lives in one place on each side, so swapping its source later is contained.
- **Overriding an `@internal` upstream JS plugin.** `B2bQuoteHistoryItemPlugin`
  carries no stability guarantee, and both overridden methods are private to it
  by convention. A SwagCommercial update can rename or restructure either. This
  is the same class of coupling the bridge already accepts and documents for
  `QuoteCommenter`; it must be recorded the same way, with an
  `@internal-dependency` note naming both methods.
- **`HISTORY_MERGE_WINDOW_MS` is upstream's constant.** If it grows, the merge
  suppression still holds, because we suppress by predicate rather than by
  timing.

## Follow-up, not in this change

The current `Merchant` attribution of agent comments is a defect in its own
right, independent of this feature — it misattributes machine-written text to a
named party today, on every shop running the plugin. It should get its own
issue so it is not buried in this one, and so it can be reasoned about for the
released lane, where this spec leaves it unfixed.
