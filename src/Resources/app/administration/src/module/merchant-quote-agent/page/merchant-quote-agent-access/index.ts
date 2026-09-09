import template from './merchant-quote-agent-access.html.twig';

/**
 * Edits the three UCP allowlists, plus the Identity Linking capability, for
 * one sales channel.
 *
 * The data belongs to the Agentic Commerce plugin, so this page drives that
 * plugin's own admin API rather than writing its table: the PUT goes through its
 * UcpConfigService, which validates every host and merges the payload over the
 * stored config. We therefore send only the keys we edit, and anything set
 * by console survives untouched. That merge is top-level only, though: a list
 * value such as `enabledCapabilities` is replaced wholesale, not merged
 * element-wise, so save() always sends the complete array it loaded rather
 * than just the toggled entry — otherwise saving allowlists here would
 * silently disable every other capability. Saving is not otherwise inert,
 * though: on an active channel that same method also provisions a signing key
 * and enables the agentic-files bridge. Both are idempotent and identical to
 * what Agentic Commerce's own settings screen does on save.
 *
 * Saving needs that plugin's `ucp.editor` privilege, which is separate from this
 * plugin's own. A 403 from it is shown as-is rather than reported as success.
 */
Shopware.Component.register('merchant-quote-agent-access', {
    template,

    inject: ['syncService', 'acl'],

    data() {
        return {
            salesChannels: [],
            salesChannelId: null,
            lists: { platformAllowlist: '', remoteProfileAllowlist: '', agentAllowlist: '' },
            capabilities: [],
            profileDomain: null,
            isLoading: false,
            isSaving: false,
            // True only once loadConfig() has completed successfully for the
            // channel currently selected. Save is gated on this: the PUT
            // replaces enabledCapabilities wholesale, so saving from a blank
            // or stale `capabilities` (never loaded, or a load that failed
            // after a channel switch) would silently wipe every capability on
            // that sales channel. Cleared on channel switch and on load
            // failure; set in loadConfig() only behind its own
            // requested-channel guard, so a slow, now-stale response cannot
            // arm it for whatever channel is selected by the time it resolves.
            configLoaded: false,
            error: null,
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('ucp.editor');
        },

        /**
         * `identity_linking` is off by default and has no control in Agentic
         * Commerce's own admin UI, so it lives here instead. The checkbox only
         * toggles that one entry; `capabilities` keeps every other entry
         * (catalog, cart, discount, checkout, order, ...) untouched, because the
         * config endpoint replaces a list wholesale rather than merging it
         * element-wise — see the PUT in save().
         */
        identityLinkingEnabled: {
            get() {
                return this.capabilities.includes('identity_linking');
            },

            set(value) {
                this.capabilities = value
                    ? [...this.capabilities.filter((capability) => capability !== 'identity_linking'), 'identity_linking']
                    : this.capabilities.filter((capability) => capability !== 'identity_linking');
            },
        },

        httpClient() {
            return this.syncService.httpClient;
        },

        selectedChannel() {
            return this.salesChannels.find((channel) => channel.id === this.salesChannelId) ?? null;
        },

        /**
         * The host every empty list ultimately falls back to.
         *
         * `hostname`, not `host`: PHP's `parse_url($uri, PHP_URL_HOST)` drops the
         * port, so `localhost:8095` resolves to `localhost` at runtime and
         * showing the port here would misstate the rule.
         */
        channelHost() {
            const base = this.profileDomain || this.selectedChannel?.domains?.[0]?.url || '';

            try {
                return new URL(base).hostname || null;
            } catch {
                return null;
            }
        },

        /**
         * What the three lists resolve to for real.
         *
         * Mirrors `UcpConfig::toRuntimeConfiguration()` and `::resolveBaseUri()`
         * in the Agentic Commerce plugin. That is a copy of someone else's rule
         * and can drift, which is a real cost — but three empty fields tell a
         * merchant nothing about whether the shop is open or shut, and the hint
         * text this replaces got the answer wrong: empty lists do not deny every
         * remote agent, they narrow access to the shop's own host.
         */
        effective() {
            const platform = this.fromText(this.lists.platformAllowlist);
            const profile = this.fromText(this.lists.remoteProfileAllowlist);
            const agent = this.fromText(this.lists.agentAllowlist);

            const fallback = platform.length > 0
                ? { hosts: platform, source: 'platform' }
                : { hosts: this.channelHost ? [this.channelHost] : [], source: 'channel' };

            return {
                profile: profile.length > 0 ? { hosts: profile, source: 'explicit' } : fallback,
                agent: agent.length > 0 ? { hosts: agent, source: 'explicit' } : fallback,
            };
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

                // Overrides the channel domain as the fallback host, so the
                // effective lists cannot be read without it.
                this.profileDomain = config.profileDomain ?? null;

                this.lists = {
                    platformAllowlist: this.toText(config.platformAllowlist),
                    remoteProfileAllowlist: this.toText(config.remoteProfileAllowlist),
                    agentAllowlist: this.toText(config.agentAllowlist),
                };
                this.capabilities = Array.isArray(config.enabledCapabilities) ? config.enabledCapabilities : [];
                this.configLoaded = true;
            } catch (error) {
                if (this.salesChannelId === requested) {
                    this.error = this.messageFor(error);
                    this.configLoaded = false;
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
                        enabledCapabilities: this.capabilities,
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
