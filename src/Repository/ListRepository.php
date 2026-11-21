<?php

namespace App\Repository;

use App\Text\Slugger;
use InvalidArgumentException;
use PDO;

/** Custom user lists with an ordered set of media. */
final class ListRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(bool $publicOnly = false): array
    {
        $rows = $this->pdo->query(
            'SELECT l.id, l.name, l.slug, l.description, l.is_public, l.position, l.created_at, l.updated_at,
                    COUNT(m.id) AS media_count
             FROM user_lists l
             LEFT JOIN user_list_media ulm ON ulm.list_id = l.id
             LEFT JOIN media m ON m.id = ulm.media_id AND m.deleted_at IS NULL
             ' . ($publicOnly ? 'WHERE l.is_public = 1' : '') . '
             GROUP BY l.id ORDER BY l.position, l.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => ['is_public' => (bool) $r['is_public'], 'media_count' => (int) $r['media_count']] + $r, $rows);
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $l) {
            if ((int) $l['id'] === $id) {
                return $l;
            }
        }
        return null;
    }

    public function create(string $name, ?string $description = null, bool $isPublic = false): array
    {
        $name = self::cleanName($name);
        $position = (int) $this->pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM user_lists')->fetchColumn();
        $this->pdo->prepare('INSERT INTO user_lists (name, slug, description, is_public, position) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $this->uniqueSlug($name), $description ?: null, (int) $isPublic, $position]);
        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $fields): ?array
    {
        $set = [];
        if (array_key_exists('name', $fields)) {
            $set['name'] = self::cleanName((string) $fields['name']);
            $set['slug'] = $this->uniqueSlug($set['name'], $id);
        }
        if (array_key_exists('description', $fields)) {
            $set['description'] = trim((string) $fields['description']) ?: null;
        }
        if (array_key_exists('is_public', $fields)) {
            $set['is_public'] = filter_var($fields['is_public'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($set) {
            $cols = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($set)));
            $this->pdo->prepare("UPDATE user_lists SET $cols WHERE id = :id")->execute($set + ['id' => $id]);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_lists WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** @param int[] $orderedIds list ids in their new order */
    public function reorder(array $orderedIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_lists SET position = ? WHERE id = ?');
        foreach (array_values($orderedIds) as $pos => $id) {
            $stmt->execute([$pos, (int) $id]);
        }
    }

    /** Append media to the end of a list (already-present items are kept where they are). */
    public function addItems(int $listId, array $mediaIds): int
    {
        $next = (int) $this->pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM user_list_media WHERE list_id = ' . $listId)->fetchColumn();
        $ins = $this->pdo->prepare('INSERT IGNORE INTO user_list_media (list_id, media_id, position) VALUES (?, ?, ?)');
        $added = 0;
        foreach (array_unique(array_map('intval', $mediaIds)) as $m) {
            $ins->execute([$listId, $m, $next]);
            if ($ins->rowCount()) {
                $added++;
                $next++;
            }
        }
        return $added;
    }

    public function removeItem(int $listId, int $mediaId): void
    {
        $this->pdo->prepare('DELETE FROM user_list_media WHERE list_id = ? AND media_id = ?')->execute([$listId, $mediaId]);
    }

    /** @param int[] $orderedMediaIds media ids in their new order */
    public function reorderItems(int $listId, array $orderedMediaIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_list_media SET position = ? WHERE list_id = ? AND media_id = ?');
        foreach (array_values($orderedMediaIds) as $pos => $m) {
            $stmt->execute([$pos, $listId, (int) $m]);
        }
    }

    /** @return array<int, array<array{id:int,name:string}>> media id => lists it belongs to */
    public function forMedia(array $mediaIds): array
    {
        $mediaIds = array_values(array_filter(array_map('intval', $mediaIds)));
        if (!$mediaIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($mediaIds), '?'));
        $stmt = $this->pdo->prepare("SELECT ulm.media_id, l.id, l.name, l.slug, l.is_public FROM user_list_media ulm JOIN user_lists l ON l.id = ulm.list_id WHERE ulm.media_id IN ($ph) ORDER BY l.position");
        $stmt->execute($mediaIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['media_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'is_public' => (bool) $r['is_public']];
        }
        return $out;
    }

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('List names must be 1–100 characters');
        }
        return $name;
    }

    private function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = Slugger::slug($name, 90);
        $slug = $base;
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM user_lists WHERE slug = ? AND id <> ?');
        for ($i = 2; ; $i++) {
            $check->execute([$slug, $exceptId ?? 0]);
            if (!$check->fetchColumn()) {
                return $slug;
            }
            $slug = "$base-$i";
        }
    }
}
