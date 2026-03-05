<?php

declare(strict_types=1);

use Atoll\Http\Request;
use Atoll\Http\Response;

$toBlocks = static function (string $markdown): array {
    $lines = preg_split('/\R/', str_replace("\r\n", "\n", $markdown)) ?: [];
    $blocks = [];
    $paragraph = [];
    $count = count($lines);
    $i = 0;

    $flushParagraph = static function (array &$buffer, array &$blocks): void {
        if ($buffer === []) {
            return;
        }
        $text = trim(implode("\n", $buffer));
        if ($text === '') {
            $buffer = [];
            return;
        }
        $blocks[] = [
            'type' => 'paragraph',
            'text' => $text,
        ];
        $buffer = [];
    };

    while ($i < $count) {
        $line = rtrim((string) $lines[$i]);
        $trim = trim($line);

        if ($trim === '') {
            $flushParagraph($paragraph, $blocks);
            $i++;
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m) === 1) {
            $flushParagraph($paragraph, $blocks);
            $blocks[] = [
                'type' => 'heading',
                'level' => strlen((string) $m[1]),
                'text' => trim((string) $m[2]),
            ];
            $i++;
            continue;
        }

        if (preg_match('/^!\[(.*?)\]\((.*?)\)$/', $trim, $m) === 1) {
            $flushParagraph($paragraph, $blocks);
            $blocks[] = [
                'type' => 'image',
                'alt' => trim((string) $m[1]),
                'src' => trim((string) $m[2]),
            ];
            $i++;
            continue;
        }

        if (preg_match('/^```([\w-]*)\s*$/', $trim, $m) === 1) {
            $flushParagraph($paragraph, $blocks);
            $lang = trim((string) $m[1]);
            $code = [];
            $i++;
            while ($i < $count) {
                $codeLine = rtrim((string) $lines[$i], "\r\n");
                if (trim($codeLine) === '```') {
                    $i++;
                    break;
                }
                $code[] = $codeLine;
                $i++;
            }
            $blocks[] = [
                'type' => 'code',
                'language' => $lang,
                'code' => implode("\n", $code),
            ];
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $trim, $m) === 1) {
            $flushParagraph($paragraph, $blocks);
            $quote = [trim((string) $m[1])];
            $i++;
            while ($i < $count) {
                $next = trim((string) $lines[$i]);
                if (preg_match('/^>\s?(.*)$/', $next, $qm) !== 1) {
                    break;
                }
                $quote[] = trim((string) $qm[1]);
                $i++;
            }
            $blocks[] = [
                'type' => 'quote',
                'text' => trim(implode("\n", $quote)),
            ];
            continue;
        }

        if (preg_match('/^[-*]\s+(.*)$/', $trim, $m) === 1) {
            $flushParagraph($paragraph, $blocks);
            $items = [trim((string) $m[1])];
            $i++;
            while ($i < $count) {
                $next = trim((string) $lines[$i]);
                if (preg_match('/^[-*]\s+(.*)$/', $next, $lm) !== 1) {
                    break;
                }
                $items[] = trim((string) $lm[1]);
                $i++;
            }
            $blocks[] = [
                'type' => 'list',
                'items' => $items,
            ];
            continue;
        }

        $paragraph[] = $line;
        $i++;
    }

    $flushParagraph($paragraph, $blocks);
    return $blocks;
};

$toMarkdown = static function (array $blocks): string {
    $chunks = [];

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        $type = strtolower(trim((string) ($block['type'] ?? 'paragraph')));
        if ($type === 'heading') {
            $level = (int) ($block['level'] ?? 2);
            $level = max(1, min(6, $level));
            $text = trim((string) ($block['text'] ?? ''));
            if ($text !== '') {
                $chunks[] = str_repeat('#', $level) . ' ' . $text;
            }
            continue;
        }

        if ($type === 'list') {
            $items = $block['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            $lines = [];
            foreach ($items as $item) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $lines[] = '- ' . $text;
                }
            }
            if ($lines !== []) {
                $chunks[] = implode("\n", $lines);
            }
            continue;
        }

        if ($type === 'code') {
            $lang = trim((string) ($block['language'] ?? ''));
            $code = rtrim((string) ($block['code'] ?? ''), "\n");
            $chunks[] = "```{$lang}\n{$code}\n```";
            continue;
        }

        if ($type === 'quote') {
            $text = trim((string) ($block['text'] ?? ''));
            if ($text !== '') {
                $lines = array_map(static fn (string $line): string => '> ' . $line, preg_split('/\R/', $text) ?: []);
                $chunks[] = implode("\n", $lines);
            }
            continue;
        }

        if ($type === 'image') {
            $src = trim((string) ($block['src'] ?? ''));
            if ($src !== '') {
                $alt = trim((string) ($block['alt'] ?? ''));
                $chunks[] = '![' . $alt . '](' . $src . ')';
            }
            continue;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text !== '') {
            $chunks[] = $text;
        }
    }

    return trim(implode("\n\n", $chunks));
};

$payload = static function (Request $request): array {
    if ($request->isJson()) {
        return $request->json();
    }

    return array_merge($request->query, $request->post);
};

return [
    'name' => 'visual-editor',
    'description' => 'Block-style visual editor bridge for markdown content.',
    'version' => '0.1.0',
    'hooks' => [
        'admin:menu' => static fn (): array => [
            'id' => 'visual-editor',
            'label' => 'Visual Editor',
            'icon' => 'M9 12h6m-6 4h6m3-11H6a2 2 0 00-2 2v10a2 2 0 002 2h5l5-5V7a2 2 0 00-2-2z',
            'route' => '/admin/visual-editor',
        ],
        'admin:dashboard' => static fn (): array => [
            'id' => 'visual-editor-widget',
            'title' => 'Visual Editor',
            'value' => 'beta',
            'text' => 'Markdown <-> Block Konvertierung fuer strukturierte Editier-Workflows.',
        ],
    ],
    'routes' => [
        '/visual-editor/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'visual-editor',
            'features' => ['blocks', 'markdown_bridge', 'admin_page'],
        ],
        '/visual-editor/blocks/parse' => static function (Request $request) use ($payload, $toBlocks): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $payload($request);
            $markdown = (string) ($input['markdown'] ?? '');

            return Response::json([
                'ok' => true,
                'blocks' => $toBlocks($markdown),
            ]);
        },
        '/visual-editor/blocks/render' => static function (Request $request) use ($payload, $toMarkdown): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $payload($request);
            $blocks = $input['blocks'] ?? [];
            if (!is_array($blocks)) {
                return Response::json(['ok' => false, 'error' => 'blocks must be an array'], 422);
            }

            return Response::json([
                'ok' => true,
                'markdown' => $toMarkdown($blocks),
            ]);
        },
    ],
    'islands' => [],
    'admin_pages' => [
        'visual-editor' => 'admin/visual-editor.html',
    ],
];
