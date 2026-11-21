<?php

namespace App\Repository;

use App\Text\Slugger;
use PDO;

final class GenreRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return int genre id (created if missing) */
    public function idFor(string $name): int
    {
        $name = trim($name);
        $slug = Slugger::slug($name);
        $this->pdo->prepare('INSERT IGNORE INTO genres (name, slug) VALUES (?, ?)')->execute([mb_substr($name, 0, 64), $slug]);
        $stmt = $this->pdo->prepare('SELECT id FROM genres WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn();
    }

    /** Replace a media item's genres with $names (empty names ignored). */
    public function sync(int $mediaId, array $names): void
    {
        $ids = [];
        foreach ($names as $n) {
            if (is_string($n) && trim($n) !== '') {
                $ids[] = $this->idFor($n);
            }
        }
        $ids = array_values(array_unique($ids));
        $this->pdo->prepare('DELETE FROM media_genre WHERE media_id = ?')->execute([$mediaId]);
        $ins = $this->pdo->prepare('INSERT IGNORE INTO media_genre (media_id, genre_id) VALUES (?, ?)');
        foreach ($ids as $id) {
            $ins->execute([$mediaId, $id]);
        }
    }

    /** @return array<int, string[]> media id => genre names */
    public function namesFor(array $mediaIds): array
    {
        $mediaIds = array_values(array_filter(array_map('intval', $mediaIds)));
        if (!$mediaIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($mediaIds), '?'));
        $stmt = $this->pdo->prepare("SELECT mg.media_id, g.name FROM media_genre mg JOIN genres g ON g.id = mg.genre_id WHERE mg.media_id IN ($ph) ORDER BY g.name");
        $stmt->execute($mediaIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['media_id']][] = $r['name'];
        }
        return $out;
    }

    public function all(): array
    {
        return $this->pdo->query(
            'SELECT g.id, g.name, g.slug, COUNT(mg.media_id) AS media_count
             FROM genres g LEFT JOIN media_genre mg ON mg.genre_id = g.id
             GROUP BY g.id ORDER BY g.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
