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
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
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
    }

    public function testAnOutOfAuthorityAskEscalatesWithoutTouchingPrices(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'I need 40% off.');
        $before = $gateway->fetchSnapshot($quoteId);

        $pipeline = self::pipelineWith(['{"additional_discount_percent": 40}']);

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
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can offer 5% off.',
        ];

        self::pipelineWith($replies)
            ->service(
                $gateway->fetchSnapshot($quoteId),
                $gateway,
                self::enabledSettings(),
                NegotiationFixture::context(),
            );
        $afterFirst = \count($gateway->fetchSnapshot($quoteId)->content->comments);

        self::pipelineWith($replies)
            ->service(
                $gateway->fetchSnapshot($quoteId),
                $gateway,
                self::enabledSettings(),
                NegotiationFixture::context(),
            );

        self::assertSame(
            $afterFirst,
            \count($gateway->fetchSnapshot($quoteId)->content->comments),
            'A re-run posted a second message to the buyer.',
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
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
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
