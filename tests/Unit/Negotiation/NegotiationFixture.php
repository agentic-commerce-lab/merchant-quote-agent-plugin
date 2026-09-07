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

/** Shared fixtures for the negotiation stages. */
final class NegotiationFixture
{
    /**
     * The expiry every fixture quote carries, so the reply the template writes
     * is a fixed string rather than today + 14 days. A date in the past is
     * fine: the verifier only bounds how FAR OUT an offer may be valid.
     */
    public const EXPIRES = '2026-09-11';

    private function __construct() {}

    /** @param list<QuoteComment> $comments */
    public static function snapshot(
        array $comments = [],
        string $state = 'open',
        float $totalNet = 1000.0,
        ?float $requestedUnitPrice = null,
        ?QuoteRevision $revision = null,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: $revision ?? new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: $totalNet),
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state, expiresAt: new \DateTimeImmutable(self::EXPIRES)),
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: $totalNet / 10,
                totalNet: $totalNet,
                requestedUnitPrice: $requestedUnitPrice,
            )], comments: $comments),
        );
    }

    public static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
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
        bool $rulesOnly = false,
        float $maxDiscountPercent = 10.0,
        ?float $counterOfferMaxPercent = 20.0,
        ?string $strategy = null,
        ?string $tone = null,
    ): QuoteAgentSettings {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: $maxDiscountPercent,
                counterOfferMaxPercent: $counterOfferMaxPercent,
                validityDays: 14,
                replyTone: $tone,
            )),
            rulesOnly: $rulesOnly,
            llm: self::modelAccess(),
            strategyPrompt: $strategy,
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
