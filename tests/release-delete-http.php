<?php
// Isolated fixtures only; no production files or external GitHub requests.
$apiToken = $otherToken[1];
$snapshot = json_decode(file_get_contents("$scratch/metadata/project-releases.json"), true);
$currentRecord = $snapshot['releases'][$myId];
$oldId = bin2hex(random_bytes(32));
$oldRecord = array_replace($currentRecord, ['id' => $oldId, 'version' => '1.0.0', 'package_id' => bin2hex(random_bytes(32)), 'manifest_id' => bin2hex(random_bytes(32))]);
foreach (['package_id', 'manifest_id'] as $key) copy("$scratch/uploads/{$currentRecord[$key]}", "$scratch/uploads/{$oldRecord[$key]}");
$snapshot['releases'][$oldId] = $oldRecord;
file_put_contents("$scratch/metadata/project-releases.json", json_encode($snapshot));
$deleteInput = ['csrf' => $token, 'action' => 'release_delete', 'release_project' => 'myapp', 'release_id' => $oldId, 'delete_confirm' => '1'];
assertHttp(request('POST', '/', http_build_query($deleteInput), 'application/x-www-form-urlencoded', false)[0] === 403, '匿名不能删除版本');
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['csrf' => 'bad'])))[0] === 403, '删除版本需要有效CSRF');
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['delete_confirm' => ''])))[0] === 400, '删除必须明确确认');
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['release_project' => 'hao52okp'])))[0] === 400, '删除拒绝项目不匹配');
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['release_id' => '../config/admin.php'])))[0] === 400, '删除拒绝路径穿越');
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['release_id' => $myId])))[0] === 400, '当前已发布版本不能删除');
assertHttp(request('GET', '/api/v1/projects/myapp/releases/' . $oldId . '/package')[0] === 200, '删除前历史版本仍可下载');
[, $deletePage] = request('GET', '/?view=releases&project=myapp');
$dom = new DOMDocument(); @$dom->loadHTML($deletePage); $xpath = new DOMXPath($dom);
assertHttp($xpath->query('//details[@data-release-id="' . $oldId . '"]//form[@class="release-delete-form"]')->length === 1 && $xpath->query('//details[@data-release-id="' . $myId . '"]//form[@class="release-delete-form"]')->length === 0, '仅非当前版本显示删除表单');
$deletedResponse = request('POST', '/', http_build_query($deleteInput));
assertHttp($deletedResponse[0] === 302 && str_contains($deletedResponse[2], 'Location: /?view=releases&project=myapp'), '删除成功仍停留当前项目');
clearstatcache();
assertHttp(!file_exists("$scratch/uploads/{$oldRecord['package_id']}") && !file_exists("$scratch/uploads/{$oldRecord['manifest_id']}"), '历史版本ZIP和清单已真实删除');
$afterDelete = json_decode(file_get_contents("$scratch/metadata/project-releases.json"), true);
assertHttp(!isset($afterDelete['releases'][$oldId]) && $afterDelete['projects'] === $snapshot['projects'] && $afterDelete['releases'][$myId] === $currentRecord, '删除记录但保留所有项目配置和当前发布');
foreach (['', '/package', '/manifest'] as $suffix) assertHttp(request('GET', '/api/v1/projects/myapp/releases/' . $oldId . $suffix)[0] === 404, '已删除版本接口失效：' . $suffix);
assertHttp(request('GET', '/api/v1/projects/myapp/releases/' . $myId . '/package')[1] === $myBytes, '当前包内容不受删除影响');
assertHttp(request('POST', '/', http_build_query($deleteInput))[0] === 400, '重复删除已完成记录安全拒绝');
// A persisted deleting tombstone with one already removed file can be safely retried.
$retryId = bin2hex(random_bytes(32)); $retry = array_replace($oldRecord, ['id' => $retryId, 'status' => 'deleting']);
copy("$scratch/uploads/{$currentRecord['manifest_id']}", "$scratch/uploads/{$retry['manifest_id']}");
transaction('project-releases', function (&$d) use ($retryId, $retry) { $d['releases'][$retryId] = $retry; });
assertHttp(request('GET', '/api/v1/projects/myapp/releases/' . $retryId)[0] === 404, '未清理完成版本不再提供下载记录');
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['release_id' => $retryId])))[0] === 302, '部分删除可安全重试');
clearstatcache(); assertHttp(!file_exists("$scratch/uploads/{$retry['manifest_id']}"), '重试清理剩余清单');
// Corrupt/shared references must fail before touching another release's files.
$badId = bin2hex(random_bytes(32));
transaction('project-releases', function (&$d) use ($badId, $currentRecord) { $d['releases'][$badId] = array_replace($currentRecord, ['id' => $badId, 'status' => 'draft']); });
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['release_id' => $badId])))[0] === 400, '共享文件标识拒绝删除');
assertHttp(is_file("$scratch/uploads/{$currentRecord['package_id']}") && is_file("$scratch/uploads/{$currentRecord['manifest_id']}"), '共享引用检查保护当前文件');
transaction('project-releases', function (&$d) use ($badId) { unset($d['releases'][$badId]); });
transaction('project-releases', function (&$d) use ($badId, $oldRecord) { $d['releases'][$badId] = array_replace($oldRecord, ['id' => $badId, 'package_id' => '../config/admin.php']); });
assertHttp(request('POST', '/', http_build_query(array_replace($deleteInput, ['release_id' => $badId])))[0] === 400 && is_file("$scratch/config/admin.php"), '异常元数据路径不能删除配置');
transaction('project-releases', function (&$d) use ($badId) { unset($d['releases'][$badId]); });
$apiToken = '';
