<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Database\TableOptions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Phase 1: free-form tags (many-to-many with media). */
final class Version20261121000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tags + media_tag';
    }

    public function up(Schema $schema): void
    {
        $options = TableOptions::sql($this->connection);
        $this->addSql("CREATE TABLE tags (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(64)  NOT NULL,
            slug       VARCHAR(64)  NOT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_tags_slug (slug)
        ) " . $options);

        $this->addSql("CREATE TABLE media_tag (
            media_id INT          NOT NULL,
            tag_id   INT UNSIGNED NOT NULL,
            PRIMARY KEY (media_id, tag_id),
            KEY idx_media_tag_tag (tag_id),
            CONSTRAINT fk_media_tag_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE,
            CONSTRAINT fk_media_tag_tag   FOREIGN KEY (tag_id)   REFERENCES tags (id)  ON DELETE CASCADE
        ) " . $options);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_tag');
        $this->addSql('DROP TABLE tags');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
