<?php

declare(strict_types=1);

namespace Atoll\Backup;

use Atoll\Support\Config;
use ZipArchive;

final class BackupManager
{
    /** @var array<string, mixed> */
    private array $config;
    private string $schedulerStateFile;

    public function __construct(
        private readonly string $sourceDir,
        private readonly string $backupDir,
        array $config = []
    ) {
        $this->config = $config;
        $siteRoot = dirname($this->sourceDir);
        $this->schedulerStateFile = rtrim($siteRoot, '/') . '/content/data/backup-scheduler.json';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }
    }

    /** @return array<string, mixed> */
    public function create(): array
    {
        $filename = 'backup-' . date('Ymd-His') . '.zip';
        $path = rtrim($this->backupDir, '/') . '/' . $filename;

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return ['ok' => false, 'error' => 'Could not create zip'];
        }

        $source = realpath($this->sourceDir);
        if ($source === false) {
            return ['ok' => false, 'error' => 'Source missing'];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $real = $item->getRealPath();
            if ($real === false) {
                continue;
            }
            $relative = substr($real, strlen($source) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($real, $relative);
            }
        }

        $zip->close();

        $uploads = [];
        $errors = [];

        $s3 = Config::get($this->config, 'backup.targets.s3', []);
        if (is_array($s3) && (bool) ($s3['enabled'] ?? false)) {
            try {
                $uploads[] = [
                    'target' => 's3',
                    'location' => $this->uploadToS3($path, $filename, $s3),
                    'ok' => true,
                ];
            } catch (\Throwable $e) {
                $errors[] = 's3: ' . $e->getMessage();
            }
        }

        $sftp = Config::get($this->config, 'backup.targets.sftp', []);
        if (is_array($sftp) && (bool) ($sftp['enabled'] ?? false)) {
            try {
                $uploads[] = [
                    'target' => 'sftp',
                    'location' => $this->uploadToSftp($path, $filename, $sftp),
                    'ok' => true,
                ];
            } catch (\Throwable $e) {
                $errors[] = 'sftp: ' . $e->getMessage();
            }
        }

        return [
            'ok' => true,
            'partial' => $errors !== [],
            'errors' => $errors,
            'uploads' => $uploads,
            'file' => '/backups/' . $filename,
            'path' => $path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulerStatus(): array
    {
        $schedule = $this->normalizedSchedule();
        $state = $this->loadSchedulerState();
        $now = new \DateTimeImmutable('now', $this->timezone());
        $lastSlot = $this->lastScheduledSlot($now, $schedule);
        $nextSlot = $this->nextScheduledSlot($now, $schedule);

        $lastSuccessAt = trim((string) ($state['last_success_at'] ?? ''));
        $lastSuccessTs = $lastSuccessAt !== '' ? strtotime($lastSuccessAt) : false;
        $due = (bool) $schedule['enabled'];
        if ($due && $lastSlot !== null) {
            $due = $lastSuccessTs === false || $lastSuccessTs < $lastSlot->getTimestamp();
        }
        $nextDueAt = (bool) $schedule['enabled'] ? ($nextSlot?->format('c') ?? '') : '';

        return [
            'enabled' => (bool) $schedule['enabled'],
            'frequency' => (string) $schedule['frequency'],
            'time' => (string) $schedule['time'],
            'weekday' => (int) $schedule['weekday'],
            'timezone' => $this->timezone()->getName(),
            'due' => $due,
            'last_run_at' => (string) ($state['last_run_at'] ?? ''),
            'last_success_at' => $lastSuccessAt,
            'last_error_at' => (string) ($state['last_error_at'] ?? ''),
            'last_error' => (string) ($state['last_error'] ?? ''),
            'last_duration_ms' => (int) ($state['last_duration_ms'] ?? 0),
            'last_target' => (string) ($state['last_target'] ?? ''),
            'last_file' => (string) ($state['last_file'] ?? ''),
            'next_due_at' => $nextDueAt,
            'runs' => is_array($state['runs'] ?? null) ? array_values($state['runs']) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runScheduled(bool $force = false): array
    {
        $status = $this->schedulerStatus();
        if (!$force && !($status['enabled'] ?? false)) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'disabled',
                'status' => $status,
            ];
        }
        if (!$force && !($status['due'] ?? false)) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'not_due',
                'status' => $status,
            ];
        }

        $startedAt = date('c');
        $start = microtime(true);
        $result = $this->create();
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $ok = (bool) ($result['ok'] ?? false);
        $target = $this->targetSummary($result);
        $error = '';
        if (!$ok) {
            $error = trim((string) ($result['error'] ?? 'Backup failed'));
        } elseif ((bool) ($result['partial'] ?? false)) {
            $error = implode('; ', array_map('strval', $result['errors'] ?? []));
        }

        $state = $this->loadSchedulerState();
        $state['last_run_at'] = $startedAt;
        $state['last_duration_ms'] = $durationMs;
        $state['last_target'] = $target;
        $state['last_file'] = (string) ($result['file'] ?? '');
        if ($ok) {
            $state['last_success_at'] = $startedAt;
            if ($error !== '') {
                $state['last_error_at'] = $startedAt;
                $state['last_error'] = $error;
            } else {
                $state['last_error_at'] = '';
                $state['last_error'] = '';
            }
        } else {
            $state['last_error_at'] = $startedAt;
            $state['last_error'] = $error;
        }

        $runs = is_array($state['runs'] ?? null) ? array_values($state['runs']) : [];
        array_unshift($runs, [
            'started_at' => $startedAt,
            'ok' => $ok,
            'partial' => (bool) ($result['partial'] ?? false),
            'duration_ms' => $durationMs,
            'target' => $target,
            'file' => (string) ($result['file'] ?? ''),
            'error' => $error,
        ]);
        $state['runs'] = array_slice($runs, 0, 30);
        $this->writeSchedulerState($state);

        return [
            ...$result,
            'skipped' => false,
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'target' => $target,
            'status' => $this->schedulerStatus(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function uploadToS3(string $localFile, string $filename, array $config): string
    {
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $region = trim((string) ($config['region'] ?? 'eu-central-1'));
        $accessKey = trim((string) ($config['access_key'] ?? ''));
        $secretKey = (string) ($config['secret_key'] ?? '');
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $prefix = trim((string) ($config['prefix'] ?? 'atoll-backups'), '/');
        $pathStyle = (bool) ($config['path_style'] ?? true);

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new \RuntimeException('bucket/access_key/secret_key are required');
        }

        if ($endpoint === '') {
            $endpoint = 'https://s3.' . $region . '.amazonaws.com';
        }

        $endpointParts = parse_url($endpoint);
        if (!is_array($endpointParts) || !isset($endpointParts['host'])) {
            throw new \RuntimeException('invalid endpoint');
        }

        $scheme = (string) ($endpointParts['scheme'] ?? 'https');
        $host = (string) $endpointParts['host'];
        $basePath = trim((string) ($endpointParts['path'] ?? ''), '/');
        $key = ($prefix !== '' ? $prefix . '/' : '') . date('Y/m') . '/' . $filename;

        $segments = array_map('rawurlencode', explode('/', $key));
        $encodedKey = implode('/', $segments);
        $encodedBucket = rawurlencode($bucket);

        if ($pathStyle) {
            $canonicalUri = '/' . ($basePath !== '' ? $basePath . '/' : '') . $encodedBucket . '/' . $encodedKey;
            $url = $scheme . '://' . $host . $canonicalUri;
        } else {
            $virtualHost = $bucket . '.' . $host;
            $canonicalUri = '/' . ($basePath !== '' ? $basePath . '/' : '') . $encodedKey;
            $url = $scheme . '://' . $virtualHost . $canonicalUri;
            $host = $virtualHost;
        }

        $payload = (string) file_get_contents($localFile);
        $payloadHash = hash('sha256', $payload);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $service = 's3';
        $scope = "{$dateStamp}/{$region}/{$service}/aws4_request";

        $canonicalHeaders = "host:{$host}\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->awsSignatureKey($secretKey, $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $headers = [
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Content-Type: application/zip',
            'Content-Length: ' . strlen($payload),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException('network error while uploading');
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new \RuntimeException('invalid HTTP response');
        }
        $statusCode = (int) ($matches[1] ?? 0);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('HTTP ' . $statusCode);
        }

        return 's3://' . $bucket . '/' . $key;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function uploadToSftp(string $localFile, string $filename, array $config): string
    {
        if (!function_exists('ssh2_connect')) {
            throw new \RuntimeException('ext-ssh2 is not installed');
        }

        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 22);
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $remoteRoot = trim((string) ($config['path'] ?? '/backups/atoll'));
        $privateKeyFile = trim((string) ($config['private_key_file'] ?? ''));
        $publicKeyFile = trim((string) ($config['public_key_file'] ?? ''));
        $passphrase = (string) ($config['passphrase'] ?? '');

        if ($host === '' || $username === '') {
            throw new \RuntimeException('host and username are required');
        }

        $connection = @ssh2_connect($host, $port);
        if (!is_resource($connection)) {
            throw new \RuntimeException('connect failed');
        }

        $authed = false;
        if ($privateKeyFile !== '' && $publicKeyFile !== '') {
            $authed = @ssh2_auth_pubkey_file($connection, $username, $publicKeyFile, $privateKeyFile, $passphrase);
        } elseif ($password !== '') {
            $authed = @ssh2_auth_password($connection, $username, $password);
        }

        if (!$authed) {
            throw new \RuntimeException('authentication failed');
        }

        $sftp = @ssh2_sftp($connection);
        if (!is_resource($sftp)) {
            throw new \RuntimeException('sftp init failed');
        }

        $remoteDir = rtrim($remoteRoot, '/') . '/' . date('Y/m');
        $this->ensureSftpDirectory($connection, $remoteDir);

        $remotePath = $remoteDir . '/' . $filename;
        $uri = 'ssh2.sftp://' . intval($sftp) . $remotePath;

        $stream = @fopen($uri, 'w');
        if ($stream === false) {
            throw new \RuntimeException('could not open remote file');
        }

        $payload = (string) file_get_contents($localFile);
        $written = fwrite($stream, $payload);
        fclose($stream);

        if ($written === false || $written < strlen($payload)) {
            throw new \RuntimeException('incomplete upload');
        }

        return 'sftp://' . $host . $remotePath;
    }

    private function ensureSftpDirectory(mixed $connection, string $directory): void
    {
        $parts = array_values(array_filter(explode('/', trim($directory, '/'))));
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            $command = 'mkdir -p ' . escapeshellarg($current);
            $stream = @ssh2_exec($connection, $command);
            if (is_resource($stream)) {
                stream_set_blocking($stream, true);
                stream_get_contents($stream);
                fclose($stream);
            }
        }
    }

    private function awsSignatureKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedSchedule(): array
    {
        $schedule = Config::get($this->config, 'backup.schedule', []);
        if (!is_array($schedule)) {
            $schedule = [];
        }

        $frequency = strtolower(trim((string) ($schedule['frequency'] ?? 'daily')));
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            $frequency = 'daily';
        }

        $time = trim((string) ($schedule['time'] ?? '03:00'));
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '03:00';
        }

        $weekday = (int) ($schedule['weekday'] ?? 1);
        if ($weekday < 1 || $weekday > 7) {
            $weekday = 1;
        }

        return [
            'enabled' => (bool) ($schedule['enabled'] ?? false),
            'frequency' => $frequency,
            'time' => $time,
            'weekday' => $weekday,
        ];
    }

    private function timezone(): \DateTimeZone
    {
        $tz = trim((string) Config::get($this->config, 'timezone', date_default_timezone_get()));
        try {
            return new \DateTimeZone($tz !== '' ? $tz : date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    private function lastScheduledSlot(\DateTimeImmutable $now, array $schedule): ?\DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) ($schedule['time'] ?? '03:00')));
        if (($schedule['frequency'] ?? 'daily') === 'weekly') {
            $weekday = (int) ($schedule['weekday'] ?? 1);
            $currentDow = (int) $now->format('N');
            $delta = $weekday - $currentDow;
            $slot = $now->modify(($delta >= 0 ? '+' : '') . $delta . ' days')->setTime($hour, $minute);
            if ($slot > $now) {
                $slot = $slot->modify('-7 days');
            }
            return $slot;
        }

        $slot = $now->setTime($hour, $minute);
        if ($slot > $now) {
            $slot = $slot->modify('-1 day');
        }
        return $slot;
    }

    private function nextScheduledSlot(\DateTimeImmutable $now, array $schedule): ?\DateTimeImmutable
    {
        $last = $this->lastScheduledSlot($now, $schedule);
        if ($last === null) {
            return null;
        }
        if (($schedule['frequency'] ?? 'daily') === 'weekly') {
            return $last->modify('+7 days');
        }
        return $last->modify('+1 day');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchedulerState(): array
    {
        if (!is_file($this->schedulerStateFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->schedulerStateFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeSchedulerState(array $state): void
    {
        if (!is_dir(dirname($this->schedulerStateFile))) {
            mkdir(dirname($this->schedulerStateFile), 0775, true);
        }
        file_put_contents($this->schedulerStateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @param array<string, mixed> $result
     */
    private function targetSummary(array $result): string
    {
        $targets = ['local'];
        foreach (($result['uploads'] ?? []) as $upload) {
            if (!is_array($upload) || !(bool) ($upload['ok'] ?? false)) {
                continue;
            }
            $target = trim((string) ($upload['target'] ?? ''));
            if ($target !== '') {
                $targets[] = $target;
            }
        }

        $targets = array_values(array_unique($targets));
        sort($targets);
        return implode('+', $targets);
    }
}
