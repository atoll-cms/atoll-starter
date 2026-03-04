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

    /** @return array<string, mixed> */
    public function list(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $items = [];

        if (!is_dir($this->uploadRoot)) {
            return ['ok' => true, 'files' => []];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $absolute = $item->getPathname();
            $public = $this->toPublicPath($absolute);
            if ($public === null) {
                continue;
            }

            $mime = (string) (mime_content_type($absolute) ?: 'application/octet-stream');
            $isImage = str_starts_with($mime, 'image/');
            $width = null;
            $height = null;

            if ($isImage) {
                $size = @getimagesize($absolute);
                if (is_array($size)) {
                    $width = isset($size[0]) ? (int) $size[0] : null;
                    $height = isset($size[1]) ? (int) $size[1] : null;
                }
            }

            $items[] = [
                'file' => $public,
                'name' => basename($absolute),
                'mime' => $mime,
                'is_image' => $isImage,
                'size' => (int) @filesize($absolute),
                'modified_at' => date('c', (int) @filemtime($absolute)),
                'width' => $width,
                'height' => $height,
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['modified_at'] ?? ''), (string) ($a['modified_at'] ?? '')));
        $items = array_slice($items, 0, $limit);

        return ['ok' => true, 'files' => $items];
    }

    /** @param array<string, mixed> $options
     *  @return array<string, mixed>
     */
    public function transform(string $publicPath, array $options): array
    {
        $absolute = $this->toAbsolutePath($publicPath);
        if ($absolute === null || !is_file($absolute)) {
            return ['ok' => false, 'error' => 'File not found'];
        }

        if (!$this->isImage($absolute)) {
            return ['ok' => false, 'error' => 'File is not an image'];
        }

        [$manager, $driver] = $this->imageManager();
        if ($manager === null) {
            return ['ok' => false, 'error' => 'Image driver not available'];
        }

        $mode = strtolower((string) ($options['mode'] ?? 'resize'));
        $width = max(0, (int) ($options['width'] ?? 0));
        $height = max(0, (int) ($options['height'] ?? 0));
        $quality = max(1, min(100, (int) ($options['quality'] ?? 82)));
        $format = strtolower(trim((string) ($options['format'] ?? '')));
        $overwrite = (bool) ($options['overwrite'] ?? false);

        if ($mode !== 'resize' && $mode !== 'crop') {
            return ['ok' => false, 'error' => 'Unsupported mode'];
        }

        if ($mode === 'crop' && ($width < 1 || $height < 1)) {
            return ['ok' => false, 'error' => 'Crop requires width and height'];
        }
        if ($mode === 'resize' && $width < 1 && $height < 1) {
            return ['ok' => false, 'error' => 'Resize requires width or height'];
        }

        $allowedFormats = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
        if ($format !== '' && !in_array($format, $allowedFormats, true)) {
            return ['ok' => false, 'error' => 'Unsupported output format'];
        }

        if ($format === 'webp' && !$this->supportsFormat('webp', $driver)) {
            return ['ok' => false, 'error' => 'WebP not supported by server image driver'];
        }
        if ($format === 'avif' && !$this->supportsFormat('avif', $driver)) {
            return ['ok' => false, 'error' => 'AVIF not supported by server image driver'];
        }

        try {
            $image = $manager->read($absolute);
            if ($mode === 'crop') {
                if (is_callable([$image, 'cover'])) {
                    $image->cover($width, $height);
                } else {
                    $image->resize($width, $height);
                }
            } else {
                if ($width > 0 && $height > 0) {
                    $image->scale(width: $width, height: $height);
                } elseif ($width > 0) {
                    $image->scale(width: $width);
                } else {
                    $image->scale(height: $height);
                }
            }

            $inputExt = strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION));
            if ($inputExt === '') {
                $inputExt = 'jpg';
            }
            $outputExt = $format !== '' ? $format : $inputExt;
            if ($outputExt === 'jpeg') {
                $outputExt = 'jpg';
            }

            $base = substr($absolute, 0, -(strlen((string) pathinfo($absolute, PATHINFO_EXTENSION)) + 1));
            if ($base === '' || $base === $absolute) {
                $base = $absolute;
            }
            $dimSuffix = $width > 0 && $height > 0
                ? "{$width}x{$height}"
                : ($width > 0 ? "{$width}w" : "{$height}h");
            $targetAbsolute = $overwrite && $outputExt === $inputExt
                ? $absolute
                : sprintf('%s-%s-%s.%s', $base, $mode, $dimSuffix, $outputExt);

            $saved = $this->saveImage($image, $targetAbsolute, $outputExt, $quality);
            if (!$saved) {
                return ['ok' => false, 'error' => 'Could not write transformed image'];
            }

            $publicTarget = $this->toPublicPath($targetAbsolute);
            if ($publicTarget === null) {
                return ['ok' => false, 'error' => 'Could not resolve output path'];
            }

            $this->hooks->run('media:upload', [
                'file' => $targetAbsolute,
                'public' => $publicTarget,
                'generated' => [$publicTarget],
            ]);

            return [
                'ok' => true,
                'file' => $publicTarget,
                'source' => $publicPath,
                'mode' => $mode,
                'width' => $width,
                'height' => $height,
                'format' => $outputExt,
                'overwrite' => $overwrite && $outputExt === $inputExt,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Image transform failed'];
        }
    }

    /** @return array<int, string> */
    private function generateResponsiveImages(string $path): array
    {
        $generated = [];

        try {
            [$manager, $driver] = $this->imageManager();
            if ($manager === null) {
                return [];
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

    /** @return array{0:?ImageManager,1:string} */
    private function imageManager(): array
    {
        if (!class_exists(ImageManager::class)) {
            return [null, 'none'];
        }

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

        return [$manager, $driver];
    }

    private function saveImage(mixed $image, string $targetAbsolute, string $format, int $quality): bool
    {
        try {
            if ($format === 'webp' && is_callable([$image, 'toWebp'])) {
                $image->toWebp(quality: $quality)->save($targetAbsolute);
                return true;
            }
            if ($format === 'avif' && is_callable([$image, 'toAvif'])) {
                $image->toAvif(quality: $quality)->save($targetAbsolute);
                return true;
            }
            if ($format === 'jpg' && is_callable([$image, 'toJpeg'])) {
                $image->toJpeg(quality: $quality)->save($targetAbsolute);
                return true;
            }
            if ($format === 'png' && is_callable([$image, 'toPng'])) {
                $image->toPng()->save($targetAbsolute);
                return true;
            }

            $image->save($targetAbsolute);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function toAbsolutePath(string $publicPath): ?string
    {
        $publicPath = trim($publicPath);
        if ($publicPath === '' || !str_starts_with($publicPath, '/assets/uploads/')) {
            return null;
        }

        $relative = ltrim(substr($publicPath, strlen('/assets/uploads/')), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $full = rtrim($this->uploadRoot, '/') . '/' . $relative;
        $rootReal = realpath($this->uploadRoot);
        $dirReal = realpath(dirname($full));
        if ($rootReal === false || $dirReal === false) {
            return null;
        }
        if (!str_starts_with($dirReal . '/', rtrim($rootReal, '/') . '/')) {
            return null;
        }

        return $full;
    }

    private function toPublicPath(string $absolutePath): ?string
    {
        $rootReal = realpath($this->uploadRoot);
        $fileReal = realpath($absolutePath);
        if ($rootReal === false || $fileReal === false) {
            return null;
        }
        if (!str_starts_with($fileReal . '/', rtrim($rootReal, '/') . '/')) {
            return null;
        }

        return '/assets/uploads' . substr($fileReal, strlen($rootReal));
    }
}
