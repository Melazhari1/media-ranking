<?php

namespace App\Import;

use App\Repository\GenreRepository;
use App\Repository\TagRepository;
use MediaManager;

/**
 * Export the list (all, or the current filtered view) as JSON or CSV.
 * The format round-trips through app:import (mmr_json / mmr_csv).
 */
final class ExportService
{
    public const CSV_COLUMNS = [
        'id', 'title', 'year', 'category', 'rating', 'status', 'my_score', 'public_score', 'score_source',
        'progress_current', 'progress_total', 'progress_unit', 'started_at', 'finished_at', 'rewatch_count',
        'external_source', 'external_id', 'genres', 'tags', 'notes', 'created_at',
    ];

    public function __construct(private MediaManager $media, private GenreRepository $genres, private TagRepository $tags)
    {
    }

    /** @return \Generator<array> export rows */
    public function rows(array $filters): \Generator
    {
        $batch = [];
        foreach ($this->media->iterate($filters) as $row) {
            $batch[] = $row;
            if (count($batch) === 500) {
                yield from $this->decorate($batch);
                $batch = [];
            }
        }
        if ($batch) {
            yield from $this->decorate($batch);
        }
    }

    /** @param resource $out */
    public function writeJson($out, array $filters): int
    {
        fwrite($out, '{"app":"mmr","version":2,"exported_at":' . json_encode(date('c')) . ',"items":[');
        $n = 0;
        foreach ($this->rows($filters) as $row) {
            fwrite($out, ($n++ ? ',' : '') . "\n" . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        fwrite($out, "\n],\"count\":$n}\n");
        return $n;
    }

    /** @param resource $out */
    public function writeCsv($out, array $filters): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::CSV_COLUMNS);
        $n = 0;
        foreach ($this->rows($filters) as $row) {
            $row['genres'] = implode('|', $row['genres']);
            $row['tags'] = implode('|', $row['tags']);
            fputcsv($out, array_map(fn ($c) => self::csvSafe($row[$c] ?? ''), self::CSV_COLUMNS));
            $n++;
        }
        return $n;
    }

    /** Prevent spreadsheet formula injection (=, +, -, @ at the start of a cell). */
    private static function csvSafe($v): string
    {
        $v = (string) $v;
        return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) ? "'" . $v : $v;
    }

    private function decorate(array $rows): \Generator
    {
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $genres = $this->genres->namesFor($ids);
        $tags = $this->tags->forMedia($ids);
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            yield [
                'id'               => $id,
                'title'            => $r['title'],
                'year'             => $r['year'],
                'category'         => $r['categories'],
                'rating'           => $r['ratings'],
                'status'           => $r['status'] ?: null,
                'my_score'         => (float) $r['score'] ?: null,
                'public_score'     => $r['public_score'] !== null ? (float) $r['public_score'] : null,
                'score_source'     => $r['score_source'],
                'progress_current' => $r['progress_current'] !== null ? (int) $r['progress_current'] : null,
                'progress_total'   => $r['progress_total'] !== null ? (int) $r['progress_total'] : null,
                'progress_unit'    => $r['progress_unit'],
                'started_at'       => $r['started_at'],
                'finished_at'      => $r['finished_at'],
                'rewatch_count'    => (int) $r['rewatch_count'],
                'external_source'  => $r['external_source'],
                'external_id'      => $r['external_id'],
                'genres'           => $genres[$id] ?? [],
                'tags'             => array_column($tags[$id] ?? [], 'name'),
                'notes'            => $r['infos'] ?: null,
                'synopsis'         => $r['synopsis'] ?? null,
                'created_at'       => $r['created_at'],
            ];
        }
    }
}
