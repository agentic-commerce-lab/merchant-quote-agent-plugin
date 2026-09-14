<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/** One party to a session, as a record names them. */
final readonly class RecordParty
{
    public function __construct(
        public string $organizationName,
        public string $did,
        public string $agentId,
        public string $verificationMethod,
        /**
         * The mandate method this party is known to hold, or '' when we have
         * been shown none. No default: a mandate is a claim about a party's
         * authority, and defaulting one is how a record ends up asserting
         * something nobody evidenced. The reference implementation reads the
         * same field off the mandate a party actually presented, and leaves it
         * empty when there was none.
         */
        public string $mandateType,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'did' => $this->did,
            'agent_id' => $this->agentId,
            'verification_method' => $this->verificationMethod,
            'mandate_type' => $this->mandateType,
        ];
    }
}
