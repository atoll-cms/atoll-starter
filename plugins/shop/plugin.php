<?php

declare(strict_types=1);

return [
    'name' => 'shop',
    'description' => 'Lightweight product listing and checkout handoff',
    'version' => '0.1.0',
    'hooks' => [
        'head:meta' => static function (): string {
            return '<meta name="atoll-shop" content="enabled">';
        },
    ],
    'routes' => [
        '/shop/health' => static fn (): array => ['ok' => true, 'plugin' => 'shop'],
        '/shop/products.json' => static function (): array {
            $root = dirname(__DIR__, 2);
            $dir = $root . '/content/shop';
            if (!is_dir($dir)) {
                return ['ok' => true, 'products' => []];
            }

            $products = [];
            foreach (glob($dir . '/*.md') ?: [] as $file) {
                $raw = (string) file_get_contents($file);
                if (preg_match('/^---\s*\R(.*?)\R---\s*\R?(.*)$/s', $raw, $m) !== 1) {
                    continue;
                }
                $data = \Atoll\Support\Yaml::parse((string) $m[1]);
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $slug = preg_replace('/^\d{4}-\d{2}-/', '', $filename) ?: $filename;
                $products[] = [
                    'id' => $filename,
                    'slug' => $slug,
                    'title' => $data['title'] ?? $filename,
                    'price' => $data['price'] ?? null,
                    'currency' => $data['currency'] ?? 'EUR',
                    'excerpt' => $data['excerpt'] ?? '',
                    'stock' => $data['stock'] ?? null,
                    'url' => '/shop/' . $slug,
                ];
            }

            usort($products, static fn (array $a, array $b): int => strcmp((string) $a['title'], (string) $b['title']));
            return ['ok' => true, 'products' => $products];
        },
    ],
    'islands' => [],
];
