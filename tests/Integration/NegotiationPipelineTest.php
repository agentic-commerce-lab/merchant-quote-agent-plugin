<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * The pipeline against real quotes, real writes and the real verifier. Only
 * the model is scripted — everything else is the shop.
 */
final class NegotiationPipelineTest extends IntegrationTestCase
{
    public function testAnInBandAskIsAppliedToTheQuoteAndAnswered(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $before = $gateway->fetchSnapshot($quoteId);
        $commentsBefore = \count($before->content->comments);

        // A buyer ask has to exist for the pass to do anything.
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $pipeline = self::pipelineWith([
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ]);

        $outcome = $pipeline->service(
            $gateway->fetchSnapshot($quoteId),
            $gateway,
            self::enabledSettings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertGreaterThan($commentsBefore, \count($after->content->comments), 'The buyer was never answered.');
        self::assertFalse($after->revision->matches($before->revision), 'Nothing was written to the quote.');
    }

    public function testAnOutOfAuthorityAskEscalatesWithoutTouchingPrices(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'I need 40% off.');
        $before = $gateway->fetchSnapshot($quoteId);

        $pipeline = self::pipelineWith(['{"additional_discount_percent": 40}']);

        $outcome = $pipeline->service(
            $gateway->fetchSnapshot($quoteId),
            $gateway,
            self::enabledSettings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(
            $before->totals->totalNet,
            $gateway->fetchSnapshot($quoteId)->totals->totalNet,
            'An escalated ask must not move the price.',
        );
    }

    public function testTheSamePassTwiceAnswersOnce(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $replies = [
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ];

        self::pipelineWith($replies)
            ->service(
                $gateway->fetchSnapshot($quoteId),
                $gateway,
                self::enabledSettings(),
                NegotiationFixture::context(),
            );
        $afterFirst = \count($gateway->fetchSnapshot($quoteId)->content->comments);

        self::pipelineWith($replies)
            ->service(
                $gateway->fetchSnapshot($quoteId),
                $gateway,
                self::enabledSettings(),
                NegotiationFixture::context(),
            );

        self::assertSame(
            $afterFirst,
            \count($gateway->fetchSnapshot($quoteId)->content->comments),
            'A re-run posted a second message to the buyer.',
        );
    }

    public function testAReplyPostedByADeadPassStillReachesReplied(): void
    {
        // #31, on the shop rather than against a fake: the reply is two writes,
        // and a worker dying between them used to strand the quote in
        // `in_review` for good — the retry reads the agent's own comment as
        // the newest, so the interpreter returns null and nothing was left to
        // finish the transition.
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        // Exactly what a dead pass leaves behind: the quote moved to in_review
        // and the buyer answered, but never the `sent` transition after it.
        $gateway->transition($quoteId, QuoteTransition::Process);
        $gateway->addComment($quoteId, 'We can offer 5% off.');

        $stranded = $gateway->fetchSnapshot($quoteId);
        self::assertSame('in_review', $stranded->lifecycle->stateTechnicalName);

        self::pipelineWith([])->service($stranded, $gateway, self::enabledSettings(), NegotiationFixture::context());

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertSame('replied', $after->lifecycle->stateTechnicalName);
        self::assertCount(
            \count($stranded->content->comments),
            $after->content->comments,
            'Finishing the transition must not say anything further to the buyer.',
        );
    }

    /** A real buyer comment, written the same way ServicingTriggerTest does. */
    private static function writeBuyerComment(string $quoteId, string $text): void
    {
        $comments = static::getContainer()->get('quote_comment.repository');
        self::assertInstanceOf(EntityRepository::class, $comments);

        $customers = static::getContainer()->get('customer.repository');
        self::assertInstanceOf(EntityRepository::class, $customers);
        $customerId = $customers->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($customerId, 'The shop has no customer to attribute a buyer comment to.');

        $comments->create([[
            'quoteId' => $quoteId,
            'comment' => $text,
            'customerId' => $customerId,
        ]], Context::createDefaultContext());
    }

    /** Built directly rather than read from config: this test is proving the pipeline, not the reader. */
    private static function enabledSettings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: 10.0,
                counterOfferMaxPercent: 20.0,
                validityDays: 14,
            )),
            rulesOnly: false,
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: null,
        );
    }

    /**
     * The real pipeline, with every collaborator resolved from the container
     * except the model client, which is scripted so no API key is needed.
     *
     * @param list<string> $replies each becomes one model call's answer, in order
     */
    private static function pipelineWith(array $replies): NegotiationPipeline
    {
        $client = ScriptedClient::returning($replies);
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

        $round = new OfferRound(
            new OfferProposer($client, $prompts, $authorizer, $recorder),
            new OfferApplier($verifier, $logger, $recorder),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            $escalator,
            $logger,
        );

        return new NegotiationPipeline(
            new AskInterpreter($client, $prompts, $recorder),
            $decider,
            $round,
            $recorder,
            $logger,
        );
    }
}
