<?php
declare(strict_types=1);

function releaseManifest(string $json): array {
    if (strlen($json) > 4 * 1024 * 1024) throw new RuntimeException('清单超过 4 MB');
    $m = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($m) || ($m['product'] ?? '') !== 'hao52okp' || ($m['format'] ?? null) !== 2 || ($m['package'] ?? '') !== 'hao52okp-update.zip') throw new RuntimeException('清单必须为 hao52okp format 2 更新清单');
    foreach (['version', 'from'] as $key) if (!is_string($m[$key] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$/D', $m[$key])) throw new RuntimeException('版本必须为 x.y.z');
    if (!version_compare($m['version'], $m['from'], '>')) throw new RuntimeException('目标版本必须高于起始版本');
    if (!is_int($m['size'] ?? null) || $m['size'] <= 0 || !is_string($m['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $m['sha256'])) throw new RuntimeException('清单大小或 SHA-256 无效');
    if (!is_string($m['notes'] ?? null) || strlen($m['notes']) > 65536) throw new RuntimeException('更新说明无效或超过 64 KB');
    return $m;
}

function releaseValidate(string $zipPath, string $manifestPath): array {
    global $config;
    if (!is_file($manifestPath) || filesize($manifestPath) > 4 * 1024 * 1024) throw new RuntimeException('清单不存在或超过 4 MB');
    $m = releaseManifest((string)file_get_contents($manifestPath));
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
        $index = $zip->locateName('nav-version.json');
        if ($index === false || $zip->statIndex($index)['size'] > 4096) throw new RuntimeException('ZIP 缺少有效 nav-version.json');
        $version = json_decode((string)$zip->getFromIndex($index), true, 8, JSON_THROW_ON_ERROR);
        if (($version['product'] ?? '') !== 'hao52okp' || ($version['version'] ?? '') !== $m['version']) throw new RuntimeException('ZIP 内版本与清单不一致');
    } finally { $zip->close(); }
    return $m;
}

function releaseStage(array $uploads): void {
    global $root;
    foreach (['package' => 'hao52okp-update.zip', 'manifest' => 'update-manifest.json'] as $key => $name) {
        $upload = $uploads[$key] ?? [];
        if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || ($upload['name'] ?? '') !== $name || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('请完整上传 ' . $name);
    }
    if (filesize($uploads['manifest']['tmp_name']) > 4 * 1024 * 1024) throw new RuntimeException('清单超过 4 MB');
    $m = releaseValidate($uploads['package']['tmp_name'], $uploads['manifest']['tmp_name']);
    $id = bin2hex(random_bytes(32)); $zipId = bin2hex(random_bytes(32)); $manifestId = bin2hex(random_bytes(32));
    try {
        foreach (['package' => $zipId, 'manifest' => $manifestId] as $key => $blob) {
            if (!move_uploaded_file($uploads[$key]['tmp_name'], "$root/uploads/$blob") || !chmod("$root/uploads/$blob", 0600)) throw new RuntimeException('保存私有更新包失败');
        }
        $manifestHash = hash_file('sha256', "$root/uploads/$manifestId");
        transaction('project-releases', function (&$data) use ($id, $zipId, $manifestId, $m, $manifestHash) {
            foreach ($data['releases'] ?? [] as $release) if ($release['version'] === $m['version']) throw new RuntimeException('该版本已存在，请使用新的版本号');
            $data['releases'][$id] = ['id' => $id, 'project' => 'hao52okp', 'version' => $m['version'], 'from' => $m['from'], 'notes' => $m['notes'], 'size' => $m['size'], 'sha256' => $m['sha256'], 'manifest_sha256' => $manifestHash, 'package_id' => $zipId, 'manifest_id' => $manifestId, 'status' => 'draft', 'created' => gmdate('c')];
        });
    } catch (Throwable $e) { foreach ([$zipId, $manifestId] as $blob) if (is_file("$root/uploads/$blob")) unlink("$root/uploads/$blob"); throw $e; }
}

function releasePublish(string $id): void {
    global $root;
    transaction('project-releases', function (&$data) use ($id, $root) {
        $r = $data['releases'][$id] ?? null;
        if (!$r || $r['status'] !== 'draft') throw new RuntimeException('待发布版本不存在或已发布');
        if (empty($data['token_hash'])) throw new RuntimeException('请先生成导航站访问令牌');
        $m = releaseValidate("$root/uploads/{$r['package_id']}", "$root/uploads/{$r['manifest_id']}");
        if (!hash_equals($r['manifest_sha256'], (string)hash_file('sha256', "$root/uploads/{$r['manifest_id']}"))) throw new RuntimeException('清单上传后发生变化，请重新上传');
        if ($m['version'] !== $r['version'] || !hash_equals($r['sha256'], $m['sha256'])) throw new RuntimeException('待发布文件已变化，请重新上传');
        $latest = $data['releases'][$data['latest'] ?? ''] ?? null;
        if ($latest && !version_compare($r['version'], $latest['version'], '>')) throw new RuntimeException('发布版本必须高于当前已发布版本');
        $data['releases'][$id]['status'] = 'published'; $data['releases'][$id]['published_at'] = gmdate('c');
        $data['latest'] = $id;
    });
}

function releasePrivate(array $entry): bool {
    return strtolower(trim((string)($entry['project'] ?? ''))) === 'hao52okp' || in_array(strtolower((string)($entry['name'] ?? '')), ['hao52okp-update.zip', 'update-manifest.json'], true);
}

function releaseApi(string $route): never {
    global $root, $config;
    header('Cache-Control: private, no-store, max-age=0'); header('Vary: Authorization'); header('X-Content-Type-Options: nosniff');
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) fail(405, 'Method not allowed');
    $data = transaction('project-releases', fn(&$data) => $data, false);
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $match) || empty($data['token_hash']) || !hash_equals($data['token_hash'], hash('sha256', $match[1]))) { header('WWW-Authenticate: Bearer'); fail(401, 'Unauthorized'); }
    if ($route === '/api/project-updates/hao52okp/latest') {
        $r = $data['releases'][$data['latest'] ?? ''] ?? null;
        if (!$r || $r['status'] !== 'published') fail(404, 'No published release');
        $base = rtrim($config['base_url'], '/') . '/api/project-updates/hao52okp/';
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo json_encode(['project' => 'hao52okp', 'version' => $r['version'], 'from' => $r['from'], 'notes' => $r['notes'], 'size' => $r['size'], 'sha256' => $r['sha256'], 'published_at' => $r['published_at'], 'download_url' => $base . $r['id'] . '/package', 'manifest_url' => $base . $r['id'] . '/manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    if (!preg_match('~^/api/project-updates/hao52okp/([a-f0-9]{64})/(package|manifest)$~D', $route, $match)) fail(404, 'Not found');
    $r = $data['releases'][$match[1]] ?? null;
    if (!$r || $r['status'] !== 'published') fail(404, 'Not found');
    $package = $match[2] === 'package'; $path = "$root/uploads/" . $r[$package ? 'package_id' : 'manifest_id'];
    $stream = @fopen($path, 'rb'); if (!$stream) fail(404, 'Not found');
    header('Content-Type: ' . ($package ? 'application/zip' : 'application/json'));
    header('Content-Disposition: attachment; filename="' . ($package ? 'hao52okp-update.zip' : 'update-manifest.json') . '"');
    header('Content-Length: ' . fstat($stream)['size']);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') fpassthru($stream);
    fclose($stream); exit;
}
