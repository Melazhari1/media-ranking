<?php
// bootstrap.php — shared entry for api.php, bin/console and tests.
// Loads Composer's autoloader and, when present, .env / .env.local.
// Real environment variables always win over .env values.

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(function () {
    $dotenv = new Dotenv();
    foreach (['.env', '.env.local'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path)) {
            $dotenv->load($path);
        }
    }
})();

// Legacy credentials file; still supported as a fallback for DB_* values.
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
