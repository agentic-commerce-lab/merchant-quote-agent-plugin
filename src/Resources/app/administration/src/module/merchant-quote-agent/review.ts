/** Pure Draft Mode review and feedback logic. Keep the reason list in sync with FeedbackReason.php. */
export const FEEDBACK_REASONS = [
    'wrong_price',
    'wrong_wording',
    'misunderstood_buyer',
    'should_have_escalated',
    'should_not_have_escalated',
    'other',
] as const;

/** InvalidReviewReason.php's values: a 400 the merchant can fix, each with its own copy. */
export const INVALID_REASONS = [
    'malformed',
    'not_an_admin_user',
    'empty_reply',
    'discount_out_of_range',
    'negative_price',
    'date_format',
    'date_in_past',
    'price_increase',
    'no_prices',
    'unknown_line',
    'comment_too_long',
    'empty_feedback',
] as const;

/** The 409 codes that block the card until the merchant rejects or reloads. */
const BLOCKING_CODES = ['stale', 'gone', 'published', 'not_pending', 'unavailable'];

/** PHP limits comments to 2,000 code points; JS UTF-16 length is never looser. */
export const FEEDBACK_COMMENT_MAX = 2000;
export const REVIEW_PRIVILEGE = 'merchant_quote_agent_drafts.review';

export interface DraftView {
    outcome: string | null;
    pricing: 'lines' | 'discount' | null;
    reply: string;
    previewEdited: boolean;
    replyRedrafted: boolean;
    discountPercent: { live: number | null; draft: number | null };
    lines: Array<{ id: string; draft: number }>;
    expiresAt: { live: string | null; draft: string | null };
}

export interface DraftForm {
    reply: string;
    discountPercent: number | null;
    linePrices: Record<string, number>;
    expiresAt: string | null;
}

const CENT = 0.005;

function same(a: number | null | undefined, b: number | null | undefined): boolean {
    if (a === null || a === undefined || b === null || b === undefined) {
        return a === b;
    }

    return Math.abs(a - b) < CENT;
}

/** Nothing to save, or a comment too long: the save button remains disabled. */
export function feedbackPayload(reasons: string[], comment: string): { reasons: string[]; comment: string } | null {
    const known = FEEDBACK_REASONS.filter((reason) => reasons.includes(reason));
    const text = comment.trim();

    if ((known.length === 0 && text === '') || text.length > FEEDBACK_COMMENT_MAX) {
        return null;
    }

    return { reasons: [...known], comment: text };
}

function isNumber(value: unknown): value is number {
    return typeof value === 'number' && Number.isFinite(value);
}

/**
 * Submit changed fields only, and only the price type actually drafted. A
 * cleared number field is no edit: the drafted price stays, as the totals
 * show, rather than a `null` the request mapping refuses.
 */
export function editsPayload(view: DraftView, form: DraftForm): Record<string, unknown> {
    const edits: Record<string, unknown> = {};

    if (view.pricing === null) {
        return edits;
    }

    if (view.pricing === 'discount' && isNumber(form.discountPercent) && !same(form.discountPercent, view.discountPercent.draft)) {
        edits.discountPercent = form.discountPercent;
    }

    if (view.pricing === 'lines') {
        const moved = view.lines.filter((line) => isNumber(form.linePrices[line.id]) && !same(form.linePrices[line.id], line.draft));

        if (moved.length > 0) {
            edits.linePrices = Object.fromEntries(moved.map((line) => [line.id, form.linePrices[line.id]]));
        }
    }

    if (form.expiresAt && form.expiresAt !== view.expiresAt.draft) {
        edits.expiresAt = form.expiresAt;
    }

    return edits;
}

export function wasEdited(view: DraftView, form: DraftForm): boolean {
    return form.reply.trim() !== view.reply.trim() || Object.keys(editsPayload(view, form)).length > 0;
}

/** A persisted Preview edit still needs a reply check after a page reload. */
export function needsReplyReview(view: DraftView | null, form: DraftForm | null, replyTouched: boolean, replyChecked: boolean): boolean {
    if (view === null || form === null || replyTouched) {
        return false;
    }

    return wasEdited(view, form) || (view.previewEdited && !replyChecked);
}

export function replyCheckedAfterPreview(view: DraftView, replyTouched: boolean): boolean {
    return view.replyRedrafted && !replyTouched;
}

/** Merchant cap is advisory here; it binds the agent, not the human reviewer. */
export function exceedsCap(discount: number | null, cap: number | null): boolean {
    return discount !== null && cap !== null && discount > cap + CENT;
}

/**
 * The card's opening line. A draft without prices is a clarification or an
 * acknowledgement (DraftView.php), and only the first one asks the buyer
 * anything.
 */
export function reviewIntroKey(view: Pick<DraftView, 'pricing' | 'outcome'>): string {
    if (view.pricing !== null) {
        return 'merchant-quote-agent.review.intro';
    }

    return view.outcome === 'acknowledged'
        ? 'merchant-quote-agent.review.introAcknowledgement'
        : 'merchant-quote-agent.review.introClarification';
}

const STATUS_VARIANTS: Record<string, string> = {
    pending: 'attention',
    sent: 'positive',
    rejected: 'critical',
    superseded: 'neutral',
};

export function reviewStatusVariant(status: string | null): string {
    return (status && STATUS_VARIANTS[status]) || 'neutral';
}

/** A calendar day in the browser's own time zone, as `<input type="date">` reads it. */
export function localDay(date: Date): string {
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export type ReviewAction = 'load' | 'preview' | 'send' | 'reject';

/** What the card does with a failed request: block itself, or notify with a snippet or the server's own copy. */
export type ReviewFailure = { blockedBy: string } | { snippet: string } | { message: string };

function field(value: unknown, key: string): unknown {
    return typeof value === 'object' && value !== null && key in value ? (value as Record<string, unknown>)[key] : undefined;
}

/**
 * DraftReviewController answers 409 `{code}` for a draft that cannot be acted
 * on and 400 `{code: 'invalid', reason, message}` for a request the merchant
 * can fix. A reason the card has no copy for yet shows the server's message,
 * which is merchant-facing too. A Send that failed past its merge
 * (DraftSendFailed) is not caught there, so Shopware answers 500 — and the
 * reply may already be with the buyer, which "Try again" must not hide.
 */
export function reviewFailure(error: unknown, action: ReviewAction): ReviewFailure {
    const response = field(error, 'response');
    const data = field(response, 'data');
    const code = field(data, 'code');
    const reason = field(data, 'reason');
    const message = field(data, 'message');

    if (typeof code === 'string' && BLOCKING_CODES.includes(code)) {
        return { blockedBy: code };
    }

    if (code === 'invalid' && (INVALID_REASONS as readonly unknown[]).includes(reason)) {
        return { snippet: `merchant-quote-agent.review.error.invalid.${String(reason)}` };
    }

    if (code === 'invalid' && typeof message === 'string' && message !== '') {
        return { message };
    }

    if (code === 'busy') {
        return { snippet: 'merchant-quote-agent.review.error.busy' };
    }

    if (action === 'send' && field(response, 'status') === 500) {
        return { snippet: 'merchant-quote-agent.review.error.send_failed' };
    }

    return { snippet: 'merchant-quote-agent.review.error.generic' };
}
