<?php

declare(strict_types=1);

namespace Atoll\Routing;

use Atoll\Content\ContentRepository;

final class Router
{
    /** @param array<int, string> $pagesTemplateRoots */
    public function __construct(private readonly array $pagesTemplateRoots)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $path, ContentRepository $content): ?array
    {
        $normalized = trim($path, '/');

        if ($normalized === '') {
            $page = $content->getPage('index');
            $template = $this->resolvePageTemplate('index');
            return ($page !== null && $template !== null) ? [
                'template' => $template,
                'page' => $page,
                'dependencies' => [$page->sourcePath],
            ] : null;
        }

        // Collection listing route: /blog -> templates/pages/blog/index.twig
        $segments = explode('/', $normalized);
        if (count($segments) === 1) {
            $single = $segments[0];
            if ($this->templateExists($single . '/index.twig')) {
                $items = $content->listCollection($single);
                return [
                    'template' => 'pages/' . $single . '/index.twig',
                    'collection' => $single,
                    'items' => array_map(static fn ($p) => $p->toArray(), $items),
                    'dependencies' => array_map(static fn ($p) => $p->sourcePath, $items),
                ];
            }
        }

        // Dynamic route: /blog/:slug -> templates/pages/blog/[slug].twig
        if (count($segments) === 2) {
            [$collection, $slug] = $segments;
            if ($this->templateExists($collection . '/[slug].twig')) {
                $entry = $content->getCollectionEntryBySlug($collection, $slug);
                if ($entry !== null) {
                    return [
                        'template' => 'pages/' . $collection . '/[slug].twig',
                        'page' => $entry,
                        'collection' => $collection,
                        'dependencies' => [$entry->sourcePath],
                    ];
                }
            }
        }

        $contentPage = $content->getPage($normalized);
        $template = $this->resolvePageTemplate($normalized);
        if ($contentPage !== null && $template !== null) {
            return [
                'template' => $template,
                'page' => $contentPage,
                'dependencies' => [$contentPage->sourcePath],
            ];
        }

        return null;
    }

    private function resolvePageTemplate(string $slug): ?string
    {
        $slug = trim($slug, '/');
        $candidates = [];

        if ($slug === '' || $slug === 'index') {
            $candidates[] = 'index.twig';
        } else {
            $candidates[] = $slug . '.twig';
        }
        $candidates[] = 'default.twig';

        foreach ($candidates as $candidate) {
            if ($this->templateExists($candidate)) {
                return 'pages/' . $candidate;
            }
        }

        return null;
    }

    private function templateExists(string $relativePagePath): bool
    {
        foreach ($this->pagesTemplateRoots as $root) {
            $path = rtrim($root, '/') . '/' . ltrim($relativePagePath, '/');
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }
}
