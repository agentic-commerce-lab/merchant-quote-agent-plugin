<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// shipmonk/composer-dependency-analyser: detects unused + missing/shadow composer deps.
// Backs the `quality:depcheck` task. Adjust the scanned paths to the project's layout.
// See https://github.com/shipmonk-rnd/composer-dependency-analyser for the full config API.
//
// Add `->ignoreErrorsOnPackage('<vendor/pkg>', [ErrorType::UNUSED_DEPENDENCY])` (with
// `use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;`) only for a real finding —
// the analyser reports unmatched ignores as errors, so do not pre-declare ignores that do
// not yet apply.
$config = (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    // Symfony's PHP-DI DSL helper used by src/Resources/config/services.php. It
    // ships in symfony/dependency-injection, which IS declared in composer.json
    // (^7.4). The analyser resolves symbols to packages through the classmap,
    // and Composer cannot autoload free functions at all — `service()` is
    // declared at the foot of the PSR-4 class file Loader/Configurator/
    // ContainerConfigurator.php, so the function symbol has no package to
    // attribute it to. A tool limitation, not an undeclared dependency.
    ->ignoreUnknownFunctions(['Symfony\Component\DependencyInjection\Loader\Configurator\service'])
    // Declared in Task 1 of issue #9 for UcpResponseFactory, which Task 6 wires up.
    // Drop this ignore once that task lands and the package is actually used.
    ->ignoreErrorsOnPackage('ucp-php-sdk/symfony-bundle', [ErrorType::UNUSED_DEPENDENCY]);

// Scan tests as dev paths only when the directory exists (addPathToScan throws on a
// missing path, which would break the gate on projects without a tests/ directory).
if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

return $config;
