<?php
// php bin/lint.php — runs `php -l` on every project PHP file (vendor/ and var/ excluded).
$root  = dirname(__DIR__);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn ($f) => !in_array($f->getFilename(), ['vendor', 'node_modules', 'var', '.git', 'medias'], true)
));
foreach ($it as $f) {
    if ($f->getExtension() === 'php' || $f->getFilename() === 'console') {
        $files[] = $f->getPathname();
    }
}
$failed = 0;
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failed++;
        echo implode("\n", $out), "\n";
    }
    $out = [];
}
echo count($files) . " files checked, $failed with errors\n";
exit($failed ? 1 : 0);
