<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

final class OfferApplierTest extends TestCase
{
    private static function applier(): OfferApplier
    {
        return new OfferApplier(new OfferVerifier(), new NullLogger(), new DecisionRecorder(new FakeDecisionWriter()));
    }

    private static function quoteWideOffer(): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));
    }

    public function testAQuoteWideOfferWritesADiscountAndAnExpiry(): void
    {
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertContains('updateQuote', $gateway->calls);
        self::assertContains('recalculate', $gateway->calls);
        self::assertNotContains('updateLineItems', $gateway->calls, 'A quote-wide offer must not write line prices.');
    }

    public function testAPerLineOfferWritesAbsoluteUnitPrices(): void
    {
        // Absolute, not a delta — that is what makes a retry idempotent.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(linePricesNet: [new QuoteLinePrice(
            'line-1',
            90.0,
        )]));

        self::applier()->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), $offer);

        self::assertContains('updateLineItems', $gateway->calls);
        self::assertSame(90.0, $gateway->lineItemChanges[0]->unitPriceNet);
    }

    public function testTheFirstWriteCarriesTheRevisionPrecondition(): void
    {
        // claim() itself writes (the open -> in_review transition), which
        // bumps the quote's revision. The precondition on the write below
        // must therefore be the POST-claim reference read, not the stale
        // pre-claim $snapshot — otherwise every quote's first pass would
        // throw a spurious QuoteRevisionMismatch.
        $postClaimRevision = new QuoteRevision('v2', new \DateTimeImmutable('2026-08-28 10:00:01.000'));
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(revision: $postClaimRevision)]);
        $snapshot = NegotiationFixture::snapshot();

        self::applier()->apply($gateway, $snapshot, NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertSame($postClaimRevision, $gateway->firstExpectedRevision);
        self::assertNotSame($snapshot->revision, $gateway->firstExpectedRevision);
    }

    public function testTheProcessTransitionIsAttemptedAndAnIllegalOneIsSurvived(): void
    {
        // Idempotency: a retry finds the quote already in_review. The
        // transition is bookkeeping; the offer is the substance.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $gateway->transitionThrows = new IllegalTransitionException('in_review', 'in_review', ['sent']);

        $applied = self::applier()
            ->apply(
                $gateway,
                NegotiationFixture::snapshot(state: 'in_review'),
                NegotiationFixture::settings(),
                self::quoteWideOffer(),
            );

        self::assertTrue($applied->verified, 'An illegal transition must not fail the pass.');
        self::assertContains('updateQuote', $gateway->calls);
    }

    public function testAnAlreadyClaimedRetryDoesNotReportAClaimWrite(): void
    {
        // claim() swallows an IllegalTransitionException on a retry that finds
        // the quote already in_review — no transition actually happened, so
        // the audit trail must not claim one did.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
        $gateway->transitionThrows = new IllegalTransitionException('in_review', 'in_review', ['sent']);
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(state: 'in_review'), NegotiationFixture::context());

        (new OfferApplier(new OfferVerifier(), new NullLogger(), $recorder))->apply(
            $gateway,
            NegotiationFixture::snapshot(state: 'in_review'),
            NegotiationFixture::settings(),
            self::quoteWideOffer(),
        );
        $recorder->finish(null);

        self::assertNotContains('claim', $writer->drafts[0]->writes);
    }

    public function testItVerifiesAgainstWhatTheDatabaseSaysNotWhatWeIntended(): void
    {
        // The re-read is a SECOND snapshot, deliberately different: the
        // verifier must see the database, not the offer we built.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(),
            NegotiationFixture::snapshot(totalNet: 950.0),
        ]);

        $applied = self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertSame(950.0, $applied->after->totals->totalNet);
    }

    public function testAVerificationViolationIsReportedRatherThanRolledBack(): void
    {
        // 1000 -> 500 is a 50% cut against a 10% cap: the verifier must object,
        // and the changes must stay for a human to see.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(),
            NegotiationFixture::snapshot(totalNet: 500.0),
        ]);

        $applied = self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertFalse($applied->verified);
        self::assertNotSame([], $applied->violations);
        self::assertNotContains('rollback', $gateway->calls);
    }
}
