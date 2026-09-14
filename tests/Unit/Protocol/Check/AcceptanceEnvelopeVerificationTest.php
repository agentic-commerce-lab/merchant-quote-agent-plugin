<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\AcceptanceView;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

/**
 * A2CN's `acceptance` is not shaped like the acts around it: it carries no
 * `protocol_act_hash` and no `protocol_act_signature`, and signs a five-field
 * acceptance payload into `acceptance_signature` instead. These tests pin the
 * second envelope end to end, because the reference client cannot close a
 * negotiation with this shop without it.
 *
 * @mago-expect lint:too-many-methods
 * Nine cases plus four private helpers. Five cover what must verify and what
 * must not (a correct signature, an edited accepted hash, an edited offer id,
 * a signature over the wrong object, a foreign key), two cover which messages
 * may use the envelope at all, and two pin the shape itself. Each is an
 * independent property of a second signing path, and a signing path is the
 * last place to trade coverage for a method count.
 */
final class AcceptanceEnvelopeVerificationTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';

    public function testAnAcceptanceSignedOverItsFiveFieldsVerifies(): void
    {
        ['private' => $private, 'public' => $public] = ProtocolFixtures::keyPair();

        self::assertNull(self::verifier($public)->reasonItDoesNotVerify(self::signedAcceptance($private)));
    }

    public function testEditingTheAcceptedHashAfterSigningIsCaught(): void
    {
        ['private' => $private, 'public' => $public] = ProtocolFixtures::keyPair();
        $raw = self::signedAcceptance($private)->raw();
        $raw['accepted_protocol_act_hash'] = 'a-different-offer-entirely-0000000000000000';

        self::assertNotNull(self::verifier($public)->reasonItDoesNotVerify(self::act($raw)));
    }

    public function testEditingTheOfferItNamesAfterSigningIsCaught(): void
    {
        ['private' => $private, 'public' => $public] = ProtocolFixtures::keyPair();
        $raw = self::signedAcceptance($private)->raw();
        $raw['accepted_offer_id'] = 'some-other-offer';

        self::assertNotNull(self::verifier($public)->reasonItDoesNotVerify(self::act($raw)));
    }

    public function testAnAcceptanceSignedOverTheWrongObjectIsRefused(): void
    {
        // Signing the nine-field protocol act object and posting it in the
        // acceptance envelope: a real signature over the wrong thing.
        ['private' => $private, 'public' => $public] = ProtocolFixtures::keyPair();
        $act = self::signedAcceptance($private);
        $raw = $act->raw();
        $raw['acceptance_signature'] = CompactJws::sign(self::hash()->of(SignedView::of($act)), $private);

        self::assertNotNull(self::verifier($public)->reasonItDoesNotVerify(self::act($raw)));
    }

    public function testAnAcceptanceIsRefusedByAKeyItsSenderDoesNotPublish(): void
    {
        ['private' => $private] = ProtocolFixtures::keyPair();
        ['public' => $other] = ProtocolFixtures::keyPair();

        self::assertNotNull(self::verifier($other)->reasonItDoesNotVerify(self::signedAcceptance($private)));
    }

    public function testOnlyAnAcceptanceMayUseTheAcceptanceEnvelope(): void
    {
        // Otherwise any act could drop its protocol act signature, claim the
        // acceptance envelope's fields, and be parsed without ever binding a
        // signature to its own terms.
        $raw = self::unsignedAcceptance();
        $raw['message_type'] = 'offer';
        $raw['accepted_protocol_act_hash'] = 'x';
        $raw['acceptance_signature'] = 'y';

        self::assertNull(Act::fromArray($raw));
    }

    public function testAnActWithNeitherProofShapeIsRefused(): void
    {
        self::assertNull(Act::fromArray(self::unsignedAcceptance()));
    }

    public function testTheEnvelopeCarriesNoDigestOfItself(): void
    {
        // `accepted_protocol_act_hash` is the OFFER's digest. Reading it as
        // this act's own would put the offer's hash into the chain twice.
        ['private' => $private] = ProtocolFixtures::keyPair();

        self::assertSame('', self::signedAcceptance($private)->hash());
    }

    public function testTheFiveSignedFieldsAreExactlyTheSpecs(): void
    {
        ['private' => $private] = ProtocolFixtures::keyPair();

        self::assertSame(
            [
                'session_id',
                'round_number',
                'sequence_number',
                'accepted_offer_id',
                'accepted_protocol_act_hash',
            ],
            array_keys(AcceptanceView::of(self::signedAcceptance($private))),
        );
    }

    private static function signedAcceptance(string $privateKeyPem): Act
    {
        $raw = self::unsignedAcceptance();
        $unsigned = self::act(
            $raw + ['accepted_protocol_act_hash' => self::ACCEPTED_HASH, 'acceptance_signature' => ''],
        );

        $raw['accepted_protocol_act_hash'] = self::ACCEPTED_HASH;
        $raw['acceptance_signature'] = CompactJws::sign(
            self::hash()->of(AcceptanceView::of($unsigned)),
            $privateKeyPem,
        );

        return self::act($raw);
    }

    private const ACCEPTED_HASH = 'BFpsbtHS4QeJdN18cv0E4o4Nerwj5eB49FkR6VkuAjw';

    /** @return array<string, mixed> */
    private static function unsignedAcceptance(): array
    {
        // The shape A2CN's own client puts on the wire: no protocol_act_hash,
        // no protocol_act_signature, no terms.
        return [
            'message_type' => 'acceptance',
            'message_id' => self::SESSION . ':3',
            'session_id' => self::SESSION,
            'in_reply_to' => self::SESSION . ':2',
            'round_number' => 2,
            'sequence_number' => 3,
            'accepted_offer_id' => self::SESSION . ':2',
            'sender_did' => ProtocolFixtures::BUYER,
            'sender_agent_id' => 'buyer-agent',
            'sender_verification_method' => ProtocolFixtures::BUYER . '#key-1',
            'timestamp' => '2026-09-04T09:05:00Z',
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function act(array $raw): Act
    {
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }

    private static function hash(): ProtocolHash
    {
        return new ProtocolHash(new DefaultJsonCanonicalization());
    }

    private static function verifier(?string $publicKeyPem): ActVerifier
    {
        return new ActVerifier(ProtocolFixtures::resolvingTo($publicKeyPem), self::hash());
    }
}
