<?php
declare(strict_types=1);
// PHP HTTP集成测试；X-Accel文件内容/Range需在真实Nginx另验。
$scratch = sys_get_temp_dir() . '/oss-http-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
foreach (['config', 'uploads', 'metadata', 'sessions', 'updater'] as $dir) mkdir("$scratch/$dir", 0700);
$process = null; $pipes = []; $cookie = '';
function assertHttp(bool $value, string $name): void { if (!$value) throw new RuntimeException($name); echo "PASS $name\n"; }
function request(string $method, string $path, string $body = '', string $type = 'application/x-www-form-urlencoded', bool $useCookie = true): array {
    global $port, $cookie;
    $headers = "Content-Type: $type\r\n";
    if ($useCookie && $cookie) $headers .= "Cookie: $cookie\r\n";
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
    $content = file_get_contents("http://127.0.0.1:$port$path", false, $context);
    $response = $http_response_header ?? [];
    foreach ($response as $line) if ($useCookie && preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $line, $match)) $cookie = $match[1];
    preg_match('/\s(\d{3})\s/', $response[0] ?? '', $status);
    return [(int)($status[1] ?? 0), $content, implode("\n", $response)];
}
function csrf(string $body): string { preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match); return $match[1] ?? ''; }
try {
    file_put_contents("$scratch/config/config.php", '<?php return ' . var_export(['base_url' => 'https://example.com', 'shared' => $scratch, 'max_bytes' => 1024], true) . ';');
    file_put_contents("$scratch/config/admin.php", '<?php return ' . var_export(['username' => 'tester', 'hash' => password_hash('test-only-password', PASSWORD_DEFAULT)], true) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if (!$socket) throw new RuntimeException($message);
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $environment = getenv(); $environment['OSS_CONFIG'] = "$scratch/config/config.php";
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'], [0 => ['pipe', 'r'], 1 => ['file', "$scratch/server.log", 'a'], 2 => ['file', "$scratch/server.log", 'a']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('无法启动HTTP测试服务');
    for ($i = 0; $i < 50; $i++) { $connection = @fsockopen('127.0.0.1', $port); if ($connection) { fclose($connection); break; } usleep(100000); }
    [$status, $page] = request('GET', '/');
    assertHttp($status === 200 && !str_contains($page, '文件列表'), '匿名只显示登录');
    assertHttp(request('GET', '/?api=updates')[0] === 401, '匿名不能查看更新状态');
    $token = csrf($page);
    assertHttp(request('POST', '/', 'action=upload')[0] === 403, '缺失CSRF被拒绝');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'delete', 'id' => str_repeat('a', 64)]))[0] === 401, '匿名管理被拒绝');
    [$status] = request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'login', 'username' => 'tester', 'password' => 'test-only-password']));
    assertHttp($status === 302, '管理员登录');
    [$status, $page] = request('GET', '/'); $token = csrf($page);
    assertHttp(str_contains($page, '文件列表'), '登录可见后台');
    assertHttp(str_contains($page, '程序更新'), '后台包含程序更新区');
    assertHttp(str_contains($page, '52okp') && str_contains($page, 'id="dropzone"') && str_contains($page, 'id="file-search"') && str_contains($page, 'id="page-size"'), '新后台包含品牌、拖拽上传、搜索和分页');
    assertHttp(str_contains($page, 'id="settings-view"') && !str_contains($page, '共 512 MB'), '系统信息只读且不虚构总容量');
    assertHttp(str_contains($page, 'id="update-progress"') && str_contains($page, 'id="update-stage"'), '后台包含更新任务进度条及阶段反馈');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'check_update']))[0] === 400, '更新服务未连接时拒绝请求');
    file_put_contents("$scratch/updater/enabled", '');
    file_put_contents("$scratch/updater/heartbeat.json", json_encode(['at' => time()]));
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'check_update']))[0] === 302, '检查更新提交到本地队列');
    $job = json_decode(file_get_contents("$scratch/updater/request.json"), true);
    assertHttp($job['type'] === 'check' && preg_match('/^[a-f0-9]{32}$/D', $job['id']) === 1, '更新请求只包含任务标识');
    [$status, $state] = request('GET', '/?api=updates');
    $queued = json_decode($state, true)['state'];
    assertHttp($queued['progress']['percent'] === 10 && $queued['progress']['active'] && $queued['task'] === 'check' && $queued['started'] > 0, '队列状态包含阶段进度和开始时间');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'check_update']))[0] === 400, '重复更新任务被拒绝');
    unlink("$scratch/updater/request.json");
    file_put_contents("$scratch/updater/status.json", json_encode(['phase' => 'checked', 'latest' => ['tag' => 'build-12345-1']]));
    [$status, $state] = request('GET', '/?api=updates');
    assertHttp($status === 200 && json_decode($state, true)['connected'], '登录可读取更新状态');
    assertHttp(json_decode($state, true)['state']['progress']['percent'] === 100, '已安装旧版worker也能显示检查完成进度');
    foreach (['checked', 'success'] as $phase) {
        file_put_contents("$scratch/updater/status.json", json_encode(['phase' => $phase, 'message' => 'build-12345-1', 'latest' => ['tag' => 'build-12345-1', 'name' => 'v1.2.1']]));
        [$status, $state] = request('GET', '/?api=updates');
        $display = json_decode($state, true)['state'];
        assertHttp(str_contains($display['message'], 'v1.2.1') && !str_contains($display['message'], 'build-') && $display['latest']['tag'] === 'build-12345-1', '版本提示显示正式版本且保留内部校验标识：' . $phase);
    }
    file_put_contents("$scratch/updater/status.json", json_encode(['phase' => 'checking', 'job' => $job['id'], 'updated' => time()]));
    [$status, $state] = request('GET', '/?api=updates');
    assertHttp(json_decode($state, true)['state']['progress']['percent'] === null, '连接GitHub阶段采用动态进度而非虚构百分比');
    file_put_contents("$scratch/updater/status.json", json_encode(['phase' => 'checked', 'latest' => ['tag' => 'build-12345-1']]));
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'install_update', 'tag' => '../config']))[0] === 400, '更新版本路径注入被拒绝');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'install_update', 'tag' => 'build-12345-1']))[0] === 302, '管理员提交已检查版本更新');
    $job = json_decode(file_get_contents("$scratch/updater/request.json"), true);
    assertHttp($job['type'] === 'install' && $job['tag'] === 'build-12345-1', '更新请求不接收任意下载地址');
    [$status, $page] = request('GET', '/');
    assertHttp(str_contains($page, '请求已提交') && str_contains($page, 'value="10"'), '无JavaScript或页面刷新后仍有队列反馈');
    unlink("$scratch/updater/request.json");
    $boundary = 'oss-test-boundary';
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n--$boundary\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nupload\r\n--$boundary\r\nContent-Disposition: form-data; name=\"project\"\r\n\r\n测试项目\r\n--$boundary\r\nContent-Disposition: form-data; name=\"platform\"\r\n\r\nwindows\r\n--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"test.apk\"\r\nContent-Type: application/octet-stream\r\n\r\nhello-update\r\n--$boundary--\r\n";
    assertHttp(request('POST', '/', $body, "multipart/form-data; boundary=$boundary")[0] === 302, 'HTTP文件上传');
    assertHttp(request('POST', '/', $body, "multipart/form-data; boundary=$boundary")[0] === 302, '同名再次上传');
    $files = json_decode(file_get_contents("$scratch/metadata/files.json"), true, 512, JSON_THROW_ON_ERROR);
    assertHttp(count($files) === 2, '同名上传互不覆盖'); $id = array_key_first($files);
    assertHttp($files[$id]['project'] === '测试项目' && $files[$id]['platform'] === 'windows' && $files[$id]['version'] === '', '上传保存项目和平台且版本允许留空');
    $legacyId = array_key_last($files);
    unset($files[$legacyId]['project'], $files[$legacyId]['platform'], $files[$legacyId]['version']);
    file_put_contents("$scratch/metadata/files.json", json_encode($files));
    [$status, $categoryPage] = request('GET', '/');
    assertHttp($status === 200 && str_contains($categoryPage, '未分类') && str_contains($categoryPage, 'id="project-filter"') && str_contains($categoryPage, 'Mac Apple 芯片版本'), '旧文件兼容并显示项目平台筛选');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'classify', 'id' => $id, 'project' => '新项目', 'platform' => 'bad']))[0] === 400, '编辑分类拒绝未知平台');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'classify', 'id' => $id, 'project' => '新项目', 'platform' => 'mac_apple', 'version' => 'v2.0.0']))[0] === 302, '管理员可编辑分类');
    $classified = json_decode(file_get_contents("$scratch/metadata/files.json"), true);
    assertHttp($classified[$id]['project'] === '新项目' && $classified[$id]['version'] === 'v2.0.0' && $classified[$id]['name'] === $files[$id]['name'] && is_file("$scratch/uploads/$id"), '分类修改保留文件标识和内容');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'classify', 'id' => $legacyId, 'project' => '旧项目', 'platform' => 'android']))[0] === 302, '旧文件可补分类并不填版本号');
    [$status, $listedPage] = request('GET', '/');
    assertHttp(substr_count($listedPage, 'class="file-row"') === 2 && str_contains($listedPage, 'data-name="test.apk"'), '已上传文件在新表格中真实显示');
    assertHttp(file_get_contents("$scratch/uploads/$id") === 'hello-update', '上传内容一致');
    [$status, , $headers] = request('GET', "/d/$id", '', 'application/x-www-form-urlencoded', false);
    assertHttp($status === 200 && str_contains($headers, "X-Accel-Redirect: /_files/$id"), '匿名下载交给Nginx');
    assertHttp(str_contains($headers, 'Content-Disposition: attachment;'), '下载附件响应头');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'delete', 'id' => '../config/admin.php']))[0] === 400, '删除路径穿越被拒绝');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'delete', 'id' => $id]))[0] === 302, '管理员删除');
    assertHttp(request('GET', "/d/$id", '', 'application/x-www-form-urlencoded', false)[0] === 404, '删除后直链失效');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'logout']))[0] === 302, '退出登录');
    [$status, $page] = request('GET', '/'); $token = csrf($page);
    for ($i = 0; $i < 4; $i++) request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'login', 'username' => 'tester', 'password' => 'wrong']));
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'login', 'username' => 'tester', 'password' => 'wrong']))[0] === 429, '登录限速');
} finally {
    if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process); }
    // 仅清理本次创建的随机测试目录，不使用外部配置路径。
    foreach (['config', 'uploads', 'metadata', 'sessions', 'updater'] as $dir) { foreach (glob("$scratch/$dir/*") as $file) if (is_file($file)) unlink($file); rmdir("$scratch/$dir"); }
    if (is_file("$scratch/server.log")) unlink("$scratch/server.log"); rmdir($scratch);
}
