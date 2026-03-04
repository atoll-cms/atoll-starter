<?php

declare(strict_types=1);

namespace Atoll\Backup;

use Atoll\Support\Config;
use ZipArchive;

final class BackupManager
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly string $sourceDir,
        private readonly string $backupDir,
        array $config = []
    ) {
        $this->config = $config;
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
}
