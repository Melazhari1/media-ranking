<?php

namespace App\Tests\Integration;

use InvalidArgumentException;
use MediaManager;

/** Phase 1 data model: tracking fields, statuses, release_year, join tables. */
final class DataModelTest extends DatabaseTestCase
{
    private MediaManager $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new MediaManager(self::$pdo);
    }

    public function testDefaultsForExistingRows(): void
    {
        $id  = $this->insertRaw(['title' => 'Legacy row']);
        $row = $this->m->getMediaById($id);
        self::assertNull($row['progress_current']);
        self::assertNull($row['finished_at']);
        self::assertSame(0, (int) $row['rewatch_count']);
        self::assertArrayHasKey('synopsis', $row);
    }

    public function testReleaseYearIsParsedFromYear(): void
    {
        $a = $this->insertRaw(['title' => 'Q', 'year' => '2019-Q1']);
        $b = $this->insertRaw(['title' => 'Plain', 'year' => '2006']);
        $c = $this->insertRaw(['title' => 'NA', 'year' => 'N/A']);
        self::assertSame(2019, (int) $this->fetchRow($a)['release_year']);
        self::assertSame(2006, (int) $this->fetchRow($b)['release_year']);
        self::assertNull($this->fetchRow($c)['release_year']);

        // Year sort is numeric, unknown years last in both directions
        self::assertSame(['Q', 'Plain', 'NA'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.year DESC'), 'title'));
        self::assertSame(['Plain', 'Q', 'NA'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.year ASC'), 'title'));
    }

    public function testNewStatusesAreAcceptedAndFilterable(): void
    {
        foreach (['Watching', 'On Hold', 'Dropped', 'Rewatching', 'Reading'] as $status) {
            $this->m->createMedia("Item $status", 'a.jpg', 1, 1, '2020', 0, null, $status);
        }
        self::assertSame(2, $this->m->countFilteredMedia('', [], [], ['On Hold', 'Dropped']));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->m->createMedia('Bad', 'a.jpg', 1, 1, '2020', 0, null, 'Binge');
    }

    public function testUpdateTrackingPartial(): void
    {
        $id = (int) $this->m->createMedia('Show', 'a.jpg', 1, 1, '2020', 0, null, 'Watching');

        $row = $this->m->updateTracking($id, ['progress_current' => 7, 'progress_total' => '24', 'progress_unit' => 'episode', 'started_at' => '2026-11-01']);
        self::assertSame([7, 24, 'episode', '2026-11-01'], [(int) $row['progress_current'], (int) $row['progress_total'], $row['progress_unit'], $row['started_at']]);

        // Only the given key changes
        $row = $this->m->updateTracking($id, ['progress_current' => 8]);
        self::assertSame(8, (int) $row['progress_current']);
        self::assertSame(24, (int) $row['progress_total']);

        // Unknown keys are ignored, nulls clear
        $row = $this->m->updateTracking($id, ['title' => 'hacked', 'progress_total' => null]);
        self::assertSame('Show', $row['title']);
        self::assertNull($row['progress_total']);

        self::assertFalse($this->m->updateTracking(999999, ['progress_current' => 1]));
    }

    public static function invalidTracking(): array
    {
        return [
            'negative'           => [['progress_current' => -1]],
            'not a number'       => [['progress_total' => 'ten']],
            'current > total'    => [['progress_current' => 30, 'progress_total' => 24]],
            'bad date'           => [['started_at' => '2026-02-30']],
            'wrong format'       => [['finished_at' => '21/11/2026']],
            'finished < started' => [['started_at' => '2026-11-10', 'finished_at' => '2026-11-01']],
            'bad unit'           => [['progress_unit' => 'minute']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTracking')]
    public function testUpdateTrackingValidation(array $fields): void
    {
        $id = (int) $this->m->createMedia('Show', 'a.jpg', 1, 1, '2020', 0, null);
        $this->expectException(InvalidArgumentException::class);
        $this->m->updateTracking($id, $fields);
    }

    public function testJoinTablesCascadeOnPurge(): void
    {
        $id = $this->insertRaw(['title' => 'Tagged']);
        self::$pdo->exec("INSERT INTO genres (id, name, slug) VALUES (1, 'Horror', 'horror')");
        self::$pdo->exec("INSERT INTO tags (id, name, slug) VALUES (1, 'Comfy', 'comfy')");
        self::$pdo->exec("INSERT INTO user_lists (id, name, slug) VALUES (1, 'Halloween', 'halloween')");
        self::$pdo->exec("INSERT INTO media_genre VALUES ($id, 1)");
        self::$pdo->exec("INSERT INTO media_tag VALUES ($id, 1)");
        self::$pdo->exec("INSERT INTO user_list_media (list_id, media_id, position) VALUES (1, $id, 0)");
        self::$pdo->exec("INSERT INTO rewatch_log (media_id, finished_at) VALUES ($id, '2026-10-31')");

        // Soft delete keeps relations (so Undo restores everything)
        $this->m->deleteMedia($id);
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM media_tag')->fetchColumn());

        // Hard delete (app:purge-deleted) cascades; genres/tags/lists themselves survive
        self::$pdo->exec("DELETE FROM media WHERE id = $id");
        foreach (['media_genre', 'media_tag', 'user_list_media', 'rewatch_log'] as $t) {
            self::assertSame(0, (int) self::$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), $t);
        }
        foreach (['genres', 'tags', 'user_lists'] as $t) {
            self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), $t);
        }
    }

    public function testSlugsAreUnique(): void
    {
        self::$pdo->exec("INSERT INTO tags (name, slug) VALUES ('Comfy', 'comfy')");
        $this->expectException(\PDOException::class);
        self::$pdo->exec("INSERT INTO tags (name, slug) VALUES ('comfy', 'comfy')");
    }
}
