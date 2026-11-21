<?php

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

    private const SELECT_COLUMNS = "m.id, m.title, m.sort_title, m.image, m.year, m.score,
                    " . self::PUBLIC_SCORE_EXPR . " AS public_score,
                    " . self::SCORE_SOURCE_EXPR . " AS score_source,
                    m.status, m.infos, m.category_id, m.rating_id, m.created_at,
                    c.name AS categories, r.name AS ratings, r.label AS rating_label";

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
        'm.year DESC'         => 'm.year DESC',
        'm.year ASC'          => 'm.year ASC',
    ];

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
            "SELECT " . self::SELECT_COLUMNS . " " . self::FROM . " WHERE m.id = ? AND m.deleted_at IS NULL"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getRandomMedia($limit = 20)
    {
        $stmt = $this->pdo->prepare(
            "SELECT " . self::SELECT_COLUMNS . " " . self::FROM . "
             WHERE m.deleted_at IS NULL
             ORDER BY RAND()
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(100, (int) $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function filterMedia(
        $keyword = '',
        $categoryIds = [],
        $ratingIds = [],
        $statuses = [],
        $limit = 20,
        $offset = 0,
        $orderBy = 'm.created_at DESC'
    ) {
        $orderSql = self::SORTS[$orderBy] ?? self::SORTS['m.created_at DESC'];

        $params = [];
        $where  = $this->buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, $params);

        // m.id is a tie-breaker so pages are stable when many rows share a sort value.
        $sql = "SELECT " . self::SELECT_COLUMNS . " " . self::FROM . "
                WHERE m.deleted_at IS NULL $where
                ORDER BY $orderSql, m.id DESC
                LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countFilteredMedia($keyword = '', $categoryIds = [], $ratingIds = [], $statuses = [])
    {
        $params = [];
        $where  = $this->buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, $params);

        $sql = "SELECT COUNT(m.id)
                FROM media m
                LEFT JOIN categories c ON m.category_id = c.id
                WHERE m.deleted_at IS NULL $where";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
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

    private function sanitizeStatus($status)
    {
        return ($status === '' || $status === 'null' || $status === null) ? null : $status;
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

    private function buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, &$params)
    {
        $where = '';

        if (!empty($keyword)) {
            $keyword = trim($keyword);
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

        if (!empty($categoryIds)) {
            $categoryIds  = array_map('intval', $categoryIds);
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            // "Unknown" also matches media with no category or a dangling category id.
            $includeUncategorized = (bool) array_intersect($categoryIds, $this->unknownCategoryIds());
            $where .= $includeUncategorized
                ? " AND (m.category_id IN ($placeholders) OR c.id IS NULL)"
                : " AND m.category_id IN ($placeholders)";
            foreach ($categoryIds as $id) {
                $params[] = $id;
            }
        }

        if (!empty($ratingIds)) {
            $placeholders = implode(',', array_fill(0, count($ratingIds), '?'));
            $where       .= " AND m.rating_id IN ($placeholders)";
            foreach ($ratingIds as $id) {
                $params[] = (int) $id;
            }
        }

        if (!empty($statuses)) {
            $hasNull         = in_array('null', $statuses);
            $nonNullStatuses = array_values(array_filter($statuses, fn($s) => $s !== 'null'));
            $nullCond        = "(m.status IS NULL OR m.status = '')";

            if ($hasNull && empty($nonNullStatuses)) {
                // Only "Not in List" selected
                $where .= " AND $nullCond";
            } elseif (!empty($nonNullStatuses)) {
                $placeholders = implode(',', array_fill(0, count($nonNullStatuses), '?'));
                if ($hasNull) {
                    $where .= " AND (m.status IN ($placeholders) OR $nullCond)";
                } else {
                    $where .= " AND m.status IN ($placeholders)";
                }
                foreach ($nonNullStatuses as $stat) {
                    $params[] = $stat;
                }
            }
        }

        return $where;
    }
}
