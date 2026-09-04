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
        public string $mandateType = 'declared',
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
