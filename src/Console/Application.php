<?php

namespace App\Console;

use App\Console\Command\BackfillMetadataCommand;
use App\Console\Command\BackfillPublicScoresCommand;
use App\Console\Command\ExportCommand;
use App\Console\Command\ImportCommand;
use App\Console\Command\NormalizeTitlesCommand;
use App\Console\Command\PurgeDeletedCommand;
use App\Console\Command\ReportUncategorizedCommand;
use App\Console\Command\ResetImportedRatingsCommand;
use App\Services;
use Database;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command as Migrations;
use PDO;
use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    private ?PDO $pdo = null;

    public function __construct(?Services $services = null)
    {
        parent::__construct('MMR console', '2.0');

        $services ??= new Services(null, dirname(__DIR__, 2));
        $pdo = fn (): PDO => $this->pdo ??= $services->pdo();
        $varDir = $services->varDir();

        $this->addCommands([
            new NormalizeTitlesCommand($pdo, $varDir),
            new ReportUncategorizedCommand($pdo, $varDir),
            new BackfillPublicScoresCommand($pdo, $varDir),
            new PurgeDeletedCommand($pdo),
            new ResetImportedRatingsCommand($pdo, $varDir),
            new BackfillMetadataCommand($services),
            new ImportCommand($services),
            new ExportCommand($services),
        ]);

        $this->addMigrationCommands();
    }

    public static function migrationsDependencyFactory(): DependencyFactory
    {
        $c = Database::config();
        $connection = DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => $c['host'],
            'port'     => $c['port'],
            'dbname'   => $c['dbname'],
            'user'     => $c['user'],
            'password' => $c['password'],
            'charset'  => $c['charset'],
        ]);

        $config = new ConfigurationArray([
            'table_storage' => [
                'table_name' => 'doctrine_migration_versions',
            ],
            'migrations_paths' => [
                'App\\Migrations' => dirname(__DIR__, 2) . '/migrations',
            ],
            'all_or_nothing' => false,
            'check_database_platform' => false,
        ]);

        return DependencyFactory::fromConnection($config, new ExistingConnection($connection));
    }

    private function addMigrationCommands(): void
    {
        $factory = self::migrationsDependencyFactory();
        $this->addCommands([
            new Migrations\CurrentCommand($factory),
            new Migrations\ExecuteCommand($factory),
            new Migrations\GenerateCommand($factory),
            new Migrations\LatestCommand($factory),
            new Migrations\ListCommand($factory),
            new Migrations\MigrateCommand($factory),
            new Migrations\StatusCommand($factory),
            new Migrations\SyncMetadataCommand($factory),
            new Migrations\UpToDateCommand($factory),
            new Migrations\VersionCommand($factory),
        ]);
    }
}
