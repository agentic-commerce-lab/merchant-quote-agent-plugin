<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Policy\NetFactor;
use PHPUnit\Framework\TestCase;

/**
 * Issue #49. The baseline is what stops a multi-round per-line negotiation
 * compounding past the cap, so what it stores and what it refuses to parse
 * both matter.
 */
final class QuoteBaselineTest extends TestCase
{
    public function testItRoundTripsPricesQuantitiesAndTheTotal(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));

        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));

        self::assertNotNull($baseline);
        self::assertSame(1000.0, $baseline->totalNet);
        self::assertCount(2, $baseline->lines);
        self::assertSame('line-1', $baseline->lines[0]->lineItemId());
        self::assertSame(100.0, $baseline->lines[0]->unitPriceNet);
        self::assertSame(4, $baseline->lines[0]->quantity);
    }

    /**
     * Custom fields survive the database as JSON, so a float written as 100.0
     * can come back as the integer 100. Reading must not reject that.
     */
    public function testItAcceptsWholeNumbersThatJsonReturnedAsIntegers(): void
    {
        $baseline = QuoteBaseline::read(self::snapshot(customFields: [
            QuoteBaseline::KEY => [
                'totalNet' => 1000,
                'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => 100, 'quantity' => 4]],
            ],
        ]));

        self::assertNotNull($baseline);
        self::assertSame(1000.0, $baseline->totalNet);
        self::assertSame(100.0, $baseline->lines[0]->unitPriceNet);
    }

    public function testAnAbsentBaselineReadsAsNull(): void
    {
        self::assertNull(QuoteBaseline::read(self::snapshot(customFields: [])));
    }

    /**
     * A baseline nobody can parse must not become a partial list — a partial
     * list is a smaller cap than the merchant set, silently.
     */
    public function testAMalformedBaselineReadsAsNullRatherThanAPartialList(): void
    {
        foreach ([
            'not an array' => [QuoteBaseline::KEY => 'nonsense'],
            'no total' => [
                QuoteBaseline::KEY => ['lines' => [['lineItemId' => 'a', 'unitPriceNet' => 1, 'quantity' => 1]]],
            ],
            'no lines' => [QuoteBaseline::KEY => ['totalNet' => 10.0]],
            'empty lines' => [QuoteBaseline::KEY => ['totalNet' => 10.0, 'lines' => []]],
            'row missing quantity' => [
                QuoteBaseline::KEY => [
                    'totalNet' => 10.0,
                    'lines' => [['lineItemId' => 'a', 'unitPriceNet' => 1]],
                ],
            ],
            'one bad row among good ones' => [
                QuoteBaseline::KEY => [
                    'totalNet' => 10.0,
                    'lines' => [
                        ['lineItemId' => 'a', 'unitPriceNet' => 1, 'quantity' => 1],
                        ['lineItemId' => 42, 'unitPriceNet' => 1, 'quantity' => 1],
                    ],
                ],
            ],
        ] as $case => $customFields) {
            self::assertNull(
                QuoteBaseline::read(self::snapshot(customFields: $customFields)),
                sprintf('"%s" was parsed instead of rejected.', $case),
            );
        }
    }

    /**
     * The reason quantities and totalNet are stored at all. NetFactor divides
     * totalNet by the sum of unitPriceNet * quantity to normalise gross-vs-net
     * price space, so a baseline that dropped quantities would produce a
     * different factor and a meaningless per-line comparison. This test fails
     * if anyone later "simplifies" the stored shape back to prices alone.
     */
    public function testTheStoredShapeKeepsTheNetFactorCoherent(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));
        self::assertNotNull($baseline);

        $live = self::policySnapshot();
        $reference = $baseline->asReferenceSnapshot($live);

        // 4 * 100 + 2 * 300 = 1000, and totalNet is 1000, so the factor is 1.0.
        self::assertSame(1.0, NetFactor::of($reference));

        $withoutQuantities = new \MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1000.0,
            lines: array_map(
                static fn(\MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot $l) => new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
                    identity: $l->identity,
                    quantity: 1,
                    unitPriceNet: $l->unitPriceNet,
                ),
                $baseline->lines,
            ),
            lifecycle: $live->lifecycle,
        );

        self::assertNotSame(
            NetFactor::of($reference),
            NetFactor::of($withoutQuantities),
            'Dropping quantities changed nothing, so the shape is not actually load-bearing.',
        );
    }

    /**
     * A line the buyer added after the agent started has no baseline entry.
     * Its current price is its baseline — it has had no concession yet — and
     * without this LinePriceOfferCheck would reject it as "not on this
     * quote".
     */
    public function testALineAddedMidNegotiationTakesItsCurrentPriceAsItsOwnBaseline(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));
        self::assertNotNull($baseline);

        $added = new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot(
            identity: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity('line-3'),
            quantity: 1,
            unitPriceNet: 50.0,
        );

        $merged = $baseline->linesMergedWith([$added]);

        self::assertCount(3, $merged);
        self::assertSame('line-3', $merged[2]->lineItemId());
        self::assertSame(50.0, $merged[2]->unitPriceNet);
        self::assertSame(
            100.0,
            $merged[0]->unitPriceNet,
            'A known line must keep its BASELINE price, not its current one.',
        );
    }

    /** Prices come from the baseline; currency and lifecycle come from now. */
    public function testTheReferenceSnapshotTakesPricesFromTheBaselineAndTheRestFromTheLiveQuote(): void
    {
        $stamped = QuoteBaseline::stamp(self::snapshot(customFields: []));
        $baseline = QuoteBaseline::read(self::snapshot(customFields: $stamped));
        self::assertNotNull($baseline);

        $live = self::policySnapshot(totalNet: 700.0, currencyIso: 'CHF');
        $reference = $baseline->asReferenceSnapshot($live);

        self::assertSame(1000.0, $reference->totalNet, 'The reference must keep the ORIGINAL total.');
        self::assertSame('CHF', $reference->currencyIso);
        self::assertSame($live->lifecycle, $reference->lifecycle);
    }

    /** @param array<string, mixed> $customFields */
    private static function snapshot(array $customFields): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-09-01 10:00:00')),
            totals: new QuoteTotals(totalNet: 1000.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open', customFields: $customFields),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget'),
                    quantity: 4,
                    unitPriceNet: 100.0,
                    totalNet: 400.0,
                ),
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-2', 'Gadget'),
                    quantity: 2,
                    unitPriceNet: 300.0,
                    totalNet: 600.0,
                ),
            ]),
        );
    }

    private static function policySnapshot(
        float $totalNet = 1000.0,
        string $currencyIso = 'EUR',
    ): \MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot {
        return new \MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot(
            currencyIso: $currencyIso,
            totalNet: $totalNet,
            lines: [],
            lifecycle: new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle(stateTechnicalName: 'open'),
        );
    }
}
