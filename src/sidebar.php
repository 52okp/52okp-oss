<?php declare(strict_types=1); ?>
<aside class="admin-sidebar"><p class="sidebar-caption">工作空间</p><nav aria-label="功能分类">
<?php foreach (['app' => ['APP 项目', ['files' => ['folder', '文件管理'], 'upload' => ['upload', '上传文件']]], 'backend' => ['后端项目', ['projects' => ['database', '项目管理'], 'releases' => ['cloud', '更新包发布']]]] as $group => [$heading, $links]): ?>
<details class="sidebar-group" data-nav-group="<?=h($group)?>"<?= isset($links[$adminPage]) ? ' open' : '' ?>><summary><?=h($heading)?></summary><div class="sidebar-subnav">
<?php foreach ($links as $key => [$symbol, $label]): ?><a class="sidebar-link<?= $adminPage === $key ? ' active' : '' ?>" href="/?view=<?=h($key)?>"<?= $adminPage === $key ? ' aria-current="page"' : '' ?>><?php icon($symbol); ?><?=h($label)?></a><?php endforeach; ?>
</div></details><?php endforeach; ?>
<p class="sidebar-caption system-caption">系统</p>
<?php foreach (['updates' => ['refresh', '程序更新'], 'settings' => ['settings', '系统信息']] as $key => [$symbol, $label]): ?><a class="sidebar-link<?= $adminPage === $key ? ' active' : '' ?>" href="/?view=<?=h($key)?>"<?= $adminPage === $key ? ' aria-current="page"' : '' ?>><?php icon($symbol); ?><?=h($label)?></a><?php endforeach; ?>
<a class="sidebar-link" href="/api" target="_blank" rel="noopener noreferrer"><?php icon('file'); ?>API 对接文档 ↗</a>
</nav><p class="sidebar-note">APP 安装包与后端更新包<br>分区管理，独立发布</p></aside>
