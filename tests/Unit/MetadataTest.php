<?php

namespace App\Tests\Unit;

use App\Cache\FileCache;
use App\Http\HttpException;
use App\Http\RateLimiter;
use App\Media\PosterStore;
use App\Metadata\JikanClient;
use App\Metadata\MetadataResult;
use App\Metadata\MetadataService;
use App\Metadata\TitleMatcher;
use App\Metadata\TmdbClient;
use App\Tests\Support\FakeHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mmr-meta-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    private function service(FakeHttpClient $http, ?string $tmdbKey = 'k'): MetadataService
    {
        return new MetadataService(
            new JikanClient($http, new RateLimiter($this->dir, 'jikan', [1 => 1000])),
            new TmdbClient($http, $tmdbKey),
            new FileCache($this->dir, 'metadata'),
        );
    }

    public function testJikanAnimeMapping(): void
    {
        $r = (new JikanClient(new FakeHttpClient(), new RateLimiter($this->dir, 'j', [1 => 99])))->map('anime', FakeHttpClient::jikanAnime(5114, 'Fullmetal Alchemist: Brotherhood', ['rating' => 'R - 17+ (violence & profanity)', 'episodes' => 64]));
        self::assertSame('mal_anime', $r->externalSource);
        self::assertSame('5114', $r->externalId);
        self::assertSame('TV', $r->category);
        self::assertSame(64, $r->total);
        self::assertSame('episode', $r->unit);
        self::assertSame('R', $r->ageRating);
        self::assertSame(8.5, $r->publicScore);
        self::assertSame(['Action', 'Drama', 'Military'], $r->genres);
        self::assertStringNotContainsString('MAL Rewrite', $r->synopsis);
        self::assertStringStartsWith('https://cdn.myanimelist.net/', $r->posterUrl);
    }

    public function testJikanMangaNovelUsesVolumes(): void
    {
        $r = (new JikanClient(new FakeHttpClient(), new RateLimiter($this->dir, 'j', [1 => 99])))->map('manga', [
            'mal_id' => 1, 'title' => 'Overlord', 'type' => 'Light Novel', 'chapters' => null, 'volumes' => 16,
            'published' => ['from' => '2012-07-30T00:00:00+00:00'], 'score' => 8.4, 'images' => ['jpg' => []],
        ]);
        self::assertSame('mal_manga', $r->externalSource);
        self::assertSame('Light Novel', $r->category);
        self::assertSame(16, $r->total);
        self::assertSame('volume', $r->unit);
        self::assertSame(2012, $r->year);
        self::assertNull($r->ageRating);
    }

    public function testRatingMap(): void
    {
        self::assertSame('Rx', JikanClient::mapRating('Rx - Hentai'));
        self::assertSame('R+', JikanClient::mapRating('R+ - Mild Nudity'));
        self::assertSame('G', JikanClient::mapRating('G - All Ages'));
        self::assertNull(JikanClient::mapRating('None'));
    }

    public function testTmdbMappingAndCertification(): void
    {
        $tmdb = new TmdbClient(new FakeHttpClient(), 'k');
        $movie = $tmdb->map('movie', [
            'id' => 550, 'title' => 'Fight Club', 'original_title' => 'Fight Club', 'release_date' => '1999-10-15',
            'poster_path' => '/p.jpg', 'backdrop_path' => '/b.jpg', 'vote_average' => 8.433, 'overview' => 'Rules.',
            'genres' => [['name' => 'Drama']],
            'release_dates' => ['results' => [['iso_3166_1' => 'US', 'release_dates' => [['certification' => 'R']]]]],
        ]);
        self::assertSame('tmdb_movie', $movie->externalSource);
        self::assertSame('Movie', $movie->category);
        self::assertSame('R', $movie->ageRating);
        self::assertSame(8.43, $movie->publicScore);
        self::assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $movie->posterUrl);
        self::assertSame('https://image.tmdb.org/t/p/w1280/b.jpg', $movie->backdropUrl);
        self::assertNull($movie->altTitle);

        $tv = $tmdb->map('tv', ['id' => 1, 'name' => 'Planet Earth', 'first_air_date' => '2006-03-05', 'number_of_episodes' => 11,
            'genres' => [['name' => 'Documentary']], 'content_ratings' => ['results' => [['iso_3166_1' => 'US', 'rating' => 'TV-PG']]]]);
        self::assertSame('Documentary', $tv->category);
        self::assertSame(11, $tv->total);
        self::assertSame('PG', $tv->ageRating);
    }

    public function testSearchIsCachedServerSide(): void
    {
        $http = new FakeHttpClient(['#api\.jikan\.moe/v4/anime\?#' => ['data' => [FakeHttpClient::jikanAnime(1, 'Monster')]]]);
        $svc = $this->service($http);
        $a = $svc->search('Monster', 'anime');
        $b = $svc->search('monster', 'anime');
        self::assertSame('Monster', $a['results'][0]->title);
        self::assertSame('Monster', $b['results'][0]->title);
        self::assertCount(1, $http->requests, 'second search served from cache');
    }

    public function testTmdbNotConfiguredGivesWarningNotError(): void
    {
        $http = new FakeHttpClient(['#jikan#' => ['data' => []]]);
        $res = $this->service($http, null)->search('Alien', 'all');
        self::assertSame([], $res['results']);
        self::assertStringContainsString('TMDB_API_KEY', $res['warnings'][0]);
        self::assertFalse((bool) preg_grep('#themoviedb#', $http->requests), 'key never needed, TMDB never called');
    }

    public function testProviderFailureBecomesWarning(): void
    {
        $http = new FakeHttpClient(['#jikan#' => fn () => new HttpException('HTTP 429', 429)]);
        $res = $this->service($http)->search('Naruto', 'anime');
        self::assertSame([], $res['results']);
        self::assertStringContainsString('429', $res['warnings'][0]);
    }

    public function testTmdbKeyNeverInResults(): void
    {
        $http = new FakeHttpClient(['#themoviedb.*search/multi#' => ['results' => [['media_type' => 'movie', 'id' => 1, 'title' => 'Alien', 'release_date' => '1979-05-25']]]]);
        $res = $this->service($http, 'secret-key')->search('Alien', 'screen');
        self::assertStringNotContainsString('secret-key', json_encode(array_map(fn ($r) => $r->toArray(), $res['results'])));
        self::assertStringContainsString('api_key=secret-key', $http->requests[0], 'key sent server-side only');
    }

    public function testDetailsValidatesInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service(new FakeHttpClient())->details('mal_anime', '../etc/passwd');
    }

    public function testKindForCategory(): void
    {
        self::assertSame('anime', MetadataService::kindForCategory('OVA'));
        self::assertSame('manga', MetadataService::kindForCategory('Light Novel'));
        self::assertSame('screen', MetadataService::kindForCategory('TV Series'));
        self::assertSame('all', MetadataService::kindForCategory('Movie'));
        self::assertSame('all', MetadataService::kindForCategory(null));
    }

    public function testTitleMatcherConfidence(): void
    {
        $cand = new MetadataResult('mal_anime', '1', 'Sousou no Frieren', 'Frieren: Beyond Journey\'s End', 2023, 'TV', 'TV', titles: ['Sousou no Frieren']);
        self::assertGreaterThanOrEqual(0.95, TitleMatcher::confidence(['title' => 'Sousou no Frieren', 'year' => 2023, 'category' => 'TV'], $cand));
        self::assertGreaterThanOrEqual(0.9, TitleMatcher::confidence(['title' => 'Frieren: Beyond Journey’s End', 'year' => 2023, 'category' => 'TV'], $cand));
        self::assertLessThan(0.9, TitleMatcher::confidence(['title' => 'Sousou no Frieren 2nd Season', 'year' => 2026, 'category' => 'TV'], $cand));
        self::assertLessThan(0.5, TitleMatcher::confidence(['title' => 'Naruto', 'year' => 2002, 'category' => 'TV'], $cand));
    }

    public function testRateLimiterWaitsWhenWindowIsFull(): void
    {
        $now = 1000.0;
        $slept = [];
        $limiter = new RateLimiter($this->dir, 'rl', [1 => 3, 60 => 60], function (float $s) use (&$now, &$slept) {
            $slept[] = $s;
            $now += $s;
        }, function () use (&$now) {
            return $now;
        });
        for ($i = 0; $i < 3; $i++) {
            $limiter->acquire();
        }
        self::assertSame([], $slept, 'first 3 requests in a second are free');
        $limiter->acquire();
        self::assertCount(1, $slept);
        self::assertEqualsWithDelta(1.0, $slept[0], 0.05, 'the 4th waits ~1 s');
    }

    public function testPosterStoreAllowListAndValidation(): void
    {
        $jpeg = FakeHttpClient::jpeg();
        $http = new FakeHttpClient(['#cdn\.myanimelist\.net/ok#' => $jpeg, '#cdn\.myanimelist\.net/html#' => '<html>nope</html>']);
        $store = new PosterStore($http, $this->dir . '/medias');

        $name = $store->download('https://cdn.myanimelist.net/ok.jpg');
        self::assertMatchesRegularExpression('/^[a-f0-9]{20}\.jpg$/', $name);
        self::assertNull($store->qualityProblem($name));

        try {
            $store->download('https://evil.example.com/x.jpg');
            self::fail('disallowed host accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            $store->download('http://cdn.myanimelist.net/ok.jpg');
            self::fail('plain http accepted');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(HttpException::class);
        $store->download('https://cdn.myanimelist.net/html');
    }

    public function testPosterQualityProblems(): void
    {
        $store = new PosterStore(new FakeHttpClient(), $this->dir . '/medias');
        mkdir($this->dir . '/medias', 0777, true);
        file_put_contents($this->dir . '/medias/small.jpg', FakeHttpClient::jpeg(90, 120));
        self::assertSame('no poster', $store->qualityProblem(''));
        self::assertSame('file missing', $store->qualityProblem('nope.jpg'));
        self::assertSame('watermarked (alamy)', $store->qualityProblem('alamy-stock-123.jpg'));
        self::assertNotNull($store->qualityProblem('small.jpg'));
    }
}
