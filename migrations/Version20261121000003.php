<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Database\TableOptions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1: external metadata (Jikan / TMDB), genres, numeric release year.
 *
 * release_year is a STORED generated column parsed from the free-text `year`
 * ("2019-Q1" → 2019, "N/A" → NULL), so stats and year sorting don't need string parsing.
 * Nothing in `year` changes.
 *
 * The expression is deliberately plain (LEFT/SUBSTRING/IN, no REGEXP) because MariaDB refuses
 * REGEXP in generated columns (#1901). New tables inherit the database's default collation so the
 * same migrations run on MySQL 8+ and MariaDB 10.6+.
 */
final class Version20261121000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add external metadata columns, release_year, genres + media_genre';
    }

    public function up(Schema $schema): void
    {
        $options = TableOptions::sql($this->connection);
        $this->addSql("ALTER TABLE media
            ADD COLUMN release_year SMALLINT UNSIGNED
                GENERATED ALWAYS AS (IF(
                    LEFT(year, 1) IN ('1', '2')
                    AND SUBSTRING(year, 2, 1) IN ('0','1','2','3','4','5','6','7','8','9')
                    AND SUBSTRING(year, 3, 1) IN ('0','1','2','3','4','5','6','7','8','9')
                    AND SUBSTRING(year, 4, 1) IN ('0','1','2','3','4','5','6','7','8','9'),
                    CAST(LEFT(year, 4) AS UNSIGNED), NULL)) STORED
                AFTER year,
            ADD COLUMN external_source VARCHAR(10)  NULL,
            ADD COLUMN external_id     VARCHAR(32)  NULL,
            ADD COLUMN synopsis        TEXT         NULL,
            ADD COLUMN backdrop_url    VARCHAR(500) NULL,
            ADD COLUMN last_synced_at  DATETIME     NULL,
            ADD INDEX idx_media_external (external_source, external_id),
            ADD INDEX idx_media_release_year (release_year)");

        $this->addSql("CREATE TABLE genres (
            id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(64)  NOT NULL,
            slug VARCHAR(64)  NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_genres_slug (slug)
        ) " . $options);

        $this->addSql("CREATE TABLE media_genre (
            media_id INT          NOT NULL,
            genre_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (media_id, genre_id),
            KEY idx_media_genre_genre (genre_id),
            CONSTRAINT fk_media_genre_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE,
            CONSTRAINT fk_media_genre_genre FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE
        ) " . $options);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_genre');
        $this->addSql('DROP TABLE genres');
        $this->addSql("ALTER TABLE media
            DROP INDEX idx_media_external,
            DROP INDEX idx_media_release_year,
            DROP COLUMN release_year,
            DROP COLUMN external_source,
            DROP COLUMN external_id,
            DROP COLUMN synopsis,
            DROP COLUMN backdrop_url,
            DROP COLUMN last_synced_at");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
