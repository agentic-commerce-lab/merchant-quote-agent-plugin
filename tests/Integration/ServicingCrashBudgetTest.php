<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The counter that bounds a message which kills the worker. Tested without
 * killing anything: what matters is that the budget lives in the quote's own
 * data rather than in process state, so it is readable and enforceable on the
 * next delivery.
 */
final class ServicingCrashBudgetTest extends IntegrationTestCase
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

    public function testAQuoteAtTheCeilingParksWithoutServicing(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ServiceQuoteHandler::ATTEMPTS_KEY => ServiceQuoteHandler::MAX_ATTEMPTS,
        ]));

        $pipeline = self::countingPipeline();
        $handler = new ServiceQuoteHandler(self::locks(), new NullLogger(), static::preflight(), $gateway, $pipeline);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));
        } finally {
            self::assertSame(0, $pipeline->passes, 'A quote past its crash budget was serviced anyway.');
        }
    }

    /**
     * Also proves a customFields value can be cleared through the bridge:
     * QuoteWriter drops an empty array but must pass a null VALUE through, and
     * clearing the counter on success depends on that.
     */
    public function testASuccessfulPassClearsTheCounter(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ServiceQuoteHandler::ATTEMPTS_KEY => 2,
        ]));

        $handler = new ServiceQuoteHandler(
            self::locks(),
            new NullLogger(),
            static::preflight(),
            $gateway,
            self::countingPipeline(),
        );
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        $customFields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;
        self::assertNull(
            $customFields[ServiceQuoteHandler::ATTEMPTS_KEY] ?? null,
            'A healthy quote is still carrying a crash counter, so its budget will never reset.',
        );
    }

    public function testTheCounterIsRaisedBeforeTheQuoteIsServiced(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?int $counterDuringPass = null;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): NegotiationOutcome {
                $customFields = $gateway->fetchSnapshot($snapshot->identity->quoteId)->lifecycle->customFields;
                $counter = $customFields[ServiceQuoteHandler::ATTEMPTS_KEY] ?? null;
                $this->counterDuringPass = \is_int($counter) ? $counter : null;

                return NegotiationOutcome::Offered;
            }
        };

        $handler = new ServiceQuoteHandler(self::locks(), new NullLogger(), static::preflight(), $gateway, $pipeline);
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        self::assertSame(
            1,
            $pipeline->counterDuringPass,
            'The crash counter was not committed to the database before the pipeline ran. A segfault '
            . 'during servicing leaves no exception and no retry stamp, so an uncommitted counter '
            . 'means the doctrine transport redelivers the poison message hourly, forever.',
        );
    }

    private static function locks(): QuoteServicingLock
    {
        return new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    private static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): NegotiationOutcome {
                ++$this->passes;

                return NegotiationOutcome::Offered;
            }
        };
    }
}
