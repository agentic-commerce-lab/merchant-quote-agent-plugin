<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `meta` leaves in every export, with or without free text. So every kind is
 * produced here through its real recording path, and every leaf of its meta
 * is checked to be something that cannot carry a person: a number, a bool,
 * null, or a short machine string. The same obligation ExportFieldCoverageTest
 * puts on decision columns -- a new kind fails here until it has a sample,
 * which forces someone to look at what its meta holds.
 */
final class TraceMetaCoverageTest extends TestCase
{
    /** Machine strings: enums, hashes, hosts, model names, class names, dotted paths. */
    private const MACHINE_STRING = '/^[A-Za-z0-9_.:\\\\\/ -]{0,160}$/';

    public function testEveryKindHasASampleFromItsRealRecordingPath(): void
    {
        $seen = array_unique(array_map(static fn(TraceDraft $t): string => $t->kind->value, self::samples()));
        $all = array_map(static fn(TraceKind $k): string => $k->value, TraceKind::cases());
        sort($seen);
        sort($all);

        self::assertSame($all, $seen, 'A TraceKind has no sample here. Add its real recording path to samples().');
    }

    public function testEveryMetaIsExactlyItsDeclaredKeysAndHoldsOnlyMachineValues(): void
    {
        foreach (self::samples() as $event) {
            $keys = array_values(array_diff(array_keys($event->meta), ['truncated']));
            self::assertSame(
                $event->kind->metaKeys(),
                $keys,
                $event->kind->value . ' meta drifted from its allowlist.',
            );

            // A copy: array_walk_recursive() takes its array by reference, and
            // TraceDraft is readonly.
            $meta = $event->meta;
            array_walk_recursive($meta, static function (mixed $leaf, int|string $key) use ($event): void {
                if (\is_string($leaf)) {
                    self::assertMatchesRegularExpression(
                        self::MACHINE_STRING,
                        $leaf,
                        \sprintf(
                            '%s meta.%s holds text that is not a machine value: "%s"',
                            $event->kind->value,
                            $key,
                            $leaf,
                        ),
                    );

                    return;
                }

                self::assertTrue(
                    $leaf === null || \is_int($leaf) || \is_float($leaf) || \is_bool($leaf),
                    \sprintf('%s meta.%s is not a scalar.', $event->kind->value, $key),
                );
            });
        }
    }

    /** @return list<TraceDraft> */
    private static function samples(): array
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context()); // quote_before

        $recorder->recordDecision(
            new NegotiationDecision(
                Band::Grant,
                QuoteDecision::autoReply(new QuoteAutoReplyDetails(5.0, false, [], 14)),
            ),
            10.0,
        ); // policy_verdict

        // model_call (the reply) and reply_guard: a rewording the guard rejects.
        [$client] = ScriptedClient::spy(['Anna, we will be in touch soon.'], $recorder);
        $after = NegotiationFixture::snapshot(state: 'in_review', totalNet: 950.0);
        (new ReplyComposer($client, new PromptComposer('E', 'N', 'R {{tone}}'), new NullLogger(), $recorder))->reply(
            new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]),
            $after,
            NegotiationFixture::settings(),
            5.0,
            SnapshotAdapter::conversation($after),
        );

        $recorder->recordApplied(new AppliedOffer(true, [], $after, 1000.0), ['updateLineItems']); // quote_after
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return $writer->drafts[0]->trace;
    }
}
