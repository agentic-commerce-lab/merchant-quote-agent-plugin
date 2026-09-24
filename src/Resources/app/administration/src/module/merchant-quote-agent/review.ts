/** Pure Draft Mode review and feedback logic. Keep the reason list in sync with FeedbackReason.php. */
export const FEEDBACK_REASONS = [
    'wrong_price',
    'wrong_wording',
    'misunderstood_buyer',
    'should_have_escalated',
    'should_not_have_escalated',
    'other',
] as const;

/** PHP limits comments to 2,000 code points; JS UTF-16 length is never looser. */
export const FEEDBACK_COMMENT_MAX = 2000;
export const REVIEW_PRIVILEGE = 'merchant_quote_agent_drafts.review';

export interface DraftView {
    pricing: 'lines' | 'discount' | null;
    reply: string;
    previewEdited: boolean;
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

/** Submit changed fields only, and only the price type actually drafted. */
export function editsPayload(view: DraftView, form: DraftForm): Record<string, unknown> {
    const edits: Record<string, unknown> = {};

    if (view.pricing === null) {
        return edits;
    }

    if (view.pricing === 'discount' && form.discountPercent !== null && !same(form.discountPercent, view.discountPercent.draft)) {
        edits.discountPercent = form.discountPercent;
    }

    if (view.pricing === 'lines') {
        const moved = view.lines.filter((line) => !same(form.linePrices[line.id], line.draft));

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

/** Merchant cap is advisory here; it binds the agent, not the human reviewer. */
export function exceedsCap(discount: number | null, cap: number | null): boolean {
    return discount !== null && cap !== null && discount > cap + CENT;
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

/** Error code returned by DraftReviewController, if this response came from it. */
export function errorCode(error: unknown): string | null {
    if (typeof error !== 'object' || error === null || !('response' in error)) {
        return null;
    }

    const response = error.response;

    if (typeof response !== 'object' || response === null || !('data' in response)) {
        return null;
    }

    const data = response.data;

    if (typeof data !== 'object' || data === null || !('code' in data)) {
        return null;
    }

    return typeof data.code === 'string' ? data.code : null;
}
