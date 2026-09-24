<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
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

/**
 * @mago-expect lint:too-many-methods
 * Every case here is a distinct fact about `apply()` -- what it writes, what
 * it reads before writing, what the verifier does with the result, and now
 * (#174) whether a write above the quote's current total fails verification. Each
 * one needs its own scenario; splitting the class would scatter one
 * behaviour's coverage for no reader's benefit, the same reasoning
 * `ReplyComposerTest` already carries this annotation for.
 */
final class OfferApplierTest extends TestCase
{
    private static function applier(): OfferApplier
    {
        return new OfferApplier(
            new OfferVerifier(),
            new NullLogger(),
            new DecisionRecorder(new FakeDecisionWriter()),
            new MarginFloorGuard(new FakePurchasePrices()),
        );
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

    public function testTheWrittenExpiryIsTheConfiguredValidityAheadOfTheWrite(): void
    {
        // #57 end to end. Asserting that *an* expiry was written is what the
        // suite did before, and `+0 days` satisfies that: the quote came back
        // stamped with an expiry of now and the buyer was told the offer was
        // valid until today. The value is the assertion.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        $expiresAt = $gateway->quoteUpdates[0]->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertSame((new \DateTimeImmutable('+14 days'))->format('Y-m-d'), $expiresAt->format('Y-m-d'));
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

        (new OfferApplier(
            new OfferVerifier(),
            new NullLogger(),
            $recorder,
            new MarginFloorGuard(new FakePurchasePrices()),
        ))->apply(
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

    /**
     * Issue #174, quote 1039's exact shape. The current total (1818.20) is
     * already below the stored baseline (2114.56) — an earlier round or a
     * human moved it there. The model prices "5% off" from the BASELINE,
     * landing on 2008.83 — legal against the baseline's 10% cap and a 190.63
     * INCREASE against what the buyer's quote showed before this pass. The
     * write itself already landed by the time this check runs (there is no
     * rollback); what must happen is that it fails verification instead of
     * being accepted as a discount, because the existing baseline-relative
     * check has nothing to say about it.
     */
    public function testAWriteThatRaisesAboveTheCurrentTotalFailsVerificationQuote1039(): void
    {
        $before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 1818.20),
            NegotiationFixture::baselineOf(2114.56, 211.456),
        );
        $after = NegotiationFixture::snapshot(totalNet: 2008.83);
        $gateway = new FakeQuoteGateway([$before, $after]);
        $offer = new ProposedOffer(orderTotalNet: 2114.56, price: new OfferedPrice(discountPercent: 5.0));

        $applied = self::applier()->apply($gateway, $before, NegotiationFixture::settings(), $offer);

        self::assertFalse(
            $applied->verified,
            'A write above the quote\'s current total must fail verification, even though it is a legal '
            . 'discount against the baseline.',
        );
        self::assertNotSame([], $applied->violations);
        self::assertSame(1818.20, $applied->beforeNet);
    }

    /** Issue #174, quote 1048's exact shape: same defect, a smaller and easier-to-miss gap. */
    public function testAWriteThatRaisesAboveTheCurrentTotalFailsVerificationQuote1048(): void
    {
        $before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 8065.58),
            NegotiationFixture::baselineOf(8314.65, 831.465),
        );
        $after = NegotiationFixture::snapshot(totalNet: 8148.63);
        $gateway = new FakeQuoteGateway([$before, $after]);
        $offer = new ProposedOffer(orderTotalNet: 8314.65, price: new OfferedPrice(discountPercent: 2.0));

        $applied = self::applier()->apply($gateway, $before, NegotiationFixture::settings(), $offer);

        self::assertFalse($applied->verified);
        self::assertNotSame([], $applied->violations);
    }

    /** A write that holds or lowers the current total must pass this check. */
    public function testAWriteAtOrBelowTheCurrentTotalPassesVerification(): void
    {
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 950.0),
        ]);

        $applied = self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        self::assertTrue($applied->verified);
        self::assertSame(1000.0, $applied->beforeNet);
    }
}
