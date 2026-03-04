<?php

declare(strict_types=1);

namespace Atoll\Islands;

final class IslandManager
{
    /** @var array<string, string> */
    private array $manifest;

    /**
     * @param array<int, string> $manifestPaths
     * @param array<string, string> $pluginIslands
     */
    public function __construct(
        private readonly array $manifestPaths,
        private readonly array $pluginIslands = [],
        private readonly string $loaderScript = '/core/assets/js/island-loader.js'
    ) {
        $manifest = [];
        foreach ($this->manifestPaths as $manifestPath) {
            if (is_file($manifestPath)) {
                $decoded = json_decode((string) file_get_contents($manifestPath), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $name => $url) {
                        if (is_string($name) && is_string($url)) {
                            $manifest[$name] = $url;
                        }
                    }
                }
            }
        }

        $this->manifest = array_merge($manifest, $this->pluginIslands);
    }

    /** @param array<string, mixed> $options */
    public function placeholder(string $component, array $options = []): string
    {
        $client = (string) ($options['client'] ?? 'load');
        $props = $options['props'] ?? [];
        $media = (string) ($options['media'] ?? '');

        return sprintf(
            '<astro-island component="%s" client="%s" media="%s" props="%s"></astro-island>',
            htmlspecialchars($component, ENT_QUOTES),
            htmlspecialchars($client, ENT_QUOTES),
            htmlspecialchars($media, ENT_QUOTES),
            htmlspecialchars((string) json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES)
        );
    }

    public function process(string $html): string
    {
        $components = [];

        $html = preg_replace_callback(
            '/<astro-island\s+([^>]+)><\/astro-island>/i',
            function (array $matches) use (&$components): string {
                $attrString = $matches[1];
                $attrs = $this->parseAttributes($attrString);

                $component = $attrs['component'] ?? '';
                if ($component === '') {
                    return '';
                }

                $client = $attrs['client'] ?? 'load';
                $media = $attrs['media'] ?? '';
                $props = $attrs['props'] ?? '{}';
                $module = $this->manifest[$component] ?? '';

                if ($client !== 'none' && $module !== '') {
                    $components[$component] = $module;
                }

                return sprintf(
                    '<div class="atoll-island" data-island="%s" data-client="%s" data-media="%s" data-module="%s" data-props="%s"></div>',
                    htmlspecialchars($component, ENT_QUOTES),
                    htmlspecialchars($client, ENT_QUOTES),
                    htmlspecialchars($media, ENT_QUOTES),
                    htmlspecialchars($module, ENT_QUOTES),
                    htmlspecialchars($props, ENT_QUOTES)
                );
            },
            $html
        ) ?? $html;

        if ($components === []) {
            return $html;
        }

        $scripts = [
            '<script type="module" src="' . htmlspecialchars($this->loaderScript, ENT_QUOTES) . '" defer></script>',
        ];

        foreach ($components as $component => $src) {
            $scripts[] = '<link rel="modulepreload" href="' . htmlspecialchars($src, ENT_QUOTES) . '">';
        }

        $inject = implode("\n", $scripts);
        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $inject . "\n</body>", $html);
        }

        return $html . "\n" . $inject;
    }

    /** @return array<string, string> */
    private function parseAttributes(string $raw): array
    {
        $attrs = [];
        preg_match_all('/(\w+)="([^"]*)"/', $raw, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attrs[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $attrs;
    }
}
