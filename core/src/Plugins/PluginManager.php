<?php

declare(strict_types=1);

namespace Atoll\Plugins;

use Atoll\Hooks\HookManager;
use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Support\Yaml;

final class PluginManager
{
    /** @var array<string, array<string, mixed>> */
    private array $plugins = [];

    /** @var array<string, mixed> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $islands = [];

    /** @var array<string, array{plugin:string,title:string,path:string}> */
    private array $adminPages = [];

    /** @var array<string, bool> */
    private array $state = [];

    public function __construct(
        private readonly string $pluginsDir,
        private readonly string $stateFile,
        private readonly HookManager $hooks,
        private readonly bool $defaultsEnabled = true
    ) {
    }

    public function load(): void
    {
        $this->state = $this->loadState();
        $this->plugins = [];
        $this->routes = [];
        $this->islands = [];
        $this->adminPages = [];

        if (!is_dir($this->pluginsDir)) {
            return;
        }

        $dirs = glob($this->pluginsDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $id = basename($dir);
            $manifestPath = $dir . '/plugin.php';
            if (!is_file($manifestPath)) {
                continue;
            }

            /** @var mixed $manifest */
            $manifest = require $manifestPath;
            if (!is_array($manifest)) {
                continue;
            }

            $active = $this->state[$id] ?? $this->defaultsEnabled;
            $manifest['id'] = $id;
            $manifest['active'] = $active;
            $manifest['path'] = $dir;
            $this->plugins[$id] = $manifest;

            if (!$active) {
                continue;
            }

            foreach (($manifest['hooks'] ?? []) as $hook => $handler) {
                if (is_callable($handler)) {
                    $this->hooks->register((string) $hook, $handler);
                }
            }

            $this->registerRouteDefinition($manifest['routes'] ?? []);

            foreach (($manifest['islands'] ?? []) as $name => $relativePath) {
                $path = rtrim($dir, '/') . '/' . ltrim((string) $relativePath, '/');
                $publicPath = str_replace($this->pluginsDir, '/plugins', $path);
                $this->islands[(string) $name] = $publicPath;
            }

            foreach (($manifest['admin_pages'] ?? []) as $viewId => $relativePath) {
                if (!is_string($viewId) || !is_string($relativePath)) {
                    continue;
                }

                $normalizedView = trim($viewId);
                if ($normalizedView === '') {
                    continue;
                }

                $path = rtrim($dir, '/') . '/' . ltrim($relativePath, '/');
                if (!is_file($path)) {
                    continue;
                }

                $this->adminPages[$normalizedView] = [
                    'plugin' => $id,
                    'title' => (string) ($manifest['name'] ?? $id),
                    'path' => $path,
                ];
            }
        }

        foreach ($this->hooks->run('route:register', $this) as $result) {
            $this->registerRouteDefinition($result);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->plugins;
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $rows = [];
        foreach ($this->plugins as $id => $manifest) {
            $rows[] = [
                'id' => $id,
                'name' => $manifest['name'] ?? $id,
                'version' => $manifest['version'] ?? '0.0.0',
                'active' => (bool) ($manifest['active'] ?? false),
                'description' => $manifest['description'] ?? '',
            ];
        }

        return $rows;
    }

    public function toggle(string $id, bool $active): void
    {
        $this->state[$id] = $active;
        file_put_contents($this->stateFile, Yaml::dump($this->state));
    }

    public function hasRoute(string $path): bool
    {
        return array_key_exists($path, $this->routes);
    }

    public function dispatchRoute(Request $request): ?Response
    {
        $handler = $this->routes[$request->path] ?? null;
        if ($handler === null) {
            return null;
        }

        if (is_callable($handler)) {
            $result = $this->invokeRouteHandler($handler, $request);
            if ($result instanceof Response) {
                return $result;
            }
            if (is_array($result)) {
                return Response::json($result);
            }
            return Response::html((string) $result);
        }

        return Response::text('Plugin route handler is not callable', 500);
    }

    /** @return array<string, string> */
    public function islandMap(): array
    {
        return $this->islands;
    }

    /** @return array{plugin:string,title:string,path:string}|null */
    public function adminPage(string $viewId): ?array
    {
        $viewId = trim($viewId);
        if ($viewId === '') {
            return null;
        }

        return $this->adminPages[$viewId] ?? null;
    }

    /** @return array<string, bool> */
    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $state = Yaml::parse((string) file_get_contents($this->stateFile));
        return is_array($state) ? array_map(static fn ($v) => (bool) $v, $state) : [];
    }

    private function invokeRouteHandler(callable $handler, Request $request): mixed
    {
        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $reflection = new \ReflectionMethod($handler[0], $handler[1]);
            return $reflection->getNumberOfParameters() > 0 ? $handler($request) : $handler();
        }

        $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
        return $reflection->getNumberOfParameters() > 0 ? $handler($request) : $handler();
    }

    private function registerRouteDefinition(mixed $definition): void
    {
        if (!is_array($definition) || $definition === []) {
            return;
        }

        if (array_is_list($definition)) {
            foreach ($definition as $item) {
                $this->registerRouteDefinition($item);
            }
            return;
        }

        if (isset($definition['path'])) {
            $path = trim((string) ($definition['path'] ?? ''));
            $handler = $definition['handler'] ?? null;
            if ($path !== '' && $handler !== null) {
                $this->routes[$path] = $handler;
            }
            return;
        }

        foreach ($definition as $path => $handler) {
            if (!is_string($path)) {
                continue;
            }
            $normalized = trim($path);
            if ($normalized === '') {
                continue;
            }
            $this->routes[$normalized] = $handler;
        }
    }
}
