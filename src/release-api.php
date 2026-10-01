<?php
declare(strict_types=1);

function releaseJson(array $value, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function releaseApiError(int $status, string $code, string $message): never {
    releaseJson(['error' => ['code' => $code, 'message' => $message]], $status);
}

function releasePublicRecord(array $r, array $project): array {
    global $config, $root;
    $base = rtrim($config['base_url'], '/') . '/api/v1/projects/' . $r['project'] . '/releases/' . $r['id'];
    $manifestSize = $r['manifest_size'] ?? (@filesize("$root/uploads/{$r['manifest_id']}") ?: 0);
    return ['format' => 1, 'id' => $r['id'], 'project' => $r['project'], 'product' => $r['project'], 'channel' => 'stable', 'version' => $r['version'], 'from' => $r['from'], 'notes' => $r['notes'], 'status' => 'published', 'published_at' => $r['published_at'], 'size' => $r['size'], 'sha256' => $r['sha256'], 'download_url' => $base . '/package', 'manifest_url' => $base . '/manifest',
        'verification' => empty($r['github']) ? 'legacy-local' : 'github-sha256',
        'assets' => [$project['package'] => ['size' => $r['size'], 'sha256' => $r['sha256'], 'url' => $base . '/package'], 'update-manifest.json' => ['size' => $manifestSize, 'sha256' => $r['manifest_sha256'], 'url' => $base . '/manifest']]];
}

function releaseApiDispatch(string $route): never {
    global $root;
    header('Cache-Control: private, no-store, max-age=0'); header('Vary: Authorization'); header('X-Content-Type-Options: nosniff');
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); releaseApiError(405, 'method_not_allowed', 'Only GET and HEAD are supported'); }
    $kind = ''; $id = ''; $version = '';
    if (preg_match('~^/api/v1/projects/([a-z][a-z0-9-]{1,47})/(updates|releases/([a-f0-9]{64})(?:/(package|manifest))?)$~D', $route, $m)) {
        $projectId = $m[1]; $kind = $m[2] === 'updates' ? 'latest' : ($m[4] ?? 'record'); $id = $m[3] ?? '';
    } elseif (preg_match('~^/api/project-updates/(hao52okp)/(latest|([a-f0-9]{64})/(package|manifest))$~D', $route, $m)) {
        $projectId = $m[1]; $kind = $m[2] === 'latest' ? 'legacy-latest' : $m[4]; $id = $m[3] ?? '';
    } elseif (preg_match('~^/api/updates/(hao52okp)/([0-9]+\.[0-9]+\.[0-9]+)(?:/(package|manifest))?$~D', $route, $m)) {
        $projectId = $m[1]; $version = $m[2]; $kind = $m[3] ?? 'record';
    } else releaseApiError(404, 'not_found', 'Unknown endpoint');
    $data = releaseData(transaction('project-releases', fn(&$d) => $d, false)); $project = $data['projects'][$projectId] ?? [];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $m) || empty($project['token_hash']) || !hash_equals($project['token_hash'], hash('sha256', $m[1]))) {
        header('WWW-Authenticate: Bearer'); releaseApiError(401, 'unauthorized', 'Missing, revoked, or wrong project token');
    }
    if (isset($_GET['channel']) && $_GET['channel'] !== 'stable') releaseApiError(400, 'invalid_channel', 'Only stable is supported');
    $current = $_GET['current_version'] ?? null;
    if ($kind === 'latest' && (!is_string($current) || !releaseVersion($current))) releaseApiError(400, 'invalid_version', 'current_version must be x.y.z');
    if ($version !== '') foreach ($data['releases'] ?? [] as $candidate) if ($candidate['project'] === $projectId && $candidate['version'] === $version) { $id = $candidate['id']; break; }
    if (in_array($kind, ['latest', 'legacy-latest'], true)) $id = $project['latest'] ?? '';
    $r = $data['releases'][$id] ?? null;
    if (!$r || $r['project'] !== $projectId || $r['status'] !== 'published') releaseApiError(404, 'release_not_found', 'No published release');
    if (!in_array($kind, ['package', 'manifest'], true)) {
        $record = releasePublicRecord($r, $project);
        if ($kind === 'latest') releaseJson(['api_version' => 1, 'project' => $projectId, 'channel' => 'stable', 'current_version' => $current, 'update_available' => version_compare($r['version'], $current, '>'), 'release' => $record]);
        releaseJson($record);
    }
    $package = $kind === 'package'; $path = "$root/uploads/" . $r[$package ? 'package_id' : 'manifest_id'];
    $stream = @fopen($path, 'rb'); if (!$stream) releaseApiError(404, 'release_not_found', 'Asset not found');
    header('Content-Type: ' . ($package ? 'application/octet-stream' : 'application/json'));
    header('Content-Disposition: attachment; filename="' . ($package ? $project['package'] : 'update-manifest.json') . '"');
    header('Content-Length: ' . fstat($stream)['size']);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') fpassthru($stream);
    fclose($stream); exit;
}
