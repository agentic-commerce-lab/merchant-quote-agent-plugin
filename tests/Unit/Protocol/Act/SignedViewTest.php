<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use PHPUnit\Framework\TestCase;

final class SignedViewTest extends TestCase
{
    public function testItSignsExactlyTheNormativeFieldSet(): void
    {
        $act = Act::fromArray(self::act());
        self::assertNotNull($act);

        self::assertSame(
            [
                // The interop literal, not our own record version: the
                // counterparty's protocol_act_object() supplies "0.2", and a
                // different value here is a hash nobody can reproduce.
                'protocol_version' => '0.2',
                'session_id' => 'session-1',
                'round_number' => 2,
                'sequence_number' => 3,
                'message_type' => 'counteroffer',
                'sender_did' => 'did:web:shop.example',
                'timestamp' => '2026-09-04T10:00:00Z',
                'expires_at' => '2026-09-18T10:00:00Z',
                'terms' => ['total_value' => 1, 'currency' => 'EUR'],
            ],
            SignedView::of($act),
        );
    }

    /**
     * The counterparty's `protocol_act_object()` returns the nine fields
     * unconditionally, so an act without an expiry is signed as
     * `"expires_at":null` — the one place this module nulls rather than omits.
     * Omitting would diverge the hash from theirs in both directions.
     */
    public function testItNullsAbsentOptionalFieldsRatherThanOmittingThem(): void
    {
        $raw = self::act();
        unset($raw['round_number'], $raw['expires_at'], $raw['terms']);
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        $view = SignedView::of($act);

        self::assertSame(
            [
                'protocol_version' => '0.2',
                'session_id' => 'session-1',
                'round_number' => null,
                'sequence_number' => 3,
                'message_type' => 'counteroffer',
                'sender_did' => 'did:web:shop.example',
                'timestamp' => '2026-09-04T10:00:00Z',
                'expires_at' => null,
                'terms' => null,
            ],
            $view,
        );
    }

    public function testItExcludesEnvelopeAndProofFields(): void
    {
        $act = Act::fromArray(self::act());
        self::assertNotNull($act);

        $view = SignedView::of($act);

        foreach ([
            'message_id',
            'in_reply_to',
            'sender_agent_id',
            'sender_verification_method',
            'protocol_act_hash',
            'protocol_act_signature',
        ] as $field) {
            self::assertArrayNotHasKey($field, $view);
        }
    }

    /** @return array<string, mixed> */
    private static function act(): array
    {
        return [
            'message_type' => 'counteroffer',
            'message_id' => 'session-1:3',
            'in_reply_to' => 'session-1:2',
            'session_id' => 'session-1',
            'round_number' => 2,
            'sequence_number' => 3,
            'sender_did' => 'did:web:shop.example',
            'sender_agent_id' => 'merchant-quote-agent',
            'sender_verification_method' => 'did:web:shop.example#key-1',
            'timestamp' => '2026-09-04T10:00:00Z',
            'expires_at' => '2026-09-18T10:00:00Z',
            'terms' => ['total_value' => 1, 'currency' => 'EUR'],
            'protocol_act_hash' => 'hash',
            'protocol_act_signature' => 'signature',
        ];
    }
}
