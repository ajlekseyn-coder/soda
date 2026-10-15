<?php

declare(strict_types=1);

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$root = realpath($argv[1]);
$composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$roots = [];
foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
    foreach ($composer['autoload'][$kind] ?? [] as $paths) {
        foreach ((array) $paths as $path) {
            $roots[] = $root.'/'.ltrim($path, '/');
        }
    }
}
foreach ($composer['bin'] ?? [] as $path) {
    $contents = file_get_contents($root.'/'.$path) ?: '';
    if (str_contains($contents, '<?php') && ! str_contains($contents, '__HALT_COMPILER')) {
        $roots[] = $root.'/'.$path;
    }
}
if (! $roots) {
    $roots[] = $root;
}
$files = [];
foreach ($roots as $path) {
    $entries = is_dir($path) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) : [$path];
    foreach ($entries as $entry) {
        $file = realpath((string) $entry);
        if (! $file || ! is_file($file)) {
            continue;
        }
        $relative = substr($file, strlen($root) + 1);
        if (preg_match('~(^|/)(tests?|vendor|node_modules|resources|stubs|\.github)(/|$)~i', $relative) || str_ends_with($file, '.blade.php')) {
            continue;
        }
        if (str_ends_with($file, '.php') || in_array($relative, $composer['bin'] ?? [], true)) {
            $files[$file] = hash_file('sha256', $file);
        }
    }
}
ksort($files);
$started = microtime(true);
$result = (new Runner)->check(array_keys($files), RuleCatalog::standard());
$violations = [];
foreach ($result->violations as $violation) {
    $row = $violation->toArray();
    $row['file'] = str_starts_with($row['file'], $root.'/') ? substr($row['file'], strlen($root) + 1) : $row['file'];
    if ($row['message'] !== null) {
        $row['message'] = str_replace($root.'/', '', $row['message']);
    }
    $violations[] = $row;
}
$hashes = [];
foreach ($files as $file => $sha) {
    $hashes[substr($file, strlen($root) + 1)] = $sha;
}
$report = ['files' => $hashes, 'seconds' => microtime(true) - $started, 'peak_bytes' => memory_get_peak_usage(true), 'violations' => $violations];
file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo basename($root).': '.count($files).' files, '.count($violations).' diagnostics'.PHP_EOL;
