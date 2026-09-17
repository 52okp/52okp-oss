<?php
declare(strict_types=1);
function token(): void { echo '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function bytesLabel(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
function icon(string $name): void {
    $paths = [
        'cloud' => 'M7 18a5 5 0 0 1-1-10 6 6 0 0 1 11-1 5.5 5.5 0 0 1 0 11H7',
        'folder' => 'M3 7V5h7l2 3h9v12H3V7',
        'upload' => 'M12 16V4m-5 5 5-5 5 5M4 15v5h16v-5',
        'refresh' => 'M20 7v5h-5M4 17v-5h5M5 8a8 8 0 0 1 13-3l2 3M4 16l2 3a8 8 0 0 0 13-3',
        'file' => 'M6 3h8l4 4v14H6V3m8 0v5h4M9 12h6m-6 4h6',
        'database' => 'M20 6c0 5-16 5-16 0s16-5 16 0v12c0 5-16 5-16 0V6m0 6c0 5 16 5 16 0',
        'pulse' => 'M2 12h5l3-8 4 16 3-8h5',
        'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2',
        'user' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M4 21v-3c0-7 16-7 16 0v3H4',
        'link' => 'M10 14l4-4M8 16l-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 1 1-1a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0',
        'trash' => 'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7',
        'search' => 'M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0m-1 5 6 6',
    ];
    echo '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ($paths[$name] ?? $paths['file']) . '"></path></svg>';
}
$loggedIn = !empty($_SESSION['admin']);
$assetVersion = substr(hash('sha256', hash_file('sha256', __DIR__ . '/../public/update.js') . hash_file('sha256', __DIR__ . '/../public/dashboard.js') . hash_file('sha256', __DIR__ . '/../public/cloud.css')), 0, 12);
$totalBytes = array_sum(array_column($files, 'size'));
$uploadLimit = bytesLabel($config['max_bytes']);
if ($loggedIn) {
    $adminInfo = require "$root/config/admin.php";
    $updateState = $updates['state']; $updateProgress = $updateState['progress'];
    $latestTag = (string)($updateState['latest']['tag'] ?? ''); $updateBusy = $updateProgress['active'];
    $storageReady = is_writable("$root/uploads") && is_writable("$root/metadata") && is_writable("$root/sessions");
}
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>52okp Cloud · 文件云存储</title><link rel="stylesheet" href="/cloud.css?v=<?=h($assetVersion)?>"><script src="/update.js?v=<?=h($assetVersion)?>" defer></script><script src="/dashboard.js?v=<?=h($assetVersion)?>" defer></script></head>
<body>
<header class="topbar"><div class="topbar-inner"><a href="/" class="brand"><?php icon('cloud'); ?><span><strong>52okp</strong> Cloud</span></a>
<?php if ($loggedIn): ?>
<nav aria-label="主导航"><a class="nav-link active" href="#files" data-view="files"><?php icon('folder'); ?>文件云存储</a><a class="nav-link" href="#settings" data-view="settings"><?php icon('settings'); ?>系统信息</a><a class="nav-link" href="#updates"><?php icon('refresh'); ?>程序更新</a></nav>
<div class="account"><span class="avatar"><?php icon('user'); ?></span><span><?=h($adminInfo['username'])?></span><form method="post"><?php token(); ?><button class="quiet" name="action" value="logout">退出</button></form></div>
<?php else: ?><span class="topbar-caption">安全管理 · 轻松分享</span><?php endif; ?></div></header>
<main>
<?php if ($error): ?><p class="error-banner" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if (!$loggedIn): ?>
<section class="login-card card"><div class="login-mark"><?php icon('cloud'); ?></div><p class="eyebrow">52OKP CLOUD</p><h1>欢迎回来</h1><p class="muted">登录管理你的文件与分享链接</p><form method="post"><?php token(); ?><input type="hidden" name="action" value="login"><label>账号<input name="username" required autocomplete="username" placeholder="请输入管理员账号"></label><label>密码<input type="password" name="password" required autocomplete="current-password" placeholder="请输入密码"></label><button class="primary login-button">登录后台</button></form><p class="login-footnote">仅管理员可管理文件，分享链接可公开下载。</p></section>
<?php else: ?>
<div id="files-view">
<div class="page-heading"><div><h1>文件云存储</h1><p class="muted">管理上传文件、分享链接与系统状态</p></div><span class="pill">简单 · 高效 · 安全</span></div>
<div class="stats-grid">
<a class="stat card" href="#file-list"><span class="stat-icon blue"><?php icon('folder'); ?></span><div><p>文件数量</p><strong id="file-count"><?=count($files)?></strong><small>当前保存的文件</small></div><span class="chevron">›</span></a>
<a class="stat card" href="#file-list"><span class="stat-icon green"><?php icon('database'); ?></span><div><p>已用空间</p><strong><?=h(bytesLabel((int)$totalBytes))?></strong><small>上传文件总大小 · 非磁盘容量</small></div><span class="chevron">›</span></a>
<a class="stat card" href="#settings" data-view="settings"><span class="stat-icon violet"><?php icon('pulse'); ?></span><div><p>文件存储状态</p><strong class="<?= $storageReady ? 'success-text' : 'danger-text' ?>"><?= $storageReady ? '运行正常' : '需要检查' ?></strong><small><?= $storageReady ? '存储与会话目录可写' : '请检查目录权限' ?></small></div><span class="chevron">›</span></a>
</div>
<div class="workspace-grid">
<section class="card upload-card"><div class="section-heading"><span class="section-icon"><?php icon('upload'); ?></span><div><h2>上传文件</h2><p class="muted">上传后即可生成公开分享链接</p></div><small>单个文件不超过 <?=h($uploadLimit)?></small></div>
<form id="upload" method="post" enctype="multipart/form-data" data-max-bytes="<?=h((string)$config['max_bytes'])?>"><?php token(); ?><input type="hidden" name="action" value="upload">
<div class="dropzone" id="dropzone"><span class="upload-cloud"><?php icon('cloud'); ?></span><label class="file-picker" for="upload-file">拖拽文件到此处，或 <span>点击选择文件</span></label><p class="muted">支持多文件逐个上传 · 不支持断点续传</p><input id="upload-file" type="file" name="file" multiple required aria-label="选择上传文件"></div>
<div class="upload-controls"><span id="selected-files" class="muted">尚未选择文件</span><button class="primary" id="upload-button"><?php icon('upload'); ?>开始上传</button></div></form>
<div id="upload-queue" aria-live="polite"></div><progress id="progress" max="100" value="0" aria-label="当前文件上传进度" hidden></progress><p id="result" role="status"></p><noscript><p class="muted">未启用 JavaScript 时，每次请选择一个文件上传。</p></noscript></section>
<section id="updates" class="card update-card"><div class="section-heading"><span class="section-icon"><?php icon('refresh'); ?></span><div><h2>程序更新</h2><p class="muted">检查并更新系统程序，保持最新版本</p></div></div><div class="update-surface">
<dl class="version-list"><dt>当前版本</dt><dd id="current-version"><?=h($updates['current_version'])?></dd><dt>最新版本</dt><dd id="latest-version"><?=h($updateState['latest_version'] ?: '请先检查更新')?></dd><dt>服务状态</dt><dd><?= $updates['connected'] ? '更新服务已连接' : '更新服务未连接' ?></dd></dl>
<div id="update-task" data-phase="<?=h((string)($updateState['phase'] ?? 'idle'))?>" aria-busy="<?= $updateBusy ? 'true' : 'false' ?>"><div class="update-progress-heading"><span id="update-stage"><?=h($updateProgress['label'])?></span><span id="update-percent"><?= $updateProgress['percent'] === null ? '处理中' : h((string)$updateProgress['percent']) . '%' ?></span></div><progress id="update-progress" max="100"<?= $updateProgress['percent'] === null ? '' : ' value="' . h((string)$updateProgress['percent']) . '"' ?> aria-label="程序更新任务阶段进度"></progress>
<p id="update-message" role="status" aria-live="polite"><?=h($updates['connected'] ? (string)($updateState['message'] ?? '点击检查更新获取最新版本') : '更新服务未连接，请检查本地服务')?></p><details class="task-details"><summary>任务详情</summary><p class="update-detail"><span id="update-elapsed"></span><span id="update-job"><?=h(isset($updateState['job']) ? '任务编号：' . $updateState['job'] : '')?></span></p><p class="muted">进度按任务阶段显示，不是下载百分比。</p></details></div>
<form id="update-form" method="post"><?php token(); ?><input type="hidden" id="update-tag" name="tag" value="<?=h($latestTag)?>"><button class="primary" name="action" value="check_update" id="check-update"<?= $updates['connected'] && !$updateBusy ? '' : ' disabled' ?>>检查更新</button><button class="primary" name="action" value="install_update" id="install-update"<?= $updates['connected'] && !$updateBusy && $latestTag !== '' && $latestTag !== $updates['current'] ? '' : ' disabled' ?>>更新程序</button><button class="secondary" type="button" id="refresh-update-status">刷新状态</button></form><p class="update-note">只更新程序代码，账号和上传文件保持不变。失败时会尝试自动回滚。</p><noscript><p>提交后刷新页面查看任务状态。</p></noscript></div></section>
</div>
<section id="file-list" class="card files-card"><div class="list-heading"><div class="section-heading"><span class="section-icon"><?php icon('file'); ?></span><div><h2>文件列表</h2><p class="muted">共 <?=count($files)?> 个文件，已使用 <?=h(bytesLabel((int)$totalBytes))?> 存储空间</p></div></div><div class="list-filters"><label class="search-field"><?php icon('search'); ?><input id="file-search" type="search" placeholder="搜索文件名…" aria-label="搜索文件名"></label><select id="file-type" aria-label="文件类型"><option value="all">全部类型</option><option value="image">图片</option><option value="document">文档</option><option value="archive">压缩包</option><option value="media">音视频</option><option value="other">其他</option></select><select id="file-sort" aria-label="文件排序"><option value="newest">按上传时间</option><option value="oldest">最早上传</option><option value="name">按文件名</option><option value="largest">按大小降序</option></select></div></div>
<div class="table-scroll"><table><thead><tr><th>文件名</th><th>大小</th><th>上传时间</th><th>状态</th><th>操作</th></tr></thead><tbody id="file-rows">
<?php foreach ($files as $id => $file): $url = rtrim($config['base_url'], '/') . '/d/' . $id;
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $kind = in_array($extension, ['jpg','jpeg','png','gif','webp','svg','bmp','avif']) ? 'image' : (in_array($extension, ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','csv']) ? 'document' : (in_array($extension, ['zip','rar','7z','gz','tar','bz2']) ? 'archive' : (in_array($extension, ['mp3','mp4','wav','mkv','mov','ogg','flac','webm']) ? 'media' : 'other')));
?>
<tr class="file-row" data-name="<?=h($file['name'])?>" data-size="<?=h((string)$file['size'])?>" data-time="<?=h($file['time'])?>" data-type="<?=h($kind)?>"><td><div class="filename"><span class="file-icon <?=h($kind)?>"><?php icon('file'); ?></span><a href="<?=h($url)?>" title="<?=h($file['name'])?>"><?=h($file['name'])?></a></div></td><td><?=h(bytesLabel($file['size']))?></td><td><time datetime="<?=h($file['time'])?>"><?=h(str_replace(['T','Z'], [' ',' UTC'], $file['time']))?></time></td><td><span class="file-status">可分享</span></td><td><div class="row-actions"><input class="share-url" readonly value="<?=h($url)?>" aria-label="下载链接"><button class="copy text-button" type="button"><?php icon('link'); ?>复制链接</button><form method="post" class="delete"><?php token(); ?><input type="hidden" name="id" value="<?=h($id)?>"><button class="text-button danger-text" name="action" value="delete"><?php icon('trash'); ?>删除</button></form></div></td></tr>
<?php endforeach; ?></tbody></table></div><div id="empty-files" class="empty-state"<?= $files ? ' hidden' : '' ?>><?php icon('folder'); ?><h3><?= $files ? '没有找到匹配的文件' : '暂无文件' ?></h3><p class="muted">上传文件后，可在这里管理和复制分享链接。</p></div>
<div class="list-footer"><span id="list-count">共 <?=count($files)?> 条记录</span><div class="pagination"><button type="button" class="secondary" id="previous-page" aria-label="上一页">‹</button><span id="page-label">第 1 页</span><button type="button" class="secondary" id="next-page" aria-label="下一页">›</button><select id="page-size" aria-label="每页条数"><option value="10">10 条/页</option><option value="20">20 条/页</option><option value="50">50 条/页</option></select></div></div></section>
</div>
<section id="settings-view" class="card settings-card" hidden><p class="eyebrow">SYSTEM INFORMATION</p><h1>系统信息</h1><p class="muted">以下信息来自当前服务器配置。此页面只读，不修改生产配置。</p><dl class="settings-list"><dt>网站地址</dt><dd><?=h($config['base_url'])?></dd><dt>单文件上传上限</dt><dd><?=h($uploadLimit)?></dd><dt>程序版本</dt><dd><?=h($updates['current_version'])?></dd><dt>管理员账号</dt><dd><?=h($adminInfo['username'])?></dd><dt>分享方式</dt><dd>持有链接即可公开下载，请勿上传不适合公开分享的敏感文件。</dd><dt>容量说明</dt><dd>已用空间仅统计上传文件，未设置总容量配额。</dd></dl><a class="secondary" href="#files" data-view="files">返回文件管理</a></section>
<footer class="site-footer"><span>52okp Cloud</span><span>文件由你管理，分享由你掌握。</span></footer>
<?php endif; ?>
</main></body></html>
