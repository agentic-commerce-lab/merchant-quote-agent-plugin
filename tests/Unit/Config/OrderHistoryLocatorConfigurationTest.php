<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\QuoteStateTransitioner;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Shopware's Bundle creates PhpFileLoader without its optional environment. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderHistoryLocatorConfigurationTest extends TestCase
{
    #[DataProvider('environments')]
    public function testLocatorUsesTheKernelEnvironmentAndKeepsServicesPrivate(
        string $kernelEnvironment,
        bool $expected,
    ): void {
        self::makeCommercialClassesAvailable();
        // With the classes loadable, a null container must still shut the
        // gate — the classpath alone is never enough (#152).
        self::assertFalse(CommercialAvailability::isRegistered(null));

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $kernelEnvironment);
        $container->setParameter('kernel.bundles', [
            'QuoteManagement' => 'Shopware\\Commercial\\B2B\\QuoteManagement\\QuoteManagement',
        ]);
        self::assertTrue(CommercialAvailability::isRegistered($container));
        $pluginRoot = \dirname(__DIR__, levels: 3);
        (new MerchantQuoteAgentPlugin(active: true, basePath: $pluginRoot))->build($container);

        $id = 'merchant_quote_agent.dev.order_history';
        self::assertSame($expected, $container->hasDefinition($id));
        self::assertFalse($container->getAlias(BuyerQuoteGatewayInterface::class)->isPublic());
        self::assertFalse($container->getDefinition(SalesChannelContextResolver::class)->isPublic());
        self::assertFalse($container->getDefinition(QuoteStateTransitioner::class)->isPublic());
        if ($expected) {
            $locator = $container->getDefinition($id);
            self::assertTrue($locator->isPublic());
            self::assertTrue($locator->hasTag('container.service_locator'));
            self::assertSame(
                [
                    BuyerQuoteGatewayInterface::class,
                    SalesChannelContextResolver::class,
                    QuoteStateTransitioner::class,
                    CommercialAvailability::QUOTE_ORDER_ROUTE,
                ],
                array_keys($locator->getArgument(0)),
            );
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function environments(): iterable
    {
        yield 'Shopware dev loader has no environment' => ['dev', true];
        yield 'Shopware test loader has no environment' => ['test', true];
        yield 'production exposes no locator' => ['prod', false];
        yield 'unrecognized environment exposes no locator' => ['staging', false];
    }

    private static function makeCommercialClassesAvailable(): void
    {
        // The gate checks class existence alongside the bundle list; it never
        // instantiates these placeholders. Process isolation prevents leaking
        // them to tests.
        $placeholder = new class {};
        foreach ([
            CommercialAvailability::QUOTE_MANIPULATION,
            CommercialAvailability::QUOTE_COMMENTER,
            'Shopware\\Commercial\\Licensing\\License',
        ] as $class) {
            class_alias($placeholder::class, $class);
        }
    }
}
