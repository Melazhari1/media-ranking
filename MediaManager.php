<?php

class MediaManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── Create ──────────────────────────────────────────────────────────────

    public function createMedia($title, $image, $categoryId, $ratingId, $year, $score, $scoreMal, $status = null, $infos = '')
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (title, image, category_id, rating_id, year, score, score_mal, status, infos)
             VALUES (:title, :image, :categoryId, :ratingId, :year, :score, :scoreMal, :status, :infos)"
        );
        $stmt->execute([
            'title'      => $title,
            'image'      => $image,
            'categoryId' => $categoryId ?: null,
            'ratingId'   => $ratingId   ?: null,
            'year'       => $year,
            'score'      => (float) $score,
            'scoreMal'   => (float) $scoreMal,
            'status'     => $this->sanitizeStatus($status),
            'infos'      => $infos ?? '',
        ]);
        return $this->pdo->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    public function getMediaById($id)
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.title, m.image, m.year, m.score, m.score_mal, m.status, m.infos,
                    m.category_id, m.rating_id,
                    c.name AS categories, r.name AS ratings, r.label AS rating_label
             FROM media m
             LEFT JOIN categories c ON m.category_id = c.id
             LEFT JOIN ratings r    ON m.rating_id   = r.id
             WHERE m.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getRandomMedia($limit = 20)
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.title, m.image, m.year, m.score, m.score_mal, m.status, m.infos,
                    m.category_id, m.rating_id,
                    c.name AS categories, r.name AS ratings, r.label AS rating_label
             FROM media m
             LEFT JOIN categories c ON m.category_id = c.id
             LEFT JOIN ratings r    ON m.rating_id   = r.id
             ORDER BY RAND()
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
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
        $allowedSorts = [
            'm.created_at DESC', 'm.created_at ASC',
            'm.title ASC',       'm.title DESC',
            'm.score DESC',      'm.score ASC',
            'm.score_mal DESC',  'm.score_mal ASC',
            'm.year DESC',       'm.year ASC',
        ];
        if (!in_array($orderBy, $allowedSorts)) {
            $orderBy = 'm.created_at DESC';
        }

        $params = [];
        $where  = $this->buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, $params);

        $sql = "SELECT m.id, m.title, m.image, m.year, m.score, m.score_mal, m.status, m.infos,
                       m.category_id, m.rating_id,
                       c.name AS categories, r.name AS ratings, r.label AS rating_label
                FROM media m
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN ratings r    ON m.rating_id   = r.id
                WHERE 1=1 $where
                ORDER BY $orderBy
                LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

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
                WHERE 1=1 $where";

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
        $stmt->execute([$title]);
        return $stmt->fetchColumn();
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function updateMedia($id, $title, $image, $categoryId, $ratingId, $year, $score, $scoreMal, $status = null, $infos = '')
    {
        $stmt = $this->pdo->prepare(
            "UPDATE media
             SET title       = :title,
                 image       = :image,
                 category_id = :categoryId,
                 rating_id   = :ratingId,
                 year        = :year,
                 score       = :score,
                 score_mal   = :scoreMal,
                 status      = :status,
                 infos       = :infos
             WHERE id = :id"
        );
        return $stmt->execute([
            'id'         => $id,
            'title'      => $title,
            'image'      => $image,
            'categoryId' => $categoryId ?: null,
            'ratingId'   => $ratingId   ?: null,
            'year'       => $year,
            'score'      => (float) $score,
            'scoreMal'   => (float) $scoreMal,
            'status'     => $this->sanitizeStatus($status),
            'infos'      => $infos ?? '',
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

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function deleteMedia($id)
    {
        $stmt = $this->pdo->prepare("DELETE FROM media WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function sanitizeStatus($status)
    {
        return ($status === '' || $status === 'null' || $status === null) ? null : $status;
    }

    private function buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, &$params)
    {
        $where = '';

        if (!empty($keyword)) {
            $keyword = trim($keyword);
            // Quoted keyword triggers exact title match
            if (preg_match('/^"(.*)"$/', $keyword, $matches)) {
                $where    .= " AND m.title = ?";
                $params[]  = $matches[1];
            } else {
                $where    .= " AND (m.title LIKE ? OR c.name LIKE ?)";
                $params[]  = "%$keyword%";
                $params[]  = "%$keyword%";
            }
        }

        if (!empty($categoryIds)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $where       .= " AND m.category_id IN ($placeholders)";
            foreach ($categoryIds as $id) {
                $params[] = $id;
            }
        }

        if (!empty($ratingIds)) {
            $placeholders = implode(',', array_fill(0, count($ratingIds), '?'));
            $where       .= " AND m.rating_id IN ($placeholders)";
            foreach ($ratingIds as $id) {
                $params[] = $id;
            }
        }

        if (!empty($statuses)) {
            $hasNull         = in_array('null', $statuses);
            $nonNullStatuses = array_values(array_filter($statuses, fn($s) => $s !== 'null'));

            if ($hasNull && empty($nonNullStatuses)) {
                // Only "Not in List" selected
                $where .= " AND m.status IS NULL";
            } elseif (!empty($nonNullStatuses)) {
                $placeholders = implode(',', array_fill(0, count($nonNullStatuses), '?'));
                if ($hasNull) {
                    $where .= " AND (m.status IN ($placeholders) OR m.status IS NULL)";
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
