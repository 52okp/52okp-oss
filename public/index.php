<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/updater.php';
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
                $upload = $_FILES['file'] ?? null;
                if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('上传失败，请检查文件大小及服务器限制');
                $size = filesize($upload['tmp_name']);
                if ($size > $config['max_bytes']) throw new RuntimeException('文件超过上传上限');
                $name = preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $upload['name'])));
                if (!$name || strlen($name) > 240) throw new RuntimeException('文件名无效或过长');
                $id = bin2hex(random_bytes(32));
                if (!move_uploaded_file($upload['tmp_name'], "$root/uploads/$id")) throw new RuntimeException('无法保存文件');
                if (!chmod("$root/uploads/$id", 0640)) { unlink("$root/uploads/$id"); throw new RuntimeException('无法设置文件权限'); }
                try { transaction('files', function (&$files) use ($id, $name, $size) { $files[$id] = ['name' => $name, 'size' => $size, 'time' => gmdate('c')]; }); }
                catch (Throwable $e) { unlink("$root/uploads/$id"); throw $e; }
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') { header('Content-Type: application/json'); echo json_encode(['url' => rtrim($config['base_url'], '/') . '/d/' . $id]); exit; }
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
$assetVersion = substr(hash_file('sha256', __DIR__ . '/app.js'), 0, 12);
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>文件云存储</title><link rel="stylesheet" href="/style.css?v=<?=h($assetVersion)?>"><script src="/app.js?v=<?=h($assetVersion)?>" defer></script></head><body><main><h1>文件云存储</h1><p class="error"><?=h($error)?></p>
<?php function token(): void { echo '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; } ?>
<?php if (empty($_SESSION['admin'])): ?>
<form method="post"><?php token(); ?><input type="hidden" name="action" value="login"><label>账号<input name="username" required autocomplete="username"></label><label>密码<input type="password" name="password" required autocomplete="current-password"></label><button>登录</button></form>
<?php else: ?>
<form method="post"><?php token(); ?><button name="action" value="logout">退出登录</button></form>
<?php $updateState = $updates['state']; $updateProgress = $updateState['progress']; $latestTag = (string)($updateState['latest']['tag'] ?? ''); $updateBusy = $updateProgress['active']; ?>
<section id="updates"><h2>程序更新</h2><p>当前版本：<span id="current-version"><?=h($updates['current'])?></span></p><p>最新版本：<span id="latest-version"><?=h($latestTag ?: '请先检查更新')?></span></p>
<div id="update-task" aria-busy="<?= $updateBusy ? 'true' : 'false' ?>">
<div class="update-progress-heading"><span id="update-stage"><?=h($updateProgress['label'])?></span><span id="update-percent"><?= $updateProgress['percent'] === null ? '处理中' : h((string)$updateProgress['percent']) . '%' ?></span></div>
<progress id="update-progress" max="100"<?= $updateProgress['percent'] === null ? '' : ' value="' . h((string)$updateProgress['percent']) . '"' ?> aria-label="程序更新任务阶段进度"></progress>
<p id="update-message" role="status" aria-live="polite"><?=h($updates['connected'] ? (string)($updateState['message'] ?? '更新服务已连接，点击检查更新获取发布版本') : '更新服务未连接，请先安装或启动本地更新服务')?></p>
<p class="update-detail"><span id="update-elapsed"></span><span id="update-job"><?=h(isset($updateState['job']) ? '任务编号：' . $updateState['job'] : '')?></span></p>
</div>
<form id="update-form" method="post"><?php token(); ?><input type="hidden" id="update-tag" name="tag" value="<?=h($latestTag)?>"><button name="action" value="check_update" id="check-update"<?= $updates['connected'] && !$updateBusy ? '' : ' disabled' ?>>检查更新</button><button name="action" value="install_update" id="install-update"<?= $updates['connected'] && !$updateBusy && $latestTag !== '' && $latestTag !== $updates['current'] ? '' : ' disabled' ?>>更新程序</button><button type="button" id="refresh-update-status">刷新状态</button></form><p class="update-detail">进度按任务阶段显示，不是文件下载百分比。连接 GitHub 时显示动态进度，请等待明确的成功或失败结果。</p><p>只更新程序代码，账号和上传文件保持不变。更新失败会尝试自动回滚。</p><noscript><p>JavaScript 未启用。提交后可刷新页面查看任务阶段和结果。</p></noscript></section>
<section><h2>上传文件</h2><p>上限 <?=h((string)round($config['max_bytes']/1048576))?> MB。直链为公开链接，持有链接即可下载。</p><form id="upload" method="post" enctype="multipart/form-data"><?php token(); ?><input type="hidden" name="action" value="upload"><input type="file" name="file" required><button>上传</button></form><progress id="progress" max="100" value="0"></progress><p id="result" role="status"></p></section>
<h2>文件列表</h2><?php if (!$files): ?><p>暂无文件</p><?php endif; ?>
<?php foreach ($files as $id => $file): $url = rtrim($config['base_url'], '/') . '/d/' . $id; ?>
<article><h3><?=h($file['name'])?></h3><p><?=h((string)$file['size'])?> 字节 · <?=h($file['time'])?></p><input readonly value="<?=h($url)?>" aria-label="下载链接"><button type="button" class="copy">复制链接</button><form method="post" class="delete"><?php token(); ?><input type="hidden" name="id" value="<?=h($id)?>"><button name="action" value="delete">删除文件</button></form></article>
<?php endforeach; endif; ?></main></body></html>
