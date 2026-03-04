<?php

declare(strict_types=1);

namespace Atoll\Hooks;

final class HookManager
{
    /** @var array<string, list<callable>> */
    private array $hooks = [];

    public function register(string $hook, callable $handler): void
    {
        $this->hooks[$hook] ??= [];
        $this->hooks[$hook][] = $handler;
    }

    /** @return list<mixed> */
    public function run(string $hook, mixed ...$payload): array
    {
        $results = [];
        foreach ($this->hooks[$hook] ?? [] as $handler) {
            $results[] = $handler(...$payload);
        }

        return $results;
    }

    public function concat(string $hook, mixed ...$payload): string
    {
        $parts = [];
        foreach ($this->run($hook, ...$payload) as $result) {
            if (is_string($result) && $result !== '') {
                $parts[] = $result;
            }
        }

        return implode("\n", $parts);
    }
}
