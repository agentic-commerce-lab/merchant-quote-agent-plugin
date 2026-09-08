<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/** The `negotiation_log` block of the audit log: one row per act, in chain order. */
final class NegotiationLog
{
    private function __construct() {}

    /**
     * @param list<Act> $acts
     *
     * @return list<array<string, mixed>>
     */
    public static function of(array $acts): array
    {
        return array_map(self::entry(...), $acts);
    }

    /** @return array<string, mixed> */
    private static function entry(Act $act): array
    {
        $total = $act->terms()['total_value'] ?? null;

        return [
            'sequence_number' => $act->sequenceNumber(),
            'message_type' => $act->messageType(),
            'message_id' => $act->messageId(),
            'sender_did' => $act->senderDid(),
            'timestamp' => $act->timestamp(),
            'round_number' => $act->roundNumber(),
            'total_value_offered' => \is_int($total) ? $total : null,
            'protocol_act_hash' => $act->hash(),
        ];
    }
}
