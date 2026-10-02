<?php
declare(strict_types=1);
$releaseGroups = [];
foreach ($projectReleases['releases'] ?? [] as $release) if ($release['project'] === $selectedProject) $releaseGroups[$release['project']][] = $release;
ksort($releaseGroups, SORT_NATURAL);
?>
<div class="release-groups">
<?php foreach ($releaseGroups as $projectId => $releases):
    $p = $projectReleases['projects'][$projectId] ?? [];
    usort($releases, fn($a, $b) => version_compare($b['version'], $a['version'])); ?>
<section class="release-project-group" id="release-project-<?=h($projectId)?>" data-release-project="<?=h($projectId)?>">
<div class="release-group-heading"><h3><?=h($projectId)?></h3><span class="muted"><?=count($releases)?> 个版本</span></div>
<?php foreach ($releases as $release):
    $state = $release['status'] === 'deleting' ? '删除未完成，请重试' : ($release['status'] === 'draft' ? (empty($release['github']) ? '待 GitHub 校验' : '待发布') : (($p['latest'] ?? '') === $release['id'] ? '当前已发布' : '历史已发布')); ?>
<details class="release-record" data-release-id="<?=h($release['id'])?>">
<summary><strong class="release-version">v<?=h($release['version'])?></strong><span class="release-state"><?=h($state)?></span><span class="muted"><?=h(bytesLabel($release['size']))?></span><span class="release-expand-hint">展开详情</span></summary>
<div class="release-record-body">
<dl class="release-record-meta"><dt>项目</dt><dd><?=h($projectId)?></dd><dt>起始版本</dt><dd>v<?=h($release['from'])?></dd><dt>上传时间</dt><dd><?=h($release['created'])?></dd><dt>GitHub 校验</dt><dd><?=h($release['github']['checked_at'] ?? '尚未进行来源校验')?></dd></dl>
<h4>更新说明</h4><p class="release-notes"><?=h($release['notes'])?></p>
<?php if ($release['status'] === 'draft'): ?><form method="post" class="release-network-form"><?php token(); ?><input type="hidden" name="release_id" value="<?=h($release['id'])?>"><button class="secondary" name="action" value="release_verify">校验 GitHub</button><button class="primary" name="action" value="release_publish"<?= empty($p['token_hash']) || empty($release['github']) ? ' disabled' : '' ?>>发布</button><span class="release-feedback" role="status" aria-live="polite"></span></form><?php else: ?><p class="muted"><?= $release['status'] === 'deleting' ? '该版本已停止下载，请重试清理剩余本地文件。' : (empty($release['github']) ? '旧版本地发布（兼容保留）' : '已发布') ?></p><?php endif; ?>
<?php if (($p['latest'] ?? '') !== $release['id']): ?><form method="post" class="release-delete-form" data-project="<?=h($projectId)?>" data-version="<?=h($release['version'])?>"><?php token(); ?><input type="hidden" name="action" value="release_delete"><input type="hidden" name="release_id" value="<?=h($release['id'])?>"><input type="hidden" name="release_project" value="<?=h($projectId)?>"><label><input type="checkbox" name="delete_confirm" value="1" required> 确认永久删除此版本及本地 ZIP、清单，旧下载地址将失效。</label><button class="secondary danger-text" type="submit"><?= $release['status'] === 'deleting' ? '重试删除' : '删除版本及本地文件' ?></button></form><?php else: ?><p class="muted">当前已发布版本受保护，发布新版本后才能删除。删除旧版本可能影响尚未完成更新的客户端，请确认不再需要后操作。</p><?php endif; ?>
</div></details>
<?php endforeach; ?></section>
<?php endforeach; ?></div>
