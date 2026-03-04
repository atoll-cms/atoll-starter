<?php

declare(strict_types=1);

namespace Atoll\Redirect;

use Atoll\Support\Yaml;

final class RedirectManager
{
    /** @var array<int, array<string, mixed>> */
    private array $rules = [];

    public function __construct(private readonly string $redirectFile)
    {
        if (is_file($this->redirectFile)) {
            $parsed = Yaml::parse((string) file_get_contents($this->redirectFile));
            $this->rules = is_array($parsed) ? array_values($parsed) : [];
        }
    }

    /** @return array{to:string,status:int}|null */
    public function match(string $path): ?array
    {
        foreach ($this->rules as $rule) {
            $from = (string) ($rule['from'] ?? '');
            if ($from === '') {
                continue;
            }

            if (str_contains($from, '*')) {
                $regex = '#^' . str_replace('\*', '(.+)', preg_quote($from, '#')) . '$#';
                if (preg_match($regex, $path, $matches) === 1) {
                    $to = (string) ($rule['to'] ?? '/');
                    if (isset($matches[1])) {
                        $to = str_replace('$1', $matches[1], $to);
                    }

                    return [
                        'to' => $to,
                        'status' => (int) ($rule['status'] ?? 301),
                    ];
                }
                continue;
            }

            if ($from === $path) {
                return [
                    'to' => (string) ($rule['to'] ?? '/'),
                    'status' => (int) ($rule['status'] ?? 301),
                ];
            }
        }

        return null;
    }
}
