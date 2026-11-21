<?php

namespace App\Import;

use App\Domain\MediaStatus;
use InvalidArgumentException;

/**
 * Import file parsers: MyAnimeList XML export, Letterboxd CSV, and MMR's own JSON/CSV export.
 * Each returns a list of ImportRecord arrays.
 */
final class Parsers
{
    public const FORMATS = ['mal', 'letterboxd', 'mmr_json', 'mmr_csv'];

    public static function detect(string $content, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $head = ltrim(substr($content, 0, 2048), "\xEF\xBB\xBF \r\n\t");
        if ($ext === 'xml' || str_starts_with($head, '<?xml') || str_starts_with($head, '<myanimelist')) {
            return 'mal';
        }
        if ($ext === 'json' || str_starts_with($head, '{') || str_starts_with($head, '[')) {
            return 'mmr_json';
        }
        $firstLine = strtok($head, "\n");
        if (stripos((string) $firstLine, 'Letterboxd URI') !== false) {
            return 'letterboxd';
        }
        if (preg_match('/(^|,)"?title"?(,|$)/i', (string) $firstLine)) {
            return 'mmr_csv';
        }
        throw new InvalidArgumentException('Unrecognised file format. Supported: MyAnimeList XML, Letterboxd CSV, MMR JSON/CSV.');
    }

    public static function parse(string $content, string $filename, string $format = 'auto'): array
    {
        $format = $format === 'auto' ? self::detect($content, $filename) : $format;
        return match ($format) {
            'mal'        => self::mal($content),
            'letterboxd' => self::letterboxd($content, $filename),
            'mmr_json'   => self::mmrJson($content),
            'mmr_csv'    => self::mmrCsv($content),
            default      => throw new InvalidArgumentException("Unknown format '$format'"),
        };
    }

    // ── MyAnimeList XML (anime or manga list export) ─────────────────────────

    public static function mal(string $xml): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new InvalidArgumentException('XML with DOCTYPE/ENTITY declarations is not accepted');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($doc === false || $doc->getName() !== 'myanimelist') {
            throw new InvalidArgumentException('Not a MyAnimeList export (expected <myanimelist> root)');
        }

        $out = [];
        $line = 0;
        foreach ($doc->anime as $a) {
            $line++;
            $type = (string) $a->series_type;
            $out[] = ImportRecord::make([
                'title'            => (string) $a->series_title,
                'category'         => $type !== '' && $type !== 'Unknown' ? $type : null,
                'status'           => self::malStatus((string) $a->my_status, (string) $a->my_rewatching === '1', false),
                'score'            => (string) $a->my_score,
                'progress_current' => (string) $a->my_watched_episodes,
                'progress_total'   => (string) $a->series_episodes,
                'progress_unit'    => 'episode',
                'started_at'       => (string) $a->my_start_date,
                'finished_at'      => (string) $a->my_finish_date,
                'rewatch_count'    => (string) $a->my_times_watched,
                'external_source'  => 'mal_anime',
                'external_id'      => (string) $a->series_animedb_id,
                'notes'            => (string) $a->my_comments,
                'tags'             => self::splitTags((string) $a->my_tags),
            ], $line);
        }
        foreach ($doc->manga as $m) {
            $line++;
            $out[] = ImportRecord::make([
                'title'            => (string) $m->manga_title,
                'category'         => 'Manga',
                'status'           => self::malStatus((string) $m->my_status, (string) $m->my_rereading === '1', true),
                'score'            => (string) $m->my_score,
                'progress_current' => (string) $m->my_read_chapters,
                'progress_total'   => (string) $m->manga_chapters,
                'progress_unit'    => 'chapter',
                'started_at'       => (string) $m->my_start_date,
                'finished_at'      => (string) $m->my_finish_date,
                'rewatch_count'    => (string) $m->my_times_read,
                'external_source'  => 'mal_manga',
                'external_id'      => (string) $m->manga_mangadb_id,
                'notes'            => (string) $m->my_comments,
                'tags'             => self::splitTags((string) $m->my_tags),
            ], $line);
        }
        return $out;
    }

    private static function malStatus(string $s, bool $rewatching, bool $reading): ?string
    {
        if ($rewatching) {
            return MediaStatus::Rewatching->value;
        }
        $status = match (strtolower(trim($s))) {
            'completed'                     => MediaStatus::Watched,
            'watching', 'reading'           => MediaStatus::Watching,
            'on-hold', 'on hold'            => MediaStatus::OnHold,
            'dropped'                       => MediaStatus::Dropped,
            'plan to watch', 'plan to read' => MediaStatus::PlanToWatch,
            default                         => null,
        };
        return $status?->forCategory($reading ? 'Manga' : 'TV')->value;
    }

    // ── Letterboxd CSV (watched / ratings / diary / watchlist) ───────────────

    public static function letterboxd(string $csv, string $filename): array
    {
        $rows = self::csvRows($csv);
        $isWatchlist = stripos($filename, 'watchlist') !== false;
        $films = [];
        foreach ($rows as $i => $r) {
            $name = $r['Name'] ?? '';
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name) . '|' . ($r['Year'] ?? '');
            $f = $films[$key] ?? [
                'title' => $name, 'year' => $r['Year'] ?? null, 'category' => 'Movie', 'rewatch_count' => 0,
                'status' => $isWatchlist ? MediaStatus::PlanToWatch->value : MediaStatus::Watched->value,
                'line' => $i + 2, 'tags' => [],
            ];
            if (isset($r['Rating']) && is_numeric($r['Rating'])) {
                $f['score'] = (float) $r['Rating'] * 2; // 0.5–5 stars → 1–10
            }
            $watched = $r['Watched Date'] ?? (!$isWatchlist && !isset($r['Rating']) ? ($r['Date'] ?? null) : null);
            if ($watched && (!isset($f['finished_at']) || $watched > $f['finished_at'])) {
                $f['finished_at'] = $watched;
            }
            if (strcasecmp((string) ($r['Rewatch'] ?? ''), 'Yes') === 0) {
                $f['rewatch_count']++;
            }
            if (!empty($r['Tags'])) {
                $f['tags'] = array_values(array_unique(array_merge($f['tags'], self::splitTags($r['Tags']))));
            }
            $films[$key] = $f;
        }
        return array_values(array_map(fn ($f) => ImportRecord::make($f, $f['line']), $films));
    }

    // ── MMR export (JSON / CSV) ───────────────────────────────────────────────

    public static function mmrJson(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON');
        }
        $items = $data['items'] ?? $data;
        if (!array_is_list($items)) {
            throw new InvalidArgumentException('Expected an "items" array');
        }
        $out = [];
        foreach ($items as $i => $item) {
            if (is_array($item) && !empty($item['title'])) {
                $out[] = ImportRecord::make(self::fromMmrRow($item), $i + 1);
            }
        }
        return $out;
    }

    public static function mmrCsv(string $csv): array
    {
        $out = [];
        foreach (self::csvRows($csv) as $i => $row) {
            if (!empty($row['title'])) {
                $row['tags'] = self::splitTags($row['tags'] ?? '', '|');
                $row['genres'] = self::splitTags($row['genres'] ?? '', '|');
                $out[] = ImportRecord::make(self::fromMmrRow($row), $i + 2);
            }
        }
        return $out;
    }

    private static function fromMmrRow(array $r): array
    {
        $tags = $r['tags'] ?? [];
        if ($tags && is_array($tags) && is_array($tags[0] ?? null)) {
            $tags = array_column($tags, 'name');
        }
        return [
            'title' => $r['title'], 'year' => $r['year'] ?? $r['release_year'] ?? null,
            'category' => $r['category'] ?? $r['categories'] ?? null, 'status' => $r['status'] ?? null,
            'score' => $r['my_score'] ?? $r['score'] ?? null, 'public_score' => $r['public_score'] ?? null,
            'score_source' => $r['score_source'] ?? null, 'progress_current' => $r['progress_current'] ?? null,
            'progress_total' => $r['progress_total'] ?? null, 'progress_unit' => $r['progress_unit'] ?? null,
            'started_at' => $r['started_at'] ?? null, 'finished_at' => $r['finished_at'] ?? null,
            'rewatch_count' => $r['rewatch_count'] ?? null, 'external_source' => $r['external_source'] ?? null,
            'external_id' => $r['external_id'] ?? null, 'notes' => $r['notes'] ?? $r['infos'] ?? null,
            'tags' => $tags, 'genres' => $r['genres'] ?? [], 'rating' => $r['rating'] ?? $r['ratings'] ?? null,
            'synopsis' => $r['synopsis'] ?? null,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string,string>> rows keyed by header */
    public static function csvRows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = fgetcsv($h);
        if (!$header) {
            return [];
        }
        $header = array_map('trim', $header);
        $rows = [];
        while (($row = fgetcsv($h)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
        }
        fclose($h);
        return $rows;
    }

    private static function splitTags(string $s, string $sep = ','): array
    {
        return array_values(array_filter(array_map('trim', explode($sep, $s)), 'strlen'));
    }
}
