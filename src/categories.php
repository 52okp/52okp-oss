<?php
declare(strict_types=1);
const FILE_PLATFORMS = ['windows' => 'Windows', 'android' => '安卓', 'ios' => 'iOS', 'mac_intel' => 'Mac Intel 版本', 'mac_apple' => 'Mac Apple 芯片版本'];
function fileCategory(array $input): array {
    $result = [];
    foreach (['project' => 120, 'version' => 60, 'platform' => 20] as $key => $limit) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/u', $value)) throw new RuntimeException('分类字段格式无效');
        $value = trim($value);
        if (strlen($value) > $limit) throw new RuntimeException('项目名称或版本号过长');
        $result[$key] = $value;
    }
    if ($result['project'] === '') throw new RuntimeException('请填写项目名称');
    if (!isset(FILE_PLATFORMS[$result['platform']])) throw new RuntimeException('请选择有效的平台分类');
    return $result;
}
