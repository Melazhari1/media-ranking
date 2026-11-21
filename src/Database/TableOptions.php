<?php

namespace App\Database;

use Doctrine\DBAL\Connection;

/**
 * Table options for new tables created by migrations: same charset + collation as the existing
 * `media` table, so joins never hit "Illegal mix of collations" and the SQL runs unchanged on
 * MySQL 8+ (utf8mb4_0900_ai_ci) and MariaDB 10.x/11.x (utf8mb4_unicode_ci, uca1400…).
 */
final class TableOptions
{
    public static function sql(Connection $connection): string
    {
        $collation = (string) $connection->fetchOne(
            "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media'"
        );
        if (!preg_match('/^(utf8mb4)_[a-z0-9_]+$/', $collation, $m)) {
            $collation = 'utf8mb4_unicode_ci'; // supported everywhere
        }
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $collation;
    }
}
