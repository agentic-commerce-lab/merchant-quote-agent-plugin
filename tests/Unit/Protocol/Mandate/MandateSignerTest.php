<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class MandateSignerTest extends TestCase
{
    public function testItAttachesADetachedProofAThirdPartyCanVerify(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
        $mandate = ['mandate_type' => 'declared', 'agent_id' => 'merchant-quote-agent'];

        $signed = (new MandateSigner($hash, TestActSigner::keyStore()))->sign(
            $mandate,
            $identity,
            new \DateTimeImmutable('2026-09-04T10:00:00+00:00'),
        );

        self::assertSame('JsonWebSignature2020', $signed['proof']['type']);
        self::assertSame($identity->verificationMethod, $signed['proof']['verification_method']);
        self::assertSame('2026-09-04T10:00:00Z', $signed['proof']['created']);

        // Detached: the signature covers the mandate body with no `proof`
        // member — exactly what a verifier reconstructs by removing it.
        $withoutProof = $signed;
        unset($withoutProof['proof']);
        $verified = CompactJws::verify($signed['proof']['jws'], TestActSigner::publicKeyPem());

        self::assertSame($hash->of($withoutProof), $verified);
    }

    public function testFlippingOneByteOfTheMandateBodyFailsVerification(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');
        $mandate = ['mandate_type' => 'declared', 'agent_id' => 'merchant-quote-agent'];

        $signed = (new MandateSigner($hash, TestActSigner::keyStore()))->sign(
            $mandate,
            $identity,
            new \DateTimeImmutable('2026-09-04T10:00:00+00:00'),
        );

        $tampered = $signed;
        $tampered['agent_id'] = 'a-different-agent';
        unset($tampered['proof']);

        $verified = CompactJws::verify($signed['proof']['jws'], TestActSigner::publicKeyPem());

        self::assertNotSame($hash->of($tampered), $verified);
    }
}
