<?php
declare(strict_types=1);
require_once __DIR__ . '/update-center.php';

function releaseManifest(string $json, string $project = 'hao52okp'): array {
    if (strlen($json) > 4 * 1024 * 1024) throw new RuntimeException('清单超过 4 MB');
    $m = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($m) || ($m['product'] ?? '') !== $project || ($m['format'] ?? null) !== 2 || ($m['package'] ?? '') !== $project . '-update.zip') throw new RuntimeException('清单必须为该项目的 format 2 更新清单');
    foreach (['version', 'from'] as $key) if (!is_string($m[$key] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$/D', $m[$key])) throw new RuntimeException('版本必须为 x.y.z');
    if (!version_compare($m['version'], $m['from'], '>')) throw new RuntimeException('目标版本必须高于起始版本');
    if (!is_int($m['size'] ?? null) || $m['size'] <= 0 || !is_string($m['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $m['sha256'])) throw new RuntimeException('清单大小或 SHA-256 无效');
    if (!is_string($m['notes'] ?? null) || strlen($m['notes']) > 65536) throw new RuntimeException('更新说明无效或超过 64 KB');
    return $m;
}

function releaseValidate(string $zipPath, string $manifestPath, string $project = 'hao52okp'): array {
    global $config;
    if (!is_file($manifestPath) || filesize($manifestPath) > 4 * 1024 * 1024) throw new RuntimeException('清单不存在或超过 4 MB');
    $m = releaseManifest((string)file_get_contents($manifestPath), $project);
    clearstatcache(true, $zipPath);
    if (!is_file($zipPath) || filesize($zipPath) !== $m['size'] || $m['size'] > min($config['max_bytes'], 200 * 1024 * 1024)) throw new RuntimeException('ZIP 大小与清单不一致或超过 200 MB / 站点上限');
    if (!hash_equals($m['sha256'], (string)hash_file('sha256', $zipPath))) throw new RuntimeException('ZIP SHA-256 与清单不一致');
    if (!class_exists('ZipArchive')) throw new RuntimeException('请先启用 PHP ZIP 扩展');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('ZIP 文件损坏或无效');
    try {
        if ($zip->numFiles > 10000) throw new RuntimeException('ZIP 文件数量超过上限');
        $names = []; $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i); $name = $entry['name'];
            if (isset($names[$name]) || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~(^|/)(\.\.?)(/|$)|[:\x00-\x1f]~', $name)) throw new RuntimeException('ZIP 含不安全或重复路径');
            $names[$name] = true; $expanded += $entry['size'];
            if ($entry['size'] > 50 * 1024 * 1024 || $expanded > 1024 * 1024 * 1024 || ($entry['encryption_method'] ?? 0) !== 0) throw new RuntimeException('ZIP 含超限或加密文件');
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === 3 && (($attributes >> 16) & 0170000) === 0120000) throw new RuntimeException('ZIP 不允许符号链接');
        }
        $versionFile = $project === 'hao52okp' ? 'nav-version.json' : 'update-version.json';
        $index = $zip->locateName($versionFile);
        if ($index === false || $zip->statIndex($index)['size'] > 4096) throw new RuntimeException('ZIP 缺少有效 ' . $versionFile);
        $version = json_decode((string)$zip->getFromIndex($index), true, 8, JSON_THROW_ON_ERROR);
        if (($version['product'] ?? '') !== $project || ($version['version'] ?? '') !== $m['version']) throw new RuntimeException('ZIP 内版本与清单不一致');
    } finally { $zip->close(); }
    return $m;
}

function releaseStage(array $uploads, string $project = 'hao52okp'): void {
    releaseProject($project);
    foreach (['package' => $project . '-update.zip', 'manifest' => 'update-manifest.json'] as $key => $name) {
        $upload = $uploads[$key] ?? [];
        if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || ($upload['name'] ?? '') !== $name || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('请完整上传 ' . $name);
    }
    releaseStore($uploads['package']['tmp_name'], $uploads['manifest']['tmp_name'], $project, true);
}

function releaseStore(string $zipPath, string $manifestPath, string $project, bool $uploaded, ?array $proof = null): void {
    global $root;
    releaseProject($project);
    $m = releaseValidate($zipPath, $manifestPath, $project);
    $id = bin2hex(random_bytes(32)); $zipId = bin2hex(random_bytes(32)); $manifestId = bin2hex(random_bytes(32));
    try {
        foreach ([$zipId => $zipPath, $manifestId => $manifestPath] as $blob => $source) {
            $saved = $uploaded ? move_uploaded_file($source, "$root/uploads/$blob") : rename($source, "$root/uploads/$blob");
            if (!$saved || !chmod("$root/uploads/$blob", 0600)) throw new RuntimeException('保存私有更新包失败');
        }
        $manifestHash = hash_file('sha256', "$root/uploads/$manifestId");
        $manifestSize = filesize("$root/uploads/$manifestId");
        transaction('project-releases', function (&$data) use ($id, $zipId, $manifestId, $m, $manifestHash, $manifestSize, $project, $proof) {
            $data = releaseData($data);
            foreach ($data['releases'] ?? [] as $release) if ($release['project'] === $project && $release['version'] === $m['version']) throw new RuntimeException('该项目版本已存在，请使用新的版本号');
            $r = ['id' => $id, 'project' => $project, 'version' => $m['version'], 'from' => $m['from'], 'notes' => $m['notes'], 'size' => $m['size'], 'sha256' => $m['sha256'], 'manifest_sha256' => $manifestHash, 'manifest_size' => $manifestSize, 'package_id' => $zipId, 'manifest_id' => $manifestId, 'status' => 'draft', 'created' => gmdate('c')];
            if ($proof !== null) { releaseMatchProof($r, releaseProject($project, $data), $proof); $r['github'] = $proof; }
            $data['releases'][$id] = $r;
        });
    } catch (Throwable $e) { foreach ([$zipId, $manifestId] as $blob) if (is_file("$root/uploads/$blob")) unlink("$root/uploads/$blob"); throw $e; }
}

function releasePublish(string $id): void {
    global $root;
    transaction('project-releases', function (&$data) use ($id, $root) {
        $data = releaseData($data);
        $r = $data['releases'][$id] ?? null;
        if (!$r || $r['status'] !== 'draft') throw new RuntimeException('待发布版本不存在或已发布');
        $project = releaseProject($r['project'], $data);
        if (empty($project['token_hash'])) throw new RuntimeException('请先生成该项目访问令牌');
        if (empty($r['github']) || $project['repository'] === '') throw new RuntimeException('请先完成 GitHub 来源校验');
        releaseMatchProof($r, $project, $r['github']);
        $m = releaseValidate("$root/uploads/{$r['package_id']}", "$root/uploads/{$r['manifest_id']}", $r['project']);
        if (!hash_equals($r['manifest_sha256'], (string)hash_file('sha256', "$root/uploads/{$r['manifest_id']}"))) throw new RuntimeException('清单上传后发生变化，请重新上传');
        if ($m['version'] !== $r['version'] || !hash_equals($r['sha256'], $m['sha256'])) throw new RuntimeException('待发布文件已变化，请重新上传');
        $latest = $data['releases'][$project['latest'] ?? ''] ?? null;
        if ($latest && !version_compare($r['version'], $latest['version'], '>')) throw new RuntimeException('发布版本必须高于当前已发布版本');
        $data['releases'][$id]['status'] = 'published'; $data['releases'][$id]['published_at'] = gmdate('c');
        $data['projects'][$r['project']]['latest'] = $id;
        if ($r['project'] === 'hao52okp') $data['latest'] = $id;
    });
}

// Validate under the metadata lock before touching either asset. Never accept paths from POST.
function releaseDeletePaths(array $data, string $id, string $project): array {
    global $root;
    $r = $data['releases'][$id] ?? throw new RuntimeException('版本不存在或已删除');
    if ($r['project'] !== $project) throw new RuntimeException('版本不属于所选项目');
    foreach ($data['projects'] as $p) if (($p['latest'] ?? '') === $id) throw new RuntimeException('当前已发布版本不能删除，请先发布新版本');
    if (($data['latest'] ?? '') === $id) throw new RuntimeException('当前已发布版本不能删除');
    $directory = realpath("$root/uploads");
    if ($directory === false) throw new RuntimeException('上传目录不存在');
    $paths = [];
    foreach (['package_id', 'manifest_id'] as $key) {
        $blob = $r[$key] ?? '';
        if (!is_string($blob) || !preg_match('/^[a-f0-9]{64}$/D', $blob)) throw new RuntimeException('版本文件标识异常，拒绝删除');
        foreach ($data['releases'] as $otherId => $other) {
            if ($otherId !== $id && in_array($blob, [$other['package_id'] ?? '', $other['manifest_id'] ?? ''], true)) throw new RuntimeException('文件被其他版本引用，拒绝删除');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $blob;
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && (!is_file($path) || dirname((string)realpath($path)) !== $directory))) throw new RuntimeException('版本文件路径异常，拒绝删除');
        $paths[] = $path;
    }
    return $paths;
}

function releaseDelete(string $id, string $project): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new RuntimeException('无效版本标识');
    // Persist a non-downloadable tombstone first. A partial unlink or metadata failure
    // leaves a retryable record instead of advertising an incomplete published release.
    transaction('project-releases', function (&$data) use ($id, $project) {
        $data = releaseData($data);
        releaseDeletePaths($data, $id, $project);
        $data['releases'][$id]['status'] = 'deleting';
    });
    transaction('project-releases', function (&$data) use ($id, $project) {
        $data = releaseData($data);
        if (!isset($data['releases'][$id])) return; // Another delete request completed it.
        foreach (releaseDeletePaths($data, $id, $project) as $path) {
            if (file_exists($path) && !@unlink($path)) throw new RuntimeException('本地文件清理未完成，请检查目录权限后重试删除；该版本已停止下载');
        }
        unset($data['releases'][$id]);
    });
}

function releasePrivate(array $entry): bool {
    $name = strtolower((string)($entry['name'] ?? ''));
    if (strtolower(trim((string)($entry['project'] ?? ''))) === 'hao52okp' || in_array($name, ['hao52okp-update.zip', 'update-manifest.json'], true)) return true;
    $data = releaseData(transaction('project-releases', fn(&$d) => $d, false));
    foreach ($data['projects'] as $project) if ($name === $project['package']) return true;
    return false;
}

function releaseApi(string $route): never {
    require_once __DIR__ . '/release-api.php';
    releaseApiDispatch($route);
}
