<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Database\TableOptions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Phase 4: key/value settings (public profile options). */
final class Version20261121000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add settings table';
    }

    public function up(Schema $schema): void
    {
        $options = TableOptions::sql($this->connection);
        $this->addSql("CREATE TABLE settings (
            name       VARCHAR(64) NOT NULL,
            value      TEXT        NULL,
            updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) " . $options);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
