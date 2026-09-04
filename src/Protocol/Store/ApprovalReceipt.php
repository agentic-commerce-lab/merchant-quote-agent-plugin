<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

/**
 * A human approval receipt (spec section 10): what records that a person stood
 * behind terms the agent itself would have escalated.
 *
 * The signature on the act attests the ORGANISATION, never an individual — so
 * this receipt carries a threshold and a time, not a name.
 */
final readonly class ApprovalReceipt
{
    public function __construct(
        public string $receiptId,
        public string $offerHash,
        public string $thresholdCrossed,
        public string $approvedAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'approval_receipt_id' => $this->receiptId,
            'offer_hash' => $this->offerHash,
            'threshold_crossed' => $this->thresholdCrossed,
            'approved_at' => $this->approvedAt,
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $id = $row['approval_receipt_id'] ?? null;
        $hash = $row['offer_hash'] ?? null;
        $threshold = $row['threshold_crossed'] ?? null;
        $approvedAt = $row['approved_at'] ?? null;

        if (!\is_string($id) || !\is_string($hash) || !\is_string($threshold) || !\is_string($approvedAt)) {
            return null;
        }

        return new self($id, $hash, $threshold, $approvedAt);
    }
}
