<?php

declare(strict_types=1);

namespace Atoll\Backup;

use ZipArchive;

final class BackupManager
{
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $backupDir
    ) {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }
    }

    /** @return array<string, mixed> */
    public function create(): array
    {
        $filename = 'backup-' . date('Ymd-His') . '.zip';
        $path = rtrim($this->backupDir, '/') . '/' . $filename;

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return ['ok' => false, 'error' => 'Could not create zip'];
        }

        $source = realpath($this->sourceDir);
        if ($source === false) {
            return ['ok' => false, 'error' => 'Source missing'];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $real = $item->getRealPath();
            if ($real === false) {
                continue;
            }
            $relative = substr($real, strlen($source) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($real, $relative);
            }
        }

        $zip->close();

        return ['ok' => true, 'file' => '/backups/' . $filename, 'path' => $path];
    }
}
