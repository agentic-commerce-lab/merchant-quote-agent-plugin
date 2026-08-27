<?php

declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$shopRoot = getenv('SHOPWARE_ROOT') !== false ? getenv('SHOPWARE_ROOT') : '/var/www/html';

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
(new \Symfony\Component\Dotenv\Dotenv())
    ->usePutenv()
    ->loadEnv($shopRoot . '/.env');
$databaseUrl = $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new \RuntimeException('DATABASE_URL could not be read from ' . $shopRoot . '/.env');
}

(new TestBootstrapper())
    ->setProjectDir($shopRoot)
    ->setClassLoader($loader)
    ->setLoadEnvFile(true)
    ->setDatabaseUrl($databaseUrl)
    ->setForceInstall(false)
    ->bootstrap();
