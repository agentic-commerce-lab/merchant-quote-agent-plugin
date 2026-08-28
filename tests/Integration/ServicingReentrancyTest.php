<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Issue #4's headline guarantee, against the live shop: duplicate or concurrent
 * triggers for one quote produce exactly one servicing pass, and the quote is
 * not left mid-flight.
 */
final class ServicingReentrancyTest extends IntegrationTestCase
{
    /**
     * The pass only runs for an enabled, validly configured sales channel, so
     * the preflight has something to hand the pipeline. Written rather than
     * read: DatabaseTransactionBehaviour rolls these back, and a write-then-read
     * inside one test is what makes the config caches agree with the database.
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', 'sk-integration');
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmModel', 'gpt-4o-mini');
    }

    public function testASecondDeliveryDuringAPassHandsOffOnlyOnce(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);

        // The pipeline writes a real agent comment — the write that used to
        // re-fire the webhook indistinguishably from a buyer's — and then
        // replays the same delivery from inside the pass.
        $pipeline = new class($locks) implements QuoteServicingPipelineInterface {
            public int $passes = 0;
            public ?ServiceQuoteMessage $replay = null;
            public ?ServiceQuoteHandler $handler = null;
            public bool $replayThrew = false;

            public function __construct(
                private readonly QuoteServicingLock $locks,
            ) {}

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                ++$this->passes;
                $gateway->addComment($snapshot->identity->quoteId, 'ServicingReentrancyTest agent reply');

                if ($this->replay === null || $this->handler === null) {
                    return;
                }

                // Re-entrant delivery, lock still held by the outer pass.
                try {
                    ($this->handler)($this->replay);
                } catch (\Throwable) {
                    $this->replayThrew = true;
                }
            }
        };

        $handler = new ServiceQuoteHandler($locks, new NullLogger(), static::preflight(), $gateway, $pipeline);
        $pipeline->handler = $handler;
        $pipeline->replay = $message;

        $handler($message);

        self::assertSame(1, $pipeline->passes, 'A replayed delivery produced a second servicing pass.');
        self::assertTrue(
            $pipeline->replayThrew,
            'The re-entrant delivery should have been refused for retry, not silently dropped: '
            . 'dropping it would drop a buyer comment that landed during the pass.',
        );
    }

    public function testAFreshDeliveryAfterAPassIsANoOp(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                ++$this->passes;
                $gateway->addComment($snapshot->identity->quoteId, 'ServicingReentrancyTest agent reply');
            }
        };

        $handler = new ServiceQuoteHandler($locks, new NullLogger(), static::preflight(), $gateway, $pipeline);

        $handler($message);
        $handler($message);

        self::assertSame(1, $pipeline->passes, 'The duplicate delivery serviced the quote a second time.');

        $marker = ServicingFingerprint::stamped($gateway->fetchSnapshot($quoteId)->lifecycle->customFields);
        self::assertNotNull($marker, 'The successful pass did not stamp the marker.');
        self::assertSame(
            ServicingFingerprint::of($gateway->fetchSnapshot($quoteId)),
            $marker,
            'The stamped marker does not match the quote as it now stands, so the next trigger will '
            . 'service it again for nothing. The agent reply must not move the fingerprint.',
        );
    }

    /**
     * A buyer comment that lands DURING a servicing pass must still be serviced.
     *
     * This is the failure revision-abort was rejected for, and a handler that
     * stamps `ServicingFingerprint::of($after)` instead of
     * `ServicingFingerprint::stamp($serviced, $stateAfter)` reintroduces it while
     * passing every duplicate-suppression test in this file: the post-servicing
     * read already contains the buyer's new comment, so the stamp claims credit
     * for input the pass never saw.
     */
    public function testABuyerCommentLandingDuringAPassIsServicedByTheNextDelivery(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);

        $repository = static::getContainer()->get('quote_comment.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $customerId = $this->anyCustomerId();

        $pipeline = new class($repository, $customerId) implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            public function __construct(
                private readonly EntityRepository $comments,
                private readonly string $customerId,
            ) {}

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                ++$this->passes;

                // A BUYER comment arriving mid-pass. Written through the
                // repository rather than the gateway because the gateway's
                // addComment() is author-less by design, and authorship is
                // exactly what the fingerprint keys on.
                if ($this->passes === 1) {
                    $this->comments->create([[
                        'quoteId' => $snapshot->identity->quoteId,
                        'comment' => 'buyer follow-up during servicing',
                        'customerId' => $this->customerId,
                    ]], Context::createDefaultContext());
                }

                $gateway->addComment($snapshot->identity->quoteId, 'agent reply');
            }
        };

        $handler = new ServiceQuoteHandler($locks, new NullLogger(), static::preflight(), $gateway, $pipeline);

        $handler($message);
        $handler($message);

        self::assertSame(
            2,
            $pipeline->passes,
            'The buyer comment that landed during the first pass was never serviced. The stamp '
            . 'claimed credit for input the pass never saw — see the spec on stamp composition.',
        );
    }

    private function anyCustomerId(): string
    {
        $repository = static::getContainer()->get('customer.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = $repository->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($id, 'The shop has no customer to attribute a buyer comment to.');

        return $id;
    }
}
