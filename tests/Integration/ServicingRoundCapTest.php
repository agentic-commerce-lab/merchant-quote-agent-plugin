<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The spend bound against a real quote (#142). The counter has to survive
 * QuoteWriter's shallow merge alongside the crash budget and the baseline, and
 * the handler's claim write has to be the thing the cap later reads.
 */
final class ServicingRoundCapTest extends IntegrationTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', 'sk-integration');
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmModel', 'gpt-4o-mini');
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'validityDays', 14);
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testTheCounterOutlivesASuccessfulPass(): void
    {
        // The regression this test exists for: ATTEMPTS_KEY is cleared in the
        // same stamp write, and a counter cleared on success bounds nothing.
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $pipeline = new class implements QuoteServicingPipelineInterface {
            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
                PassContext $context,
            ): NegotiationOutcome {
                return NegotiationOutcome::Offered;
            }
        };

        $handler = new ServiceQuoteHandler(self::locks(), new NullLogger(), static::preflight(), $gateway, $pipeline);
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        $customFields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;
        self::assertNull(
            $customFields[ServiceQuoteHandler::ATTEMPTS_KEY] ?? null,
            'The crash budget must still clear on a normal exit.',
        );
        self::assertSame(
            1,
            $customFields[NegotiationRounds::KEY] ?? null,
            'The round counter must NOT clear on a normal exit; it is a spend bound, not a crash budget.',
        );
    }

    private static function locks(): QuoteServicingLock
    {
        return new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
    }
}
