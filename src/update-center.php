<?php
declare(strict_types=1);

// Read-time normalization keeps v1.3 records usable without rewriting on anonymous requests.
function releaseData(array $data): array {
    if (!isset($data['projects']['hao52okp'])) {
        $data['projects']['hao52okp'] = ['id' => 'hao52okp', 'repository' => '', 'package' => 'hao52okp-update.zip', 'version_file' => 'nav-version.json', 'channel' => 'stable'];
        foreach (['token_hash', 'latest'] as $key) if (isset($data[$key])) $data['projects']['hao52okp'][$key] = $data[$key];
    }
    return $data;
}

function releaseProject(string $id, ?array $data = null): array {
    $data ??= transaction('project-releases', fn(&$d) => $d, false);
    return releaseData($data)['projects'][$id] ?? throw new RuntimeException('项目未注册');
}

function releaseRegister(array $input): void {
    $id = (string)($input['release_project'] ?? '');
    $repo = (string)($input['repository'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9-]{1,47}$/D', $id)) throw new RuntimeException('项目标识使用 2–48 位小写字母、数字、连字符');
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D', $repo)) throw new RuntimeException('仓库格式必须为 owner/repo，不是 URL');
    transaction('project-releases', function (&$data) use ($id, $repo) {
        $data = releaseData($data);
        $existing = $data['projects'][$id] ?? null;
        if ($existing && $existing['repository'] !== '' && $existing['repository'] !== $repo) throw new RuntimeException('已绑定仓库不可更改；更换来源请注册新项目');
        $data['projects'][$id] = array_replace($existing ?? [], ['id' => $id, 'repository' => $repo, 'package' => "$id-update.zip", 'version_file' => $id === 'hao52okp' ? 'nav-version.json' : 'update-version.json', 'channel' => 'stable']);
    });
}

function releaseToken(string $project, ?string $secret): void {
    transaction('project-releases', function (&$data) use ($project, $secret) {
        $data = releaseData($data); releaseProject($project, $data);
        unset($data['projects'][$project]['token_hash']);
        if ($secret !== null) $data['projects'][$project]['token_hash'] = hash('sha256', $secret);
        // Keep old readers compatible; new readers use the project-scoped value only.
        if ($project === 'hao52okp') {
            unset($data['token_hash']);
            if ($secret !== null) $data['token_hash'] = hash('sha256', $secret);
        }
    });
}

function releaseVersion(string $value): bool {
    return preg_match('/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$/D', $value) === 1;
}

// Never accept a URL supplied by the browser, a manifest, or a collecting node.
function releaseGithubRequest(string $url, int $limit, bool $binary = false, $output = null): string {
    global $config;
    if (!extension_loaded('curl')) throw new RuntimeException('GitHub 校验/拉取需要 PHP cURL 扩展');
    $body = ''; $size = 0;
    for ($redirect = 0; $redirect < 4; $redirect++) {
        $parts = parse_url($url); $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !in_array($host, ['api.github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) throw new RuntimeException('GitHub 返回了不允许的下载地址');
        $headers = ['Accept: ' . ($binary ? 'application/octet-stream' : 'application/vnd.github+json'), 'X-GitHub-Api-Version: 2022-11-28'];
        $credential = (string)($config['github_token'] ?? '');
        if ($host === 'api.github.com' && $credential !== '') {
            if (preg_match('/[\r\n]/', $credential)) throw new RuntimeException('GitHub 凭据格式错误');
            $headers[] = 'Authorization: Bearer ' . $credential;
        }
        $location = ''; $status = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => '52okp-update-center/1', CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $binary ? 120 : 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$location, &$status): int {
                if (preg_match('~^HTTP/\S+ (\d+)~', $line, $m)) $status = (int)$m[1];
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$size, $limit, $output, &$status): int {
                if ($status !== 200) return strlen($chunk);
                $size += strlen($chunk); if ($size > $limit) return 0;
                if (is_resource($output)) return (int)fwrite($output, $chunk);
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); unset($curl);
        if ($ok === false) throw new RuntimeException('GitHub 请求失败、超时或文件超限；未发布任何新版本');
        if (in_array($status, [301, 302, 303, 307, 308], true) && $binary && $location !== '') { $url = $location; continue; }
        if ($status !== 200) throw new RuntimeException('GitHub 返回 HTTP ' . $status . '；请检查仓库、版本、访问权限及限流');
        return $body;
    }
    throw new RuntimeException('GitHub 重定向次数超限');
}

function releaseGithubAssets(array $project, string $version, array $remote): array {
    if (($remote['draft'] ?? true) !== false || ($remote['prerelease'] ?? true) !== false || ($remote['tag_name'] ?? '') !== 'v' . $version || !is_int($remote['id'] ?? null) || empty($remote['published_at'])) throw new RuntimeException('GitHub 必须存在对应 vX.Y.Z 正式 Release（非草稿/预发布）');
    $assets = [];
    foreach ($remote['assets'] ?? [] as $asset) {
        $name = $asset['name'] ?? '';
        if (!in_array($name, [$project['package'], 'update-manifest.json'], true)) continue;
        if (isset($assets[$name]) || ($asset['state'] ?? '') !== 'uploaded' || !is_int($asset['id'] ?? null) || !is_int($asset['size'] ?? null) || $asset['size'] <= 0 || !preg_match('/^sha256:([a-f0-9]{64})$/D', (string)($asset['digest'] ?? ''), $m)) throw new RuntimeException('GitHub 资产不完整或缺少 SHA-256 digest；请重新上传正式资产');
        $assets[$name] = ['id' => $asset['id'], 'size' => $asset['size'], 'sha256' => $m[1]];
    }
    if (count($assets) !== 2) throw new RuntimeException('GitHub Release 必须同时包含更新 ZIP 和 update-manifest.json');
    return ['repository' => $project['repository'], 'tag' => 'v' . $version, 'release_id' => $remote['id'], 'checked_at' => gmdate('c'), 'assets' => $assets];
}

function releaseGithub(array $project, string $version): array {
    if ($project['repository'] === '') throw new RuntimeException('请先为项目绑定 GitHub 仓库');
    if (!releaseVersion($version)) throw new RuntimeException('版本必须为 x.y.z');
    $json = releaseGithubRequest('https://api.github.com/repos/' . $project['repository'] . '/releases/tags/v' . $version, 4 * 1024 * 1024);
    return releaseGithubAssets($project, $version, json_decode($json, true, 32, JSON_THROW_ON_ERROR));
}

function releaseMatchProof(array $r, array $project, array $proof): void {
    if (($proof['repository'] ?? '') !== $project['repository'] || ($proof['tag'] ?? '') !== 'v' . $r['version']) throw new RuntimeException('GitHub 来源与项目不一致');
    foreach ([$project['package'] => ['size', 'sha256'], 'update-manifest.json' => ['manifest_size', 'manifest_sha256']] as $name => [$size, $hash]) {
        $asset = $proof['assets'][$name] ?? [];
        if (($asset['size'] ?? 0) !== ($r[$size] ?? -1) || !hash_equals((string)($r[$hash] ?? ''), (string)($asset['sha256'] ?? ''))) throw new RuntimeException('本地文件与 GitHub 正式资产不一致：' . $name);
    }
}

function releaseVerify(string $id): void {
    global $root;
    $data = releaseData(transaction('project-releases', fn(&$d) => $d, false));
    $original = $data['releases'][$id] ?? throw new RuntimeException('版本不存在');
    $r = $original;
    if ($r['status'] !== 'draft') throw new RuntimeException('只能校验待发布草稿');
    $r['manifest_size'] ??= filesize("$root/uploads/{$r['manifest_id']}");
    $project = releaseProject($r['project'], $data);
    $proof = releaseGithub($project, $r['version']); releaseMatchProof($r, $project, $proof);
    transaction('project-releases', function (&$data) use ($id, $r, $original, $proof) {
        $data = releaseData($data);
        if (($data['releases'][$id] ?? null) !== $original) throw new RuntimeException('记录已变化，请重新校验');
        releaseMatchProof($r, releaseProject($r['project'], $data), $proof);
        $data['releases'][$id] = array_replace($r, ['github' => $proof]);
    });
}

function releaseImport(string $projectId, string $version): void {
    global $root, $config;
    $project = releaseProject($projectId); $proof = releaseGithub($project, $version); $paths = [];
    try {
        foreach (['manifest' => 'update-manifest.json', 'package' => $project['package']] as $kind => $name) {
            $asset = $proof['assets'][$name]; $limit = $kind === 'manifest' ? 4 * 1024 * 1024 : min($config['max_bytes'], 200 * 1024 * 1024);
            if ($asset['size'] > $limit) throw new RuntimeException('GitHub 资产超过上传上限');
            $paths[$kind] = tempnam("$root/uploads", '.import-');
            if (!$paths[$kind] || !chmod($paths[$kind], 0600)) throw new RuntimeException('无法创建私有临时文件');
            $stream = fopen($paths[$kind], 'wb');
            if (!$stream) throw new RuntimeException('无法写入私有临时文件');
            try { releaseGithubRequest('https://api.github.com/repos/' . $project['repository'] . '/releases/assets/' . $asset['id'], $limit, true, $stream); }
            finally { fclose($stream); }
            clearstatcache(true, $paths[$kind]);
            if (filesize($paths[$kind]) !== $asset['size'] || !hash_equals($asset['sha256'], (string)hash_file('sha256', $paths[$kind]))) throw new RuntimeException('GitHub 拉取的文件不完整或哈希不匹配');
        }
        releaseStore($paths['package'], $paths['manifest'], $projectId, false, $proof);
    } finally { foreach ($paths as $path) if (is_file($path)) unlink($path); }
}
