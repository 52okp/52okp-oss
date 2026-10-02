<?php
// Included by http.php inside its isolated HTTP test environment.
$apiPath = '/api/project-updates/hao52okp/latest';
[, $emptyProjectPage] = request('GET', '/?view=projects');
assertHttp(str_contains($emptyProjectPage, '尚未注册项目') && !str_contains($emptyProjectPage, 'value="hao52okp"'), '空白安装不创建默认导航站项目');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_token']))[0] === 400, '未选择项目不能默认操作导航站');
foreach (['files' => 'files-view', 'upload' => 'upload-view', 'projects' => 'projects-view', 'releases' => 'project-releases', 'updates' => 'updates-view', 'settings' => 'settings-view'] as $view => $panel) {
    [, $viewPage] = request('GET', '/?view=' . $view);
    $dom = new DOMDocument(); @$dom->loadHTML($viewPage); $xpath = new DOMXPath($dom);
    assertHttp($xpath->query('//*[@id="' . $panel . '" and not(@hidden)]')->length === 1, '侧栏页面可独立打开：' . $view);
    foreach (['files-view', 'upload-view', 'projects-view', 'project-releases', 'updates-view', 'settings-view'] as $other) {
        if ($other !== $panel) assertHttp($xpath->query('//*[@id="' . $other . '" and @hidden]')->length === 1, '其他内容不堆叠：' . $view . '/' . $other);
    }
}
assertHttp(request('GET', '/api', '', 'text/plain', false)[0] === 200 && str_contains(request('GET', '/api/spec', '', 'text/plain', false)[1], '/api/v1/projects/{project}/updates'), '公开 API 页面与原始规范无需登录');
assertHttp(!str_contains(request('GET', '/api')[1], "\xEF\xBF\xBD") && str_contains(request('GET', '/api')[1], '安全解压'), '文档中文按UTF-8完整渲染');
[$savedStatus, , $savedHeaders] = request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_register', 'release_project' => 'hao52okp', 'repository' => 'test-owner/hao52okp']));
assertHttp($savedStatus === 302 && str_contains($savedHeaders, 'Location: /?view=projects'), '保存项目后返回项目管理');
assertHttp(str_contains(request('GET', '/?view=projects')[1], '项目已保存'), '保存项目后明确提示成功');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_register', 'release_project' => 'hao52okp', 'repository' => 'other/repo']))[0] === 400, '已绑定来源不可静默替换');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_register', 'release_project' => '../bad', 'repository' => 'http://127.0.0.1']))[0] === 400, '注册拒绝路径与任意来源URL');
assertHttp(request('GET', $apiPath)[0] === 401, '私有更新查询必须鉴权，后台登录不替代令牌');
assertHttp(request('POST', '/', http_build_query(['action' => 'release_token', 'release_project' => 'hao52okp']))[0] === 403, '令牌管理需要CSRF');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_token', 'release_project' => 'hao52okp']))[0] === 302, '管理员生成项目令牌');
[, $tokenPage] = request('GET', '/');
preg_match('/aria-label="项目访问令牌" value="([a-f0-9]{64})"/', $tokenPage, $tokenMatch);
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
    $body = "--release-test\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n--release-test\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nrelease_stage\r\n--release-test\r\nContent-Disposition: form-data; name=\"release_project\"\r\n\r\nhao52okp\r\n--release-test\r\nContent-Disposition: form-data; name=\"package\"; filename=\"hao52okp-update.zip\"\r\nContent-Type: application/zip\r\n\r\n$zipBytes\r\n";
    if ($includeManifest) $body .= "--release-test\r\nContent-Disposition: form-data; name=\"manifest\"; filename=\"update-manifest.json\"\r\nContent-Type: application/json\r\n\r\n" . json_encode($manifest) . "\r\n";
    return $body . "--release-test--\r\n";
}
$mime = 'multipart/form-data; boundary=release-test';
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes, false), $mime)[0] === 400, '缺少清单不能进入待发布区');
foreach (['size' => 999, 'sha256' => str_repeat('a', 64), 'version' => '1.0.2'] as $field => $value) {
    assertHttp(request('POST', '/', releaseUploadBody(array_replace($manifest, [$field => $value]), $zipBytes), $mime)[0] === 400, '发布前拒绝不一致：' . $field);
}
[$uploadStatus, $uploadJson] = request('POST', '/', releaseUploadBody($manifest, $zipBytes), $mime, true, "X-Requested-With: XMLHttpRequest\r\n");
assertHttp($uploadStatus === 201 && json_decode($uploadJson, true)['ok'] === true, '两个文件一致后异步上传确认成功');
[$uploadStatus, $uploadJson] = request('POST', '/', releaseUploadBody($manifest, $zipBytes, false), $mime, true, "X-Requested-With: XMLHttpRequest\r\n");
assertHttp($uploadStatus === 400 && isset(json_decode($uploadJson, true)['error']), '异步上传失败返回可显示的JSON错误');
$releaseData = json_decode(file_get_contents("$scratch/metadata/project-releases.json"), true); $releaseId = array_key_first($releaseData['releases']); $release = $releaseData['releases'][$releaseId];
assertHttp(request('GET', $apiPath)[0] === 404, '完整上传但未发布时查询仍不可见');
$packagePath = '/api/project-updates/hao52okp/' . $releaseId . '/package';
assertHttp(request('GET', $packagePath)[0] === 404, '持有令牌也不能下载草稿');
assertHttp(request('GET', '/d/' . $release['package_id'])[0] === 404, '私有包不注册公开下载链接');
file_put_contents("$scratch/uploads/{$release['package_id']}", 'tampered');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $releaseId]))[0] === 400, '发布时重新校验拒绝变动文件');
file_put_contents("$scratch/uploads/{$release['package_id']}", $zipBytes);
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $releaseId]))[0] === 400, '没有 GitHub 来源校验不能发布');
// Simulate the output of the independently tested GitHub adapter, never a production bypass.
require_once dirname(__DIR__) . '/src/update-center.php';
$fixtureProject = $releaseData['projects']['hao52okp'];
$remoteFixture = ['id' => 7, 'tag_name' => 'v1.0.1', 'draft' => false, 'prerelease' => false, 'published_at' => gmdate('c'), 'assets' => [
    ['id' => 8, 'name' => 'hao52okp-update.zip', 'state' => 'uploaded', 'size' => $release['size'], 'digest' => 'sha256:' . $release['sha256']],
    ['id' => 9, 'name' => 'update-manifest.json', 'state' => 'uploaded', 'size' => $release['manifest_size'], 'digest' => 'sha256:' . $release['manifest_sha256']],
]];
$proof = releaseGithubAssets($fixtureProject, '1.0.1', $remoteFixture);
releaseMatchProof($release, $fixtureProject, $proof);
foreach (['draft' => true, 'prerelease' => true, 'tag_name' => 'v9.0.0', 'assets' => []] as $field => $value) {
    $rejected = false;
    try { releaseGithubAssets($fixtureProject, '1.0.1', array_replace($remoteFixture, [$field => $value])); } catch (RuntimeException $e) { $rejected = true; }
    assertHttp($rejected, 'GitHub 来源校验拒绝：' . $field);
}
foreach (['size' => 99, 'digest' => null, 'state' => 'new'] as $field => $value) {
    $invalid = $remoteFixture; $invalid['assets'][0][$field] = $value; $rejected = false;
    try { releaseMatchProof($release, $fixtureProject, releaseGithubAssets($fixtureProject, '1.0.1', $invalid)); } catch (RuntimeException $e) { $rejected = true; }
    assertHttp($rejected, 'GitHub 资产拒绝：' . $field);
}
$releaseData['releases'][$releaseId]['github'] = $proof;
file_put_contents("$scratch/metadata/project-releases.json", json_encode($releaseData));
file_put_contents("$scratch/uploads/{$release['package_id']}", 'tampered');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $releaseId]))[0] === 400, 'GitHub 已校验后本地文件变化仍拒绝发布');
file_put_contents("$scratch/uploads/{$release['package_id']}", $zipBytes);
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $releaseId]))[0] === 302, '校验通过后原子发布');
[$status, $json, $headers] = request('GET', $apiPath); $published = json_decode($json, true);
assertHttp($status === 200 && $published['version'] === '1.0.1' && $published['notes'] === '测试更新' && str_contains($published['download_url'], $releaseId), 'API返回已发布版本说明和受保护下载地址');
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes), $mime)[0] === 400, '已存在版本不能覆盖');
assertHttp(request('POST', '/', releaseUploadBody($manifest, $zipBytes, false), $mime)[0] === 400 && json_decode(request('GET', $apiPath)[1], true)['version'] === '1.0.1', '失败上传保留当前已发布版本');
[$status, $download, $headers] = request('GET', $packagePath);
assertHttp($status === 200 && hash('sha256', $download) === $manifest['sha256'] && str_contains($headers, 'no-store') && !str_contains($headers, 'X-Accel-Redirect'), '私有下载完整且不通过公共加速下载路径');
assertHttp(json_decode(request('GET', str_replace('/package', '/manifest', $packagePath))[1], true) === $manifest, '原始清单可鉴权下载');
$v1 = '/api/v1/projects/hao52okp/updates?current_version=';
[$status, $json] = request('GET', $v1 . '1.0.0'); $v1Record = json_decode($json, true);
assertHttp($status === 200 && $v1Record['update_available'] === true && $v1Record['release']['verification'] === 'github-sha256' && $v1Record['release']['assets']['update-manifest.json']['size'] === $release['manifest_size'], '统一 API 返回版本、双文件哈希和来源状态');
assertHttp(json_decode(request('GET', $v1 . '1.0.1')[1], true)['update_available'] === false && json_decode(request('GET', $v1 . '2.0.0')[1], true)['update_available'] === false, '相等或客户端更高版本不提示降级');
assertHttp(request('GET', $v1 . 'v1.0.0')[0] === 400 && request('GET', $v1 . '1.0.0&channel=beta')[0] === 400, '拒绝非规范版本和频道');
assertHttp(request('POST', $v1 . '1.0.0')[0] === 405, 'API只接受GET和HEAD');
assertHttp(request('GET', '/api/v1/projects/hao52okp/releases/' . $releaseId)[0] === 200 && request('HEAD', '/api/v1/projects/hao52okp/releases/' . $releaseId . '/package')[1] === '', '固定发布查询与HEAD可用');
$compatible = json_decode(request('GET', '/api/updates/hao52okp/1.0.1')[1], true);
assertHttp($compatible['format'] === 1 && $compatible['product'] === 'hao52okp' && $compatible['assets']['hao52okp-update.zip']['sha256'] === $manifest['sha256'], '兼容导航站版本查询契约');
assertHttp(request('GET', '/api/updates/hao52okp/1.0.1/package')[1] === $zipBytes, '导航站兼容下载原始ZIP');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_register', 'release_project' => 'myapp', 'repository' => 'test-owner/myapp']))[0] === 302, '可注册第二个项目');
assertHttp(request('GET', '/api/v1/projects/myapp/updates?current_version=1.0.0')[0] === 401, 'A令牌不能查询B项目');
$haoToken = $apiToken;
request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_token', 'release_project' => 'myapp']));
preg_match('/aria-label="项目访问令牌" value="([a-f0-9]{64})"/', request('GET', '/')[1], $otherToken);
$apiToken = $otherToken[1] ?? '';
assertHttp(strlen($apiToken) === 64 && request('GET', '/api/v1/projects/myapp/updates?current_version=1.0.0')[0] === 404, '第二项目独立令牌有效且无已发布记录');
assertHttp(request('GET', $packagePath)[0] === 401 && request('GET', '/api/v1/projects/myapp/releases/' . $releaseId . '/package')[0] === 404, '跨项目令牌与发布ID不能越权下载');
// Exercise the same local staging path used by GitHub imports, without real network access.
require_once dirname(__DIR__) . '/src/storage.php';
require_once dirname(__DIR__) . '/src/project-releases.php';
$root = $scratch; $config = ['max_bytes' => 1024];
$zip = new ZipArchive(); $zip->open($zipFile, ZipArchive::CREATE);
$zip->addFromString('update-version.json', json_encode(['product' => 'myapp', 'version' => '1.0.1'])); $zip->close();
$myBytes = file_get_contents($zipFile);
$myManifest = array_replace($manifest, ['product' => 'myapp', 'package' => 'myapp-update.zip', 'size' => strlen($myBytes), 'sha256' => hash('sha256', $myBytes)]);
$myManifestFile = "$scratch/uploads/test-manifest.json"; file_put_contents($myManifestFile, json_encode($myManifest));
$myRecord = ['version' => '1.0.1', 'size' => strlen($myBytes), 'sha256' => hash('sha256', $myBytes), 'manifest_size' => filesize($myManifestFile), 'manifest_sha256' => hash_file('sha256', $myManifestFile)];
$myRemote = $remoteFixture;
$myRemote['assets'][0] = ['id' => 10, 'name' => 'myapp-update.zip', 'state' => 'uploaded', 'size' => $myRecord['size'], 'digest' => 'sha256:' . $myRecord['sha256']];
$myRemote['assets'][1]['size'] = $myRecord['manifest_size']; $myRemote['assets'][1]['digest'] = 'sha256:' . $myRecord['manifest_sha256'];
$myProof = releaseGithubAssets(releaseProject('myapp'), '1.0.1', $myRemote);
releaseStore($zipFile, $myManifestFile, 'myapp', false, $myProof);
$myData = json_decode(file_get_contents("$scratch/metadata/project-releases.json"), true); $myId = array_key_last($myData['releases']);
assertHttp(request('GET', '/api/v1/projects/myapp/releases/' . $myId . '/package')[0] === 404, '拉取入库仍为不可下载草稿');
$publishResponse = request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_publish', 'release_id' => $myId]));
assertHttp($publishResponse[0] === 302 && str_contains($publishResponse[2], 'Location: /?view=releases&project=myapp'), '另一项目独立发布并保留当前项目选择');
assertHttp(request('GET', '/api/v1/projects/myapp/releases/' . $myId . '/package')[1] === $myBytes, '通用项目下载与包内版本验证通过');
[, $releasePage] = request('GET', '/?view=releases');
$dom = new DOMDocument(); @$dom->loadHTML($releasePage); $xpath = new DOMXPath($dom);
assertHttp($xpath->query('//aside[contains(@class,"release-project-nav")]//a[contains(@class,"release-project-link")]')->length === 2, '左侧列出两个已绑定项目');
assertHttp($xpath->query('//details[@data-nav-group="backend" and @open]//a')->length === 2 && $xpath->query('//details[@data-nav-group="app" and not(@open)]//a')->length === 2, '后端分类展开且两大类各含两个模块');
foreach (['hao52okp' => $releaseId, 'myapp' => $myId] as $projectId => $recordId) {
    [, $releasePage] = request('GET', '/?view=releases&project=' . $projectId);
    @$dom->loadHTML($releasePage); $xpath = new DOMXPath($dom);
    assertHttp($xpath->query('//section[@data-release-project]')->length === 1 && $xpath->query('//*[@data-selected-project="' . $projectId . '"]')->length === 1, '右侧仅显示当前项目：' . $projectId);
    assertHttp($xpath->query('//form[@id="release-upload"]/input[@name="release_project" and @value="' . $projectId . '"]')->length === 1, '上传绑定当前选中项目：' . $projectId);
    $query = '//section[@data-release-project="' . $projectId . '"]/details[@data-release-id="' . $recordId . '" and not(@open)]';
    assertHttp($xpath->query($query)->length === 1 && str_contains($xpath->query($query . '/summary')->item(0)->textContent, 'v1.0.1'), '记录默认折叠且标题保留版本：' . $projectId);
}
assertHttp($xpath->query('//a[@href="/api" and @target="_blank" and contains(@rel,"noopener")]')->length === 2, '两个开发文档入口均在安全的新标签页打开');
assertHttp($xpath->query('//*[@id="release-upload-progress"]')->length === 1, '上传ZIP与清单有专用进度条');
[, $unknownPage] = request('GET', '/?view=releases&project=missing');
assertHttp(!str_contains($unknownPage, 'id="release-upload"') && str_contains($unknownPage, '未找到所选项目'), '未知项目不能误上传到其他项目');
[, $arrayPage] = request('GET', '/?view=releases&project[]=myapp');
assertHttp(!str_contains($arrayPage, 'id="release-upload"'), '数组项目参数安全拒绝');
[, $appPage] = request('GET', '/?view=files'); @$dom->loadHTML($appPage); $xpath = new DOMXPath($dom);
assertHttp($xpath->query('//details[@data-nav-group="app" and @open]')->length === 1 && $xpath->query('//details[@data-nav-group="backend" and not(@open)]')->length === 1, 'APP模块仅默认展开APP分类');
assertHttp(releasePrivate(['name' => 'myapp-update.zip']) && !releasePrivate(['name' => 'installer.exe', 'project' => 'myapp']), '注册项目源码包拒绝公开但普通安装包不受影响');
$normalized = releaseData(['token_hash' => hash('sha256', 'legacy'), 'latest' => $releaseId]);
assertHttp($normalized['projects']['hao52okp']['latest'] === $releaseId && $normalized['projects']['hao52okp']['token_hash'] === hash('sha256', 'legacy'), '旧单项目令牌和latest可无损读取迁移');
$apiToken = $haoToken;
assertHttp(request('GET', $apiPath)[0] === 200, '第二项目生成令牌不影响第一项目');
$validToken = $apiToken; $apiToken = str_repeat('b', 64);
assertHttp(request('GET', $packagePath)[0] === 401, '错误令牌拒绝下载');
$apiToken = '';
assertHttp(request('GET', $packagePath)[0] === 401, '匿名拒绝私有包下载');
assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'release_revoke', 'release_project' => 'hao52okp']))[0] === 302, '可撤销令牌');
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
