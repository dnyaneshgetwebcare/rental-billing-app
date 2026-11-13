<?php

/**
 * Builds the GoDaddy upload archives inside the php82 container (vendor/ lives in a Docker volume).
 * Fed through stdin by scripts/release.ps1:
 *   php -- backend /var/www/billing-api /var/www/billing-api/runtime/release/billing-api.zip
 *   php -- public  /var/www/html        /var/www/billing-api/runtime/release/public_html.zip
 */

[, $mode, $source, $zipPath] = $argv + [null, null, null, null];
if (!in_array($mode, ['backend', 'public'], true) || !is_dir((string) $source) || $zipPath === null) {
    fwrite(STDERR, "usage: php -- backend|public <source> <zip>\n");
    exit(2);
}
@mkdir(dirname($zipPath), 0777, true);

if ($mode === 'backend') {
    $build = sys_get_temp_dir() . '/billing-api-release';
    passthru('rm -rf ' . escapeshellarg($build));
    mkdir($build, 0777, true);
    foreach (['config', 'migrations', 'src', 'resources'] as $dir) {
        copyTree("$source/$dir", "$build/$dir");
    }
    foreach (['yii', 'composer.json', 'composer.lock', '.env.example'] as $file) {
        copy("$source/$file", "$build/$file");
    }
    mkdir("$build/runtime", 0777, true);
    file_put_contents("$build/runtime/.gitignore", "*\n!.gitignore\n");

    $cmd = 'cd ' . escapeshellarg($build) . ' && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress 2>&1';
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "composer install failed\n");
        exit(1);
    }
    zipTree($build, $zipPath, static fn(string $rel): bool => true);
    passthru('rm -rf ' . escapeshellarg($build));
} else {
    zipTree($source, $zipPath, static function (string $rel): bool {
        if (str_starts_with($rel, 'uploads/')) {
            return $rel === 'uploads/.htaccess';
        }
        return !str_ends_with($rel, '.php') || $rel === 'api/index.php';
    });
}

printf("%s  %.1f MB\n", basename($zipPath), filesize($zipPath) / 1048576);

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
    }
}

function zipTree(string $root, string $zipPath, callable $include): void
{
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Cannot create $zipPath");
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($file->isFile() && $include($rel)) {
            $zip->addFile($file->getPathname(), $rel);
        }
    }
    $zip->close();
}
