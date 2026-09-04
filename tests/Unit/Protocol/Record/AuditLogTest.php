<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditEvidence;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditLog;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParty;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class AuditLogTest extends TestCase
{
    private const SESSION = '57d88e14-5e38-5b75-a94e-1b46206f6215';

    public function testItLogsEveryActAndTheOutcome(): void
    {
        $log = self::build(new AuditEvidence([], []));

        self::assertSame('a2cn_audit_log', $log['log_type']);
        self::assertSame('TIMED_OUT', $log['session_outcome']);
        self::assertCount(2, $log['negotiation_log']);
        self::assertSame(760000, $log['negotiation_log'][0]['total_value_offered']);
    }

    public function testItReportsTheViolationsItActuallyRecorded(): void
    {
        $log = self::build(new AuditEvidence([
            new ProtocolViolation('2026-09-04T10:00:00+00:00', 'duplicate_sequence', null, 'sequence 1 twice'),
        ], []));

        self::assertCount(1, $log['protocol_violations']);
        self::assertSame('duplicate_sequence', $log['protocol_violations'][0]['violation_type']);
    }

    public function testHumanOversightFollowsTheReceipts(): void
    {
        $without = self::build(new AuditEvidence([], []));
        self::assertFalse($without['audit_metadata']['human_oversight_present']);
        self::assertTrue($without['audit_metadata']['autonomous_decision']);
        self::assertTrue($without['audit_metadata']['ai_system_involved']);

        $with = self::build(
            new AuditEvidence([], [new ApprovalReceipt('id', 'hash', 'reason', '2026-09-04T10:00:00+00:00')]),
        );
        self::assertTrue($with['audit_metadata']['human_oversight_present']);
        self::assertFalse($with['audit_metadata']['autonomous_decision']);
        self::assertCount(1, $with['audit_metadata']['human_approval_receipts']);
    }

    /**
     * A chain with no acts at all: an escalated-then-declined session before
     * any emission reached the mirror, say. OfferSelection, SessionTimeline
     * and NegotiationLog all read `$acts[0]` / `$acts[count-1]` through
     * `?->`/`??`, and this is what proves that null-dereference never
     * happens rather than merely reading the code and trusting it.
     */
    public function testAnEmptyChainDegradesWithoutANullDereference(): void
    {
        $log = self::build(new AuditEvidence([], []), []);

        self::assertSame('a2cn_audit_log', $log['log_type']);
        self::assertSame([], $log['negotiation_log']);
        self::assertSame(0, $log['session_timeline']['total_duration_seconds']);
        self::assertGreaterThanOrEqual(0, $log['session_timeline']['total_duration_seconds']);
        self::assertNull($log['session_timeline']['first_offer_at']);
    }

    /**
     * One act only: no acceptance, no counteroffer to measure a round trip
     * against. The duration is still non-negative — a single timestamp
     * measured against itself/generatedAt, never a negative span.
     */
    public function testASingleActDegradesWithoutANegativeDuration(): void
    {
        $acts = [self::act(ProtocolFixtures::buyerAct(1, self::SESSION))];

        $log = self::build(new AuditEvidence([], []), $acts);

        self::assertCount(1, $log['negotiation_log']);
        self::assertGreaterThanOrEqual(0, $log['session_timeline']['total_duration_seconds']);
    }

    /**
     * @param list<Act>|null $acts
     *
     * @return array<string, mixed>
     */
    private static function build(AuditEvidence $evidence, ?array $acts = null): array
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $acts ??= [
            self::act(ProtocolFixtures::buyerAct(1, self::SESSION)),
            self::act(ProtocolFixtures::sellerAct(2, self::SESSION)),
        ];

        return (new AuditLog(new OfferChainHash($hash)))->build(
            new RecordParties(
                new RecordParty('', ProtocolFixtures::BUYER, 'buyer-agent', ProtocolFixtures::BUYER . '#key-1'),
                new RecordParty(
                    'Example Shop',
                    ProtocolFixtures::SELLER,
                    'merchant-quote-agent',
                    ProtocolFixtures::SELLER . '#key-1',
                ),
            ),
            $acts,
            'TIMED_OUT',
            $evidence,
            '2026-09-04T10:00:00+00:00',
        );
    }

    /** @param array<string, mixed> $raw */
    private static function act(array $raw): Act
    {
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }
}
