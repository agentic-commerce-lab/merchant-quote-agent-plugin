import template from './merchant-quote-agent-feedback-modal.html.twig';
import { FEEDBACK_REASONS, feedbackPayload } from '../../review';

interface SyncService {
    getBasicHeaders(): Record<string, string>;
    httpClient: {
        put(url: string, body: { reasons: string[]; comment: string }, options: { headers: Record<string, string> }): Promise<unknown>;
    };
}

Shopware.Component.register('merchant-quote-agent-feedback-modal', {
    template,

    inject: ['syncService'],
    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        decisionId: { type: String, required: true },
        reasons: { type: Array, default: () => [] },
        comment: { type: String, default: '' },
        prompt: { type: String, default: '' },
    },

    emits: ['close', 'saved'],

    data() {
        return {
            selected: this.reasons.filter((reason): reason is string => typeof reason === 'string'),
            text: this.comment,
            isSaving: false,
        };
    },

    computed: {
        options() {
            return FEEDBACK_REASONS.map((value) => ({
                value,
                label: this.$tc(`merchant-quote-agent.feedback.reasons.${value}`),
            }));
        },

        payload() {
            return feedbackPayload(this.selected, this.text);
        },
    },

    methods: {
        sync(): SyncService {
            return this.syncService as SyncService;
        },

        toggle(value: string, on: boolean) {
            this.selected = on ? [...new Set([...this.selected, value])] : this.selected.filter((v: string) => v !== value);
        },

        async save() {
            if (this.payload === null) {
                return;
            }

            this.isSaving = true;

            try {
                await this.sync().httpClient.put(
                    `_action/merchant-quote-agent/decision/${this.decisionId}/feedback`,
                    this.payload,
                    { headers: this.sync().getBasicHeaders() },
                );
                this.createNotificationSuccess({ message: this.$tc('merchant-quote-agent.feedback.saved') });
                this.$emit('saved', this.payload);
            } catch (error) {
                this.createNotificationError({ message: this.$tc('merchant-quote-agent.feedback.failed') });
                console.error('merchant-quote-agent: feedback failed', error);
            } finally {
                this.isSaving = false;
            }
        },
    },
});
