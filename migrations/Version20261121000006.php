<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1: indexes for the filters/sorts used on every page load (~30k rows had none).
 * No foreign keys on the legacy category_id / rating_id columns, so a dangling id can never
 * block the migration — those rows are reported by app:report-uncategorized instead.
 */
final class Version20261121000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media indexes: status, category, rating, created_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media
            ADD INDEX idx_media_status (status),
            ADD INDEX idx_media_category (category_id),
            ADD INDEX idx_media_rating (rating_id),
            ADD INDEX idx_media_created_at (created_at)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media
            DROP INDEX idx_media_status,
            DROP INDEX idx_media_category,
            DROP INDEX idx_media_rating,
            DROP INDEX idx_media_created_at");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
