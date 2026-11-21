<?php

namespace App\Stats;

use App\Cache\FileCache;
use App\Domain\MediaStatus;
use PDO;

/**
 * All dashboard numbers in one payload. "In my list" = status set and not deleted.
 * Cached for 10 minutes; Services::invalidateAggregates() clears it after writes.
 */
final class StatsService
{
    private const IN_LIST = "m.deleted_at IS NULL AND m.status IS NOT NULL AND m.status <> ''";
    private const PUBLIC  = 'COALESCE(m.public_score, NULLIF(ROUND(m.score_mal, 2), 0))';

    public function __construct(private PDO $pdo, private FileCache $cache)
    {
    }

    public function get(): array
    {
        return $this->cache->remember('dashboard', 600, fn () => $this->compute());
    }

    public function compute(): array
    {
        $q = fn (string $sql) => $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $one = fn (string $sql) => $this->pdo->query($sql)->fetchColumn();

        $byStatus = array_map(function ($r) {
            $s = MediaStatus::tryFrom($r['status']);
            return ['status' => $r['status'], 'group' => $s?->group() ?? 'plan', 'count' => (int) $r['n']];
        }, $q('SELECT m.status, COUNT(*) n FROM media m WHERE ' . self::IN_LIST . ' GROUP BY m.status ORDER BY n DESC'));

        $byGroup = [];
        foreach ($byStatus as $s) {
            $byGroup[$s['group']] = ($byGroup[$s['group']] ?? 0) + $s['count'];
        }

        $byCategory = array_map(fn ($r) => ['category' => $r['category'] ?? 'Unknown', 'count' => (int) $r['n']], $q(
            'SELECT COALESCE(c.name, \'Unknown\') category, COUNT(*) n FROM media m LEFT JOIN categories c ON c.id = m.category_id
             WHERE ' . self::IN_LIST . ' GROUP BY category ORDER BY n DESC'
        ));

        $ratings = $q('SELECT ROUND(AVG(m.score), 2) my_avg, COUNT(*) n,
                              ROUND(AVG(' . self::PUBLIC . '), 2) public_avg,
                              SUM(' . self::PUBLIC . ' IS NOT NULL) public_n
                       FROM media m WHERE ' . self::IN_LIST . ' AND m.score > 0')[0];

        $distribution = array_fill(0, 11, 0);
        foreach ($q('SELECT LEAST(10, GREATEST(0, ROUND(m.score))) b, COUNT(*) n FROM media m WHERE ' . self::IN_LIST . ' AND m.score > 0 GROUP BY b') as $r) {
            $distribution[(int) $r['b']] = (int) $r['n'];
        }

        $topGenres = array_map(fn ($r) => ['genre' => $r['name'], 'count' => (int) $r['n'], 'my_avg' => $r['my_avg'] !== null ? (float) $r['my_avg'] : null], $q(
            'SELECT g.name, COUNT(*) n, ROUND(AVG(NULLIF(m.score, 0)), 2) my_avg
             FROM media m JOIN media_genre mg ON mg.media_id = m.id JOIN genres g ON g.id = mg.genre_id
             WHERE ' . self::IN_LIST . ' GROUP BY g.id ORDER BY n DESC, g.name LIMIT 12'
        ));

        $perMonth = array_map(fn ($r) => ['month' => $r['ym'], 'count' => (int) $r['n']], $q(
            "SELECT DATE_FORMAT(d, '%Y-%m') ym, COUNT(*) n FROM (
                 SELECT m.finished_at d FROM media m WHERE m.deleted_at IS NULL AND m.finished_at IS NOT NULL
                 UNION ALL
                 SELECT rl.finished_at FROM rewatch_log rl JOIN media m ON m.id = rl.media_id WHERE m.deleted_at IS NULL AND rl.finished_at IS NOT NULL
             ) t WHERE d >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 23 MONTH)
             GROUP BY ym ORDER BY ym"
        ));
        $perYear = array_map(fn ($r) => ['year' => (int) $r['y'], 'count' => (int) $r['n']], $q(
            'SELECT YEAR(d) y, COUNT(*) n FROM (
                 SELECT m.finished_at d FROM media m WHERE m.deleted_at IS NULL AND m.finished_at IS NOT NULL
                 UNION ALL
                 SELECT rl.finished_at FROM rewatch_log rl JOIN media m ON m.id = rl.media_id WHERE m.deleted_at IS NULL AND rl.finished_at IS NOT NULL
             ) t GROUP BY y ORDER BY y'
        ));

        // Episodes/chapters consumed: progress_current, or the full total for finished items without progress.
        $done = "'" . implode("','", [MediaStatus::Watched->value, MediaStatus::Read->value]) . "'";
        $consumed = [];
        foreach ($q("SELECT COALESCE(m.progress_unit, 'episode') unit,
                            SUM(COALESCE(m.progress_current, IF(m.status IN ($done), m.progress_total, 0), 0)
                                + m.rewatch_count * COALESCE(m.progress_total, 0)) n
                     FROM media m WHERE " . self::IN_LIST . ' GROUP BY unit') as $r) {
            $consumed[$r['unit']] = (int) $r['n'];
        }

        return [
            'generated_at' => date('c'),
            'totals' => [
                'in_list'     => array_sum(array_column($byStatus, 'count')),
                'all'         => (int) $one('SELECT COUNT(*) FROM media WHERE deleted_at IS NULL'),
                'by_status'   => $byStatus,
                'by_group'    => $byGroup,
                'by_category' => $byCategory,
            ],
            'ratings' => [
                'my_avg'        => $ratings['my_avg'] !== null ? (float) $ratings['my_avg'] : null,
                'rated_count'   => (int) $ratings['n'],
                'public_avg'    => $ratings['public_avg'] !== null ? (float) $ratings['public_avg'] : null,
                'public_count'  => (int) $ratings['public_n'],
                'distribution'  => $distribution,
                // Ratings identical to the MAL score are likely copies from the legacy importer.
                'equal_to_mal'  => (int) $one('SELECT COUNT(*) FROM media m WHERE ' . self::IN_LIST . ' AND m.score > 0 AND ABS(m.score - m.score_mal) < 0.001'),
            ],
            'top_genres'     => $topGenres,
            'finished'       => ['per_month' => $perMonth, 'per_year' => $perYear],
            'consumed'       => $consumed,
            'rewatches'      => (int) $one('SELECT COALESCE(SUM(rewatch_count), 0) FROM media WHERE deleted_at IS NULL'),
        ];
    }
}
