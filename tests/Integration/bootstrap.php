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
// Read straight out of `.env` rather than through symfony/dotenv: that would be
// a shadow dependency (quality:depcheck) for one line of parsing. It also keeps
// `.env.test` out of this: that file DOES exist here and sets KERNEL_CLASS to
// App\Kernel, which is not this shop's kernel, so loadEnv() pulling it in is a
// hazard rather than a help. DATABASE_URL is defined in `.env` alone — neither
// `.env.local` nor `.env.test` carries it — so one file is the whole story.
$envFile = $shopRoot . '/.env';
$envContents = is_readable($envFile) ? (string) file_get_contents($envFile) : '';
preg_match('/^DATABASE_URL=[\'"]?(?<url>[^\'"\r\n]+)/m', $envContents, $matches);
$databaseUrl = $matches['url'] ?? null;

if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new \RuntimeException(
        'DATABASE_URL could not be read from '
        . $envFile
        . ' — refusing to fall through to '
        . "TestBootstrapper's _test default, which cannot boot this container.",
    );
}

(new TestBootstrapper())
    ->setProjectDir($shopRoot)
    ->setClassLoader($loader)
    ->setLoadEnvFile(true)
    ->setDatabaseUrl($databaseUrl)
    ->setForceInstall(false)
    ->bootstrap();
