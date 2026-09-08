<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
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
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

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
     * @param list<string> $replies each becomes one model call's answer, in order
     */
    protected static function pipelineWith(array $replies): NegotiationPipeline
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
