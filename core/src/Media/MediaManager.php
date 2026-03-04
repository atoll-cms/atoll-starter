<?php

declare(strict_types=1);

namespace Atoll\Media;

use Atoll\Hooks\HookManager;
use Intervention\Image\ImageManager;

final class MediaManager
{
    public function __construct(
        private readonly string $uploadRoot,
        private readonly HookManager $hooks
    ) {
    }

    /** @return array<string, mixed> */
    public function upload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) ($file['name'] ?? 'upload.bin')) ?: 'upload.bin';
        $datePath = date('Y/m');
        $targetDir = rtrim($this->uploadRoot, '/') . '/' . $datePath;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $targetPath = $targetDir . '/' . $name;
        if (!move_uploaded_file($tmp, $targetPath)) {
            if (!rename($tmp, $targetPath)) {
                return ['ok' => false, 'error' => 'Could not store file'];
            }
        }

        $generated = [];
        if (class_exists(ImageManager::class) && $this->isImage($targetPath)) {
            $generated = $this->generateResponsiveImages($targetPath);
        }

        $publicPath = str_replace($this->uploadRoot, '/assets/uploads', $targetPath);

        $this->hooks->run('media:upload', [
            'file' => $targetPath,
            'public' => $publicPath,
            'generated' => $generated,
        ]);

        return [
            'ok' => true,
            'file' => $publicPath,
            'generated' => $generated,
        ];
    }

    /** @return array<int, string> */
    private function generateResponsiveImages(string $path): array
    {
        $generated = [];

        try {
            $driver = 'gd';
            $manager = ImageManager::gd();
            if (class_exists(\Imagick::class)) {
                try {
                    $manager = ImageManager::imagick();
                    $driver = 'imagick';
                } catch (\Throwable) {
                    $manager = ImageManager::gd();
                    $driver = 'gd';
                }
            }

            $image = $manager->read($path);
            $widths = [480, 960, 1600];
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === '') {
                $ext = 'jpg';
            }
            $base = substr($path, 0, -(strlen($ext) + 1));

            foreach ($widths as $width) {
                $clone = clone $image;
                $clone->scale(width: $width);
                $jpgPath = sprintf('%s-%d.%s', $base, $width, $ext);
                $clone->save($jpgPath);
                $generated[] = str_replace($this->uploadRoot, '/assets/uploads', $jpgPath);

                if ($this->supportsFormat('webp', $driver) && is_callable([$clone, 'toWebp'])) {
                    try {
                        $webpPath = sprintf('%s-%d.webp', $base, $width);
                        $webpClone = clone $clone;
                        $webpClone->toWebp(quality: 80)->save($webpPath);
                        $generated[] = str_replace($this->uploadRoot, '/assets/uploads', $webpPath);
                    } catch (\Throwable) {
                        // best effort only
                    }
                }

                if ($this->supportsFormat('avif', $driver) && is_callable([$clone, 'toAvif'])) {
                    try {
                        $avifPath = sprintf('%s-%d.avif', $base, $width);
                        $avifClone = clone $clone;
                        $avifClone->toAvif(quality: 62)->save($avifPath);
                        $generated[] = str_replace($this->uploadRoot, '/assets/uploads', $avifPath);
                    } catch (\Throwable) {
                        // best effort only
                    }
                }
            }
        } catch (\Throwable) {
            // best effort only
        }

        return $generated;
    }

    private function supportsFormat(string $format, string $driver): bool
    {
        $format = strtolower($format);
        if ($driver === 'gd') {
            return match ($format) {
                'webp' => function_exists('imagewebp'),
                'avif' => function_exists('imageavif'),
                default => false,
            };
        }

        if ($driver === 'imagick' && class_exists(\Imagick::class)) {
            try {
                $formats = \Imagick::queryFormats(strtoupper($format));
                return is_array($formats) && $formats !== [];
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function isImage(string $path): bool
    {
        $mime = mime_content_type($path) ?: '';
        return str_starts_with($mime, 'image/');
    }
}
