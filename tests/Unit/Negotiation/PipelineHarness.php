<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryFactoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/** A fully wired pipeline over a scripted model and a fake gateway. */
final class PipelineHarness
{
    /**
     * What `with()` serves as the post-apply re-read, so a default pass comes
     * down from `NegotiationFixture::DEFAULT_TOTAL_NET` by exactly 5%. Named
     * because `rewordedReply()` below has to state that same figure.
     */
    public const AFTER_NET = 950.0;

    public OfferRound $round;

    public ?QuoteAgentSettingsSource $settingsSource = null;

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
        float $beforeNet = NegotiationFixture::DEFAULT_TOTAL_NET,
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
        float $reReadTotalNet = self::AFTER_NET,
        ?CustomerHistoryFactoryInterface $historyFactory = null,
    ): self {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        [$client, $spy] = ScriptedClient::spy($replies, $recorder);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
        $logger = new RecordingLogger();
        $settingsSource = new class implements QuoteAgentSettingsSource {
            public ?QuoteAgentSettings $settings = null;

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return $this->settings;
            }
        };
        $escalator = new QuoteEscalator(settingsSource: $settingsSource);

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
        $harness->settingsSource = $settingsSource;
        $harness->before = NegotiationFixture::snapshot();

        return $harness;
    }

    /**
     * A rewording `RewordingGuard` accepts: exactly the three facts
     * `ReplyTemplate` wrote for this harness's totals, and no fourth figure.
     *
     * A scripted reply is the only thing standing between these tests and the
     * reword path, and the guard's verdict is invisible from outside. A
     * rejected rewording is not an error — it is the deterministic template
     * arriving instead, with the outcome, the model-call count and every
     * gateway write unchanged. That is how five test files spent months
     * exercising the fallback while their names said otherwise (#141).
     *
     * So the figures are computed by the production formatters rather than
     * typed out. `percent()`, `money()` and `reduction()` are the very
     * functions `RewordingGuard` compares a rewording against, and
     * `NegotiationFixture::expires()` is the expiry the fixture quote actually
     * carries — clock-relative since #57 — so this string cannot drift away
     * from the template the way a hardcoded `2026-09-11` did.
     *
     * Deliberately NOT `ReplyTemplate::compose()`'s own sentence. A scripted
     * reply identical to the fallback ships either way, so an assertion on it
     * could not tell the reword path from the template path — which is the
     * defect, not the fix. This one differs in its final clause and is
     * accepted by the guard, which `RewordingGuardTest` pins for exactly this
     * shape.
     */
    public static function rewordedReply(
        float $afterNet = self::AFTER_NET,
        float $beforeNet = NegotiationFixture::DEFAULT_TOTAL_NET,
        string $currencyIso = 'EUR',
    ): string {
        return sprintf(
            'We can bring this quote down by %s%% to %s %s, valid until %s.',
            ReplyTemplate::percent(ReplyTemplate::reduction($beforeNet, $afterNet)),
            ReplyTemplate::money($afterNet),
            $currencyIso,
            NegotiationFixture::expires(),
        );
    }
}
