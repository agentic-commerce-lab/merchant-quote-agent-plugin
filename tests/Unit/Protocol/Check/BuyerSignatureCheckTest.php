<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Check\BuyerSignatureCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class BuyerSignatureCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesForAProperlySignedBuyerAct(): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();
        $chain = self::chainWith(self::signedBuyerAct($private));

        self::assertNull(
            self::check($public)
                ->check(
                    $chain,
                    ProtocolFixtures::snapshot(self::QUOTE_ID),
                    ProtocolFixtures::SELLER,
                    ProtocolFixtures::at(),
                ),
        );
    }

    public function testItReportsAnActWhoseKeyDoesNotResolve(): void
    {
        ['private' => $private] = self::keyPair();
        $chain = self::chainWith(self::signedBuyerAct($private));

        $violation = self::check(null)
            ->check(
                $chain,
                ProtocolFixtures::snapshot(self::QUOTE_ID),
                ProtocolFixtures::SELLER,
                ProtocolFixtures::at(),
            );

        self::assertNotNull($violation);
        self::assertSame('buyer_act_unverified', $violation->violationType);
    }

    public function testItReportsAnActSignedByADifferentKey(): void
    {
        ['private' => $private] = self::keyPair();
        ['public' => $other] = self::keyPair();
        $chain = self::chainWith(self::signedBuyerAct($private));

        self::assertNotNull(
            self::check($other)
                ->check(
                    $chain,
                    ProtocolFixtures::snapshot(self::QUOTE_ID),
                    ProtocolFixtures::SELLER,
                    ProtocolFixtures::at(),
                ),
        );
    }

    public function testItReportsAnActWhoseHashDoesNotCoverIt(): void
    {
        // A valid signature over a hash that is not this act's hash: the
        // signature verifies, the binding does not.
        ['private' => $private, 'public' => $public] = self::keyPair();
        $act = self::signedBuyerAct($private);
        $act['timestamp'] = '2026-01-01T00:00:00Z';

        $violation = self::check($public)
            ->check(
                self::chainWith($act),
                ProtocolFixtures::snapshot(self::QUOTE_ID),
                ProtocolFixtures::SELLER,
                ProtocolFixtures::at(),
            );

        self::assertNotNull($violation);
        self::assertStringContainsString('hash', $violation->description);
    }

    /**
     * `sender_verification_method` naming a DID other than `sender_did`
     * must be refused locally — and, since the resolver would otherwise be
     * asked to fetch that OTHER party's did:web document, refused before
     * any network call. The resolver double here throws if ever invoked, so
     * a passing test proves the network hop never happened rather than
     * merely that the final result was a violation.
     */
    public function testItRefusesAVerificationMethodUnderAForeignDidBeforeAnyHttpCall(): void
    {
        ['private' => $private] = self::keyPair();
        $act = self::signedBuyerAct($private, 'did:web:someone-else.example#key-1');

        $resolver = new class extends DidWebResolver {
            public function __construct() {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                throw new \LogicException(
                    'the network hop must not run once the verification method mismatch is caught locally',
                );
            }
        };

        $violation = (new BuyerSignatureCheck($resolver, new ProtocolHash(new DefaultJsonCanonicalization())))->check(
            self::chainWith($act),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNotNull($violation);
        self::assertSame('buyer_act_unverified', $violation->violationType);
        self::assertStringContainsString('did:web:someone-else.example#key-1', $violation->description);
        self::assertStringContainsString(ProtocolFixtures::BUYER, $violation->description);
    }

    public function testItIgnoresOurOwnActs(): void
    {
        $chain = self::chainWith(ProtocolFixtures::sellerAct(1, SessionId::forQuote(self::QUOTE_ID)), ActRole::Seller);

        self::assertNull(
            self::check(null)
                ->check(
                    $chain,
                    ProtocolFixtures::snapshot(self::QUOTE_ID),
                    ProtocolFixtures::SELLER,
                    ProtocolFixtures::at(),
                ),
        );
    }

    /** @param array<string, mixed> $act */
    private static function chainWith(array $act, ActRole $role = ActRole::Buyer): ActChain
    {
        $session = SessionId::forQuote(self::QUOTE_ID);

        return ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, $role) => $act]);
    }

    /** @return array<string, mixed> */
    private static function signedBuyerAct(string $privateKeyPem, ?string $verificationMethod = null): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $act = ProtocolFixtures::buyerAct(1, SessionId::forQuote(self::QUOTE_ID));
        unset($act['protocol_act_hash'], $act['protocol_act_signature']);
        if ($verificationMethod !== null) {
            $act['sender_verification_method'] = $verificationMethod;
        }

        $parsed = Act::fromArray($act + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        self::assertNotNull($parsed);
        $digest = $hash->of(SignedView::of($parsed));

        $act['protocol_act_hash'] = $digest;
        $act['protocol_act_signature'] = CompactJws::sign($digest, $privateKeyPem);

        return $act;
    }

    private static function check(?string $publicKeyPem): BuyerSignatureCheck
    {
        $resolver = new class($publicKeyPem) extends DidWebResolver {
            public function __construct(
                private readonly ?string $pem,
            ) {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return $this->pem;
            }
        };

        return new BuyerSignatureCheck($resolver, new ProtocolHash(new DefaultJsonCanonicalization()));
    }

    /** @return array{private: string, public: string} */
    private static function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($resource);
        $private = '';
        self::assertTrue(openssl_pkey_export($resource, $private));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $private, 'public' => $details['key']];
    }
}
