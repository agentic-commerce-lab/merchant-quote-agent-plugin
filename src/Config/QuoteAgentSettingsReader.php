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
 * `maxQuoteValueNet` is the one value this class does more than read. The
 * admin stores a price field as a list of `{currencyId, net, ...}` rows, and a
 * currency id is a uuid only Shopware can resolve — so the ISO lookup happens
 * here, and the factory below stays pure.
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
    ];

    public function __construct(
        private SystemConfigService $config,
        private QuoteAgentSettingsFactory $factory,
        private CurrencyIsoResolver $currencies,
    ) {}

    /** @throws InvalidQuoteAgentConfiguration */
    #[\Override]
    public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
    {
        $raw = [];

        foreach (self::KEYS as $key) {
            $raw[$key] = $this->config->get(self::DOMAIN . $key, $salesChannelId);
        }

        $ceiling = $this->currencies->netByIso($raw['maxQuoteValueNet']);

        if ($ceiling !== null) {
            $raw['maxQuoteValueNet'] = $ceiling;
        }

        return $this->factory->fromValues($raw);
    }
}
