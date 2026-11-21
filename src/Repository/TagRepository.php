<?php

namespace App\Repository;

use App\Text\Slugger;
use InvalidArgumentException;
use PDO;

final class TagRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('Tag names must be 1–64 characters');
        }
        return $name;
    }

    public function idFor(string $name): int
    {
        $name = self::cleanName($name);
        $slug = Slugger::slug($name);
        $this->pdo->prepare('INSERT IGNORE INTO tags (name, slug) VALUES (?, ?)')->execute([$name, $slug]);
        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn();
    }

    /** Autocomplete: tags whose name contains $q, most used first. */
    public function search(string $q = '', int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, COUNT(mt.media_id) AS media_count
             FROM tags t LEFT JOIN media_tag mt ON mt.tag_id = t.id
             WHERE t.name LIKE ? GROUP BY t.id ORDER BY media_count DESC, t.name LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['%' . $q . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Replace the tags of one media item. @return array tags now attached */
    public function setForMedia(int $mediaId, array $names): array
    {
        $ids = [];
        foreach ($names as $n) {
            if (is_string($n) && trim($n) !== '') {
                $ids[] = $this->idFor($n);
            }
        }
        $this->pdo->prepare('DELETE FROM media_tag WHERE media_id = ?')->execute([$mediaId]);
        $this->attach([$mediaId], array_unique($ids));
        return $this->forMedia([$mediaId])[$mediaId] ?? [];
    }

    /** Add tags (by name) to many media items without removing existing ones. */
    public function addToMedia(array $mediaIds, array $names): void
    {
        $ids = [];
        foreach ($names as $n) {
            if (is_string($n) && trim($n) !== '') {
                $ids[] = $this->idFor($n);
            }
        }
        $this->attach($mediaIds, array_unique($ids));
    }

    private function attach(array $mediaIds, array $tagIds): void
    {
        $ins = $this->pdo->prepare('INSERT IGNORE INTO media_tag (media_id, tag_id) VALUES (?, ?)');
        foreach ($mediaIds as $m) {
            foreach ($tagIds as $t) {
                $ins->execute([(int) $m, (int) $t]);
            }
        }
    }

    /** @return array<int, array<array{id:int,name:string,slug:string}>> */
    public function forMedia(array $mediaIds): array
    {
        $mediaIds = array_values(array_filter(array_map('intval', $mediaIds)));
        if (!$mediaIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($mediaIds), '?'));
        $stmt = $this->pdo->prepare("SELECT mt.media_id, t.id, t.name, t.slug FROM media_tag mt JOIN tags t ON t.id = mt.tag_id WHERE mt.media_id IN ($ph) ORDER BY t.name");
        $stmt->execute($mediaIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['media_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
        }
        return $out;
    }

    public function rename(int $id, string $name): void
    {
        $name = self::cleanName($name);
        $this->pdo->prepare('UPDATE tags SET name = ?, slug = ? WHERE id = ?')->execute([$name, Slugger::slug($name), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
    }
}
