<?php

namespace App\Import;

/**
 * One normalized item read from an import file. All fields optional except title.
 * status uses MediaStatus values; score is "my rating" 0–10.
 */
final class ImportRecord
{
    public const FIELDS = [
        'title', 'year', 'category', 'status', 'score', 'public_score', 'score_source',
        'progress_current', 'progress_total', 'progress_unit', 'started_at', 'finished_at', 'rewatch_count',
        'external_source', 'external_id', 'notes', 'tags', 'genres', 'rating', 'synopsis',
    ];

    public static function make(array $data, int $line): array
    {
        $r = array_fill_keys(self::FIELDS, null);
        foreach ($data as $k => $v) {
            if (array_key_exists($k, $r)) {
                $r[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
            }
        }
        $r['tags'] = array_values(array_filter((array) ($r['tags'] ?? []), fn ($t) => is_string($t) && trim($t) !== ''));
        $r['genres'] = array_values(array_filter((array) ($r['genres'] ?? []), fn ($t) => is_string($t) && trim($t) !== ''));
        $r['year'] = $r['year'] !== null && preg_match('/^\d{4}/', (string) $r['year']) ? (int) substr((string) $r['year'], 0, 4) : null;
        foreach (['score', 'public_score'] as $f) {
            $r[$f] = is_numeric($r[$f]) && (float) $r[$f] > 0 ? round(min(10, (float) $r[$f]), 2) : null;
        }
        foreach (['progress_current', 'progress_total', 'rewatch_count'] as $f) {
            $r[$f] = is_numeric($r[$f]) && (int) $r[$f] >= 0 ? (int) $r[$f] : null;
        }
        if ($r['progress_total'] === 0) {
            $r['progress_total'] = null; // MAL uses 0 for "unknown"
        }
        foreach (['started_at', 'finished_at'] as $f) {
            $r[$f] = self::date($r[$f]);
        }
        $r['line'] = $line;
        return $r;
    }

    public static function date($v): ?string
    {
        if (!is_string($v) || $v === '' || str_starts_with($v, '0000')) {
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
