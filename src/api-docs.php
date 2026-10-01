<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'");
$document = file_get_contents(__DIR__ . '/api-contract.md');
if ($route === '/api/spec') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $document;
    return;
}
header('Content-Type: text/html; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') return;
$digest = substr(hash_file('sha256', __DIR__ . '/../public/cloud.css'), 0, 16);
$css = is_file(__DIR__ . '/../public/cloud.' . $digest . '.css') ? '/cloud.' . $digest . '.css' : '/cloud.css?v=' . $digest;
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>52okp · 更新 API 对接文档</title><link rel="stylesheet" href="<?=h($css)?>"></head><body>
<header class="topbar"><div class="topbar-inner"><a class="brand" href="/">52okp Cloud</a><span class="topbar-caption">开发者文档 · API v1</span></div></header>
<main class="api-document"><div class="api-doc-actions"><a href="/api/spec">获取原始规范（供 AI / 开发者读取）</a><a href="/?view=releases">返回更新中心</a></div><article class="card">
<?php
// A deliberately small renderer for this trusted, bundled document; escape all text.
$code = false;
foreach (preg_split('/\R/u', $document) as $line) {
    if (str_starts_with($line, '```')) { echo $code ? '</code></pre>' : '<pre><code>'; $code = !$code; continue; }
    if ($code) { echo h($line) . "\n"; continue; }
    if (preg_match('/^(#{1,2}) (.*)$/', $line, $m)) { $level = strlen($m[1]); echo "<h$level>" . h($m[2]) . "</h$level>"; }
    elseif (trim($line) !== '') echo '<p>' . h($line) . '</p>';
}
?>
</article></main></body></html>
