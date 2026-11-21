<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Database\TableOptions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1: progress tracking, dates, rewatches.
 *
 * Status stays a VARCHAR(20) holding the existing display values; the allowed set
 * (old + Watching/Reading/On Hold/Dropped/Rewatching) is enforced by App\Domain\MediaStatus.
 * No existing value is modified.
 */
final class Version20261121000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add progress, started/finished dates, rewatch_count and rewatch_log';
    }

    public function up(Schema $schema): void
    {
        $options = TableOptions::sql($this->connection);
        $this->addSql("ALTER TABLE media
            ADD COLUMN progress_current INT UNSIGNED NULL AFTER status,
            ADD COLUMN progress_total   INT UNSIGNED NULL AFTER progress_current,
            ADD COLUMN progress_unit    VARCHAR(10)  NULL AFTER progress_total,
            ADD COLUMN started_at       DATE         NULL AFTER progress_unit,
            ADD COLUMN finished_at      DATE         NULL AFTER started_at,
            ADD COLUMN rewatch_count    INT UNSIGNED NOT NULL DEFAULT 0 AFTER finished_at,
            ADD INDEX idx_media_finished_at (finished_at)");

        // History of rewatches/rereads (Phase 4 diary). Rows vanish only if the media row is purged.
        $this->addSql("CREATE TABLE rewatch_log (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            media_id    INT          NOT NULL,
            started_at  DATE         NULL,
            finished_at DATE         NULL,
            notes       TEXT         NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rewatch_log_media (media_id),
            KEY idx_rewatch_log_finished (finished_at),
            CONSTRAINT fk_rewatch_log_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
        ) " . $options);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rewatch_log');
        $this->addSql("ALTER TABLE media
            DROP INDEX idx_media_finished_at,
            DROP COLUMN progress_current,
            DROP COLUMN progress_total,
            DROP COLUMN progress_unit,
            DROP COLUMN started_at,
            DROP COLUMN finished_at,
            DROP COLUMN rewatch_count");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
