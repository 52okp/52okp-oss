document.querySelectorAll('.delete').forEach(form => form.addEventListener('submit', event => { if (!confirm('确定删除该文件？删除后下载链接立即失效。')) event.preventDefault(); }));
document.querySelectorAll('.copy').forEach(button => button.addEventListener('click', async () => { try { await navigator.clipboard.writeText(button.previousElementSibling.value); button.textContent = '已复制'; } catch { button.previousElementSibling.select(); button.textContent = '请手动复制'; } }));
const form = document.querySelector('#upload');
form?.addEventListener('submit', event => {
  event.preventDefault(); const button = form.querySelector('button'); button.disabled = true;
  const result = document.querySelector('#result'); result.textContent = '正在上传…';
  const xhr = new XMLHttpRequest(); xhr.open('POST', '/'); xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.upload.onprogress = event => { if (event.lengthComputable) document.querySelector('#progress').value = event.loaded / event.total * 100; };
  xhr.onload = () => { button.disabled = false; if (xhr.status === 200) { try { const data = JSON.parse(xhr.responseText); result.textContent = `上传成功：${data.url}`; const copy = document.createElement('button'); copy.textContent = '复制直链'; copy.onclick = () => navigator.clipboard.writeText(data.url).catch(() => { result.textContent = data.url; }); result.append(copy); const link = document.createElement('a'); link.href = '/'; link.textContent = '刷新文件列表'; result.append(link); } catch { result.textContent = '服务器响应异常，请刷新列表确认。'; } } else result.textContent = '上传失败，请检查大小限制、登录状态或服务器日志。'; };
  xhr.onerror = () => { button.disabled = false; result.textContent = '网络异常，请刷新列表确认后重试。'; }; xhr.send(new FormData(form));
});

const updateForm = document.querySelector('#update-form');
if (updateForm) {
  const check = document.querySelector('#check-update');
  const install = document.querySelector('#install-update');
  const message = document.querySelector('#update-message');
  const tag = document.querySelector('#update-tag');
  let busy = false;
  async function refreshUpdates() {
    try {
      const response = await fetch('/?api=updates', {cache: 'no-store'});
      if (!response.ok) throw new Error('更新状态读取失败，请确认登录状态后刷新页面');
      const data = await response.json();
      const state = data.state || {};
      busy = ['queued', 'checking', 'downloading', 'validating', 'switching'].includes(state.phase);
      document.querySelector('#current-version').textContent = data.current;
      document.querySelector('#latest-version').textContent = state.latest?.tag || '请先检查更新';
      tag.value = state.latest?.tag || '';
      check.disabled = busy || !data.connected;
      install.disabled = busy || !data.connected || !tag.value || tag.value === data.current;
      message.textContent = !data.connected ? '更新服务未连接，请检查本地服务状态' : state.message || '更新服务已连接';
      if (state.phase === 'success') {
        if (!document.querySelector('#reload-after-update')) {
          const link = document.createElement('a'); link.id = 'reload-after-update'; link.href = '/'; link.textContent = '刷新页面使用新版本'; message.append(link);
        }
      }
    } catch (error) { message.textContent = error.message; check.disabled = true; install.disabled = true; }
  }
  updateForm.addEventListener('submit', async event => {
    event.preventDefault();
    const action = event.submitter?.value;
    if (!action || busy) return;
    if (action === 'install_update' && !confirm(`确定更新程序至 ${tag.value}？账号和上传文件不受影响。`)) return;
    busy = true; check.disabled = true; install.disabled = true; message.textContent = '正在提交请求…';
    try {
      const body = new FormData(updateForm); body.set('action', action);
      const response = await fetch('/', {method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'}});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || '更新请求失败');
      await refreshUpdates();
    } catch (error) { busy = false; message.textContent = error.message; check.disabled = false; }
  });
  refreshUpdates();
  // Poll only while this admin page is visible; never causes an automatic deployment.
  setInterval(() => { if (!document.hidden) refreshUpdates(); }, 4000);
}
