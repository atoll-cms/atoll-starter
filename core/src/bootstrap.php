<?php

declare(strict_types=1);

$selfCoreRoot = realpath(dirname(__DIR__));
$selfCoreRoot = $selfCoreRoot !== false ? $selfCoreRoot : dirname(__DIR__);
$siteRootCandidate = realpath(dirname(__DIR__, 2));

if ($siteRootCandidate !== false && is_file($siteRootCandidate . '/core/src/bootstrap.php')) {
    $readCorePathFromConfig = static function (string $configPath): ?string {
        if (!is_file($configPath)) {
            return null;
        }

        $lines = file($configPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return null;
        }

        $inCore = false;
        $coreIndent = 0;

        foreach ($lines as $line) {
            $line = rtrim((string) $line, "\r\n");
            if ($line === '' || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            if (
                !$inCore
                && preg_match('/^(\s*)core:\s*(?:#.*)?$/', $line, $matches) === 1
            ) {
                $inCore = true;
                $coreIndent = strlen((string) $matches[1]);
                continue;
            }

            if (!$inCore) {
                continue;
            }

            if (
                preg_match('/^(\s*)([A-Za-z0-9_.-]+):\s*(.*?)\s*(?:#.*)?$/', $line, $matches) !== 1
            ) {
                continue;
            }

            $indent = strlen((string) $matches[1]);
            if ($indent <= $coreIndent) {
                break;
            }

            if ($matches[2] !== 'path') {
                continue;
            }

            $raw = trim((string) $matches[3]);
            if ($raw === '') {
                return null;
            }

            if (
                (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
                || (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            ) {
                $raw = substr($raw, 1, -1);
            }

            return trim($raw);
        }

        return null;
    };

    $looksAbsolutePath = static function (string $path): bool {
        if ($path === '') {
            return false;
        }
        if (str_starts_with($path, '/')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    };

    $configuredCorePath = $readCorePathFromConfig($siteRootCandidate . '/config.yaml');
    if (is_string($configuredCorePath) && $configuredCorePath !== '') {
        $candidate = $looksAbsolutePath($configuredCorePath)
            ? $configuredCorePath
            : $siteRootCandidate . '/' . ltrim($configuredCorePath, '/');
        $resolvedCandidate = realpath($candidate);
        if (
            $resolvedCandidate !== false
            && rtrim($resolvedCandidate, '/') !== rtrim($selfCoreRoot, '/')
            && is_file($resolvedCandidate . '/src/bootstrap.php')
        ) {
            require $resolvedCandidate . '/src/bootstrap.php';
            return;
        }
    }
}

$vendorCandidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php', // site layout: <root>/core/src
    dirname(__DIR__) . '/vendor/autoload.php', // core repo layout: <root>/src
];

foreach ($vendorCandidates as $vendor) {
    if (is_file($vendor)) {
        require $vendor;
        break;
    }
}

spl_autoload_register(static function (string $class) use ($selfCoreRoot): void {
    $prefix = 'Atoll\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = rtrim($selfCoreRoot, '/') . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
