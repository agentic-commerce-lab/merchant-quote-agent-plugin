<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/** Rekeys the extract prompt's optional `negotiation` block, if present. */
final class NegotiationBlock
{
    private const DELIVERY_KEYS = [
        'free_shipping' => 'freeShipping',
        'expedited' => 'expedited',
        'requested_lead_time_days' => 'requestedLeadTimeDays',
    ];

    private const PAYMENT_KEYS = [
        'requested_term' => 'requestedTerm',
        'requested_net_days' => 'requestedNetDays',
        'requested_deposit_percent' => 'requestedDepositPercent',
    ];

    private const BUNDLE_KEYS = ['requested' => 'requested'];

    private function __construct() {}

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    public static function read(array $raw): ?array
    {
        $negotiation = $raw['negotiation'] ?? null;

        if (!\is_array($negotiation)) {
            return null;
        }

        $fields = WireArray::orEmpty($negotiation);

        return [
            'delivery' => self::section($fields, 'delivery', self::DELIVERY_KEYS),
            'payment' => self::section($fields, 'payment', self::PAYMENT_KEYS),
            'bundle' => self::section($fields, 'bundle', self::BUNDLE_KEYS),
        ];
    }

    /**
     * @param array<string, mixed> $negotiation
     * @param array<string, string> $keyMap
     *
     * @return array<string, mixed>
     */
    private static function section(array $negotiation, string $key, array $keyMap): array
    {
        return RenamedRows::of(WireArray::orEmpty($negotiation[$key] ?? null), $keyMap);
    }
}
