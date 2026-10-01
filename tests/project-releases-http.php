<?php
// Included by http.php inside its isolated HTTP test environment.
$apiPath = '/api/project-updates/hao52okp/latest';
assertHttp(request('GET', $apiPath)[0] === 401, '私有更新查询必须鉴权，后台登录不替代令牌');
assertHttp(request('POST', '/', http_build_query(['action' => 'release_token']))[0] === 403, '令牌管理需要CSRF');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_token']))[0] === 302, '管理员生成项目令牌');
[, $tokenPage] = request('GET', '/');
preg_match('/aria-label="导航站访问令牌" value="([a-f0-9]{64})"/', $tokenPage, $tokenMatch);
$apiToken = $tokenMatch[1] ?? '';
assertHttp(strlen($apiToken) === 64, '令牌仅在生成后返回');
assertHttp(!str_contains(request('GET', '/')[1], $apiToken), '令牌刷新后不再显示');
assertHttp(!str_contains(file_get_contents("$scratch/metadata/project-releases.json"), $apiToken), '只持久化令牌哈希');
assertHttp(request('GET', $apiPath)[0] === 404, '没有已发布版本时不返回草稿');
$zipFile = "$scratch/uploads/test-source.zip";
$zip = new ZipArchive(); $zip->open($zipFile, ZipArchive::CREATE);
$zip->addFromString('nav-version.json', json_encode(['product' => 'hao52okp', 'version' => '1.0.1'])); $zip->close();
$zipBytes = file_get_contents($zipFile); unlink($zipFile);
$manifest = ['format' => 2, 'product' => 'hao52okp', 'package' => 'hao52okp-update.zip', 'version' => '1.0.1', 'from' => '1.0.0', 'size' => strlen($zipBytes), 'sha256' => hash('sha256', $zipBytes), 'notes' => '测试更新'];
function releaseUploadBody(array $manifest, string $zipBytes, bool $includeManifest = true): string {
    global $token;
    $body = "--release-test\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n--release-test\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nrelease_stage\r\n--release-test\r\nContent-Disposition: form-data; name=\"package\"; filename=\"hao52okp-update.zip\"\r\nContent-Type: application/zip\r\n\r\n$zipBytes\r\n";
    if ($includeManifest) $body .= "--release-test\r\nContent-Disposition: form-data; name=\"manifest\"; filename=\"update-manifest.json\"\r\nContent-Type: application/json\r\n\r\n" . json_encode($manifest) . "\r\n";
    return $body . "--release-test--\r\n";
}
$mime = 'multipart/form-data; boundary=release-test';
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes, false), $mime)[0] === 400, '缺少清单不能进入待发布区');
foreach (['size' => 999, 'sha256' => str_repeat('a', 64), 'version' => '1.0.2'] as $field => $value) {
    assertHttp(request('POST', '/', releaseUploadBody(array_replace($manifest, [$field => $value]), $zipBytes), $mime)[0] === 400, '发布前拒绝不一致：' . $field);
}
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes), $mime)[0] === 302, '两个文件一致后进入待发布区');
$releaseData = json_decode(file_get_contents("$scratch/metadata/project-releases.json"), true); $releaseId = array_key_first($releaseData['releases']); $release = $releaseData['releases'][$releaseId];
assertHttp(request('GET', $apiPath)[0] === 404, '完整上传但未发布时查询仍不可见');
$packagePath = '/api/project-updates/hao52okp/' . $releaseId . '/package';
assertHttp(request('GET', $packagePath)[0] === 404, '持有令牌也不能下载草稿');
assertHttp(request('GET', '/d/' . $release['package_id'])[0] === 404, '私有包不注册公开下载链接');
file_put_contents("$scratch/uploads/{$release['package_id']}", 'tampered');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $releaseId]))[0] === 400, '发布时重新校验拒绝变动文件');
file_put_contents("$scratch/uploads/{$release['package_id']}", $zipBytes);
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $releaseId]))[0] === 302, '校验通过后原子发布');
[$status, $json, $headers] = request('GET', $apiPath); $published = json_decode($json, true);
assertHttp($status === 200 && $published['version'] === '1.0.1' && $published['notes'] === '测试更新' && str_contains($published['download_url'], $releaseId), 'API返回已发布版本说明和受保护下载地址');
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes), $mime)[0] === 400, '已存在版本不能覆盖');
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes, false), $mime)[0] === 400 && json_decode(request('GET', $apiPath)[1], true)['version'] === '1.0.1', '失败上传保留当前已发布版本');
[$status, $download, $headers] = request('GET', $packagePath);
assertHttp($status === 200 && hash('sha256', $download) === $manifest['sha256'] && str_contains($headers, 'no-store') && !str_contains($headers, 'X-Accel-Redirect'), '私有下载完整且不通过公共加速下载路径');
assertHttp(json_decode(request('GET', str_replace('/package', '/manifest', $packagePath))[1], true) === $manifest, '原始清单可鉴权下载');
$validToken = $apiToken; $apiToken = str_repeat('b', 64);
assertHttp(request('GET', $packagePath)[0] === 401, '错误令牌拒绝下载');
$apiToken = '';
assertHttp(request('GET', $packagePath)[0] === 401, '匿名拒绝私有包下载');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_revoke']))[0] === 302, '可撤销令牌');
$apiToken = $validToken;
assertHttp(request('GET', $packagePath)[0] === 401, '撤销后旧令牌立即失效'); $apiToken = '';
// Existing hao52okp public links must fail closed, other file projects are unaffected.
$legacyPrivate = str_repeat('f', 64);
$stored = json_decode(file_get_contents("$scratch/metadata/files.json"), true);
$stored[$legacyPrivate] = ['name' => 'source.zip', 'project' => 'hao52okp', 'size' => 6, 'time' => gmdate('c')];
file_put_contents("$scratch/metadata/files.json", json_encode($stored)); file_put_contents("$scratch/uploads/$legacyPrivate", 'source');
assertHttp(request('GET', '/d/' . $legacyPrivate, '', 'text/plain', false)[0] === 404, '历史导航站源码公开直链被封闭');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'classify', 'id' => $legacyPrivate, 'project' => 'other', 'platform' => 'windows']))[0] === 400, '不能通过改分类将源码重新公开');
assertHttp(request('GET', '/d/' . $legacyId, '', 'text/plain', false)[0] === 200, '私有发布功能不改变其他安装包公开下载');
