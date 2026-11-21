<?php

namespace App\Repository;

use PDO;

/** Categories and age ratings by name (get-or-create, like the legacy importer). */
final class LookupRepository
{
    private array $cache = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function categoryId(?string $name, bool $create = true): ?int
    {
        return $this->resolve('categories', $name, $create);
    }

    public function ratingId(?string $name, bool $create = false): ?int
    {
        return $this->resolve('ratings', $name, $create);
    }

    public function categoryName(?int $id): ?string
    {
        if (!$id) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT name FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }

    private function resolve(string $table, ?string $name, bool $create): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $key = $table . ':' . mb_strtolower($name);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $stmt = $this->pdo->prepare("SELECT id FROM $table WHERE name = ?");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if (!$id && $create) {
            if ($table === 'ratings') {
                $this->pdo->prepare('INSERT INTO ratings (name, label) VALUES (?, ?)')->execute([$name, $name]);
            } else {
                $this->pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
            }
            $id = $this->pdo->lastInsertId();
        }
        return $this->cache[$key] = $id ? (int) $id : null;
    }
}
