<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteValueCeiling;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use PHPUnit\Framework\TestCase;

/**
 * @mago-expect lint:too-many-methods
 * Eleven cases plus one private helper. The envelope and its validity window
 * are two; the rest are the commitment ceiling, which has one behaviour per
 * way a merchant can configure it — no ceiling, one per currency, several, a
 * bare number anchored by the storefront, a currency with none of its own —
 * plus the tax basis and the negotiation bands. Every one is a distinct
 * statement in a signed document.
 */
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
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, valueCeiling: new QuoteValueCeiling([
                'EUR' => 7600.0,
            ]))),
        );

        self::assertSame(760000, $mandate['max_commitment_value']);
        self::assertSame('EUR', $mandate['max_commitment_currency']);
    }

    public function testTheCommitmentDeclaresTheTaxBasisItIsDenominatedIn(): void
    {
        // The configured ceiling is a NET total. Gross and net differ by the
        // VAT rate, so a ceiling published without saying which it is can be
        // read ~20% wrong by a European buyer.
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, valueCeiling: new QuoteValueCeiling([
                'EUR' => 7600.0,
            ]))),
        );

        self::assertSame('net', $mandate['max_commitment_basis']);
    }

    public function testNoBasisIsClaimedWithoutACommitment(): void
    {
        self::assertArrayNotHasKey(
            'max_commitment_basis',
            self::build(new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0))),
        );
    }

    public function testTheStorefrontsOwnCurrencyAnchorsACurrencyAgnosticCeiling(): void
    {
        // The admin's plain number means "this ceiling, whatever the
        // currency". The mandate is served per storefront and a storefront
        // trades in one currency, so that is the currency the reader is
        // negotiating in and the honest one to name.
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, valueCeiling: new QuoteValueCeiling([
                QuoteValueCeiling::ANY_CURRENCY => 50000.0,
            ]))),
            currencyIso: 'EUR',
        );

        self::assertSame(5000000, $mandate['max_commitment_value']);
        self::assertSame('EUR', $mandate['max_commitment_currency']);
        self::assertSame('net', $mandate['max_commitment_basis']);
    }

    public function testTheStorefrontsOwnCurrencyPicksItsCeilingOutOfAPerCurrencyMap(): void
    {
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, valueCeiling: new QuoteValueCeiling([
                'EUR' => 7600.0,
                'USD' => 8200.0,
            ]))),
            currencyIso: 'USD',
        );

        self::assertSame(820000, $mandate['max_commitment_value']);
        self::assertSame('USD', $mandate['max_commitment_currency']);
    }

    public function testACurrencyWithNoCeilingOfItsOwnClaimsNothing(): void
    {
        // netFor() returns null for an unlisted currency and both call sites
        // escalate. Publishing another currency's number here would be a
        // signed claim about a limit that does not apply.
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, valueCeiling: new QuoteValueCeiling([
                'EUR' => 7600.0,
            ]))),
            currencyIso: 'GBP',
        );

        self::assertArrayNotHasKey('max_commitment_value', $mandate);
    }

    public function testTwoPerCurrencyCeilingsPublishNoMaxCommitmentAtAll(): void
    {
        // `max_commitment_value` and `max_commitment_currency` are scalars in
        // the A2CN spec. With a ceiling per currency there is no single honest
        // pair, and a signed document that picks one arbitrarily is worse than
        // one that claims nothing.
        $mandate = self::build(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, valueCeiling: new QuoteValueCeiling([
                'EUR' => 7600.0,
                'USD' => 8200.0,
            ]))),
        );

        self::assertArrayNotHasKey('max_commitment_value', $mandate);
        self::assertArrayNotHasKey('max_commitment_currency', $mandate);
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

    public function testTheMandatePublishesPriceAuthorityOnly(): void
    {
        // The signed document used to carry payment, delivery and bundle
        // bands. The agent decides none of them -- AskGate escalates every
        // non-price ask, and QuoteUpdate cannot write a term -- so each claim
        // was unbacked.
        $mandate = self::build(new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0)));

        self::assertArrayNotHasKey('payment', $mandate['negotiation_bands']);
        self::assertArrayNotHasKey('delivery', $mandate['negotiation_bands']);
        self::assertArrayNotHasKey('bundle', $mandate['negotiation_bands']);
        self::assertSame(1500, $mandate['negotiation_bands']['autoGrantMaxBps']);
    }

    /** @return array<string, mixed> */
    private static function build(NegotiationPolicy $policy, ?string $currencyIso = null): array
    {
        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');

        return (new SellerMandateFactory())->build(
            $policy,
            $identity,
            new \DateTimeImmutable(self::VALID_FROM),
            $currencyIso,
        );
    }
}
