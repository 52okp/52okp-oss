<?php
declare(strict_types=1);
// 不依赖服务器配置的基础回归测试。
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
$ids = [];
for ($i = 0; $i < 1000; $i++) $ids[] = bin2hex(random_bytes(32));
check(count(array_unique($ids)) === 1000, '随机文件标识不重复');
check(preg_match('/^[a-f0-9]{64}$/D', '../config/admin.php') === 0, '路径穿越被拒绝');
$name = '中文安装包 "测试".apk';
check(rawurldecode(rawurlencode($name)) === $name, '下载文件名编码');
$hash = password_hash('testing-password-only', PASSWORD_DEFAULT);
check(password_verify('testing-password-only', $hash), '密码哈希');
check(!password_verify('wrong', $hash), '错误密码');
check(is_file(dirname(__DIR__) . '/public/index.php'), '入口存在');
require dirname(__DIR__) . '/src/updater.php';
check(updateVersionLabel('v1.0.0', 'build-1-1') === 'v1.0.0', '语义版本展示');
check(updateVersionLabel('程序更新 build-1-1', 'build-1-1') === 'build-1-1', '旧发布版本展示兼容');
$scratch = sys_get_temp_dir() . '/oss-test-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700); mkdir("$scratch/metadata", 0700);
$root = $scratch;
try {
    require dirname(__DIR__) . '/src/storage.php';
    transaction('files', function (&$data) { $data['one'] = ['name' => '中文.apk']; });
    transaction('files', function (&$data) { $data['two'] = ['name' => '中文.apk']; });
    $data = transaction('files', fn(&$data) => $data, false);
    check(count($data) === 2 && $data['one']['name'] === '中文.apk', '元数据累加及中文保存');
    $before = file_get_contents("$scratch/metadata/files.json");
    try { transaction('files', function (&$data) { $data = []; throw new RuntimeException('expected'); }); } catch (RuntimeException $e) { check($e->getMessage() === 'expected', '事务异常传播'); }
    check(file_get_contents("$scratch/metadata/files.json") === $before, '失败事务不覆盖数据');
    transaction('files', function (&$data) { unset($data['one']); });
    check(count(transaction('files', fn(&$data) => $data, false)) === 1, '异常后锁释放及删除');
    check(h('<script>"') === '&lt;script&gt;&quot;', 'HTML转义');
} finally {
    foreach (['files.json', 'files.json.lock'] as $file) if (is_file("$scratch/metadata/$file")) unlink("$scratch/metadata/$file");
    rmdir("$scratch/metadata"); rmdir($scratch);
}
