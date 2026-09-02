<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
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

    /** @param list<string> $replies in call order: extract, negotiate, reply */
    public static function with(array $replies, float $reReadTotalNet = 950.0): self
    {
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
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder),
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

        return $harness;
    }
}
