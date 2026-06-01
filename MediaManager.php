<?php
// MediaManager.php

class MediaManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * CREATE: Adds media + links categories & ratings
     * Uses TRANSACTIONS to ensure all tables update correctly or none do.
     */
    public function createMedia($title, $image, $categoryId, $ratingId, $year, $score, $scoreMal, $status = null, $infos = '')
    {
        $sql = "INSERT INTO media (title, image, category_id, rating_id, year, score, score_mal, status, infos) 
                VALUES (:title, :image, :categoryId, :ratingId, :year, :score, :scoreMal, :status, :infos)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'title' => $title,
            'image' => $image,
            'categoryId' => $categoryId,
            'ratingId' => $ratingId,
            'year' => $year,
            'score' => $score,
            'scoreMal' => $scoreMal,
            'status' => $status,
            'infos' => $infos ?? ''
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * READ: Get full details with Joined data
     */
    public function getFullMediaList($limit = 20, $offset = 0)
    {
        $sql = "SELECT 
                    m.id, m.title, m.image, m.year, m.score, m.score_mal, m.status, m.infos, m.category_id, m.rating_id,
                    c.name AS categories,
                    r.name AS ratings,
                    r.label AS rating_label
                FROM media m
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN ratings r ON m.rating_id = r.id
                WHERE m.status IS NOT NULL
                ORDER BY m.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getRandomMedia($limit = 20)
    {
        $sql = "SELECT 
                    m.id, m.title, m.image, m.year, m.score, m.score_mal, m.status, m.infos, m.category_id, m.rating_id,
                    c.name AS categories,
                    r.name AS ratings,
                    r.label AS rating_label
                FROM media m
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN ratings r ON m.rating_id = r.id
                WHERE m.status IS NOT NULL
                ORDER BY RAND()
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getMediaById($id)
    {
        $sql = "SELECT m.*, c.name AS categories, r.name AS ratings, r.label AS rating_label 
                FROM media m 
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN ratings r ON m.rating_id = r.id
                WHERE m.id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * SEARCH & FILTER: Flexible method to filter by keyword, categories, and types
     */
    public function filterMedia($keyword = '', $categoryIds = [], $ratingIds = [], $statuses = [], $limit = 20, $offset = 0, $orderBy = 'm.created_at DESC')
    {
        $sql = "SELECT m.id, m.title, m.image, m.year, m.score, m.score_mal, m.status, m.infos, m.category_id, m.rating_id,
                       c.name AS categories,
                       r.name AS ratings,
                       r.label AS rating_label
                FROM media m
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN ratings r ON m.rating_id = r.id
                WHERE 1=1";

        $params = [];
        $whereSql = $this->buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, $params);
        $sql .= $whereSql;

        // Validation for order by to prevent SQL injection
        $allowedSorts = [
            'm.created_at DESC',
            'm.created_at ASC',
            'm.title ASC',
            'm.title DESC',
            'm.score DESC',
            'm.score ASC',
            'm.score_mal DESC',
            'm.score_mal ASC',
            'm.year DESC',
            'm.year ASC'
        ];

        if (!in_array($orderBy, $allowedSorts)) {
            $orderBy = 'm.created_at DESC';
        }

        $sql .= " ORDER BY $orderBy LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get total count for filters
     */
    public function countFilteredMedia($keyword = '', $categoryIds = [], $ratingIds = [], $statuses = [])
    {
        $sql = "SELECT COUNT(m.id) 
                FROM media m
                LEFT JOIN categories c ON m.category_id = c.id
                WHERE 1=1";

        $params = [];
        $whereSql = $this->buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, $params);
        $sql .= $whereSql;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Helper to build the WHERE clause for filtering
     */
    private function buildWhereClause($keyword, $categoryIds, $ratingIds, $statuses, &$params)
    {
        $whereSql = "";

        if (!empty($keyword)) {
            $keyword = trim($keyword);

            // Check for exact match with quotes
            if (preg_match('/^"(.*)"$/', $keyword, $matches)) {
                $exactKeyword = $matches[1];
                $whereSql .= " AND m.title = ?";
                $params[] = $exactKeyword;
            } else {
                $whereSql .= " AND (m.title LIKE ? OR c.name LIKE ?)";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
            }
        }

        if (!empty($categoryIds)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $whereSql .= " AND m.category_id IN ($placeholders)";
            foreach ($categoryIds as $id)
                $params[] = $id;
        }

        if (!empty($ratingIds)) {
            $placeholders = implode(',', array_fill(0, count($ratingIds), '?'));
            $whereSql .= " AND m.rating_id IN ($placeholders)";
            foreach ($ratingIds as $id)
                $params[] = $id;
        }

        if (!empty($statuses)) {
            $hasNull = in_array('null', $statuses);
            $nonNullStatuses = array_filter($statuses, fn($s) => $s !== 'null');

            if (!empty($nonNullStatuses)) {
                $placeholders = implode(',', array_fill(0, count($nonNullStatuses), '?'));
                if ($hasNull) {
                    $whereSql .= " AND (m.status IN ($placeholders) OR m.status IS NULL)";
                } else {
                    $whereSql .= " AND m.status IN ($placeholders)";
                }
                foreach ($nonNullStatuses as $stat) {
                    $params[] = $stat;
                }
            }
        }

        return $whereSql;
    }

    public function searchMedia($keyword, $limit = 20, $offset = 0)
    {
        return $this->filterMedia($keyword, [], [], [], $limit, $offset);
    }

    public function existsByTitle($title)
    {
        $stmt = $this->pdo->prepare("SELECT id FROM media WHERE title = ?");
        $stmt->execute([$title]);
        return $stmt->fetchColumn();
    }

    /**
     * DELETE: Cascading delete handles the junction tables automatically
     * thanks to your SQL (ON DELETE CASCADE)
     */
    public function updateStatus($id, $status)
    {
        $sql = "UPDATE media SET status = :status WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $dbStatus = ($status === 'null' || $status === '' || $status === null) ? null : $status;
        return $stmt->execute(['id' => $id, 'status' => $dbStatus]);
    }

    public function updateScore($id, $score)
    {
        $sql = "UPDATE media SET score = :score WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id, 'score' => $score]);
    }

    public function deleteMedia($id)
    {
        $stmt = $this->pdo->prepare("DELETE FROM media WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * UPDATE: Updates media details and links
     */
    public function updateMedia($id, $title, $image, $categoryId, $ratingId, $year, $score, $scoreMal, $status = null, $infos = '')
    {
        $sql = "UPDATE media SET title = :title, image = :image, category_id = :categoryId, rating_id = :ratingId, 
                year = :year, score = :score, score_mal = :score_mal, status = :status, infos = :infos WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'title' => $title,
            'image' => $image,
            'categoryId' => $categoryId,
            'ratingId' => $ratingId,
            'year' => $year,
            'score' => $score,
            'score_mal' => $scoreMal,
            'status' => $status,
            'infos' => $infos
        ]);
    }

    public function getCategories()
    {
        return $this->pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
    }

    public function getRatings()
    {
        return $this->pdo->query("SELECT * FROM ratings ORDER BY name ASC")->fetchAll();
    }
}