<?php

class Database
{
    /**
     * DB settings: environment / .env first (DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET),
     * then the constants from config.php.
     */
    public static function config(): array
    {
        $get = static function (string $key, $default = null) {
            $env = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($env !== false && $env !== null && $env !== '') {
                return $env;
            }
            return defined($key) ? constant($key) : $default;
        };

        return [
            'host'    => $get('DB_HOST', 'localhost'),
            'port'    => (int) $get('DB_PORT', 3306),
            'dbname'  => $get('DB_NAME'),
            'user'    => $get('DB_USER', 'root'),
            'password'=> $get('DB_PASS', ''),
            'charset' => $get('DB_CHARSET', 'utf8mb4'),
        ];
    }

    public static function connect(): PDO
    {
        if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class, false) && is_file(__DIR__ . '/bootstrap.php')) {
            require_once __DIR__ . '/bootstrap.php';
        }
        $c   = self::config();
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['dbname']};charset={$c['charset']}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            return new PDO($dsn, $c['user'], $c['password'], $options);
        } catch (\PDOException $e) {
            if (PHP_SAPI === 'cli') {
                throw $e;
            }
            http_response_code(500);
            die(json_encode(['status' => 'error', 'message' => 'Database connection error']));
        }
    }
}
