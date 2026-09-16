<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnRecordsController;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The SwagCommercial half of the gate in `src/Resources/config/services.php`,
 * built from the real file — the commercial-side sibling of
 * tests/Unit/Ucp/UcpSurfaceConfigurationTest.php (#79).
 *
 * This suite runs with SwagCommercial genuinely absent: ADR 0001 keeps it out
 * of composer.json, so `class_exists` is false here with no help from anyone.
 * What has to be simulated is therefore PRESENCE, which is why the two absent
 * containers are built before `class_alias` runs and the two present ones
 * after. The aliases cannot be undone, hence the process isolation — without
 * it CommercialAvailabilityTest, which asserts the opposite, fails on order.
 *
 * Definitions, not a compiled container, for the reason
 * UcpSurfaceConfigurationTest already records: the file references core and SDK
 * ids nothing here provides, so compiling would fail for reasons that say
 * nothing about the gate. testNoServiceDependsOnOneItsGateRemoved() is what
 * stands in for the compile, and it is sharper — it sees only gate crossings.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommercialSurfaceConfigurationTest extends TestCase
{
    /**
     * #79's list, restated against the gate these services actually sit
     * behind today. The issue calls them "unconditionally registered"; ADR
     * 0001's amendment has since moved the whole evidence layer behind the UCP
     * gate, so what is true of them — and what matters here — is that they need
     * no SwagCommercial.
     *
     * Does not itself assert `CommercialAvailability::isAvailableByClass()` is
     * false: `CommercialAvailabilityTest::testReportsUnavailableWhenSwagCommercialIsAbsent`
     * already covers that claim, and `shops()` has aliased the commercial
     * classes into existence process-wide by the time this method runs, so a
     * second probe here would just read back its own fixture.
     */
    public function testTheEvidenceLayerBuildsWithoutSwagCommercial(): void
    {
        $container = self::shops()['withoutCommercial'];

        foreach ([
            ProtocolHash::class,
            A2cnKeyStore::class,
            A2cnIdentityResolver::class,
            SellerMandateFactory::class,
            MandateSigner::class,
            A2cnDiscoveryController::class,
            QuoteAgentSettingsReader::class,
        ] as $id) {
            self::assertTrue($container->hasDefinition($id), $id . ' should survive without SwagCommercial');
        }
    }

    /**
     * The other half: absent rather than broken. Asserted against BOTH shops so
     * the test cannot pass by asserting nothing — every id named here is
     * present once SwagCommercial is.
     */
    public function testTheBridgeAndItsConsumersAreAbsentWithoutSwagCommercial(): void
    {
        $shops = self::shops();

        foreach ([
            SellerActEmitter::class,
            QuoteTerminalStateReader::class,
            A2cnRecordsController::class,
            ServiceQuoteHandler::class,
        ] as $id) {
            self::assertFalse(
                $shops['withoutCommercial']->hasDefinition($id),
                $id . ' needs SwagCommercial and must not be registered without it',
            );
            self::assertTrue($shops['withBoth']->hasDefinition($id), $id . ' should return with SwagCommercial');
        }

        foreach ([QuoteGatewayInterface::class, BuyerQuoteGatewayInterface::class] as $id) {
            self::assertFalse(
                $shops['withoutCommercial']->has($id),
                $id . ' must not be registered without SwagCommercial',
            );
            self::assertTrue($shops['withBoth']->has($id), $id . ' should return with SwagCommercial');
        }
    }

    /**
     * Both gates are booleans, so there are four shops. All four are built in
     * one pass because `class_alias` is one-way: the two SwagCommercial-absent
     * containers must exist before the placeholders do.
     *
     * @return array{
     *     withoutEither: ContainerBuilder,
     *     withoutCommercial: ContainerBuilder,
     *     withBoth: ContainerBuilder,
     *     withoutUcp: ContainerBuilder,
     * }
     */
    private static function shops(): array
    {
        $withoutEither = self::build(ucp: false);
        $withoutCommercial = self::build(ucp: true);

        self::makeCommercialClassesAvailable();

        return [
            'withoutEither' => $withoutEither,
            'withoutCommercial' => $withoutCommercial,
            'withBoth' => self::build(ucp: true),
            'withoutUcp' => self::build(ucp: false),
        ];
    }

    /**
     * The UCP gate reads `kernel.bundles` and nothing else, so the bundle need
     * not be loadable here — only listed, exactly as the kernel would list it.
     */
    private static function build(bool $ucp): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.bundles', $ucp ? ['UcpSdkBundle' => 'Ucp\Sdk\Symfony\UcpSdkBundle'] : []);
        (new MerchantQuoteAgentPlugin(active: true, basePath: \dirname(__DIR__, levels: 4)))->build($container);

        return $container;
    }

    /**
     * The gate only asks whether these names exist and never instantiates
     * anything behind them, so one placeholder under three names is enough.
     * Same trick as OrderHistoryLocatorConfigurationTest, same process
     * isolation keeping it from leaking.
     */
    private static function makeCommercialClassesAvailable(): void
    {
        $placeholder = new class {};
        foreach ([
            CommercialAvailability::QUOTE_MANIPULATION,
            CommercialAvailability::QUOTE_COMMENTER,
            'Shopware\Commercial\Licensing\License',
        ] as $class) {
            class_alias($placeholder::class, $class);
        }
    }
}
