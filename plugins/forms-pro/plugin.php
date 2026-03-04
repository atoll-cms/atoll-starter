<?php

declare(strict_types=1);

return [
    'name' => 'forms-pro',
    'description' => 'Advanced form automation with webhook delivery',
    'version' => '0.1.0',
    'hooks' => [
        'form:submitted' => static function (array $payload): void {
            $config = \Atoll\Support\Config::load(dirname(__DIR__, 2) . '/config.yaml');
            $webhooks = \Atoll\Support\Config::get($config, 'forms_pro.webhooks', []);
            if (!is_array($webhooks) || $webhooks === []) {
                return;
            }

            $logFile = dirname(__DIR__, 2) . '/cache/forms-pro-webhooks.log';
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($jsonPayload)) {
                return;
            }

            foreach ($webhooks as $url) {
                if (!is_string($url) || $url === '') {
                    continue;
                }

                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/json\r\n",
                        'content' => $jsonPayload,
                        'timeout' => 6,
                        'ignore_errors' => true,
                    ],
                ]);

                $result = @file_get_contents($url, false, $context);
                $status = 0;
                foreach ($http_response_header ?? [] as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                        $status = (int) $m[1];
                        break;
                    }
                }

                $line = sprintf(
                    "[%s] webhook=%s status=%d ok=%s\n",
                    date('c'),
                    $url,
                    $status,
                    $result !== false ? '1' : '0'
                );
                file_put_contents($logFile, $line, FILE_APPEND);
            }
        },
    ],
    'routes' => [
        '/forms-pro/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'forms-pro',
            'features' => ['webhooks'],
        ],
        '/forms-pro/webhooks' => static function (): array {
            $logFile = dirname(__DIR__, 2) . '/cache/forms-pro-webhooks.log';
            if (!is_file($logFile)) {
                return ['ok' => true, 'entries' => []];
            }
            $rows = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            return ['ok' => true, 'entries' => array_slice(array_reverse($rows), 0, 200)];
        },
    ],
    'islands' => [],
];
