<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `src/Resources/config/services.php` built four times: both runtime gates —
 * SwagCommercial and the UCP SDK bundle — crossed.
 *
 * Not a test. It is the fixture CommercialSurfaceConfigurationTest asserts
 * over, split out because the building is a different job from the asserting
 * and because the two together put one class over the lint gate's method
 * count. Never instantiate it outside a process that can afford
 * `makeCommercialClassesAvailable()`: see build().
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class against a threshold of 10. The branches are
 * removedIn()'s classification of each referenced id — ours or foreign, and if
 * foreign, behind which gate — which is four cases because there are two gates
 * and each can be open or shut. Collapsing them would lose the distinction the
 * whole check rests on.
 */
final class GateMatrix
{
    /** Which gates each shop has open: [SwagCommercial, UCP SDK bundle]. */
    public const SHOPS = [
        'withoutEither' => [false, false],
        'withoutCommercial' => [false, true],
        'withBoth' => [true, true],
        'withoutUcp' => [true, false],
    ];

    /**
     * Ids that belong to SwagCommercial but carry no namespace to recognise
     * them by, so they have to be named.
     *
     * They are DAL repositories, synthesised by Shopware from SwagCommercial's
     * own entity definitions, so they leave with the bundle exactly as its
     * classes do — and `services.php` references them PLAINLY, without
     * `ignoreOnInvalid()`, which makes an ungated one a compile-time
     * ServiceNotFoundException and a shop that will not boot. Everything else
     * foreign is recognised by prefix; see foreignOwner().
     */
    private const COMMERCIAL_REPOSITORY_IDS = ['quote.repository', 'quote_line_item.repository'];

    /**
     * @param array<string, ContainerBuilder>                                            $shops
     * @param array<string, list<array{id: string, needs: string, of: string, loadable: bool}>> $needs
     */
    private function __construct(
        public readonly array $shops,
        public readonly array $needs,
    ) {}

    /**
     * Builds all four in one pass, because `class_alias` is one-way: the two
     * SwagCommercial-absent containers must exist before the placeholders do,
     * and the two present ones after.
     *
     * Each shop's construction needs are snapshotted as it is built, while the
     * process's class table still matches that shop — see DefinitionNeeds::inContainer().
     */
    public static function build(): self
    {
        $shops = [];
        $needs = [];

        $record = static function (string $shop, bool $ucp) use (&$shops, &$needs): void {
            $container = self::container($ucp);
            $shops[$shop] = $container;
            $needs[$shop] = DefinitionNeeds::inContainer($container);
        };

        // Without this the two "absent" shops silently become present shops:
        // class_alias on a name already in use warns and returns false, so a
        // second build() in one process would model four identical shops and
        // the gate checks would pass vacuously.
        if (class_exists(CommercialAvailability::QUOTE_MANIPULATION, autoload: false)) {
            throw new \LogicException(
                'GateMatrix needs a process where SwagCommercial has never been faked. '
                . 'Did CommercialSurfaceConfigurationTest lose #[RunTestsInSeparateProcesses]?',
            );
        }

        $record('withoutEither', ucp: false);
        $record('withoutCommercial', ucp: true);

        self::makeCommercialClassesAvailable();

        $record('withBoth', ucp: true);
        $record('withoutUcp', ucp: false);

        return new self($shops, $needs);
    }

    /**
     * The ids this shop's gates removed: every id services.php registers in any
     * configuration, minus the ids this shop has.
     *
     * Derived from the file on every run rather than listed by hand, so a
     * service added to either side of either gate is classified correctly
     * without anyone updating a test.
     *
     * Plus the FOREIGN ids a closed gate takes with it. Those cannot be
     * derived, because services.php never registers them — they arrive with
     * SwagCommercial or with the UCP SDK bundle, and subtracting this file
     * from itself can never see them. Leaving them out is not a small gap: the
     * one bug of this family the plugin has actually shipped was a decoration
     * of the SDK's `RuntimeConfigurationResolverInterface` outliving its gate,
     * which killed a real shop in DecoratorServicePass (ADR 0001, amendment),
     * and `service('quote.repository')` outliving the commercial gate is a
     * plain unguarded reference in five places. Both are foreign ids.
     *
     * @return array<string, true>
     */
    public function removedIn(string $shop): array
    {
        $ids = static fn(ContainerBuilder $container): array => array_merge(
            array_keys($container->getDefinitions()),
            array_keys($container->getAliases()),
        );

        $everywhere = array_merge(...array_map($ids, array_values($this->shops)));
        $ours = array_fill_keys(array_diff($everywhere, $ids($this->shops[$shop])), value: true);

        [$commercial, $ucp] = self::SHOPS[$shop];
        $foreign = [];
        foreach ($this->referencedIds() as $id) {
            // Registered somewhere, so the derivation above already judged it.
            if (\in_array($id, $everywhere, strict: true)) {
                continue;
            }

            $owner = self::foreignOwner($id);
            if ($owner === 'commercial' && !$commercial || $owner === 'ucp' && !$ucp) {
                $foreign[$id] = true;
            }
        }

        return $ours + $foreign;
    }

    /**
     * Which optional plugin an id that services.php never registers comes from,
     * or null for core — which no gate here switches off.
     *
     * By prefix rather than by list, so a newly injected SwagCommercial or SDK
     * id is classified without anyone updating this file. The two DAL
     * repository ids are the exception that has to be named: they carry no
     * namespace to recognise.
     */
    private static function foreignOwner(string $id): ?string
    {
        if (
            \in_array($id, self::COMMERCIAL_REPOSITORY_IDS, strict: true) || str_starts_with($id, 'Shopware\Commercial')
        ) {
            return 'commercial';
        }

        return str_starts_with($id, 'Ucp\Sdk') ? 'ucp' : null;
    }

    /**
     * Every id any shop points at, mandatorily or not — the search space
     * removedIn() classifies. Includes ids nothing registers, which is the
     * whole point: those are where the foreign ones live.
     *
     * @return list<string>
     */
    private function referencedIds(): array
    {
        $ids = [];
        foreach ($this->shops as $container) {
            foreach ($container->getDefinitions() as $definition) {
                $ids = array_merge($ids, DefinitionNeeds::mandatoryReferences($definition));
            }

            foreach ($container->getAliases() as $alias) {
                $ids[] = (string) $alias;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The UCP gate reads `kernel.bundles` and nothing else, so the bundle need
     * not be loadable here — only listed, exactly as the kernel would list it.
     */
    private static function container(bool $ucp): ContainerBuilder
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
     * Same trick as OrderHistoryLocatorConfigurationTest — and, like it, this
     * can only run in a process that is thrown away afterwards.
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
