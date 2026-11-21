<?php

namespace App\PublicProfile;

use App\Domain\MediaStatus;
use App\Repository\ListRepository;
use App\Repository\SettingsRepository;
use MediaManager;
use PDO;

/**
 * Read-only data for the opt-in public profile (/u/{username}).
 * Notes and adult (Rx) items are never included unless explicitly enabled in settings.
 */
final class PublicProfileService
{
    private const IN_LIST = "m.deleted_at IS NULL AND m.status IS NOT NULL AND m.status <> ''";

    public function __construct(
        private PDO $pdo,
        private MediaManager $media,
        private SettingsRepository $settings,
        private ListRepository $lists,
    ) {
    }

    /** Settings if the profile is enabled for this username (case-insensitive), else null. */
    public function settingsFor(string $username): ?array
    {
        $s = $this->settings->all();
        if (!$s['public_enabled'] || $s['username'] === '' || strcasecmp($s['username'], $username) !== 0) {
            return null;
        }
        return $s;
    }

    public function build(string $username): ?array
    {
        $s = $this->settingsFor($username);
        if (!$s) {
            return null;
        }
        $filters = ['statuses' => MediaStatus::values()] + ($s['public_show_adult'] ? [] : ['exclude_adult' => true]);
        $adultSql = $s['public_show_adult'] ? '' : " AND (r.name IS NULL OR r.name NOT IN ('" . implode("','", MediaManager::ADULT_RATINGS) . "'))";
        $from = 'FROM media m LEFT JOIN ratings r ON r.id = m.rating_id';

        $groups = [];
        foreach ($this->pdo->query("SELECT m.status, COUNT(*) n $from WHERE " . self::IN_LIST . $adultSql . ' GROUP BY m.status') as $row) {
            $g = MediaStatus::tryFrom($row['status'])?->group() ?? 'plan';
            $groups[$g] = ($groups[$g] ?? 0) + (int) $row['n'];
        }
        $avg = $this->pdo->query("SELECT ROUND(AVG(m.score), 2) $from WHERE " . self::IN_LIST . " AND m.score > 0$adultSql")->fetchColumn();
        $finishedYear = (int) $this->pdo->query("SELECT COUNT(*) $from WHERE m.deleted_at IS NULL AND YEAR(m.finished_at) = YEAR(CURDATE())$adultSql")->fetchColumn();

        $top = array_values(array_filter(
            $this->media->search($filters, 10, 0, 'm.score DESC'),
            fn ($r) => (float) $r['score'] > 0
        ));

        $lists = [];
        foreach ($this->lists->all(true) as $l) {
            $items = $this->media->search($filters + ['list_id' => (int) $l['id']], 24, 0, 'list_position');
            $lists[] = ['name' => $l['name'], 'description' => $l['description'], 'count' => count($items), 'items' => array_map([$this, 'publicItem'], $items)];
        }

        $clean = fn (array $rows) => array_map(function ($r) use ($s) {
            $p = $this->publicItem($r);
            if ($s['public_show_notes'] && trim((string) $r['infos']) !== '') {
                $p['notes'] = $r['infos'];
            }
            return $p;
        }, $rows);

        return [
            'username'     => $s['username'],
            'display_name' => $s['display_name'] ?: $s['username'],
            'stats'        => [
                'in_list'        => array_sum($groups),
                'by_group'       => $groups,
                'my_avg'         => $avg !== null && $avg !== false ? (float) $avg : null,
                'finished_year'  => $finishedYear,
            ],
            'top'          => $clean($top),
            'lists'        => $lists,
        ];
    }

    /** Ids whose posters may be served publicly (shown on the page). */
    public function posterIds(array $profile): array
    {
        $ids = array_column($profile['top'], 'id');
        foreach ($profile['lists'] as $l) {
            $ids = array_merge($ids, array_column($l['items'], 'id'));
        }
        return array_map('intval', array_unique($ids));
    }

    /** Only the fields safe to publish (never notes, never internal ids beyond the media id). */
    private function publicItem(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'title'        => $r['title'],
            'year'         => $r['release_year'] ?: $r['year'],
            'category'     => $r['categories'] ?: 'Unknown',
            'status'       => $r['status'],
            'my_score'     => (float) $r['score'] > 0 ? round((float) $r['score'], 1) : null,
            'public_score' => $r['public_score'] !== null ? (float) $r['public_score'] : null,
            'has_poster'   => (string) $r['image'] !== '',
        ];
    }

    public function posterFile(int $id, string $mediaDir): ?string
    {
        $stmt = $this->pdo->prepare('SELECT image FROM media WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $image = (string) $stmt->fetchColumn();
        $path = $mediaDir . '/' . basename($image);
        return $image !== '' && is_file($path) ? $path : null;
    }
}
