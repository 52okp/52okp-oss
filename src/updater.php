<?php
declare(strict_types=1);

function updateRead(string $name): array {
    global $root;
    $path = "$root/updater/$name.json";
    if (!is_file($path) || filesize($path) > 65536) return [];
    try { return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) ?: []; }
    catch (Throwable $e) { return []; }
}

function updateWrite(string $name, array $data): void {
    global $root;
    $tmp = tempnam("$root/updater", '.update-');
    if ($tmp === false) throw new RuntimeException('无法创建更新请求');
    try {
        if (!chmod($tmp, 0660) || file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, "$root/updater/$name.json")) throw new RuntimeException('无法保存更新请求');
    } finally { if (is_file($tmp)) unlink($tmp); }
}

function updateStatus(): array {
    global $root;
    $state = updateRead('status');
    $heartbeat = updateRead('heartbeat');
    $enabled = is_file("$root/updater/enabled");
    $current = is_file(dirname(__DIR__) . '/.release') ? trim(file_get_contents(dirname(__DIR__) . '/.release')) : 'manual';
    return ['enabled' => $enabled, 'connected' => $enabled && ($heartbeat['at'] ?? 0) > time() - 180, 'current' => $current, 'state' => $state];
}

function updateSubmit(string $type, string $tag = ''): string {
    global $root;
    $status = updateStatus();
    if (!$status['connected']) throw new RuntimeException('本地更新服务尚未运行，请先安装或启动服务');
    if (!in_array($type, ['check', 'install'], true)) throw new RuntimeException('无效更新操作');
    if ($type === 'install' && !preg_match('/^build-[0-9]+-[0-9]+$/D', $tag)) throw new RuntimeException('请先检查最新版本');
    $path = "$root/updater/queue.lock";
    $lock = fopen($path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('无法锁定更新队列');
    try {
        $state = updateRead('status');
        if (is_file("$root/updater/request.json") || is_file("$root/updater/running.json")) throw new RuntimeException('已有更新任务正在执行');
        if ($type === 'install' && ($state['latest']['tag'] ?? '') !== $tag) throw new RuntimeException('版本信息已变化，请重新检查更新');
        if ($type === 'install' && $tag === $status['current']) throw new RuntimeException('当前已经是该版本');
        $id = bin2hex(random_bytes(16));
        updateWrite('request', ['id' => $id, 'type' => $type, 'tag' => $tag]);
        updateWrite('status', ['job' => $id, 'phase' => 'queued', 'message' => '请求已提交，等待本地服务处理', 'updated' => time(), 'latest' => $state['latest'] ?? null]);
        return $id;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
