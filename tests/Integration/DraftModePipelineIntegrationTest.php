<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStore;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Review\DraftModePipeline;
use MerchantQuoteAgentPlugin\Review\DraftPreviewer;
use MerchantQuoteAgentPlugin\Review\DraftRejecter;
use MerchantQuoteAgentPlugin\Review\DraftReply;
use MerchantQuoteAgentPlugin\Review\DraftReviewController;
use MerchantQuoteAgentPlugin\Review\DraftSendCompletion;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Review\RecordingNotifier;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

/** The real servicing pass, preview endpoint and send path on an installed shop. */
final class DraftModePipelineIntegrationTest extends IntegrationTestCase
{
    use PipelineFixture;

    public function testRealPassPreviewsAndSendsWithoutASecondDraft(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInStateWithoutUnmetPriceAsk(
            static::getContainer(),
            Context::createDefaultContext(),
            'open',
            $gateway,
        );
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');
        $before = $gateway->fetchSnapshot($quoteId);
        $store = new DecisionReviewStore(static::getContainer()->get('merchant_quote_agent_decision.repository'));
        $versions = new QuoteDraftVersions(
            static::getContainer()->get('quote.repository'),
            static::getContainer()->get('version.repository'),
            static::gatewayFactory(),
            static::getContainer()->get(Connection::class),
            new NullLogger(),
        );
        $baseSettings = self::enabledSettings();
        $settings = new QuoteAgentSettings(
            $baseSettings->policy,
            $baseSettings->llm,
            $baseSettings->strategyPrompt,
            draftMode: true,
        );
        $inner = self::pipelineWith([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            self::reworded(...),
        ]);
        $notifier = new RecordingNotifier();
        $pipeline = new DraftModePipeline(
            $inner,
            $versions,
            static::getContainer()->get(DecisionRecorder::class),
            $store,
            $notifier,
        );

        self::assertSame(NegotiationOutcome::Offered, $pipeline->service(
            $before,
            $gateway,
            $settings,
            NegotiationFixture::context(),
        ));
        self::assertSame($before->totals->totalNet, $gateway->fetchSnapshot($quoteId)->totals->totalNet);
        $decisionId = static::getContainer()
            ->get(Connection::class)
            ->fetchOne(
                'SELECT LOWER(HEX(id)) FROM merchant_quote_agent_decision WHERE quote_id = UNHEX(:quoteId) ORDER BY created_at DESC LIMIT 1',
                ['quoteId' => $quoteId],
            );
        self::assertIsString($decisionId);
        $record = $store->find($decisionId);
        self::assertSame('pending', $record?->reviewStatus);
        self::assertNotNull($record->draftVersionId);
        self::assertTrue($versions->exists($quoteId, $record->draftVersionId));

        $drafts = new PendingDrafts(
            $store,
            $versions,
            $gateway,
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock'),
        );
        $controller = new DraftReviewController(
            $drafts,
            new DraftPreviewer(
                static::getContainer()->get(Connection::class),
                static::getContainer()->get(DraftReply::class),
                $store,
            ),
            new DraftSender(
                $versions,
                static::gatewayFactory(),
                new DraftSendCompletion($store, new NullLogger()),
                static::getContainer()->get(Connection::class),
            ),
            new DraftRejecter($versions, $gateway, $store),
            $store,
        );
        $day = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $preview = Request::create(
            '/preview',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['expiresAt' => $day], JSON_THROW_ON_ERROR),
        );
        self::assertSame(200, $controller->preview($decisionId, $preview)->getStatusCode());
        self::assertNotSame($day, $gateway->fetchSnapshot($quoteId)->lifecycle->expiresAt?->format('Y-m-d'));
        self::assertSame(
            $day,
            $versions
                ->gateway($record->draftVersionId)
                ->fetchSnapshot($quoteId)
                ->lifecycle->expiresAt?->format('Y-m-d'),
        );

        $userId = static::getContainer()->get(Connection::class)->fetchOne('SELECT LOWER(HEX(id)) FROM `user` LIMIT 1');
        self::assertIsString($userId);
        $send = Request::create('/send', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'reply' => 'We can offer 5%.',
        ], JSON_THROW_ON_ERROR));
        // A second trigger would queue a pass even before it could create a
        // second decision row. Observe the real merge and publish events.
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $trigger = new QuoteServicingTrigger($bus);
        $dispatcher->addSubscriber($trigger);

        try {
            self::assertSame(
                200,
                $controller->send($decisionId, $send, new Context(new AdminApiSource($userId)))->getStatusCode(),
            );
        } finally {
            $dispatcher->removeSubscriber($trigger);
        }

        self::assertSame('replied', $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName);
        self::assertSame('sent', $store->find($decisionId)?->reviewStatus);
        self::assertSame(
            0,
            (int) static::getContainer()
                ->get(Connection::class)
                ->fetchOne('SELECT COUNT(*) FROM merchant_quote_agent_decision WHERE quote_id = UNHEX(:quoteId) AND review_status = :status', [
                    'quoteId' => $quoteId,
                    'status' => 'pending',
                ]),
        );
    }
}
