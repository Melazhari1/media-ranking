<?php

namespace App\Api;

use App\Domain\MediaStatus;
use App\Import\ExportService;
use App\Import\ImportService;
use App\Import\Parsers;
use App\Media\PosterStore;
use App\Recommendation\RecommendationService;
use App\Services;
use App\Stats\StatsService;
use InvalidArgumentException;
use MediaManager;
use Throwable;

/**
 * JSON API behind api.php. Routes on HTTP method + ?action=.
 * Every handler returns an array that is JSON-encoded with {"status":"success"} unless it set
 * an error via HttpError.
 */
final class ApiController
{
    private MediaManager $media;

    public function __construct(private Services $s)
    {
        $this->media = $s->media();
    }

    public function handle(string $method, string $action, ?int $id, array $query, array $body, array $files): array
    {
        $route = strtoupper($method) . ' ' . $action;
        return match (true) {
            // ── reads ──
            $route === 'GET categories'       => $this->ok($this->media->getCategories()),
            $route === 'GET ratings'          => $this->ok($this->media->getRatings()),
            $route === 'GET statuses'         => $this->ok(MediaStatus::values()),
            $route === 'GET genres'           => $this->ok($this->s->genres()->all()),
            $route === 'GET random'           => $this->ok($this->media->getRandomMedia($this->filters($query), (int) ($query['limit'] ?? 20))),
            $route === 'GET detail'           => $this->detail($this->requireId($id)),
            $route === 'GET lookup'           => $this->lookup($query),
            $route === 'GET lookup_details'   => $this->lookupDetails($query),
            $route === 'GET stats'            => $this->ok($this->statsService()->get()),
            $route === 'GET recommendations'  => $this->ok($this->reco()->becauseYouLiked((int) ($query['rows'] ?? 2), (int) ($query['limit'] ?? 12))),
            $route === 'GET home_rows'        => $this->homeRows(),
            $route === 'GET export'           => $this->export($query),
            $route === 'GET ' && $id !== null   => $this->getOne($id),
            $route === 'GET '                 => $this->listPage($query),

            // ── writes ──
            $route === 'POST update_status'   => $this->write(fn () => $this->updateStatus($this->requireId($id), $body)),
            $route === 'POST update_score'    => $this->write(fn () => $this->updateScore($this->requireId($id), $body)),
            $route === 'POST update_infos'    => $this->write(fn () => $this->updateInfos($this->requireId($id), $body)),
            $route === 'POST update_tracking' => $this->write(fn () => $this->updateTracking($this->requireId($id), $body)),
            $route === 'POST progress'        => $this->write(fn () => $this->progress($this->requireId($id), $body)),
            $route === 'POST complete'        => $this->write(fn () => $this->complete($this->requireId($id), $body)),
            $route === 'POST restore'         => $this->write(fn () => $this->restore($this->requireId($id))),
            $route === 'POST import_preview'  => $this->importPreview($files, $body),
            $route === 'POST import_commit'   => $this->write(fn () => $this->importCommit($body)),
            $route === 'POST '                => $this->write(fn () => $this->save($id, $body, $files)),
            $route === 'DELETE ' || $route === 'DELETE delete' => $this->write(fn () => $this->delete($this->requireId($id))),

            default => throw new HttpError(404, "Unknown endpoint: $method " . ($action ?: '(none)')),
        };
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ok($data, array $extra = []): array
    {
        return ['status' => 'success', 'data' => $data] + $extra;
    }

    private function write(callable $fn): array
    {
        $result = $fn();
        $this->s->invalidateAggregates();
        return $result;
    }

    private function requireId(?int $id): int
    {
        if ($id === null || $id <= 0) {
            throw new HttpError(400, 'ID is required');
        }
        return $id;
    }

    private function found($item, string $what = 'Media not found')
    {
        if (!$item) {
            throw new HttpError(404, $what);
        }
        return $item;
    }

    /** Filter params shared by list, random and export. */
    public function filters(array $q): array
    {
        $csv = fn ($k) => !empty($q[$k]) ? array_values(array_filter(explode(',', (string) $q[$k]), 'strlen')) : [];
        return array_filter([
            'keyword'       => trim((string) ($q['keyword'] ?? '')),
            'category_ids'  => $csv('category_ids'),
            'rating_ids'    => $csv('rating_ids'),
            'statuses'      => $csv('statuses'),
            'tag_ids'       => $csv('tag_ids'),
            'genre_ids'     => $csv('genre_ids'),
            'list_id'       => (int) ($q['list_id'] ?? 0),
            'finished_year' => (int) ($q['finished_year'] ?? 0),
            'in_progress'   => !empty($q['in_progress']),
            'exclude_adult' => !empty($q['exclude_adult']),
        ]);
    }

    private function statsService(): StatsService
    {
        return new StatsService($this->s->pdo(), $this->s->cache('stats'));
    }

    private function reco(): RecommendationService
    {
        return new RecommendationService($this->s->pdo(), $this->media, $this->s->genres());
    }

    private function importService(): ImportService
    {
        return new ImportService($this->s->pdo(), $this->media, $this->s->lookups(), $this->s->tags(), $this->s->genres(), $this->s->varDir());
    }

    // ── reads ────────────────────────────────────────────────────────────────

    private function listPage(array $q): array
    {
        $limit  = max(1, min(200, (int) ($q['limit'] ?? 20)));
        $page   = max(1, (int) ($q['page'] ?? 1));
        $f      = $this->filters($q);
        $total  = $this->media->count($f);
        return [
            'status' => 'success',
            'data'   => $this->media->search($f, $limit, ($page - 1) * $limit, (string) ($q['order_by'] ?? 'm.created_at DESC')),
            'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => $total > 0 ? (int) ceil($total / $limit) : 1],
        ];
    }

    private function getOne(int $id): array
    {
        return $this->ok($this->found($this->media->getMediaById($id)));
    }

    private function detail(int $id): array
    {
        $item = $this->found($this->media->getMediaById($id));
        $item['genres']      = $this->s->genres()->namesFor([$id])[$id] ?? [];
        $item['tags']        = $this->s->tags()->forMedia([$id])[$id] ?? [];
        $item['lists']       = $this->s->lists()->forMedia([$id])[$id] ?? [];
        $item['rewatch_log'] = $this->media->rewatchLog($id);
        $related = array_map(fn ($r) => array_intersect_key($r, array_flip([
            'id', 'title', 'image', 'year', 'status', 'categories', 'ratings', 'rating_label', 'public_score', 'score_source', 'score',
        ])), $this->reco()->related($id, 12));
        return $this->ok($item, ['related' => $related]);
    }

    private function lookup(array $q): array
    {
        $kind = (string) ($q['type'] ?? '');
        if ($kind === '' || $kind === 'auto') {
            $kind = \App\Metadata\MetadataService::kindForCategory($q['category'] ?? null);
        }
        $res = $this->s->metadata()->search((string) ($q['q'] ?? ''), $kind, 8);
        return $this->ok(array_map(fn ($r) => [
            'external_source' => $r->externalSource, 'external_id' => $r->externalId, 'title' => $r->title,
            'alt_title' => $r->altTitle, 'year' => $r->year, 'type' => $r->type, 'category' => $r->category,
            'poster_url' => $r->posterUrl, 'public_score' => $r->publicScore, 'score_source' => $r->scoreSource,
        ], $res['results']), ['warnings' => $res['warnings'], 'kind' => $kind]);
    }

    private function lookupDetails(array $q): array
    {
        $r = $this->found($this->s->metadata()->details((string) ($q['source'] ?? ''), (string) ($q['id'] ?? '')), 'Title not found');
        $data = $r->toArray();
        $data['category_id'] = $r->category ? $this->s->lookups()->categoryId($r->category, false) : null;
        $data['rating_id'] = $r->ageRating ? $this->s->lookups()->ratingId($r->ageRating) : null;
        return $this->ok($data);
    }

    private function homeRows(): array
    {
        $cards = fn (array $rows) => $rows;
        return $this->ok([
            'continue'   => $cards($this->media->search(['in_progress' => true], 20, 0, 'm.created_at DESC')),
            'recent'     => $cards($this->media->search(['statuses' => MediaStatus::values()], 20, 0, 'm.created_at DESC')),
            'top_rated'  => $cards(array_values(array_filter(
                $this->media->search(['statuses' => MediaStatus::values()], 20, 0, 'm.score DESC'),
                fn ($r) => (float) $r['score'] > 0
            ))),
            'random'     => $cards($this->media->getRandomMedia(['statuses' => [MediaStatus::PlanToWatch->value, MediaStatus::PlanToRead->value]], 20)),
        ]);
    }

    private function export(array $q): array
    {
        $format = ($q['format'] ?? 'json') === 'csv' ? 'csv' : 'json';
        $filters = ($q['scope'] ?? 'all') === 'filtered' ? $this->filters($q) : [];
        $export = new ExportService($this->media, $this->s->genres(), $this->s->tags());

        $name = 'mmr-export-' . date('Ymd-His') . ".$format";
        header_remove('Content-Type');
        header($format === 'csv' ? 'Content-Type: text/csv; charset=UTF-8' : 'Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        $format === 'csv' ? $export->writeCsv($out, $filters) : $export->writeJson($out, $filters);
        fclose($out);
        return ['__raw' => true];
    }

    // ── writes ───────────────────────────────────────────────────────────────

    private function updateStatus(int $id, array $b): array
    {
        $this->found($this->media->getMediaById($id));
        $this->media->updateStatus($id, $b['status'] ?? null);
        return ['status' => 'success', 'message' => 'Status updated', 'data' => $this->media->getMediaById($id)];
    }

    private function updateScore(int $id, array $b): array
    {
        if (!isset($b['score']) || !is_numeric($b['score'])) {
            throw new HttpError(400, 'ID and score are required');
        }
        $score = (float) $b['score'];
        if ($score < 0 || $score > 10) {
            throw new HttpError(400, 'Score must be between 0 and 10');
        }
        $this->media->updateScore($id, $score);
        return ['status' => 'success', 'message' => 'Score updated'];
    }

    private function updateInfos(int $id, array $b): array
    {
        $this->media->updateInfos($id, $b['infos'] ?? '');
        return ['status' => 'success', 'message' => 'Infos updated'];
    }

    private function updateTracking(int $id, array $b): array
    {
        return $this->ok($this->found($this->media->updateTracking($id, $b)));
    }

    private function progress(int $id, array $b): array
    {
        $by = isset($b['by']) ? (int) $b['by'] : 1;
        if ($by === 0 || abs($by) > 1000) {
            throw new HttpError(400, 'by must be a non-zero number up to 1000');
        }
        $res = $this->found($this->media->incrementProgress($id, $by));
        return $this->ok($res['item'], ['reached_total' => $res['reached_total']]);
    }

    private function complete(int $id, array $b): array
    {
        return $this->ok($this->found($this->media->completeMedia($id, $b['finished_at'] ?? null)));
    }

    private function restore(int $id): array
    {
        if (!$this->media->restoreMedia($id)) {
            throw new HttpError(404, 'Nothing to restore');
        }
        return ['status' => 'success', 'message' => 'Media restored'];
    }

    private function delete(int $id): array
    {
        if (!$this->media->deleteMedia($id)) {
            throw new HttpError(404, 'Media not found');
        }
        // Soft delete: POST api.php?action=restore&id=… undoes it.
        return ['status' => 'success', 'message' => 'Media deleted', 'undo' => 'restore'];
    }

    /** Create (no id) or full update, plus optional auto-fill extras. */
    private function save(?int $id, array $data, array $files): array
    {
        if (empty($data['title']) || trim((string) $data['title']) === '') {
            throw new HttpError(400, 'Title is required');
        }
        if ($id !== null) {
            $this->found($this->media->getMediaById($id));
        }

        $warnings = [];
        $imageName = (string) ($data['image'] ?? '');
        if (!empty($files['image_file']['name'])) {
            $imageName = $this->storeUpload($files['image_file']);
        } elseif (!empty($data['image_url'])) {
            try {
                $imageName = $this->s->posters()->download((string) $data['image_url']);
            } catch (Throwable $e) {
                $warnings[] = 'Poster could not be downloaded: ' . $e->getMessage();
            }
        }

        $categoryId = self::resolveId($data, 'category_id', 'category_ids');
        $ratingId   = self::resolveId($data, 'rating_id', 'rating_ids');
        $year       = !empty($data['year']) ? (string) $data['year'] : date('Y');
        $score      = isset($data['score']) && is_numeric($data['score']) ? (float) $data['score'] : 0.0;
        $infos      = (string) ($data['infos'] ?? '');

        // public_score replaces score_mal; the legacy field is still accepted (as a MAL score).
        if (array_key_exists('public_score', $data)) {
            [$publicScore, $scoreSource] = [$data['public_score'], $data['score_source'] ?? 'manual'];
        } else {
            [$publicScore, $scoreSource] = [$data['score_mal'] ?? null, 'mal'];
        }

        $pdo = $this->s->pdo();
        $pdo->beginTransaction();
        try {
            if ($id !== null) {
                $this->media->updateMedia($id, $data['title'], $imageName, $categoryId, $ratingId, $year, $score, $publicScore, $data['status'] ?? null, $infos, $scoreSource);
            } else {
                $id = (int) $this->media->createMedia($data['title'], $imageName, $categoryId, $ratingId, $year, $score, $publicScore, $data['status'] ?? null, $infos, $scoreSource);
                $created = true;
            }

            $tracking = array_intersect_key($data, array_flip(MediaManager::TRACKING_FIELDS));
            if ($tracking) {
                $this->media->updateTracking($id, $tracking);
            }
            $meta = array_intersect_key($data, array_flip(['external_source', 'external_id', 'synopsis', 'backdrop_url']));
            if ($meta) {
                if (!empty($meta['external_id'])) {
                    $meta['last_synced_at'] = date('Y-m-d H:i:s');
                }
                $this->media->updateMetadataFields($id, $meta);
            }
            if (array_key_exists('genres', $data)) {
                $genres = is_array($data['genres']) ? $data['genres'] : (json_decode((string) $data['genres'], true) ?? array_map('trim', explode(',', (string) $data['genres'])));
                $this->s->genres()->sync($id, array_filter((array) $genres, 'is_string'));
            }
            if (array_key_exists('tags', $data)) {
                $tags = is_array($data['tags']) ? $data['tags'] : (json_decode((string) $data['tags'], true) ?? array_map('trim', explode(',', (string) $data['tags'])));
                $this->s->tags()->setForMedia($id, array_filter((array) $tags, 'is_string'));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return empty($created)
            ? ['status' => 'success', 'message' => 'Media updated', 'id' => $id, 'warnings' => $warnings]
            : ['status' => 'success', 'message' => 'Media created', 'id' => $id, 'warnings' => $warnings];
    }

    /** Validate an uploaded image by its bytes (never trust the client's file name). */
    private function storeUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new HttpError(400, 'Upload failed');
        }
        if ($file['size'] > 8_000_000) {
            throw new HttpError(400, 'Image is larger than 8 MB');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
        if (!$ext) {
            throw new HttpError(400, 'Only JPEG, PNG, WebP or GIF images are allowed');
        }
        $dir = $this->s->mediaDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new HttpError(500, 'Could not store the image');
        }
        return $name;
    }

    private function importPreview(array $files, array $body): array
    {
        $f = $files['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError(400, 'Choose a file to import');
        }
        if ($f['size'] > 20_000_000) {
            throw new HttpError(400, 'File is larger than 20 MB');
        }
        $content = (string) file_get_contents($f['tmp_name']);
        $name = (string) $f['name'];
        if (str_ends_with(strtolower($name), '.gz')) {
            $content = (string) @gzdecode($content);
            $name = substr($name, 0, -3);
        }
        $format = (string) ($body['format'] ?? 'auto');
        $format = $format === 'auto' ? Parsers::detect($content, $name) : $format;
        $records = Parsers::parse($content, $name, $format);
        if (!$records) {
            throw new HttpError(400, 'No items found in this file');
        }
        $service = $this->importService();
        $preview = $service->preview($records);
        $token = $service->stash($records, $name, $format);
        return $this->ok([
            'token'   => $token,
            'format'  => $format,
            'summary' => $preview['summary'],
            'rows'    => array_slice($preview['rows'], 0, 500),
        ]);
    }

    private function importCommit(array $b): array
    {
        $service = $this->importService();
        $stash = $service->unstash((string) ($b['token'] ?? ''));
        $result = $service->commit($stash['records'], [
            'create' => filter_var($b['create'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'update' => filter_var($b['update'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);
        $service->forget((string) $b['token']);
        return $this->ok($result);
    }

    private static function resolveId(array $data, string $singular, string $plural): ?int
    {
        $val = $data[$plural] ?? $data[$singular] ?? null;
        $val = is_array($val) ? ($val[0] ?? null) : $val;
        return $val !== null && $val !== '' && (int) $val > 0 ? (int) $val : null;
    }
}
