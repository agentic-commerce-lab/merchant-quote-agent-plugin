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
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The kill switch and the misconfigured state, against the real container and
 * the real configuration store.
 */
final class ServicingConfigGateTest extends IntegrationTestCase
{
    public function testADisabledSalesChannelIsNeverServicedAndTheQuoteIsUntouched(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        self::config()->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', false);

        $pipeline = self::countingPipeline();
        $before = $gateway->fetchSnapshot($quoteId);

        self::handler($gateway, $pipeline)(ServiceQuoteMessage::because(
            $quoteId,
            ServicingTriggerReason::CommentWritten,
        ));

        self::assertSame(0, $pipeline->passes, 'A disabled sales channel was serviced.');
        self::assertTrue(
            $gateway->fetchSnapshot($quoteId)->revision->matches($before->revision),
            'A paused agent wrote to the quote.',
        );
    }

    public function testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $config = self::config();
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        $pipeline = self::countingPipeline();
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);
        $before = \count($gateway->fetchSnapshot($quoteId)->content->comments);

        self::handler($gateway, $pipeline)($message);
        self::handler($gateway, $pipeline)($message);

        self::assertSame(0, $pipeline->passes, 'A misconfigured agent serviced the quote anyway.');
        self::assertSame(
            $before + 1,
            \count($gateway->fetchSnapshot($quoteId)->content->comments),
            'The misconfigured state must produce exactly one comment, not one per trigger.',
        );
        self::assertSame(
            'not_configured',
            $gateway->fetchSnapshot($quoteId)->lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null,
        );
    }

    private static function config(): SystemConfigService
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
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
                PassContext $context,
            ): NegotiationOutcome {
                ++$this->passes;

                return NegotiationOutcome::Offered;
            }
        };
    }

    private static function handler(
        QuoteGatewayInterface $gateway,
        QuoteServicingPipelineInterface $pipeline,
    ): ServiceQuoteHandler {
        return new ServiceQuoteHandler(
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x'),
            new NullLogger(),
            static::preflight(),
            $gateway,
            $pipeline,
        );
    }
}
