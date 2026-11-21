<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 0: sort_title, public score (generic replacement for score_mal) and soft delete.
 *
 * Additive only — no existing value is modified. score_mal stays in place (and is still
 * written by the app) so rolling back is lossless. Data is filled afterwards by:
 *   php bin/console app:backfill-public-scores
 *   php bin/console app:normalize-titles
 */
final class Version20261121000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media.sort_title, public_score, score_source, deleted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media
            ADD COLUMN sort_title   VARCHAR(255) NULL AFTER title,
            ADD COLUMN public_score DECIMAL(4,2) NULL AFTER score_mal,
            ADD COLUMN score_source VARCHAR(10)  NULL AFTER public_score,
            ADD COLUMN deleted_at   DATETIME     NULL,
            ADD INDEX idx_media_sort_title (sort_title),
            ADD INDEX idx_media_deleted_at (deleted_at)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media
            DROP INDEX idx_media_sort_title,
            DROP INDEX idx_media_deleted_at,
            DROP COLUMN sort_title,
            DROP COLUMN public_score,
            DROP COLUMN score_source,
            DROP COLUMN deleted_at");
    }

    public function isTransactional(): bool
    {
        // MySQL DDL commits implicitly.
        return false;
    }
}
