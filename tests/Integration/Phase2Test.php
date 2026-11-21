<?php

namespace App\Tests\Integration;

use App\Console\Command\BackfillMetadataCommand;
use App\Import\ExportService;
use App\Import\ImportService;
use App\Import\Parsers;
use App\Recommendation\RecommendationService;
use App\Services;
use App\Stats\StatsService;
use App\Tests\Support\FakeHttpClient;
use MediaManager;
use Symfony\Component\Console\Tester\CommandTester;

final class Phase2Test extends DatabaseTestCase
{
    private MediaManager $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new MediaManager(self::$pdo);
        self::$pdo->exec("INSERT INTO categories (id, name) VALUES (4, 'Manga')");
    }

    private function services(FakeHttpClient $http): Services
    {
        putenv('MMR_VAR_DIR=' . $this->varDir);
        putenv('MMR_MEDIA_DIR=' . $this->varDir . '/medias');
        return new Services(self::$pdo, dirname(__DIR__, 2), $http, fn () => null);
    }

    protected function tearDown(): void
    {
        putenv('MMR_VAR_DIR');
        putenv('MMR_MEDIA_DIR');
        parent::tearDown();
    }

    // ── progress ─────────────────────────────────────────────────────────────

    public function testIncrementStartsTrackingAndCapsAtTotal(): void
    {
        $id = (int) $this->m->createMedia('Show', 'a.jpg', 1, 1, '2020', 0, null, 'Plan to Watch');
        $this->m->updateTracking($id, ['progress_total' => 2]);

        $r = $this->m->incrementProgress($id);
        self::assertSame(1, (int) $r['item']['progress_current']);
        self::assertSame('Watching', $r['item']['status']);
        self::assertSame(date('Y-m-d'), $r['item']['started_at']);
        self::assertSame('episode', $r['item']['progress_unit']);
        self::assertFalse($r['reached_total']);

        $r = $this->m->incrementProgress($id);
        self::assertTrue($r['reached_total']);
        $r = $this->m->incrementProgress($id);
        self::assertSame(2, (int) $r['item']['progress_current'], 'capped at total');
    }

    public function testIncrementUsesReadingWordingForManga(): void
    {
        $id = (int) $this->m->createMedia('Berserk', 'a.jpg', 4, 1, '1989', 0, null);
        $r = $this->m->incrementProgress($id, 5);
        self::assertSame('Reading', $r['item']['status']);
        self::assertSame('chapter', $r['item']['progress_unit']);
    }

    public function testCompleteSetsFinishedAndDoneStatus(): void
    {
        $id = (int) $this->m->createMedia('Berserk', 'a.jpg', 4, 1, '1989', 0, null, 'Reading');
        $this->m->updateTracking($id, ['progress_current' => 300, 'progress_total' => 364]);
        $item = $this->m->completeMedia($id);
        self::assertSame('Read', $item['status']);
        self::assertSame(364, (int) $item['progress_current']);
        self::assertSame(date('Y-m-d'), $item['finished_at']);
    }

    public function testCompletingARewatchLogsItAndKeepsFirstFinishDate(): void
    {
        $id = (int) $this->m->createMedia('Show', 'a.jpg', 1, 1, '2020', 0, null, 'Rewatching');
        $this->m->updateTracking($id, ['finished_at' => '2020-05-01']);
        $item = $this->m->completeMedia($id, '2026-11-20');
        self::assertSame('Watched', $item['status']);
        self::assertSame('2020-05-01', $item['finished_at']);
        self::assertSame(1, (int) $item['rewatch_count']);
        self::assertSame('2026-11-20', $this->m->rewatchLog($id)[0]['finished_at']);
    }

    // ── backfill ─────────────────────────────────────────────────────────────

    public function testBackfillAppliesConfidentMatchesOnlyToEmptyFields(): void
    {
        $exact = $this->insertRaw(['title' => 'Monster', 'year' => '2004-Q2', 'image' => 'missing.jpg', 'status' => 'Watched', 'score' => 9, 'infos' => 'my notes']);
        $fuzzy = $this->insertRaw(['title' => 'Monstr Special', 'year' => '2004-Q2', 'image' => 'missing2.jpg']);
        $fine  = $this->insertRaw(['title' => 'Has everything', 'score_mal' => 7, 'image' => 'ok.jpg']);
        mkdir($this->varDir . '/medias', 0777, true);
        file_put_contents($this->varDir . '/medias/ok.jpg', FakeHttpClient::jpeg());

        $http = new FakeHttpClient([
            '#jikan\.moe/v4/anime\?#' => ['data' => [FakeHttpClient::jikanAnime(19, 'Monster', ['year' => 2004, 'episodes' => 74])]],
            '#cdn\.myanimelist\.net#' => FakeHttpClient::jpeg(),
        ]);
        $s = $this->services($http);
        $t = new CommandTester(new BackfillMetadataCommand($s));

        $t->execute(['--dry-run' => true]);
        self::assertNull($this->fetchRow($exact)['external_id'], 'dry run changes nothing');

        $t->execute([]);
        $t->assertCommandIsSuccessful();
        $row = $this->fetchRow($exact);
        self::assertSame(['mal_anime', '19'], [$row['external_source'], $row['external_id']]);
        self::assertSame('8.50', $row['public_score']);
        self::assertSame(74, (int) $row['progress_total']);
        self::assertStringStartsWith('Synopsis of Monster', $row['synopsis']);
        self::assertNotSame('missing.jpg', $row['image'], 'missing poster replaced');
        self::assertFileExists($this->varDir . '/medias/' . $row['image']);
        self::assertSame(['Watched', 9.0, 'my notes'], [$row['status'], (float) $row['score'], $row['infos']], 'user data untouched');
        self::assertSame(3, (int) self::$pdo->query("SELECT COUNT(*) FROM media_genre WHERE media_id = $exact")->fetchColumn());

        self::assertNull($this->fetchRow($fuzzy)['external_id'], 'uncertain match not applied');
        $review = glob($this->varDir . '/reports/metadata-review-*.csv');
        self::assertNotEmpty($review);
        self::assertStringContainsString('Monstr Special', file_get_contents($review[0]));
        self::assertNull($this->fetchRow($fine)['external_id'], 'items without problems are not candidates');

        // Approve the review row and apply it
        $csv = file($review[0], FILE_IGNORE_NEW_LINES);
        $csv[1] = preg_replace('/,$/', ',yes', $csv[1]);
        file_put_contents($review[0], implode("\n", $csv));
        $http->routes['#jikan\.moe/v4/anime/19#'] = ['data' => FakeHttpClient::jikanAnime(19, 'Monster')];
        $t->execute(['--apply-review' => $review[0]]);
        self::assertSame('19', $this->fetchRow($fuzzy)['external_id']);
    }

    // ── stats ────────────────────────────────────────────────────────────────

    public function testStats(): void
    {
        $a = $this->insertRaw(['title' => 'A', 'status' => 'Watched', 'score' => 9, 'score_mal' => 8, 'finished_at' => date('Y-m-d'), 'progress_total' => 12]);
        $this->insertRaw(['title' => 'B', 'status' => 'Watching', 'score' => 6.6, 'score_mal' => 7, 'progress_current' => 5, 'progress_total' => 10]);
        $this->insertRaw(['title' => 'C', 'status' => 'Plan to Read', 'category_id' => 4, 'progress_unit' => 'chapter']);
        $this->insertRaw(['title' => 'Not in list', 'score' => 3]);
        self::$pdo->exec("INSERT INTO genres (id, name, slug) VALUES (1, 'Drama', 'drama')");
        self::$pdo->exec("INSERT INTO media_genre VALUES ($a, 1)");

        $s = (new StatsService(self::$pdo, new \App\Cache\FileCache($this->varDir, 'stats')))->compute();
        self::assertSame(3, $s['totals']['in_list']);
        self::assertSame(4, $s['totals']['all']);
        $groups = array_intersect_key($s['totals']['by_group'], ['done' => 0, 'active' => 0, 'plan' => 0]);
        ksort($groups); // ties have no defined order
        self::assertSame(['active' => 1, 'done' => 1, 'plan' => 1], $groups);
        self::assertSame(7.8, $s['ratings']['my_avg']);
        self::assertSame(7.5, $s['ratings']['public_avg']);
        self::assertSame(1, $s['ratings']['distribution'][9]);
        self::assertSame(1, $s['ratings']['distribution'][7]);
        self::assertSame('Drama', $s['top_genres'][0]['genre']);
        self::assertSame(date('Y-m'), end($s['finished']['per_month'])['month']);
        self::assertSame(17, $s['consumed']['episode'], '12 (finished, total) + 5 (in progress)');
    }

    // ── recommendations ──────────────────────────────────────────────────────

    public function testRecommendationsPreferPlanToWatchAndSkipFinishedAndAdult(): void
    {
        self::$pdo->exec("INSERT INTO genres (id, name, slug) VALUES (1, 'Horror', 'horror'), (2, 'Comedy', 'comedy')");
        $seed    = $this->insertRaw(['title' => 'Loved horror', 'status' => 'Watched', 'score' => 9.5]);
        $plan    = $this->insertRaw(['title' => 'Planned horror', 'status' => 'Plan to Watch', 'score_mal' => 6]);
        $new     = $this->insertRaw(['title' => 'Unseen horror', 'score_mal' => 9]);
        $seen    = $this->insertRaw(['title' => 'Seen horror', 'status' => 'Watched', 'score' => 5]);
        $adult   = $this->insertRaw(['title' => 'Adult horror', 'rating_id' => 2, 'score_mal' => 9.9]);
        $comedy  = $this->insertRaw(['title' => 'Comedy', 'score_mal' => 9.9]);
        $extra   = $this->insertRaw(['title' => 'Another horror', 'score_mal' => 5]);
        foreach ([$seed, $plan, $new, $seen, $adult, $extra] as $id) {
            self::$pdo->exec("INSERT INTO media_genre VALUES ($id, 1)");
        }
        self::$pdo->exec("INSERT INTO media_genre VALUES ($comedy, 2)");

        $rows = (new RecommendationService(self::$pdo, $this->m, new \App\Repository\GenreRepository(self::$pdo)))->becauseYouLiked(1, 10);
        self::assertSame('Loved horror', $rows[0]['seed']['title']);
        $titles = array_column($rows[0]['items'], 'title');
        self::assertSame('Planned horror', $titles[0], 'Plan to Watch first');
        self::assertContains('Unseen horror', $titles);
        self::assertNotContains('Seen horror', $titles);
        self::assertNotContains('Adult horror', $titles);
        self::assertNotContains('Comedy', $titles);
    }

    public function testRandomRespectsFilters(): void
    {
        $this->insertRaw(['title' => 'Plan movie', 'status' => 'Plan to Watch', 'category_id' => 2]);
        $this->insertRaw(['title' => 'Plan tv', 'status' => 'Plan to Watch', 'category_id' => 1]);
        $this->insertRaw(['title' => 'Watched movie', 'status' => 'Watched', 'category_id' => 2]);
        for ($i = 0; $i < 5; $i++) {
            $r = $this->m->getRandomMedia(['statuses' => ['Plan to Watch'], 'category_ids' => [2]], 1);
            self::assertSame('Plan movie', $r[0]['title']);
        }
    }

    // ── import / export ──────────────────────────────────────────────────────

    private function import(): ImportService
    {
        $s = $this->services(new FakeHttpClient());
        return new ImportService(self::$pdo, $this->m, $s->lookups(), $s->tags(), $s->genres(), $this->varDir);
    }

    private const MAL_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<myanimelist>
  <myinfo><user_export_type>1</user_export_type></myinfo>
  <anime>
    <series_animedb_id>5114</series_animedb_id><series_title><![CDATA[Fullmetal Alchemist: Brotherhood]]></series_title>
    <series_type>TV</series_type><series_episodes>64</series_episodes><my_watched_episodes>64</my_watched_episodes>
    <my_start_date>2020-01-01</my_start_date><my_finish_date>2020-02-01</my_finish_date><my_score>10</my_score>
    <my_status>Completed</my_status><my_times_watched>1</my_times_watched><my_rewatching>0</my_rewatching>
    <my_comments><![CDATA[Masterpiece]]></my_comments><my_tags><![CDATA[favorite, shounen]]></my_tags>
  </anime>
  <anime>
    <series_animedb_id>1</series_animedb_id><series_title><![CDATA[Existing Show]]></series_title>
    <series_type>TV</series_type><series_episodes>0</series_episodes><my_watched_episodes>3</my_watched_episodes>
    <my_start_date>0000-00-00</my_start_date><my_finish_date>0000-00-00</my_finish_date><my_score>0</my_score>
    <my_status>On-Hold</my_status><my_times_watched>0</my_times_watched><my_rewatching>0</my_rewatching>
  </anime>
  <anime>
    <series_animedb_id>5114</series_animedb_id><series_title><![CDATA[Fullmetal Alchemist: Brotherhood]]></series_title>
    <series_type>TV</series_type><my_status>Completed</my_status>
  </anime>
</myanimelist>
XML;

    public function testMalXmlPreviewAndCommit(): void
    {
        $existing = $this->insertRaw(['title' => 'Existing Show', 'year' => '2010', 'status' => 'Watching']);
        $records = Parsers::parse(self::MAL_XML, 'animelist.xml');
        self::assertCount(3, $records);
        self::assertSame('Watched', $records[0]['status']);
        self::assertSame(10.0, $records[0]['score']);
        self::assertSame('2020-02-01', $records[0]['finished_at']);
        self::assertNull($records[1]['started_at'], '0000-00-00 → null');
        self::assertNull($records[1]['progress_total'], '0 episodes → unknown');

        $svc = $this->import();
        $p = $svc->preview($records);
        self::assertSame(['total' => 3, 'create' => 1, 'update' => 1, 'skip' => 1, 'duplicates' => 1, 'errors' => 0], $p['summary']);
        self::assertSame($existing, $p['rows'][1]['match_id']);
        self::assertContains('status', $p['rows'][1]['changes']);

        $res = $svc->commit($records);
        self::assertSame(['created' => 1, 'updated' => 1, 'skipped' => 1], $res);

        $fma = self::$pdo->query("SELECT * FROM media WHERE external_id = '5114'")->fetch();
        self::assertSame(['Watched', 10.0, 64, 1, 'Masterpiece'], [$fma['status'], (float) $fma['score'], (int) $fma['progress_current'], (int) $fma['rewatch_count'], $fma['infos']]);
        self::assertSame(2, (int) self::$pdo->query("SELECT COUNT(*) FROM media_tag WHERE media_id = {$fma['id']}")->fetchColumn());
        $ex = $this->fetchRow($existing);
        self::assertSame(['On Hold', 3], [$ex['status'], (int) $ex['progress_current']]);
        self::assertSame('mal_anime', $ex['external_source']);

        // Re-import: everything matches by external id now, nothing to change
        $again = $svc->preview($records);
        self::assertSame(0, $again['summary']['create']);
        self::assertSame(0, $again['summary']['update']);
    }

    public function testLetterboxdDiaryAggregatesRewatches(): void
    {
        $csv = "Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date\n"
             . "2024-01-02,Alien,1979,https://boxd.it/a,4.5,,scifi,2024-01-01\n"
             . "2025-06-02,Alien,1979,https://boxd.it/b,5,Yes,,2025-06-01\n"
             . "2025-07-02,Heat,1995,https://boxd.it/c,4,,,2025-07-01\n";
        $records = Parsers::parse($csv, 'diary.csv');
        self::assertCount(2, $records);
        self::assertSame(['Alien', 1979, 'Watched', 10.0, '2025-06-01', 1, ['scifi']], [
            $records[0]['title'], $records[0]['year'], $records[0]['status'], $records[0]['score'],
            $records[0]['finished_at'], $records[0]['rewatch_count'], $records[0]['tags'],
        ]);
        $watchlist = Parsers::parse("Date,Name,Year,Letterboxd URI\n2024-01-01,Dune,2021,https://boxd.it/d\n", 'watchlist.csv');
        self::assertSame('Plan to Watch', $watchlist[0]['status']);
    }

    public function testRejectsXmlEntities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parsers::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><myanimelist>&e;</myanimelist>', 'a.xml');
    }

    public function testExportRoundTripsThroughImport(): void
    {
        $id = (int) $this->m->createMedia('=Formula Title', 'a.jpg', 1, 1, '2021', 8, 7.5, 'Watching', 'notes', 'tmdb');
        $this->m->updateTracking($id, ['progress_current' => 3, 'progress_total' => 10]);
        $s = $this->services(new FakeHttpClient());
        $s->tags()->setForMedia($id, ['comfy']);
        $export = new ExportService($this->m, $s->genres(), $s->tags());

        $json = fopen('php://memory', 'w+');
        self::assertSame(1, $export->writeJson($json, []));
        rewind($json);
        $content = stream_get_contents($json);
        $records = Parsers::parse($content, 'mmr.json');
        self::assertSame(['=Formula Title', 'Watching', 8.0, 3, ['comfy']], [$records[0]['title'], $records[0]['status'], $records[0]['score'], $records[0]['progress_current'], $records[0]['tags']]);
        self::assertSame(0, $this->import()->preview($records)['summary']['create'], 'round trip finds the existing item');

        $csv = fopen('php://memory', 'w+');
        $export->writeCsv($csv, []);
        rewind($csv);
        $csvContent = stream_get_contents($csv);
        self::assertStringContainsString("'=Formula Title", $csvContent, 'formula injection neutralised');
        self::assertSame('mmr_csv', Parsers::detect($csvContent, 'export.csv'));
    }
}
