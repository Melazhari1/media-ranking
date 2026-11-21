<?php
// Test bootstrap: (re)creates the *_test database from the baseline schema and runs all migrations.
// Set SKIP_DB_SETUP=1 to run unit tests without MySQL.

require dirname(__DIR__) . '/bootstrap.php';

use App\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

if (getenv('SKIP_DB_SETUP')) {
    return;
}

$config = Database::config();
if (!preg_match('/_test$/', (string) $config['dbname'])) {
    fwrite(STDERR, "Refusing to run: DB_NAME '{$config['dbname']}' does not end with _test.\n");
    exit(1);
}

$server = new PDO(
    "mysql:host={$config['host']};port={$config['port']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db = '`' . str_replace('`', '', $config['dbname']) . '`';
$server->exec("DROP DATABASE IF EXISTS $db");
$server->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$server->exec("USE $db");
$server->exec(file_get_contents(__DIR__ . '/fixtures/baseline-schema.sql'));

$app = new Application();
$app->setAutoExit(false);
$out = new BufferedOutput();
$code = $app->run(new ArrayInput(['command' => 'migrations:migrate', '--no-interaction' => true]), $out);
if ($code !== 0) {
    fwrite(STDERR, "Migrations failed:\n" . $out->fetch());
    exit(1);
}
