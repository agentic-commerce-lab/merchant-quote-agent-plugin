# Refine Quote History Timeline UI

Date: 2026-09-10

## Status

Approved.

## Context

On the quote agent dashboard detail page (`merchant-quote-agent-detail`), the quote history timeline displays a merged stream of customer messages, agent servicing passes, and terminal outcomes.

Currently, each servicing pass header displays the backend event trigger reason (`round.triggerReason`) as its title:
- `The customer wrote a comment` (`comment_written`)
- `Quote changed state` (`state_entered`)

This presentation has two major drawbacks for merchants:
1. **Redundant & confusing actor attribution:** Right above an agent pass triggered by a customer comment, the timeline already displays the customer message bubble. Showing "The customer wrote a comment" as the title of the agent's servicing pass makes it sound like the pass belongs to the customer rather than the agent.
2. **Internal pipeline jargon:** "Quote changed state" is a backend event trigger name that carries little meaning for merchants evaluating negotiation progress.

Additionally, the collapsed "Technical details" spoiler currently includes "The model's own answer" (`rawProposal`), which dumps large JSON structures into a wide grid cell. Merchants and operators do not need this JSON blob rendered in the admin.

## Decisions

1. **Timeline Pass Entry Header Redesign**:
   - In `merchant-quote-agent-detail.html.twig`, update the pass header from:
     ```html
     <span class="mqa-run__index">{{ entry.run.index }}</span>
     <h4 class="mqa-entry__title">{{ entry.run.title }}</h4>
     <mt-badge :variant="entry.run.outcomeVariant">{{ entry.run.outcomeLabel }}</mt-badge>
     <span class="mqa-entry__time">{{ entry.run.timestamp }}</span>
     ```
     to:
     ```html
     <span class="mqa-run__index">#{{ entry.run.index }}</span>
     <h4 class="mqa-entry__title">{{ $tc("merchant-quote-agent.detail.agentTitle") }}</h4>
     <mt-badge :variant="entry.run.outcomeVariant">{{ entry.run.outcomeLabel }}</mt-badge>
     <span class="mqa-entry__time">{{ entry.run.timestamp }}</span>
     ```
   - In `index.ts`, update `formatRun(round, index)`:
     - Set `title: this.$tc("merchant-quote-agent.detail.agentTitle")` so any consumers reading `entry.run.title` receive the clear "Quote Agent" label.

2. **Index Tag Styling (`merchant-quote-agent.scss`)**:
   - Style `.mqa-run__index` to render as a neat, subtle tag:
     ```scss
     .mqa-run__index {
         color: var(--color-text-secondary-default);
         font-size: 12px;
         font-weight: 600;
         font-variant-numeric: tabular-nums;
         background: var(--color-elevation-surface-sunken);
         padding: 1px 6px;
         border-radius: 4px;
     }
     ```
   - Uses Meteor design tokens for theme compatibility (light and dark mode).

3. **Technical Details Spoiler Cleanup**:
   - In `index.ts` method `technical(round)`, remove the `rawProposal` block:
     ```ts
     if (round.rawProposal) {
         rows.push({ key: "rawProposal", value: round.rawProposal, mono: true, wide: true });
     }
     ```
   - Retain `{ key: "trigger", value: triggerLabel(this, round.triggerReason) }` in the collapsed technical grid so developers and support can still view the triggering event during triage without cluttering the main timeline.

4. **Snippet Additions (`en.json` and `de.json`)**:
   - Add `merchant-quote-agent.detail.agentTitle`:
     - English (`en.json`): `"Quote Agent"`
     - German (`de.json`): `"Quote Agent"`

## Verification Plan

1. **Administration checks:**
   - Run `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs` to verify decision and history helper invariants pass.
   - Run `node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs`.
2. **Visual inspection:**
   - Verify timeline header rendering in browser with visual companion.
   - Confirm `#1 Quote Agent [Offer sent] 10:01` renders with proper spacing, tokens, and badges.
   - Confirm `rawProposal` no longer appears in the technical details section.
3. **Quality & style checks:**
   - Verify Mago / composer checks where applicable.
