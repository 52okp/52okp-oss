<?php
declare(strict_types=1);
// PHP HTTP集成测试；X-Accel文件内容/Range需在真实Nginx另验。
$scratch = sys_get_temp_dir() . '/oss-http-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
foreach (['config', 'uploads', 'metadata', 'sessions'] as $dir) mkdir("$scratch/$dir", 0700);
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
    $token = csrf($page);
    assertHttp(request('POST', '/', 'action=upload')[0] === 403, '缺失CSRF被拒绝');
    assertHttp(request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'delete', 'id' => str_repeat('a', 64)]))[0] === 401, '匿名管理被拒绝');
    [$status] = request('POST', '/', http_build_query(['csrf' => $token, 'action' => 'login', 'username' => 'tester', 'password' => 'test-only-password']));
    assertHttp($status === 302, '管理员登录');
    [$status, $page] = request('GET', '/'); $token = csrf($page);
    assertHttp(str_contains($page, '文件列表'), '登录可见后台');
    $boundary = 'oss-test-boundary';
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$token\r\n--$boundary\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nupload\r\n--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"test.apk\"\r\nContent-Type: application/octet-stream\r\n\r\nhello-update\r\n--$boundary--\r\n";
    assertHttp(request('POST', '/', $body, "multipart/form-data; boundary=$boundary")[0] === 302, 'HTTP文件上传');
    assertHttp(request('POST', '/', $body, "multipart/form-data; boundary=$boundary")[0] === 302, '同名再次上传');
    $files = json_decode(file_get_contents("$scratch/metadata/files.json"), true, 512, JSON_THROW_ON_ERROR);
    assertHttp(count($files) === 2, '同名上传互不覆盖'); $id = array_key_first($files);
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
    foreach (['config', 'uploads', 'metadata', 'sessions'] as $dir) { foreach (glob("$scratch/$dir/*") as $file) if (is_file($file)) unlink($file); rmdir("$scratch/$dir"); }
    if (is_file("$scratch/server.log")) unlink("$scratch/server.log"); rmdir($scratch);
}
