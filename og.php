<?php
// /u/{username}/og.png — Open Graph image for the public profile (cached 1 h per content hash).

require_once __DIR__ . '/bootstrap.php';

use App\PublicProfile\OgImage;
use App\PublicProfile\PublicProfileService;
use App\Services;

$username = (string) ($_GET['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
    http_response_code(404);
    exit;
}

try {
    $s = new Services(null, __DIR__);
    $service = new PublicProfileService($s->pdo(), $s->media(), $s->settings(), $s->lists());
    $profile = $service->build($username);
    if (!$profile) {
        http_response_code(404);
        exit;
    }

    $posters = [];
    foreach ($profile['top'] as $item) {
        if ($item['has_poster'] && ($f = $service->posterFile($item['id'], $s->mediaDir()))) {
            $posters[] = $f;
        }
    }

    $hash = sha1(json_encode([$profile['display_name'], $profile['stats'], $posters]));
    $dir = $s->varDir() . '/cache/og';
    $file = "$dir/$hash.png";
    if (!is_file($file) || filemtime($file) < time() - 3600) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach (glob("$dir/*.png") ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        file_put_contents($file, (new OgImage())->render($profile['display_name'], $profile['stats'], $posters));
    }

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
} catch (Throwable $e) {
    error_log('[MMR og] ' . $e->getMessage());
    http_response_code(500);
}
