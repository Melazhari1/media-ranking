<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Phase 1: custom lists with an ordered many-to-many to media. */
final class Version20261121000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_lists + user_list_media';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE user_lists (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(100) NOT NULL,
            slug        VARCHAR(100) NOT NULL,
            description TEXT         NULL,
            is_public   TINYINT(1)   NOT NULL DEFAULT 0,
            position    INT          NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_lists_slug (slug),
            KEY idx_user_lists_position (position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

        $this->addSql("CREATE TABLE user_list_media (
            list_id  INT UNSIGNED NOT NULL,
            media_id INT          NOT NULL,
            position INT          NOT NULL DEFAULT 0,
            added_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (list_id, media_id),
            KEY idx_user_list_media_order (list_id, position),
            KEY idx_user_list_media_media (media_id),
            CONSTRAINT fk_user_list_media_list  FOREIGN KEY (list_id)  REFERENCES user_lists (id) ON DELETE CASCADE,
            CONSTRAINT fk_user_list_media_media FOREIGN KEY (media_id) REFERENCES media (id)      ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_list_media');
        $this->addSql('DROP TABLE user_lists');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
