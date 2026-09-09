<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryFactoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/** A fully wired pipeline over a scripted model and a fake gateway. */
final class PipelineHarness
{
    public OfferRound $round;

    private function __construct(
        public NegotiationPipeline $pipeline,
        public FakeQuoteGateway $gateway,
        public ScriptedClient $spy,
        public RecordingLogger $logger,
        public FakeDecisionWriter $writer,
    ) {}

    /** The snapshot to hand to service(), when a test needs a specific opening total. */
    public QuoteSnapshot $before;

    /**
     * A pipeline whose before/after totals are stated outright, including the
     * gross ones — what the buyer is told is measured on these.
     *
     * @param list<string> $replies in call order: extract, negotiate, reply
     */
    public static function withTotals(
        array $replies,
        float $afterNet,
        float $afterGross,
        float $beforeNet = 1000.0,
        ?float $baselineNet = null,
    ): self {
        $harness = self::with($replies, reReadTotalNet: $afterNet);
        // A buyer comment, so the pass has an ask to answer and reaches the
        // reply at all: an empty conversation is NothingToDo.
        $before = NegotiationFixture::snapshot(totalNet: $beforeNet, comments: [
            NegotiationFixture::buyerComment('what can you do on price?', '2026-08-28 09:00:00'),
        ]);

        if ($baselineNet !== null) {
            $before = NegotiationFixture::withCustomFields($before, NegotiationFixture::baselineOf(
                $baselineNet,
                $baselineNet / 10,
            ));
        }

        $harness->before = $before;
        $harness->gateway->replaceSnapshots([
            NegotiationFixture::snapshot(state: 'in_review', totalNet: $beforeNet),
            self::taxed(NegotiationFixture::snapshot(state: 'in_review', totalNet: $afterNet), $afterGross),
        ]);

        return $harness;
    }

    /**
     * The same snapshot with a gross total that differs from its net one — a
     * taxed quote. Here rather than on NegotiationFixture, which is at its
     * method-count gate, and this is its only caller.
     */
    private static function taxed(QuoteSnapshot $snapshot, float $totalGross): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: new QuoteTotals(totalNet: $snapshot->totals->totalNet, totalGross: $totalGross),
            lifecycle: $snapshot->lifecycle,
            content: $snapshot->content,
        );
    }

    /** @param list<string> $replies in call order: extract, negotiate, reply */
    public static function with(
        array $replies,
        float $reReadTotalNet = 950.0,
        ?CustomerHistoryFactoryInterface $historyFactory = null,
    ): self {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        [$client, $spy] = ScriptedClient::spy($replies, $recorder);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
        $logger = new RecordingLogger();
        $escalator = new QuoteEscalator();

        // Two snapshots: the pre-apply read, which still carries the quote as
        // the buyer asked about it, and the post-apply re-read the verifier
        // measures against it. Making them differ is what gives the verifier
        // something to check at all.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(state: 'in_review'),
            NegotiationFixture::snapshot(state: 'in_review', totalNet: $reReadTotalNet),
        ]);

        $round = new OfferRound(
            new OfferProposer(
                $client,
                $prompts,
                new OfferAuthorizer(),
                $recorder,
                $historyFactory ?? new FakeCustomerHistoryFactory(),
            ),
            new OfferApplier(new OfferVerifier(), $logger, $recorder),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            $escalator,
            $logger,
        );

        $pipeline = new NegotiationPipeline(
            new AskInterpreter($client, $prompts, $recorder),
            new NegotiationDecider(),
            $round,
            $recorder,
            $logger,
        );

        $harness = new self($pipeline, $gateway, $spy, $logger, $writer);
        $harness->round = $round;
        $harness->before = NegotiationFixture::snapshot();

        return $harness;
    }
}
