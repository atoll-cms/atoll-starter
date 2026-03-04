<?php

declare(strict_types=1);

namespace Atoll\Form;

use Atoll\Hooks\HookManager;
use Atoll\Http\Request;
use Atoll\Mail\Mailer;
use Atoll\Security\SecurityManager;
use Atoll\Support\Config;
use Atoll\Support\Yaml;

final class FormManager
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly string $formsDir,
        private readonly string $submissionsDir,
        private readonly Mailer $mailer,
        private readonly SecurityManager $security,
        private readonly HookManager $hooks,
        array $config = []
    ) {
        $this->config = $config;
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

        $spamError = $this->validateAntiSpam($config, $payload, $request);
        if ($spamError !== null) {
            return ['ok' => false, 'error' => $spamError];
        }

        $captchaError = $this->validateCaptcha($config, $payload, $request);
        if ($captchaError !== null) {
            return ['ok' => false, 'error' => $captchaError];
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

    /**
     * @param array<string, mixed> $formConfig
     * @param array<string, mixed> $payload
     */
    private function validateAntiSpam(array $formConfig, array $payload, Request $request): ?string
    {
        $global = Config::get($this->config, 'security.forms.anti_spam', []);
        $local = $formConfig['anti_spam'] ?? [];
        if (!is_array($global)) {
            $global = [];
        }
        if (!is_array($local)) {
            $local = [];
        }
        $rules = array_merge($global, $local);

        $minSeconds = max(0, (int) ($rules['min_seconds'] ?? 0));
        $timestampField = trim((string) ($rules['timestamp_field'] ?? '_atoll_ts'));
        $requireTimestamp = (bool) ($rules['require_timestamp'] ?? false);

        if ($minSeconds > 0 && $timestampField !== '') {
            $submittedAtRaw = $payload[$timestampField] ?? null;
            $submittedAt = null;
            if (is_string($submittedAtRaw) && trim($submittedAtRaw) !== '') {
                $raw = trim($submittedAtRaw);
                if (ctype_digit($raw)) {
                    $submittedAt = (int) $raw;
                } else {
                    $parsed = strtotime($raw);
                    if ($parsed !== false) {
                        $submittedAt = $parsed;
                    }
                }
            }

            if ($submittedAt === null) {
                if ($requireTimestamp) {
                    return 'Form timing validation failed';
                }
            } else {
                $now = time();
                if (($now - $submittedAt) < $minSeconds) {
                    return 'Spam detected';
                }
                if ($submittedAt > ($now + 60)) {
                    return 'Spam detected';
                }
            }
        }

        $maxLinks = (int) ($rules['max_links'] ?? 0);
        if ($maxLinks > 0) {
            $linkCount = 0;
            foreach ($payload as $key => $value) {
                if (!is_string($value) || str_starts_with((string) $key, '_')) {
                    continue;
                }
                $linkCount += preg_match_all('/(?:https?:\/\/|www\.)/iu', $value) ?: 0;
                if ($linkCount > $maxLinks) {
                    return 'Spam detected';
                }
            }
        }

        $blockedPhrases = $rules['blocked_phrases'] ?? [];
        if (is_array($blockedPhrases) && $blockedPhrases !== []) {
            $haystack = mb_strtolower(implode("\n", array_filter(
                array_map(
                    static fn ($v): string => is_string($v) ? $v : '',
                    $payload
                ),
                static fn (string $v): bool => $v !== ''
            )));
            foreach ($blockedPhrases as $phrase) {
                if (!is_string($phrase) || trim($phrase) === '') {
                    continue;
                }
                if (str_contains($haystack, mb_strtolower(trim($phrase)))) {
                    return 'Spam detected';
                }
            }
        }

        $disposableDomains = $rules['disposable_domains'] ?? [];
        $emailField = trim((string) ($rules['email_field'] ?? 'email'));
        if (is_array($disposableDomains) && $disposableDomains !== [] && $emailField !== '') {
            $emailValue = trim((string) ($payload[$emailField] ?? ''));
            if ($emailValue !== '' && str_contains($emailValue, '@')) {
                $domain = strtolower((string) substr(strrchr($emailValue, '@') ?: '', 1));
                foreach ($disposableDomains as $blockedDomain) {
                    if (!is_string($blockedDomain) || trim($blockedDomain) === '') {
                        continue;
                    }
                    $blockedDomain = strtolower(trim($blockedDomain));
                    if ($domain === $blockedDomain || str_ends_with($domain, '.' . $blockedDomain)) {
                        return 'Spam detected';
                    }
                }
            }
        }

        if ((bool) ($rules['require_user_agent'] ?? false)) {
            $ua = trim((string) ($request->server['HTTP_USER_AGENT'] ?? ''));
            if ($ua === '') {
                return 'Spam detected';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $formConfig
     * @param array<string, mixed> $payload
     */
    private function validateCaptcha(array $formConfig, array $payload, Request $request): ?string
    {
        $global = Config::get($this->config, 'security.forms.captcha', []);
        $local = $formConfig['captcha'] ?? [];
        if (!is_array($global)) {
            $global = [];
        }
        if (!is_array($local)) {
            $local = [];
        }

        $captcha = array_merge($global, $local);
        $enabled = (bool) ($captcha['enabled'] ?? false);
        if (!$enabled) {
            return null;
        }

        $provider = strtolower(trim((string) ($captcha['provider'] ?? 'turnstile')));
        $tokenField = trim((string) ($captcha['token_field'] ?? 'captcha_token'));
        $secret = $this->resolveSecret((string) ($captcha['secret'] ?? ''));
        if ($provider === '' || $tokenField === '' || $secret === '') {
            return 'Captcha configuration missing';
        }

        $token = trim((string) ($payload[$tokenField] ?? ''));
        if ($token === '') {
            return 'Captcha validation failed';
        }

        $endpoint = trim((string) ($captcha['verify_url'] ?? $this->captchaEndpoint($provider)));
        if ($endpoint === '') {
            return 'Captcha configuration invalid';
        }

        $postData = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                    . 'Content-Length: ' . strlen($postData) . "\r\n",
                'content' => $postData,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($endpoint, false, $context);
        if (!is_string($raw) || $raw === '') {
            return 'Captcha validation failed';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return 'Captcha validation failed';
        }

        $success = (bool) ($decoded['success'] ?? false);
        if (!$success) {
            return 'Captcha validation failed';
        }

        if ($provider === 'recaptcha') {
            $minScore = (float) ($captcha['minimum_score'] ?? 0.5);
            $score = (float) ($decoded['score'] ?? 0.0);
            if ($score > 0 && $score < $minScore) {
                return 'Captcha validation failed';
            }
        }

        return null;
    }

    private function captchaEndpoint(string $provider): string
    {
        return match ($provider) {
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'hcaptcha' => 'https://hcaptcha.com/siteverify',
            'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
            default => '',
        };
    }

    private function resolveSecret(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, 'env:')) {
            $envKey = trim(substr($trimmed, 4));
            if ($envKey === '') {
                return '';
            }

            $resolved = getenv($envKey);
            return is_string($resolved) ? trim($resolved) : '';
        }

        return $trimmed;
    }
}
