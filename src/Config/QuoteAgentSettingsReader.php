<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The one place that touches Shopware's configuration store. Everything it
 * knows is which keys exist; the meaning of the values is the factory's.
 *
 * Values come through `get()`, never `getFloat()` / `getInt()`, because those
 * coerce an unset value to 0 — and "unset" is load-bearing here: a blank
 * sub-policy field must stay null so the dimension escalates.
 *
 * A null sales-channel id reads the global value, and a sales-channel id falls
 * back to it. That is Shopware's own semantics and the reason a merchant can
 * configure once and override for a pilot channel.
 *
 * Every value is read and passed on untouched; the meaning is the factory's.
 *
 * The LLM API key is the one value that may not come from the configuration
 * store at all: `MQA_LLM_API_KEY` overrides it when set. That is injected as a
 * container parameter rather than read with `getenv()`, the same reason
 * `LOCK_DSN` is.
 */
final readonly class QuoteAgentSettingsReader implements QuoteAgentSettingsSource
{
    public const DOMAIN = 'MerchantQuoteAgentPlugin.config.';

    private const KEYS = [
        'enabled',
        'llmApiKey',
        'llmBaseUrl',
        'llmModel',
        'negotiationStrategy',
        'maxDiscountPercent',
        'counterOfferMaxPercent',
        'maxQuoteValueNet',
        'validityDays',
        'notifyBuyerOnEscalation',
    ];

    public function __construct(
        private SystemConfigService $config,
        private QuoteAgentSettingsFactory $factory,
        #[\SensitiveParameter]
        private ?string $envApiKey = null,
    ) {}

    /** @throws InvalidQuoteAgentConfiguration */
    #[\Override]
    public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
    {
        $raw = [];

        foreach (self::KEYS as $key) {
            $raw[$key] = $this->config->get(self::DOMAIN . $key, $salesChannelId);
        }

        // The environment wins when it is set, so a merchant who can set it
        // keeps the key out of the database entirely -- out of reach of a
        // `system_config:read` token and of a database dump alike. A blank or
        // whitespace-only value is treated as unset rather than as an
        // instruction to blank the configured key.
        if ($this->envApiKey !== null && trim($this->envApiKey) !== '') {
            $raw['llmApiKey'] = $this->envApiKey;
        }

        return $this->factory->fromValues($raw);
    }
}
