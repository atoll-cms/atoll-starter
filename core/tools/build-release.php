#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$corePath = $root . '/core';
$versionFile = $corePath . '/VERSION';
$version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';

$outDir = $argv[1] ?? ($root . '/releases');
if (!str_starts_with($outDir, '/')) {
    $outDir = $root . '/' . ltrim($outDir, '/');
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$zipPath = rtrim($outDir, '/') . '/atoll-core-' . $version . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create release archive: {$zipPath}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($corePath, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $real = $item->getRealPath();
    if ($real === false) {
        continue;
    }

    $relative = 'core/' . substr($real, strlen($corePath) + 1);
    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
    } else {
        $zip->addFile($real, $relative);
    }
}

$zip->close();
$sha = hash_file('sha256', $zipPath);

echo "Release archive: {$zipPath}\n";
echo "Version: {$version}\n";
echo "SHA256: {$sha}\n";
