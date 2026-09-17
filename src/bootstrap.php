<?php
declare(strict_types=1);
$path = getenv('OSS_CONFIG') ?: dirname(__DIR__, 2) . '/shared/config/config.php';
if (!is_file($path)) { http_response_code(503); exit('请先配置 OSS_CONFIG 并执行初始化脚本。'); }
$config = require $path;
if (!is_array($config) || !filter_var($config['base_url'] ?? '', FILTER_VALIDATE_URL) || parse_url($config['base_url'], PHP_URL_SCHEME) !== 'https' || !is_int($config['max_bytes'] ?? null) || $config['max_bytes'] <= 0) { http_response_code(503); exit('服务器配置无效'); }
$root = rtrim($config['shared'], '/');
require_once __DIR__ . '/storage.php';
