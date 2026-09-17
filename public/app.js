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
  const progress = document.querySelector('#update-progress');
  const stage = document.querySelector('#update-stage');
  const percent = document.querySelector('#update-percent');
  const taskPanel = document.querySelector('#update-task');
  const elapsed = document.querySelector('#update-elapsed');
  const jobLabel = document.querySelector('#update-job');
  const reload = document.createElement('a');
  reload.id = 'reload-after-update'; reload.href = '/'; reload.textContent = '刷新页面使用新版本';
  taskPanel.append(reload); reload.hidden = true;
  let busy = false;
  let submitting = false;
  let fetching = false;
  let generation = 0;
  let expectedJob = '';
  let expectedSince = 0;
  let lastData = null;
  let notice = '';
  let noticeJob = '';

  function showProgress(phase, details) {
    const value = details.percent;
    if (value === null) progress.removeAttribute('value');
    else progress.value = Math.max(0, Math.min(100, Number(value) || 0));
    stage.textContent = details.label;
    percent.textContent = value === null ? '处理中' : `${progress.value}%`;
    progress.setAttribute('aria-valuetext', `${details.label}，${percent.textContent}`);
    taskPanel.dataset.phase = phase;
    taskPanel.setAttribute('aria-busy', String(details.active));
  }

  function renderElapsed() {
    const state = lastData?.state;
    if (!state?.started || !state.job) { elapsed.textContent = ''; return; }
    const end = state.progress?.active ? Date.now() / 1000 : state.updated;
    const seconds = Math.max(0, Math.floor(end - state.started));
    elapsed.textContent = `${state.progress?.active ? '已等待' : '任务耗时'}：${seconds} 秒`;
  }

  function renderStatus(data) {
    const state = data.state || {};
    const details = state.progress || {percent: 0, label: '等待开始', active: false};
    if (notice && state.job !== noticeJob) notice = '';
    lastData = data;
    busy = Boolean(details.active);
    document.querySelector('#current-version').textContent = data.current;
    document.querySelector('#latest-version').textContent = state.latest?.tag || '请先检查更新';
    tag.value = state.latest?.tag || '';
    check.disabled = busy || submitting || !data.connected;
    install.disabled = busy || submitting || !data.connected || !tag.value || tag.value === data.current;
    check.textContent = busy && state.task === 'check' ? '检查中…' : '检查更新';
    install.textContent = busy && state.task === 'install' ? '更新中…' : '更新程序';
    showProgress(state.phase || 'idle', details);
    let text = state.message || '更新服务已连接，点击检查更新获取最新版本';
    if (state.phase === 'checked' && tag.value === data.current) text = `检查完成，当前已是最新版本：${data.current}`;
    if (!data.connected) text = busy ? `${text}。服务心跳已超时，请检查本地更新服务；不要重复提交任务。` : '更新服务未连接，请检查或启动本地更新服务';
    if (state.phase === 'queued' && Date.now() / 1000 - state.started > 30) text += '。已等待超过 30 秒，请检查本地服务日志；任务不会重复提交。';
    message.textContent = notice ? `${notice}。${text}` : text;
    jobLabel.textContent = state.job ? `任务编号：${state.job}` : '';
    reload.hidden = state.phase !== 'success';
    renderElapsed();
  }

  async function requestJson(url, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(url, {...options, signal: controller.signal, cache: 'no-store'});
      if (response.status === 401 || response.status === 403) throw new Error('登录或权限验证失效，请刷新页面后重新登录');
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) throw new Error(`服务器未返回更新状态（HTTP ${response.status}），请检查登录状态和 EdgeOne 缓存／拦截规则`);
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || `请求失败（HTTP ${response.status}）`);
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('网络请求超时，任务结果未确认，请刷新状态，不要重复提交');
      throw error;
    } finally { clearTimeout(timeout); }
  }

  async function refreshUpdates() {
    if (fetching || submitting) return;
    fetching = true;
    const revision = generation;
    try {
      const data = await requestJson('/?api=updates');
      // A pre-submit poll or cached old job must not overwrite immediate submission feedback.
      if (revision !== generation || submitting) return;
      if (expectedJob && data.state?.job !== expectedJob && !(data.state?.updated > lastData?.state?.started)) {
        if (Date.now() - expectedSince > 15000) message.textContent = '任务已提交，但新任务状态尚未同步。请等待或检查 EdgeOne 是否缓存了后台状态接口，不要重复提交。';
        return;
      }
      expectedJob = '';
      renderStatus(data);
    } catch (error) {
      if (revision !== generation || submitting) return;
      message.textContent = `${error.message}。正在自动重试状态同步；服务器任务可能仍在执行。`;
      stage.textContent = '状态同步失败'; check.disabled = true; install.disabled = true;
    } finally { fetching = false; }
  }

  updateForm.addEventListener('submit', async event => {
    event.preventDefault();
    const action = event.submitter?.value;
    if (!action || busy || submitting) return;
    if (action === 'install_update' && !confirm(`确定更新程序至 ${tag.value}？账号和上传文件不受影响。`)) return;
    generation++; submitting = true; busy = true; notice = ''; expectedJob = '';
    check.disabled = true; install.disabled = true; reload.hidden = true;
    showProgress('submitting', {percent: null, label: action === 'check_update' ? '正在提交检查更新任务' : '正在提交程序更新任务', active: true});
    message.textContent = '正在提交请求，请稍候…'; elapsed.textContent = ''; jobLabel.textContent = '';
    try {
      const body = new FormData(updateForm); body.set('action', action);
      const data = await requestJson('/', {method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'}});
      expectedJob = data.job;
      expectedSince = Date.now();
      submitting = false;
      if (data.status) renderStatus(data.status);
      else {
        showProgress('queued', {percent: 10, label: '已提交，等待本地服务处理', active: true});
        message.textContent = '任务已提交，正在等待处理…'; jobLabel.textContent = `任务编号：${data.job}`;
      }
    } catch (error) {
      submitting = false; busy = false;
      notice = error.message; noticeJob = lastData?.state?.job;
      showProgress('failed', {percent: 0, label: '请求未确认，请刷新状态', active: false});
      message.textContent = error.message; check.disabled = false;
    } finally { submitting = false; }
  });
  document.querySelector('#refresh-update-status').addEventListener('click', () => {
    if (!submitting) { message.textContent = '正在刷新任务状态…'; refreshUpdates(); }
  });
  refreshUpdates();
  setInterval(() => { if (!document.hidden) refreshUpdates(); }, 2000);
  setInterval(renderElapsed, 1000);
}
