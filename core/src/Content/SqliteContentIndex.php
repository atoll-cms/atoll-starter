<?php

declare(strict_types=1);

namespace Atoll\Content;

use Atoll\Support\Yaml;
use PDO;
use PDOException;

final class SqliteContentIndex
{
    private ?PDO $pdo = null;
    private bool $bootstrapped = false;

    /** @var array<string, array<string, mixed>> */
    private array $collectionMetaCache = [];

    public function __construct(
        private readonly string $contentRoot,
        private readonly string $databasePath
    ) {
    }

    public function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /**
     * @return array{enabled:bool,available:bool,path:string,entries:int,last_rebuild_at:string,last_update_at:string,reason:string}
     */
    public function status(): array
    {
        if (!$this->isAvailable()) {
            return [
                'enabled' => false,
                'available' => false,
                'path' => $this->databasePath,
                'entries' => 0,
                'last_rebuild_at' => '',
                'last_update_at' => '',
                'reason' => 'pdo_sqlite extension is not available.',
            ];
        }

        try {
            $pdo = $this->pdo();
            $entries = (int) ($pdo->query('SELECT COUNT(*) FROM content_entries')?->fetchColumn() ?: 0);
            return [
                'enabled' => true,
                'available' => true,
                'path' => $this->databasePath,
                'entries' => $entries,
                'last_rebuild_at' => (string) ($this->metaGet('last_rebuild_at') ?? ''),
                'last_update_at' => (string) ($this->metaGet('last_update_at') ?? ''),
                'reason' => '',
            ];
        } catch (PDOException $e) {
            return [
                'enabled' => false,
                'available' => true,
                'path' => $this->databasePath,
                'entries' => 0,
                'last_rebuild_at' => '',
                'last_update_at' => '',
                'reason' => 'sqlite error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{enabled:bool,indexed:int,updated:int,removed:int,path:string}
     */
    public function rebuild(): array
    {
        if (!$this->isAvailable()) {
            return [
                'enabled' => false,
                'indexed' => 0,
                'updated' => 0,
                'removed' => 0,
                'path' => $this->databasePath,
            ];
        }

        $pdo = $this->pdo();
        $indexed = 0;

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM content_entries');
            $collections = $this->collections();
            foreach ($collections as $collection) {
                foreach ($this->collectionFiles($collection) as $file) {
                    $record = $this->buildRecord($collection, $file);
                    if ($record === null) {
                        continue;
                    }
                    $this->insertRecord($record);
                    $indexed++;
                }
            }
            $this->metaSet('last_rebuild_at', gmdate('c'));
            $this->metaSet('last_update_at', gmdate('c'));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->bootstrapped = true;

        return [
            'enabled' => true,
            'indexed' => $indexed,
            'updated' => $indexed,
            'removed' => 0,
            'path' => $this->databasePath,
        ];
    }

    public function upsertFile(string $collection, string $filePath): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        if (!is_file($filePath)) {
            $this->removeFile($filePath);
            return;
        }

        $record = $this->buildRecord($collection, $filePath);
        if ($record === null) {
            return;
        }

        $this->pdo();
        $this->insertRecord($record);
        $this->metaSet('last_update_at', gmdate('c'));
        $this->bootstrapped = true;
    }

    public function removeFile(string $filePath): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $pdo = $this->pdo();
        $stmt = $pdo->prepare('DELETE FROM content_entries WHERE source_path = :source_path');
        $stmt->execute([
            ':source_path' => $this->normalizePath($filePath),
        ]);
        $this->metaSet('last_update_at', gmdate('c'));
    }

    /**
     * @return array{collection:string,id:string,slug:string,url:string,source_path:string,data:array<string,mixed>}|null
     */
    public function findBySlug(string $collection, string $slug, bool $includeDraft = false): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $this->ensureBootstrapped();
        $pdo = $this->pdo();
        $sql = 'SELECT collection, entry_id, slug, url, source_path, frontmatter_json, is_draft
                FROM content_entries
                WHERE collection = :collection AND slug = :slug';
        if (!$includeDraft) {
            $sql .= ' AND is_draft = 0';
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':collection' => $collection,
            ':slug' => $slug,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->rowToMeta($row);
    }

    /**
     * @param array<string, mixed> $collectionMeta
     * @return array<int, array{collection:string,id:string,slug:string,url:string,source_path:string,data:array<string,mixed>}>
     */
    public function listCollection(string $collection, bool $includeDraft, array $collectionMeta): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $this->ensureBootstrapped();
        $pdo = $this->pdo();

        $sql = 'SELECT collection, entry_id, slug, url, source_path, frontmatter_json, is_draft
                FROM content_entries
                WHERE collection = :collection';
        if (!$includeDraft) {
            $sql .= ' AND is_draft = 0';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':collection' => $collection]);

        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $this->rowToMeta($row);
        }

        $sort = (string) ($collectionMeta['sort'] ?? 'date desc');
        [$field, $direction] = array_pad(explode(' ', trim($sort), 2), 2, 'desc');
        $direction = strtolower(trim($direction));

        usort($rows, static function (array $a, array $b) use ($field, $direction): int {
            $av = $a['data'][$field] ?? '';
            $bv = $b['data'][$field] ?? '';
            $cmp = $av <=> $bv;
            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return $rows;
    }

    /**
     * @return array<int, array{url:string,source_path:string}>
     */
    public function sitemapEntries(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $this->ensureBootstrapped();
        $pdo = $this->pdo();
        $stmt = $pdo->query(
            'SELECT url, source_path
             FROM content_entries
             WHERE is_draft = 0
             ORDER BY url ASC'
        );

        $rows = [];
        while (($row = $stmt?->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'url' => (string) ($row['url'] ?? ''),
                'source_path' => (string) ($row['source_path'] ?? ''),
            ];
        }

        return $rows;
    }

    private function ensureBootstrapped(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        if (!$this->isAvailable()) {
            return;
        }

        $pdo = $this->pdo();
        $count = (int) ($pdo->query('SELECT COUNT(*) FROM content_entries')?->fetchColumn() ?: 0);
        if ($count === 0 && $this->hasContentFiles()) {
            $this->rebuild();
            return;
        }

        $this->bootstrapped = true;
    }

    private function hasContentFiles(): bool
    {
        foreach ($this->collections() as $collection) {
            if ($this->collectionFiles($collection) !== []) {
                return true;
            }
        }

        return false;
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dir = dirname($this->databasePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_entries (
                source_path TEXT PRIMARY KEY,
                collection TEXT NOT NULL,
                entry_id TEXT NOT NULL,
                slug TEXT NOT NULL,
                url TEXT NOT NULL,
                frontmatter_json TEXT NOT NULL,
                is_draft INTEGER NOT NULL DEFAULT 0,
                mtime INTEGER NOT NULL DEFAULT 0,
                size INTEGER NOT NULL DEFAULT 0,
                indexed_at INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_entries_collection ON content_entries(collection)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_entries_collection_slug ON content_entries(collection, slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_entries_draft ON content_entries(is_draft)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_index_meta (
                meta_key TEXT PRIMARY KEY,
                meta_value TEXT NOT NULL
            )'
        );

        $this->pdo = $pdo;
        return $pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRecord(string $collection, string $filePath): ?array
    {
        $raw = @file_get_contents($filePath);
        if (!is_string($raw)) {
            return null;
        }

        [$frontmatter] = $this->splitFrontmatter($raw);
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        $slug = $this->slugFromFilename($collection, $filename);
        $url = $collection === 'pages'
            ? '/' . ($slug === 'index' ? '' : $slug)
            : '/' . trim($collection, '/') . '/' . $slug;
        if ($url !== '/' && str_ends_with($url, '/')) {
            $url = rtrim($url, '/');
        }

        $mtime = (int) (@filemtime($filePath) ?: 0);
        $size = (int) (@filesize($filePath) ?: 0);

        return [
            'source_path' => $this->normalizePath($filePath),
            'collection' => $collection,
            'entry_id' => $filename,
            'slug' => $slug,
            'url' => $url,
            'frontmatter_json' => json_encode($frontmatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'is_draft' => (bool) ($frontmatter['draft'] ?? false) ? 1 : 0,
            'mtime' => $mtime,
            'size' => $size,
            'indexed_at' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function insertRecord(array $record): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO content_entries (
                source_path, collection, entry_id, slug, url, frontmatter_json, is_draft, mtime, size, indexed_at
            ) VALUES (
                :source_path, :collection, :entry_id, :slug, :url, :frontmatter_json, :is_draft, :mtime, :size, :indexed_at
            )
            ON CONFLICT(source_path) DO UPDATE SET
                collection=excluded.collection,
                entry_id=excluded.entry_id,
                slug=excluded.slug,
                url=excluded.url,
                frontmatter_json=excluded.frontmatter_json,
                is_draft=excluded.is_draft,
                mtime=excluded.mtime,
                size=excluded.size,
                indexed_at=excluded.indexed_at'
        );
        $stmt->execute([
            ':source_path' => (string) $record['source_path'],
            ':collection' => (string) $record['collection'],
            ':entry_id' => (string) $record['entry_id'],
            ':slug' => (string) $record['slug'],
            ':url' => (string) $record['url'],
            ':frontmatter_json' => (string) $record['frontmatter_json'],
            ':is_draft' => (int) $record['is_draft'],
            ':mtime' => (int) $record['mtime'],
            ':size' => (int) $record['size'],
            ':indexed_at' => (int) $record['indexed_at'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{collection:string,id:string,slug:string,url:string,source_path:string,data:array<string,mixed>}
     */
    private function rowToMeta(array $row): array
    {
        $decoded = json_decode((string) ($row['frontmatter_json'] ?? '{}'), true);
        $data = is_array($decoded) ? $decoded : [];
        return [
            'collection' => (string) ($row['collection'] ?? ''),
            'id' => (string) ($row['entry_id'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'source_path' => (string) ($row['source_path'] ?? ''),
            'data' => $data,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function collections(): array
    {
        $dirs = glob($this->contentRoot . '/*', GLOB_ONLYDIR) ?: [];
        $collections = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if ($name === 'data' || $name === 'forms' || $name === 'forms-submissions') {
                continue;
            }
            $collections[] = $name;
        }
        sort($collections);
        return $collections;
    }

    /**
     * @return array<int, string>
     */
    private function collectionFiles(string $collection): array
    {
        $dir = $this->contentRoot . '/' . trim($collection, '/');
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md') ?: [];
        $rows = [];
        foreach ($files as $file) {
            if (basename($file) === '_collection.md') {
                continue;
            }
            $rows[] = $file;
        }
        sort($rows);

        return $rows;
    }

    /**
     * @return array{0:array<string,mixed>,1:string}
     */
    private function splitFrontmatter(string $raw): array
    {
        if (preg_match('/^---\s*\R(.*?)\R---\s*\R?(.*)$/s', $raw, $matches) !== 1) {
            return [[], $raw];
        }

        $frontmatter = Yaml::parse($matches[1]);
        return [is_array($frontmatter) ? $frontmatter : [], $matches[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionMeta(string $collection): array
    {
        if (array_key_exists($collection, $this->collectionMetaCache)) {
            return $this->collectionMetaCache[$collection];
        }

        $path = $this->contentRoot . '/' . trim($collection, '/') . '/_collection.yaml';
        if (!is_file($path)) {
            $this->collectionMetaCache[$collection] = [];
            return [];
        }

        $meta = Yaml::parse((string) file_get_contents($path));
        $this->collectionMetaCache[$collection] = is_array($meta) ? $meta : [];
        return $this->collectionMetaCache[$collection];
    }

    private function slugFromFilename(string $collection, string $filename): string
    {
        $meta = $this->collectionMeta($collection);
        $slugSource = (string) ($meta['slug_from'] ?? 'filename');
        if ($slugSource !== 'filename') {
            return $filename;
        }

        return (string) preg_replace('/^\d{4}-\d{2}-/', '', $filename);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function metaGet(string $key): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT meta_value FROM content_index_meta WHERE meta_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function metaSet(string $key, string $value): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO content_index_meta (meta_key, meta_value)
             VALUES (:key, :value)
             ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }
}
