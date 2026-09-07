<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteValueCeiling;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use PHPUnit\Framework\TestCase;

final class SellerMandateFactoryTest extends TestCase
{
    private const VALID_FROM = '2026-09-04T10:00:00+00:00';

    public function testItBuildsTheDeclaredMandateEnvelope(): void
    {
        $mandate = self::build(new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0)));

        self::assertSame('declared', $mandate['mandate_type']);
        self::assertSame('merchant-quote-agent', $mandate['agent_id']);
        self::assertSame('Example Shop', $mandate['principal_organization']);
        self::assertSame('did:web:shop.example', $mandate['principal_did']);
        self::assertSame(['goods_procurement'], $mandate['authorized_deal_types']);
    }

    public function testValidFromAndValidUntilAreOneYearApart(): void
    {
        $mandate = self::build(new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0)));

        self::assertSame('2026-09-04T10:00:00Z', $mandate['valid_from']);
        self::assertSame('2027-09-04T10:00:00Z', $mandate['valid_until']);
    }

    public function testMaxCommitmentIsAbsentWithoutAValueCeiling(): void
    {
        $mandate = self::build(new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0)));

        self::assertArrayNotHasKey('max_commitment_value', $mandate);
        self::assertArrayNotHasKey('max_commitment_currency', $mandate);
    }

    public function testMaxCommitmentIsInMinorUnitsFromTheValueCeiling(): void
    {
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: 15.0,
                valueCeiling: new QuoteValueCeiling(net: 7600.0, currencyIso: 'EUR'),
            )),
        );

        self::assertSame(760000, $mandate['max_commitment_value']);
        self::assertSame('EUR', $mandate['max_commitment_currency']);
    }

    public function testNegotiationBandsPublishTheGrantAndCounterBoundsInBasisPoints(): void
    {
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, counterOfferMaxPercent: 20.0)),
        );

        $bands = $mandate['negotiation_bands'];
        self::assertSame(1500, $bands['autoGrantMaxBps']);
        self::assertSame(2000, $bands['counterAtBps']);
        self::assertSame($bands['autoGrantMaxBps'], $bands['escalateAboveBps']);
    }

    public function testNegotiationBandsPublishPaymentAuthorityWhenConfigured(): void
    {
        $mandate = self::build(new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 15.0),
            payment: new PaymentPolicy(allowedTerms: [PaymentTerm::Net30], maxNetDays: 30, minDepositPercent: 10.0),
        ));

        self::assertSame(['net_30'], $mandate['negotiation_bands']['payment']['allowedTerms']);
        self::assertSame(30, $mandate['negotiation_bands']['payment']['maxNetDays']);
        self::assertSame(1000, $mandate['negotiation_bands']['payment']['minDepositBps']);
    }

    /** @return array<string, mixed> */
    private static function build(NegotiationPolicy $policy): array
    {
        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');

        return (new SellerMandateFactory())->build($policy, $identity, new \DateTimeImmutable(self::VALID_FROM));
    }
}
