import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    FEEDBACK_COMMENT_MAX,
    FEEDBACK_REASONS,
    INVALID_REASONS,
    draftAmount,
    draftPercent,
    editsPayload,
    exceedsCap,
    feedbackPayload,
    localDay,
    needsReplyReview,
    replacesAmount,
    replyCheckedAfterPreview,
    reviewFailure,
    reviewIntroKey,
    reviewStatusVariant,
    sendNeedsPreview,
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
    discount: { live: null, draft: { type: 'percentage', value: 5 } },
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
// Send posts the reply the last Preview drafted, so it must not go out with
// prices that reply never saw (QA-01). A Preview adopts the edits into the
// draft version, after which the payload is empty again.
assert.equal(sendNeedsPreview(view, untouched), false);
assert.equal(sendNeedsPreview(view, edited), true);
assert.equal(sendNeedsPreview(view, { ...untouched, reply: 'Something else' }), false, 'A reply edit alone needs no Preview.');
assert.equal(sendNeedsPreview(view, { ...untouched, expiresAt: '2026-10-09' }), true, 'A new date is an edit Preview has to adopt.');
assert.equal(sendNeedsPreview(view, { ...untouched, discountPercent: null }), false, 'A cleared field is no edit.');
assert.equal(sendNeedsPreview({ ...view, pricing: 'lines' }, { ...untouched, linePrices: { l1: 8.5 } }), true);
assert.equal(sendNeedsPreview({ ...view, pricing: null }, { ...untouched, discountPercent: 8 }), false, 'A draft without prices has nothing to preview.');
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
// A cleared number field is not an edit: sending `null` fails the request's
// mapping, and the drafted price the totals show is what goes out.
assert.deepEqual(editsPayload(lines, { ...untouched, linePrices: { l1: null, l2: 3.5 } }), { linePrices: { l2: 3.5 } });
assert.deepEqual(editsPayload(lines, { ...untouched, linePrices: { l1: undefined, l2: 4 } }), {});
assert.deepEqual(editsPayload(view, { ...untouched, discountPercent: null }), {});

// QuoteTotalRounding drafts a fixed amount (DraftView.php). The percentage
// field starts empty and an unedited send keeps the amount; a typed
// percentage replaces it, which the card has to say.
const rounded = { ...view, discount: { live: { type: 'percentage', value: 5 }, draft: { type: 'absolute', value: 90 } } };
assert.equal(draftAmount(rounded), 90);
assert.equal(draftPercent(rounded), null);
assert.equal(draftAmount(view), null);
assert.equal(draftPercent(view), 5);
assert.equal(draftPercent({ ...view, discount: { live: null, draft: null } }), null);
const roundedForm = { ...untouched, discountPercent: draftPercent(rounded) };
assert.deepEqual(editsPayload(rounded, roundedForm), {});
assert.equal(wasEdited(rounded, roundedForm), false);
assert.equal(replacesAmount(rounded, roundedForm), false);
assert.deepEqual(editsPayload(rounded, { ...roundedForm, discountPercent: 8 }), { discountPercent: 8 });
assert.equal(replacesAmount(rounded, { ...roundedForm, discountPercent: 8 }), true);
assert.equal(replacesAmount(rounded, { ...roundedForm, discountPercent: 0 }), true, 'Zero percent still drops the amount.');
assert.equal(replacesAmount(view, edited), false);
assert.equal(replacesAmount({ ...rounded, pricing: 'lines' }, { ...roundedForm, discountPercent: 8 }), false);

assert.equal(exceedsCap(12, 10), true);
assert.equal(exceedsCap(10, 10), false);
assert.equal(exceedsCap(12, null), false);
assert.equal(reviewStatusVariant('pending'), 'attention');
assert.equal(reviewStatusVariant('sent'), 'positive');
assert.equal(reviewStatusVariant('rejected'), 'critical');
assert.equal(reviewStatusVariant('superseded'), 'neutral');
assert.equal(reviewStatusVariant(null), 'neutral');
// The 400 reasons are the PHP enum's, and each has copy in both locales.
const reasonPhp = readFileSync(new URL('../../../../../../Review/InvalidReviewReason.php', import.meta.url), 'utf8');
const reasonValues = [...reasonPhp.matchAll(/case \w+ = '([a-z_]+)';/g)].map((match) => match[1]);
assert.ok(reasonValues.length > 0, 'no cases read from InvalidReviewReason.php');
assert.deepEqual([...INVALID_REASONS].sort(), reasonValues.sort());

const failure = (status, data) => ({ response: { status, data } });
assert.deepEqual(reviewFailure(failure(409, { code: 'stale' }), 'send'), { blockedBy: 'stale' });
assert.deepEqual(reviewFailure(failure(409, { code: 'busy' }), 'send'), { snippet: 'merchant-quote-agent.review.error.busy' });
assert.deepEqual(
    reviewFailure(failure(400, { code: 'invalid', reason: 'price_increase', message: 'These prices…' }), 'preview'),
    { snippet: 'merchant-quote-agent.review.error.invalid.price_increase' },
);
assert.deepEqual(
    reviewFailure(failure(400, { code: 'invalid', reason: 'from_a_newer_server', message: 'Server copy.' }), 'preview'),
    { message: 'Server copy.' },
    'A reason the card does not know shows the server message, never "Try again".',
);
// DraftSendFailed is not caught by the controller: Shopware answers 500 with
// its own `errors` body, and the reply may already be with the buyer.
assert.deepEqual(
    reviewFailure(failure(500, { errors: [{ code: '0', status: '500' }] }), 'send'),
    { snippet: 'merchant-quote-agent.review.error.send_failed' },
);
assert.deepEqual(reviewFailure(failure(500, { errors: [] }), 'preview'), { snippet: 'merchant-quote-agent.review.error.generic' });
assert.deepEqual(reviewFailure(new Error('network'), 'send'), { snippet: 'merchant-quote-agent.review.error.generic' });

// The date input's minimum is today in the merchant's own time zone.
assert.equal(localDay(new Date(2026, 0, 5, 23, 30)), '2026-01-05');
assert.equal(localDay(new Date(2026, 10, 30, 0, 5)), '2026-11-30');

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

const cardKeys = [
    ...[{ pricing: 'lines' }, { pricing: null, outcome: 'clarified' }, { pricing: null, outcome: 'acknowledged' }].map(reviewIntroKey),
    ...INVALID_REASONS.map((reason) => `merchant-quote-agent.review.error.invalid.${reason}`),
    'merchant-quote-agent.review.error.send_failed',
    'merchant-quote-agent.review.discountAmount',
    'merchant-quote-agent.review.discountReplace',
    'merchant-quote-agent.review.discountSwitch',
    'merchant-quote-agent.review.previewBeforeSend',
];

for (const key of cardKeys) {
    for (const locale of ['en', 'de']) {
        assert.ok(typeof snippetAt(locale, key) === 'string', `snippet/${locale}.json is missing ${key}`);
    }
}

for (const locale of ['en', 'de']) {
    assert.match(snippetAt(locale, 'merchant-quote-agent.review.discountAmount'), /\{amount\}.*\{total\}/, `${locale} amount copy lost a placeholder`);
}

// Meteor 5.7's MtTextarea declares `maxLength`; a `maxlength` attribute falls
// through to its wrapper and limits nothing.
const feedbackModal = readFileSync(new URL('./component/merchant-quote-agent-feedback-modal/merchant-quote-agent-feedback-modal.html.twig', import.meta.url), 'utf8');
assert.match(feedbackModal, /:max-length="/);
assert.doesNotMatch(feedbackModal, /:maxlength="/);

// Saved feedback shows on every pass, a no-op one included: it must not sit
// inside the facts list a nothing_to_do / handed_over pass hides.
const detail = readFileSync(new URL('./page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig', import.meta.url), 'utf8');
const factsOpen = detail.indexOf('<dl v-if="!entry.run.isNoop"');
const factsClose = detail.indexOf('</dl>', factsOpen);
const savedFeedback = detail.indexOf('feedback.savedLabel');
assert.ok(factsOpen >= 0 && factsClose > factsOpen && savedFeedback >= 0, 'detail template landmarks moved');
assert.ok(savedFeedback > factsClose, 'saved feedback is inside the facts list a no-op pass hides');

// Meteor 5.7's MtNumberField emits `update:modelValue` only on change (blur or
// Enter) and `input-change` while typing. Bound to the first alone, a value
// typed and never blurred stayed out of `form`, so Preview and Send posted no
// edit (QA-01). Every number field on the card binds both, to the same field.
const draftReview = readFileSync(new URL('./component/merchant-quote-agent-draft-review/merchant-quote-agent-draft-review.html.twig', import.meta.url), 'utf8');
const numberFields = [...draftReview.matchAll(/<mt-number-field\b[\s\S]*?\/>/g)].map((match) => match[0]);
assert.equal(numberFields.length, 2, 'draft-review number fields moved');

for (const numberField of numberFields) {
    const onChange = numberField.match(/@update:model-value="\(value\) => \{ (.+?) = value; \}"/)?.[1];
    const onInput = numberField.match(/@input-change="\(value\) => \{ (.+?) = value; \}"/)?.[1];
    assert.ok(onChange, `a number field lost its @update:model-value binding:\n${numberField}`);
    assert.equal(onInput, onChange, `a number field does not write ${onChange} on @input-change`);
}

// The guard has to reach the button: Send stays disabled while edits are unpreviewed.
const sendButton = draftReview.match(/<mt-button\b[^>]*@click="send"[^>]*>/)?.[0] ?? '';
assert.match(sendButton, /:disabled="[^"]*\bsendNeedsPreview\b/, 'Send is not disabled while edits are unpreviewed');
assert.match(draftReview, /v-if="[^"]*!blocked\b[^"]*\bsendNeedsPreview\b[^"]*"[^>]*>\s*\{\{ \$tc\('merchant-quote-agent\.review\.previewBeforeSend'\) \}\}/, 'the preview hint is not shown with the guard, or shows on a blocked draft');

console.log('review.check.mjs: all assertions passed');
