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

    public function testItOmitsOptionalFieldsRatherThanNullingThem(): void
    {
        $raw = self::act();
        unset($raw['round_number'], $raw['expires_at'], $raw['terms']);
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        $view = SignedView::of($act);

        // `{a:1}` and `{a:1,b:null}` canonicalize differently, so an absent
        // field must be absent, not null.
        self::assertArrayNotHasKey('round_number', $view);
        self::assertArrayNotHasKey('expires_at', $view);
        self::assertArrayNotHasKey('terms', $view);
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
