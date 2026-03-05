<?php

declare(strict_types=1);

namespace Atoll\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        if (class_exists(GithubFlavoredMarkdownConverter::class)) {
            $converter = new GithubFlavoredMarkdownConverter([
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
            ]);

            return (string) $converter->convert($markdown);
        }

        // Very small fallback parser for environments without dependencies.
        $html = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html) ?? $html;
        $html = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $html) ?? $html;
        $html = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $html) ?? $html;
        $html = preg_replace('/\n\n+/', "</p><p>", $html) ?? $html;

        return '<p>' . $html . '</p>';
    }
}
