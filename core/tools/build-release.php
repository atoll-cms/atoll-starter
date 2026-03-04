#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$corePath = dirname(__DIR__);
$projectRoot = $corePath;

if (!is_file($corePath . '/VERSION')) {
    $projectRoot = dirname(__DIR__, 2);
    $corePath = $projectRoot . '/core';
}

if (!is_file($corePath . '/VERSION')) {
    fwrite(STDERR, "Could not locate core VERSION file.\n");
    exit(1);
}

$versionFile = $corePath . '/VERSION';
$version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';

$outDir = $argv[1] ?? ($projectRoot . '/releases');
if (!str_starts_with($outDir, '/')) {
    $outDir = $projectRoot . '/' . ltrim($outDir, '/');
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

    $relativeCore = substr($real, strlen($corePath) + 1);
    if ($relativeCore === '' || str_starts_with($relativeCore, '.git') || str_starts_with($relativeCore, 'releases/')) {
        continue;
    }

    $relative = 'core/' . $relativeCore;
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
