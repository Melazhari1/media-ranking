<?php
// /u/{username} — opt-in, read-only public profile (server-rendered, no app JS, no API access).
// ?poster=ID serves a poster only if that item is shown on the profile.

require_once __DIR__ . '/bootstrap.php';

use App\PublicProfile\PublicProfileService;
use App\Services;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$username = (string) ($_GET['u'] ?? '');

$notFound = static function () {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not found</title>'
        . '<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f6f7fb;color:#10131f}'
        . '@media (prefers-color-scheme:dark){body{background:#0a0d15;color:#eef1fa}}</style><p>This profile doesn’t exist or isn’t public.</p>';
    exit;
};

if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
    $notFound();
}

try {
    $s = new Services(null, __DIR__);
    $service = new PublicProfileService($s->pdo(), $s->media(), $s->settings(), $s->lists());
    $profile = $service->build($username);
} catch (Throwable $e) {
    error_log('[MMR public] ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
if (!$profile) {
    $notFound();
}

// Poster proxy: only for items visible on this profile.
if (isset($_GET['poster'])) {
    $id = (int) $_GET['poster'];
    $file = in_array($id, $service->posterIds($profile), true) ? $service->posterFile($id, $s->mediaDir()) : null;
    if (!$file) {
        http_response_code(404);
        exit;
    }
    $type = @getimagesize($file)['mime'] ?? 'image/jpeg';
    header('Content-Type: ' . (in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $type : 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$self = $origin . $base . 'u/' . rawurlencode($profile['username']);
$poster = fn ($item) => $item['has_poster'] ? $base . 'public.php?u=' . rawurlencode($profile['username']) . '&poster=' . $item['id'] : null;
$name = $profile['display_name'];
$st = $profile['stats'];
$desc = sprintf('%s’s media list: %s titles, %s finished%s.', $name, number_format($st['in_list']), number_format($st['by_group']['done'] ?? 0),
    $st['my_avg'] !== null ? ', average rating ' . number_format($st['my_avg'], 1) : '');
$labels = ['plan' => 'Planned', 'active' => 'In progress', 'done' => 'Finished', 'on_hold' => 'On hold', 'dropped' => 'Dropped', 'rewatching' => 'Rewatching'];

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $h($name) ?> · MMR</title>
<meta name="description" content="<?= $h($desc) ?>">
<link rel="canonical" href="<?= $h($self) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= $h($name) ?> · My Media Ranking">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:url" content="<?= $h($self) ?>">
<meta property="og:image" content="<?= $h($self) ?>/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<style>
:root{--bg:#f6f7fb;--panel:#fff;--text:#10131f;--muted:#5b6474;--border:#e2e6f0;--accent:#0891b2}
@media (prefers-color-scheme:dark){:root{--bg:#0a0d15;--panel:#171c28;--text:#eef1fa;--muted:#8991a8;--border:#2b3346;--accent:#3ee6ff}}
*{box-sizing:border-box}body{margin:0;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
.wrap{max-width:1100px;margin:0 auto;padding:32px 16px 64px}
header{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:24px}h1{margin:0;font-size:2rem}.brand{color:var(--accent);font-weight:800;letter-spacing:.05em}
.muted{color:var(--muted)}.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:32px}
.tile{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}.tile b{display:block;font-size:1.6rem}
h2{font-size:1.2rem;margin:32px 0 12px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:16px;list-style:none;padding:0;margin:0}
.item{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden}.item img,.ph{width:100%;aspect-ratio:2/3;object-fit:cover;display:block;background:var(--border)}
.item div{padding:8px 10px}.item strong{display:block;font-size:.9rem;line-height:1.3;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.item small{color:var(--muted);font-size:.78rem}.rank{position:absolute;margin:8px;background:rgba(0,0,0,.7);color:#fff;border-radius:6px;padding:0 8px;font-weight:700}
.note{font-size:.8rem;color:var(--muted);font-style:italic;margin-top:4px}.rel{position:relative}footer{margin-top:48px;font-size:.8rem}
</style>
</head>
<body>
<div class="wrap">
<header><span class="brand">MMR</span><h1><?= $h($name) ?></h1><span class="muted">@<?= $h($profile['username']) ?></span></header>

<div class="tiles">
  <div class="tile"><b><?= number_format($st['in_list']) ?></b><span class="muted">titles in the list</span></div>
  <div class="tile"><b><?= number_format($st['finished_year']) ?></b><span class="muted">finished in <?= date('Y') ?></span></div>
  <div class="tile"><b><?= $st['my_avg'] !== null ? number_format($st['my_avg'], 1) . ' ★' : '—' ?></b><span class="muted">average rating</span></div>
  <?php foreach ($labels as $key => $label): if (!empty($st['by_group'][$key])): ?>
  <div class="tile"><b><?= number_format($st['by_group'][$key]) ?></b><span class="muted"><?= $h($label) ?></span></div>
  <?php endif; endforeach; ?>
</div>

<?php if ($profile['top']): ?>
<h2>Top 10</h2>
<ol class="grid">
<?php foreach ($profile['top'] as $i => $item): ?>
  <li class="item rel"><span class="rank">#<?= $i + 1 ?></span>
    <?php if ($p = $poster($item)): ?><img src="<?= $h($p) ?>" alt="" loading="lazy"><?php else: ?><span class="ph"></span><?php endif; ?>
    <div><strong><?= $h($item['title']) ?></strong><small><?= $h($item['category']) ?> · <?= $h($item['year']) ?> · ★ <?= $h($item['my_score']) ?></small>
    <?php if (!empty($item['notes'])): ?><p class="note"><?= $h(mb_strimwidth($item['notes'], 0, 140, '…')) ?></p><?php endif; ?></div>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>

<?php foreach ($profile['lists'] as $list): if (!$list['items']) continue; ?>
<h2><?= $h($list['name']) ?> <span class="muted">· <?= (int) $list['count'] ?></span></h2>
<?php if ($list['description']): ?><p class="muted"><?= $h($list['description']) ?></p><?php endif; ?>
<ul class="grid">
<?php foreach ($list['items'] as $item): ?>
  <li class="item">
    <?php if ($p = $poster($item)): ?><img src="<?= $h($p) ?>" alt="" loading="lazy"><?php else: ?><span class="ph"></span><?php endif; ?>
    <div><strong><?= $h($item['title']) ?></strong><small><?= $h($item['category']) ?> · <?= $h($item['year']) ?></small></div>
  </li>
<?php endforeach; ?>
</ul>
<?php endforeach; ?>

<footer class="muted">Shared from My Media Ranking · read-only</footer>
</div>
</body>
</html>
