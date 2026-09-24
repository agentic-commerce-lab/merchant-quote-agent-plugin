<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Shared fixtures for the negotiation stages.
 *
 * @mago-expect lint:too-many-methods
 * These small named fixture builders keep test call sites readable without
 * expanding snapshot() beyond five parameters. Negotiation tests own this
 * fixture; split it if its builders grow beyond shared stage inputs.
 */
final class NegotiationFixture
{
    /**
     * What a fixture quote opens at, named because two other things have to
     * agree with it: `PipelineHarness::AFTER_NET` is the total a pass re-reads
     * against it, and `PipelineHarness::rewordedReply()` states the reduction
     * between the two. A literal in three places is how the scripted replies
     * in #141 drifted away from the quotes they answer.
     */
    public const DEFAULT_TOTAL_NET = 1000.0;

    /**
     * The expiry every fixture quote carries, so the reply the template writes
     * is a fixed string rather than today + 14 days. Computed relative to now,
     * not a hardcoded date: #57 gave the verifier a lower bound on expiry, so
     * a calendar date frozen in source eventually drifts into the past and
     * every one of these fixtures would start failing the pass it is meant
     * to exercise, for no reason connected to the code under test.
     *
     * +7 days, not some other offset: settings() below fixes validityDays at
     * 14, so this must clear the new lower bound (after today) while staying
     * inside the upper one (at most 14 + 1 days out) with room either side.
     */
    public static function expires(): string
    {
        return (new \DateTimeImmutable('+7 days'))->format('Y-m-d');
    }

    private function __construct() {}

    /** @param list<QuoteComment> $comments */
    public static function snapshot(
        array $comments = [],
        string $state = 'open',
        float $totalNet = self::DEFAULT_TOTAL_NET,
        ?float $requestedUnitPrice = null,
        ?QuoteRevision $revision = null,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1', 'cust-1'),
            revision: $revision ?? new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: $totalNet, totalGross: $totalNet),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $state,
                expiresAt: new \DateTimeImmutable(self::expires()),
            ),
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: $totalNet / 10,
                totalNet: $totalNet,
                requestedUnitPrice: $requestedUnitPrice,
            )], comments: $comments),
        );
    }

    /**
     * A quote whose stored prices are GROSS, at a round 25% tax so the spaces
     * stay readable: 100.00 on the line the buyer reads, 80.00 net behind it.
     *
     * @param list<QuoteComment> $comments
     */
    public static function grossSnapshot(array $comments = [], string $state = 'change_requested'): QuoteSnapshot
    {
        $snapshot = self::snapshot(comments: $comments, state: $state, totalNet: 800.0);

        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: new QuoteTotals(totalNet: 800.0, totalGross: 1000.0),
            lifecycle: $snapshot->lifecycle,
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: 80.0,
                totalNet: 800.0,
                netRatio: 0.8,
            )], comments: $comments),
        );
    }

    public static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
    }

    public static function snapshotWithCustomer(string $customerId): QuoteSnapshot
    {
        $snapshot = self::snapshot();

        return new QuoteSnapshot(
            identity: new QuoteIdentity(
                $snapshot->identity->quoteId,
                $snapshot->identity->quoteNumber,
                $snapshot->identity->currencyIso,
                $snapshot->identity->salesChannelId,
                $customerId,
            ),
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: $snapshot->lifecycle,
            content: $snapshot->content,
        );
    }

    /** The merchant's LLM credentials, shared so every test points at the same fake host. */
    public static function modelAccess(): ModelAccess
    {
        return new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini');
    }

    /** A 200 OK with the OpenAI chat-completions envelope around $content. */
    public static function modelReply(string $content): MockResponse
    {
        return new MockResponse(
            json_encode([
                'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            ], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    public static function buyerComment(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, customerId: 'cust-1', createdAt: new \DateTimeImmutable($at));
    }

    public static function agentComment(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, createdAt: new \DateTimeImmutable($at));
    }

    public static function settings(
        float $maxDiscountPercent = 10.0,
        ?float $counterOfferMaxPercent = 20.0,
        ?string $strategy = null,
        bool $notifyBuyerOnEscalation = false,
    ): QuoteAgentSettings {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: $maxDiscountPercent,
                counterOfferMaxPercent: $counterOfferMaxPercent,
                validityDays: 14,
            )),
            llm: self::modelAccess(),
            strategyPrompt: $strategy,
            notifyBuyerOnEscalation: $notifyBuyerOnEscalation,
        );
    }

    /**
     * The same snapshot with different quote custom fields. A separate method
     * rather than a sixth parameter on snapshot(), which is already at the
     * parameter-count gate.
     *
     * @param array<string, mixed> $customFields
     */
    public static function withCustomFields(QuoteSnapshot $snapshot, array $customFields): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: $snapshot->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $snapshot->lifecycle->stateTechnicalName,
                expiresAt: $snapshot->lifecycle->expiresAt,
                customFields: $customFields,
                lastAdminTransitionAt: $snapshot->lifecycle->lastAdminTransitionAt,
                lastAdminTransitionTo: $snapshot->lifecycle->lastAdminTransitionTo,
            ),
            content: $snapshot->content,
        );
    }

    /** A stored baseline saying every line started at `$unitPriceNet`. */
    public static function baselineOf(float $totalNet, float $unitPriceNet): array
    {
        return [
            QuoteBaseline::KEY => [
                'totalNet' => $totalNet,
                'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => $unitPriceNet, 'quantity' => 10]],
            ],
        ];
    }
}
