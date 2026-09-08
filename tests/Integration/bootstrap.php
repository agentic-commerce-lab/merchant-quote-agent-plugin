<?php

declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

// `?:` not `!== false`: a set-but-empty SHOPWARE_ROOT (common in CI env blocks) must fall
// back to the default, not send us to require '/vendor/autoload.php'.
$shopRoot = getenv('SHOPWARE_ROOT') ?: '/var/www/html';

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $shopRoot . '/vendor/autoload.php';
$loader->addPsr4('MerchantQuoteAgentPlugin\\', __DIR__ . '/../../src/');
$loader->addPsr4('MerchantQuoteAgentPlugin\\Tests\\', __DIR__ . '/../');

// Force env=test: the shop's .env sets APP_ENV=dev, but only
// config/packages/test/framework.yaml turns on framework.test (which is what
// provides `test.service_container`, the private-services-visible container
// KernelTestBehaviour requires). Set before loadEnv() so it isn't overwritten
// — Dotenv never overrides an already-set var.
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';

// The shop's .env.test carries an unedited Symfony-skeleton default,
// KERNEL_CLASS='App\Kernel' — there is no App\Kernel in a Shopware project.
// Override it the same way, before loadEnv() reads that file.
$_SERVER['KERNEL_CLASS'] = \Shopware\Core\Kernel::class;
$_ENV['KERNEL_CLASS'] = \Shopware\Core\Kernel::class;

// The shop's own DATABASE_URL, NOT suffixed with _test: TestBootstrapper would
// otherwise target a fresh shopware_test DB, and a zero-plugin DB cannot boot
// this container (a stray always-loaded config/packages/zz-ucp-sdk-test.yaml
// sets a ucp_sdk key that only registers when SwagAgenticCommerce is active).
// Using the installed DB also means SwagCommercial's services are already
// active — this plugin is never installed into Shopware for these tests, it
// only needs to autoload and reach the container (see PSR-4 registration
// above and ADR 0001 / Task 1 report for why plugin:install is skipped).
//
// Read straight out of `.env.local` / `.env` rather than through symfony/dotenv:
// that would be a shadow dependency (quality:depcheck) for a few lines of
// parsing. Try `.env.local` first and fall back to `.env` — that's Symfony's
// own precedence (.env.local overrides .env), and it matters here: on a
// Docker shop DATABASE_URL lives in `.env` alone, but on a normally-provisioned
// host `.env` keeps Shopware's skeleton default and only `.env.local` carries
// the real credentials. Reading `.env` alone silently connects as the wrong
// user to the wrong database instead of this shop's real one. `.env.test` is
// still deliberately left out of the search: it DOES exist here too and sets
// KERNEL_CLASS to App\Kernel, which is not this shop's kernel, so letting it
// contribute would be a hazard rather than a help.
$envFiles = [$shopRoot . '/.env.local', $shopRoot . '/.env'];
$databaseUrl = null;
foreach ($envFiles as $envFile) {
    $envContents = is_readable($envFile) ? (string) file_get_contents($envFile) : '';
    preg_match('/^DATABASE_URL=[\'"]?(?<url>[^\'"\r\n]+)/m', $envContents, $matches);
    if (isset($matches['url']) && $matches['url'] !== '') {
        $databaseUrl = $matches['url'];
        break;
    }
}

if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new \RuntimeException(
        'DATABASE_URL could not be read from any of: '
        . implode(', ', $envFiles)
        . ' — refusing to fall through to '
        . "TestBootstrapper's _test default, which cannot boot this container.",
    );
}

// TestBootstrapper::pluginTableExists() (vendor/shopware/core/TestBootstrapper.php)
// catches every \Throwable while building the kernel container, not just "table
// doesn't exist" — so a bad credential, a missing config, or any other container-
// build failure reads identically to "this database has no Shopware in it", and
// bootstrap() answers both by running SystemInstallCommand against whatever
// DATABASE_URL points at. setForceInstall(false) does not stop this: it's the
// second condition, !pluginTableExists(), that fires. Verify the database
// ourselves first, with a plain PDO connection (no doctrine/dbal, no new
// Composer dependency — this is a guard, not a framework), so a wrong-database
// mistake is a clear refusal instead of a silent install.
assertShopIsInstalled($databaseUrl);

(new TestBootstrapper())
    ->setProjectDir($shopRoot)
    ->setClassLoader($loader)
    ->setLoadEnvFile(true)
    ->setDatabaseUrl($databaseUrl)
    ->setForceInstall(false)
    ->bootstrap();

function urlPart(array $parts, string $key): ?string
{
    return isset($parts[$key]) ? rawurldecode($parts[$key]) : null;
}

function assertShopIsInstalled(string $databaseUrl): void
{
    $parts = parse_url($databaseUrl);
    $host = $parts['host'] ?? null;
    $port = $parts['port'] ?? 3306;
    $dbName = ltrim($parts['path'] ?? '', '/');
    $user = urlPart($parts, 'user');
    $pass = urlPart($parts, 'pass');

    if ($host === null || $host === '' || $dbName === '') {
        throw new \RuntimeException(
            'DATABASE_URL could not be parsed into a host and database name — refusing to guess where to connect.',
        );
    }

    // Never interpolate $pass into a message: this is what an operator sees on
    // a misconfigured host, and it must be safe to paste into a bug report.
    $target = "{$host}:{$port}/{$dbName}";
    $refusal =
        'This suite refuses to run against a database that does not already hold an '
        . 'installed Shopware, and it will not install one — unlike '
        . 'TestBootstrapper::pluginTableExists(), which treats "cannot reach the database" and '
        . '"database is empty" as the same signal and answers both by running the installer.';

    try {
        $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$dbName}", $user, $pass);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pluginCount = (int) $pdo->query('SELECT COUNT(*) FROM `plugin`')->fetchColumn();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Could not verify an installed Shopware at {$target}: {$e->getMessage()}. {$refusal}",
            previous: $e,
        );
    }

    if ($pluginCount === 0) {
        throw new \RuntimeException("Database at {$target} has an empty `plugin` table. {$refusal}");
    }
}
