<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @mago-expect lint:too-many-methods
 * Five route actions plus three boundary helpers are kept together so route
 * metadata, HTTP error mapping, and body validation cannot drift apart.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The five route actions intentionally map the same four domain failures to
 * HTTP responses through one shared answer boundary; splitting that mapping
 * would duplicate the security-sensitive error contract.
 */
final readonly class DraftReviewController
{
    private const BASE = '/api/_action/merchant-quote-agent/decision/{decisionId}';

    public function __construct(
        private PendingDrafts $drafts,
        private DraftPreviewer $previewer,
        private DraftSender $sender,
        private DraftRejecter $rejecter,
        private DecisionReviewStoreInterface $store,
    ) {}

    #[Route(
        path: self::BASE . '/draft',
        name: 'api.action.merchant_quote_agent.decision_draft',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:read']],
        methods: ['GET'],
    )]
    public function draft(string $decisionId): JsonResponse
    {
        return self::answer(fn(): array => $this->drafts->with($decisionId, static fn(PendingDraft $pending): array => DraftView::of(
            $pending,
            $pending->draftSnapshot(),
            null,
        )));
    }

    #[Route(
        path: self::BASE . '/preview',
        name: 'api.action.merchant_quote_agent.decision_preview',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['POST'],
    )]
    public function preview(string $decisionId, Request $request): JsonResponse
    {
        return self::answer(function () use ($decisionId, $request): array {
            $edits = self::edits($request);

            return $this->drafts->with($decisionId, fn(PendingDraft $pending): array => $this->previewer->preview(
                $pending,
                $edits,
            ));
        });
    }

    #[Route(
        path: self::BASE . '/send',
        name: 'api.action.merchant_quote_agent.decision_send',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['POST'],
    )]
    public function send(string $decisionId, Request $request, Context $context): JsonResponse
    {
        return self::answer(function () use ($decisionId, $request, $context): array {
            $body = self::body($request);
            $edits = self::editsFromBody($body);
            $reply = \is_string($body['reply'] ?? null) ? $body['reply'] : '';

            $this->drafts->with($decisionId, fn(PendingDraft $pending) => $this->sender->send(
                $pending,
                $reply,
                $edits,
                $context,
            ));

            return ['status' => 'sent'];
        });
    }

    #[Route(
        path: self::BASE . '/reject',
        name: 'api.action.merchant_quote_agent.decision_reject',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['POST'],
    )]
    public function reject(string $decisionId): JsonResponse
    {
        return self::answer(function () use ($decisionId): array {
            $this->drafts->withAnyDraft($decisionId, fn(PendingDraft $pending) => $this->rejecter->reject($pending));

            return ['status' => 'rejected'];
        });
    }

    #[Route(
        path: self::BASE . '/feedback',
        name: 'api.action.merchant_quote_agent.decision_feedback',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['PUT'],
    )]
    public function feedback(string $decisionId, Request $request): Response
    {
        $response = self::answer(function () use ($decisionId, $request): array {
            $feedback = self::feedbackRequest($request);

            if ($this->store->find($decisionId) === null) {
                throw DecisionNotFound::forId($decisionId);
            }

            $this->store->saveFeedback($decisionId, $feedback->reasonValues(), $feedback->comment);

            return [];
        });

        return $response->getStatusCode() === 200 ? new Response(status: 204) : $response;
    }

    /** @param \Closure(): array<string, mixed> $work */
    private static function answer(\Closure $work): JsonResponse
    {
        try {
            return new JsonResponse($work());
        } catch (DraftNotReviewable $e) {
            return new JsonResponse(['code' => $e->reason, 'message' => $e->getMessage()], 409);
        } catch (DecisionNotFound $e) {
            return new JsonResponse(['code' => 'not_found', 'message' => $e->getMessage()], 404);
        } catch (InvalidReviewRequest $e) {
            return new JsonResponse([
                'code' => 'invalid',
                'reason' => $e->reason->value,
                'message' => $e->getMessage(),
            ], 400);
        } catch (MappingError) {
            return new JsonResponse([
                'code' => 'invalid',
                'reason' => InvalidReviewReason::Malformed->value,
                'message' => 'The request does not have the expected shape.',
            ], 400);
        }
    }

    /** @return array<mixed> */
    private static function body(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        try {
            return $request->toArray();
        } catch (JsonException $e) {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::Malformed,
                'The request body is not a JSON object.',
                $e,
            );
        }
    }

    /** @throws InvalidReviewRequest */
    private static function edits(Request $request): DraftEdits
    {
        return self::editsFromBody(self::body($request));
    }

    /** @param array<mixed> $body */
    private static function editsFromBody(array $body): DraftEdits
    {
        try {
            return ArrayMapper::mapObject(DraftEdits::class, $body);
        } catch (MappingError $e) {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::Malformed,
                'The request does not have the expected shape.',
                $e,
            );
        }
    }

    /** @throws InvalidReviewRequest */
    private static function feedbackRequest(Request $request): FeedbackRequest
    {
        try {
            return ArrayMapper::mapObject(FeedbackRequest::class, self::body($request));
        } catch (MappingError $e) {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::Malformed,
                'The request does not have the expected shape.',
                $e,
            );
        }
    }
}
