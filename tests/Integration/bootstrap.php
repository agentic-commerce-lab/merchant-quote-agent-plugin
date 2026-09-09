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
// Read straight out of the env files rather than through symfony/dotenv: that
// would be a shadow dependency (quality:depcheck) for one line of parsing. It
// also keeps `.env.test` out of this: that file DOES exist here and sets
// KERNEL_CLASS to App\Kernel, which is not this shop's kernel, so loadEnv()
// pulling it in is a hazard rather than a help.
//
// `.env.local` WINS over `.env`, which is Symfony's own precedence and is
// load-bearing rather than tidiness. The shopdev hosts keep a template
// DATABASE_URL in `.env` and the real credentials in `.env.local`, and the two
// point at different databases. Reading `.env` alone there hands
// TestBootstrapper a URL whose database has no `plugin` table, and
// `bootstrap()` reacts to that by running SystemInstallCommand — i.e. it
// INSTALLS SHOPWARE over whatever the template happens to name. On
// `agenticquote` that only stopped because the template's `root@localhost` was
// denied; a template with working credentials would have taken the database
// with it. See the guard below.
$envFiles = [$shopRoot . '/.env', $shopRoot . '/.env.local'];
$databaseUrl = null;

foreach ($envFiles as $envFile) {
    $envContents = is_readable($envFile) ? (string) file_get_contents($envFile) : '';

    if (preg_match('/^DATABASE_URL=[\'"]?(?<url>[^\'"\r\n]+)/m', $envContents, $matches) === 1) {
        $databaseUrl = $matches['url'];
    }
}

if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new \RuntimeException(
        'DATABASE_URL could not be read from '
        . implode(' or ', $envFiles)
        . " — refusing to fall through to TestBootstrapper's _test default, "
        . 'which cannot boot this container.',
    );
}

// Refuse an uninstalled database instead of letting TestBootstrapper install
// one. `bootstrap()` runs `install()` when `!$this->pluginTableExists()`, and
// that is SystemInstallCommand against the URL above — destructive, silent
// about what it is about to do, and reached by nothing more exotic than a
// mis-read env file. These suites only ever run against an ALREADY installed
// shop (that is the whole reason the URL is not `_test`-suffixed), so "no
// plugin table" is always an operator error here and never a state to repair.
$dsn = parse_url($databaseUrl);

if (!\is_array($dsn) || !isset($dsn['host'], $dsn['path'])) {
    throw new \RuntimeException(sprintf('DATABASE_URL is not a parsable URL: %s', $databaseUrl));
}

$database = rawurldecode(ltrim($dsn['path'], characters: '/'));

try {
    $probe = new \PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', $dsn['host'], $dsn['port'] ?? 3306, $database),
        rawurldecode($dsn['user'] ?? ''),
        rawurldecode($dsn['pass'] ?? ''),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
    );
    $installed = (bool) $probe
        ->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'plugin'",
        )
        ?->fetchColumn();
} catch (\PDOException $e) {
    throw new \RuntimeException(
        sprintf(
            'Could not reach the database named by DATABASE_URL (%s on %s). Refusing to continue: '
            . 'TestBootstrapper would read the unreachable database as uninstalled and run '
            . 'SystemInstallCommand against it.',
            $database,
            $dsn['host'],
        ),
        previous: $e,
    );
}

if (!$installed) {
    throw new \RuntimeException(sprintf(
        'The database named by DATABASE_URL (%s on %s) has no `plugin` table, so it is not an '
        . 'installed Shopware. Refusing to continue: TestBootstrapper would INSTALL over it. '
        . 'Check which env file supplied the URL — a template DATABASE_URL in `.env` overridden '
        . 'by the real one in `.env.local` is the usual cause.',
        $database,
        $dsn['host'],
    ));
}

(new TestBootstrapper())
    ->setProjectDir($shopRoot)
    ->setClassLoader($loader)
    ->setLoadEnvFile(true)
    ->setDatabaseUrl($databaseUrl)
    ->setForceInstall(false)
    ->bootstrap();
