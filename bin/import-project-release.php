<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/project-releases.php';
try {
    if ($argc !== 3) throw new RuntimeException('用法：php bin/import-project-release.php 项目标识 1.0.0');
    releaseImport($argv[1], $argv[2]);
    echo "已拉取并校验为草稿，请在 OSS 后台发布。\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
