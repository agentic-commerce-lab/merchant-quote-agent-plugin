<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use MerchantQuoteAgentPlugin\Scripts\OrderHistory\OrderHistorySeeder;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Kernel;
use Symfony\Component\Dotenv\Dotenv;

/**
 * @param array<int, string> $arguments
 * @return array{perCustomer: int, productId: ?string}
 */
function orderHistoryOptions(array $arguments): array
{
    $options = ['perCustomer' => 4, 'productId' => null];
    foreach ($arguments as $argument) {
        $match = [];
        if (
            preg_match('/^--per-customer=([1-9][0-9]?)$/D', $argument, $match) === 1
            && isset($match[1])
            && (int) $match[1] <= 24
        ) {
            $options['perCustomer'] = (int) $match[1];
        } elseif (preg_match('/^--product-id=([a-f0-9]{32})$/Di', $argument, $match) === 1 && isset($match[1])) {
            $options['productId'] = strtolower($match[1]);
        } else {
            throw new InvalidArgumentException('Expected --per-customer=1..24 or --product-id=<32 hex characters>; got '
            . $argument);
        }
    }
    return $options;
}

try {
    if (in_array('--help', array_slice($argv, 1), true)) {
        fwrite(
            STDOUT,
            "Usage: APP_ENV=dev php scripts/seed-order-history.php [--per-customer=4] [--product-id=<id>]\n"
            . "Run inside the installed plugin (or set SHOPWARE_ROOT). Dev/test only.\n"
            . "Creates 1..24 real quote orders per live quote customer; existing seed slots are skipped.\n",
        );
        exit(0);
    }
    $options = orderHistoryOptions(array_slice($argv, 1));
    // An explicit unsafe environment is rejected even outside an installed shop.
    $externalEnvironment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
    if (is_string($externalEnvironment) && !in_array($externalEnvironment, ['dev', 'test'], true)) {
        throw new RuntimeException('Refusing order-history seeding: APP_ENV must be dev or test.');
    }
    $root = getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4);
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException(
            'Run this script in the installed Shopware plugin, or set SHOPWARE_ROOT to its shop root.',
        );
    }
    $classLoader = require $root . '/vendor/autoload.php';
    if (!$classLoader instanceof ClassLoader) {
        throw new RuntimeException('The installed Shopware autoloader did not return a Composer ClassLoader.');
    }
    (new Dotenv())->bootEnv($root . '/.env');
    $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
    if (!in_array($environment, ['dev', 'test'], true)) {
        throw new RuntimeException('Refusing order-history seeding: effective APP_ENV must be dev or test.');
    }
    // Runtime boot deliberately uses the installed DBAL plugin loader. The
    // testing bootstrap would override APP_ENV and defeat the production guard.
    $kernel = KernelFactory::create($environment, false, $classLoader);
    if (!$kernel instanceof Kernel) {
        throw new RuntimeException('The installed Shopware kernel could not be created.');
    }
    $kernel->boot();
    require __DIR__ . '/order-history/SeedData.php';
    require __DIR__ . '/order-history/SeedServices.php';
    require __DIR__ . '/order-history/SeedSelection.php';
    require __DIR__ . '/order-history/SeedQuoteWriter.php';
    require __DIR__ . '/order-history/SeedOrderWriter.php';
    require __DIR__ . '/order-history/OrderHistorySeeder.php';
    (new OrderHistorySeeder($kernel->getContainer()))->run($options['perCustomer'], $options['productId']);
    $kernel->shutdown();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
