<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * The shape the buyer prompt answers under. `ModelPlatform::object()`
 * generates a JSON schema from this class and maps the model's answer onto
 * it -- the same mechanism `AskInterpreter` uses to read the buyer's own free
 * text on the agent side (src/Negotiation/AskInterpreter.php), just pointed
 * the other way: here the model plays the buyer instead of being read by it.
 *
 * Two fields, so an ordinary promoted constructor is used directly, same as
 * `BuyerMove` right beside it. The parameter-count gate this codebase
 * actually reshapes constructors for only bites past five (see
 * src/Policy/Data/ArrayMapper.php); there is no private-constructor-plus-array
 * precedent for a DTO this size anywhere under src/.
 *
 * `kind` is required and never null, so it needs none of the
 * AdmitNullInEnum handling ResponseFormatFactory applies to nullable enums.
 * `comment` defaults to '' rather than null: whether an empty counter is
 * acceptable is `LlmBuyer`'s decision, not the schema's.
 */
final readonly class BuyerAnswer
{
    public function __construct(
        public BuyerMoveKind $kind,
        public string $comment = '',
    ) {}
}
