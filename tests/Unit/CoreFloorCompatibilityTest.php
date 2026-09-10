<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Everything this plugin takes from Shopware core must exist at the SUPPORT
 * FLOOR, not merely in the core that happens to be installed.
 *
 * This is the only check that looks at the floor at all. `mago analyze`
 * resolves symbols against `vendor/`, which holds whatever core the developer
 * installed — 6.7.13.1 today — so a class or a constructor argument introduced
 * after the floor type-checks perfectly and then fails on a merchant's shop.
 * That is not hypothetical: `Attribute\Field::$maxLength` does not exist at the
 * floor (absent up to and including 6.7.4.2, present by 6.7.13.1) and was used
 * on thirteen columns of QuoteDecisionRecord, which would have been an `Error`
 * during the container build on a 6.7.1 shop — taking the whole installation
 * down, not just this plugin.
 *
 * Class existence and DAL attribute arguments only. A full signature diff
 * would need the floor's sources parsed; these two are the failures that take
 * a shop down rather than merely misbehave.
 *
 * Skipped without a core clone: CI has no checkout of shopware/shopware, and
 * this is a developer-machine guard, exactly like ReleaseCapabilityMatrixTest.
 */
final class CoreFloorCompatibilityTest extends TestCase
{
    /**
     * The oldest core this plugin claims to run on. Keep it in step with
     * `composer.json`'s `shopware/core` constraint and the README.
     */
    private const FLOOR_TAG = 'v6.7.1.0';

    private const SOURCE_DIR = __DIR__ . '/../../src';

    /** The DAL attributes this plugin puts arguments into. */
    private const DAL_ATTRIBUTES = [
        'Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field',
        'Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity',
    ];

    public function testEveryImportedCoreClassExistsAtTheSupportFloor(): void
    {
        $repository = self::coreClone();

        $missing = array_values(array_filter(
            self::importedShopwareClasses(),
            static fn(string $class): bool => !self::existsAt($repository, $class),
        ));

        self::assertSame(
            [],
            $missing,
            sprintf(
                "These Shopware classes do not exist at the support floor %s:\n  %s\n"
                . 'Either stop using them, or raise the floor in composer.json, the README and this test.',
                self::FLOOR_TAG,
                implode("\n  ", $missing),
            ),
        );
    }

    /**
     * Attribute arguments are the trap the class check cannot catch: they are
     * resolved at `newInstance()` while core builds the container, so an
     * argument the installed core does not declare is a hard failure rather
     * than a type error somebody might notice in review.
     */
    public function testDalAttributeArgumentsExistAtTheSupportFloor(): void
    {
        $repository = self::coreClone();
        $declared = [];

        foreach (self::DAL_ATTRIBUTES as $attribute) {
            $source = (string) shell_exec(sprintf(
                'git -C %s show %s 2>/dev/null',
                escapeshellarg($repository),
                escapeshellarg(self::FLOOR_TAG . ':' . self::pathFor($attribute)),
            ));

            self::assertNotSame('', trim($source), 'could not read ' . $attribute . ' at ' . self::FLOOR_TAG);

            preg_match_all('/(?:public|protected|private)\s+[^\s$]+\s+\$(\w+)/', $source, $matches);
            $declared += array_fill_keys($matches[1], true);
        }

        $unknown = array_diff_key(self::attributeArgumentsUsed(), $declared);

        self::assertSame(
            [],
            array_keys($unknown),
            sprintf(
                "These DAL attribute arguments do not exist at the support floor %s: %s\n"
                . 'Passing one is an Error when core builds the container.',
                self::FLOOR_TAG,
                json_encode($unknown, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    /**
     * Named arguments passed to `#[Field(...)]` / `#[Entity(...)]` anywhere in
     * src, as argument name => the file that uses it.
     *
     * Split on the attribute opener and cut at the first `)]` rather than
     * matching one regex over the whole attribute: `api:` is an array literal,
     * so a pattern that stops at the first `]` never sees the arguments after
     * it — which is exactly how the first version of this guard passed while
     * the bug it exists for was present.
     *
     * @return array<string, string>
     */
    private static function attributeArgumentsUsed(): array
    {
        $used = [];

        foreach (self::sourceFiles() as $file) {
            $chunks = (array) preg_split('/#\[(?:Field|Entity)\(/', (string) file_get_contents($file));

            $arguments = implode(' , ', array_map(
                static fn(string $rest): string => explode(')]', $rest, limit: 2)[0],
                \array_slice($chunks, offset: 1),
            ));

            // Anchored on `(`, `,` or the start so that a hyphenated array key
            // such as 'admin-api' => true is not misread as an argument name.
            preg_match_all('/(?:^|[(,]\s*)(\w+):(?!:)/', $arguments, $named);
            $used += array_fill_keys($named[1], $file);
        }

        return $used;
    }

    /** @return list<string> */
    private static function importedShopwareClasses(): array
    {
        $classes = [];

        foreach (self::sourceFiles() as $file) {
            // `use function` / `use const` carry their keyword and so do not
            // match; `use X as Y` is trimmed back to X below.
            preg_match_all('/^use\s+(Shopware\\\\[^\s;]+)/m', (string) file_get_contents($file), $matches);
            $classes += array_fill_keys($matches[1], true);
        }

        $names = array_keys($classes);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::SOURCE_DIR,
            \FilesystemIterator::SKIP_DOTS,
        ));

        /** @var list<string> $files */
        $files = array_keys(iterator_to_array(new \RegexIterator($tree, '/\.php$/')));

        sort($files);

        return $files;
    }

    /** Shopware\Core\Framework\Context -> src/Core/Framework/Context.php */
    private static function pathFor(string $class): string
    {
        return 'src/' . str_replace('\\', '/', substr($class, \strlen('Shopware\\'))) . '.php';
    }

    private static function existsAt(string $repository, string $class): bool
    {
        exec(
            sprintf(
                'git -C %s cat-file -e %s 2>/dev/null',
                escapeshellarg($repository),
                escapeshellarg(self::FLOOR_TAG . ':' . self::pathFor($class)),
            ),
            $output,
            $status,
        );

        return $status === 0;
    }

    /**
     * A clone of shopware/shopware carrying the floor tag, from `MQ_CORE_CLONE`
     * or the usual spot beside this repository. Skips rather than fails when
     * there is none, so the suite still runs on a machine without one.
     */
    private static function coreClone(): string
    {
        $candidate = getenv('MQ_CORE_CLONE') ?: getenv('HOME') . '/projects/shopware';

        if (!is_dir($candidate . '/.git')) {
            self::markTestSkipped('No shopware/shopware clone found; set MQ_CORE_CLONE to run this guard.');
        }

        if (!self::existsAt($candidate, 'Shopware\Core\Framework\Context')) {
            self::markTestSkipped(sprintf(
                'The core clone at %s has no %s tag; fetch tags to run this guard.',
                $candidate,
                self::FLOOR_TAG,
            ));
        }

        return $candidate;
    }
}
