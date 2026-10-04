<?php

declare(strict_types=1);

namespace Atoll\Support;

use RuntimeException;
use ZipArchive;

final class PackageInstaller
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installPlugin(
        string $root,
        string $source,
        bool $force = false,
        bool $enable = false,
        array $config = [],
        ?string $targetId = null,
        array $registryEntry = []
    ): array {
        $sourceDir = self::resolveInstallSource($root, $source, 'plugin', $config);
        $manifestPath = $sourceDir . '/plugin.php';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Invalid plugin package: plugin.php missing at root.');
        }

        $id = self::normalizePackageId($targetId ?? basename($sourceDir));
        self::assertPluginCompatibility($root, $id, $manifestPath, $config, $registryEntry);
        $dest = rtrim($root, '/') . '/plugins/' . $id;
        $sourceReal = realpath($sourceDir);
        $destReal = realpath($dest);

        if (!($sourceReal !== false && $destReal !== false && $sourceReal === $destReal)) {
            self::prepareDestination($dest, $force, "Plugin already exists: {$id} (use --force to overwrite)");
            self::copyDirectory($sourceDir, $dest);
        }

        self::applyPluginEnabledState($root, $id, $enable);

        return [
            'ok' => true,
            'id' => $id,
            'path' => $dest,
            'enabled' => $enable,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installTheme(
        string $root,
        string $source,
        bool $force = false,
        array $config = [],
        ?string $targetId = null
    ): array {
        $sourceDir = self::resolveInstallSource($root, $source, 'theme', $config);
        if (!is_dir($sourceDir . '/templates')) {
            throw new RuntimeException('Invalid theme package: templates/ missing at root.');
        }

        $id = self::normalizePackageId($targetId ?? basename($sourceDir));
        $dest = rtrim($root, '/') . '/themes/' . $id;
        $sourceReal = realpath($sourceDir);
        $destReal = realpath($dest);
        if (!($sourceReal !== false && $destReal !== false && $sourceReal === $destReal)) {
            self::prepareDestination($dest, $force, "Theme already exists: {$id} (use --force to overwrite)");
            self::copyDirectory($sourceDir, $dest);
        }

        return [
            'ok' => true,
            'id' => $id,
            'path' => $dest,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installPluginFromRegistry(
        string $root,
        string $id,
        bool $force = false,
        bool $enable = true,
        array $config = [],
        ?string $licenseKey = null
    ): array {
        $normalizedId = self::normalizePackageId($id);
        $entry = self::findRegistryEntry(rtrim($root, '/') . '/content/data/plugin-registry.json', $id);
        $localPlugin = self::resolveLocalPluginRepository($root, $normalizedId, $config);
        if ($localPlugin !== null) {
            $resolved = self::resolveRegistryInstallSource($root, $entry, 'plugins', $normalizedId, $licenseKey);
            self::assertPluginCompatibility($root, $normalizedId, $localPlugin . '/plugin.php', $config, $entry);
            $result = self::linkPluginFromLocalRepository($root, $normalizedId, $localPlugin, $force, $enable);
            if ($resolved['license_key'] !== null) {
                $result['license_saved'] = true;
            }
            if (($resolved['requires_license'] ?? false) === true) {
                $result['requires_license'] = true;
            }

            return $result;
        }

        $resolved = self::resolveRegistryInstallSource($root, $entry, 'plugins', $normalizedId, $licenseKey);
        $result = self::installPlugin($root, $resolved['source'], $force, $enable, $config, $normalizedId, $entry);
        if ($resolved['license_key'] !== null) {
            $result['license_saved'] = true;
        }
        if (($resolved['requires_license'] ?? false) === true) {
            $result['requires_license'] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installThemeFromRegistry(
        string $root,
        string $id,
        bool $force = false,
        array $config = [],
        ?string $licenseKey = null
    ): array {
        $normalizedId = self::normalizePackageId($id);
        $localTheme = self::resolveLocalThemeRepository($root, $normalizedId, $config);
        if ($localTheme !== null) {
            if ($licenseKey !== null && trim($licenseKey) !== '') {
                self::setStoredLicense($root, 'themes', $normalizedId, trim($licenseKey));
            }
            return self::linkThemeFromLocalRepository($root, $normalizedId, $localTheme, $force);
        }

        $entry = self::findRegistryEntry(rtrim($root, '/') . '/content/data/theme-registry.json', $id);
        $resolved = self::resolveRegistryInstallSource($root, $entry, 'themes', $normalizedId, $licenseKey);
        $result = self::installTheme($root, $resolved['source'], $force, $config, $normalizedId);
        if ($resolved['license_key'] !== null) {
            $result['license_saved'] = true;
        }
        if (($resolved['requires_license'] ?? false) === true) {
            $result['requires_license'] = true;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function loadRegistry(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function purchaseMarketplaceItem(
        string $root,
        string $kind,
        string $id,
        string $buyerEmail,
        string $buyerName = ''
    ): array {
        $group = self::normalizeLicenseGroupKind($kind);
        $normalizedId = self::normalizePackageId($id);
        if ($normalizedId === '') {
            throw new RuntimeException('Marketplace item id cannot be empty.');
        }

        $email = strtolower(trim($buyerEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Valid buyer email is required.');
        }

        $registryEntry = self::findRegistryEntry(self::registryFileForGroup($root, $group), $normalizedId);
        $price = is_numeric($registryEntry['price_eur'] ?? null) ? (float) $registryEntry['price_eur'] : 0.0;
        $type = strtolower(trim((string) ($registryEntry['type'] ?? '')));
        $requiresLicense = (bool) ($registryEntry['requires_license'] ?? false);

        if (!$requiresLicense && $price <= 0 && $type !== 'marketplace') {
            throw new RuntimeException("Item '{$normalizedId}' is not configured as paid marketplace product.");
        }

        $licenseKey = '';
        $issued = self::loadMarketplaceIssuedLicenses($root);
        for ($i = 0; $i < 20; $i++) {
            $candidate = self::generateMarketplaceLicenseKey($group, $normalizedId);
            if (!isset($issued[$candidate])) {
                $licenseKey = $candidate;
                break;
            }
        }
        if ($licenseKey === '') {
            throw new RuntimeException('Could not allocate unique license key.');
        }

        $now = date('c');
        $ttlDays = is_numeric($registryEntry['license_ttl_days'] ?? null) ? (int) $registryEntry['license_ttl_days'] : 0;
        $expiresAt = $ttlDays > 0 ? date('c', time() + ($ttlDays * 86400)) : null;
        $record = [
            'license_key' => $licenseKey,
            'group' => $group,
            'id' => $normalizedId,
            'status' => 'active',
            'buyer_email' => $email,
            'buyer_name' => trim($buyerName),
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'price_eur' => round($price, 2),
            'seller' => (string) ($registryEntry['seller'] ?? 'unknown'),
        ];
        $issued[$licenseKey] = $record;
        self::saveMarketplaceIssuedLicenses($root, $issued);

        $order = [
            'order_id' => 'mkt_' . date('YmdHis') . '_' . random_int(1000, 9999),
            'created_at' => $now,
            'status' => 'paid',
            'group' => $group,
            'id' => $normalizedId,
            'name' => (string) ($registryEntry['name'] ?? $normalizedId),
            'price_eur' => round($price, 2),
            'currency' => (string) ($registryEntry['currency'] ?? 'EUR'),
            'buyer' => [
                'email' => $email,
                'name' => trim($buyerName),
            ],
            'license_key' => $licenseKey,
        ];
        self::appendMarketplaceOrder($root, $order);

        self::setStoredLicense($root, $group, $normalizedId, $licenseKey);

        return [
            'ok' => true,
            'order' => $order,
            'license_key' => $licenseKey,
            'product' => [
                'group' => $group,
                'id' => $normalizedId,
                'name' => (string) ($registryEntry['name'] ?? $normalizedId),
            ],
        ];
    }

    /**
     * @return array{ok:bool,valid:bool,reason?:string,record?:array<string,mixed>}
     */
    public static function verifyMarketplaceLicense(
        string $root,
        string $kind,
        string $id,
        string $licenseKey
    ): array {
        $group = self::normalizeLicenseGroupKind($kind);
        $normalizedId = self::normalizePackageId($id);
        $licenseKey = strtoupper(trim($licenseKey));
        if ($licenseKey === '') {
            return [
                'ok' => true,
                'valid' => false,
                'reason' => 'empty',
            ];
        }

        $issued = self::loadMarketplaceIssuedLicenses($root);
        $record = $issued[$licenseKey] ?? null;
        if (!is_array($record)) {
            return [
                'ok' => true,
                'valid' => false,
                'reason' => 'not_found',
            ];
        }

        $recordGroup = strtolower(trim((string) ($record['group'] ?? '')));
        $recordId = self::normalizePackageId((string) ($record['id'] ?? ''));
        if ($recordGroup !== $group || $recordId !== $normalizedId) {
            return [
                'ok' => true,
                'valid' => false,
                'reason' => 'mismatch',
            ];
        }

        $status = strtolower(trim((string) ($record['status'] ?? 'active')));
        if ($status !== 'active') {
            return [
                'ok' => true,
                'valid' => false,
                'reason' => 'inactive',
            ];
        }

        $expiresAt = trim((string) ($record['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts !== false && $ts < time()) {
                return [
                    'ok' => true,
                    'valid' => false,
                    'reason' => 'expired',
                ];
            }
        }

        return [
            'ok' => true,
            'valid' => true,
            'record' => [
                'group' => $group,
                'id' => $normalizedId,
                'issued_at' => (string) ($record['issued_at'] ?? ''),
                'expires_at' => (string) ($record['expires_at'] ?? ''),
                'buyer_email' => (string) ($record['buyer_email'] ?? ''),
                'buyer_name' => (string) ($record['buyer_name'] ?? ''),
                'price_eur' => (float) ($record['price_eur'] ?? 0),
                'seller' => (string) ($record['seller'] ?? ''),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function marketplaceOrders(string $root, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $file = self::marketplaceOrderFile($root);
        if (!is_file($file)) {
            return [];
        }

        $rows = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($rows === []) {
            return [];
        }

        $rows = array_slice($rows, -$limit);
        $orders = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row, true);
            if (is_array($decoded)) {
                $orders[] = $decoded;
            }
        }

        return array_reverse($orders);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveInstallSource(string $root, string $source, string $kind, array $config): string
    {
        $resolvedInput = trim($source);
        if ($resolvedInput === '') {
            throw new RuntimeException("{$kind} source cannot be empty.");
        }

        $cacheRoot = rtrim($root, '/') . '/cache';
        if (self::isRemoteSource($resolvedInput)) {
            $downloads = $cacheRoot . '/downloads';
            if (!is_dir($downloads)) {
                mkdir($downloads, 0775, true);
            }
            $resolvedInput = self::downloadSource($resolvedInput, $downloads, $config);
        } elseif (!str_starts_with($resolvedInput, '/')) {
            $resolvedInput = rtrim($root, '/') . '/' . ltrim($resolvedInput, '/');
        }

        $resolved = realpath($resolvedInput);
        if ($resolved === false) {
            throw new RuntimeException("{$kind} source not found: {$source}");
        }

        if (is_dir($resolved)) {
            return rtrim($resolved, '/');
        }

        if (!str_ends_with(strtolower($resolved), '.zip')) {
            throw new RuntimeException("{$kind} source must be a directory or .zip archive");
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required for zip installs.');
        }

        $extractRoot = self::createUniqueWorkDir($cacheRoot, '.' . $kind . '-install-');
        $zip = new ZipArchive();
        if ($zip->open($resolved) !== true) {
            throw new RuntimeException("Could not open zip archive: {$resolved}");
        }
        $zip->extractTo($extractRoot);
        $zip->close();

        $dirs = glob($extractRoot . '/*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) === 1) {
            return $dirs[0];
        }

        return $extractRoot;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function downloadSource(string $url, string $downloadDir, array $config): string
    {
        $timeout = (int) Config::get($config, 'updater.timeout_seconds', 20);
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'package.zip');
        $filename = $filename !== '' ? $filename : 'package.zip';
        $target = rtrim($downloadDir, '/') . '/' . $filename;

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ]);

        $binary = @file_get_contents($url, false, $context);
        if (!is_string($binary) || $binary === '') {
            throw new RuntimeException('Could not download package source: ' . $url);
        }

        file_put_contents($target, $binary);
        return $target;
    }

    private static function isRemoteSource(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $registryEntry
     */
    private static function assertPluginCompatibility(
        string $root,
        string $pluginId,
        string $manifestPath,
        array $config,
        array $registryEntry = []
    ): void {
        $manifestRequirements = self::parsePluginManifestRequirements($manifestPath);
        $requiresPhp = self::mergeVersionConstraints(
            (string) ($manifestRequirements['requires_php'] ?? ''),
            (string) ($registryEntry['requires_php'] ?? '')
        );
        $requiresCore = self::mergeVersionConstraints(
            (string) ($manifestRequirements['requires_core'] ?? ''),
            (string) ($registryEntry['requires_core'] ?? '')
        );

        if ($requiresPhp !== '' && !self::matchesVersionConstraint(PHP_VERSION, $requiresPhp)) {
            throw new RuntimeException(
                sprintf(
                    "Plugin '%s' requires PHP %s (current: %s).",
                    $pluginId,
                    $requiresPhp,
                    PHP_VERSION
                )
            );
        }

        if ($requiresCore !== '') {
            $coreVersion = self::currentCoreVersion($root, $config);
            if (!self::matchesVersionConstraint($coreVersion, $requiresCore)) {
                throw new RuntimeException(
                    sprintf(
                        "Plugin '%s' requires atoll-core %s (current: %s).",
                        $pluginId,
                        $requiresCore,
                        $coreVersion
                    )
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parsePluginManifestRequirements(string $manifestPath): array
    {
        if (!is_file($manifestPath)) {
            return [];
        }

        $raw = (string) file_get_contents($manifestPath);
        $requirements = [];
        foreach (['requires_php', 'requires_core'] as $field) {
            if (preg_match('/[\'"]' . preg_quote($field, '/') . '[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $raw, $match) === 1) {
                $value = trim((string) ($match[1] ?? ''));
                if ($value !== '') {
                    $requirements[$field] = $value;
                }
            }
        }

        return $requirements;
    }

    private static function mergeVersionConstraints(string $first, string $second): string
    {
        $parts = [];
        $first = trim($first);
        $second = trim($second);
        if ($first !== '') {
            $parts[] = $first;
        }
        if ($second !== '') {
            $parts[] = $second;
        }

        return implode(',', $parts);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function currentCoreVersion(string $root, array $config): string
    {
        $configured = Config::get($config, 'core.path', 'core');
        $corePath = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'core';
        if (!str_starts_with($corePath, '/')) {
            $corePath = rtrim($root, '/') . '/' . ltrim($corePath, '/');
        }

        $candidates = [
            rtrim($corePath, '/') . '/VERSION',
            rtrim($root, '/') . '/core/VERSION',
        ];
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $version = trim((string) file_get_contents($file));
            if ($version !== '') {
                return $version;
            }
        }

        return '0.0.0';
    }

    private static function matchesVersionConstraint(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return true;
        }

        $parts = preg_split('/\s*,\s*|\s+/', $constraint) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/^(>=|<=|>|<|==|=|!=)\s*(.+)$/', $part, $match) === 1) {
                $op = $match[1] === '=' ? '==' : $match[1];
                $target = trim((string) ($match[2] ?? ''));
                if ($target === '' || !version_compare($version, $target, $op)) {
                    return false;
                }
                continue;
            }

            if (!version_compare($version, $part, '==')) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeLicenseGroupKind(string $kind): string
    {
        $normalized = strtolower(trim($kind));
        return match ($normalized) {
            'plugin', 'plugins' => 'plugins',
            'theme', 'themes' => 'themes',
            default => throw new RuntimeException('Invalid marketplace kind: ' . $kind),
        };
    }

    private static function registryFileForGroup(string $root, string $group): string
    {
        return rtrim($root, '/') . '/content/data/' . ($group === 'plugins' ? 'plugin-registry.json' : 'theme-registry.json');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadMarketplaceIssuedLicenses(string $root): array
    {
        $file = self::marketplaceLicenseFile($root);
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parse((string) file_get_contents($file));
        if (!is_array($parsed)) {
            return [];
        }

        $raw = $parsed['issued'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $licenseKey => $record) {
            if (!is_string($licenseKey) || !is_array($record)) {
                continue;
            }
            $rows[strtoupper(trim($licenseKey))] = $record;
        }
        ksort($rows);
        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $issued
     */
    private static function saveMarketplaceIssuedLicenses(string $root, array $issued): void
    {
        $normalized = [];
        foreach ($issued as $licenseKey => $record) {
            if (!is_string($licenseKey) || !is_array($record)) {
                continue;
            }
            $normalized[strtoupper(trim($licenseKey))] = $record;
        }
        ksort($normalized);

        $file = self::marketplaceLicenseFile($root);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, Yaml::dump(['issued' => $normalized]));
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function appendMarketplaceOrder(string $root, array $order): void
    {
        $file = self::marketplaceOrderFile($root);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents(
            $file,
            (string) json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }

    private static function generateMarketplaceLicenseKey(string $group, string $id): string
    {
        $prefix = $group === 'plugins' ? 'PLG' : 'THM';
        $slug = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($id)) ?: 'ITEM', 0, 6));
        $random = strtoupper(bin2hex(random_bytes(5)));
        $checksum = strtoupper(substr(sha1($group . ':' . $id . ':' . $random), 0, 6));
        return sprintf('ATOLL-%s-%s-%s-%s', $prefix, str_pad($slug, 6, 'X'), $random, $checksum);
    }

    private static function marketplaceLicenseFile(string $root): string
    {
        return rtrim($root, '/') . '/content/data/marketplace-licenses.yaml';
    }

    private static function marketplaceOrderFile(string $root): string
    {
        return rtrim($root, '/') . '/content/data/marketplace-orders.jsonl';
    }

    /**
     * @return array<string, mixed>
     */
    private static function findRegistryEntry(string $file, string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RuntimeException('Registry id cannot be empty.');
        }

        $registry = self::loadRegistry($file);
        foreach ($registry as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        throw new RuntimeException("Registry entry not found: {$id}");
    }

    private static function prepareDestination(string $destination, bool $force, string $existsError): void
    {
        if (!file_exists($destination) && !is_link($destination)) {
            return;
        }

        if (!$force) {
            throw new RuntimeException($existsError);
        }

        if (is_link($destination) || is_file($destination)) {
            @unlink($destination);
            return;
        }

        self::rrmdir($destination);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{source: string, license_key: ?string, requires_license: bool}
     */
    private static function resolveRegistryInstallSource(
        string $root,
        array $entry,
        string $licenseGroup,
        string $id,
        ?string $licenseKey
    ): array {
        $providedLicense = trim((string) ($licenseKey ?? ''));
        $storedLicense = self::getStoredLicense($root, $licenseGroup, $id);
        $effectiveLicense = $providedLicense !== '' ? $providedLicense : $storedLicense;

        $requiresLicense = (bool) ($entry['requires_license'] ?? false);
        $source = trim((string) ($entry['source'] ?? ''));
        if ($source === '') {
            $source = trim((string) ($entry['source_url'] ?? ''));
        }
        if ($source === '') {
            $source = trim((string) ($entry['download_url'] ?? ''));
        }
        if ($source === '') {
            $source = trim((string) ($entry['source_template'] ?? ''));
        }
        if ($source === '') {
            $source = trim((string) ($entry['source_url_template'] ?? ''));
        }
        if ($source === '') {
            $source = trim((string) ($entry['download_url_template'] ?? ''));
        }
        if ($source === '') {
            throw new RuntimeException("Registry entry '{$id}' has no source.");
        }

        if (str_contains($source, '{license_key}')) {
            $requiresLicense = true;
            if ($effectiveLicense === '') {
                throw new RuntimeException("License key required for '{$id}'.");
            }
            $source = str_replace('{license_key}', rawurlencode($effectiveLicense), $source);
        } elseif ($requiresLicense && $effectiveLicense === '') {
            throw new RuntimeException("License key required for '{$id}'.");
        }

        $licenseProvider = strtolower(trim((string) ($entry['license_provider'] ?? 'atoll')));
        if ($requiresLicense && $effectiveLicense !== '' && $licenseProvider !== 'external') {
            $verification = self::verifyMarketplaceLicense($root, $licenseGroup, $id, $effectiveLicense);
            if (($verification['valid'] ?? false) !== true) {
                $reason = (string) ($verification['reason'] ?? 'invalid_license');
                throw new RuntimeException("Invalid license key for '{$id}' ({$reason}).");
            }
        }

        if ($providedLicense !== '') {
            self::setStoredLicense($root, $licenseGroup, $id, $providedLicense);
            $effectiveLicense = $providedLicense;
        }

        return [
            'source' => $source,
            'license_key' => $effectiveLicense !== '' ? $effectiveLicense : null,
            'requires_license' => $requiresLicense,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function loadLicenses(string $root): array
    {
        $file = self::licenseFile($root);
        if (!is_file($file)) {
            return ['plugins' => [], 'themes' => []];
        }

        $data = Yaml::parse((string) file_get_contents($file));
        if (!is_array($data)) {
            return ['plugins' => [], 'themes' => []];
        }

        $normalizeGroup = static function (mixed $group): array {
            if (!is_array($group)) {
                return [];
            }
            $rows = [];
            foreach ($group as $id => $key) {
                if (!is_string($id)) {
                    continue;
                }
                $license = trim((string) $key);
                if ($license !== '') {
                    $rows[$id] = $license;
                }
            }
            ksort($rows);
            return $rows;
        };

        return [
            'plugins' => $normalizeGroup($data['plugins'] ?? []),
            'themes' => $normalizeGroup($data['themes'] ?? []),
        ];
    }

    public static function setStoredLicense(string $root, string $group, string $id, string $licenseKey): void
    {
        $group = strtolower(trim($group));
        if (!in_array($group, ['plugins', 'themes'], true)) {
            throw new RuntimeException('Invalid license group: ' . $group);
        }

        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            throw new RuntimeException('License key cannot be empty.');
        }

        $licenses = self::loadLicenses($root);
        $licenses[$group][$id] = $licenseKey;
        self::saveLicenses($root, $licenses);
    }

    public static function getStoredLicense(string $root, string $group, string $id): string
    {
        $group = strtolower(trim($group));
        if (!in_array($group, ['plugins', 'themes'], true)) {
            return '';
        }

        $licenses = self::loadLicenses($root);
        return trim((string) ($licenses[$group][$id] ?? ''));
    }

    /**
     * @param array<string, array<string, string>> $licenses
     */
    private static function saveLicenses(string $root, array $licenses): void
    {
        $normalized = [
            'plugins' => is_array($licenses['plugins'] ?? null) ? $licenses['plugins'] : [],
            'themes' => is_array($licenses['themes'] ?? null) ? $licenses['themes'] : [],
        ];

        $file = self::licenseFile($root);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, Yaml::dump($normalized));
    }

    private static function licenseFile(string $root): string
    {
        return rtrim($root, '/') . '/content/data/licenses.yaml';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveLocalThemeRepository(string $root, string $id, array $config): ?string
    {
        $envEnabled = getenv('ATOLL_DEV_LOCAL');
        if (!is_string($envEnabled) || trim($envEnabled) === '') {
            return null;
        }
        $envEnabledNormalized = strtolower(trim($envEnabled));
        if (in_array($envEnabledNormalized, ['0', 'false', 'off', 'no'], true)) {
            return null;
        }

        $workspaceEnv = getenv('ATOLL_DEV_LOCAL_WORKSPACE');
        $workspace = is_string($workspaceEnv) && trim($workspaceEnv) !== '' ? $workspaceEnv : '..';
        if ($workspace === '') {
            $workspace = '..';
        }

        $workspacePath = str_starts_with($workspace, '/')
            ? rtrim($workspace, '/')
            : rtrim($root, '/') . '/' . ltrim($workspace, '/');

        $candidate = rtrim($workspacePath, '/') . '/atoll-theme-' . $id;
        $real = realpath($candidate);
        if ($real === false || !is_dir($real) || !is_dir($real . '/templates')) {
            return null;
        }

        return rtrim($real, '/');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveLocalPluginRepository(string $root, string $id, array $config): ?string
    {
        $envEnabled = getenv('ATOLL_DEV_LOCAL');
        if (!is_string($envEnabled) || trim($envEnabled) === '') {
            return null;
        }
        $envEnabledNormalized = strtolower(trim($envEnabled));
        if (in_array($envEnabledNormalized, ['0', 'false', 'off', 'no'], true)) {
            return null;
        }

        $workspaceEnv = getenv('ATOLL_DEV_LOCAL_WORKSPACE');
        $workspace = is_string($workspaceEnv) && trim($workspaceEnv) !== '' ? $workspaceEnv : '..';
        if ($workspace === '') {
            $workspace = '..';
        }

        $workspacePath = str_starts_with($workspace, '/')
            ? rtrim($workspace, '/')
            : rtrim($root, '/') . '/' . ltrim($workspace, '/');

        $candidate = rtrim($workspacePath, '/') . '/atoll-plugin-' . $id;
        $real = realpath($candidate);
        if ($real === false || !is_dir($real) || !is_file($real . '/plugin.php')) {
            return null;
        }

        return rtrim($real, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkPluginFromLocalRepository(
        string $root,
        string $id,
        string $sourceDir,
        bool $force,
        bool $enable
    ): array {
        $dest = rtrim($root, '/') . '/plugins/' . $id;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }

        if (is_link($dest)) {
            $current = realpath($dest);
            if ($current !== false && $current === $sourceDir) {
                self::applyPluginEnabledState($root, $id, $enable);
                return [
                    'ok' => true,
                    'id' => $id,
                    'path' => $dest,
                    'enabled' => $enable,
                    'linked' => true,
                    'link_target' => $sourceDir,
                ];
            }

            if (!$force) {
                throw new RuntimeException("Plugin already exists: {$id} (use --force to overwrite)");
            }
            @unlink($dest);
        } elseif (file_exists($dest)) {
            if (!$force) {
                throw new RuntimeException("Plugin already exists: {$id} (use --force to overwrite)");
            }
            self::prepareDestination($dest, true, '');
        }

        if (!symlink($sourceDir, $dest)) {
            throw new RuntimeException("Could not create symlink for plugin '{$id}'");
        }

        self::applyPluginEnabledState($root, $id, $enable);

        return [
            'ok' => true,
            'id' => $id,
            'path' => $dest,
            'enabled' => $enable,
            'linked' => true,
            'link_target' => $sourceDir,
        ];
    }

    private static function applyPluginEnabledState(string $root, string $id, bool $enable): void
    {
        if (!$enable) {
            return;
        }

        $stateFile = rtrim($root, '/') . '/content/data/plugins.yaml';
        $state = is_file($stateFile) ? Yaml::parse((string) file_get_contents($stateFile)) : [];
        $state = is_array($state) ? $state : [];
        $state[$id] = true;

        if (!is_dir(dirname($stateFile))) {
            mkdir(dirname($stateFile), 0775, true);
        }
        file_put_contents($stateFile, Yaml::dump($state));
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkThemeFromLocalRepository(string $root, string $id, string $sourceDir, bool $force): array
    {
        $dest = rtrim($root, '/') . '/themes/' . $id;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }

        if (is_link($dest)) {
            $current = realpath($dest);
            if ($current !== false && $current === $sourceDir) {
                return [
                    'ok' => true,
                    'id' => $id,
                    'path' => $dest,
                    'linked' => true,
                    'link_target' => $sourceDir,
                ];
            }

            if (!$force) {
                throw new RuntimeException("Theme already exists: {$id} (use --force to overwrite)");
            }
            @unlink($dest);
        } elseif (file_exists($dest)) {
            if (!$force) {
                throw new RuntimeException("Theme already exists: {$id} (use --force to overwrite)");
            }
            self::prepareDestination($dest, true, '');
        }

        if (!symlink($sourceDir, $dest)) {
            throw new RuntimeException("Could not create symlink for theme '{$id}'");
        }

        return [
            'ok' => true,
            'id' => $id,
            'path' => $dest,
            'linked' => true,
            'link_target' => $sourceDir,
        ];
    }

    private static function normalizePackageId(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $value));
        $slug = trim($slug, '-._');
        return $slug !== '' ? $slug : 'package';
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('copyDirectory source missing: ' . $source);
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0775, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relative = substr($sourcePath, strlen($source) + 1);
            $targetPath = $destination . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0775, true);
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0775, true);
                }
                copy($sourcePath, $targetPath);
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private static function createUniqueWorkDir(string $parentDir, string $prefix): string
    {
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0775, true);
        }

        for ($i = 0; $i < 20; $i++) {
            $suffix = bin2hex(random_bytes(3));
            $candidate = rtrim($parentDir, '/') . '/' . $prefix . date('Ymd-His') . '-' . $suffix;
            if (!file_exists($candidate)) {
                mkdir($candidate, 0775, true);
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate temporary directory.');
    }
}
