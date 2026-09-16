<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryFactoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Shared by every integration test that needs a real pipeline against the
 * live shop with only the model scripted. Lifted out of NegotiationPipelineTest
 * (Task 9) so DecisionRecordTest (Task 10) can drive the same real pass
 * without duplicating it — a trait rather than a base-class method because
 * both test classes already extend IntegrationTestCase for unrelated reasons
 * and gain nothing from a second inheritance layer.
 */
trait PipelineFixture
{
    /** A real buyer comment, written the same way ServicingTriggerTest does. */
    protected static function writeBuyerComment(string $quoteId, string $text): void
    {
        $comments = static::getContainer()->get('quote_comment.repository');
        self::assertInstanceOf(EntityRepository::class, $comments);

        $customerId = self::connection(static::getContainer())
            ->fetchOne('SELECT LOWER(HEX(customer_id)) FROM quote WHERE id = UNHEX(:quote) AND version_id = UNHEX(:live)', [
                'quote' => $quoteId,
                'live' => Defaults::LIVE_VERSION,
            ]);
        self::assertIsString($customerId, 'The quote must have an actual buyer owner.');
        $context = AgentContext::create();
        $context->addState(Context::SKIP_TRIGGER_FLOW);

        $comments->create([[
            'quoteId' => $quoteId,
            'comment' => $text,
            'customerId' => $customerId,
        ]], $context);
    }

    /** Built directly rather than read from config: this test is proving the pipeline, not the reader. */
    protected static function enabledSettings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: 10.0,
                counterOfferMaxPercent: 20.0,
                validityDays: 14,
            )),
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: null,
        );
    }

    /**
     * The real pipeline, with every collaborator resolved from the container
     * except the model client, which is scripted so no API key is needed.
     *
     * @param list<string|\Closure(string): string> $replies each becomes one model call's answer, in order
     */
    protected static function pipelineWith(array $replies): NegotiationPipeline
    {
        return self::pipelineWithSpy($replies)[0];
    }

    /**
     * @param list<string|\Closure(string): string> $replies
     * @return array{0: NegotiationPipeline, 1: ScriptedClient}
     */
    protected static function pipelineWithSpy(array $replies): array
    {
        $logger = new NullLogger();

        $prompts = static::getContainer()->get(PromptComposer::class);
        self::assertInstanceOf(PromptComposer::class, $prompts);

        $authorizer = static::getContainer()->get(OfferAuthorizer::class);
        self::assertInstanceOf(OfferAuthorizer::class, $authorizer);

        $verifier = static::getContainer()->get(OfferVerifier::class);
        self::assertInstanceOf(OfferVerifier::class, $verifier);

        $decider = static::getContainer()->get(NegotiationDecider::class);
        self::assertInstanceOf(NegotiationDecider::class, $decider);

        $escalator = static::getContainer()->get(QuoteEscalator::class);
        self::assertInstanceOf(QuoteEscalator::class, $escalator);

        $recorder = static::getContainer()->get(DecisionRecorder::class);
        self::assertInstanceOf(DecisionRecorder::class, $recorder);
        [$client, $spy] = ScriptedClient::spy($replies, $recorder);

        $historyFactory = static::getContainer()->get(CustomerHistoryFactoryInterface::class);
        self::assertInstanceOf(CustomerHistoryFactoryInterface::class, $historyFactory);

        $round = new OfferRound(
            new OfferProposer($client, $prompts, $authorizer, $recorder, $historyFactory),
            new OfferApplier($verifier, $logger, $recorder),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            $escalator,
            $logger,
        );

        $pipeline = new NegotiationPipeline(
            new AskInterpreter($client, $prompts, $recorder),
            $decider,
            $round,
            $recorder,
            $logger,
        );

        return [$pipeline, $spy];
    }

    /**
     * The template `ReplyComposer::reply()` hands the model as its user
     * prompt — reconstructed the same way `assertPrivateReplyBoundary()` used
     * to build it inline, before that construction moved here so `reworded()`
     * scripted sites could reach it too. It exists to be computed from the
     * snapshot the shop reports, not typed by the test: the reduction, the
     * total and the expiry are only known after this pass's own write.
     */
    protected static function replyTemplateFor(QuoteSnapshot $before, QuoteSnapshot $after): string
    {
        self::assertNotNull($after->lifecycle->expiresAt);

        return ReplyTemplate::compose(
            ReplyTemplate::reduction($before->totals->totalNet, $after->totals->totalNet),
            $after->totals->buyerFacingTotal(),
            $after->identity->currencyIso,
            $after->lifecycle->expiresAt,
        );
    }

    /**
     * The rewording `RewordingGuard` accepts for a template supplied at call
     * time, rather than one this fixture predicts: same reduction, total,
     * currency and expiry as the template, its closing sentence turned into a
     * trailing clause — the same shape `PipelineHarness::rewordedReply()`
     * types out at unit level, where the harness's figures are fixed and so
     * can be spelled rather than derived.
     *
     * The `assertNotSame` is the point of this method, not a guard rail on
     * it. A rewording identical to its template would still satisfy every
     * assertion downstream — `assertPrivateReplyBoundary()` included, since
     * both derive from the same figures — which is exactly how six
     * integration sites spent months scripting a reply the guard silently
     * rejected every run, with nothing asserted able to notice (#146). If
     * this transform ever degraded to the identity function, those sites
     * would go straight back to proving nothing while looking like they do.
     */
    protected static function reworded(string $template): string
    {
        $reworded = str_replace('. The offer is valid until ', ', valid until ', $template);
        self::assertNotSame($template, $reworded, 'The rewording must actually differ from its template.');

        return $reworded;
    }

    /**
     * The agent's own side of the conversation: comments nobody authored, per
     * `QuoteComment::isAuthored()` — see that class for why the absence of
     * all three author columns is what tells the agent's write apart from the
     * buyer's or the merchant's. Shared so the six sites now scripting
     * `reworded()` replies, and `HistoryInjectionAssertions`, read the same
     * filter rather than each repeating it.
     *
     * @return list<string>
     */
    protected static function agentComments(QuoteSnapshot $snapshot): array
    {
        return array_values(array_map(
            static fn(QuoteComment $comment): string => $comment->comment,
            array_filter(
                $snapshot->content->comments,
                static fn(QuoteComment $comment): bool => !$comment->isAuthored(),
            ),
        ));
    }

    /**
     * What THIS pass said to the buyer, as opposed to what the quote already
     * said.
     *
     * `QuoteFixture` hands out a real shop quote rather than building one, and
     * the quote this suite settles on already carries an agent comment from an
     * earlier life — so "the agent's replies" and "the reply this pass wrote"
     * are different sets, and only the second one is ever the subject. The
     * freshly-built quotes in `HistoryInjectionFixture` have no such history,
     * which is why `assertPrivateReplyBoundary()` can still assert the whole
     * set there.
     *
     * @return list<string>
     */
    protected static function agentCommentsAdded(QuoteSnapshot $before, QuoteSnapshot $after): array
    {
        return array_values(array_diff(self::agentComments($after), self::agentComments($before)));
    }
}
