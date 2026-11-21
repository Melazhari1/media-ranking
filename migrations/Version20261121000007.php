<?php

declare(strict_types=1);

namespace App\Migrations;

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
        $this->addSql("CREATE TABLE settings (
            name       VARCHAR(64) NOT NULL,
            value      TEXT        NULL,
            updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
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
