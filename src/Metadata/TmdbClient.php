<?php

namespace App\Metadata;

use App\Http\HttpClient;

/**
 * TMDB v3. The key stays server-side (TMDB_API_KEY in .env). Accepts either a v3 API key
 * (sent as ?api_key=) or a v4 read access token (sent as a Bearer header).
 */
final class TmdbClient
{
    private const BASE = 'https://api.themoviedb.org/3';
    private const IMG  = 'https://image.tmdb.org/t/p/';

    private const MOVIE_CERT = ['G' => 'G', 'PG' => 'PG', 'PG-13' => 'PG-13', 'R' => 'R', 'NC-17' => 'R+'];
    private const TV_CERT    = ['TV-Y' => 'G', 'TV-Y7' => 'G', 'TV-G' => 'G', 'TV-PG' => 'PG', 'TV-14' => 'PG-13', 'TV-MA' => 'R'];

    public function __construct(private HttpClient $http, private ?string $apiKey)
    {
    }

    public function isConfigured(): bool
    {
        return (bool) $this->apiKey;
    }

    /**
     * @param string $kind movie | tv | multi
     * @return MetadataResult[]
     */
    public function search(string $kind, string $query, int $limit = 8): array
    {
        $kind = in_array($kind, ['movie', 'tv'], true) ? $kind : 'multi';
        $res  = $this->get("/search/$kind", ['query' => $query, 'include_adult' => 'false']);
        $out  = [];
        foreach ($res['results'] ?? [] as $r) {
            $type = $kind === 'multi' ? ($r['media_type'] ?? '') : $kind;
            if (!in_array($type, ['movie', 'tv'], true)) {
                continue; // skip people
            }
            $out[] = $this->map($type, $r);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function details(string $type, string $id): ?MetadataResult
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $append = $type === 'tv' ? 'content_ratings' : 'release_dates';
        $d = $this->get("/$type/" . rawurlencode($id), ['append_to_response' => $append]);
        return isset($d['id']) ? $this->map($type, $d) : null;
    }

    public function map(string $type, array $d): MetadataResult
    {
        $title = $type === 'tv' ? ($d['name'] ?? '') : ($d['title'] ?? '');
        $orig  = $type === 'tv' ? ($d['original_name'] ?? null) : ($d['original_title'] ?? null);
        $date  = $type === 'tv' ? ($d['first_air_date'] ?? '') : ($d['release_date'] ?? '');
        $genres = array_column($d['genres'] ?? [], 'name');

        $category = $type === 'tv' ? 'TV Series' : 'Movie';
        if (in_array('Documentary', $genres, true) || in_array(99, $d['genre_ids'] ?? [], true)) {
            $category = 'Documentary';
        }

        return new MetadataResult(
            externalSource: $type === 'tv' ? 'tmdb_tv' : 'tmdb_movie',
            externalId: (string) $d['id'],
            title: (string) $title,
            altTitle: $orig !== $title ? $orig : null,
            year: strlen($date) >= 4 ? (int) substr($date, 0, 4) : null,
            type: $type,
            category: $category,
            synopsis: ($d['overview'] ?? '') !== '' ? $d['overview'] : null,
            posterUrl: !empty($d['poster_path']) ? self::IMG . 'w500' . $d['poster_path'] : null,
            backdropUrl: !empty($d['backdrop_path']) ? self::IMG . 'w1280' . $d['backdrop_path'] : null,
            genres: $genres,
            total: $type === 'tv' ? ($d['number_of_episodes'] ?? null) : null,
            unit: $type === 'tv' ? 'episode' : null,
            publicScore: isset($d['vote_average']) && $d['vote_average'] > 0 ? round((float) $d['vote_average'], 2) : null,
            scoreSource: 'tmdb',
            ageRating: $this->certification($type, $d),
            titles: array_values(array_unique(array_filter([$title, $orig]))),
        );
    }

    private function certification(string $type, array $d): ?string
    {
        if ($type === 'tv') {
            foreach ($d['content_ratings']['results'] ?? [] as $r) {
                if (($r['iso_3166_1'] ?? '') === 'US') {
                    return self::TV_CERT[$r['rating'] ?? ''] ?? null;
                }
            }
            return null;
        }
        foreach ($d['release_dates']['results'] ?? [] as $r) {
            if (($r['iso_3166_1'] ?? '') === 'US') {
                foreach ($r['release_dates'] ?? [] as $rel) {
                    if (!empty($rel['certification'])) {
                        return self::MOVIE_CERT[$rel['certification']] ?? null;
                    }
                }
            }
        }
        return null;
    }

    private function get(string $path, array $params): array
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('TMDB_API_KEY is not configured');
        }
        $headers = [];
        if (strlen($this->apiKey) > 40) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey; // v4 read access token
        } else {
            $params['api_key'] = $this->apiKey;
        }
        return $this->http->getJson(self::BASE . $path . '?' . http_build_query($params), $headers);
    }
}
