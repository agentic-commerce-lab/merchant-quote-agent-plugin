<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;

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
 *
 * @mago-expect lint:excessive-parameter-list
 * All seven fields are what one sales channel's configuration resolves to;
 * `$strategyAssignmentSource` travels with `$strategyPrompt` and
 * `$strategyVersionId` because all three describe the same resolved
 * strategy, so splitting them into a sub-object would just move that
 * three-field group one level down without changing what withStrategy()
 * has to keep in sync. `$draftMode` is here rather than behind a raw accessor
 * because the pipeline decorator and the mandate read it alongside the
 * validated policy.
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
        public ?StrategyAssignmentSource $strategyAssignmentSource = null,
        /** Prepare, never send — see Review\DraftModePipeline. */
        public bool $draftMode = false,
    ) {}

    public function withPolicy(NegotiationPolicy $policy): self
    {
        return new self(
            $policy,
            $this->llm,
            $this->strategyPrompt,
            $this->notifyBuyerOnEscalation,
            $this->strategyVersionId,
            $this->strategyAssignmentSource,
            $this->draftMode,
        );
    }

    /**
     * The assignment ladder's answer, replacing whatever the configuration key
     * resolved. Both the prompt and the version id move together: a row that
     * recorded one strategy's version while another strategy's prompt was sent
     * would make the whole per-strategy dashboard lie.
     */
    public function withStrategy(ResolvedStrategy $strategy, StrategyAssignmentSource $source): self
    {
        return new self(
            $this->policy,
            $this->llm,
            $strategy->prompt,
            $this->notifyBuyerOnEscalation,
            $strategy->versionId,
            $source,
            $this->draftMode,
        );
    }

    /**
     * The one field a nightly replay arm changes (Task 11): every candidate
     * arm is the SAME settings as the control, with only the strategy prompt
     * swapped, so the delta between arms is attributable to the prompt alone.
     */
    public function withStrategyPrompt(?string $strategyPrompt): self
    {
        return new self(
            $this->policy,
            $this->llm,
            $strategyPrompt,
            $this->notifyBuyerOnEscalation,
            $this->strategyVersionId,
        );
    }
}
