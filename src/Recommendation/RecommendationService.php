<?php

namespace App\Recommendation;

use App\Domain\MediaStatus;
use App\Repository\GenreRepository;
use MediaManager;
use PDO;

/**
 * Content-based recommendations.
 *
 *  - Taste profile: genre and category weights from rated items in the list, centred on the
 *    user's mean rating (loved genres > 0, disliked < 0).
 *  - "Because you liked X": seeds are the highest-rated, most recently finished items.
 *    Candidates share genres with the seed (or its category when genres are unknown).
 *  - Plan to Watch/Read items are suggested first, then items not yet in the list.
 *    Finished, dropped and in-progress items are never suggested. Adult items are excluded
 *    unless the seed itself is adult.
 */
final class RecommendationService
{
    private const IN_LIST = "m.deleted_at IS NULL AND m.status IS NOT NULL AND m.status <> ''";

    public function __construct(private PDO $pdo, private MediaManager $media, private GenreRepository $genres)
    {
    }

    /** @return array<array{seed: array, items: array}> */
    public function becauseYouLiked(int $rows = 2, int $perRow = 12): array
    {
        $seeds = $this->pdo->query(
            'SELECT m.id FROM media m WHERE ' . self::IN_LIST . " AND m.score >= 7
               AND m.status IN ('Watched', 'Read', 'Rewatching', 'Watching', 'Reading')
             ORDER BY m.score DESC, COALESCE(m.finished_at, m.created_at) DESC LIMIT 25"
        )->fetchAll(PDO::FETCH_COLUMN);

        $out = [];
        $used = [];
        $profile = $this->profile();
        foreach ($seeds as $seedId) {
            $seed = $this->media->getMediaById((int) $seedId);
            $items = $this->similarTo($seed, $perRow, $used, $profile);
            if (count($items) >= 3) {
                $out[] = ['seed' => $seed, 'items' => $items];
                $used = array_merge($used, array_column($items, 'id'));
            }
            if (count($out) >= $rows) {
                break;
            }
        }
        return $out;
    }

    /** Related titles for a detail page (any status except the item itself). */
    public function related(int $mediaId, int $limit = 12): array
    {
        $seed = $this->media->getMediaById($mediaId);
        return $seed ? $this->similarTo($seed, $limit, [], $this->profile(), true) : [];
    }

    /** @return array<string, array<string,float>> ['genre' => [name => w], 'category' => [name => w]] */
    public function profile(): array
    {
        $mean = (float) ($this->pdo->query('SELECT AVG(score) FROM media m WHERE ' . self::IN_LIST . ' AND m.score > 0')->fetchColumn() ?: 6.5);
        $genre = [];
        foreach ($this->pdo->query(
            'SELECT g.name, SUM(m.score - ' . $mean . ') w, COUNT(*) n FROM media m
             JOIN media_genre mg ON mg.media_id = m.id JOIN genres g ON g.id = mg.genre_id
             WHERE ' . self::IN_LIST . ' AND m.score > 0 GROUP BY g.id'
        ) as $r) {
            $genre[$r['name']] = (float) $r['w'] / max(1, sqrt((int) $r['n']));
        }
        $category = [];
        foreach ($this->pdo->query(
            'SELECT c.name, AVG(m.score - ' . $mean . ') w FROM media m JOIN categories c ON c.id = m.category_id
             WHERE ' . self::IN_LIST . ' AND m.score > 0 GROUP BY c.id'
        ) as $r) {
            $category[$r['name']] = (float) $r['w'];
        }
        return ['genre' => self::normalize($genre), 'category' => self::normalize($category)];
    }

    private function similarTo(array $seed, int $limit, array $excludeIds, array $profile, bool $anyStatus = false): array
    {
        $seedId     = (int) $seed['id'];
        $seedGenres = $this->genres->namesFor([$seedId])[$seedId] ?? [];
        $seedAdult  = in_array($seed['ratings'], MediaManager::ADULT_RATINGS, true);

        $statusCond = $anyStatus
            ? ''
            : " AND (m.status IS NULL OR m.status = '' OR m.status IN ('" . MediaStatus::PlanToWatch->value . "', '" . MediaStatus::PlanToRead->value . "'))";
        $adultCond = $seedAdult ? '' : " AND (r.name IS NULL OR r.name NOT IN ('" . implode("','", MediaManager::ADULT_RATINGS) . "'))";
        $exclude = implode(',', array_map('intval', array_merge([$seedId], $excludeIds)));

        // Candidate pool: share a genre with the seed, else same category. Best public scores first.
        if ($seedGenres) {
            $ph = implode(',', array_fill(0, count($seedGenres), '?'));
            $sql = "SELECT DISTINCT m.id FROM media m
                    JOIN media_genre mg ON mg.media_id = m.id JOIN genres g ON g.id = mg.genre_id
                    LEFT JOIN ratings r ON r.id = m.rating_id
                    WHERE m.deleted_at IS NULL AND g.name IN ($ph) AND m.id NOT IN ($exclude) $statusCond $adultCond
                    ORDER BY (m.status IS NOT NULL AND m.status <> '') DESC, COALESCE(m.public_score, m.score_mal) DESC LIMIT 400";
            $params = $seedGenres;
        } else {
            $sql = "SELECT m.id FROM media m LEFT JOIN ratings r ON r.id = m.rating_id
                    WHERE m.deleted_at IS NULL AND m.category_id <=> ? AND m.id NOT IN ($exclude) $statusCond $adultCond
                    ORDER BY (m.status IS NOT NULL AND m.status <> '') DESC, COALESCE(m.public_score, m.score_mal) DESC LIMIT 400";
            $params = [$seed['category_id']];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return [];
        }

        $rows = $this->media->search(['ids' => $ids], count($ids));
        $genreMap = $this->genres->namesFor($ids);
        $seedSet = array_flip($seedGenres);

        foreach ($rows as &$row) {
            $g = $genreMap[(int) $row['id']] ?? [];
            $shared = $seedSet ? count(array_intersect_key(array_flip($g), $seedSet)) / count($seedSet) : 0;
            $taste = 0.0;
            foreach ($g as $name) {
                $taste += $profile['genre'][$name] ?? 0;
            }
            $taste = $g ? $taste / count($g) : 0;
            $cat = $profile['category'][$row['categories'] ?? ''] ?? 0;
            $public = $row['public_score'] !== null ? (float) $row['public_score'] / 10 : 0.5;
            $plan = MediaStatus::tryFrom((string) $row['status'])?->group() === 'plan' ? 1 : 0;

            $row['reco_score'] = round(3 * $shared + 1.5 * $taste + 0.5 * $cat + $public, 3);
            $row['reco_plan'] = $plan;
            $row['genres'] = $g;
        }
        unset($row);

        // Plan-to items first, then by score.
        usort($rows, fn ($a, $b) => [$b['reco_plan'], $b['reco_score']] <=> [$a['reco_plan'], $a['reco_score']]);
        return array_slice($rows, 0, $limit);
    }

    /** Scale weights into [-1, 1]. */
    private static function normalize(array $w): array
    {
        $max = max(array_map('abs', $w ?: [1])) ?: 1;
        return array_map(fn ($v) => $v / $max, $w);
    }
}
