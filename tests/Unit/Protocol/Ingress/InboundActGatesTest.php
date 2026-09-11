<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActConformance;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActEligibility;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActEnvelope;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRefusal;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

/**
 * Three gate classes, one file (matches the brief's split: envelope binding,
 * session eligibility, act conformance). Every case within a gate is folded
 * into a single #[DataProvider] test, the same technique CompactJwsTest uses,
 * which is what keeps the total at the too-many-methods ceiling instead of
 * well past it.
 */
final class InboundActGatesTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    /** @return iterable<string, array{0: Act, 1: string, 2: string, 3: ?InboundActRefusal}> */
    public static function envelopeCases(): iterable
    {
        yield 'a bound act is accepted' => [self::buyerAct(1), self::session(), ProtocolFixtures::BUYER, null];
        yield 'a session id that is not the path is refused' => [
            self::buyerAct(1),
            'some-other-session',
            ProtocolFixtures::BUYER,
            new InboundActRefusal(400, 'session_id_mismatch'),
        ];
        yield 'a sender that is not the token issuer is refused' => [
            self::buyerAct(1),
            self::session(),
            'did:web:someone.else',
            new InboundActRefusal(401, 'sender_did_mismatch'),
        ];
    }

    #[DataProvider('envelopeCases')]
    public function testEnvelopeDecisions(
        Act $act,
        string $sessionId,
        string $issuerDid,
        ?InboundActRefusal $expected,
    ): void {
        self::assertEquals($expected, InboundActEnvelope::refusal($act, $sessionId, $issuerDid));
    }

    /** @return iterable<string, array{0: Act, 1: ActChain, 2: QuoteTerminalState, 3: string, 4: ?InboundActRefusal}> */
    public static function eligibilityCases(): iterable
    {
        $session = self::session();
        $emptyChain = ActChain::read([ActKey::SESSION_KEY => $session]);
        $pinnedChain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);
        $closed = new InboundActRefusal(409, 'session_closed');
        $notAParty = new InboundActRefusal(403, 'sender_did_not_party');

        yield 'a first act on a live quote is accepted' => [
            self::buyerAct(1),
            $emptyChain,
            self::quote(),
            ProtocolFixtures::SELLER,
            null,
        ];
        yield 'an accepted-state quote is refused' => [
            self::buyerAct(1),
            $emptyChain,
            self::quote(state: 'accepted'),
            ProtocolFixtures::SELLER,
            $closed,
        ];
        yield 'an expired quote is refused' => [
            self::buyerAct(1),
            $emptyChain,
            self::quote(expired: true),
            ProtocolFixtures::SELLER,
            $closed,
        ];
        yield 'a quote carrying an acceptance act is refused' => [
            self::buyerAct(1),
            $emptyChain,
            self::quote(acceptance: self::buyerAct(1, 'accept')),
            ProtocolFixtures::SELLER,
            $closed,
        ];
        // Same session key, ActChain::MAX_ACTS buyer acts already on it — the
        // chain-cap refusal (ruling 2) must fire before the pinned-party
        // check does, on a chain that never mentions a stranger.
        $fullCustomFields = [ActKey::SESSION_KEY => $session];
        for ($sequence = 1; $sequence <= ActChain::MAX_ACTS; $sequence++) {
            $fullCustomFields[ActKey::for($sequence, ActRole::Buyer)] = ProtocolFixtures::buyerAct($sequence, $session);
        }
        yield 'a full chain is refused' => [
            self::buyerAct(999),
            ActChain::read($fullCustomFields),
            self::quote(),
            ProtocolFixtures::SELLER,
            new InboundActRefusal(409, 'chain_length_exceeded'),
        ];
        $sellerAct = Act::fromArray(ProtocolFixtures::sellerAct(1, $session));
        self::assertNotNull($sellerAct);
        yield 'our own seller did is refused' => [
            $sellerAct,
            $emptyChain,
            self::quote(),
            ProtocolFixtures::SELLER,
            $notAParty,
        ];
        $stranger = Act::fromArray(ProtocolFixtures::act(2, $session, 'did:web:stranger.example', 'counteroffer'));
        self::assertNotNull($stranger);
        yield 'a third party once the chain has pinned the buyer is refused' => [
            $stranger,
            $pinnedChain,
            self::quote(),
            ProtocolFixtures::SELLER,
            $notAParty,
        ];
    }

    #[DataProvider('eligibilityCases')]
    public function testEligibilityDecisions(
        Act $act,
        ActChain $chain,
        QuoteTerminalState $quote,
        string $sellerDid,
        ?InboundActRefusal $expected,
    ): void {
        self::assertEquals($expected, InboundActEligibility::refusal($act, $chain, $quote, $sellerDid));
    }

    /** @return iterable<string, array{0: Act, 1: ActChain, 2: ActVerifier, 3: ?InboundActRefusal}> */
    public static function conformanceCases(): iterable
    {
        $session = self::session();
        $priorActChain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);
        $failingVerifier = new ActVerifier(
            ProtocolFixtures::resolvingTo(null),
            new ProtocolHash(new DefaultJsonCanonicalization()),
        );

        yield 'a +00:00 timestamp is refused' => [
            self::buyerAct(2, overrides: ['timestamp' => '2026-09-04T09:00:00+00:00']),
            $priorActChain,
            $failingVerifier,
            new InboundActRefusal(400, 'timestamp_format_invalid'),
        ];
        // Shape-valid (matches PATTERN's digit counts) but not a real
        // instant. Before ProtocolTimestamp::matches() round-tripped through
        // strtotime()/gmdate(), this rolled silently to March 2nd and both
        // ordering checks skipped the act instead of refusing it (#113).
        yield 'a shape-valid but unreal timestamp is refused' => [
            self::buyerAct(2, overrides: ['timestamp' => '2026-02-30T00:00:00Z']),
            $priorActChain,
            $failingVerifier,
            new InboundActRefusal(400, 'timestamp_format_invalid'),
        ];
        yield 'a timestamp before the previous act is refused' => [
            self::buyerAct(2, overrides: ['timestamp' => '2026-09-04T08:00:00Z']),
            $priorActChain,
            $failingVerifier,
            new InboundActRefusal(409, 'timestamp_inversion'),
        ];
        yield 'sequence 5 on a chain of 1 is refused' => [
            self::buyerAct(5),
            $priorActChain,
            $failingVerifier,
            new InboundActRefusal(409, 'sequence_conflict'),
        ];
        yield 'a signature that does not verify is refused' => [
            self::buyerAct(2),
            $priorActChain,
            $failingVerifier,
            new InboundActRefusal(403, 'act_unverified'),
        ];
        ['private' => $private, 'public' => $public] = ProtocolFixtures::keyPair();
        yield 'a well-formed, verified act is accepted' => [
            self::signedAct(1, $private),
            ActChain::read([ActKey::SESSION_KEY => $session]),
            new ActVerifier(
                ProtocolFixtures::resolvingTo($public),
                new ProtocolHash(new DefaultJsonCanonicalization()),
            ),
            null,
        ];
    }

    #[DataProvider('conformanceCases')]
    public function testConformanceDecisions(
        Act $act,
        ActChain $chain,
        ActVerifier $verifier,
        ?InboundActRefusal $expected,
    ): void {
        self::assertEquals($expected, (new InboundActConformance($verifier))->refusal($act, $chain));
    }

    private static function session(): string
    {
        return SessionId::forQuote(self::QUOTE_ID);
    }

    /** @param array<string, mixed> $overrides */
    private static function buyerAct(int $sequence, string $type = 'offer', array $overrides = []): Act
    {
        $act = Act::fromArray([...ProtocolFixtures::buyerAct($sequence, self::session(), $type), ...$overrides]);
        self::assertNotNull($act);

        return $act;
    }

    private static function signedAct(int $sequence, string $privateKeyPem): Act
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $raw = ProtocolFixtures::buyerAct($sequence, self::session());
        unset($raw['protocol_act_hash'], $raw['protocol_act_signature']);
        $parsed = Act::fromArray($raw + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        self::assertNotNull($parsed);
        $digest = $hash->of(SignedView::of($parsed));

        $raw['protocol_act_hash'] = $digest;
        $raw['protocol_act_signature'] = CompactJws::sign($digest, $privateKeyPem);

        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }

    private static function quote(
        string $state = 'replied',
        bool $expired = false,
        ?Act $acceptance = null,
    ): QuoteTerminalState {
        return new QuoteTerminalState(
            state: $state,
            expired: $expired,
            quoteNumber: 'Q-1001',
            salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
            acceptance: $acceptance,
        );
    }
}
