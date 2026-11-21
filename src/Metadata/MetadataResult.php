<?php

namespace App\Metadata;

/**
 * Provider-independent metadata for one title (Jikan anime/manga or TMDB movie/tv).
 * external_source: mal_anime | mal_manga | tmdb_movie | tmdb_tv
 */
final class MetadataResult
{
    public function __construct(
        public string $externalSource,
        public string $externalId,
        public string $title,
        public ?string $altTitle = null,
        public ?int $year = null,
        public ?string $type = null,          // provider type: TV, Movie, OVA, Manga, movie, tv…
        public ?string $category = null,      // MMR category name: TV, Movie, Manga, TV Series…
        public ?string $synopsis = null,
        public ?string $posterUrl = null,
        public ?string $backdropUrl = null,
        /** @var string[] */
        public array $genres = [],
        public ?int $total = null,            // episodes / chapters
        public ?string $unit = null,          // episode | chapter | volume
        public ?float $publicScore = null,
        public ?string $scoreSource = null,   // mal | tmdb
        public ?string $ageRating = null,     // G, PG, PG-13, R, R+, Rx
        /** @var string[] */
        public array $titles = [],            // all known titles, used for matching
    ) {
    }

    public function toArray(): array
    {
        return [
            'external_source' => $this->externalSource,
            'external_id'     => $this->externalId,
            'title'           => $this->title,
            'alt_title'       => $this->altTitle,
            'year'            => $this->year,
            'type'            => $this->type,
            'category'        => $this->category,
            'synopsis'        => $this->synopsis,
            'poster_url'      => $this->posterUrl,
            'backdrop_url'    => $this->backdropUrl,
            'genres'          => $this->genres,
            'total'           => $this->total,
            'unit'            => $this->unit,
            'public_score'    => $this->publicScore,
            'score_source'    => $this->scoreSource,
            'age_rating'      => $this->ageRating,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            $a['external_source'], (string) $a['external_id'], $a['title'], $a['alt_title'] ?? null,
            isset($a['year']) ? (int) $a['year'] : null, $a['type'] ?? null, $a['category'] ?? null,
            $a['synopsis'] ?? null, $a['poster_url'] ?? null, $a['backdrop_url'] ?? null, $a['genres'] ?? [],
            isset($a['total']) ? (int) $a['total'] : null, $a['unit'] ?? null,
            isset($a['public_score']) ? (float) $a['public_score'] : null, $a['score_source'] ?? null,
            $a['age_rating'] ?? null, $a['titles'] ?? [],
        );
    }
}
