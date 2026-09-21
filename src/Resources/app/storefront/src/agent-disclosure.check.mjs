/**
 * Self-check for agent-disclosure.ts. No test runner: the project has no JS
 * toolchain, and this matches how the administration module is checked.
 *
 *     node --experimental-strip-types src/Resources/app/storefront/src/agent-disclosure.check.mjs
 *
 * The fixtures are the four author shapes a quote_comment can carry, as
 * measured against SwagCommercial's own source: the buyer (customerId, plus
 * employeeId for a B2B employee), the merchant (createdById alone), and the
 * agent (none of them).
 */

import assert from 'node:assert/strict';
import { AGENT_ACTOR_NAME, isAgentEntry } from './agent-disclosure.ts';

const buyer = { comment: 'Can you do better?', customerId: 'c1' };
const employee = { comment: 'Approving this', employeeId: 'e1' };
const merchant = { comment: 'Here is our best offer', createdById: 'u1' };
const agent = { comment: 'We can offer 12% off', sentAt: '2026-09-21T10:00:00Z' };

assert.equal(isAgentEntry(agent), true, 'an entry with no author of any kind is the agent');
assert.equal(isAgentEntry(buyer), false, 'a customerId is the buyer');
assert.equal(isAgentEntry(employee), false, 'an employeeId is a B2B employee, still the buyer side');
assert.equal(isAgentEntry(merchant), false, 'a createdById is the merchant');

// The association objects, not just the foreign keys: the history payload
// carries `customer` and `employee` expanded, and an entry can arrive with the
// association populated. Treating one of those as the agent would label a
// buyer's own message as AI-written.
assert.equal(
    isAgentEntry({ comment: 'hi', customer: { firstName: 'Ada' } }),
    false,
    'an expanded customer association is the buyer',
);
assert.equal(
    isAgentEntry({ comment: 'hi', employee: { firstName: 'Ada' } }),
    false,
    'an expanded employee association is the buyer side',
);

// An empty association object is what SwagCommercial's own asRecord() yields
// for an absent association, so it must not read as a present one.
assert.equal(
    isAgentEntry({ comment: 'hi', customer: {}, employee: {} }),
    true,
    'empty association objects mean absent, not present',
);

// The merge case. This is the regression that motivated suppressing the merge
// at all: a merchant detail change 5 seconds after an agent reply is inside
// SwagCommercial's 15s window, and merging would carry the merchant's
// createdById onto the agent's text.
const mergedByUpstream = { comment: 'We can offer 12% off', createdById: 'u1', sentAt: '2026-09-21T10:00:05Z' };
assert.equal(
    isAgentEntry(mergedByUpstream),
    false,
    'once merged the signal is gone - which is why the merge is suppressed upstream of this',
);

assert.equal(typeof AGENT_ACTOR_NAME, 'string');
assert.ok(AGENT_ACTOR_NAME.length > 0, 'the actor needs a name to render');

console.log('agent-disclosure.check.mjs: all assertions passed');
