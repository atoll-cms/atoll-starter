#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', [
    'private-key:',
    'version:',
    'sha256:',
]);

$privateKeyPath = $options['private-key'] ?? null;
$version = $options['version'] ?? null;
$sha = $options['sha256'] ?? null;

if (!is_string($privateKeyPath) || !is_string($version) || !is_string($sha) || $privateKeyPath === '' || $version === '' || $sha === '') {
    fwrite(STDERR, "Usage: php core/tools/sign-release.php --private-key=<path> --version=<v> --sha256=<hash>\n");
    exit(1);
}

if (!is_file($privateKeyPath)) {
    fwrite(STDERR, "Private key not found: {$privateKeyPath}\n");
    exit(1);
}

if (!function_exists('openssl_sign')) {
    fwrite(STDERR, "OpenSSL extension is required.\n");
    exit(1);
}

$privateKey = (string) file_get_contents($privateKeyPath);
$payload = 'atoll-core|' . $version . '|' . strtolower($sha);

$signature = '';
$ok = openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
if ($ok !== true) {
    fwrite(STDERR, "Signing failed.\n");
    exit(1);
}

$base64 = base64_encode($signature);

echo "Payload: {$payload}\n";
echo "Signature (base64): {$base64}\n\n";

echo "JSON snippet:\n";
echo json_encode([
    'version' => $version,
    'artifact_sha256' => strtolower($sha),
    'signature_payload' => $payload,
    'signature' => $base64,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
