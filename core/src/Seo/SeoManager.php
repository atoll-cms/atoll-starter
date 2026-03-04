<?php

declare(strict_types=1);

namespace Atoll\Seo;

use Atoll\Content\ContentRepository;
use Atoll\Content\Page;
use Atoll\Support\Config;

final class SeoManager
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function meta(Page $page): string
    {
        $siteName = (string) Config::get($this->config, 'name', 'atoll-cms');
        $baseUrl = rtrim((string) Config::get($this->config, 'base_url', ''), '/');
        $title = trim(($page->data['seo_title'] ?? $page->title()) . ' - ' . $siteName, ' -');
        $description = (string) ($page->data['seo_description'] ?? $page->excerpt());
        $url = $baseUrl . $page->url;
        $image = (string) ($page->data['seo_image'] ?? Config::get($this->config, 'seo.default_image', '/assets/images/og-default.jpg'));

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $page->title(),
            'description' => $description,
            'url' => $url,
        ];

        $parts = [
            '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>',
            '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">',
            '<meta property="og:type" content="article">',
            '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES) . '">',
            '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">',
            '<meta property="og:url" content="' . htmlspecialchars($url, ENT_QUOTES) . '">',
            '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES) . '">',
            '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>',
        ];

        return implode("\n", $parts);
    }

    public function sitemap(ContentRepository $content): string
    {
        $baseUrl = rtrim((string) Config::get($this->config, 'base_url', ''), '/');
        $pages = $content->allPublicPages();

        $urls = [];
        foreach ($pages as $page) {
            $loc = htmlspecialchars($baseUrl . $page->url, ENT_QUOTES);
            $lastMod = date('c', filemtime($page->sourcePath) ?: time());
            $urls[] = "<url><loc>{$loc}</loc><lastmod>{$lastMod}</lastmod></url>";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . implode('', $urls)
            . '</urlset>';
    }

    public function robots(): string
    {
        return (string) Config::get($this->config, 'seo.robots', "User-agent: *\nAllow: /");
    }
}
