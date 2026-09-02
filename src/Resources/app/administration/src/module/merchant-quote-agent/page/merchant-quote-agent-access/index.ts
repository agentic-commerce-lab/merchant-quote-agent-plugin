import template from './merchant-quote-agent-access.html.twig';

/**
 * Edits the three UCP allowlists for one sales channel.
 *
 * The data belongs to the Agentic Commerce plugin, so this page drives that
 * plugin's own admin API rather than writing its table: the PUT goes through its
 * UcpConfigService, which validates every host and merges the payload over the
 * stored config. We therefore send only the three keys we edit, and anything set
 * by console survives untouched.
 *
 * Saving needs that plugin's `ucp.editor` privilege, which is separate from this
 * plugin's own. A 403 from it is shown as-is rather than reported as success.
 */
Shopware.Component.register('merchant-quote-agent-access', {
    template,

    inject: ['httpClient', 'syncService', 'acl'],

    data() {
        return {
            salesChannels: [],
            salesChannelId: null,
            lists: { platformAllowlist: '', remoteProfileAllowlist: '', agentAllowlist: '' },
            isLoading: false,
            isSaving: false,
            error: null,
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('ucp.editor');
        },
    },

    created() {
        this.loadSalesChannels();
    },

    methods: {
        headers() {
            return this.syncService.getBasicHeaders();
        },

        async loadSalesChannels() {
            this.isLoading = true;
            this.error = null;

            try {
                const { data } = await this.httpClient.get('_admin/ucp/sales-channels', { headers: this.headers() });
                this.salesChannels = data?.data ?? data ?? [];
                this.salesChannelId = this.salesChannels[0]?.id ?? null;

                if (this.salesChannelId) {
                    await this.loadConfig();
                }
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Switching channels starts a request per switch, and they can resolve
         * out of order. Anything that comes back for a channel the user has
         * since navigated away from is dropped, so the fields always describe
         * the channel the select is showing.
         */
        async loadConfig() {
            const requested = this.salesChannelId;

            this.isLoading = true;
            this.error = null;

            try {
                const { data } = await this.httpClient.get(
                    `_admin/ucp/sales-channels/${requested}/config`,
                    { headers: this.headers() },
                );

                if (this.salesChannelId !== requested) {
                    return;
                }

                const config = data?.data ?? data ?? {};

                this.lists = {
                    platformAllowlist: this.toText(config.platformAllowlist),
                    remoteProfileAllowlist: this.toText(config.remoteProfileAllowlist),
                    agentAllowlist: this.toText(config.agentAllowlist),
                };
            } catch (error) {
                if (this.salesChannelId === requested) {
                    this.error = this.messageFor(error);
                }
            } finally {
                if (this.salesChannelId === requested) {
                    this.isLoading = false;
                }
            }
        },

        async save() {
            const salesChannelId = this.salesChannelId;

            this.isSaving = true;
            this.error = null;

            try {
                await this.httpClient.put(
                    `_admin/ucp/sales-channels/${salesChannelId}/config`,
                    {
                        platformAllowlist: this.fromText(this.lists.platformAllowlist),
                        remoteProfileAllowlist: this.fromText(this.lists.remoteProfileAllowlist),
                        agentAllowlist: this.fromText(this.lists.agentAllowlist),
                    },
                    { headers: this.headers() },
                );
                await this.loadConfig();
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isSaving = false;
            }
        },

        toText(values) {
            return Array.isArray(values) ? values.join('\n') : '';
        },

        /**
         * Splits on newlines and commas so a pasted list works, trims, drops
         * blanks and de-duplicates while keeping the typed order. Entries are
         * otherwise left alone: the Agentic Commerce plugin normalizes them and
         * names the offending host by path if one is malformed.
         */
        fromText(text) {
            const entries = String(text ?? '')
                .split(/[\n,]/)
                .map((entry) => entry.trim())
                .filter((entry) => entry !== '');

            return entries.filter((entry, index) => entries.indexOf(entry) === index);
        },

        messageFor(error) {
            const response = error?.response;
            const detail = response?.data?.errors?.[0]?.detail;

            if (response?.status === 403) {
                return detail ?? this.$tc('merchant-quote-agent.access.forbidden');
            }

            return detail ?? this.$tc('merchant-quote-agent.access.failed');
        },
    },
});
