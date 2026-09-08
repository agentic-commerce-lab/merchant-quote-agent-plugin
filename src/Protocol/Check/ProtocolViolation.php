<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

/**
 * One evidence problem, as it is persisted and as it appears in the audit log.
 *
 * The shape is the spec's (section 10) and is read by a third party, so the
 * field names are wire names, not PHP names.
 */
final readonly class ProtocolViolation
{
    public function __construct(
        public string $timestamp,
        public string $violationType,
        public ?string $messageId,
        public string $description,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'violation_type' => $this->violationType,
            'message_id' => $this->messageId,
            'description' => $this->description,
        ];
    }

    /**
     * Reads a persisted row back. Returns null rather than throwing: a row we
     * cannot read must not take down a records request for the whole session.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $timestamp = $row['timestamp'] ?? null;
        $type = $row['violation_type'] ?? null;
        $description = $row['description'] ?? null;
        $messageId = $row['message_id'] ?? null;

        if (!\is_string($timestamp) || !\is_string($type) || !\is_string($description)) {
            return null;
        }

        return new self($timestamp, $type, \is_string($messageId) ? $messageId : null, $description);
    }
}
