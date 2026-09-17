<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
foreach (['config', 'uploads', 'metadata', 'sessions'] as $dir) if (!is_dir("$root/$dir") && !mkdir("$root/$dir", 0700, true)) throw new RuntimeException('无法创建目录');
if (is_file("$root/config/admin.php")) exit("账号已初始化，不会覆盖。\n");
fwrite(STDOUT, '管理员账号：');
$line = fgets(STDIN);
if ($line === false) exit("无法读取账号\n");
$username = trim($line);
if ($username === '') exit("账号不能为空\n");
// 从标准输入读取，部署时用 stty 隐藏终端回显。
fwrite(STDOUT, "管理员密码（至少12字符）：");
$line = fgets(STDIN);
if ($line === false) exit("无法读取密码\n");
$password = rtrim($line, "\r\n");
if (strlen($password) < 12) exit("密码过短\n");
$file = fopen("$root/config/admin.php", 'x');
if (!$file) exit("账号已存在\n");
fwrite($file, '<?php return ' . var_export(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT)], true) . ';');
fclose($file); chmod("$root/config/admin.php", 0600);
echo "初始化完成\n";
