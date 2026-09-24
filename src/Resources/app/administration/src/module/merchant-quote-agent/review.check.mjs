import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    FEEDBACK_COMMENT_MAX,
    FEEDBACK_REASONS,
    editsPayload,
    errorCode,
    exceedsCap,
    feedbackPayload,
    reviewStatusVariant,
    wasEdited,
} from './review.ts';

const php = readFileSync(new URL('../../../../../../Review/FeedbackReason.php', import.meta.url), 'utf8');
const phpValues = [...php.matchAll(/case \w+ = '([a-z_]+)';/g)].map((match) => match[1]);
assert.deepEqual([...FEEDBACK_REASONS].sort(), phpValues.sort());

assert.equal(feedbackPayload([], '   '), null);
assert.deepEqual(feedbackPayload(['wrong_price', 'vibes'], ' ok '), { reasons: ['wrong_price'], comment: 'ok' });
assert.deepEqual(feedbackPayload([], 'Too pushy'), { reasons: [], comment: 'Too pushy' });
assert.equal(feedbackPayload([], 'x'.repeat(FEEDBACK_COMMENT_MAX + 1)), null);

const view = {
    pricing: 'discount',
    reply: 'We can offer 5%.',
    discountPercent: { live: null, draft: 5 },
    lines: [{ id: 'l1', draft: 9 }],
    expiresAt: { live: null, draft: '2026-10-07' },
};
const untouched = { reply: view.reply, discountPercent: 5, linePrices: { l1: 9 }, expiresAt: '2026-10-07' };
assert.deepEqual(editsPayload(view, untouched), {});
assert.equal(wasEdited(view, untouched), false);
const edited = { ...untouched, discountPercent: 8 };
assert.deepEqual(editsPayload(view, edited), { discountPercent: 8 });
assert.equal(wasEdited(view, edited), true);
assert.equal(wasEdited(view, { ...untouched, reply: 'Something else' }), true);
const lines = { ...view, pricing: 'lines', lines: [{ id: 'l1', draft: 9 }, { id: 'l2', draft: 4 }] };
assert.deepEqual(editsPayload(lines, { ...untouched, discountPercent: 99, linePrices: { l1: 9, l2: 3.5 } }), { linePrices: { l2: 3.5 } });
assert.deepEqual(editsPayload({ ...view, pricing: null }, { ...untouched, discountPercent: 8 }), {});

assert.equal(exceedsCap(12, 10), true);
assert.equal(exceedsCap(10, 10), false);
assert.equal(exceedsCap(12, null), false);
assert.equal(reviewStatusVariant('pending'), 'attention');
assert.equal(reviewStatusVariant('sent'), 'positive');
assert.equal(reviewStatusVariant('rejected'), 'critical');
assert.equal(reviewStatusVariant('superseded'), 'neutral');
assert.equal(reviewStatusVariant(null), 'neutral');
assert.equal(errorCode({ response: { data: { code: 'stale' } } }), 'stale');
assert.equal(errorCode(new Error('network')), null);

console.log('review.check.mjs: all assertions passed');
