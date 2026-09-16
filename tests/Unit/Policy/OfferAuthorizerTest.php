<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferAuthorizerTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $offer = self::offerFromArray($input['offer']);
        $policy = NegotiationPolicy::fromArray($input['policy']);

        $authorization = (new OfferAuthorizer())->authorize($offer, $policy);

        self::assertSame($expected['approved'], $authorization->approved, $description);
        self::assertSame($expected['violations'], $authorization->violations, $description);
    }

    public static function fixtures(): iterable
    {
        yield from self::readFixtureFile('offer-authorize.json');
        yield from self::readFixtureFile('offer-authorize-compounding.json');
    }

    /**
     * #56. A negative discount is written as a SwagCommercial percentage
     * discount and lands on the quote as a SURCHARGE, while
     * ReplyTemplate::reduction() floors its reported figure at 0 — so the
     * buyer reads "came down by 0%" on a quote that went up. The cap was
     * one-sided by construction and bounded nothing below.
     */
    public function testANegativeDiscountIsRefused(): void
    {
        $authorization = (new OfferAuthorizer())->authorize(
            new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: -5.0)),
            self::policy(),
        );

        self::assertFalse($authorization->approved);
    }

    public function testZeroIsNotANegativeDiscount(): void
    {
        $authorization = (new OfferAuthorizer())->authorize(
            new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 0.0)),
            self::policy(),
        );

        self::assertTrue($authorization->approved);
    }

    /**
     * This file has no other bare policy builder — every other case reads
     * `input.policy` from a fixture — so this is the plain construction the
     * fixture path already uses under the hood (`NegotiationPolicy::fromArray`).
     */
    private static function policy(): NegotiationPolicy
    {
        return NegotiationPolicy::fromArray(['price' => ['maxDiscountPercent' => 10, 'validityDays' => 14]]);
    }

    private static function readFixtureFile(string $filename): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/' . $filename),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $filename . ': ' . $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }

    /**
     * The fixtures carry the TS field name `quoteLines` (the quote's current
     * lines); this suite only exercises single-round and pre-anchored
     * multi-round scenarios, so it maps 1:1 onto the port's `referenceLines`
     * (the pre-negotiation snapshot — see LinePriceOfferCheck).
     */
    private static function offerFromArray(array $data): ProposedOffer
    {
        $linePricesNet = array_map(
            static fn(array $p): QuoteLinePrice => new QuoteLinePrice($p['lineItemId'], (float) $p['unitPriceNet']),
            $data['linePricesNet'] ?? [],
        );
        $referenceLines = array_map(QuoteLineSnapshot::fromArray(...), $data['quoteLines'] ?? []);

        return new ProposedOffer(
            orderTotalNet: (float) $data['orderTotalNet'],
            price: new OfferedPrice(linePricesNet: $linePricesNet, referenceLines: $referenceLines),
        );
    }
}
