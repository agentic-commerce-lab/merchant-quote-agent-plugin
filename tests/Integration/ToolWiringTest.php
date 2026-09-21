<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Assistant\AssistantAvailability;
use MerchantQuoteAgentPlugin\Assistant\QuoteStatusToolFactory;
use MerchantQuoteAgentPlugin\Assistant\RequestQuoteToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `tests/Stub/SwagAssistantStarterKit` hand-writes four of the starter kit's
 * own types because this repo takes no Composer dependency on that plugin
 * (the same trade ADR 0001 makes for SwagCommercial and Agentic Commerce).
 * Everything that references those stubs — `AssistantAvailability`,
 * `RequestQuoteToolFactory`, `QuoteStatusToolFactory`, and the two `#[AsTool]`
 * classes — compiles and type-checks against a fiction. **This test is the
 * only thing standing between that fiction and an upstream rename.** If it
 * fails, the fix is the stub under `tests/Stub`, never a change to this test:
 * the stub is what is allowed to be wrong, because it is the copy nobody but
 * this test reads back against reality.
 *
 * Runs only against a shop that has the shopping-assistant-starter-kit
 * plugin (`swag/assistant-starter-kit`) installed and active — `setUp()`
 * skips otherwise, the same way `requireUcpSurface()` skips the Agentic
 * Commerce suites. That shop is not this project's own test shop as of this
 * writing (see the Task 7 report), so passing here has so far only been
 * observed as "skipped for the right reason", never as green.
 */
final class ToolWiringTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::requireAssistantSurface();
    }

    /**
     * The literal bundle key `AssistantAvailability::isRegistered()` hardcodes
     * against `kernel.bundles`. This is independent of `setUp()`'s own guard,
     * which detects the plugin by the *namespace* of its registered bundle
     * class rather than by this literal — see `requireAssistantSurface()`'s
     * docblock for why. So this assertion can genuinely fail: if upstream
     * renames the bundle, `setUp()` still lets the test run (the namespace is
     * unchanged), and this line goes red instead of the whole file skipping
     * quietly around the very rename it exists to catch.
     */
    public function testKernelBundlesListsTheStarterKit(): void
    {
        self::assertTrue(AssistantAvailability::isRegistered(static::getContainer()));
    }

    /**
     * The real interface, not the stub — `requireAssistantSurface()` above
     * already established the bundle is active, and this repo's own
     * `autoload-dev` stub is never loaded when the plugin runs as an
     * installed dependency inside a shop (see `tests/Integration/bootstrap.php`),
     * so a resolved instance here can only satisfy `instanceof` against
     * whatever the shop's own vendored copy of the interface declares.
     */
    public function testBothFactoriesImplementTheRealToolFactoryInterface(): void
    {
        self::assertTrue(
            interface_exists(ToolFactoryInterface::class),
            'Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface is not on the classpath, '
            . 'even though the bundle is registered.',
        );

        $requestQuoteFactory = static::getContainer()->get(RequestQuoteToolFactory::class);
        $quoteStatusFactory = static::getContainer()->get(QuoteStatusToolFactory::class);

        self::assertInstanceOf(ToolFactoryInterface::class, $requestQuoteFactory);
        self::assertInstanceOf(ToolFactoryInterface::class, $quoteStatusFactory);
    }

    /**
     * `RequestQuoteToolFactory::create()` and `QuoteStatusToolFactory::create()`
     * both type-hint `ToolContext` and read only `$context->trace` and
     * `$context->config` off it. If upstream widens the constructor — a new
     * required property, a renamed one — nothing here fails loudly on its
     * own; this reflection check is what turns that into a red test instead
     * of a shop finding out first.
     */
    public function testToolContextsConstructorTakesExactlyTraceAndConfig(): void
    {
        $constructor = new \ReflectionMethod(ToolContext::class, '__construct');

        $names = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        self::assertSame(['trace', 'config'], $names);
    }

    /**
     * Reads the tag off a freshly built `ContainerBuilder`, not the compiled
     * runtime container `getContainer()` hands back — tags are consumed and
     * discarded by the compiler passes that act on them, so the compiled
     * container has none left to ask about. This rebuild is the same one
     * `debug:container --tag=…` performs internally
     * (`Symfony\Bundle\FrameworkBundle\Command\BuildDebugContainerTrait`), so
     * the answer comes from Symfony's own tag bookkeeping rather than from
     * this plugin's `services.php` being re-read and trusted.
     */
    public function testBothFactoriesAreRegisteredUnderTheToolFactoryTag(): void
    {
        $taggedIds = array_keys(self::freshContainerBuilder()->findTaggedServiceIds('swag_assistant.tool_factory'));

        self::assertContains(RequestQuoteToolFactory::class, $taggedIds);
        self::assertContains(QuoteStatusToolFactory::class, $taggedIds);
    }

    private static function freshContainerBuilder(): ContainerBuilder
    {
        $kernel = static::getKernel();

        /** @var \Closure(): ContainerBuilder $build */
        $build = \Closure::bind(
            function (): ContainerBuilder {
                $this->initializeBundles();

                return $this->buildContainer();
            },
            $kernel,
            $kernel::class,
        );

        $container = $build();
        // Same posture as BuildDebugContainerTrait: a removing pass can inline
        // or drop the definition of a private, single-use service entirely,
        // taking its tags with it, before this test ever gets to read them.
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->compile();

        return $container;
    }
}
