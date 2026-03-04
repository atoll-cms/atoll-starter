<?php

declare(strict_types=1);

namespace Atoll\Mail;

use Atoll\Support\Config;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string, string> $data */
    public function send(string $to, string $subject, string $body, array $data = []): bool
    {
        $driver = strtolower((string) Config::get($this->config, 'smtp.driver', 'mail'));
        $body = $this->interpolateTemplate($body, $data);

        return match ($driver) {
            'postmark' => $this->sendViaPostmark($to, $subject, $body),
            'mailgun' => $this->sendViaMailgun($to, $subject, $body),
            'ses' => $this->sendViaSesApi($to, $subject, $body),
            default => $this->sendViaPhpMailer($driver, $to, $subject, $body),
        };
    }

    private function sendViaPhpMailer(string $driver, string $to, string $subject, string $body): bool
    {
        if (!class_exists(PHPMailer::class)) {
            return mail($to, $subject, $body);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $fromEmail = (string) Config::get($this->config, 'smtp.from_email', 'noreply@example.com');
            $fromName = (string) Config::get($this->config, 'smtp.from_name', 'atoll-cms');
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);

            if ($driver === 'smtp') {
                $username = (string) Config::get($this->config, 'smtp.username', '');
                $password = (string) Config::get($this->config, 'smtp.password', '');
                $encryption = trim((string) Config::get($this->config, 'smtp.encryption', PHPMailer::ENCRYPTION_STARTTLS));

                $mail->isSMTP();
                $mail->Host = (string) Config::get($this->config, 'smtp.host', 'localhost');
                $mail->Port = max(1, (int) Config::get($this->config, 'smtp.port', 587));
                $mail->SMTPAuth = ($username !== '' || $password !== '');
                $mail->Username = $username;
                $mail->Password = $password;
                $mail->SMTPSecure = $encryption;
            } elseif ($driver === 'sendmail') {
                $mail->isSendmail();
                $sendmailPath = trim((string) Config::get($this->config, 'smtp.sendmail_path', ''));
                if ($sendmailPath !== '') {
                    $mail->Sendmail = $sendmailPath;
                }
            } else {
                $mail->isMail();
            }

            return $mail->send();
        } catch (\Throwable) {
            return false;
        }
    }

    private function sendViaPostmark(string $to, string $subject, string $body): bool
    {
        $token = $this->resolveSecret((string) Config::get($this->config, 'smtp.api.postmark.token', ''));
        $endpoint = trim((string) Config::get($this->config, 'smtp.api.postmark.endpoint', 'https://api.postmarkapp.com/email'));
        $fromEmail = (string) Config::get($this->config, 'smtp.from_email', 'noreply@example.com');
        $fromName = (string) Config::get($this->config, 'smtp.from_name', 'atoll-cms');
        $from = trim($fromName) !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;

        if ($token === '' || $endpoint === '') {
            return false;
        }

        $payload = json_encode([
            'From' => $from,
            'To' => $to,
            'Subject' => $subject,
            'TextBody' => $body,
            'MessageStream' => 'outbound',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return false;
        }

        return $this->sendJsonRequest(
            $endpoint,
            'POST',
            $payload,
            [
                'Accept: application/json',
                'X-Postmark-Server-Token: ' . $token,
            ]
        );
    }

    private function sendViaMailgun(string $to, string $subject, string $body): bool
    {
        $domain = trim((string) Config::get($this->config, 'smtp.api.mailgun.domain', ''));
        $apiKey = $this->resolveSecret((string) Config::get($this->config, 'smtp.api.mailgun.api_key', ''));
        $endpoint = trim((string) Config::get($this->config, 'smtp.api.mailgun.endpoint', ''));
        $fromEmail = (string) Config::get($this->config, 'smtp.from_email', 'noreply@example.com');
        $fromName = (string) Config::get($this->config, 'smtp.from_name', 'atoll-cms');
        $from = trim($fromName) !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;

        if ($apiKey === '') {
            return false;
        }

        if ($endpoint === '') {
            if ($domain === '') {
                return false;
            }
            $endpoint = 'https://api.mailgun.net/v3/' . rawurlencode($domain) . '/messages';
        }

        $payload = http_build_query([
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'text' => $body,
        ]);

        return $this->sendFormRequest(
            $endpoint,
            'POST',
            $payload,
            [
                'Authorization: Basic ' . base64_encode('api:' . $apiKey),
                'Accept: application/json',
            ]
        );
    }

    private function sendViaSesApi(string $to, string $subject, string $body): bool
    {
        $region = trim((string) Config::get($this->config, 'smtp.api.ses.region', 'eu-central-1'));
        $accessKey = trim((string) Config::get($this->config, 'smtp.api.ses.access_key', ''));
        $secretKey = $this->resolveSecret((string) Config::get($this->config, 'smtp.api.ses.secret_key', ''));
        $sessionToken = $this->resolveSecret((string) Config::get($this->config, 'smtp.api.ses.session_token', ''));
        $endpoint = trim((string) Config::get($this->config, 'smtp.api.ses.endpoint', ''));
        $fromEmail = (string) Config::get($this->config, 'smtp.from_email', 'noreply@example.com');

        if ($accessKey === '' || $secretKey === '') {
            return false;
        }

        if ($endpoint === '') {
            $endpoint = 'https://email.' . $region . '.amazonaws.com/v2/email/outbound-emails';
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) $parts['host'];
        $path = (string) ($parts['path'] ?? '/v2/email/outbound-emails');
        if ($path === '') {
            $path = '/v2/email/outbound-emails';
        }

        $payload = json_encode([
            'FromEmailAddress' => $fromEmail,
            'Destination' => [
                'ToAddresses' => [$to],
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                    'Body' => ['Text' => ['Data' => $body, 'Charset' => 'UTF-8']],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return false;
        }

        $url = $scheme . '://' . $host . $path;
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $payload);
        $service = 'ses';
        $scope = "{$dateStamp}/{$region}/{$service}/aws4_request";

        $headers = [
            'content-type' => 'application/json',
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($sessionToken !== '') {
            $headers['x-amz-security-token'] = $sessionToken;
        }

        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalUri = $this->awsCanonicalUri($path);
        $canonicalRequest = "POST\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->awsSignatureKey($secretKey, $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $requestHeaders = [
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Content-Type: application/json',
        ];
        if ($sessionToken !== '') {
            $requestHeaders[] = 'x-amz-security-token: ' . $sessionToken;
        }

        return $this->sendJsonRequest($url, 'POST', $payload, $requestHeaders);
    }

    private function sendJsonRequest(string $url, string $method, string $payload, array $headers): bool
    {
        $headers[] = 'Content-Length: ' . strlen($payload);
        return $this->sendHttpRequest($url, $method, $payload, $headers, 'application/json');
    }

    private function sendFormRequest(string $url, string $method, string $payload, array $headers): bool
    {
        $headers[] = 'Content-Length: ' . strlen($payload);
        return $this->sendHttpRequest($url, $method, $payload, $headers, 'application/x-www-form-urlencoded');
    }

    private function sendHttpRequest(
        string $url,
        string $method,
        string $payload,
        array $headers,
        string $contentType
    ): bool {
        $allHeaders = array_merge([
            'Content-Type: ' . $contentType,
        ], $headers);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $allHeaders),
                'content' => $payload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            return false;
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            return false;
        }

        $status = (int) ($matches[1] ?? 0);
        return $status >= 200 && $status < 300;
    }

    /** @param array<string, string> $data */
    private function interpolateTemplate(string $body, array $data): string
    {
        foreach ($data as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }

        return $body;
    }

    private function resolveSecret(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, 'env:')) {
            $name = trim(substr($trimmed, 4));
            if ($name === '') {
                return '';
            }

            $resolved = getenv($name);
            return is_string($resolved) ? trim($resolved) : '';
        }

        return $trimmed;
    }

    private function awsCanonicalUri(string $path): string
    {
        $segments = explode('/', $path);
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);
        $uri = implode('/', $encoded);
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        return $uri;
    }

    private function awsSignatureKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
