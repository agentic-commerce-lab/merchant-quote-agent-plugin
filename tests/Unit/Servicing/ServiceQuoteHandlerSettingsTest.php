<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

final class ServiceQuoteHandlerSettingsTest extends TestCase
{
    /** @throws \Throwable the handler's own declared surface */
    public function testTheSettingsReachThePipeline(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?QuoteAgentSettings $seen = null;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                $this->seen = $settings;
            }
        };

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertNotNull($pipeline->seen, '#18 cannot read config itself; the handler must hand the settings over.');
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testADisabledChannelIsNeitherServicedNorStamped(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        ServicingHandlerFixture::handler(
            $gateway,
            $pipeline,
            preflight: ServicingSettingsFixture::preflightReturning(null),
        )(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes);
        self::assertSame(
            [],
            $gateway->customFieldWrites,
            'Nothing was serviced, so nothing may be stamped — a stamp would suppress the next real trigger '
            . 'once the agent is switched back on.',
        );
    }

    /** @throws \Throwable the handler's own declared surface */
    public function testASuccessfulPassClearsTheEscalationMarker(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);

        ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline())(
            ServicingHandlerFixture::message(),
        );

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayHasKey(ServicingFingerprint::MARKER_KEY, $stamp);
        self::assertNull(
            $stamp[QuoteEscalator::MARKER_KEY],
            'A fixed configuration must be able to escalate again if it breaks again.',
        );
    }
}
