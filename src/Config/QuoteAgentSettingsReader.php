<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\UnknownStrategy;
use Shopware\Core\Framework\Context;
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
 * Every value is read and passed on untouched, with one exception: the strategy
 * id, which `resolveStrategy()` turns into a prompt and a version id via a
 * database round-trip before the factory ever sees them. That is also the one
 * refusal this class owns -- a dangling reference is something only the layer
 * touching the database can see.
 *
 * The LLM API key is the one value that may not come from the configuration
 * store at all: `MQA_LLM_API_KEY` overrides it when set. That is injected as a
 * container parameter rather than read with `getenv()`, the same reason
 * `LOCK_DSN` is.
 */
final readonly class QuoteAgentSettingsReader implements QuoteAgentSettingsSource, BuyerNotificationPreference
{
    public const DOMAIN = 'MerchantQuoteAgentPlugin.config.';

    private const KEYS = [
        'enabled',
        'llmApiKey',
        'llmBaseUrl',
        'llmModel',
        'negotiationStrategyId',
        'maxDiscountPercent',
        'counterOfferMaxPercent',
        'minMarginPercent',
        'roundingMode',
        'roundingStep',
        'maxQuoteValueNet',
        'validityDays',
        'notifyBuyerOnEscalation',
        'draftMode',
    ];

    public function __construct(
        private SystemConfigService $config,
        private QuoteAgentSettingsFactory $factory,
        private StrategyResolver $strategies,
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

        $this->resolveStrategy($raw);

        return $this->factory->fromValues($raw);
    }

    /**
     * Read raw and never validated, which is the whole point: see
     * BuyerNotificationPreference. `!== false` rather than `=== true` so an
     * unset key means notify, matching config.xml's defaultValue and the
     * identical rule in QuoteAgentSettingsFactory — only an explicit false
     * silences the notice. Draft Mode silences it too: in Draft Mode the agent
     * says nothing to the buyer at all.
     */
    #[\Override]
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool
    {
        return (
            $this->config->get(self::DOMAIN . 'notifyBuyerOnEscalation', $salesChannelId) !== false
            && !$this->draftMode($salesChannelId)
        );
    }

    /** Default off: only an explicit true holds the agent's output back for review. */
    public function draftMode(?string $salesChannelId): bool
    {
        return $this->config->get(self::DOMAIN . 'draftMode', $salesChannelId) === true;
    }

    /** Default off: absent or false both mean the assistant may not act for the buyer. */
    public function assistantQuoteRequests(?string $salesChannelId): bool
    {
        return $this->config->get(self::DOMAIN . 'assistantQuoteRequests', $salesChannelId) === true;
    }

    /**
     * Resolves the configured strategy id to the two raw keys the factory
     * already knows how to read -- the prompt text and the version id -- so
     * the factory stays pure and never learns a database was involved.
     *
     * A dangling reference (missing or archived) is refused as a
     * configuration problem rather than silently treated as "no strategy":
     * that would change this channel's negotiating behaviour invisibly,
     * where throwing escalates the quote to a human instead.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidQuoteAgentConfiguration
     */
    private function resolveStrategy(array &$raw): void
    {
        $raw['negotiationStrategy'] = null;
        $raw['negotiationStrategyVersionId'] = null;

        $strategyId = $raw['negotiationStrategyId'] ?? null;

        if (!\is_string($strategyId) || trim($strategyId) === '') {
            return;
        }

        try {
            $resolved = $this->strategies->resolve(trim($strategyId), Context::createDefaultContext());
        } catch (UnknownStrategy $e) {
            throw new InvalidQuoteAgentConfiguration([$e->getMessage()], previous: $e);
        }

        $raw['negotiationStrategy'] = $resolved->prompt;
        $raw['negotiationStrategyVersionId'] = $resolved->versionId;
    }
}
