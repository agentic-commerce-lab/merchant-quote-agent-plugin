import template from './merchant-quote-agent-draft-review.html.twig';
import { formatCurrency, formatPercent } from '../../decision';
import { REVIEW_PRIVILEGE, editsPayload, errorCode, exceedsCap, needsReplyReview, replyCheckedAfterPreview, reviewIntroKey, wasEdited } from '../../review';
import type { DraftForm, DraftView } from '../../review';

interface ReviewResponse extends DraftView {
    stale: boolean;
    maxDiscountPercent: number | null;
}

interface SyncService {
    getBasicHeaders(): Record<string, string>;
    httpClient: {
        get<T>(url: string, options: { headers: Record<string, string> }): Promise<{ data: T }>;
        post<T>(url: string, body: Record<string, unknown>, options: { headers: Record<string, string> }): Promise<{ data: T }>;
    };
}

Shopware.Component.register('merchant-quote-agent-draft-review', {
    template,

    inject: ['syncService', 'acl'],
    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        decisionId: { type: String, required: true },
    },

    emits: ['reviewed', 'rejected'],

    data() {
        return {
            view: null as ReviewResponse | null,
            form: null as DraftForm | null,
            redraft: null as string | null,
            replyTouched: false,
            replyChecked: false,
            everEdited: false,
            isLoading: false,
            isSaving: false,
            blockedBy: null as string | null,
        };
    },

    computed: {
        base() {
            return `_action/merchant-quote-agent/decision/${this.decisionId}`;
        },

        canReview() {
            return this.acl.can(REVIEW_PRIVILEGE);
        },

        overCap() {
            return this.view?.pricing === 'discount' && exceedsCap(this.form?.discountPercent ?? null, this.view.maxDiscountPercent);
        },

        edited() {
            return this.view !== null && this.form !== null && wasEdited(this.view, this.form);
        },

        needsReplyReview() {
            return needsReplyReview(this.view, this.form, this.replyTouched, this.replyChecked);
        },

        blocked() {
            return this.blockedBy !== null || this.view?.stale === true;
        },

        blockedMessage() {
            const code = this.blockedBy ?? (this.view?.stale ? 'stale' : null);

            return code ? this.$tc(`merchant-quote-agent.review.error.${code}`) : '';
        },
    },

    watch: {
        decisionId() {
            void this.load();
        },
    },

    created() {
        void this.load();
    },

    methods: {
        formatCurrency,
        formatPercent,
        reviewIntroKey,

        sync(): SyncService {
            return this.syncService as SyncService;
        },

        options() {
            return { headers: this.sync().getBasicHeaders() };
        },

        adopt(view: ReviewResponse) {
            this.view = view;
            this.form = {
                reply: view.reply,
                discountPercent: view.discountPercent.draft,
                linePrices: Object.fromEntries(view.lines.map((line) => [line.id, line.draft])),
                expiresAt: view.expiresAt.draft,
            };
            this.replyTouched = false;
            this.replyChecked = false;
            this.redraft = null;
        },

        async load() {
            this.isLoading = true;
            this.blockedBy = null;
            this.everEdited = false;

            try {
                const response = await this.sync().httpClient.get<ReviewResponse>(`${this.base}/draft`, this.options());
                this.adopt(response.data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.isLoading = false;
            }
        },

        async preview() {
            const view = this.view;
            const form = this.form;

            if (view === null || form === null) {
                return;
            }

            this.isSaving = true;
            const edited = this.edited;

            try {
                const response = await this.sync().httpClient.post<ReviewResponse>(
                    `${this.base}/preview`,
                    editsPayload(view, form),
                    this.options(),
                );
                const reply = form.reply;
                const touched = this.replyTouched;
                this.adopt(response.data);
                this.everEdited = this.everEdited || edited;
                this.replyChecked = replyCheckedAfterPreview(response.data, touched);

                // Never overwrite a merchant's reply; offer the re-draft beside it.
                if (touched) {
                    this.redraft = response.data.replyRedrafted ? response.data.reply : null;
                    if (this.form) {
                        this.form.reply = reply;
                    }
                    this.replyTouched = true;
                }
            } catch (error) {
                this.fail(error);
            } finally {
                this.isSaving = false;
            }
        },

        useRedraft() {
            if (this.form === null || this.redraft === null) {
                return;
            }

            this.form.reply = this.redraft;
            this.redraft = null;
            this.replyTouched = false;
            this.replyChecked = true;
        },

        async send() {
            const view = this.view;
            const form = this.form;

            if (view === null || form === null) {
                return;
            }

            this.isSaving = true;
            const edited = this.edited || this.everEdited;

            try {
                await this.sync().httpClient.post<unknown>(
                    `${this.base}/send`,
                    { reply: form.reply, ...editsPayload(view, form) },
                    this.options(),
                );
                this.createNotificationSuccess({ message: this.$tc('merchant-quote-agent.review.sent') });
                this.$emit('reviewed', { edited });
            } catch (error) {
                this.fail(error);
            } finally {
                this.isSaving = false;
            }
        },

        async reject() {
            this.isSaving = true;

            try {
                await this.sync().httpClient.post<unknown>(`${this.base}/reject`, {}, this.options());
                this.createNotificationInfo({ message: this.$tc('merchant-quote-agent.review.rejected') });
                this.$emit('rejected');
            } catch (error) {
                this.fail(error);
            } finally {
                this.isSaving = false;
            }
        },

        fail(error: unknown) {
            const code = errorCode(error);

            if (['stale', 'gone', 'published', 'not_pending', 'unavailable'].includes(code ?? '')) {
                this.blockedBy = code;

                return;
            }

            this.createNotificationError({
                message: this.$tc(`merchant-quote-agent.review.error.${code === 'busy' ? 'busy' : 'generic'}`),
            });
        },
    },
});
