<?php

declare(strict_types=1);

namespace Atoll\Support;

use Symfony\Component\Yaml\Yaml as SymfonyYaml;

final class Yaml
{
    public static function parse(string $content): array
    {
        if (class_exists(SymfonyYaml::class)) {
            $parsed = SymfonyYaml::parse($content);
            return is_array($parsed) ? $parsed : [];
        }

        if (function_exists('yaml_parse')) {
            $parsed = yaml_parse($content);
            return is_array($parsed) ? $parsed : [];
        }

        return self::parseSimple($content);
    }

    public static function dump(array $data): string
    {
        if (class_exists(SymfonyYaml::class)) {
            return SymfonyYaml::dump($data, 6, 2);
        }

        return self::dumpSimple($data);
    }

    private static function parseSimple(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $root = [];
        $stack = [0 => &$root];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $level = (int) floor($indent / 2);
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '- ')) {
                $value = self::castValue(substr($trimmed, 2));
                if (!isset($stack[$level]) || !is_array($stack[$level])) {
                    $stack[$level] = [];
                }
                $stack[$level][] = $value;
                continue;
            }

            [$key, $raw] = array_pad(explode(':', $trimmed, 2), 2, '');
            $key = trim($key);
            $raw = ltrim($raw);

            if ($raw === '') {
                $stack[$level][$key] = [];
                $stack[$level + 1] = &$stack[$level][$key];
                continue;
            }

            $stack[$level][$key] = self::castValue($raw);
        }

        return $root;
    }

    private static function dumpSimple(array $data, int $indent = 0): string
    {
        $yaml = '';
        foreach ($data as $key => $value) {
            $pad = str_repeat(' ', $indent);
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $yaml .= $pad . $key . ":\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= $pad . "  -\n" . self::dumpSimple($item, $indent + 4);
                        } else {
                            $yaml .= $pad . '  - ' . self::stringify($item) . "\n";
                        }
                    }
                } else {
                    $yaml .= $pad . $key . ":\n" . self::dumpSimple($value, $indent + 2);
                }
            } else {
                $yaml .= $pad . $key . ': ' . self::stringify($value) . "\n";
            }
        }

        return $yaml;
    }

    private static function castValue(string $value): mixed
    {
        $value = trim($value);
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if ($value === 'null') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        if (preg_match('/^\[(.*)\]$/', $value, $m) === 1) {
            $parts = array_map('trim', explode(',', $m[1]));
            return array_values(array_filter(array_map(static fn (string $p) => trim($p, " \t\n\r\0\x0B\"'"), $parts), static fn (string $p) => $p !== ''));
        }

        return trim($value, "\"'");
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_numeric($value) => (string) $value,
            default => '"' . str_replace('"', '\\"', (string) $value) . '"',
        };
    }
}
