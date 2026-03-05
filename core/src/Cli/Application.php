<?php

declare(strict_types=1);

namespace Atoll\Cli;

final class Application
{
    public static function run(array $argv, string $projectRoot): never
    {
        $GLOBALS['__ATOLL_CLI_ARGV'] = $argv;
        $GLOBALS['__ATOLL_CLI_ROOT'] = rtrim($projectRoot, '/');

        require __DIR__ . '/LegacyCliRunner.php';

        exit(0);
    }
}
