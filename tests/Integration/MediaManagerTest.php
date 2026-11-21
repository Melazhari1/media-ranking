<?php

namespace App\Tests\Integration;

use MediaManager;

final class MediaManagerTest extends DatabaseTestCase
{
    private MediaManager $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new MediaManager(self::$pdo);
    }

    public function testCreateNormalizesTitleAndSetsSortTitle(): void
    {
        $id  = $this->m->createMedia('  𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞 ', 'a.jpg', 2, 1, '2021', 8, '7.4', null, '', 'tmdb');
        $row = $this->fetchRow((int) $id);
        self::assertSame('The Black Phone', $row['title']);
        self::assertSame('black phone', $row['sort_title']);
        self::assertSame('7.40', $row['public_score']);
        self::assertSame('tmdb', $row['score_source']);
        self::assertEqualsWithDelta(7.4, (float) $row['score_mal'], 0.001, 'legacy mirror kept in sync');
    }

    public function testZeroPublicScoreIsStoredAsNull(): void
    {
        $id  = $this->m->createMedia('Nothing', 'a.jpg', 1, 1, '2021', 0, 0, null, '', 'mal');
        $row = $this->m->getMediaById($id);
        self::assertNull($row['public_score']);
        self::assertNull($row['score_source']);
    }

    public function testLegacyRowsFallBackToScoreMalBeforeBackfill(): void
    {
        $withScore = $this->insertRaw(['title' => 'Legacy', 'score_mal' => 6.7]);
        $zero      = $this->insertRaw(['title' => 'Legacy zero', 'score_mal' => 0]);

        $a = $this->m->getMediaById($withScore);
        self::assertEqualsWithDelta(6.7, (float) $a['public_score'], 0.001);
        self::assertSame('mal', $a['score_source']);

        $b = $this->m->getMediaById($zero);
        self::assertNull($b['public_score']);
        self::assertNull($b['score_source']);
    }

    public function testSoftDeleteHidesAndRestoreBringsBack(): void
    {
        $id = (int) $this->m->createMedia('Gone Soon', 'a.jpg', 1, 1, '2020', 0, null);

        self::assertTrue($this->m->deleteMedia($id));
        self::assertFalse($this->m->deleteMedia($id), 'second delete is a no-op');
        self::assertFalse($this->m->getMediaById($id));
        self::assertSame(0, $this->m->countFilteredMedia());
        self::assertSame([], $this->m->filterMedia());
        self::assertSame([], $this->m->getRandomMedia());
        self::assertNotNull($this->fetchRow($id)['deleted_at'], 'row still exists');

        self::assertTrue($this->m->restoreMedia($id));
        self::assertSame(1, $this->m->countFilteredMedia());
        self::assertFalse($this->m->restoreMedia($id));
    }

    public function testUnknownCategoryFilterIncludesUncategorized(): void
    {
        $this->insertRaw(['title' => 'TV show', 'category_id' => 1]);
        $this->insertRaw(['title' => 'In Unknown', 'category_id' => 3]);
        $this->insertRaw(['title' => 'No category', 'category_id' => null]);
        $this->insertRaw(['title' => 'Dangling', 'category_id' => 999]);

        $titles = array_column($this->m->filterMedia('', [3]), 'title');
        sort($titles);
        self::assertSame(['Dangling', 'In Unknown', 'No category'], $titles);
        self::assertSame(3, $this->m->countFilteredMedia('', [3]));

        self::assertSame(['TV show'], array_column($this->m->filterMedia('', [1]), 'title'));
    }

    public function testTitleSortUsesSortTitle(): void
    {
        foreach (['The Zebra', 'Apple', 'A Banana'] as $t) {
            $this->m->createMedia($t, 'a.jpg', 1, 1, '2020', 0, null);
        }
        self::assertSame(['Apple', 'A Banana', 'The Zebra'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.title ASC'), 'title'));
        self::assertSame(['The Zebra', 'A Banana', 'Apple'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.title DESC'), 'title'));
    }

    public function testPublicScoreSortPutsMissingScoresLast(): void
    {
        $this->m->createMedia('Low', 'a.jpg', 1, 1, '2020', 0, 5);
        $this->m->createMedia('None', 'a.jpg', 1, 1, '2020', 0, null);
        $this->m->createMedia('High', 'a.jpg', 1, 1, '2020', 0, 9);

        self::assertSame(['High', 'Low', 'None'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.public_score DESC'), 'title'));
        self::assertSame(['Low', 'High', 'None'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.public_score ASC'), 'title'));
        // Legacy sort key still accepted
        self::assertSame(['High', 'Low', 'None'], array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.score_mal DESC'), 'title'));
    }

    public function testSearchFindsStyledTitlesViaSortTitle(): void
    {
        // Legacy styled title that has not been normalized yet but has sort_title
        $this->insertRaw(['title' => '𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', 'sort_title' => 'black phone']);
        self::assertSame(1, $this->m->countFilteredMedia('black phone'));
    }

    public function testEmptyStatusCountsAsNotInList(): void
    {
        $this->insertRaw(['title' => 'Empty status', 'status' => '']);
        $this->insertRaw(['title' => 'Null status', 'status' => null]);
        $this->insertRaw(['title' => 'Watched one', 'status' => 'Watched']);
        self::assertSame(2, $this->m->countFilteredMedia('', [], [], ['null']));
    }

    public function testPaginationIsStableWithTies(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->m->createMedia("Same $i", 'a.jpg', 1, 1, '2020', 5, null);
        }
        $page1 = array_column($this->m->filterMedia('', [], [], [], 20, 0, 'm.score DESC'), 'id');
        $page2 = array_column($this->m->filterMedia('', [], [], [], 20, 20, 'm.score DESC'), 'id');
        self::assertCount(30, array_unique(array_merge($page1, $page2)));
    }
}
