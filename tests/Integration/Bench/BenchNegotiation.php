<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryFactoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Bench\BuyerMoveKind;
use MerchantQuoteAgentPlugin\Tests\Bench\Scenario;
use MerchantQuoteAgentPlugin\Tests\Bench\SyntheticBuyer;
use MerchantQuoteAgentPlugin\Tests\Integration\BuyerQuoteContextFixture;
use MerchantQuoteAgentPlugin\Tests\Integration\BuyerQuoteFixture;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drives one scenario to its end against a live shop: a real quote, the real
 * pipeline, real decision records. Only the model and the buyer are
 * substituted — exactly what PipelineFixture::pipelineWithSpy() already
 * substitutes for a single pass, wired the same way here, then looped.
 *
 * Not itself a test case, so it cannot reuse PipelineFixture's helpers
 * directly: those lean on static::getContainer() and self::assertInstanceOf(),
 * both only meaningful on a booted TestCase. This class takes the container
 * (and the merchant/buyer gateways ShopServices would otherwise build) as
 * constructor dependencies instead, resolved once by the caller.
 *
 * `maxRounds` is the only bound on the loop — see Scenario's own docblock:
 * issue #142's round cap was closed unmerged, so nothing in src/ stops a
 * non-converging negotiation, and this class must stop itself.
 */
final readonly class BenchNegotiation
{
    public function __construct(
        private ContainerInterface $container,
        private QuoteGatewayInterface $gateway,
        private BuyerQuoteGatewayInterface $buyerGateway,
        private ModelPlatform $model,
    ) {}

    /**
     * `$runId` is not read here: it belongs to the matrix driver (Task 6),
     * which correlates the decision rows this run leaves behind — by
     * `quoteId`, after this method returns — with the run that produced
     * them. It is part of this method's signature because that driver must
     * be able to pass one in; nothing in the loop itself needs to know it.
     */
    public function run(
        Scenario $scenario,
        SyntheticBuyer $buyer,
        QuoteAgentSettings $settings,
        string $runId,
    ): NegotiationResult {
        unset($runId);

        $customerId = BuyerQuoteFixture::anyQuoteCapableCustomerId($this->container);
        $context = BuyerQuoteContextFixture::contextForCustomer($this->container, $customerId);
        $context->getContext()->addState(AgentContext::STATE, Context::SKIP_TRIGGER_FLOW);

        $lineItems = array_map(fn(array $line): array => [
            'product_id' => self::resolveProduct($this->container, $line['productRef']),
            'quantity' => $line['quantity'],
        ], $scenario->lines);

        $quote = $this->buyerGateway->requestQuote($context, $lineItems, null);
        $quoteId = $quote->id;
        $this->writeComment($quoteId, $customerId, $scenario->openingAsk);

        // Folded into run() rather than kept as its own negotiate() method:
        // that split needed six parameters (context, quoteId, customerId,
        // scenario, buyer, settings) once the buyer context joined the list,
        // past this codebase's excessive-parameter-list gate of five — and
        // every one of those six is already a local here.
        $pipeline = $this->pipeline();
        $outcome = NegotiationOutcome::NothingToDo;

        for ($round = 1; $round <= $scenario->maxRounds; $round++) {
            $before = $this->gateway->fetchSnapshot($quoteId);
            $outcome = $pipeline->service(
                $before,
                $this->gateway,
                $settings,
                new PassContext(ServicingTriggerReason::CommentWritten, $round),
            );

            // An escalated quote is waiting for a human; asking the synthetic
            // buyer to react to it, or writing another comment at it, would
            // measure nothing the run is meant to score. Stops here rather
            // than at the top of the loop, since the escalation itself is
            // discovered by the pass this iteration just ran.
            if ($outcome === NegotiationOutcome::Escalated) {
                return new NegotiationResult($quoteId, $round, null, $outcome, OrderConversion::notAttempted());
            }

            $after = $this->gateway->fetchSnapshot($quoteId);
            $move = $buyer->respond($before, $after, self::lastAgentReply($before, $after), $round);

            if ($move->kind !== BuyerMoveKind::Counter) {
                $order = $move->kind === BuyerMoveKind::Accept
                    ? $this->convertToOrder($context, $quoteId)
                    : OrderConversion::notAttempted();

                return new NegotiationResult($quoteId, $round, $move->kind, $outcome, $order);
            }

            $this->writeComment($quoteId, $customerId, $move->comment ?? '');
        }

        return new NegotiationResult($quoteId, $scenario->maxRounds, null, $outcome, OrderConversion::notAttempted());
    }

    /**
     * The real path a storefront buyer takes to convert a quote — the same
     * call BuyerQuoteFlowTest proves against this shop. A quote the agent
     * escalated, or one that never got a real offer applied to it, may
     * legitimately refuse this call (SwagCommercial's quote-order route
     * rejects it, or a test double stands in for it —
     * BenchNegotiationTest::testAFailedConversionDoesNotLoseTheNegotiation
     * forces exactly this path); that refusal becomes an `OrderConversion`
     * with a non-null `orderFailure` on the bench cell, not a lost
     * negotiation — the rounds already happened and their decision records
     * are already committed by the time this runs.
     */
    private function convertToOrder(SalesChannelContext $context, string $quoteId): OrderConversion
    {
        try {
            return new OrderConversion($this->buyerGateway->acceptQuote($context, $quoteId)->orderId, null);
        } catch (\Throwable $e) {
            return new OrderConversion(null, sprintf('%s: %s', $e::class, $e->getMessage()));
        }
    }

    private static function resolveProduct(ContainerInterface $container, string $productRef): string
    {
        return match ($productRef) {
            'any-purchasable' => BuyerQuoteFixture::anyPurchasableProductId($container),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown scenario productRef "%s". Teach BenchNegotiation::resolveProduct() how to find it.',
                $productRef,
            )),
        };
    }

    /** '' when the pass said nothing — a NothingToDo/Clarified outcome writes no new agent comment. */
    private static function lastAgentReply(QuoteSnapshot $before, QuoteSnapshot $after): string
    {
        $replies = self::agentCommentsAdded($before, $after);

        return $replies === [] ? '' : $replies[array_key_last($replies)];
    }

    /**
     * What THIS pass said to the buyer, mirroring
     * PipelineFixture::agentCommentsAdded() — duplicated rather than shared
     * because that trait's sibling methods reach for static::getContainer()
     * and self::assertInstanceOf(), neither available outside a TestCase, and
     * splitting the one method that doesn't need them would separate it from
     * the fixture it is documented alongside.
     *
     * @return list<string>
     */
    private static function agentCommentsAdded(QuoteSnapshot $before, QuoteSnapshot $after): array
    {
        $agentComments = static fn(QuoteSnapshot $snapshot): array => array_values(array_map(
            static fn(QuoteComment $comment): string => $comment->comment,
            array_filter(
                $snapshot->content->comments,
                static fn(QuoteComment $comment): bool => !$comment->isAuthored(),
            ),
        ));

        return array_values(array_diff($agentComments($after), $agentComments($before)));
    }

    /** Same shape as PipelineFixture::writeBuyerComment(), built from an injected container instead of a booted TestCase. */
    private function writeComment(string $quoteId, string $customerId, string $text): void
    {
        $comments = $this->container->get('quote_comment.repository');
        if (!$comments instanceof EntityRepository) {
            throw new \RuntimeException('The container has no quote_comment.repository.');
        }

        $context = AgentContext::create();
        $context->addState(Context::SKIP_TRIGGER_FLOW);

        $comments->create([[
            'quoteId' => $quoteId,
            'comment' => $text,
            'customerId' => $customerId,
        ]], $context);
    }

    /**
     * The same collaborator graph PipelineFixture::pipelineWithSpy() builds,
     * with the constructor's own ModelPlatform standing in for
     * ScriptedClient::spy(...) — a real client for the matrix driver, a
     * scripted one for BenchNegotiationTest.
     */
    private function pipeline(): NegotiationPipeline
    {
        $prompts = $this->service(PromptComposer::class);
        $authorizer = $this->service(OfferAuthorizer::class);
        $verifier = $this->service(OfferVerifier::class);
        $decider = $this->service(NegotiationDecider::class);
        $escalator = $this->service(QuoteEscalator::class);
        $recorder = $this->service(DecisionRecorder::class);
        $historyFactory = $this->service(CustomerHistoryFactoryInterface::class);
        $logger = new NullLogger();

        $round = new OfferRound(
            new OfferProposer($this->model, $prompts, $authorizer, $recorder, $historyFactory),
            new OfferApplier($verifier, $logger, $recorder),
            new ReplyComposer($this->model, $prompts, $logger, $recorder),
            $escalator,
            $logger,
        );

        return new NegotiationPipeline(
            new AskInterpreter($this->model, $prompts, $recorder),
            $decider,
            $round,
            $recorder,
            $logger,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->container->get($id);

        if (!$service instanceof $id) {
            throw new \RuntimeException(sprintf('Service "%s" did not resolve to that type.', $id));
        }

        return $service;
    }
}
