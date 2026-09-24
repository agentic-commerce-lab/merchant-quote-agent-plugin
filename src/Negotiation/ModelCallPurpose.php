<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/** Which of the agent's prompts a model call served, for its trace event. */
enum ModelCallPurpose: string
{
    case Extract = 'extract';
    case Negotiate = 'negotiate';
    case Reply = 'reply';
    case Other = 'other';

    // ponytail: read off the answer type instead of a parameter on every
    // object() call (18 call sites in tests). A new structured call reads
    // `other` until it is added here -- visible in the first export.
    public static function answering(string $type): self
    {
        return match ($type) {
            CommentInterpretation::class => self::Extract,
            NegotiateResponse::class => self::Negotiate,
            default => self::Other,
        };
    }
}
