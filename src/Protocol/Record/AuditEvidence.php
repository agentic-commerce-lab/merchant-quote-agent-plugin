<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;

/**
 * The evidence only we hold: what we observed and what a human approved.
 *
 * Read from the mirror, never hardcoded — an audit log that always claims zero
 * violations and no human oversight would misrepresent every session it
 * describes.
 */
final readonly class AuditEvidence
{
    /**
     * @param list<ProtocolViolation> $violations
     * @param list<ApprovalReceipt> $receipts
     */
    public function __construct(
        public array $violations,
        public array $receipts,
    ) {}
}
