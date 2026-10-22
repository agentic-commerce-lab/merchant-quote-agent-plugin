<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Everything one sales channel needs to run the nightly self-improvement
 * loop, or null when it should not run tonight.
 *
 * Follows QuoteAgentSettingsReader exactly: the same DOMAIN, values read
 * through `get()` and never `getInt()` / `getFloat()` (an unset key must not
 * coerce to 0 and silently become the clamp floor), and the model resolved
 * field by field from the agent's own settings -- a merchant who fills in
 * only the model wants the agent's provider with a different model, and
 * falling back as a whole would drop the one field they set and bill them
 * for the wrong thing.
 *
 * null means "do not run tonight", for either of two reasons this class
 * deliberately does not distinguish to its caller: the feature itself is
 * off, or the agent underneath it is off or misconfigured on this channel --
 * there is nothing to improve and no credentials to improve it with. That
 * mirrors QuoteAgentSettingsFactory's own contract: an instance always means
 * enabled and valid, so a caller can never forget to check.
 */
final readonly class ImprovementSettingsReader
{
    public const DOMAIN = 'MerchantQuoteAgentPlugin.config.';

    public function __construct(
        private SystemConfigService $config,
        private QuoteAgentSettingsSource $agent,
    ) {}

    /** @throws InvalidQuoteAgentConfiguration */
    public function forSalesChannel(?string $salesChannelId): ?ImprovementSettings
    {
        if ($this->config->get(self::DOMAIN . 'improvementEnabled', $salesChannelId) !== true) {
            return null;
        }

        $agent = $this->agent->forSalesChannel($salesChannelId);

        if ($agent === null) {
            // The agent itself is off or misconfigured on this channel. There
            // is nothing to improve and no credentials to improve it with.
            return null;
        }

        $model = $this->config->get(self::DOMAIN . 'improvementLlmModel', $salesChannelId);

        return new ImprovementSettings(
            enabled: true,
            cadence: ImprovementCadence::fromRaw($this->config->get(
                self::DOMAIN . 'improvementCadence',
                $salesChannelId,
            )),
            sampleSize: self::asInt($this->config->get(self::DOMAIN . 'improvementSampleSize', $salesChannelId), 20),
            candidates: self::asInt($this->config->get(self::DOMAIN . 'improvementCandidates', $salesChannelId), 2),
            llm: new ModelAccess(
                $agent->llm->apiKey,
                $agent->llm->baseUrl,
                \is_string($model) && trim($model) !== '' ? trim($model) : $agent->llm->model,
            ),
        );
    }

    private static function asInt(mixed $raw, int $fallback): int
    {
        return \is_int($raw) || \is_string($raw) && is_numeric($raw) ? (int) $raw : $fallback;
    }
}
