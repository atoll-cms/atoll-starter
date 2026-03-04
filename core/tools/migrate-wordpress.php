#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Atoll\Support\Yaml;

$options = parseOptions($argv);
$wxr = $options['wxr'] ?? null;
$output = $options['output'] ?? dirname(__DIR__, 3) . '/content/blog';
$type = strtolower((string) ($options['type'] ?? 'post'));

if (!is_string($wxr) || $wxr === '') {
    fail("Usage: php core/tools/migrate-wordpress.php --wxr=/path/export.xml [--output=/path/content/blog] [--type=post|page|all]");
}

if (!is_file($wxr)) {
    fail('WXR file not found: ' . $wxr);
}

if (!is_dir($output)) {
    mkdir($output, 0775, true);
}

if (!is_file($output . '/_collection.yaml')) {
    file_put_contents($output . '/_collection.yaml', Yaml::dump([
        'name' => basename($output),
        'slug_from' => 'filename',
        'sort' => 'date desc',
        'per_page' => 20,
    ]));
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($wxr, 'SimpleXMLElement', LIBXML_NOCDATA);
if (!$xml instanceof SimpleXMLElement) {
    fail('Could not parse WXR XML.');
}

$ns = $xml->getNamespaces(true);
$wpNs = $ns['wp'] ?? null;
$contentNs = $ns['content'] ?? null;
$excerptNs = $ns['excerpt'] ?? null;

if ($wpNs === null) {
    fail('WXR appears invalid: wp namespace missing.');
}

$count = 0;
foreach ($xml->channel->item as $item) {
    $wp = $item->children($wpNs);
    $content = $contentNs ? $item->children($contentNs) : null;
    $excerpt = $excerptNs ? $item->children($excerptNs) : null;

    $postType = strtolower((string) ($wp->post_type ?? ''));
    if ($type !== 'all' && $postType !== $type) {
        continue;
    }
    if (!in_array($postType, ['post', 'page'], true) && $type !== 'all') {
        continue;
    }

    $status = strtolower((string) ($wp->status ?? 'publish'));
    if (!in_array($status, ['publish', 'draft', 'pending', 'future'], true)) {
        continue;
    }

    $title = trim((string) ($item->title ?? 'Untitled'));
    $slugRaw = trim((string) ($wp->post_name ?? ''));
    $slug = $slugRaw !== '' ? sanitizeSlug($slugRaw) : sanitizeSlug($title);
    if ($slug === '') {
        $slug = 'entry-' . ((string) ($wp->post_id ?? uniqid()));
    }

    $dateRaw = trim((string) ($wp->post_date ?? ''));
    $date = date('Y-m-d', strtotime($dateRaw) ?: time());
    $prefix = substr($date, 0, 7);
    $baseName = $prefix . '-' . $slug;
    $filename = uniqueFilename($output, $baseName);

    $author = trim((string) ($wp->post_author ?? 'admin'));
    $tags = [];
    foreach ($item->category as $category) {
        $attrs = $category->attributes();
        $domain = (string) ($attrs['domain'] ?? '');
        if ($domain === 'post_tag') {
            $tags[] = trim((string) $category);
        }
    }
    $tags = array_values(array_unique(array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''))));

    $excerptText = trim((string) ($excerpt?->encoded ?? ''));
    $body = trim((string) ($content?->encoded ?? ''));
    if ($body === '') {
        $body = "_Imported from WordPress. Original content was empty._";
    }

    $frontmatter = [
        'title' => $title,
        'date' => $date,
        'author' => $author !== '' ? $author : 'admin',
        'tags' => $tags,
        'excerpt' => $excerptText,
        'draft' => $status !== 'publish',
        'source' => [
            'wordpress' => [
                'post_id' => (string) ($wp->post_id ?? ''),
                'post_type' => $postType,
                'status' => $status,
                'link' => trim((string) ($item->link ?? '')),
            ],
        ],
    ];

    $markdown = "---\n"
        . Yaml::dump($frontmatter)
        . "---\n\n"
        . $body
        . "\n";

    file_put_contents($output . '/' . $filename . '.md', $markdown);
    $count++;
}

fwrite(STDOUT, "WordPress migration complete. Imported {$count} entries into {$output}\n");

/**
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $idx => $arg) {
        if ($idx === 0) {
            continue;
        }
        if (!str_starts_with((string) $arg, '--')) {
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
