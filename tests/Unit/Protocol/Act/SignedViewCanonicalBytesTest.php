<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

/**
 * A regression guard, not an interop fixture: the act below is this
 * plugin's own, built purely to exercise SignedView::of() end to end
 * through the real canonicalizer and hasher — it is not the counterparty's
 * signed golden vector, and neither the kit's verifier nor its reference
 * library appears here or anywhere else in this repository.
 *
 * The shape SignedView::of() produces (which nine fields, `null` where
 * absent, `protocol_version` "0.2") was validated byte-identical against the
 * counterparty's reference implementation on 2026-09-05: their verifier
 * accepted an act emitted through this plugin's real SellerActFactory /
 * ActSigner path with no schema, hash or signature failures. This test does
 * not re-run that verification — it pins the two byte-for-byte outputs
 * (canonical JSON, then its digest) of a fixture ONLY this repository knows
 * about, so a later change that alters field set, ordering, or the
 * absent-vs-null rule fails this suite immediately, long before it would
 * ever reach a counterparty's verifier again.
 */
final class SignedViewCanonicalBytesTest extends TestCase
{
    public function testItPinsTheCanonicalBytesAndHashOfAFixedAct(): void
    {
        $act = Act::fromArray(self::act());
        self::assertNotNull($act);
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        $view = SignedView::of($act);
        $canonical = $hash->canonical($view);

        self::assertSame(
            '{"expires_at":"2026-09-19T10:00:00Z","message_type":"offer","protocol_version":"0.2",'
            . '"round_number":1,"sender_did":"did:web:shop.example","sequence_number":1,'
            . '"session_id":"a2cn-regression-guard","terms":{"currency":"EUR","custom_terms":'
            . '{"quote_number":"Q-REGRESSION","tax_status":"net"},"line_items":[{"description":'
            . '"Regression Guard Widget","id":"line-1","quantity":1,"total":100000,"unit":"piece",'
            . '"unit_price":100000}],"total_value":100000},"timestamp":"2026-09-05T10:00:00Z"}',
            $canonical,
        );
        self::assertSame('o-6k31KtwomBBdoLy5KNyxTfnXfehEO6el1qTdxWJlU', $hash->of($view));
    }

    /** @return array<string, mixed> */
    private static function act(): array
    {
        return [
            'message_type' => 'offer',
            'message_id' => 'a2cn-regression-guard:1',
            'session_id' => 'a2cn-regression-guard',
            'round_number' => 1,
            'sequence_number' => 1,
            'sender_did' => 'did:web:shop.example',
            'sender_agent_id' => 'merchant-quote-agent',
            'sender_verification_method' => 'did:web:shop.example#key-1',
            'timestamp' => '2026-09-05T10:00:00Z',
            'expires_at' => '2026-09-19T10:00:00Z',
            'terms' => [
                'total_value' => 100000,
                'currency' => 'EUR',
                'line_items' => [[
                    'id' => 'line-1',
                    'description' => 'Regression Guard Widget',
                    'quantity' => 1,
                    'unit' => 'piece',
                    'unit_price' => 100000,
                    'total' => 100000,
                ]],
                'custom_terms' => ['tax_status' => 'net', 'quote_number' => 'Q-REGRESSION'],
            ],
            'protocol_act_hash' => 'placeholder',
            'protocol_act_signature' => 'placeholder',
        ];
    }
}
