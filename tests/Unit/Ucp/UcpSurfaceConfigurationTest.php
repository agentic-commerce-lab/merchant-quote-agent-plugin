<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Identity\AcOAuthAccessTokenReader;
use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Ucp\Profile\A2cnMandateProfileContributor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteContractController;
use MerchantQuoteAgentPlugin\Ucp\UcpAvailability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Both sides of the UCP gate, built from the real services.php.
 *
 * The suite runs without the SDK bundle, so the "with" case fakes the entry
 * `kernel.bundles` would carry — that parameter is the whole of what
 * `UcpAvailability::isRegistered()` reads.
 *
 * Definitions, not a compiled container: without the real bundle the UCP branch
 * references ids nothing here provides, so compiling would fail for reasons
 * that say nothing about the gate.
 */
final class UcpSurfaceConfigurationTest extends TestCase
{
    public function testWithoutTheUcpSdkBundleTheAgentFacingSurfaceIsNotRegistered(): void
    {
        $container = self::build();

        self::assertFalse(UcpAvailability::isRegistered($container));
        self::assertFalse($container->hasDefinition(AgentConsentController::class));
        self::assertFalse($container->hasDefinition(AcOAuthAccessTokenReader::class));
        self::assertFalse($container->hasDefinition(AgentAdmittingRuntimeConfigurationResolver::class));
        self::assertFalse($container->hasDefinition(QuoteCapability::class));
        self::assertFalse($container->hasDefinition(A2cnMandateProfileContributor::class));

        // The evidence layer goes with the surface: it cannot start without a
        // buyer agent to open a session. See the A2CN block in services.php.
        self::assertFalse($container->hasDefinition(A2cnKeyStore::class));
        self::assertFalse($container->hasDefinition(ProtocolHash::class));
        self::assertFalse($container->hasDefinition(A2cnDiscoveryController::class));
    }

    /**
     * The point of the whole change: a shop with no UCP surface still services
     * the quotes a buyer creates by hand. The negotiation policy reader is in
     * here deliberately — it sits in the same region of services.php as the
     * evidence layer but is what the agent DECIDES by, so gating it would take
     * servicing down with A2CN.
     */
    public function testWithoutTheUcpSdkBundleServicingStillBuilds(): void
    {
        $container = self::build();

        self::assertTrue($container->hasDefinition(DecisionRecorder::class));
        self::assertTrue($container->hasDefinition(QuoteAgentSettingsReader::class));
        self::assertTrue($container->hasDefinition(QuoteContractController::class));
    }

    public function testWithTheUcpSdkBundleTheAgentFacingSurfaceIsRegistered(): void
    {
        $container = self::build(['UcpSdkBundle' => 'Ucp\\Sdk\\Symfony\\UcpSdkBundle']);

        self::assertTrue(UcpAvailability::isRegistered($container));
        self::assertTrue($container->hasDefinition(AgentConsentController::class));
        self::assertTrue($container->hasDefinition(AcOAuthAccessTokenReader::class));
        self::assertTrue($container->hasDefinition(AgentAdmittingRuntimeConfigurationResolver::class));
        self::assertTrue($container->hasDefinition(QuoteCapability::class));
        self::assertTrue($container->hasDefinition(A2cnMandateProfileContributor::class));
        self::assertTrue($container->hasDefinition(A2cnKeyStore::class));
        self::assertTrue($container->hasDefinition(ProtocolHash::class));
        self::assertTrue($container->hasDefinition(A2cnDiscoveryController::class));
    }

    /**
     * The gate reads `kernel.bundles` and nothing else, so the bundle need not
     * be loadable here — only listed, exactly as the kernel would list it.
     *
     * @param array<string, string> $bundles
     */
    private static function build(array $bundles = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.bundles', $bundles);
        (new MerchantQuoteAgentPlugin(active: true, basePath: \dirname(__DIR__, levels: 3)))->build($container);

        return $container;
    }
}
