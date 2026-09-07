<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * Whether the negotiate prompt answered the buyer or handed the quote to a
 * human. `Escalate` is a first-class answer, not a failure: the model is
 * allowed to say it cannot serve this buyer.
 *
 * A backed enum rather than a string, because that is what puts `"offer"` and
 * `"escalate"` into the generated JSON schema as the only permitted values —
 * the gate that NegotiateResponse::read() used to hand-write.
 */
enum NegotiationAction: string
{
    case Offer = 'offer';

    case Escalate = 'escalate';
}
