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
import {
    AGENT_ACTOR_NAME,
    DELEGATE_TO_SUPER,
    isAgentEntry,
    resolveActor,
    suppressMerchantCommentMerge,
} from './agent-disclosure.ts';

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

// An author column can also arrive present-but-empty: null (no value bound)
// or '' (bound, empty). Both already behave correctly through isPresent()'s
// generic Boolean() fallback; these pin the shapes rather than change them.
assert.equal(
    isAgentEntry({ comment: 'hi', customerId: null, createdById: null }),
    true,
    'a null author column is absent, not present',
);
assert.equal(
    isAgentEntry({ comment: 'hi', createdById: '' }),
    true,
    'an empty-string author column is absent, not present',
);

assert.equal(typeof AGENT_ACTOR_NAME, 'string');
assert.ok(AGENT_ACTOR_NAME.length > 0, 'the actor needs a name to render');

// resolveActor() and suppressMerchantCommentMerge() are the pure bodies of
// main.ts's two PluginManager overrides (moved here so they can be exercised
// without a browser or window.PluginManager). The spec requires both cases:
// an agent comment standing alone, and one 5 seconds after a merchant detail
// change - the merge case - asserting the agent actor survives either way.
const getInitials = (name) => name.split(' ').map((part) => part[0]).join('');

// Case 1: an agent comment standing alone resolves to the agent actor.
const standaloneAgentComment = { comment: 'We can offer 12% off', sentAt: '2026-09-21T10:00:00Z' };
const standaloneActor = resolveActor(standaloneAgentComment, getInitials);
assert.notEqual(standaloneActor, DELEGATE_TO_SUPER, 'a standalone agent comment must not delegate to super');
assert.deepEqual(
    standaloneActor,
    { name: AGENT_ACTOR_NAME, initials: getInitials(AGENT_ACTOR_NAME), isCustomer: false },
    'a standalone agent comment resolves to the agent actor',
);

// A non-agent entry delegates instead of being resolved here.
assert.equal(
    resolveActor(merchant, getInitials),
    DELEGATE_TO_SUPER,
    'a merchant entry delegates to the parent getActor()',
);

// Case 2: an agent comment 5 seconds after a merchant detail change. Upstream
// would normally merge it (superResult: true, i.e. isCommentOnlyEntry() &&
// !isCustomerOrEmployeeHistory() both held) - suppressMerchantCommentMerge()
// must refuse that merge so the entry passed to resolveActor() afterwards is
// still the untouched agent comment, and the agent actor survives.
const agentCommentAfterMerchantChange = {
    comment: 'We can offer 12% off',
    sentAt: '2026-09-21T10:00:05Z',
};
assert.equal(
    suppressMerchantCommentMerge(agentCommentAfterMerchantChange, true),
    false,
    'an agent comment inside the merge window must not be merged into the merchant entry',
);
assert.deepEqual(
    resolveActor(agentCommentAfterMerchantChange, getInitials),
    { name: AGENT_ACTOR_NAME, initials: getInitials(AGENT_ACTOR_NAME), isCustomer: false },
    'unmerged, the agent comment still resolves to the agent actor',
);

// A merchant entry within the same window is unaffected: the merge decision
// passes through to upstream's own result.
assert.equal(
    suppressMerchantCommentMerge(merchant, true),
    true,
    'a merchant entry is still eligible for merging, per upstream',
);
assert.equal(
    suppressMerchantCommentMerge(merchant, false),
    false,
    'suppressMerchantCommentMerge never turns a merchant entry INTO a merge candidate',
);

console.log('agent-disclosure.check.mjs: all assertions passed');
