<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
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
        // The date is the live fixture expiry, so the dropped total is the
        // only fact missing — a stale date would reject this for the wrong
        // reason and the test would pass without proving anything.
        [$client] = ScriptedClient::spy([
            'We can bring this quote down by 5%, valid until ' . NegotiationFixture::expires() . '.',
        ]);
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

    /**
     * #175: a null reductionPercent is the hold shape. The template posted
     * must be `ReplyTemplate::holds()`, not `compose()` floored at 0%.
     */
    public function testANullReductionPercentPostsTheHoldTemplate(): void
    {
        [$client] = ScriptedClient::spy(['a rewording that keeps none of the facts']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after(totalNet: 1000.0);

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), null, SnapshotAdapter::conversation($after));

        self::assertSame(
            'This quote stands at 1000.00 EUR. The offer remains valid until ' . NegotiationFixture::expires() . '.',
            $gateway->comments[0],
        );
        self::assertStringNotContainsString('%', $gateway->comments[0]);
    }

    /**
     * QA-08's sibling. A buyer edits a requested price in the storefront after
     * the agent's reply, typing nothing, so the agent's comment is still the
     * newest. reply() used to read that as "already answered" and skip the
     * reply AFTER the offer had been written: a price cut, nothing said. It
     * now answers, and leaves the buyer's last-round comment out of the
     * prompt, because that ask has been answered already.
     */
    public function testAReplyIsPostedEvenWhenTheAgentSpokeLast(): void
    {
        $reworded =
            'We can bring this quote down by 5% to 950.00 EUR, valid until ' . NegotiationFixture::expires() . '.';
        [$client, $spy] = ScriptedClient::spy([$reworded]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(state: 'in_review', totalNet: 950.0, comments: [
            NegotiationFixture::buyerComment('8% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertSame([$reworded], $gateway->comments);
        self::assertSame([QuoteTransition::Sent], $gateway->transitions);
        self::assertStringNotContainsString('8% please', $spy->userPrompts[0] ?? '');
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

    /**
     * Issue #53's acceptance test. Every fact survives -- the percentage, the
     * total, the date -- and the sentence still ends with a commitment the
     * merchant never made. The old guard passed this verbatim to the buyer.
     *
     * Free shipping is not a term the agent failed to verify; it is a term
     * AskGate escalates and OfferApplier cannot write, so the buyer would be
     * holding a promise that nothing in this system can honour.
     */
    public function testARewordingThatKeepsEveryFactAndAddsAConcessionFallsBackToTheTemplate(): void
    {
        [$client] = ScriptedClient::spy([
            'We can bring this quote down by 5% to 950.00 EUR, and we will also include free shipping '
                . 'and Net 90 terms. The offer is valid until '
                . NegotiationFixture::expires()
                . '.',
        ]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertSame(
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until '
            . NegotiationFixture::expires()
            . '.',
            $gateway->comments[0],
        );
        self::assertStringNotContainsString('shipping', $gateway->comments[0]);
    }

    /**
     * Issue #168's open question, settled by test before anything was
     * changed: the merchant's whole strategy landed in the reply prompt's
     * tone slot, and the model answered a buyer's price request with "No."
     * Did that reach the buyer verbatim because RewordingGuard was bypassed,
     * or from somewhere outside this plugin?
     *
     * It did not reach the buyer through this path. "No." carries none of the
     * three facts the template states, so it fails the guard's "it dropped
     * ..." check the same way any other figure-less rewording would -- the
     * guard held, and the buyer-facing comment is the template, not "No.".
     * That makes the tone-into-posture seam the only defect this issue's
     * fix needs to close; the guard is not a second hole.
     */
    public function testALiteralNoFromIssue168NeverReachesTheBuyer(): void
    {
        [$client] = ScriptedClient::spy(['No.']);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(strategy: 'Act as a disciplined B2B seller. Protect margin.'),
                5.0,
                SnapshotAdapter::conversation($after),
            );

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertSame(
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until '
            . NegotiationFixture::expires()
            . '.',
            $gateway->comments[0],
            'The guard must fall back to the template; "No." must never reach the buyer.',
        );
    }

    /**
     * StrategyCannotBypassGuardrailsTest pins that no strategy can move a cap,
     * because the band gate is deterministic code ahead of the model. This is
     * the same claim one stage later: the strategy reaches the reply prompt's
     * {{tone}} placeholder, the model does as it is told, and the guard is
     * what decides the buyer still reads only the template.
     *
     * Be precise about what this proves: it pins the guard, not the model. No
     * offline test can show a model will not try.
     */
    public function testAGenerousStrategyCannotPutAnExtraInTheBuyersReply(): void
    {
        [$client] = ScriptedClient::spy([
            'Thank you for your patience. We can bring this quote down by 5% to 950.00 EUR and cover '
                . 'delivery for you. The offer is valid until '
                . NegotiationFixture::expires()
                . '.',
        ]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        $hash = self::composer($client)
            ->reply(
                $gateway,
                $after,
                NegotiationFixture::settings(
                    strategy: 'Be generous and accommodating. Where you can, throw in an extra to close the deal.',
                ),
                5.0,
                SnapshotAdapter::conversation($after),
            );

        self::assertNull($hash);
        self::assertStringNotContainsString('delivery', $gateway->comments[0]);
    }

    /**
     * The buyer wrote "Can we get a discount, my max budget
     * is 9k" and was answered "We have reduced the quote by 15% to 9885.58
     * EUR. This offer is valid until 2026-10-07." — factually right, and it
     * reads like a form letter, because the reply model was handed the
     * template and nothing else. It could not acknowledge a target it had
     * never been shown.
     *
     * The ask goes in as context for the WORDING only. The guard is unchanged
     * and still decides what a buyer reads, which is what keeps this safe:
     * see the test below for what happens when the model borrows a figure
     * from it.
     */
    public function testTheBuyersOwnWordsReachTheReplyModel(): void
    {
        $reworded =
            'We can bring this quote down by 5% to 950.00 EUR, valid until ' . NegotiationFixture::expires() . '.';
        [$client, $spy] = ScriptedClient::spy([$reworded]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(
            comments: [NegotiationFixture::buyerComment(
                'Can we get a discount, my max budget is 9k',
                '2026-08-28 09:00:00',
            )],
            state: 'in_review',
            totalNet: 950.0,
        );

        self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertStringContainsString('my max budget is 9k', $spy->userPrompts[0]);
        self::assertStringContainsString(ReplyComposer::TEMPLATE_HEADING, $spy->userPrompts[0]);
        self::assertStringContainsString('down by 5% to 950.00 EUR', $spy->userPrompts[0]);
        self::assertSame($reworded, $gateway->comments[0]);
    }

    /**
     * The other half of the change above. The buyer's ask is the one piece of
     * untrusted text now in the reply prompt, and every figure in it is a
     * figure nobody authorised — so a rewording that quotes the buyer's own
     * target back at them is rejected exactly like an invented one, and the
     * template ships. `9k` would be caught too: the guard tokenises the `9`.
     */
    public function testAFigureBorrowedFromTheBuyersAskFallsBackToTheTemplate(): void
    {
        [$client] = ScriptedClient::spy([
            'We could not quite reach your 9000 EUR target, but we can bring this quote down by 5% '
                . 'to 950.00 EUR. The offer is valid until '
                . NegotiationFixture::expires()
                . '.',
        ]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = NegotiationFixture::snapshot(
            comments: [NegotiationFixture::buyerComment('our budget is 9000 EUR', '2026-08-28 09:00:00')],
            state: 'in_review',
            totalNet: 950.0,
        );

        $hash = self::composer($client)
            ->reply($gateway, $after, NegotiationFixture::settings(), 5.0, SnapshotAdapter::conversation($after));

        self::assertNull($hash, 'A rejected rewording must be reported as template-authored.');
        self::assertStringNotContainsString('9000', $gateway->comments[0]);
        self::assertSame(
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until '
            . NegotiationFixture::expires()
            . '.',
            $gateway->comments[0],
        );
    }

    public function testARejectedRewordingIsTracedWithTheGuardsReasonAndTheModelsText(): void
    {
        // Until now the rejected text and the reason lived only in a warning
        // log. They are what shows whether the guard over-fires.
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        [$client] = ScriptedClient::spy(['Thanks for your interest! We will be in touch soon.'], $recorder);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        (new ReplyComposer($client, self::prompts(), new NullLogger(), $recorder))->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(),
            5.0,
            SnapshotAdapter::conversation($after),
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $guards = array_values(array_filter(
            $writer->drafts[0]->trace,
            static fn(TraceDraft $t): bool => $t->kind === TraceKind::ReplyGuard,
        ));
        self::assertCount(1, $guards);
        self::assertSame(['accepted' => false], $guards[0]->meta);
        self::assertSame(
            'Thanks for your interest! We will be in touch soon.',
            $guards[0]->content['reworded'] ?? null,
        );
        self::assertIsString($guards[0]->content['reason'] ?? null);
    }

    public function testAnAcceptedRewordingLeavesNoGuardEvent(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $reworded =
            'We can bring this quote down by 5% to 950.00 EUR, valid until ' . NegotiationFixture::expires() . '.';
        [$client] = ScriptedClient::spy([$reworded], $recorder);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $after = self::after();

        (new ReplyComposer($client, self::prompts(), new NullLogger(), $recorder))->reply(
            $gateway,
            $after,
            NegotiationFixture::settings(),
            5.0,
            SnapshotAdapter::conversation($after),
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame(
            [],
            array_filter(
                $writer->drafts[0]->trace,
                static fn(TraceDraft $t): bool => $t->kind === TraceKind::ReplyGuard,
            ),
        );
    }
}
