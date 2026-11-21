<?php

namespace App\Metadata;

use App\Cache\FileCache;
use App\Http\HttpException;
use InvalidArgumentException;

/**
 * Search + details across providers, cached server-side for 24 h.
 *
 * kind: anime | manga (Jikan), movie | tv | screen (TMDB), all (Jikan anime + TMDB multi).
 */
final class MetadataService
{
    public const TTL = 86400;

    /** Categories whose items normally come from MyAnimeList. */
    public const ANIME_CATEGORIES   = ['tv', 'ova', 'ona', 'special', 'music', 'tv special', 'cm', 'pv'];
    public const READING_CATEGORIES = ['manga', 'light novel', 'novel', 'book', 'manhwa', 'manhua'];
    public const SCREEN_CATEGORIES  = ['tv series', 'documentary', 'web series'];

    public function __construct(
        private JikanClient $jikan,
        private TmdbClient $tmdb,
        private FileCache $cache,
    ) {
    }

    public function tmdbConfigured(): bool
    {
        return $this->tmdb->isConfigured();
    }

    /** Pick a search kind from an MMR category name (null = unknown). */
    public static function kindForCategory(?string $category): string
    {
        $c = strtolower(trim((string) $category));
        return match (true) {
            in_array($c, self::ANIME_CATEGORIES, true)   => 'anime',
            in_array($c, self::READING_CATEGORIES, true) => 'manga',
            in_array($c, self::SCREEN_CATEGORIES, true)  => 'screen',
            $c === 'movie'                               => 'all', // anime film or live action
            default                                      => 'all',
        };
    }

    /**
     * @return array{results: MetadataResult[], warnings: string[]}
     */
    public function search(string $query, string $kind = 'all', int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['results' => [], 'warnings' => []];
        }
        if (!in_array($kind, ['anime', 'manga', 'movie', 'tv', 'screen', 'all'], true)) {
            throw new InvalidArgumentException("Unknown lookup type '$kind'");
        }

        $results = [];
        $warnings = [];
        $sources = match ($kind) {
            'anime', 'manga' => [['jikan', $kind]],
            'movie', 'tv'    => [['tmdb', $kind]],
            'screen'         => [['tmdb', 'multi']],
            'all'            => [['jikan', 'anime'], ['tmdb', 'multi']],
        };

        foreach ($sources as [$provider, $sub]) {
            if ($provider === 'tmdb' && !$this->tmdb->isConfigured()) {
                $warnings[] = 'TMDB is not configured (set TMDB_API_KEY in .env) — movies/series search unavailable.';
                continue;
            }
            $key = "search:$provider:$sub:" . mb_strtolower($query) . ":$limit";
            try {
                $rows = $this->cache->remember($key, self::TTL, function () use ($provider, $sub, $query, $limit) {
                    $found = $provider === 'jikan'
                        ? $this->jikan->search($sub, $query, $limit)
                        : $this->tmdb->search($sub, $query, $limit);
                    return array_map(fn (MetadataResult $r) => $r->toArray() + ['titles' => $r->titles], $found);
                });
                foreach ($rows as $row) {
                    $results[] = MetadataResult::fromArray($row);
                }
            } catch (HttpException $e) {
                $warnings[] = ($provider === 'jikan' ? 'MyAnimeList (Jikan)' : 'TMDB') . ' lookup failed: ' . $e->getMessage();
            }
        }

        if ($kind === 'all') {
            // Interleave providers so both appear near the top.
            usort($results, fn ($a, $b) => self::similarity($query, $b) <=> self::similarity($query, $a));
        }
        return ['results' => array_slice($results, 0, $limit * 2), 'warnings' => $warnings];
    }

    public function details(string $externalSource, string $externalId): ?MetadataResult
    {
        if (!preg_match('/^[0-9]{1,12}$/', $externalId)) {
            throw new InvalidArgumentException('Invalid external id');
        }
        [$provider, $sub] = match ($externalSource) {
            'mal_anime'  => ['jikan', 'anime'],
            'mal_manga'  => ['jikan', 'manga'],
            'tmdb_movie' => ['tmdb', 'movie'],
            'tmdb_tv'    => ['tmdb', 'tv'],
            default      => throw new InvalidArgumentException("Unknown source '$externalSource'"),
        };
        $row = $this->cache->remember("details:$externalSource:$externalId", self::TTL, function () use ($provider, $sub, $externalId) {
            $r = $provider === 'jikan' ? $this->jikan->details($sub, $externalId) : $this->tmdb->details($sub, $externalId);
            return $r ? $r->toArray() + ['titles' => $r->titles] : null;
        });
        return $row ? MetadataResult::fromArray($row) : null;
    }

    /** Best title similarity (0..1) between a query and any of the result's titles. */
    public static function similarity(string $query, MetadataResult $r): float
    {
        $q = TitleMatcher::key($query);
        $best = 0.0;
        foreach (array_merge([$r->title], $r->titles, [$r->altTitle]) as $t) {
            if ($t) {
                $best = max($best, TitleMatcher::ratio($q, TitleMatcher::key($t)));
            }
        }
        return $best;
    }
}
