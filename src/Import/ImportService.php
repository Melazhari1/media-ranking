<?php

namespace App\Import;

use App\Domain\MediaStatus;
use App\Metadata\TitleMatcher;
use App\Repository\GenreRepository;
use App\Repository\LookupRepository;
use App\Repository\TagRepository;
use InvalidArgumentException;
use MediaManager;
use PDO;

/**
 * Two-step import: preview() classifies every record as create / update / skip with duplicate
 * detection (external id first, then title + year); commit() applies it in one transaction.
 *
 * Updates only ever fill or change fields the file actually provides — an empty value in the
 * file never blanks out existing data, and existing notes are never overwritten.
 */
final class ImportService
{
    private const USER_FIELDS = ['status', 'score', 'progress_current', 'progress_total', 'started_at', 'finished_at', 'rewatch_count'];

    public function __construct(
        private PDO $pdo,
        private MediaManager $media,
        private LookupRepository $lookups,
        private TagRepository $tags,
        private GenreRepository $genres,
        private string $varDir,
    ) {
    }

    /** @return array{summary: array, rows: array} */
    public function preview(array $records): array
    {
        [$byExternal, $byTitleYear, $byTitle] = $this->buildIndex();
        $seen = [];
        $rows = [];
        $summary = ['total' => count($records), 'create' => 0, 'update' => 0, 'skip' => 0, 'duplicates' => 0, 'errors' => 0];

        foreach ($records as $r) {
            $row = ['line' => $r['line'], 'title' => $r['title'], 'year' => $r['year'], 'category' => $r['category'], 'status' => $r['status']];
            if (!$r['title']) {
                $row += ['action' => 'skip', 'reason' => 'missing title'];
                $summary['errors']++;
                $summary['skip']++;
                $rows[] = $row;
                continue;
            }
            if ($r['status'] !== null && !MediaStatus::tryFrom($r['status']) && !MediaStatus::tryFromKey($r['status'])) {
                $row += ['action' => 'skip', 'reason' => "unknown status '{$r['status']}'"];
                $summary['errors']++;
                $summary['skip']++;
                $rows[] = $row;
                continue;
            }

            $fileKey = $r['external_id'] ? "{$r['external_source']}:{$r['external_id']}" : TitleMatcher::key($r['title']) . '|' . $r['year'];
            if (isset($seen[$fileKey])) {
                $row += ['action' => 'skip', 'reason' => 'duplicate of line ' . $seen[$fileKey] . ' in this file'];
                $summary['duplicates']++;
                $summary['skip']++;
                $rows[] = $row;
                continue;
            }
            $seen[$fileKey] = $r['line'];

            [$match, $how] = $this->match($r, $byExternal, $byTitleYear, $byTitle);
            if (!$match) {
                $row += ['action' => 'create', 'reason' => 'new'];
                $summary['create']++;
            } else {
                $changes = $this->diff($match, $r);
                $row += [
                    'action'      => $changes ? 'update' : 'skip',
                    'reason'      => $changes ? 'matched by ' . $how : 'already up to date (matched by ' . $how . ')',
                    'match_id'    => (int) $match['id'],
                    'match_title' => $match['title'],
                    'changes'     => array_keys($changes),
                ];
                $summary[$changes ? 'update' : 'skip']++;
            }
            $rows[] = $row;
        }
        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @param array{create?:bool, update?:bool} $options
     * @return array{created:int, updated:int, skipped:int}
     */
    public function commit(array $records, array $options = []): array
    {
        $create = $options['create'] ?? true;
        $update = $options['update'] ?? true;
        $plan = $this->preview($records);
        $byLine = [];
        foreach ($records as $r) {
            $byLine[$r['line']] = $r;
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->pdo->beginTransaction();
        try {
            foreach ($plan['rows'] as $row) {
                $r = $byLine[$row['line']] ?? null;
                if (!$r || $row['action'] === 'skip' || ($row['action'] === 'create' && !$create) || ($row['action'] === 'update' && !$update)) {
                    $result['skipped']++;
                    continue;
                }
                $row['action'] === 'create' ? $this->createFrom($r) : $this->updateFrom($row['match_id'], $r);
                $result[$row['action'] === 'create' ? 'created' : 'updated']++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $result;
    }

    // ── Pending imports (preview → commit across two HTTP requests) ──────────

    public function stash(array $records, string $filename, string $format): string
    {
        $dir = $this->varDir . '/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach (glob("$dir/*.json") ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        file_put_contents("$dir/$token.json", json_encode(['filename' => $filename, 'format' => $format, 'records' => $records]));
        return $token;
    }

    public function unstash(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new InvalidArgumentException('Invalid import token');
        }
        $file = $this->varDir . "/imports/$token.json";
        if (!is_file($file)) {
            throw new InvalidArgumentException('Import expired or not found — upload the file again');
        }
        return json_decode((string) file_get_contents($file), true);
    }

    public function forget(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            @unlink($this->varDir . "/imports/$token.json");
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function buildIndex(): array
    {
        $byExternal = $byTitleYear = $byTitle = [];
        foreach ($this->media->matchIndexRows() as $m) {
            if ($m['external_id']) {
                $byExternal["{$m['external_source']}:{$m['external_id']}"] = $m;
            }
            $key = TitleMatcher::key($m['title']);
            $byTitleYear[$key . '|' . $m['release_year']] ??= $m;
            $byTitle[$key][] = $m;
        }
        return [$byExternal, $byTitleYear, $byTitle];
    }

    private function match(array $r, array $byExternal, array $byTitleYear, array $byTitle): array
    {
        if ($r['external_id'] && isset($byExternal["{$r['external_source']}:{$r['external_id']}"])) {
            return [$byExternal["{$r['external_source']}:{$r['external_id']}"], 'external id'];
        }
        $key = TitleMatcher::key($r['title']);
        if ($r['year'] && isset($byTitleYear["$key|{$r['year']}"])) {
            return [$byTitleYear["$key|{$r['year']}"], 'title + year'];
        }
        // Title only when unambiguous and the stored row has no conflicting year.
        $candidates = array_values(array_filter($byTitle[$key] ?? [], fn ($m) => !$r['year'] || !$m['release_year']));
        if (count($candidates) === 1) {
            return [$candidates[0], 'title'];
        }
        return [null, null];
    }

    /** Fields the record would change on the matched row. */
    private function diff(array $m, array $r): array
    {
        $changes = [];
        $status = $this->status($r, $m['category'] ?? $r['category']);
        if ($status !== null && $status !== $m['status']) {
            $changes['status'] = $status;
        }
        if ($r['score'] !== null && abs((float) $m['score'] - $r['score']) > 0.001) {
            $changes['score'] = $r['score'];
        }
        foreach (['progress_current', 'progress_total', 'rewatch_count'] as $f) {
            if ($r[$f] !== null && (int) $m[$f] !== $r[$f] && !($f === 'rewatch_count' && $r[$f] === 0)) {
                $changes[$f] = $r[$f];
            }
        }
        foreach (['started_at', 'finished_at'] as $f) {
            if ($r[$f] !== null && $r[$f] !== $m[$f]) {
                $changes[$f] = $r[$f];
            }
        }
        if ($r['notes'] && trim((string) $m['infos']) === '') {
            $changes['notes'] = $r['notes'];
        }
        if ($r['external_id'] && !$m['external_id']) {
            $changes['external_id'] = $r['external_id'];
        }
        return $changes;
    }

    private function status(array $r, ?string $category): ?string
    {
        if ($r['status'] === null) {
            return null;
        }
        $s = MediaStatus::tryFrom($r['status']) ?? MediaStatus::tryFromKey($r['status']);
        return $s?->forCategory($category)->value;
    }

    private function createFrom(array $r): void
    {
        $categoryId = $this->lookups->categoryId($r['category'] ?? 'Unknown');
        $ratingId = $r['rating'] ? $this->lookups->ratingId($r['rating']) : null;
        $id = (int) $this->media->createMedia(
            $r['title'], '', $categoryId, $ratingId, $r['year'] ? (string) $r['year'] : 'N/A',
            $r['score'] ?? 0, $r['public_score'], $this->status($r, $r['category']), $r['notes'] ?? '',
            $r['score_source'] ?? ($r['public_score'] ? 'manual' : null)
        );
        $this->applyExtras($id, $r);
    }

    private function updateFrom(int $id, array $r): void
    {
        $m = $this->media->getMediaById($id);
        $changes = $this->diff($m + ['category' => $m['categories']], $r);
        $set = [];
        if (isset($changes['status'])) {
            $set['status'] = $changes['status'];
        }
        if (isset($changes['score'])) {
            $set['score'] = $changes['score'];
        }
        if (isset($changes['notes'])) {
            $set['infos'] = $changes['notes'];
        }
        if ($set) {
            $cols = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($set)));
            $this->pdo->prepare("UPDATE media SET $cols WHERE id = :id")->execute($set + ['id' => $id]);
        }
        $this->applyExtras($id, $r, $m);
    }

    private function applyExtras(int $id, array $r, ?array $existing = null): void
    {
        $tracking = [];
        foreach (['progress_current', 'progress_total', 'progress_unit', 'started_at', 'finished_at', 'rewatch_count'] as $f) {
            if ($r[$f] !== null && !($f === 'rewatch_count' && $r[$f] === 0 && $existing)) {
                $tracking[$f] = $r[$f];
            }
        }
        // Keep the pair consistent if the file's total is lower than the progress.
        if (isset($tracking['progress_current'], $tracking['progress_total']) && $tracking['progress_current'] > $tracking['progress_total']) {
            $tracking['progress_total'] = $tracking['progress_current'];
        }
        if (isset($tracking['started_at'], $tracking['finished_at']) && $tracking['finished_at'] < $tracking['started_at']) {
            unset($tracking['started_at']);
        }
        if ($tracking) {
            try {
                $this->media->updateTracking($id, $tracking);
            } catch (InvalidArgumentException) {
                // e.g. progress beyond an existing total: keep the record, skip the inconsistent fields
                unset($tracking['progress_current'], $tracking['progress_total']);
                $tracking && $this->media->updateTracking($id, $tracking);
            }
        }
        if ($r['external_id'] && (!$existing || !$existing['external_id'])) {
            $this->media->updateMetadataFields($id, ['external_source' => $r['external_source'], 'external_id' => $r['external_id']]);
        }
        if ($r['synopsis'] && (!$existing || !$existing['synopsis'])) {
            $this->media->updateMetadataFields($id, ['synopsis' => $r['synopsis']]);
        }
        if ($r['tags']) {
            $this->tags->addToMedia([$id], $r['tags']);
        }
        if ($r['genres'] && !$existing) {
            $this->genres->sync($id, $r['genres']);
        }
    }
}
