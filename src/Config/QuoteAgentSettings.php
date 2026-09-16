<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * Everything the agent needs to service one sales channel, already validated.
 *
 * There is deliberately no `enabled` flag. QuoteAgentSettingsFactory returns
 * null for a disabled channel and throws for a misconfigured one, so an
 * instance of this class always means "enabled and valid" and neither
 * `$policy` nor `$llm` is nullable — #18 cannot be handed settings it must
 * re-check. `$llm` was nullable while rules-only mode existed, and the three
 * `$access === null` guards that bought were unreachable even then: the
 * factory refuses a blank API key before it ever builds one of these.
 *
 * No Shopware in it. The reader touches SystemConfigService; this does not,
 * so the negotiation engine stays framework-free.
 */
final readonly class QuoteAgentSettings
{
    public function __construct(
        public NegotiationPolicy $policy,
        public ModelAccess $llm,
        public ?string $strategyPrompt,
        /**
         * On unless the merchant turns it off. An escalated quote otherwise
         * sits in `open` with nothing said, which a buyer — and a buyer's
         * agent polling the quote — cannot tell from a shop that has stopped
         * answering at all. See QuoteEscalator for what is written.
         */
        public bool $notifyBuyerOnEscalation = true,
        public ?string $strategyVersionId = null,
    ) {}

    public function withPolicy(NegotiationPolicy $policy): self
    {
        return new self(
            $policy,
            $this->llm,
            $this->strategyPrompt,
            $this->notifyBuyerOnEscalation,
            $this->strategyVersionId,
        );
    }
}
