<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Dotenv\Dotenv;

$appRoot = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'App';
$autoload = $appRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Host dependencies are missing; cannot validate Withdrawing schema parity.\n");
    exit(2);
}

require $autoload;

if (!Type::hasType('uuid')) {
    Type::addType('uuid', UuidType::class);
}

(new Dotenv())->bootEnv($appRoot.DIRECTORY_SEPARATOR.'.env');

$databaseUrl = $_SERVER['PLATFORM_DATA_DATABASE'] ?? $_ENV['PLATFORM_DATA_DATABASE']
    ?? $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
if (!is_string($databaseUrl) || '' === trim($databaseUrl)) {
    fwrite(STDERR, "PostgreSQL connection URL is unavailable; cannot validate Withdrawing schema parity.\n");
    exit(2);
}

$config = ORMSetup::createAttributeMetadataConfiguration(
    [dirname(__DIR__).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Entity'],
    true,
);
$config->enableNativeLazyObjects(true);
$parts = parse_url($databaseUrl);
if (false === $parts || !isset($parts['host'], $parts['path'])) {
    fwrite(STDERR, "PostgreSQL connection URL is invalid; cannot validate Withdrawing schema parity.\n");
    exit(2);
}

$params = [
    'driver' => 'pdo_pgsql',
    'host' => $parts['host'],
    'port' => $parts['port'] ?? 5432,
    'dbname' => ltrim($parts['path'], '/'),
    'serverVersion' => '16',
];
if (isset($parts['user'])) {
    $params['user'] = rawurldecode($parts['user']);
}
if (isset($parts['pass'])) {
    $params['password'] = rawurldecode($parts['pass']);
}
$connection = DriverManager::getConnection($params);
$manager = new EntityManager($connection, $config);

try {
    $metadata = $manager->getMetadataFactory()->getAllMetadata();
    $expected = (new SchemaTool($manager))->getSchemaFromMetadata($metadata);
    $schemaManager = $connection->createSchemaManager();
    $comparator = $schemaManager->createComparator();

    $drift = [];
    foreach (['withdrawal_request', 'withdrawal_settlement_event'] as $tableName) {
        if (!$schemaManager->tableExists($tableName)) {
            $drift[] = sprintf('%s: table is missing', $tableName);
            continue;
        }
        if (!$expected->hasTable($tableName)) {
            $drift[] = sprintf('%s: table is absent from current Doctrine metadata', $tableName);
            continue;
        }

        $diff = $comparator->compareTables($schemaManager->introspectTable($tableName), $expected->getTable($tableName));
        if (!$diff->isEmpty()) {
            $drift[] = sprintf('%s: database table differs from current Doctrine metadata', $tableName);
        }
    }

    $migrationFiles = glob(dirname(__DIR__).DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'Version*.php') ?: [];
    $expectedVersions = array_map(
        static fn (string $path): string => 'App\\Withdrawing\\Migrations\\'.pathinfo($path, PATHINFO_FILENAME),
        $migrationFiles,
    );
    $executedVersions = $connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
    $executed = array_fill_keys(array_map('strval', $executedVersions), true);
    foreach ($expectedVersions as $version) {
        if (!isset($executed[$version])) {
            $drift[] = sprintf('%s: migration is not recorded as executed', $version);
        }
    }

    if ([] !== $drift) {
        fwrite(STDERR, "Withdrawing schema parity failed:\n- ".implode("\n- ", $drift)."\n");
        exit(1);
    }

    fwrite(STDOUT, sprintf(
        "Withdrawing schema parity is synchronized (%d tables, %d migrations).\n",
        2,
        count($expectedVersions),
    ));
} finally {
    $connection->close();
}
