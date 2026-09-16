<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @mago-expect lint:too-many-methods
 * Every case here is a distinct fact about `reply()`/`send()` — which
 * template rule fires, which transition a state maps to, whether a failed
 * transition is reported loudly — and splitting them into a second test class
 * would scatter one behaviour's coverage across two files for no reader's
 * benefit.
 */
final class ReplyComposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
    }

    private static function composer(\MerchantQuoteAgentPlugin\Negotiation\ModelPlatform $client): ReplyComposer
    {
        return new ReplyComposer(
            $client,
            self::prompts(),
            new NullLogger(),
            new DecisionRecorder(new FakeDecisionWriter()),
        );
    }

    private static function after(float $totalNet = 950.0): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot
    {
        return NegotiationFixture::snapshot(state: 'in_review', totalNet: $totalNet);
    }

    public function testItWritesTheModelsRewordingAndTransitions(): void
    {
        $reworded =
            'We can bring this quote down by 5% to 950.00 EUR, valid until ' . NegotiationFixture::expires() . '.';
        [$client] = ScriptedClient::spy([$reworded]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(strategy: 'formal'),
                5.0,
                SnapshotAdapter::conversation($after),
            );

        self::assertContains('addComment', $gateway->calls);
        self::assertSame($reworded, $gateway->comments[0]);
        self::assertContains('transition', $gateway->calls);
    }

    public function testARewordingThatDropsTheDiscountFallsBackToTheTemplate(): void
    {
        // The reply prompt's one rule is "keep every fact exactly as given".
        // A reply that lost the number is a hallucination, and it is going to
        // a buyer, so the template wins.
        [$client] = ScriptedClient::spy(['Thanks for your interest! We will be in touch soon.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertStringContainsString('down by 5% to 950.00 EUR', $gateway->comments[0]);
    }

    public function testARewordingThatDropsTheNewTotalFallsBackToTheTemplate(): void
    {
        // The total is a fact the buyer acts on, so the guard covers it too —
        // otherwise the sentence could keep the percentage and invent a total.
        [$client] = ScriptedClient::spy(['We can bring this quote down by 5%, valid until 2026-09-11.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash);
        self::assertStringContainsString('950.00 EUR', $gateway->comments[0]);
    }

    public function testAPerLineConcessionIsAnnouncedAsTheReductionTheDatabaseShows(): void
    {
        // The bug this replaces: a per-line offer carries no `discountPercent`
        // at all, so the reply used to tell the buyer "0% off this quote" on a
        // quote whose line prices had just been cut by 15%.
        //
        // The two 503s are how the template ships verbatim now that rules-only
        // mode is gone: there is no configuration in which the reply model is
        // skipped, only one in which it cannot be reached. One 503 would be
        // retried and the second exhausts that, so the reword falls back.
        [$client, $spy] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
        ]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after(totalNet: 850.0);

        self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(),
                ReplyTemplate::reduction(1000.0, 850.0),
                SnapshotAdapter::conversation($after),
            );

        self::assertSame(2, $spy->calls, 'The model must be tried, and tried only once more.');
        self::assertSame(
            'We can bring this quote down by 15% to 850.00 EUR. The offer is valid until '
            . NegotiationFixture::expires()
            . '.',
            $gateway->comments[0],
        );
    }

    public function testAnAlreadyAnsweredQuoteIsNotAnsweredTwice(): void
    {
        // Idempotency: the agent's reply is already newer than the buyer's ask,
        // so a retry must post nothing and pay for nothing.
        [$client, $spy] = ScriptedClient::spy(['would be a duplicate']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
        $after = NegotiationFixture::snapshot(state: 'replied', comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertSame(0, $spy->calls);
        self::assertNotContains('addComment', $gateway->calls);
    }

    /**
     * `in_review` (and `open`, its default-branch sibling) is the state a
     * successful `process` claim leaves the quote in on both state machines,
     * and `sent` is the transition that reaches `replied` from there.
     */
    public function testInReviewReachesRepliedWithSent(): void
    {
        [$client] = ScriptedClient::spy(['a rewording that keeps none of the facts']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertSame([QuoteTransition::Sent], $gateway->transitions);
    }

    /** Released SwagCommercial (≤6.7.12): the only exit from `reopen` to `replied` is `admin_resend`. */
    public function testReopenReachesRepliedWithAdminResend(): void
    {
        [$client] = ScriptedClient::spy(['a rewording that keeps none of the facts']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'reopen', totalNet: 950.0);

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertSame([QuoteTransition::AdminResend], $gateway->transitions);
    }

    /** Trunk: `change_requested` is the renegotiation state, same shared exit. */
    public function testChangeRequestedReachesRepliedWithAdminResend(): void
    {
        [$client] = ScriptedClient::spy(['a rewording that keeps none of the facts']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'change_requested', totalNet: 950.0);

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertSame([QuoteTransition::AdminResend], $gateway->transitions);
    }

    /**
     * The bug this whole fix exists for: an offer applied, a reply posted,
     * and the transition to `replied` refused. The pass must not go quiet
     * about it — the log has to say so loudly, and the audit record has to
     * carry a mark that this pass did not finish, even though the buyer
     * already has the comment.
     */
    public function testAFailedTransitionToRepliedIsLoudNotSilent(): void
    {
        [$client] = ScriptedClient::spy(['a rewording that keeps none of the facts']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $gateway->transitionThrows = new IllegalTransitionException('reopen', 'replied', ['admin_resend']);
        $logger = new RecordingLogger();
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(state: 'reopen'), NegotiationFixture::context());
        $composer = new ReplyComposer($client, self::prompts(), $logger, $recorder);
        $after = NegotiationFixture::snapshot(state: 'reopen', totalNet: 950.0);

        $composer->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        $errorRecord = null;
        foreach ($logger->records as $record) {
            if ($record['level'] === 'error') {
                $errorRecord = $record;
            }
        }
        self::assertNotNull($errorRecord, 'A stranded quote must log at error, not info.');

        $recorder->finish(null);
        self::assertNotEmpty(
            $writer->drafts[0]->violations,
            'The audit record must show the pass did not reach replied.',
        );
    }
}
