<?php
// App shell for every client route (/, /media/12-slug, /stats, /diary, /lists).
// Server-side it only sets <base href> and per-route <title>/meta/Open Graph tags,
// because crawlers and link previews don't run JavaScript.

require_once __DIR__ . '/bootstrap.php';

use App\Services;

const ASSET_VERSION = '2.1.0'; // keep in sync with the ?v= used by the JS imports

$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$path = '/' . ltrim(substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen($base)), '/');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$title = 'MMR - My Media Ranking';
$description = 'My personal watchlist: anime, manga, movies and series — ratings, progress and stats.';
$image = null;
$type = 'website';

$pages = ['/stats' => 'Stats', '/diary' => 'Diary', '/lists' => 'Lists'];
if (isset($pages[$path])) {
    $title = $pages[$path] . ' · MMR';
} elseif (preg_match('#^/media/(\d+)#', $path, $m)) {
    try {
        $item = (new Services(null, __DIR__))->media()->getMediaById((int) $m[1]);
        if ($item) {
            $title = $item['title'] . ' · MMR';
            $summary = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($item['synopsis'] ?? ''))));
            $description = mb_strimwidth($summary !== '' ? $summary : trim(($item['categories'] ?? 'Media') . ' · ' . $item['year']), 0, 200, '…');
            $adult = in_array($item['ratings'], MediaManager::ADULT_RATINGS, true);
            if ($item['image'] && !$adult) {
                $image = $origin . $base . 'medias/' . rawurlencode($item['image']);
            }
            $type = 'video.other';
        } else {
            http_response_code(404);
        }
    } catch (Throwable $e) {
        error_log('[MMR index] ' . $e->getMessage());
    }
}
$canonical = $origin . $base . ltrim($path, '/');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= $h($base) ?>">
    <title><?= $h($title) ?></title>
    <meta name="description" content="<?= $h($description) ?>">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?= $h($canonical) ?>">
    <meta property="og:type" content="<?= $h($type) ?>">
    <meta property="og:title" content="<?= $h($title) ?>">
    <meta property="og:description" content="<?= $h($description) ?>">
    <meta property="og:url" content="<?= $h($canonical) ?>">
    <?php if ($image): ?><meta property="og:image" content="<?= $h($image) ?>">
    <meta name="twitter:card" content="summary_large_image"><?php endif; ?>
    <meta name="theme-color" content="#0a0d15" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f6f7fb" media="(prefers-color-scheme: light)">
    <script>
        // Apply the saved theme before first paint (no flash). Falls back to the OS preference.
        try {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (t === null && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark-theme');
                if (t === null) localStorage.setItem('theme', 'dark');
            }
        } catch (e) { /* storage blocked */ }
    </script>
    <link rel="stylesheet" href="styles.css?v=<?= ASSET_VERSION ?>">
    <script src="https://unpkg.com/vue@3.5.13/dist/vue.global.prod.js"></script>
    <script src="https://unpkg.com/vue-router@4.5.0/dist/vue-router.global.prod.js"></script>
</head>
<body>
    <div id="app" v-cloak></div>
    <noscript><p style="padding:2rem">MMR needs JavaScript enabled.</p></noscript>
    <script type="module" src="app.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
