<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;

/** Assertions on actual writes and information flow, independent of the scripted safe reply. */
trait HistoryInjectionAssertions
{
    /** @param list<string> $kinds */
    private static function assertHistoryRounds(QuoteDecisionRecord $record, ScriptedClient $spy, array $kinds): void
    {
        self::assertNotNull($record->historyReads);
        self::assertTrue($record->historyReads['available']);
        $rounds = $record->historyReads['rounds'];
        self::assertIsArray($rounds);
        self::assertSame($kinds, array_column($rounds, 'kind'));
        foreach ($rounds as $index => $round) {
            self::assertNotEmpty($round['result']);
            self::assertStringContainsString($round['result'], $spy->userPrompts[$index + 2]);
        }
        self::assertStringNotContainsString('ACCOUNT HISTORY', $spy->userPrompts[0]);
    }

    private static function assertForeignHistoryAbsent(ScriptedClient $spy, string $foreign): void
    {
        $reference = new CustomerHistoryReference(self::connection(static::getContainer()));
        $orders = $reference->orders($foreign);
        self::assertNotEmpty($orders, 'The isolation proof requires real foreign orders.');
        $prompts = implode("\n", $spy->userPrompts);
        foreach (self::historyQuoteNumbers($foreign) as $number) {
            self::assertDoesNotMatchRegularExpression(
                '/\bquote\s+' . preg_quote($number, delimiter: '/') . '\b/i',
                $prompts,
            );
        }
        foreach ($orders as $order) {
            self::assertDoesNotMatchRegularExpression(
                '/\border\s+' . preg_quote($order['number'], delimiter: '/') . '\b/i',
                $prompts,
            );
        }
    }

    private static function assertVerifiedCounter(QuoteSnapshot $before, QuoteDecisionRecord $record): QuoteSnapshot
    {
        $after = self::gateway()->fetchSnapshot($before->identity->quoteId);
        self::assertSame('countered', $record->outcome);
        self::assertTrue($record->authorized);
        self::assertTrue($record->verified);
        self::assertSame([], $record->violations);
        self::assertSame(10.0, $record->maxDiscountPercent);
        self::assertLessThan($before->totals->totalNet, $after->totals->totalNet);
        $reduction = ReplyTemplate::reduction($before->totals->totalNet, $after->totals->totalNet);
        self::assertEqualsWithDelta(5.0, $reduction, 0.05);
        self::assertLessThanOrEqual(10.0, $reduction);
        self::assertSame('replied', $after->lifecycle->stateTechnicalName);
        return $after;
    }

    /** @param list<string> $privateValues actual history facts independently absent from this quote */
    private static function assertPrivateReplyBoundary(
        QuoteSnapshot $before,
        QuoteSnapshot $after,
        QuoteDecisionRecord $record,
        ScriptedClient $spy,
        array $privateValues,
    ): void {
        self::assertNotNull($after->lifecycle->expiresAt);
        $template = ReplyTemplate::compose(
            ReplyTemplate::reduction($before->totals->totalNet, $after->totals->totalNet),
            $after->totals->buyerFacingTotal(),
            $after->identity->currencyIso,
            $after->lifecycle->expiresAt,
        );
        $replyPrompt = $spy->userPrompts[$spy->calls - 1];
        self::assertSame($template, $replyPrompt, 'Only verified offer facts may reach the reply model.');
        self::assertSame(
            $template,
            $record->replyToBuyer,
            'The short scripted reply must fall back to verified facts.',
        );
        $agentReplies = array_values(array_filter(
            $after->content->comments,
            static fn($comment): bool => !$comment->isAuthored(),
        ));
        self::assertCount(1, $agentReplies);
        self::assertSame($template, $agentReplies[0]->comment);
        $exclusive = array_values(array_filter(
            $privateValues,
            static fn(string $value): bool => (
                !str_contains(json_encode($before, JSON_THROW_ON_ERROR), $value) && !str_contains($template, $value)
            ),
        ));
        self::assertNotEmpty($exclusive, 'At least one real history figure must be exclusive to the private history.');
        foreach ([
            ...$exclusive,
            self::PRIVATE_MARKER,
            'lifetime',
            'INTERNAL',
            'recorded pass',
            'proposal passes',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $replyPrompt);
            self::assertStringNotContainsString($secret, $agentReplies[0]->comment);
        }
        self::assertStringContainsString(self::PRIVATE_MARKER, $record->rawProposal ?? '');
        foreach ($exclusive as $value) {
            self::assertStringContainsString($value, $record->rawProposal ?? '');
        }
    }
}
