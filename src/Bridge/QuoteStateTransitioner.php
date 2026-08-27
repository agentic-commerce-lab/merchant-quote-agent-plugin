<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * SwagCommercial's QuoteState::transition() is @internal and is a
 * License::check plus exactly this call plus a null check, so we call Shopware
 * core directly and keep the @internal list at two. The license check the
 * wrapper would have done is covered by CommercialAvailability at the factory.
 *
 * A transition is not a quiet field write: StateMachineRegistry dispatches
 * StateMachineTransitionEvent / StateMachineStateChangeEvent afterwards, which
 * on this shop drives active flows carrying `action.mail.send` for both
 * `in_review` and `replied`. Callers should treat it as an outward-facing
 * effect. See TransitionTest for the two layers that keep that harmless in a
 * test run.
 */
final readonly class QuoteStateTransitioner
{
    /** Mirrors QuoteDefinition::ENTITY_NAME. */
    private const QUOTE_ENTITY = 'quote';

    private const STATE_FIELD = 'stateId';

    public function __construct(
        private StateMachineRegistry $stateMachineRegistry,
    ) {}

    /**
     * @throws IllegalTransitionException when the quote's current state has no
     *                                    such action; the quote is left untouched
     */
    public function transition(string $quoteId, QuoteTransition $action, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(self::QUOTE_ENTITY, $quoteId, $action->value, self::STATE_FIELD),
            $context,
        );
    }
}
