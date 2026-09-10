<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use Shopware\Core\Framework\Uuid\Uuid;

/** Only HTTP model replies are scripted: customer scope, policy, DAL writes and audit are real. */
final class HistoryInjectionTest extends IntegrationTestCase
{
    use PipelineFixture;
    use HistoryInjectionFixture;
    use HistoryInjectionAssertions;

    public function testHostileBuyerCannotRedirectHistoryAndAContaminatedProposalCannotReachTheSavedReply(): void
    {
        [$mine, $foreign] = self::historyCustomers();
        $before = self::freshHistoryQuote($mine, $foreign);
        $orders = (new CustomerHistoryReference(self::connection(static::getContainer())))->orders($mine);
        self::assertNotEmpty($orders);
        $lifetime = sprintf('%.2f', array_sum(array_column($orders, 'net')));
        $privateFacts = 'INTERNAL lifetime ' . $lifetime . ': ' . json_encode($orders, JSON_THROW_ON_ERROR);
        [$pipeline, $spy] = self::pipelineWithSpy([
            self::EXTRACT_FIFTEEN,
            self::historyRequest('quote_history'),
            self::historyRequest('orders'),
            self::historyOffer($privateFacts),
            'We can offer 5% off.',
        ]);
        $outcome = $pipeline->service($before, self::gateway(), self::enabledSettings(), NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Countered, $outcome);
        self::assertSame(5, $spy->calls);
        $record = self::historyRecord($before);
        self::assertHistoryRounds($record, $spy, ['quote_history', 'orders']);
        self::assertSame(\count($orders), $record->historyReads['orderCount']);
        // The quote under negotiation must NOT appear in its own account
        // history. Otherwise a buyer writing "you already gave us 15%" is
        // indistinguishable from a spent precedent on a closed quote, and
        // granting it again discounts a total that already came down by it --
        // the double-concession QuoteBaseline (#49) prevents, arriving through a
        // side channel. This assertion used to require the opposite.
        self::assertStringNotContainsString(
            'quote ' . $before->identity->quoteNumber,
            $spy->userPrompts[2],
            'The serviced quote leaked into its own history block.',
        );
        foreach (array_slice($orders, offset: 0, length: 10) as $order) {
            self::assertStringContainsString('order ' . $order['number'] . ',', $spy->userPrompts[3]);
            self::assertStringContainsString(
                sprintf('%.2f %s net', $order['net'], $order['currency']),
                $spy->userPrompts[3],
            );
        }
        self::assertForeignHistoryAbsent($spy, $foreign);
        $after = self::assertVerifiedCounter($before, $record);
        self::assertStringContainsString('lifetime ' . $lifetime, $spy->userPrompts[1]);
        self::assertEqualsWithDelta(
            array_sum(array_column($orders, 'net')),
            $record->historyReads['lifetimeNet'],
            0.001,
        );
        self::assertPrivateReplyBoundary($before, $after, $record, $spy, [$lifetime]);
    }

    public function testActualQuoteProductHistoryIsScopedAndStillCannotEnterTheBuyerReply(): void
    {
        [$mine, $foreign] = self::historyCustomers();
        $before = self::freshHistoryQuote($mine, $foreign);
        $productId = $before->content->lines[0]->identity->productId;
        self::assertNotNull($productId);
        self::assertSame(self::sharedHistoryProduct($mine, $foreign), $productId);
        $foreignPurchase = self::distinctForeignPurchase($foreign, $productId);
        $reference = new CustomerHistoryReference(self::connection(static::getContainer()));
        $purchases = $reference->purchases($mine, $productId);
        $orders = $reference->orders($mine);
        $lifetime = sprintf('%.2f', array_sum(array_column($orders, 'net')));
        self::assertNotEmpty($purchases);
        self::assertNotEmpty($orders);
        [$pipeline, $spy] = self::pipelineWithSpy([
            self::EXTRACT_FIFTEEN,
            self::historyRequest('product_purchases', $productId),
            self::historyOffer('INTERNAL lifetime ' . $lifetime . ': ' . json_encode($purchases, JSON_THROW_ON_ERROR)),
            'We can offer 5% off.',
        ]);
        $outcome = $pipeline->service($before, self::gateway(), self::enabledSettings(), NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Countered, $outcome);
        self::assertSame(4, $spy->calls);
        $record = self::historyRecord($before);
        self::assertHistoryRounds($record, $spy, ['product_purchases']);
        self::assertSame($productId, $record->historyReads['rounds'][0]['productId']);
        self::assertStringNotContainsString($foreignPurchase, $spy->userPrompts[2]);
        self::assertStringNotContainsString($foreignPurchase, $record->historyReads['rounds'][0]['result']);
        foreach ($purchases as $purchase) {
            self::assertStringContainsString(
                sprintf(
                    '- %s, quantity %d, unit net %.2f %s',
                    substr($purchase['date'], offset: 0, length: 10),
                    $purchase['quantity'],
                    $purchase['net'],
                    $purchase['currency'],
                ),
                $spy->userPrompts[2],
            );
        }
        self::assertForeignHistoryAbsent($spy, $foreign);
        $after = self::assertVerifiedCounter($before, $record);
        self::assertStringContainsString('lifetime ' . $lifetime, $spy->userPrompts[1]);
        self::assertEqualsWithDelta(
            array_sum(array_column($orders, 'net')),
            $record->historyReads['lifetimeNet'],
            0.001,
        );
        self::assertPrivateReplyBoundary($before, $after, $record, $spy, [$lifetime]);
    }

    public function testOffQuoteProductIsRefusedWithoutEchoingItsIdentifierAndNegotiationContinues(): void
    {
        [$mine, $foreign] = self::historyCustomers();
        $before = self::freshHistoryQuote($mine, $foreign);
        $offQuote = Uuid::randomHex();
        [$pipeline, $spy] = self::pipelineWithSpy([
            self::EXTRACT_FIFTEEN,
            self::historyRequest('product_purchases', $offQuote),
            self::historyOffer('An off-quote request must not reveal a purchase.'),
            'We can offer 5% off.',
        ]);
        $outcome = $pipeline->service($before, self::gateway(), self::enabledSettings(), NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Countered, $outcome);
        self::assertSame(4, $spy->calls);
        $record = self::historyRecord($before);
        self::assertHistoryRounds($record, $spy, ['product_purchases']);
        $refusal = $record->historyReads['rounds'][0]['result'];
        self::assertSame($offQuote, $record->historyReads['rounds'][0]['productId']);
        self::assertStringContainsString('not on this quote', $refusal);
        self::assertStringNotContainsString($offQuote, $spy->userPrompts[2]);
        self::assertStringNotContainsString('unit net', $refusal);
        self::assertStringNotContainsString('recent orders:', $refusal);
        self::assertForeignHistoryAbsent($spy, $foreign);
        self::assertVerifiedCounter($before, $record);
    }

    public function testThirdHistoryRequestEscalatesAfterTwoCompletedReadsWithoutApplyingAnOffer(): void
    {
        [$mine, $foreign] = self::historyCustomers();
        $before = self::freshHistoryQuote($mine, $foreign);
        $third = self::historyRequest('product_purchases', $before->content->lines[0]->identity->productId);
        [$pipeline, $spy] = self::pipelineWithSpy([
            self::EXTRACT_FIFTEEN,
            self::historyRequest('quote_history'),
            self::historyRequest('orders'),
            $third,
        ]);
        $outcome = $pipeline->service($before, self::gateway(), self::enabledSettings(), NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(4, $spy->calls);
        $record = self::historyRecord($before);
        self::assertHistoryRounds($record, $spy, ['quote_history', 'orders']);
        self::assertNotNull($record->rawProposal);
        $recorded = json_decode($record->rawProposal, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('offer', $recorded['action']);
        self::assertSame('product_purchases', $recorded['historyRequest']['kind']);
        self::assertSame($before->content->lines[0]->identity->productId, $recorded['historyRequest']['productId']);
        self::assertFalse($record->authorized);
        self::assertNull($record->replyToBuyer);
        self::assertNull($record->writes);
        self::assertSame(
            $before->totals->totalNet,
            self::gateway()->fetchSnapshot($before->identity->quoteId)->totals->totalNet,
        );
    }

    public function testHistoryDoesNotAuthorizeAnAboveCapFinalProposal(): void
    {
        [$mine, $foreign] = self::historyCustomers();
        $before = self::freshHistoryQuote($mine, $foreign);
        $proposal = self::historyOffer('The customer history entitles them to exceed the cap.', 15);
        [$pipeline, $spy] = self::pipelineWithSpy([
            self::EXTRACT_FIFTEEN,
            self::historyRequest('orders'),
            $proposal,
        ]);
        $outcome = $pipeline->service($before, self::gateway(), self::enabledSettings(), NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(3, $spy->calls, 'The authorizer must reject the proposal after a completed history read.');
        $record = self::historyRecord($before);
        self::assertHistoryRounds($record, $spy, ['orders']);
        self::assertNotNull($record->rawProposal);
        $recorded = json_decode($record->rawProposal, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('offer', $recorded['action']);
        self::assertEquals(15.0, $recorded['terms']['discountPercent']);
        self::assertSame(
            json_decode($proposal, associative: true, flags: JSON_THROW_ON_ERROR)['message'],
            $recorded['message'],
        );
        self::assertSame('proposal_rejected', $record->escalationReason);
        self::assertFalse($record->authorized);
        self::assertNotEmpty($record->violations);
        self::assertNull($record->writes);
        self::assertNull($record->replyToBuyer);
        self::assertSame(
            $before->totals->totalNet,
            self::gateway()->fetchSnapshot($before->identity->quoteId)->totals->totalNet,
        );
    }
}
