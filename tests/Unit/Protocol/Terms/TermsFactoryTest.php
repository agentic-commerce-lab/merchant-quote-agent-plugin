<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Terms;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Terms\TermsFactory;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class TermsFactoryTest extends TestCase
{
    public function testItBuildsTermsInIntegerMinorUnits(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot());

        self::assertSame(760000, $terms['total_value']);
        self::assertSame('EUR', $terms['currency']);
        self::assertSame(
            [
                [
                    'id' => 'line-1',
                    'description' => 'FusionGlow Sport',
                    'quantity' => 10,
                    'unit' => 'piece',
                    'unit_price' => 76000,
                    'total' => 760000,
                ],
            ],
            $terms['line_items'],
        );
    }

    public function testItSumsTotalValueAcrossMultipleLines(): void
    {
        // The single-line fixture leaves the summation path in fromSnapshot()
        // untested: total_value must be the sum of every line's total, not
        // just echo the one line it has.
        $snapshot = new QuoteSnapshot(
            identity: new QuoteIdentity(quoteId: 'quote-1', quoteNumber: 'Q-1001', currencyIso: 'EUR'),
            revision: new QuoteRevision('rev-1', new \DateTimeImmutable('2026-09-04T09:00:00+00:00')),
            totals: new QuoteTotals(totalNet: 11350.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'replied'),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity(lineItemId: 'line-1', label: 'FusionGlow Sport'),
                    quantity: 10,
                    unitPriceNet: 760.0,
                    totalNet: 7600.0,
                ),
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity(lineItemId: 'line-2', label: 'FusionGlow Pro'),
                    quantity: 5,
                    unitPriceNet: 750.0,
                    totalNet: 3750.0,
                ),
            ]),
        );

        $terms = self::factory()->fromSnapshot($snapshot);

        self::assertSame(760000, $terms['line_items'][0]['total']);
        self::assertSame(375000, $terms['line_items'][1]['total']);
        self::assertSame(1135000, $terms['total_value']);
    }

    public function testItRecordsTheReconciliationFiguresInCustomTerms(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot());

        self::assertSame(
            [
                'tax_status' => 'net',
                'quote_number' => 'Q-1001',
                'shopware_net_total_minor' => 760000,
            ],
            $terms['custom_terms'],
        );
    }

    public function testItOmitsNonPriceTermsTheQuoteDoesNotPersist(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot());

        foreach (['payment_terms', 'delivery_terms'] as $absent) {
            self::assertArrayNotHasKey($absent, $terms);
        }
        self::assertArrayNotHasKey('deposit_bps', $terms['custom_terms']);
    }

    public function testItFallsBackToTheLineIdWhenALineHasNoLabel(): void
    {
        $terms = self::factory()->fromSnapshot(self::snapshot(label: null));

        self::assertSame('line-1', $terms['line_items'][0]['description']);
    }

    public function testUnitPriceIsDerivedAndTotalIsAuthoritative(): void
    {
        // 3 x 33.34 net: the total is what the buyer is invoiced; the unit price
        // need not multiply back out exactly (A2CN determinism rule 3).
        $terms = self::factory()->fromSnapshot(self::snapshot(quantity: 3, unitNet: 33.34, totalNet: 100.01));

        self::assertSame(10001, $terms['line_items'][0]['total']);
        self::assertSame(3334, $terms['line_items'][0]['unit_price']);
    }

    public function testItComparesTermsByTheirSignedBytes(): void
    {
        $factory = self::factory();
        $terms = $factory->fromSnapshot(self::snapshot());

        self::assertTrue($factory->unchanged($terms, $terms));
        self::assertTrue($factory->unchanged(array_reverse($terms, preserve_keys: true), $terms));
        self::assertFalse($factory->unchanged(null, $terms));
        self::assertFalse($factory->unchanged($factory->fromSnapshot(self::snapshot(quantity: 9)), $terms));
    }

    private static function factory(): TermsFactory
    {
        return new TermsFactory(new ProtocolHash(new DefaultJsonCanonicalization()));
    }

    private static function snapshot(
        ?string $label = 'FusionGlow Sport',
        int $quantity = 10,
        float $unitNet = (76000.0 / 100) / 10,
        float $totalNet = 7600.0,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity(quoteId: 'quote-1', quoteNumber: 'Q-1001', currencyIso: 'EUR'),
            revision: new QuoteRevision('rev-1', new \DateTimeImmutable('2026-09-04T09:00:00+00:00')),
            totals: new QuoteTotals(totalNet: $totalNet),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'replied'),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity(lineItemId: 'line-1', label: $label),
                    quantity: $quantity,
                    unitPriceNet: $unitNet,
                    totalNet: $totalNet,
                ),
            ]),
        );
    }
}
