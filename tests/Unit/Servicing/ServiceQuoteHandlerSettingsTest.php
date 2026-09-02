<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
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
                PassContext $context,
            ): NegotiationOutcome {
                $this->seen = $settings;

                return NegotiationOutcome::Offered;
            }
        };

        $settings = ServicingSettingsFixture::settings();

        ServicingHandlerFixture::handler(
            $gateway,
            $pipeline,
            preflight: ServicingSettingsFixture::preflightReturning($settings),
        )(ServicingHandlerFixture::message());

        // assertSame, not assertNotNull: the interface promises #18 the very
        // settings the preflight validated, not an equivalent rebuild.
        self::assertSame(
            $settings,
            $pipeline->seen,
            '#18 cannot read config itself; the handler must hand the settings over.',
        );
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
    public function testAnEscalatedOutcomeKeepsTheEscalationMarker(): void
    {
        // Clearing the marker on every pass would erase one the pipeline just
        // wrote, and the quote would re-escalate on every later buyer comment.
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline(NegotiationOutcome::Escalated);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayHasKey(
            ServicingFingerprint::MARKER_KEY,
            $stamp,
            'An escalated pass still handled the ask, so it must still be fingerprinted.',
        );
        self::assertArrayNotHasKey(
            QuoteEscalator::MARKER_KEY,
            $stamp,
            'An escalated pass must not clear the marker it just wrote.',
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
        self::assertArrayHasKey(QuoteEscalator::MARKER_KEY, $stamp);
        self::assertNull(
            $stamp[QuoteEscalator::MARKER_KEY],
            'A fixed configuration must be able to escalate again if it breaks again.',
        );
        // The handler spreads both markers' releaseFor() into the same stamp;
        // an answering pass must release the clarification marker too, or the
        // first ambiguity in a quote's life permanently consumes its one
        // question.
        self::assertArrayHasKey(ClarificationMarker::MARKER_KEY, $stamp);
        self::assertNull($stamp[ClarificationMarker::MARKER_KEY]);
    }
}
