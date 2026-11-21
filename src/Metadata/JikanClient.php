<?php

namespace App\Metadata;

use App\Http\HttpClient;
use App\Http\RateLimiter;

/** Jikan v4 (unofficial MyAnimeList API). No key needed; ~3 req/s and 60 req/min. */
final class JikanClient
{
    private const BASE = 'https://api.jikan.moe/v4';

    private const MANGA_CATEGORY = [
        'manga' => 'Manga', 'one-shot' => 'Manga', 'doujinshi' => 'Manga', 'manhwa' => 'Manga',
        'manhua' => 'Manga', 'oel' => 'Manga', 'light novel' => 'Light Novel', 'novel' => 'Novel',
    ];

    public function __construct(private HttpClient $http, private RateLimiter $limiter)
    {
    }

    /** @return MetadataResult[] */
    public function search(string $kind, string $query, int $limit = 8): array
    {
        $kind = $kind === 'manga' ? 'manga' : 'anime';
        $this->limiter->acquire();
        $res = $this->http->getJson(self::BASE . "/$kind?" . http_build_query(['q' => $query, 'limit' => $limit]));
        return array_map(fn ($d) => $this->map($kind, $d), $res['data'] ?? []);
    }

    public function details(string $kind, string $id): ?MetadataResult
    {
        $kind = $kind === 'manga' ? 'manga' : 'anime';
        $this->limiter->acquire();
        $res = $this->http->getJson(self::BASE . "/$kind/" . rawurlencode($id));
        return isset($res['data']) ? $this->map($kind, $res['data']) : null;
    }

    public function map(string $kind, array $d): MetadataResult
    {
        $titles = array_values(array_unique(array_filter(array_merge(
            [$d['title'] ?? null, $d['title_english'] ?? null, $d['title_japanese'] ?? null],
            array_column($d['titles'] ?? [], 'title'),
            $d['title_synonyms'] ?? []
        ))));

        $genres = array_values(array_unique(array_merge(
            array_column($d['genres'] ?? [], 'name'),
            array_column($d['themes'] ?? [], 'name'),
            array_column($d['demographics'] ?? [], 'name')
        )));

        $from = $kind === 'manga' ? ($d['published']['from'] ?? null) : ($d['aired']['from'] ?? null);
        $year = $d['year'] ?? ($from ? (int) substr($from, 0, 4) : null);
        $type = $d['type'] ?? null;

        if ($kind === 'manga') {
            $category = self::MANGA_CATEGORY[strtolower((string) $type)] ?? 'Manga';
            $isNovel  = in_array($category, ['Light Novel', 'Novel'], true);
            $total    = $isNovel ? ($d['volumes'] ?? $d['chapters'] ?? null) : ($d['chapters'] ?? null);
            $unit     = $isNovel ? 'volume' : 'chapter';
        } else {
            $category = $type ?: null; // TV, Movie, OVA, ONA, Special, Music, TV Special, CM, PV
            $total    = $d['episodes'] ?? null;
            $unit     = 'episode';
        }

        $images = $d['images']['jpg'] ?? [];
        return new MetadataResult(
            externalSource: $kind === 'manga' ? 'mal_manga' : 'mal_anime',
            externalId: (string) $d['mal_id'],
            title: (string) ($d['title'] ?? ''),
            altTitle: $d['title_english'] ?? null,
            year: $year ?: null,
            type: $type,
            category: $category,
            synopsis: isset($d['synopsis']) ? trim(preg_replace('/\s*\[Written by MAL Rewrite\]\s*$/', '', $d['synopsis'])) : null,
            posterUrl: $images['large_image_url'] ?? $images['image_url'] ?? null,
            backdropUrl: null,
            genres: $genres,
            total: $total ? (int) $total : null,
            unit: $unit,
            publicScore: isset($d['score']) && $d['score'] > 0 ? round((float) $d['score'], 2) : null,
            scoreSource: 'mal',
            ageRating: self::mapRating($d['rating'] ?? null),
            titles: $titles,
        );
    }

    /** "PG-13 - Teens 13 or older" → "PG-13", "Rx - Hentai" → "Rx". */
    public static function mapRating(?string $rating): ?string
    {
        if (!$rating) {
            return null;
        }
        $code = trim(explode(' - ', $rating)[0]);
        return in_array($code, ['G', 'PG', 'PG-13', 'R', 'R+', 'Rx'], true) ? $code : null;
    }
}
