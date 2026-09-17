<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/updater.php';
require dirname(__DIR__) . '/src/categories.php';
header('X-Content-Type-Options: nosniff');
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/health') {
    $ready = is_readable("$root/config/admin.php") && is_writable("$root/uploads") && is_writable("$root/metadata") && is_writable("$root/sessions");
    http_response_code($ready ? 200 : 503); header('Cache-Control: no-store'); header('Content-Type: application/json');
    echo json_encode(['ok' => $ready, 'release' => is_file(dirname(__DIR__) . '/.release') ? trim(file_get_contents(dirname(__DIR__) . '/.release')) : 'manual']); exit;
}
if (preg_match('~^/d/([a-f0-9]{64})$~D', $route, $match)) {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) fail(405, 'Method not allowed');
    $id = $match[1];
    $entry = transaction('files', fn(&$files) => $files[$id] ?? null, false);
    if (!$entry || !is_file("$root/uploads/$id")) fail(404, '文件不存在');
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode($entry['name']));
    header('X-Accel-Redirect: /_files/' . $id);
    exit;
}
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
session_save_path("$root/sessions");
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if (($_GET['api'] ?? '') === 'updates') {
    if (empty($_SESSION['admin'])) fail(401, '请先登录');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, 'Method not allowed');
    header('Content-Type: application/json'); echo json_encode(updateStatus(), JSON_UNESCAPED_UNICODE); exit;
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) fail(403, 'CSRF 验证失败');
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $key = hash('sha256', $_SERVER['REMOTE_ADDR']);
            $allowed = transaction('attempts', function (&$attempts) use ($key) {
                $now = time();
                foreach ($attempts as $k => $v) if ($v['until'] <= $now) unset($attempts[$k]);
                $item = $attempts[$key] ?? ['count' => 0, 'until' => $now + 900];
                if ($item['count'] >= 5) return false;
                $item['count']++; $attempts[$key] = $item; return true;
            });
            if (!$allowed) fail(429, '尝试过多，请十五分钟后重试');
            $admin = require "$root/config/admin.php";
            if (!password_verify((string)($_POST['password'] ?? ''), $admin['hash']) || !hash_equals($admin['username'], (string)($_POST['username'] ?? ''))) throw new RuntimeException('账号或密码错误');
            session_regenerate_id(true); $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        } else {
            if (empty($_SESSION['admin'])) fail(401, '请先登录');
            if (in_array($action, ['check_update', 'install_update'], true)) {
                $job = updateSubmit($action === 'check_update' ? 'check' : 'install', (string)($_POST['tag'] ?? ''));
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') { http_response_code(202); header('Content-Type: application/json'); echo json_encode(['job' => $job, 'status' => updateStatus()], JSON_UNESCAPED_UNICODE); exit; }
                header('Location: /'); exit;
            }
            if ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: /'); exit; }
            if ($action === 'upload') {
                $category = fileCategory($_POST);
                $upload = $_FILES['file'] ?? null;
                if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('上传失败，请检查文件大小及服务器限制');
                $size = filesize($upload['tmp_name']);
                if ($size > $config['max_bytes']) throw new RuntimeException('文件超过上传上限');
                $name = preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $upload['name'])));
                if (!$name || strlen($name) > 240) throw new RuntimeException('文件名无效或过长');
                $id = bin2hex(random_bytes(32));
                if (!move_uploaded_file($upload['tmp_name'], "$root/uploads/$id")) throw new RuntimeException('无法保存文件');
                if (!chmod("$root/uploads/$id", 0640)) { unlink("$root/uploads/$id"); throw new RuntimeException('无法设置文件权限'); }
                try { transaction('files', function (&$files) use ($id, $name, $size, $category) { $files[$id] = ['name' => $name, 'size' => $size, 'time' => gmdate('c')] + $category; }); }
                catch (Throwable $e) { unlink("$root/uploads/$id"); throw $e; }
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') { header('Content-Type: application/json'); echo json_encode(['url' => rtrim($config['base_url'], '/') . '/d/' . $id]); exit; }
            } elseif ($action === 'classify') {
                $id = (string)($_POST['id'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/D', $id)) fail(400, '无效标识');
                $category = fileCategory($_POST);
                transaction('files', function (&$files) use ($id, $category) {
                    if (!isset($files[$id])) throw new RuntimeException('文件不存在');
                    $files[$id] = array_replace($files[$id], $category);
                });
            } elseif ($action === 'delete') {
                $id = (string)($_POST['id'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/D', $id)) fail(400, '无效标识');
                transaction('files', function (&$files) use ($id, $root) {
                    if (!isset($files[$id])) throw new RuntimeException('文件不存在');
                    if (is_file("$root/uploads/$id") && !unlink("$root/uploads/$id")) throw new RuntimeException('删除失败');
                    unset($files[$id]);
                });
            }
        }
        header('Location: /'); exit;
    }
} catch (Throwable $e) {
    http_response_code(400); $error = '操作失败：' . $e->getMessage();
    if (isset($action) && in_array($action, ['check_update', 'install_update'], true) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') { header('Content-Type: application/json'); echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE); exit; }
}
$files = !empty($_SESSION['admin']) ? transaction('files', fn(&$data) => array_reverse($data, true), false) : [];
$updates = !empty($_SESSION['admin']) ? updateStatus() : [];
require dirname(__DIR__) . '/src/view.php';
