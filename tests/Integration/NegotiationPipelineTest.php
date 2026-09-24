<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use Shopware\Core\Framework\Context;

/**
 * The pipeline against real quotes, real writes and the real verifier. Only
 * the model is scripted — everything else is the shop.
 */
final class NegotiationPipelineTest extends IntegrationTestCase
{
    use PipelineFixture;

    public function testAnInBandAskIsAppliedToTheQuoteAndAnswered(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $before = $gateway->fetchSnapshot($quoteId);
        $commentsBefore = \count($before->content->comments);

        // A buyer ask has to exist for the pass to do anything.
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $pipeline = self::pipelineWith([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            self::reworded(...),
        ]);

        $outcome = $pipeline->service(
            $gateway->fetchSnapshot($quoteId),
            $gateway,
            self::enabledSettings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertGreaterThan($commentsBefore, \count($after->content->comments), 'The buyer was never answered.');
        self::assertFalse($after->revision->matches($before->revision), 'Nothing was written to the quote.');

        // Measured against what the quote already said, not against zero:
        // QuoteFixture hands out a real shop quote, and the one this suite
        // settles on has carried an agent comment from an earlier life since
        // before any of this. The pass's own reply is the difference.
        self::assertSame(
            [self::reworded(self::replyTemplateFor($before, $after))],
            self::agentCommentsAdded($before, $after),
            'The buyer got the deterministic template; the reworded reply never reached them.',
        );
    }

    public function testAnOutOfAuthorityAskEscalatesWithoutTouchingPrices(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'I need 40% off.');
        $before = $gateway->fetchSnapshot($quoteId);

        $pipeline = self::pipelineWith(['{"price":{"additionalDiscountPercent":40}}']);

        $outcome = $pipeline->service(
            $gateway->fetchSnapshot($quoteId),
            $gateway,
            self::enabledSettings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(
            $before->totals->totalNet,
            $gateway->fetchSnapshot($quoteId)->totals->totalNet,
            'An escalated ask must not move the price.',
        );
    }

    public function testTheSamePassTwiceAnswersOnce(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $replies = [
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            self::reworded(...),
        ];

        $before = $gateway->fetchSnapshot($quoteId);

        self::pipelineWith($replies)
            ->service($before, $gateway, self::enabledSettings(), NegotiationFixture::context());
        $after = $gateway->fetchSnapshot($quoteId);
        $afterFirst = \count($after->content->comments);

        self::pipelineWith($replies)->service($after, $gateway, self::enabledSettings(), NegotiationFixture::context());

        self::assertSame(
            $afterFirst,
            \count($gateway->fetchSnapshot($quoteId)->content->comments),
            'A re-run posted a second message to the buyer.',
        );

        // The subject of this test is that the SECOND pass says nothing; this
        // is the other half of it, that the first pass said the right thing.
        // Without it a rewording silently replaced by the template still
        // counts as "answered once".
        self::assertSame(
            [self::reworded(self::replyTemplateFor($before, $after))],
            self::agentCommentsAdded($before, $after),
            'The buyer got the deterministic template; the reworded reply never reached them.',
        );
    }

    public function testAReplyPostedByADeadPassStillReachesReplied(): void
    {
        // #31, on the shop rather than against a fake: the reply is two writes,
        // and a worker dying between them used to strand the quote in
        // `in_review` for good — the retry reads the agent's own comment as
        // the newest, so the interpreter returns null and nothing was left to
        // finish the transition.
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInStateWithoutUnmetPriceAsk(
            static::getContainer(),
            Context::createDefaultContext(),
            'open',
            $gateway,
        );
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        // Exactly what a dead pass leaves behind: the quote moved to in_review
        // and the buyer answered, but never the `sent` transition after it.
        $gateway->transition($quoteId, QuoteTransition::Process);
        $gateway->addComment($quoteId, 'We can offer 5% off.');

        $stranded = $gateway->fetchSnapshot($quoteId);
        self::assertSame('in_review', $stranded->lifecycle->stateTechnicalName);

        self::pipelineWith([])->service($stranded, $gateway, self::enabledSettings(), NegotiationFixture::context());

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertSame('replied', $after->lifecycle->stateTechnicalName);
        self::assertCount(
            \count($stranded->content->comments),
            $after->content->comments,
            'Finishing the transition must not say anything further to the buyer.',
        );
    }
}
