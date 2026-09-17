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

function updateVersionLabel(string $name, string $fallback): string {
    return preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $name) ? $name : $fallback;
}

function updateStatus(): array {
    global $root;
    $state = updateRead('status');
    $heartbeat = updateRead('heartbeat');
    $enabled = is_file("$root/updater/enabled");
    $current = is_file(dirname(__DIR__) . '/.release') ? trim(file_get_contents(dirname(__DIR__) . '/.release')) : 'manual';
    // The installed v1 worker only reports phase names. Derive progress without upgrading it.
    $task = updateRead('task');
    $request = updateRead('request') ?: updateRead('running');
    $matching = ($task['id'] ?? '') !== '' && ($task['id'] ?? '') === ($state['job'] ?? '');
    $state['task'] = $matching ? ($task['type'] ?? '') : ($request['type'] ?? '');
    $state['started'] = $matching ? ($task['started'] ?? 0) : ($state['updated'] ?? 0);
    $state['progress'] = updateProgress((string)($state['phase'] ?? 'idle'));
    $versionPath = dirname(__DIR__) . '/deploy/version.txt';
    $version = is_file($versionPath) ? trim(file_get_contents($versionPath)) : '';
    $state['latest_version'] = updateVersionLabel((string)($state['latest']['name'] ?? ''), (string)($state['latest']['tag'] ?? ''));
    // Translate legacy worker success messages without changing its installation identity.
    $displayVersion = updateVersionLabel((string)($state['latest']['name'] ?? ''), '未提供正式版本号');
    if (($state['phase'] ?? '') === 'checked') $state['message'] = '已获取最新可用版本：' . $displayVersion;
    if (($state['phase'] ?? '') === 'success') $state['message'] = '程序更新成功：' . $displayVersion;
    return ['enabled' => $enabled, 'connected' => $enabled && ($heartbeat['at'] ?? 0) > time() - 180, 'current' => $current, 'current_version' => updateVersionLabel($version, $current), 'state' => $state];
}

function updateProgress(string $phase): array {
    $stages = [
        'idle' => [0, '等待开始', false],
        'queued' => [10, '已提交，等待本地服务处理', true],
        'checking' => [null, '正在连接 GitHub、查询最新版本', true],
        'downloading' => [25, '正在下载并校验发布包', true],
        'validating' => [60, '正在解压、检查代码和运行测试', true],
        'switching' => [85, '正在切换版本并检查网站健康状态', true],
        'checked' => [100, '版本检查完成', false],
        'success' => [100, '程序更新完成', false],
        'failed' => [0, '任务失败，请查看错误信息', false],
    ];
    [$percent, $label, $active] = $stages[$phase] ?? [0, '状态未知，请刷新状态', false];
    return ['percent' => $percent, 'label' => $label, 'active' => $active];
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
        updateWrite('task', ['id' => $id, 'type' => $type, 'started' => time()]);
        updateWrite('request', ['id' => $id, 'type' => $type, 'tag' => $tag]);
        updateWrite('status', ['job' => $id, 'phase' => 'queued', 'message' => '请求已提交，等待本地服务处理', 'updated' => time(), 'latest' => $state['latest'] ?? null]);
        return $id;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
