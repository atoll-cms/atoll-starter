<?php

declare(strict_types=1);

return [
    'id' => '20260304_001_updater_defaults',
    'target_version' => '0.2.0',
    'description' => 'Ensure updater defaults are available in config.yaml.',
    'up' => static function (array $context): void {
        $configPath = (string) ($context['config_path'] ?? '');
        if ($configPath === '' || !is_file($configPath)) {
            return;
        }

        $config = \Atoll\Support\Config::load($configPath);
        if (!isset($config['updater']) || !is_array($config['updater'])) {
            $config['updater'] = [];
        }

        $config['updater']['channel'] ??= 'stable';
        $config['updater']['manifest_url'] ??= '';
        $config['updater']['public_key'] ??= 'config/updater-public.pem';
        $config['updater']['require_signature'] ??= true;
        $config['updater']['timeout_seconds'] ??= 15;

        \Atoll\Support\Config::save($configPath, $config);
    },
];
