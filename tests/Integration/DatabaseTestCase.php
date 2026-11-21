<?php

namespace App\Tests\Integration;

use Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $pdo = null;
    protected string $varDir;

    protected function setUp(): void
    {
        self::$pdo ??= Database::connect();
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['media', 'categories', 'ratings'] as $table) {
            self::$pdo->exec("TRUNCATE TABLE $table");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        self::$pdo->exec("INSERT INTO categories (id, name) VALUES (1, 'TV'), (2, 'Movie'), (3, 'Unknown')");
        self::$pdo->exec("INSERT INTO ratings (id, name, label) VALUES (1, 'PG-13', 'Teens'), (2, 'Rx', 'Hentai')");

        $this->varDir = sys_get_temp_dir() . '/mmr-test-' . bin2hex(random_bytes(4));
        mkdir($this->varDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->varDir);
    }

    /** Insert a row directly (bypassing MediaManager) to simulate legacy data. */
    protected function insertRaw(array $row): int
    {
        $row += ['image' => 'x.jpg', 'year' => '2020-Q1', 'score' => 0, 'score_mal' => 0, 'category_id' => 1];
        $cols = implode(', ', array_keys($row));
        $ph   = implode(', ', array_fill(0, count($row), '?'));
        self::$pdo->prepare("INSERT INTO media ($cols) VALUES ($ph)")->execute(array_values($row));
        return (int) self::$pdo->lastInsertId();
    }

    protected function fetchRow(int $id): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    protected function pdoFactory(): \Closure
    {
        return fn () => self::$pdo;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
