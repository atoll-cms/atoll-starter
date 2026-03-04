<?php

declare(strict_types=1);

namespace Atoll\Template;

use Atoll\Hooks\HookManager;
use Atoll\Islands\IslandManager;
use Atoll\Seo\SeoManager;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TemplateEngine
{
    private ?Environment $twig = null;
    private string $basePath = '';
    /** @var (callable(): string)|null */
    private $csrfTokenProvider;

    /** @param array<string, mixed> $config */
    public function __construct(
        /** @var array<int, string> */
        private readonly array $templatePaths,
        private readonly string $siteRoot,
        private readonly string $coreRoot,
        private readonly string $activeTheme,
        private readonly IslandManager $islands,
        private readonly HookManager $hooks,
        private readonly SeoManager $seo,
        private readonly array $config,
        ?callable $csrfTokenProvider = null
    ) {
        $this->csrfTokenProvider = $csrfTokenProvider;
        $this->basePath = $this->extractBasePath((string) ($this->config['base_url'] ?? ''));

        if (class_exists(Environment::class) && class_exists(FilesystemLoader::class)) {
            $loader = new FilesystemLoader($this->templatePaths);
            $this->twig = new Environment($loader, [
                'cache' => false,
                'autoescape' => 'html',
                'debug' => true,
            ]);

            $this->twig->addFunction(new TwigFunction('island', fn (string $component, array $options = []) => $this->islands->placeholder($component, $options), ['is_safe' => ['html']]));
            $this->twig->addFunction(new TwigFunction('hook', fn (string $name, mixed ...$payload) => $this->hooks->concat($name, ...$payload), ['is_safe' => ['html']]));
            $this->twig->addFunction(new TwigFunction('seo_meta', fn (array $page) => $this->seoFromArray($page), ['is_safe' => ['html']]));
            $this->twig->addFunction(new TwigFunction('theme_asset', fn (string $path) => $this->resolveThemeAsset($path)));
            $this->twig->addFunction(new TwigFunction('url', fn (string $path = '/') => $this->resolveUrl($path)));
            $this->twig->addFunction(new TwigFunction('asset', fn (string $path) => $this->resolveUrl($path)));
            $this->twig->addFunction(new TwigFunction('base_path', fn () => $this->basePath === '' ? '/' : $this->basePath));
            $this->twig->addFunction(new TwigFunction('now', fn () => date('c')));
            $this->twig->addFunction(new TwigFunction('csrf_token', fn () => $this->resolveCsrfToken()));

            $this->twig->addGlobal('site', $this->config);
        }
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        if ($this->twig !== null) {
            return $this->twig->render($template, $context);
        }

        // fallback rendering when Twig is not available.
        foreach ($this->templatePaths as $templateRoot) {
            $templatePath = rtrim($templateRoot, '/') . '/' . ltrim($template, '/');
            if (is_file($templatePath)) {
                return (string) file_get_contents($templatePath);
            }
        }

        return '<h1>Template missing</h1>';
    }

    /** @param array<string, mixed> $page */
    private function seoFromArray(array $page): string
    {
        $dummyPage = new \Atoll\Content\Page(
            id: (string) ($page['id'] ?? ''),
            collection: (string) ($page['collection'] ?? 'pages'),
            slug: (string) ($page['slug'] ?? ''),
            sourcePath: (string) ($page['source_path'] ?? ''),
            url: (string) ($page['url'] ?? '/'),
            data: $page,
            markdown: (string) ($page['markdown'] ?? ''),
            content: (string) ($page['content'] ?? '')
        );

        return $this->seo->meta($dummyPage);
    }

    private function resolveThemeAsset(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $siteThemePath = $this->siteRoot . '/themes/' . $this->activeTheme . '/assets/' . $relative;
        if (is_file($siteThemePath)) {
            return $this->resolveUrl('/themes/' . rawurlencode($this->activeTheme) . '/assets/' . $relative);
        }

        $coreThemePath = $this->coreRoot . '/themes/' . $this->activeTheme . '/assets/' . $relative;
        if (is_file($coreThemePath)) {
            return $this->resolveUrl('/core/themes/' . rawurlencode($this->activeTheme) . '/assets/' . $relative);
        }

        return $this->resolveUrl('/core/themes/default/assets/' . $relative);
    }

    private function resolveCsrfToken(): string
    {
        if (!is_callable($this->csrfTokenProvider)) {
            return '';
        }

        $token = ($this->csrfTokenProvider)();
        return is_string($token) ? $token : '';
    }

    private function resolveUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->basePath === '' ? '/' : $this->basePath . '/';
        }

        if (
            preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || str_starts_with($path, '//')
            || str_starts_with($path, '#')
            || str_starts_with($path, '?')
        ) {
            return $path;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        if ($this->basePath === '') {
            return $path;
        }

        if ($path === $this->basePath || str_starts_with($path, $this->basePath . '/')) {
            return $path;
        }

        return $this->basePath . $path;
    }

    private function extractBasePath(string $baseUrl): string
    {
        $parsedPath = parse_url($baseUrl, PHP_URL_PATH);
        if (!is_string($parsedPath)) {
            return '';
        }

        $trimmed = trim($parsedPath, '/');
        return $trimmed === '' ? '' : '/' . $trimmed;
    }
}
