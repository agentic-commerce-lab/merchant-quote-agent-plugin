<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedDelivery;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPayment;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
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
            delivery: new OfferedDelivery(),
            payment: new OfferedPayment(),
        );
    }
}
