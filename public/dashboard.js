'use strict';
const filesView = document.querySelector('#files-view');
const settingsView = document.querySelector('#settings-view');
if (filesView && settingsView) {
  function showView() {
    const settings = location.hash === '#settings';
    filesView.hidden = settings; settingsView.hidden = !settings;
    document.querySelectorAll('.nav-link[data-view]').forEach(link => link.classList.toggle('active', link.dataset.view === (settings ? 'settings' : 'files')));
    if (location.hash === '#updates') document.querySelector('#updates').scrollIntoView({block: 'start'});
  }
  window.addEventListener('hashchange', showView); showView();
}
const rowsContainer = document.querySelector('#file-rows');
if (rowsContainer) {
  const rows = [...rowsContainer.querySelectorAll('.file-row')];
  const search = document.querySelector('#file-search'); const type = document.querySelector('#file-type');
  const sort = document.querySelector('#file-sort'); const size = document.querySelector('#page-size'); let page = 1;
  function renderFiles(reset = false) {
    if (reset) page = 1;
    const query = search.value.trim().toLocaleLowerCase();
    const filtered = rows.filter(row => row.dataset.name.toLocaleLowerCase().includes(query) && (type.value === 'all' || row.dataset.type === type.value));
    filtered.sort((a, b) => {
      if (sort.value === 'name') return a.dataset.name.localeCompare(b.dataset.name, 'zh-CN', {numeric: true});
      if (sort.value === 'largest') return Number(b.dataset.size) - Number(a.dataset.size);
      return sort.value === 'oldest' ? a.dataset.time.localeCompare(b.dataset.time) : b.dataset.time.localeCompare(a.dataset.time);
    });
    const pageSize = Number(size.value); const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    page = Math.max(1, Math.min(page, pages)); rows.forEach(row => { row.hidden = true; });
    filtered.forEach((row, index) => { rowsContainer.append(row); row.hidden = index < (page - 1) * pageSize || index >= page * pageSize; });
    document.querySelector('#empty-files').hidden = filtered.length !== 0;
    document.querySelector('#empty-files h3').textContent = rows.length ? '没有找到匹配的文件' : '暂无文件';
    document.querySelector('#list-count').textContent = `共 ${filtered.length} 条记录${query || type.value !== 'all' ? `（全部 ${rows.length} 条）` : ''}`;
    document.querySelector('#page-label').textContent = `${page} / ${pages}`;
    document.querySelector('#previous-page').disabled = page === 1; document.querySelector('#next-page').disabled = page === pages;
  }
  search.addEventListener('input', () => renderFiles(true));
  [type, sort, size].forEach(input => input.addEventListener('change', () => renderFiles(true)));
  document.querySelector('#previous-page').addEventListener('click', () => { page--; renderFiles(); });
  document.querySelector('#next-page').addEventListener('click', () => { page++; renderFiles(); });
  rows.forEach(row => { const time = row.querySelector('time'); const date = new Date(time.dateTime); if (!Number.isNaN(date.getTime())) { time.textContent = date.toLocaleString('zh-CN', {hour12: false}); time.title = `本地时间 · ${time.dateTime}`; } });
  renderFiles();
}
const uploadForm = document.querySelector('#upload');
if (uploadForm) {
  const input = document.querySelector('#upload-file'); const dropzone = document.querySelector('#dropzone');
  const button = document.querySelector('#upload-button'); const queue = document.querySelector('#upload-queue');
  const result = document.querySelector('#result'); const limit = Number(uploadForm.dataset.maxBytes);
  let selection = []; let uploading = false;
  function formatBytes(value) { return value < 1048576 ? `${(value / 1024).toFixed(1)} KB` : `${(value / 1048576).toFixed(1)} MB`; }
  function select(files) { if (uploading) return; selection = [...files]; document.querySelector('#selected-files').textContent = selection.length ? `已选择 ${selection.length} 个文件 · ${formatBytes(selection.reduce((total, file) => total + file.size, 0))}` : '尚未选择文件'; }
  input.addEventListener('change', () => select(input.files));
  ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => { event.preventDefault(); if (!uploading) dropzone.classList.add('dragover'); }));
  ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => { event.preventDefault(); dropzone.classList.remove('dragover'); }));
  dropzone.addEventListener('drop', event => { if (!uploading && event.dataTransfer.files.length) { select(event.dataTransfer.files); input.required = false; } });
  function upload(file) {
    const item = document.createElement('div'); item.className = 'upload-item'; item.dataset.state = 'uploading';
    const heading = document.createElement('div'); heading.className = 'upload-item-header';
    const name = document.createElement('span'); name.className = 'upload-name'; name.textContent = file.name;
    const percent = document.createElement('span'); percent.textContent = '0%';
    const progress = document.createElement('progress'); progress.max = 100; progress.value = 0; progress.setAttribute('aria-label', `${file.name} 上传进度`);
    const detail = document.createElement('small'); detail.textContent = '正在上传…'; heading.append(name, percent); item.append(heading, progress, detail); queue.append(item);
    return new Promise(resolve => {
      function fail(message) { item.dataset.state = 'failed'; detail.textContent = message; resolve(false); }
      if (file.size > limit) { fail('超过单文件上传上限，请选择较小文件'); return; }
      const xhr = new XMLHttpRequest(); xhr.open('POST', '/'); xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); xhr.timeout = 600000;
      xhr.upload.onprogress = event => { if (event.lengthComputable) { progress.value = event.loaded / event.total * 100; percent.textContent = `${Math.floor(progress.value)}%`; detail.textContent = `${formatBytes(Math.min(file.size, file.size * event.loaded / event.total))} / ${formatBytes(file.size)}${progress.value === 100 ? ' · 服务器正在保存…' : ''}`; } };
      xhr.onload = () => {
        let data; try { data = JSON.parse(xhr.responseText); } catch { fail(`服务器响应异常（HTTP ${xhr.status}），请刷新列表确认结果`); return; }
        if (xhr.status !== 200 || typeof data.url !== 'string') { fail(data.error || `上传失败（HTTP ${xhr.status}），请检查登录状态和大小限制`); return; }
        let url; try { url = new URL(data.url, location.origin); } catch { fail('分享地址配置异常，请刷新列表确认上传结果'); return; }
        if (url.protocol !== 'https:' || url.origin !== location.origin) { fail('分享地址配置异常，请刷新列表确认上传结果'); return; }
        item.dataset.state = 'success'; progress.value = 100; percent.textContent = '100%'; detail.textContent = `${formatBytes(file.size)} · 上传完成`;
        const copy = document.createElement('button'); copy.type = 'button'; copy.textContent = '复制链接';
        copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(data.url); copy.textContent = '已复制'; } catch { const field = document.createElement('input'); field.readOnly = true; field.value = data.url; field.setAttribute('aria-label', '分享链接'); item.append(field); field.select(); copy.textContent = '请手动复制'; } });
        const link = document.createElement('a'); link.href = url.href; link.textContent = '下载文件'; item.append(copy, link); resolve(true);
      };
      xhr.onerror = () => fail('网络异常，请刷新列表确认结果，不要重复上传'); xhr.ontimeout = () => fail('上传超时，请刷新列表确认结果'); xhr.onabort = () => fail('上传已中断，请刷新列表确认结果');
      const body = new FormData(uploadForm); body.set('file', file); xhr.send(body);
    });
  }
  uploadForm.addEventListener('submit', async event => {
    event.preventDefault(); if (uploading || !selection.length) return;
    uploading = true; button.disabled = true; input.disabled = true; button.textContent = '上传中…'; queue.replaceChildren(); result.textContent = '请保持页面打开，文件将逐个上传。'; let completed = 0;
    try { for (const file of selection) { if (await upload(file)) completed++; } }
    catch { result.textContent = '上传过程异常，请刷新列表确认已上传的文件。'; }
    finally {
      uploading = false; button.disabled = false; input.disabled = false; button.textContent = '开始上传'; result.textContent = `处理完成：${completed} 个成功，${selection.length - completed} 个未确认或失败。`;
      const refresh = document.createElement('a'); refresh.href = '/#file-list'; refresh.textContent = '刷新文件列表与统计'; result.append(' ', refresh);
      selection = []; input.value = ''; input.required = true; document.querySelector('#selected-files').textContent = '尚未选择文件';
    }
  });
  window.addEventListener('beforeunload', event => { if (uploading) { event.preventDefault(); event.returnValue = ''; } });
}
