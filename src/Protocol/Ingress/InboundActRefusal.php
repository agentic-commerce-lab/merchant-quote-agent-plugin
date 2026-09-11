<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

/**
 * One reason an inbound act was not accepted, as an HTTP status and the wire
 * code the buyer reads.
 *
 * Deliberately NOT a ProtocolViolation. A violation is evidence about a chain
 * we hold; this is an answer about a request we refused. Nothing here is
 * persisted: anyone who holds a session id can produce these at will, and a
 * store of them would be an audit log a stranger can write to.
 */
final readonly class InboundActRefusal
{
    public function __construct(
        public int $status,
        public string $code,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['status' => $this->code];
    }
}
