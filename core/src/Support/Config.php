<?php

declare(strict_types=1);

namespace Atoll\Support;

final class Config
{
    /** @return array<string, mixed> */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        return Yaml::parse($content);
    }

    /** @param array<string, mixed> $config */
    public static function save(string $path, array $config): void
    {
        file_put_contents($path, Yaml::dump($config));
    }

    public static function get(array $config, string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $config;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
