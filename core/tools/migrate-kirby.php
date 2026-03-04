#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Atoll\Support\Yaml;

$options = parseOptions($argv);
$source = $options['source'] ?? null;
$outputRoot = $options['output'] ?? dirname(__DIR__, 3) . '/content';

if (!is_string($source) || $source === '') {
    fail('Usage: php core/tools/migrate-kirby.php --source=/path/to/kirby/content [--output=/path/to/atoll/content]');
}

if (!is_dir($source)) {
    fail('Kirby source directory not found: ' . $source);
}

if (!is_dir($outputRoot)) {
    mkdir($outputRoot, 0775, true);
}

$files = scanKirbyTextFiles($source);
$imported = 0;

foreach ($files as $file) {
    $relative = ltrim(substr($file, strlen(rtrim($source, '/'))), '/');
    $segments = explode('/', $relative);
    $collection = 'pages';
    if (count($segments) > 1) {
        $collection = sanitizeSlug($segments[0]);
        if ($collection === '') {
            $collection = 'pages';
        }
    }

    $targetCollection = $outputRoot . '/' . $collection;
    if (!is_dir($targetCollection)) {
        mkdir($targetCollection, 0775, true);
    }

    $data = parseKirbyContent((string) file_get_contents($file));
    $title = trim((string) ($data['title'] ?? $data['Title'] ?? basename($file, '.txt')));
    $text = (string) ($data['text'] ?? $data['Text'] ?? $data['body'] ?? $data['Body'] ?? '');
    $dateRaw = (string) ($data['date'] ?? $data['Date'] ?? '');
    $date = $dateRaw !== '' ? date('Y-m-d', strtotime($dateRaw) ?: time()) : date('Y-m-d');

    $slug = sanitizeSlug(pathinfo($file, PATHINFO_FILENAME));
    if ($slug === 'default') {
        $slug = 'index';
    }
    if ($slug === '') {
        $slug = 'entry-' . substr(sha1($relative), 0, 8);
    }

    $frontmatter = [
        'title' => $title,
        'date' => $date,
        'draft' => false,
        'source' => [
            'kirby' => [
                'file' => $relative,
            ],
        ],
    ];

    if (isset($data['tags']) || isset($data['Tags'])) {
        $tagsRaw = (string) ($data['tags'] ?? $data['Tags']);
        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw)), static fn (string $v): bool => $v !== ''));
        if ($tags !== []) {
            $frontmatter['tags'] = $tags;
        }
    }

    $targetPath = $targetCollection . '/' . uniqueFilename($targetCollection, $slug) . '.md';
    $markdown = "---\n" . Yaml::dump($frontmatter) . "---\n\n" . trim($text) . "\n";
    file_put_contents($targetPath, $markdown);
    $imported++;
}

fwrite(STDOUT, "Kirby migration complete. Imported {$imported} entries into {$outputRoot}\n");

/**
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $idx => $arg) {
        if ($idx === 0 || !str_starts_with((string) $arg, '--')) {
            continue;
        }
        $raw = substr((string) $arg, 2);
        [$key, $value] = array_pad(explode('=', $raw, 2), 2, '1');
        $options[$key] = $value;
    }

    return $options;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * @return array<int, string>
 */
function scanKirbyTextFiles(string $source): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if (strtolower($item->getExtension()) !== 'txt') {
            continue;
        }
        $files[] = $item->getPathname();
    }

    sort($files);
    return $files;
}

/**
 * Kirby text parser for simple key/value format separated by "----".
 *
 * @return array<string, string>
 */
function parseKirbyContent(string $raw): array
{
    $result = [];
    $parts = preg_split('/\R----\R/', trim($raw)) ?: [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/s', $part, $m) !== 1) {
            continue;
        }
        $key = trim($m[1]);
        $value = trim($m[2]);
        $result[$key] = $value;
    }

    return $result;
}

function sanitizeSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function uniqueFilename(string $dir, string $base): string
{
    $candidate = $base;
    $i = 2;
    while (is_file($dir . '/' . $candidate . '.md')) {
        $candidate = $base . '-' . $i;
        $i++;
    }

    return $candidate;
}
