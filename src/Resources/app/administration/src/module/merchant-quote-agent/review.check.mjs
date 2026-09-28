import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    FEEDBACK_COMMENT_MAX,
    FEEDBACK_REASONS,
    editsPayload,
    errorCode,
    exceedsCap,
    feedbackPayload,
    needsReplyReview,
    replyCheckedAfterPreview,
    reviewIntroKey,
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
    previewEdited: false,
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
assert.equal(needsReplyReview(view, edited, false, false), true);
assert.equal(needsReplyReview({ ...view, previewEdited: true }, untouched, false, false), true);
assert.equal(needsReplyReview({ ...view, previewEdited: true }, untouched, false, true), false);
assert.equal(needsReplyReview({ ...view, previewEdited: true }, untouched, true, false), false);
assert.equal(replyCheckedAfterPreview({ ...view, replyRedrafted: false }, false), false);
assert.equal(replyCheckedAfterPreview({ ...view, replyRedrafted: true }, false), true);
assert.equal(replyCheckedAfterPreview({ ...view, replyRedrafted: true }, true), false);
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

// A draft without prices is a clarification or an acknowledgement, and the
// card's opening line has to say which: an acknowledgement asks nothing.
assert.equal(reviewIntroKey({ pricing: 'discount', outcome: 'offered' }), 'merchant-quote-agent.review.intro');
assert.equal(reviewIntroKey({ pricing: null, outcome: 'clarified' }), 'merchant-quote-agent.review.introClarification');
assert.equal(reviewIntroKey({ pricing: null, outcome: 'acknowledged' }), 'merchant-quote-agent.review.introAcknowledgement');

const snippets = Object.fromEntries(['en', 'de'].map((locale) => [
    locale,
    JSON.parse(readFileSync(new URL(`./snippet/${locale}.json`, import.meta.url), 'utf8')),
]));
const snippetAt = (locale, key) => key.split('.').reduce((node, part) => node?.[part], snippets[locale]);

for (const view of [{ pricing: 'lines' }, { pricing: null, outcome: 'clarified' }, { pricing: null, outcome: 'acknowledged' }]) {
    for (const locale of ['en', 'de']) {
        const key = reviewIntroKey(view);
        assert.ok(typeof snippetAt(locale, key) === 'string', `snippet/${locale}.json is missing ${key}`);
    }
}

console.log('review.check.mjs: all assertions passed');
