<?php

use App\Domain\MediaStatus;
use App\Domain\ProgressUnit;
use App\Text\TitleNormalizer;

class MediaManager
{
    public const SCORE_SOURCES = ['mal', 'tmdb', 'imdb', 'manual'];

    /**
     * Public score with a fallback to the legacy score_mal column, so the app shows correct
     * values even before app:backfill-public-scores has run. A score of 0 means "no score".
     */
    private const PUBLIC_SCORE_EXPR = 'COALESCE(m.public_score, NULLIF(ROUND(m.score_mal, 2), 0))';
    private const SCORE_SOURCE_EXPR = "COALESCE(m.score_source, IF(m.score_mal > 0, 'mal', NULL))";

    private const SELECT_COLUMNS = "m.id, m.title, m.sort_title, m.image, m.year, m.release_year, m.score,
                    " . self::PUBLIC_SCORE_EXPR . " AS public_score,
                    " . self::SCORE_SOURCE_EXPR . " AS score_source,
                    m.status, m.progress_current, m.progress_total, m.progress_unit,
                    m.started_at, m.finished_at, m.rewatch_count,
                    m.external_source, m.external_id, m.backdrop_url, m.last_synced_at,
                    m.infos, m.category_id, m.rating_id, m.created_at,
                    c.name AS categories, r.name AS ratings, r.label AS rating_label";

    /** Fields editable through updateTracking(). */
    public const TRACKING_FIELDS = ['progress_current', 'progress_total', 'progress_unit', 'started_at', 'finished_at', 'rewatch_count'];

    private const FROM = "FROM media m
             LEFT JOIN categories c ON m.category_id = c.id
             LEFT JOIN ratings r    ON m.rating_id   = r.id";

    /** Public sort keys (sent by the frontend) => SQL. Legacy keys are kept so old links keep working. */
    private const SORTS = [
        'm.created_at DESC'   => 'm.created_at DESC',
        'm.created_at ASC'    => 'm.created_at ASC',
        'm.title ASC'         => 'COALESCE(m.sort_title, m.title) ASC',
        'm.title DESC'        => 'COALESCE(m.sort_title, m.title) DESC',
        'm.score DESC'        => 'm.score DESC',
        'm.score ASC'         => 'm.score ASC',
        'm.public_score DESC' => self::PUBLIC_SCORE_EXPR . ' DESC',
        'm.public_score ASC'  => '(' . self::PUBLIC_SCORE_EXPR . ' IS NULL), ' . self::PUBLIC_SCORE_EXPR . ' ASC',
        'm.score_mal DESC'    => self::PUBLIC_SCORE_EXPR . ' DESC',
        'm.score_mal ASC'     => '(' . self::PUBLIC_SCORE_EXPR . ' IS NULL), ' . self::PUBLIC_SCORE_EXPR . ' ASC',
        // release_year is numeric (generated from `year`); "N/A" years go last both ways.
        'm.year DESC'         => 'm.release_year DESC, m.year DESC',
        'm.year ASC'          => '(m.release_year IS NULL), m.release_year ASC, m.year ASC',
        // Progress percentage (items without a total go last)
        'progress DESC'       => '(m.progress_total IS NULL OR m.progress_total = 0), (COALESCE(m.progress_current, 0) / NULLIF(m.progress_total, 0)) DESC',
        'progress ASC'        => '(m.progress_total IS NULL OR m.progress_total = 0), (COALESCE(m.progress_current, 0) / NULLIF(m.progress_total, 0)) ASC',
        'm.status ASC'        => "(m.status IS NULL OR m.status = ''), m.status ASC",
        'm.status DESC'       => "(m.status IS NULL OR m.status = ''), m.status DESC",
        'category ASC'        => '(c.name IS NULL), c.name ASC',
        'category DESC'       => '(c.name IS NULL), c.name DESC',
        'finished DESC'       => '(m.finished_at IS NULL), m.finished_at DESC',
        'list_position'       => 'm.created_at DESC', // replaced in search() when a list filter is active
    ];

    /** Rating names treated as adult content (blurred in the UI, excluded from public pages). */
    public const ADULT_RATINGS = ['Rx'];

    private PDO $pdo;
    private ?array $unknownCategoryIds = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── Create ──────────────────────────────────────────────────────────────

    public function createMedia($title, $image, $categoryId, $ratingId, $year, $score, $publicScore, $status = null, $infos = '', $scoreSource = null)
    {
        $title       = TitleNormalizer::normalize((string) $title);
        $publicScore = self::normalizePublicScore($publicScore);

        $stmt = $this->pdo->prepare(
            "INSERT INTO media (title, sort_title, image, category_id, rating_id, year, score, score_mal, public_score, score_source, status, infos)
             VALUES (:title, :sortTitle, :image, :categoryId, :ratingId, :year, :score, :scoreMal, :publicScore, :scoreSource, :status, :infos)"
        );
        $stmt->execute([
            'title'       => $title,
            'sortTitle'   => TitleNormalizer::sortTitle($title),
            'image'       => $image ?? '',
            'categoryId'  => $categoryId ?: null,
            'ratingId'    => $ratingId   ?: null,
            'year'        => $year,
            'score'       => (float) $score,
            'scoreMal'    => $publicScore ?? 0, // legacy mirror, keeps rollback lossless
            'publicScore' => $publicScore,
            'scoreSource' => self::normalizeScoreSource($scoreSource, $publicScore),
            'status'      => $this->sanitizeStatus($status),
            'infos'       => $infos ?? '',
        ]);
        return $this->pdo->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    public function getMediaById($id)
    {
        $stmt = $this->pdo->prepare(
            "SELECT " . self::SELECT_COLUMNS . ", m.synopsis " . self::FROM . " WHERE m.id = ? AND m.deleted_at IS NULL"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Random items respecting the same filters as the list (e.g. random Plan to Watch horror movie).
     * Accepts either an int limit (legacy) or a filter array + limit.
     */
    public function getRandomMedia($limitOrFilters = 20, int $limit = 20)
    {
        $filters = is_array($limitOrFilters) ? $limitOrFilters : [];
        $limit   = is_array($limitOrFilters) ? $limit : (int) $limitOrFilters;

        $params = [];
        $where  = $this->buildWhere($filters, $params);
        $stmt = $this->pdo->prepare(
            "SELECT " . self::SELECT_COLUMNS . " " . self::FROM . "
             WHERE m.deleted_at IS NULL $where
             ORDER BY RAND()
             LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Legacy positional signature (kept for callers/tests). */
    public function filterMedia(
        $keyword = '',
        $categoryIds = [],
        $ratingIds = [],
        $statuses = [],
        $limit = 20,
        $offset = 0,
        $orderBy = 'm.created_at DESC'
    ) {
        return $this->search(
            ['keyword' => $keyword, 'category_ids' => $categoryIds, 'rating_ids' => $ratingIds, 'statuses' => $statuses],
            (int) $limit,
            (int) $offset,
            (string) $orderBy
        );
    }

    public function countFilteredMedia($keyword = '', $categoryIds = [], $ratingIds = [], $statuses = [])
    {
        return $this->count(['keyword' => $keyword, 'category_ids' => $categoryIds, 'rating_ids' => $ratingIds, 'statuses' => $statuses]);
    }

    /**
     * Filtered, sorted page of media.
     *
     * Filter keys: keyword, category_ids[], rating_ids[], statuses[] ('null' = not in list), tag_ids[],
     * genre_ids[], list_id, finished_year, in_progress (bool), exclude_adult (bool), ids[], exclude_ids[].
     */
    public function search(array $filters, int $limit = 20, int $offset = 0, string $orderBy = 'm.created_at DESC'): array
    {
        $orderSql = self::SORTS[$orderBy] ?? self::SORTS['m.created_at DESC'];
        $params = [];
        $where  = $this->buildWhere($filters, $params);
        $join   = '';
        if ($orderBy === 'list_position' && !empty($filters['list_id'])) {
            $join     = ' JOIN user_list_media ulm_o ON ulm_o.media_id = m.id AND ulm_o.list_id = ' . (int) $filters['list_id'];
            $orderSql = 'ulm_o.position ASC';
        }

        // m.id is a tie-breaker so pages are stable when many rows share a sort value.
        $sql = "SELECT " . self::SELECT_COLUMNS . " " . self::FROM . "$join
                WHERE m.deleted_at IS NULL $where
                ORDER BY $orderSql, m.id DESC
                LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(array $filters): int
    {
        $params = [];
        $where  = $this->buildWhere($filters, $params);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(m.id) FROM media m
             LEFT JOIN categories c ON m.category_id = c.id
             LEFT JOIN ratings r    ON m.rating_id   = r.id
             WHERE m.deleted_at IS NULL $where"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Iterate all rows matching $filters in id order, in chunks (export). */
    public function iterate(array $filters, int $chunk = 500): \Generator
    {
        $lastId = 0;
        while (true) {
            $params = [];
            $where  = $this->buildWhere($filters, $params);
            $params[] = $lastId;
            $stmt = $this->pdo->prepare(
                "SELECT " . self::SELECT_COLUMNS . ", m.synopsis " . self::FROM . "
                 WHERE m.deleted_at IS NULL $where AND m.id > ? ORDER BY m.id LIMIT " . (int) $chunk
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                return;
            }
            foreach ($rows as $row) {
                yield $row;
            }
            $lastId = (int) end($rows)['id'];
        }
    }

    public function getCategories()
    {
        return $this->pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
    }

    public function getRatings()
    {
        return $this->pdo->query("SELECT * FROM ratings ORDER BY name ASC")->fetchAll();
    }

    public function existsByTitle($title)
    {
        $stmt = $this->pdo->prepare("SELECT id FROM media WHERE title = ?");
        $stmt->execute([TitleNormalizer::normalize((string) $title)]);
        return $stmt->fetchColumn();
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function updateMedia($id, $title, $image, $categoryId, $ratingId, $year, $score, $publicScore, $status = null, $infos = '', $scoreSource = null)
    {
        $title       = TitleNormalizer::normalize((string) $title);
        $publicScore = self::normalizePublicScore($publicScore);

        $stmt = $this->pdo->prepare(
            "UPDATE media
             SET title        = :title,
                 sort_title   = :sortTitle,
                 image        = :image,
                 category_id  = :categoryId,
                 rating_id    = :ratingId,
                 year         = :year,
                 score        = :score,
                 score_mal    = :scoreMal,
                 public_score = :publicScore,
                 score_source = :scoreSource,
                 status       = :status,
                 infos        = :infos
             WHERE id = :id"
        );
        return $stmt->execute([
            'id'          => $id,
            'title'       => $title,
            'sortTitle'   => TitleNormalizer::sortTitle($title),
            'image'       => $image ?? '',
            'categoryId'  => $categoryId ?: null,
            'ratingId'    => $ratingId   ?: null,
            'year'        => $year,
            'score'       => (float) $score,
            'scoreMal'    => $publicScore ?? 0,
            'publicScore' => $publicScore,
            'scoreSource' => self::normalizeScoreSource($scoreSource, $publicScore),
            'status'      => $this->sanitizeStatus($status),
            'infos'       => $infos ?? '',
        ]);
    }

    public function updateInfos($id, $infos)
    {
        $stmt = $this->pdo->prepare("UPDATE media SET infos = :infos WHERE id = :id");
        return $stmt->execute(['id' => $id, 'infos' => $infos ?? '']);
    }

    public function updateStatus($id, $status)
    {
        $stmt = $this->pdo->prepare("UPDATE media SET status = :status WHERE id = :id");
        return $stmt->execute(['id' => $id, 'status' => $this->sanitizeStatus($status)]);
    }

    public function updateScore($id, $score)
    {
        $stmt = $this->pdo->prepare("UPDATE media SET score = :score WHERE id = :id");
        return $stmt->execute(['id' => $id, 'score' => (float) $score]);
    }

    /**
     * Partial update of progress / dates / rewatch count. Only keys present in $fields change.
     * Validates the merged result (e.g. progress_current ≤ progress_total, finished ≥ started).
     *
     * @throws InvalidArgumentException on invalid input
     * @return array|false the updated media row, or false if not found
     */
    public function updateTracking($id, array $fields)
    {
        $current = $this->getMediaById($id);
        if (!$current) {
            return false;
        }

        $changes = [];
        foreach (array_intersect_key($fields, array_flip(self::TRACKING_FIELDS)) as $key => $value) {
            $changes[$key] = match ($key) {
                'progress_current', 'progress_total' => self::nullableCount($key, $value),
                'rewatch_count'                      => self::nullableCount($key, $value) ?? 0,
                'progress_unit'                      => ProgressUnit::fromInput($value)?->value,
                'started_at', 'finished_at'          => self::nullableDate($key, $value),
            };
        }
        if (!$changes) {
            return $current;
        }

        $merged = $changes + $current;
        if ($merged['progress_current'] !== null && $merged['progress_total'] !== null
            && (int) $merged['progress_current'] > (int) $merged['progress_total']) {
            throw new InvalidArgumentException('progress_current cannot exceed progress_total');
        }
        if ($merged['started_at'] && $merged['finished_at'] && $merged['finished_at'] < $merged['started_at']) {
            throw new InvalidArgumentException('finished_at cannot be before started_at');
        }

        $set = implode(', ', array_map(fn ($k) => "$k = :$k", array_keys($changes)));
        $stmt = $this->pdo->prepare("UPDATE media SET $set WHERE id = :id");
        $stmt->execute($changes + ['id' => $id]);

        return $this->getMediaById($id);
    }

    /**
     * +N progress. Starts tracking automatically: sets started_at (if empty) and moves
     * "not in list" / Plan to … into Watching/Reading (category-aware).
     *
     * @return array{item: array, reached_total: bool}|false
     */
    public function incrementProgress($id, int $by = 1)
    {
        $row = $this->getMediaById($id);
        if (!$row) {
            return false;
        }
        $total   = $row['progress_total'] !== null ? (int) $row['progress_total'] : null;
        $current = (int) ($row['progress_current'] ?? 0) + $by;
        $current = max(0, $total !== null ? min($current, $total) : $current);

        $status = MediaStatus::tryFrom((string) $row['status']);
        $set = ['progress_current' => $current];
        if (!$row['started_at'] && $by > 0) {
            $set['started_at'] = date('Y-m-d');
        }
        if ($by > 0 && ($status === null || $status->group() === 'plan')) {
            $set['status'] = MediaStatus::Watching->forCategory($row['categories'])->value;
        }
        if (!$row['progress_unit']) {
            $set['progress_unit'] = ProgressUnit::defaultFor($row['categories'])->value;
        }

        $cols = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($set)));
        $this->pdo->prepare("UPDATE media SET $cols WHERE id = :id")->execute($set + ['id' => $id]);

        $item = $this->getMediaById($id);
        $done = MediaStatus::tryFrom((string) $item['status'])?->isDone() ?? false;
        return ['item' => $item, 'reached_total' => $total !== null && $current >= $total && !$done];
    }

    /**
     * Mark as Watched/Read: progress = total, finished_at = today (if empty).
     * Completing a Rewatching item logs the rewatch (rewatch_count + rewatch_log) instead of
     * overwriting the original finished_at.
     */
    public function completeMedia($id, ?string $finishedAt = null)
    {
        $row = $this->getMediaById($id);
        if (!$row) {
            return false;
        }
        $finishedAt = self::nullableDate('finished_at', $finishedAt) ?? date('Y-m-d');
        $status = MediaStatus::tryFrom((string) $row['status']);

        $set = ['status' => MediaStatus::Watched->forCategory($row['categories'])->value];
        if ($row['progress_total'] !== null) {
            $set['progress_current'] = (int) $row['progress_total'];
        }

        $this->pdo->beginTransaction();
        try {
            if ($status === MediaStatus::Rewatching) {
                $this->logRewatch($id, null, $finishedAt, null, false);
            } else {
                if (!$row['finished_at']) {
                    $set['finished_at'] = $finishedAt;
                }
                if (!$row['started_at']) {
                    $set['started_at'] = min($finishedAt, date('Y-m-d'));
                }
            }
            $cols = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($set)));
            $this->pdo->prepare("UPDATE media SET $cols WHERE id = :id")->execute($set + ['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->getMediaById($id);
    }

    /** Record a rewatch/reread: adds a rewatch_log row and increments rewatch_count. */
    public function logRewatch($id, ?string $startedAt, ?string $finishedAt, ?string $notes, bool $ownTransaction = true): bool
    {
        $startedAt  = self::nullableDate('started_at', $startedAt);
        $finishedAt = self::nullableDate('finished_at', $finishedAt) ?? date('Y-m-d');
        if ($startedAt && $startedAt > $finishedAt) {
            throw new InvalidArgumentException('finished_at cannot be before started_at');
        }
        if (!$this->getMediaById($id)) {
            return false;
        }
        $ownTransaction && $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO rewatch_log (media_id, started_at, finished_at, notes) VALUES (?, ?, ?, ?)')
            ->execute([$id, $startedAt, $finishedAt, $notes !== null && trim($notes) !== '' ? trim($notes) : null]);
        $this->pdo->prepare('UPDATE media SET rewatch_count = rewatch_count + 1 WHERE id = ?')->execute([$id]);
        $ownTransaction && $this->pdo->commit();
        return true;
    }

    public function rewatchLog($id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, started_at, finished_at, notes, created_at FROM rewatch_log WHERE media_id = ? ORDER BY finished_at DESC, id DESC');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    /**
     * Set external metadata fields explicitly (Add/Edit form after choosing an auto-fill suggestion).
     * Keys: external_source, external_id, synopsis, backdrop_url, last_synced_at.
     */
    public function updateMetadataFields($id, array $fields): void
    {
        $allowed = ['external_source', 'external_id', 'synopsis', 'backdrop_url', 'last_synced_at'];
        $set = [];
        foreach (array_intersect_key($fields, array_flip($allowed)) as $k => $v) {
            $v = is_string($v) ? trim($v) : $v;
            $set[$k] = ($v === '' ? null : $v);
        }
        if (isset($set['external_source']) && !in_array($set['external_source'], ['mal_anime', 'mal_manga', 'tmdb_movie', 'tmdb_tv'], true)) {
            throw new InvalidArgumentException('Unknown external_source');
        }
        if (isset($set['external_id']) && !preg_match('/^[0-9]{1,12}$/', (string) $set['external_id'])) {
            throw new InvalidArgumentException('Invalid external_id');
        }
        if (isset($set['backdrop_url']) && !preg_match('#^https://#', (string) $set['backdrop_url'])) {
            throw new InvalidArgumentException('backdrop_url must be an https URL');
        }
        if (!$set) {
            return;
        }
        $cols = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($set)));
        $this->pdo->prepare("UPDATE media SET $cols WHERE id = :id")->execute($set + ['id' => $id]);
    }

    /**
     * Apply one operation to many items in a single transaction.
     * ops: set_status (value: status|null, wording adapted per item category), set_category, set_rating,
     *      delete (soft), restore. List/tag operations are handled by their repositories.
     *
     * @return int affected rows
     */
    public function batch(array $ids, string $op, $value = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            throw new InvalidArgumentException('No items selected');
        }
        if (count($ids) > 1000) {
            throw new InvalidArgumentException('At most 1000 items per batch');
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $this->pdo->beginTransaction();
        try {
            switch ($op) {
                case 'set_status':
                    $status = MediaStatus::fromInput($value);
                    $affected = 0;
                    $stmt = $this->pdo->prepare("SELECT m.id, c.name FROM media m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id IN ($ph) AND m.deleted_at IS NULL");
                    $stmt->execute($ids);
                    $update = $this->pdo->prepare('UPDATE media SET status = ? WHERE id = ?');
                    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $category) {
                        $update->execute([$status?->forCategory($category)->value, $id]);
                        $affected++;
                    }
                    break;
                case 'set_category':
                case 'set_rating':
                    $table = $op === 'set_category' ? 'categories' : 'ratings';
                    $col = $op === 'set_category' ? 'category_id' : 'rating_id';
                    $check = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE id = ?");
                    $check->execute([(int) $value]);
                    if (!$check->fetchColumn()) {
                        throw new InvalidArgumentException("Unknown $col");
                    }
                    $stmt = $this->pdo->prepare("UPDATE media SET $col = ? WHERE id IN ($ph) AND deleted_at IS NULL");
                    $stmt->execute(array_merge([(int) $value], $ids));
                    $affected = count($ids);
                    break;
                case 'delete':
                    $stmt = $this->pdo->prepare("UPDATE media SET deleted_at = NOW() WHERE id IN ($ph) AND deleted_at IS NULL");
                    $stmt->execute($ids);
                    $affected = $stmt->rowCount();
                    break;
                case 'restore':
                    $stmt = $this->pdo->prepare("UPDATE media SET deleted_at = NULL WHERE id IN ($ph) AND deleted_at IS NOT NULL");
                    $stmt->execute($ids);
                    $affected = $stmt->rowCount();
                    break;
                default:
                    throw new InvalidArgumentException("Unknown batch operation '$op'");
            }
            $this->pdo->commit();
            return $affected;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Diary entries: finished items + logged rewatches, newest first.
     * @param int|string $year a year or 'all'
     * @return array{entries: array, years: int[]}
     */
    public function diary($year): array
    {
        $yearCond = $year === 'all' ? '' : ' AND YEAR(%s) = ' . (int) $year;
        $finished = $this->pdo->query(
            "SELECT 'finish' AS kind, m.id AS entry_id, m.finished_at AS entry_date, NULL AS notes, " . self::SELECT_COLUMNS . ' ' . self::FROM . "
             WHERE m.deleted_at IS NULL AND m.finished_at IS NOT NULL" . sprintf($yearCond, 'm.finished_at')
        )->fetchAll();
        $rewatches = $this->pdo->query(
            "SELECT 'rewatch' AS kind, rl.id AS entry_id, rl.finished_at AS entry_date, rl.notes AS notes, " . self::SELECT_COLUMNS . "
             FROM rewatch_log rl JOIN media m ON m.id = rl.media_id
             LEFT JOIN categories c ON m.category_id = c.id LEFT JOIN ratings r ON m.rating_id = r.id
             WHERE m.deleted_at IS NULL AND rl.finished_at IS NOT NULL" . sprintf($yearCond, 'rl.finished_at')
        )->fetchAll();
        // entry_date is the entry's own date (SELECT_COLUMNS also carries the item's finished_at).
        $entries = array_merge($finished, $rewatches);
        usort($entries, fn ($a, $b) => [$b['entry_date'], $b['entry_id']] <=> [$a['entry_date'], $a['entry_id']]);

        $years = $this->pdo->query(
            'SELECT DISTINCT y FROM (
                SELECT YEAR(finished_at) y FROM media WHERE deleted_at IS NULL AND finished_at IS NOT NULL
                UNION SELECT YEAR(rl.finished_at) FROM rewatch_log rl JOIN media m ON m.id = rl.media_id WHERE m.deleted_at IS NULL AND rl.finished_at IS NOT NULL
             ) t ORDER BY y DESC'
        )->fetchAll(PDO::FETCH_COLUMN);
        return ['entries' => $entries, 'years' => array_map('intval', $years)];
    }

    /** Lightweight rows for duplicate detection (importers). */
    public function matchIndexRows(): array
    {
        return $this->pdo->query(
            'SELECT m.id, m.title, m.release_year, m.external_source, m.external_id, m.status, m.score,
                    m.progress_current, m.progress_total, m.started_at, m.finished_at, m.rewatch_count, m.infos,
                    c.name AS category
             FROM media m LEFT JOIN categories c ON c.id = m.category_id WHERE m.deleted_at IS NULL'
        )->fetchAll();
    }

    // ─── Delete (soft) ────────────────────────────────────────────────────────

    /** Soft delete: the row is hidden everywhere and can be restored; app:purge-deleted removes it for good. */
    public function deleteMedia($id)
    {
        $stmt = $this->pdo->prepare("UPDATE media SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function restoreMedia($id)
    {
        $stmt = $this->pdo->prepare("UPDATE media SET deleted_at = NULL WHERE id = :id AND deleted_at IS NOT NULL");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public static function normalizePublicScore($value): ?float
    {
        if ($value === null || $value === '' || $value === 'null' || !is_numeric($value)) {
            return null;
        }
        $v = round((float) $value, 2);
        if ($v <= 0) {
            return null; // 0 means "no score"
        }
        return min($v, 10.0);
    }

    public static function normalizeScoreSource($source, ?float $publicScore): ?string
    {
        if ($publicScore === null) {
            return null;
        }
        $source = strtolower(trim((string) $source));
        return in_array($source, self::SCORE_SOURCES, true) ? $source : 'manual';
    }

    /** @throws InvalidArgumentException for values outside MediaStatus */
    private function sanitizeStatus($status)
    {
        return MediaStatus::fromInput($status)?->value;
    }

    private static function nullableCount(string $field, $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 100000) {
            throw new InvalidArgumentException("$field must be a whole number between 0 and 100000");
        }
        return (int) $value;
    }

    private static function nullableDate(string $field, $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("$field must be a date in YYYY-MM-DD format");
        }
        return $value;
    }

    private function unknownCategoryIds(): array
    {
        if ($this->unknownCategoryIds === null) {
            $this->unknownCategoryIds = array_map(
                'intval',
                $this->pdo->query("SELECT id FROM categories WHERE name = 'Unknown'")->fetchAll(PDO::FETCH_COLUMN)
            );
        }
        return $this->unknownCategoryIds;
    }

    private function buildWhere(array $f, array &$params): string
    {
        $where = '';
        $ints = fn ($v) => array_values(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string) $v))));
        $placeholders = fn (array $v) => implode(',', array_fill(0, count($v), '?'));

        $keyword = trim((string) ($f['keyword'] ?? ''));
        if ($keyword !== '') {
            // Quoted keyword triggers exact title match
            if (preg_match('/^"(.*)"$/', $keyword, $matches)) {
                $where    .= " AND m.title = ?";
                $params[]  = TitleNormalizer::normalize($matches[1]);
            } else {
                // sort_title is NFKC-normalized, so "black phone" also finds "𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞".
                $where    .= " AND (m.title LIKE ? OR m.sort_title LIKE ? OR c.name LIKE ?)";
                $params[]  = "%$keyword%";
                $params[]  = '%' . TitleNormalizer::searchKey($keyword) . '%';
                $params[]  = "%$keyword%";
            }
        }

        if ($categoryIds = $ints($f['category_ids'] ?? [])) {
            // "Unknown" also matches media with no category or a dangling category id.
            $cond = 'm.category_id IN (' . $placeholders($categoryIds) . ')';
            $where .= array_intersect($categoryIds, $this->unknownCategoryIds()) ? " AND ($cond OR c.id IS NULL)" : " AND $cond";
            array_push($params, ...$categoryIds);
        }

        if ($ratingIds = $ints($f['rating_ids'] ?? [])) {
            $where .= ' AND m.rating_id IN (' . $placeholders($ratingIds) . ')';
            array_push($params, ...$ratingIds);
        }

        $statuses = $f['statuses'] ?? [];
        $statuses = is_array($statuses) ? $statuses : array_filter(explode(',', (string) $statuses), 'strlen');
        if ($statuses) {
            $hasNull         = in_array('null', $statuses, true);
            $nonNullStatuses = array_values(array_filter($statuses, fn ($s) => $s !== 'null'));
            $nullCond        = "(m.status IS NULL OR m.status = '')";
            if ($hasNull && !$nonNullStatuses) {
                $where .= " AND $nullCond";
            } elseif ($nonNullStatuses) {
                $ph = $placeholders($nonNullStatuses);
                $where .= $hasNull ? " AND (m.status IN ($ph) OR $nullCond)" : " AND m.status IN ($ph)";
                array_push($params, ...$nonNullStatuses);
            }
        }

        if ($tagIds = $ints($f['tag_ids'] ?? [])) {
            $where .= ' AND m.id IN (SELECT media_id FROM media_tag WHERE tag_id IN (' . $placeholders($tagIds) . '))';
            array_push($params, ...$tagIds);
        }

        if ($genreIds = $ints($f['genre_ids'] ?? [])) {
            $where .= ' AND m.id IN (SELECT media_id FROM media_genre WHERE genre_id IN (' . $placeholders($genreIds) . '))';
            array_push($params, ...$genreIds);
        }

        if (!empty($f['list_id'])) {
            $where .= ' AND m.id IN (SELECT media_id FROM user_list_media WHERE list_id = ?)';
            $params[] = (int) $f['list_id'];
        }

        if (!empty($f['finished_year'])) {
            $year = (int) $f['finished_year'];
            $where .= ' AND (YEAR(m.finished_at) = ? OR m.id IN (SELECT media_id FROM rewatch_log WHERE YEAR(finished_at) = ?))';
            array_push($params, $year, $year);
        }

        if (!empty($f['in_progress'])) {
            $where .= " AND m.status IN ('Watching', 'Reading', 'Rewatching')";
        }

        if (!empty($f['exclude_adult'])) {
            $where .= ' AND (r.name IS NULL OR r.name NOT IN (' . $placeholders(self::ADULT_RATINGS) . '))';
            array_push($params, ...self::ADULT_RATINGS);
        }

        if (array_key_exists('ids', $f)) {
            $ids = $ints($f['ids']) ?: [0];
            $where .= ' AND m.id IN (' . $placeholders($ids) . ')';
            array_push($params, ...$ids);
        }

        if ($exclude = $ints($f['exclude_ids'] ?? [])) {
            $where .= ' AND m.id NOT IN (' . $placeholders($exclude) . ')';
            array_push($params, ...$exclude);
        }

        return $where;
    }
}
