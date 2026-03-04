<?php

declare(strict_types=1);

namespace Atoll\Form;

use Atoll\Hooks\HookManager;
use Atoll\Http\Request;
use Atoll\Mail\Mailer;
use Atoll\Security\SecurityManager;
use Atoll\Support\Yaml;

final class FormManager
{
    public function __construct(
        private readonly string $formsDir,
        private readonly string $submissionsDir,
        private readonly Mailer $mailer,
        private readonly SecurityManager $security,
        private readonly HookManager $hooks
    ) {
        if (!is_dir($this->submissionsDir)) {
            mkdir($this->submissionsDir, 0775, true);
        }
    }

    /** @return array<string, mixed> */
    public function submit(string $name, Request $request): array
    {
        $configPath = rtrim($this->formsDir, '/') . '/' . $name . '.yaml';
        if (!is_file($configPath)) {
            return ['ok' => false, 'error' => 'Form not found'];
        }

        $config = Yaml::parse((string) file_get_contents($configPath));
        $payload = $request->isJson() ? $request->json() : $request->post;

        if (!$this->security->validateCsrf((string) ($payload['_csrf'] ?? ''))) {
            return ['ok' => false, 'error' => 'Invalid CSRF token'];
        }

        $honeypotField = (string) ($config['honeypot'] ?? '__website');
        if (!empty($payload[$honeypotField])) {
            return ['ok' => false, 'error' => 'Spam detected'];
        }

        $errors = [];
        $fields = (array) ($config['fields'] ?? []);

        foreach ($fields as $field => $rules) {
            if (!is_array($rules)) {
                continue;
            }

            $value = trim((string) ($payload[$field] ?? ''));
            if (($rules['required'] ?? false) && $value === '') {
                $errors[$field] = 'required';
                continue;
            }

            if (($rules['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = 'invalid_email';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'error' => 'Validation failed', 'fields' => $errors];
        }

        $record = [
            'timestamp' => date('c'),
            'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
            'payload' => array_filter($payload, static fn ($key) => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY),
        ];

        file_put_contents(
            rtrim($this->submissionsDir, '/') . '/' . $name . '.jsonl',
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );

        $recipient = (string) ($config['mail']['to'] ?? 'admin@example.com');
        $subject = (string) ($config['mail']['subject'] ?? 'New form submission: ' . $name);
        $body = (string) ($config['mail']['template'] ?? json_encode($record['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->mailer->send($recipient, $subject, $body, array_map('strval', $record['payload']));

        $this->hooks->run('form:submitted', [
            'form' => $name,
            'record' => $record,
            'config' => $config,
        ]);

        return ['ok' => true, 'message' => (string) ($config['success_message'] ?? 'Thank you.')];
    }

    /** @return array<int, array<string, mixed>> */
    public function submissions(string $name): array
    {
        $file = rtrim($this->submissionsDir, '/') . '/' . $name . '.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return array_reverse($rows);
    }
}
