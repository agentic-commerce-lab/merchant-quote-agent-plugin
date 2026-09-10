<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteStats;
use MerchantQuoteAgentPlugin\Negotiation\CustomerBrief;
use PHPUnit\Framework\Assert;

/** Synthetic account facts, deliberately distinct from the current quote and its permitted offer. */
final class HistoryMessageFixture
{
    public const FIGURES = ['25', '12', '13', '47', '11', '6.37', '137', '82461.93', '2003-04-22'];

    private const PATTERNS = [
        '/(?<!\d)(?<!\d[.,])(?:25|12|13|47|11|137)(?!\d|[.,]\d)/u',
        '/(?<!\d)6[.,]37(?!\d)/u',
        '/(?<!\d)82[.,\s\x{00a0}\x{202f}]?461[.,]93(?!\d)/u',
        '/(?:2003-04-22|22[.\/]04[.\/]2003|04\/22\/2003|April\s+22,?\s+2003|22\s+April\s+2003)/iu',
    ];

    private function __construct() {}

    public static function brief(): string
    {
        return CustomerBrief::of(new CustomerSummary(
            quotes: new QuoteStats(
                seen: 25,
                converted: 12,
                lost: 13,
                offersMade: 47,
                offersAccepted: 11,
                lastGrantedDiscountPercent: 6.37,
            ),
            orders: new OrderStats(
                count: 137,
                lifetimeNet: 82_461.93,
                lastOrderAt: new \DateTimeImmutable('2003-04-22'),
                currencyIso: 'EUR',
            ),
        ));
    }

    public static function assertPrivateMessage(string $message): void
    {
        foreach (self::PATTERNS as $pattern) {
            Assert::assertDoesNotMatchRegularExpression(
                $pattern,
                $message,
                'The negotiate model message disclosed a figure from the INTERNAL brief.',
            );
        }
    }
}
