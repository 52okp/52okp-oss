<?php
declare(strict_types=1);
$path = getenv('OSS_CONFIG') ?: dirname(__DIR__, 2) . '/shared/config/config.php';
if (!is_file($path)) { http_response_code(503); exit('请先配置 OSS_CONFIG 并执行初始化脚本。'); }
$config = require $path;
if (!is_array($config) || !filter_var($config['base_url'] ?? '', FILTER_VALIDATE_URL) || parse_url($config['base_url'], PHP_URL_SCHEME) !== 'https' || !is_int($config['max_bytes'] ?? null) || $config['max_bytes'] <= 0) { http_response_code(503); exit('服务器配置无效'); }
$root = rtrim($config['shared'], '/');
function transaction(string $name, callable $callback, bool $write = true): mixed {
    global $root;
    $file = "$root/metadata/$name.json";
    $lock = fopen("$file.lock", 'c');
    if (!$lock || !flock($lock, $write ? LOCK_EX : LOCK_SH)) { throw new RuntimeException('无法锁定元数据'); }
    try {
        $data = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        $result = $callback($data);
        if (!$write) return $result;
        $tmp = tempnam(dirname($file), '.metadata-');
        try {
            if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $file)) { throw new RuntimeException('无法保存元数据'); }
        } finally { if (is_file($tmp)) unlink($tmp); }
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fail(int $code, string $message): never { http_response_code($code); exit($message); }
