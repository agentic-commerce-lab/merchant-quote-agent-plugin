<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
 * What has to be simulated is therefore PRESENCE, which GateMatrix does by
 * aliasing three placeholders in between builds. Those aliases cannot be
 * undone, hence the process isolation — without it CommercialAvailabilityTest,
 * which asserts the opposite, fails on test order.
 *
 * Definitions, not a compiled container, for the reason
 * UcpSurfaceConfigurationTest already records: the file references core and SDK
 * ids nothing here provides, so compiling would fail for reasons that say
 * nothing about the gate. The last two tests are what stand in for the compile,
 * and they are sharper than one — they see only gate crossings, and they can
 * name which service crossed.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * Both rules aggregate per class against a threshold of 10. No single test
 * here is complex; the class is, because four tests each walk a container and
 * collect violations rather than asserting one value. Collecting is deliberate:
 * a failure names every service that crossed a gate, not just the first.
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
     * Does not itself assert `CommercialAvailability::isRegistered()` is
     * false: `CommercialAvailabilityTest` already covers the probe, and
     * GateMatrix has aliased the commercial classes into existence
     * process-wide by the time this method runs, so a second probe here would
     * only read back its own fixture.
     */
    public function testTheEvidenceLayerBuildsWithoutSwagCommercial(): void
    {
        $container = GateMatrix::build()->shops['withoutCommercial'];

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
        $shops = GateMatrix::build()->shops;

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
     * #152, stated directly. A composer-installed SwagCommercial that a
     * merchant deactivates leaves its classes loadable and its bundle gone;
     * the gate must build exactly the shop it builds when SwagCommercial was
     * never there. The two dependency tests below state the consequence — no
     * `service('quote.repository')` left dangling — this states the cause.
     */
    public function testAVendoredButInactiveShopRegistersWhatAnAbsentOneDoes(): void
    {
        $shops = GateMatrix::build()->shops;
        $ids = static function (ContainerBuilder $container): array {
            $all = array_merge(array_keys($container->getDefinitions()), array_keys($container->getAliases()));
            sort($all);

            return $all;
        };

        self::assertSame($ids($shops['withoutCommercial']), $ids($shops['vendoredButInactive']));
    }

    /**
     * The check that replaces "the container compiles", for the dependencies
     * somebody wrote down: no service this shop still registers may hold a
     * mandatory reference to an id this shop's gates removed.
     */
    #[DataProvider('shopNames')]
    public function testNoServiceDependsOnOneItsGateRemoved(string $shop): void
    {
        $matrix = GateMatrix::build();
        $removed = $matrix->removedIn($shop);
        $container = $matrix->shops[$shop];

        $violations = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            foreach (DefinitionNeeds::mandatoryReferences($definition) as $reference) {
                if (\array_key_exists($reference, $removed)) {
                    $violations[] = $id . ' -> ' . $reference;
                }
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            if (\array_key_exists((string) $alias, $removed)) {
                $violations[] = 'alias ' . $id . ' -> ' . $alias;
            }
        }

        self::assertSame([], $violations, 'These services would not resolve on a ' . $shop . ' shop');
    }

    /**
     * The same rule for the dependencies nobody wrote down. services.php sets
     * `defaults()->autowire()`, so most definitions carry no arguments at load
     * time and the reference walker above sees nothing at all for them — their
     * dependencies are constructor types Symfony only resolves at compile.
     *
     * Two arms. A need this shop's gates removed is the #76 shape reaching the
     * autowired majority of the graph. A need that was not LOADABLE when this
     * shop was built is #79's other named failure mode: an unconditional
     * service reaching for a class that only exists behind the gate.
     *
     * The loadability half reads GateMatrix's build-time snapshot rather than
     * calling `class_exists()` here, because a live call is dead on precisely
     * the classes it is meant to catch. DefinitionNeeds::inContainer() has the
     * why.
     */
    #[DataProvider('shopNames')]
    public function testNoAutowiredServiceNeedsAClassItsGateRemoved(string $shop): void
    {
        $matrix = GateMatrix::build();
        $removed = $matrix->removedIn($shop);
        $container = $matrix->shops[$shop];

        $violations = [];
        foreach ($matrix->needs[$shop] as $need) {
            ['id' => $id, 'needs' => $name, 'of' => $of, 'loadable' => $loadable] = $need;

            if (!$loadable) {
                $violations[] = $id . ': ' . $of . ' => ' . $name . ', which is not loadable on this shop';

                continue;
            }

            // A need the container satisfies is not autowired out of thin air.
            if (!$container->has($name) && \array_key_exists($name, $removed)) {
                $violations[] = $id . ': ' . $of . ' => ' . $name . ', which this shop\'s gates removed';
            }
        }

        self::assertSame([], $violations, 'These services would not autowire on a ' . $shop . ' shop');
    }

    /** @return iterable<string, array{string}> */
    public static function shopNames(): iterable
    {
        yield 'neither SwagCommercial nor the UCP SDK bundle' => ['withoutEither'];
        yield 'the UCP SDK bundle but no SwagCommercial' => ['withoutCommercial'];
        yield 'SwagCommercial but no UCP SDK bundle' => ['withoutUcp'];
        yield 'SwagCommercial vendored but deactivated' => ['vendoredButInactive'];
        // A control, not a check: every registration in services.php sits
        // inside an `if` with no `else`, so the shop with both gates open is a
        // superset of the other three and its removed set is structurally
        // empty. Kept so that the day someone writes an `else`, it is covered
        // without their having to remember this file.
        yield 'both' => ['withBoth'];
    }
}
